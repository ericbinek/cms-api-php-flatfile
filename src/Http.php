<?php
declare(strict_types=1);

namespace Cms;

final class UnsupportedMediaTypeException extends \RuntimeException
{
}

final class Http
{
    public const MAX_BODY_SIZE = 1048576;

    public const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, If-Match, If-None-Match',
        'Access-Control-Expose-Headers' => 'ETag',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store',
    ];

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function preflight(): void
    {
        http_response_code(204);
        foreach (self::CORS_HEADERS as $name => $value) {
            header("$name: $value");
        }
    }

    public static function json(int $status, mixed $data): void
    {
        foreach (self::CORS_HEADERS as $name => $value) {
            header("$name: $value");
        }
        if ($status === 204) {
            http_response_code(204);
            return;
        }
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $etag = '"' . substr(hash('sha256', $body), 0, 16) . '"';
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        if ($ifNoneMatch !== null && ($ifNoneMatch === $etag || $ifNoneMatch === '*')) {
            http_response_code(304);
            return;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        header("ETag: $etag");
        echo $body;
    }

    public static function jsonError(array $error): void
    {
        self::json($error['status'], $error);
    }

    public static function setLocation(string $location): void
    {
        header("Location: $location");
    }

    /**
     * Reads and decodes the JSON request body.
     *
     * @throws \JsonException on invalid JSON
     * @throws \RangeException on oversized body
     * @throws UnsupportedMediaTypeException on a non-JSON Content-Type
     */
    public static function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY_SIZE) {
            throw new \RangeException('Request body too large.');
        }
        if ($raw === '') {
            return [];
        }
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));
        if ($mediaType !== 'application/json') {
            throw new UnsupportedMediaTypeException('Request body must be application/json.');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    public static function isValidUuid(string $id): bool
    {
        return preg_match(self::UUID_PATTERN, $id) === 1;
    }

    public static function generateEtag(string $body): string
    {
        return '"' . substr(hash('sha256', $body), 0, 16) . '"';
    }
}
