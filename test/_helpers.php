<?php
declare(strict_types=1);

use Cms\Models\Account;
use Cms\Lib\Access;
use Cms\Models\BlogPosting;
use Cms\Models\Person;
use Cms\Models\Organization;
use Cms\Models\WebPage;
use Cms\Models\ImageObject;
use Cms\Models\VideoObject;
use Cms\Models\AudioObject;
use Cms\Models\CategoryCode;
use Cms\Models\CategoryCodeSet;
use Cms\Models\DefinedTerm;
use Cms\Models\DefinedTermSet;
use Cms\Models\Comment;
use Cms\Models\WebSite;
use Cms\Models\SiteNavigationElement;

$BASE_URL = '';

// Auth is mandatory on writes. The request helper attaches this module-scoped
// bearer token (set via cms_set_auth_token) so the entity suite can drive the API
// as an admin without threading the token through every call. An explicit
// Authorization header on a single request wins over it.
$CMS_AUTH_TOKEN = null;

// The credentials of the admin the default server seed uses. Tests can log in
// with these to obtain a fresh token.
const CMS_DEFAULT_ADMIN = ['username' => 'admin', 'password' => 'bootstrap-admin-secret', 'role' => 'admin'];

function cms_set_base(string $url): void
{
    global $BASE_URL;
    $BASE_URL = rtrim($url, '/');
}

function cms_set_auth_token(?string $token): void
{
    global $CMS_AUTH_TOKEN;
    $CMS_AUTH_TOKEN = $token;
}

// Builds a stored account record (hashing the password through the same KDF the
// server uses) so a server seeded from this store authenticates these credentials.
function cms_account_record(array $account): array
{
    return [
        'id' => \Cms\Lib\Validation::generateUuid(),
        'username' => $account['username'],
        'passwordHash' => Account::hashPassword($account['password']),
        'role' => $account['role'],
    ];
}

/**
 * Starts a fresh server against a temp data dir. By default the account store is
 * seeded with one admin and the returned descriptor carries that admin's token.
 * Pass ['accounts' => [...]] to seed a specific set, or ['env' => [...]] to
 * exercise the env bootstrap (no store written).
 */
function cms_free_port(): int
{
    // Ask the OS for a free port instead of guessing one. Tests run in
    // parallel; a guessed port from a fixed range collides (EADDRINUSE).
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("Cannot allocate a free port: $errstr ($errno)");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr($name, strrpos($name, ':') + 1);
}

function cms_start_server(array $opts = []): array
{
    $port = cms_free_port();
    $dataDir = sys_get_temp_dir() . '/cms-test-' . bin2hex(random_bytes(4));
    if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        throw new RuntimeException("Cannot create data dir: $dataDir");
    }
    $repoRoot = realpath(__DIR__ . '/..');
    $publicDir = $repoRoot . '/public';
    $serverScript = $repoRoot . '/src/server.php';

    $accounts = $opts['accounts'] ?? null;
    $extraEnv = $opts['env'] ?? null;
    if ($accounts === null && $extraEnv === null) {
        $accounts = [CMS_DEFAULT_ADMIN];
    }
    if ($accounts !== null) {
        $records = array_map('cms_account_record', $accounts);
        file_put_contents($dataDir . '/accounts.json', json_encode($records, JSON_PRETTY_PRINT));
    }

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $baseEnv = ['DATA_DIR' => $dataDir, 'PORT' => (string) $port];
    // Do not inherit ADMIN_USER/ADMIN_PASSWORD unless the test asks for the env
    // bootstrap — a seeded store must stay deterministic.
    $inherited = array_merge($_ENV, getenv());
    unset($inherited['ADMIN_USER'], $inherited['ADMIN_PASSWORD']);
    $env = array_merge($inherited, $baseEnv, $extraEnv ?? []);
    $cmd = sprintf(
        'exec php -S 127.0.0.1:%d -t %s %s',
        $port,
        escapeshellarg($publicDir),
        escapeshellarg($serverScript),
    );
    $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start php -S');
    }
    $baseUrl = "http://127.0.0.1:$port";

    // Wait for health, then log in the seeded admin (if any) for the token.
    $token = null;
    for ($i = 0; $i < 100; $i++) {
        $r = cms_raw_request($baseUrl, 'GET', '/health');
        if ($r !== null && $r['status'] === 200) {
            $admin = null;
            foreach ($accounts ?? [] as $a) {
                if (($a['role'] ?? null) === 'admin') { $admin = $a; break; }
            }
            if ($admin !== null) {
                $token = cms_login($baseUrl, $admin['username'], $admin['password']);
            }
            break;
        }
        usleep(50_000);
    }

    return [
        'proc' => $proc,
        'pipes' => $pipes,
        'baseUrl' => $baseUrl,
        'dataDir' => $dataDir,
        'token' => $token,
    ];
}

