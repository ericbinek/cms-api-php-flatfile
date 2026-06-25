<?php
declare(strict_types=1);

namespace Cms\Lib;

// Per-IP sliding-window rate limiter. Two independent one-minute windows per
// client: reads (GET/HEAD and any non-write method) and writes (POST/PUT/DELETE).
//
// Unlike the other targets, PHP's built-in server is shared-nothing: each request
// runs the script fresh with no surviving process memory, so the counter cannot
// live in a static map. State is persisted to a lock-guarded JSON file in the data
// dir instead, through the same flatfile primitives the session store uses. The
// peer of the connection (REMOTE_ADDR) is the only trusted source — an
// X-Forwarded-For header is client-spoofable and these targets run without a proxy.

final class RateLimit
{
    private const WINDOW_SECONDS = 60;
    private const STATE_FILE = 'rate-limit.json';
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE'];

    private static function limitFromEnv(string $name, int $fallback): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '' || !ctype_digit($raw)) {
            return $fallback;
        }
        $value = (int) $raw;
        return $value > 0 ? $value : $fallback;
    }

    /**
     * Records a request from $ip with the given method. Returns null when the
     * request is within its bucket's limit, otherwise the whole seconds until the
     * oldest in-window request expires (at least 1) — the Retry-After value.
     */
    public static function check(string $ip, string $method): ?int
    {
        $bucket = in_array($method, self::WRITE_METHODS, true) ? 'write' : 'read';
        $limit = $bucket === 'write'
            ? self::limitFromEnv('RATE_LIMIT_WRITE_PER_MINUTE', 60)
            : self::limitFromEnv('RATE_LIMIT_READ_PER_MINUTE', 600);
        $now = time();
        $cutoff = $now - self::WINDOW_SECONDS;

        return Storage::withLock(self::STATE_FILE, static function () use ($ip, $bucket, $limit, $now, $cutoff): ?int {
            $rows = Storage::readCollection(self::STATE_FILE);
            $state = $rows[0] ?? ['hits' => [], 'lastSweep' => 0];
            $hits = is_array($state['hits'] ?? null) ? $state['hits'] : [];

            $entry = is_array($hits[$ip] ?? null) ? $hits[$ip] : ['read' => [], 'write' => []];
            $stamps = self::prune(is_array($entry[$bucket] ?? null) ? $entry[$bucket] : [], $cutoff);

            if (count($stamps) >= $limit) {
                // Reject without writing: a limited client would otherwise drive a
                // file write on every blocked request. The unpruned tail is cleaned
                // by the next admitted request's sweep.
                return max(1, (int) ($stamps[0] + self::WINDOW_SECONDS - $now));
            }

            $stamps[] = $now;
            $entry[$bucket] = $stamps;
            $hits[$ip] = $entry;

            // Sweep idle clients at most once per window, piggybacked on an
            // admitted request, so the file stays bounded — no background timer.
            $lastSweep = (int) ($state['lastSweep'] ?? 0);
            if ($now - $lastSweep >= self::WINDOW_SECONDS) {
                $hits = self::sweep($hits, $cutoff);
                $lastSweep = $now;
            }

            Storage::writeCollection(self::STATE_FILE, [['hits' => $hits, 'lastSweep' => $lastSweep]]);
            return null;
        });
    }

    /** Drops timestamps at or before $cutoff, keeping the value a clean list. */
    private static function prune(array $stamps, int $cutoff): array
    {
        $kept = [];
        foreach ($stamps as $ts) {
            if ($ts > $cutoff) {
                $kept[] = $ts;
            }
        }
        return $kept;
    }

    /**
     * Prunes every client's buckets and forgets those that fall idle, so the
     * state stays bounded by the clients active in the last window.
     *
     * @param array<string, array{read: int[], write: int[]}> $hits
     * @return array<string, array{read: int[], write: int[]}>
     */
    private static function sweep(array $hits, int $cutoff): array
    {
        $swept = [];
        foreach ($hits as $ip => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $read = self::prune(is_array($entry['read'] ?? null) ? $entry['read'] : [], $cutoff);
            $write = self::prune(is_array($entry['write'] ?? null) ? $entry['write'] : [], $cutoff);
            if ($read !== [] || $write !== []) {
                $swept[$ip] = ['read' => $read, 'write' => $write];
            }
        }
        return $swept;
    }
}
