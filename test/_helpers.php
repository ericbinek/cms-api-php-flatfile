<?php
declare(strict_types=1);

use Cms\Models\BlogPosting;
use Cms\Models\Person;
use Cms\Models\WebPage;
use Cms\Models\ImageObject;
use Cms\Models\CategoryCode;
use Cms\Models\CategoryCodeSet;
use Cms\Models\DefinedTerm;
use Cms\Models\DefinedTermSet;
use Cms\Models\Comment;
use Cms\Models\WebSite;

$BASE_URL = '';

function cms_set_base(string $url): void
{
    global $BASE_URL;
    $BASE_URL = rtrim($url, '/');
}

function cms_start_server(): array
{
    $port = 14000 + random_int(0, 1000);
    $dataDir = sys_get_temp_dir() . '/cms-test-' . bin2hex(random_bytes(4));
    if (!mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
        throw new RuntimeException("Cannot create data dir: $dataDir");
    }
    $repoRoot = realpath(__DIR__ . '/..');
    $publicDir = $repoRoot . '/public';
    $serverScript = $repoRoot . '/src/server.php';

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $env = array_merge($_ENV, getenv(), ['DATA_DIR' => $dataDir, 'PORT' => (string) $port]);
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
    return [
        'proc' => $proc,
        'pipes' => $pipes,
        'baseUrl' => "http://127.0.0.1:$port",
        'dataDir' => $dataDir,
    ];
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
    global $BASE_URL;
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
    'WebPage' => WebPage::class,
    'ImageObject' => ImageObject::class,
    'CategoryCode' => CategoryCode::class,
    'CategoryCodeSet' => CategoryCodeSet::class,
    'DefinedTerm' => DefinedTerm::class,
    'DefinedTermSet' => DefinedTermSet::class,
    'Comment' => Comment::class,
    'WebSite' => WebSite::class,
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

function cms_build_payload(string $entity, bool $partial = false): array
{
    $cls = CMS_MODELS[$entity] ?? null;
    if ($cls === null) throw new RuntimeException("Unknown entity: $entity");
    $payload = [];
    foreach ($cls::FIELDS as $name => $spec) {
        if (!$partial && !in_array($name, $cls::REQUIRED_FIELDS, true)) continue;
        if ($spec['kind'] === 'ref') {
            $value = cms_make_dep($spec['targets'][0]);
            $payload[$name] = $spec['cardinality'] === 'many' ? [$value] : $value;
        } else {
            $v = cms_sample_one($spec);
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
