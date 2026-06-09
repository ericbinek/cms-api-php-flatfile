<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/autoload.php';

use Cms\Http;
use Cms\Errors;
use Cms\Routers\BlogPostingRouter;
use Cms\Routers\PersonRouter;
use Cms\Routers\WebPageRouter;
use Cms\Routers\ImageObjectRouter;
use Cms\Routers\CategoryCodeRouter;
use Cms\Routers\CategoryCodeSetRouter;
use Cms\Routers\DefinedTermRouter;
use Cms\Routers\DefinedTermSetRouter;
use Cms\Routers\CommentRouter;
use Cms\Routers\WebSiteRouter;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = "$method $path";

$start = microtime(true);
register_shutdown_function(static function () use ($method, $path, $start): void {
    $code = http_response_code() ?: 200;
    $ms = (int) ((microtime(true) - $start) * 1000);
    error_log("$method $path $code {$ms}ms");
});

try {
    if ($method === 'OPTIONS') {
        Http::preflight();
        return;
    }
    if ($method === 'TRACE' || $method === 'CONNECT') {
        Http::jsonError(Errors::routeNotFound($requestPath));
        return;
    }
    if ($method === 'GET' && $path === '/health') {
        Http::json(200, ['status' => 'ok']);
        return;
    }

    $routers = [
    BlogPostingRouter::class,
    PersonRouter::class,
    WebPageRouter::class,
    ImageObjectRouter::class,
    CategoryCodeRouter::class,
    CategoryCodeSetRouter::class,
    DefinedTermRouter::class,
    DefinedTermSetRouter::class,
    CommentRouter::class,
    WebSiteRouter::class,
    ];
    foreach ($routers as $router) {
        if ($router::handle($method, $path, $requestPath)) {
            return;
        }
    }

    Http::jsonError(Errors::routeNotFound($requestPath));
} catch (\JsonException) {
    Http::jsonError(Errors::invalidJson($requestPath));
} catch (\Cms\UnsupportedMediaTypeException) {
    Http::jsonError(Errors::unsupportedMediaType($requestPath));
} catch (\RangeException) {
    Http::jsonError(Errors::payloadTooLarge($requestPath));
} catch (\Throwable $error) {
    error_log("[$requestPath] {$error->getMessage()}");
    Http::jsonError(Errors::internal($requestPath));
}
