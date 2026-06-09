<?php
declare(strict_types=1);

namespace Cms;

final class Errors
{
    private static function build(int $status, string $error, string $message, array $details = [], string $path = ''): array
    {
        return [
            'status' => $status,
            'error' => $error,
            'message' => $message,
            'details' => $details,
            'path' => $path,
        ];
    }

    public static function validation(array $details, string $path): array
    {
        return self::build(400, 'VALIDATION_ERROR', 'Invalid request data.', $details, $path);
    }

    public static function invalidJson(string $path): array
    {
        return self::build(400, 'INVALID_JSON', 'Request body is not valid JSON.', [], $path);
    }

    public static function invalidId(string $path): array
    {
        return self::build(400, 'INVALID_ID', 'ID must be a valid UUID.', [], $path);
    }

    public static function notFound(string $resource, string $path): array
    {
        return self::build(404, 'NOT_FOUND', "$resource not found.", [], $path);
    }

    public static function routeNotFound(string $path): array
    {
        return self::build(404, 'ROUTE_NOT_FOUND', 'No route matches this request.', [], $path);
    }

    public static function methodNotAllowed(array $allowed, string $path): array
    {
        $list = implode(', ', $allowed);
        return self::build(405, 'METHOD_NOT_ALLOWED', "Method not allowed. Allowed: $list.", [], $path);
    }

    public static function preconditionFailed(string $path): array
    {
        return self::build(412, 'PRECONDITION_FAILED', 'ETag does not match current resource state.', [], $path);
    }

    public static function payloadTooLarge(string $path): array
    {
        return self::build(413, 'PAYLOAD_TOO_LARGE', 'Request body too large.', [], $path);
    }

    public static function unsupportedMediaType(string $path): array
    {
        return self::build(415, 'UNSUPPORTED_MEDIA_TYPE', 'Request body must be application/json.', [], $path);
    }

    public static function internal(string $path): array
    {
        return self::build(500, 'INTERNAL_ERROR', 'Internal server error.', [], $path);
    }
}
