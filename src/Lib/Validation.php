<?php
declare(strict_types=1);

namespace Cms\Lib;

final class Validation
{
    public const MAX_STRING_LENGTH = 100_000;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const HTTP_URL_PATTERN = '#^https?://\\S+$#i';
    private const ISO_DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?(Z|[+-]\d{2}:\d{2})$/';

    private const DANGEROUS_KEYS = ['__proto__', 'constructor', 'prototype'];

    public static function isDangerousKey(string $k): bool
    {
        return in_array($k, self::DANGEROUS_KEYS, true);
    }

    public static function sanitizeString(string $v): string
    {
        $stripped = str_replace("\0", '', $v);
        $normalized = \Normalizer::normalize($stripped, \Normalizer::FORM_C);
        return $normalized !== false ? $normalized : $stripped;
    }

    public static function deepSanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && self::isDangerousKey($k)) {
                    continue;
                }
                if ($isList) {
                    $out[] = self::deepSanitize($v);
                } else {
                    $out[$k] = self::deepSanitize($v);
                }
            }
            return $out;
        }
        return $value;
    }

    public static function isValidUuid(mixed $s): bool
    {
        return is_string($s) && preg_match(self::UUID_PATTERN, $s) === 1;
    }

    public static function normalizeUuid(mixed $s): mixed
    {
        return is_string($s) ? strtolower($s) : $s;
    }

    public static function checkScalar(string $type, mixed $value): bool
    {
        return match ($type) {
            'Integer' => is_int($value),
            'Number' => is_float($value) || is_int($value),
            'Boolean' => is_bool($value),
            'Date', 'DateTime', 'Time' => is_string($value) && preg_match(self::ISO_DATETIME_PATTERN, $value) === 1,
            'URL' => is_string($value) && preg_match(self::HTTP_URL_PATTERN, $value) === 1,
            default => is_string($value) && strlen($value) <= self::MAX_STRING_LENGTH,
        };
    }

    public static function isEmbed(mixed $v, string $type): bool
    {
        return is_array($v) && ($v['@type'] ?? null) === $type;
    }

    public static function etagFor(array $item): string
    {
        $json = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return '"' . substr(hash('sha256', $json ?: ''), 0, 16) . '"';
    }

    public static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