// A raw request against an explicit base URL with no implicit auth — used to
// bootstrap a freshly started server before the global base is set.
function cms_raw_request(string $baseUrl, string $method, string $path, ?array $body = null, array $headers = []): ?array
{
    $ch = curl_init(rtrim($baseUrl, '/') . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $headerLines = ['Accept: application/json'];
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        $headerLines[] = 'Content-Type: application/json';
    }
    foreach ($headers as $k => $v) $headerLines[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if ($response === false) {
        return null;
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $rawBody = substr($response, $headerSize);
    $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
    return ['status' => $status, 'body' => $decoded, 'raw' => $rawBody];
}

// Logs in against an explicit base URL and returns the session token.
function cms_login(string $baseUrl, string $username, string $password): string
{
    $r = cms_raw_request($baseUrl, 'POST', '/auth/login', ['username' => $username, 'password' => $password]);
    if ($r === null || $r['status'] !== 200) {
        $status = $r['status'] ?? 'no response';
        throw new RuntimeException("login($username) failed with $status");
    }
    return $r['body']['token'];
}

function cms_stop_server(array $server): void
{
    if (is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
    foreach ($server['pipes'] ?? [] as $pipe) {
        if (is_resource($pipe)) fclose($pipe);
    }
    if (isset($server['dataDir']) && is_dir($server['dataDir'])) {
        foreach (glob($server['dataDir'] . '/*') as $f) @unlink($f);
        @rmdir($server['dataDir']);
    }
}

function cms_request(string $method, string $path, ?array $body = null, array $headers = [], ?string $rawBody = null): array
{
    global $BASE_URL, $CMS_AUTH_TOKEN;
    $url = $BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $headerLines = ['Accept: application/json'];
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        $headerLines[] = 'Content-Type: application/json';
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        $headerLines[] = 'Content-Type: application/json';
    }
    // Attach the active bearer token unless the caller set Authorization itself.
    $hasAuth = false;
    foreach (array_keys($headers) as $k) {
        if (strcasecmp($k, 'Authorization') === 0) { $hasAuth = true; break; }
    }
    if (!$hasAuth && $CMS_AUTH_TOKEN !== null) {
        $headerLines[] = "Authorization: Bearer $CMS_AUTH_TOKEN";
    }
    foreach ($headers as $k => $v) $headerLines[] = "$k: $v";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Request failed: $err");
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $rawHeaders = substr($response, 0, $headerSize);
    $rawBody = substr($response, $headerSize);

    $hdrs = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        $parts = explode(': ', $line, 2);
        if (count($parts) === 2) $hdrs[strtolower($parts[0])] = $parts[1];
    }
    $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
    return ['status' => $status, 'body' => $decoded, 'headers' => $hdrs, 'raw' => $rawBody];
}

function cms_plural(string $entity): string
{
    return strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($entity))) . 's';
}

