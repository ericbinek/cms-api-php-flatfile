<?php
declare(strict_types=1);

namespace Cms\Lib;

final class Sessions
{
    private const COLLECTION_FILE = 'sessions.json';

    private const IDLE_TTL = 1800;          // sliding inactivity window (30 min)
    private const ABSOLUTE_TTL = 28800;     // hard cap measured from login (8 h)
    private const EXTEND_THRESHOLD = 60;    // only persist a slide worth writing

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issues a session. The raw token is returned exactly once; the store keeps
     * only its SHA-256 hash, the account, the absolute expiry and the sliding
     * idle expiry.
     *
     * @return array{token: string, expiresAt: string}
     */
    public static function createSession(string $accountId): array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($accountId): array {
            $token = bin2hex(random_bytes(32));
            $sessions = Storage::readCollection(self::COLLECTION_FILE);
            $now = time();
            $session = [
                'tokenHash' => self::hashToken($token),
                'accountId' => $accountId,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + self::ABSOLUTE_TTL),
                'idleExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + self::IDLE_TTL),
            ];
            $sessions[] = $session;
            Storage::writeCollection(self::COLLECTION_FILE, $sessions);
            return ['token' => $token, 'expiresAt' => $session['expiresAt']];
        });
    }

    /**
     * Resolves a raw token to its live session, or null if unknown or expired. An
     * expired session is dropped. On success the idle window slides forward
     * (capped at the absolute expiry) and is persisted only when the move is large
     * enough, so authenticated reads do not write on every request.
     *
     * @return array{accountId: string, expiresAt: string}|null
     */
    public static function resolveSession(string $token): ?array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($token): ?array {
            $tokenHash = self::hashToken($token);
            $sessions = Storage::readCollection(self::COLLECTION_FILE);
            $now = time();
            $index = null;
            foreach ($sessions as $i => $session) {
                if (($session['tokenHash'] ?? null) === $tokenHash) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return null;
            }

            $session = $sessions[$index];
            $absolute = strtotime($session['expiresAt']);
            $idle = strtotime($session['idleExpiresAt']);
            if ($absolute === false || $idle === false || $now >= $absolute || $now >= $idle) {
                array_splice($sessions, $index, 1);
                Storage::writeCollection(self::COLLECTION_FILE, $sessions);
                return null;
            }

            $nextIdle = min($now + self::IDLE_TTL, $absolute);
            if ($nextIdle - $idle > self::EXTEND_THRESHOLD) {
                $sessions[$index]['idleExpiresAt'] = gmdate('Y-m-d\TH:i:s\Z', $nextIdle);
                Storage::writeCollection(self::COLLECTION_FILE, $sessions);
            }
            return ['accountId' => $session['accountId'], 'expiresAt' => $session['expiresAt']];
        });
    }

    // Logout / revocation: deletes the session and takes effect immediately.
    public static function deleteSession(string $token): bool
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($token): bool {
            $tokenHash = self::hashToken($token);
            $sessions = Storage::readCollection(self::COLLECTION_FILE);
            $remaining = array_values(array_filter(
                $sessions,
                static fn ($s) => ($s['tokenHash'] ?? null) !== $tokenHash,
            ));
            $removed = count($remaining) !== count($sessions);
            if ($removed) {
                Storage::writeCollection(self::COLLECTION_FILE, $remaining);
            }
            return $removed;
        });
    }
}
