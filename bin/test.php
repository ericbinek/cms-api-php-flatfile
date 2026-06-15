<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../test/_helpers.php';

$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

// Shared server for the entity suite. It is seeded with one admin (who sees and
// may do everything), so the CRUD contract is exercised through the auth layer
// unchanged. The conformance suite starts its own servers with specific seeds.
$server = cms_start_server();
register_shutdown_function(static function () use (&$server) {
    if ($server !== null) cms_stop_server($server);
});

cms_set_base($server['baseUrl']);

$retries = 0;
$healthy = false;
while ($retries++ < 100) {
    try {
        $r = cms_request('GET', '/health');
        if ($r['status'] === 200) { $healthy = true; break; }
    } catch (\Throwable) {
        // retry while the server boots
    }
    usleep(50_000);
}
if (!$healthy) {
    error_log("Server did not become healthy.");
    exit(2);
}

// Authenticate as the seeded admin and attach the token to subsequent requests,
// so the entity suite drives writes through the auth layer.
cms_set_auth_token($server['token']);

foreach (glob(__DIR__ . '/../test/*.test.php') as $file) {
    require $file;
}

$pass = 0;
$fail = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "ok - $name\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "not ok - $name\n";
        echo '  ' . $e->getMessage() . "\n";
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            echo '  ' . $line . "\n";
        }
        $fail++;
    }
}

echo "\n# tests " . ($pass + $fail) . "\n# pass $pass\n# fail $fail\n";
exit($fail > 0 ? 1 : 0);
