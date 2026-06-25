<?php
declare(strict_types=1);

$RL_BASE = '/blog-postings';

// Reads and writes have independent per-IP windows. Each test starts its own
// server with one bucket set low and the other effectively unlimited, then drives
// requests until the limiter trips. Exact counts are not asserted — server startup
// spends a request or two on health probes — only that limiting eventually engages
// after at least one request is admitted, and that the rejection carries the 429
// envelope and a sane Retry-After header. Requests go out unauthenticated: the
// limiter runs before auth, so they still count.

test('rate limit: writes over the limit are rejected with 429 and Retry-After', function () use ($RL_BASE) {
    $s = cms_start_server(['env' => ['RATE_LIMIT_WRITE_PER_MINUTE' => '5', 'RATE_LIMIT_READ_PER_MINUTE' => '1000000']]);
    try {
        $base = $s['baseUrl'];
        $admitted = 0;
        $limited = null;
        for ($i = 0; $i < 40; $i++) {
            $r = cms_req($base, null, 'POST', $RL_BASE, []);
            if ($r['status'] === 429) { $limited = $r; break; }
            $admitted++;
        }
        cms_assert($admitted >= 1, 'at least one write should be admitted before limiting');
        cms_assert($limited !== null, 'writes should eventually be rate limited');
        $retryAfter = (int) ($limited['headers']['retry-after'] ?? '0');
        cms_assert($retryAfter >= 1 && $retryAfter <= 60, 'Retry-After out of range: ' . ($limited['headers']['retry-after'] ?? '(absent)'));
        cms_assert_equal(429, $limited['status']);
        cms_assert_equal('TOO_MANY_REQUESTS', $limited['body']['error'] ?? null);
    } finally {
        cms_stop_server($s);
    }
});

test('rate limit: reads have their own window, independent of the write limit', function () use ($RL_BASE) {
    $s = cms_start_server(['env' => ['RATE_LIMIT_READ_PER_MINUTE' => '120', 'RATE_LIMIT_WRITE_PER_MINUTE' => '1000000']]);
    try {
        $base = $s['baseUrl'];
        $admitted = 0;
        $limited = null;
        for ($i = 0; $i < 200; $i++) {
            $r = cms_req($base, null, 'GET', $RL_BASE);
            if ($r['status'] === 429) { $limited = $r; break; }
            $admitted++;
        }
        cms_assert($admitted >= 1, 'at least one read should be admitted before limiting');
        cms_assert($limited !== null, 'reads should eventually be rate limited');
        $retryAfter = (int) ($limited['headers']['retry-after'] ?? '0');
        cms_assert($retryAfter >= 1 && $retryAfter <= 60, 'Retry-After out of range: ' . ($limited['headers']['retry-after'] ?? '(absent)'));
        cms_assert_equal(429, $limited['status']);
        cms_assert_equal('TOO_MANY_REQUESTS', $limited['body']['error'] ?? null);
    } finally {
        cms_stop_server($s);
    }
});