const CMS_MODELS = [
    'BlogPosting' => BlogPosting::class,
    'Person' => Person::class,
    'Organization' => Organization::class,
    'WebPage' => WebPage::class,
    'ImageObject' => ImageObject::class,
    'VideoObject' => VideoObject::class,
    'AudioObject' => AudioObject::class,
    'CategoryCode' => CategoryCode::class,
    'CategoryCodeSet' => CategoryCodeSet::class,
    'DefinedTerm' => DefinedTerm::class,
    'DefinedTermSet' => DefinedTermSet::class,
    'Comment' => Comment::class,
    'WebSite' => WebSite::class,
    'SiteNavigationElement' => SiteNavigationElement::class,
];

const CMS_SCALAR_SAMPLES = [
    'Text' => 'sample text',
    'Integer' => 42,
    'Number' => 3.14,
    'Boolean' => true,
    'Date' => '2026-05-19T00:00:00Z',
    'DateTime' => '2026-05-19T12:00:00Z',
    'Time' => '2026-05-19T12:00:00Z',
    'URL' => 'https://example.com/resource',
];

function cms_sample_one(array $spec): mixed
{
    return match ($spec['kind']) {
        'scalar' => CMS_SCALAR_SAMPLES[$spec['type']] ?? 'sample',
        'enum' => $spec['values'][0],
        'embed' => ['@type' => $spec['type'], 'alternateName' => 'en'],
        default => null,
    };
}

// Gives each build a distinct value for a unique-key string field. Without this
// every payload would carry the same sample value and the second create in any
// multi-record test would trip duplicate detection. Ref key components are
// already unique because each is freshly created per build.
function cms_unique_value(string $type, string $base): string
{
    $suffix = bin2hex(random_bytes(8));
    return $type === 'URL' ? "$base/$suffix" : "$base-$suffix";
}

function cms_build_payload(string $entity, bool $partial = false): array
{
    $cls = CMS_MODELS[$entity] ?? null;
    if ($cls === null) throw new RuntimeException("Unknown entity: $entity");
    // System and internal fields are never sent — they are not client writable
    // and would be rejected with 400.
    $readonly = Access::readonlyFields();
    $key = $cls::UNIQUE_KEY;
    $payload = [];
    foreach ($cls::FIELDS as $name => $spec) {
        if (in_array($name, $readonly, true)) continue;
        if (!$partial && !in_array($name, $cls::REQUIRED_FIELDS, true)) continue;
        if ($spec['kind'] === 'ref') {
            $value = cms_make_dep($spec['targets'][0]);
            $payload[$name] = $spec['cardinality'] === 'many' ? [$value] : $value;
        } else {
            $v = cms_sample_one($spec);
            if (in_array($name, $key, true) && $spec['kind'] === 'scalar' && is_string($v)) {
                $v = cms_unique_value($spec['type'], $v);
            }
            $payload[$name] = $spec['cardinality'] === 'many' ? [$v] : $v;
        }
    }
    return $payload;
}

function cms_make_dep(string $entity): string
{
    $payload = cms_build_payload($entity);
    $r = cms_request('POST', '/' . cms_plural($entity), $payload);
    if ($r['status'] !== 201) {
        throw new RuntimeException("makeDep($entity) failed with {$r['status']}: {$r['raw']}");
    }
    return $r['body']['id'];
}

function cms_assert(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) throw new RuntimeException($msg);
}

function cms_assert_equal(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new RuntimeException(($msg !== '' ? "$msg: " : '') . "expected $e, got $a");
    }
}

// A bearer-aware request against an explicit base URL. $bearer null means no
// Authorization header (anonymous); a token attaches it. Used by the auth
// conformance suite, which drives several roles against its own server.
function cms_req(string $baseUrl, ?string $bearer, string $method, string $path, ?array $body = null): array
{
    $headers = [];
    if ($bearer !== null) {
        $headers['Authorization'] = "Bearer $bearer";
    }
    $r = cms_raw_request($baseUrl, $method, $path, $body, $headers);
    if ($r === null) {
        throw new RuntimeException("request failed: $method $path");
    }
    return $r;
}
