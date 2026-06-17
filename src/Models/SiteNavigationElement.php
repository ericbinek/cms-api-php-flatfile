<?php
declare(strict_types=1);

namespace Cms\Models;

use Cms\Lib\Storage;
use Cms\Lib\Validation;

final class SiteNavigationElement
{
    public const TYPE_NAME = 'SiteNavigationElement';
    public const COLLECTION_FILE = 'site-navigation-elements.json';

    public const FIELDS = [
        'name' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one'],
        'url' => ['kind' => 'scalar', 'type' => 'URL', 'cardinality' => 'one'],
        'description' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one'],
        'position' => ['kind' => 'scalar', 'type' => 'Integer', 'cardinality' => 'one'],
        'isPartOf' => ['kind' => 'ref', 'targets' => ['SiteNavigationElement'], 'cardinality' => 'one'],
    ];

    public const REQUIRED_FIELDS = ['name', 'url'];
    public const SEARCHABLE_FIELDS = ['name', 'description'];
    public const SORTABLE_FIELDS = ['dateCreated', 'dateModified', 'name', 'url', 'description', 'position'];

    private const SYSTEM_FIELDS = ['id', 'dateCreated', 'dateModified', '@context', '@type'];

    private const REF_COLLECTIONS = ['SiteNavigationElement' => 'site-navigation-elements.json'];

    public static function validate(array $data, bool $partial = false): array
    {
        $errors = [];

        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                $errors[] = 'Field names must be strings.';
                continue;
            }
            if (Validation::isDangerousKey($key)) {
                $errors[] = "Unknown field \"$key\".";
                continue;
            }
            if (!array_key_exists($key, self::FIELDS) && !in_array($key, self::SYSTEM_FIELDS, true)) {
                $errors[] = "Unknown field \"$key\".";
            }
        }

        if (!$partial) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (self::isEmpty($data[$field] ?? null)) {
                    $errors[] = "Field \"$field\" is required.";
                }
            }
        } else {
            // A partial update may omit a required field, but must not blank one
            // that is present — that would leave the resource violating its own
            // contract.
            foreach (self::REQUIRED_FIELDS as $field) {
                if (array_key_exists($field, $data) && self::isEmpty($data[$field])) {
                    $errors[] = "Field \"$field\" must not be empty.";
                }
            }
        }

        foreach (self::FIELDS as $name => $spec) {
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $value = $data[$name];
            $errors = array_merge($errors, self::checkField($spec, $value, $name));
        }

        return $errors;
    }

    private static function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if ($value === '') {
            return true;
        }
        if (is_array($value) && count($value) === 0) {
            return true;
        }
        return false;
    }

    private static function checkField(array $spec, mixed $value, string $name): array
    {
        if ($spec['cardinality'] === 'many') {
            if (!is_array($value) || !array_is_list($value)) {
                return ["Field \"$name\" must be an array."];
            }
            $errors = [];
            foreach ($value as $i => $v) {
                $errors = array_merge($errors, self::checkOne($spec, $v, "$name" . '[' . $i . ']'));
            }
            return $errors;
        }
        return self::checkOne($spec, $value, $name);
    }

    private static function checkOne(array $spec, mixed $value, string $path): array
    {
        switch ($spec['kind']) {
            case 'scalar':
                if (!Validation::checkScalar($spec['type'], $value)) {
                    return ["Field \"$path\" must be a {$spec['type']}."];
                }
                return [];
            case 'enum':
                if (!in_array($value, $spec['values'], true)) {
                    $list = implode(', ', $spec['values']);
                    return ["Field \"$path\" must be one of: $list."];
                }
                return [];
            case 'ref':
                if (!Validation::isValidUuid($value)) {
                    return ["Field \"$path\" must be a UUID."];
                }
                return [];
            case 'embed':
                if (!Validation::isEmbed($value, $spec['type'])) {
                    return ["Field \"$path\" must be an inline {$spec['type']} embed with @type set."];
                }
                return [];
        }
        return ["Field \"$path\" has unknown shape."];
    }

    private static function normalizeRefs(array $data): array
    {
        foreach (self::FIELDS as $name => $spec) {
            if ($spec['kind'] !== 'ref' || !array_key_exists($name, $data)) {
                continue;
            }
            if ($spec['cardinality'] === 'many' && is_array($data[$name])) {
                $data[$name] = array_map([Validation::class, 'normalizeUuid'], $data[$name]);
            } elseif (is_string($data[$name])) {
                $data[$name] = Validation::normalizeUuid($data[$name]);
            }
        }
        return $data;
    }

    public static function findAll(array $opts = []): array
    {
        $filter = $opts['filter'] ?? [];
        $sort = $opts['sort'] ?? 'dateCreated';
        $order = $opts['order'] ?? 'desc';
        $limit = $opts['limit'] ?? 20;
        $offset = $opts['offset'] ?? 0;

        $items = Storage::readCollection(self::COLLECTION_FILE);

        foreach ($filter as $field => $value) {
            if (!in_array($field, self::SEARCHABLE_FIELDS, true)) {
                continue;
            }
            $needle = mb_strtolower((string) $value);
            $items = array_values(array_filter($items, static function ($item) use ($field, $needle) {
                return isset($item[$field]) && is_string($item[$field])
                    && str_contains(mb_strtolower($item[$field]), $needle);
            }));
        }

        $sortField = in_array($sort, self::SORTABLE_FIELDS, true) ? $sort : 'dateCreated';
        $direction = $order === 'asc' ? 1 : -1;
        usort($items, static fn ($a, $b) => self::compareForSort($a[$sortField] ?? null, $b[$sortField] ?? null, $direction));

        $total = count($items);
        $slice = array_slice($items, $offset, $limit);
        return ['items' => $slice, 'total' => $total];
    }

    /**
     * Type-aware ordering: numbers numerically, booleans as booleans, everything
     * else lexicographically by string form. Missing values (null) always sort
     * last, regardless of order — never coerced to ''.
     */
    private static function compareForSort(mixed $va, mixed $vb, int $direction): int
    {
        $aMissing = $va === null;
        $bMissing = $vb === null;
        if ($aMissing || $bMissing) {
            if ($aMissing && $bMissing) {
                return 0;
            }
            return $aMissing ? 1 : -1;
        }
        if (is_bool($va) && is_bool($vb)) {
            $cmp = $va <=> $vb;
        } elseif ((is_int($va) || is_float($va)) && (is_int($vb) || is_float($vb))) {
            $cmp = $va <=> $vb;
        } else {
            $cmp = (string) $va <=> (string) $vb;
        }
        return $cmp * $direction;
    }

    public static function findById(string $id): ?array
    {
        if (!Validation::isValidUuid($id)) {
            return null;
        }
        $normalized = Validation::normalizeUuid($id);
        foreach (Storage::readCollection(self::COLLECTION_FILE) as $item) {
            if (($item['id'] ?? null) === $normalized) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Embeds referenced entities one level deep for single-resource GET (JSON-LD
     * style): each ref UUID is replaced by the referenced object. List responses
     * stay flat. Embedded objects keep their own refs as UUIDs; a ref that no
     * longer resolves is left as the stored UUID string.
     */
    public static function embedRefs(array $item): array
    {
        $cache = [];
        $load = static function (string $file) use (&$cache): array {
            if (!array_key_exists($file, $cache)) {
                $cache[$file] = Storage::readCollection($file);
            }
            return $cache[$file];
        };
        $resolveRef = static function (mixed $id, array $targets) use ($load): mixed {
            if (!is_string($id)) {
                return $id;
            }
            foreach ($targets as $target) {
                $file = self::REF_COLLECTIONS[$target] ?? null;
                if ($file === null) {
                    continue;
                }
                foreach ($load($file) as $entry) {
                    if (($entry['id'] ?? null) === $id) {
                        return $entry;
                    }
                }
            }
            return $id;
        };

        foreach (self::FIELDS as $name => $spec) {
            if ($spec['kind'] !== 'ref' || !isset($item[$name])) {
                continue;
            }
            if ($spec['cardinality'] === 'many') {
                if (!is_array($item[$name])) {
                    continue;
                }
                $item[$name] = array_map(
                    static fn ($id) => $resolveRef($id, $spec['targets']),
                    $item[$name],
                );
            } else {
                $item[$name] = $resolveRef($item[$name], $spec['targets']);
            }
        }
        return $item;
    }

    public static function create(array $rawData): array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($rawData) {
            $data = self::normalizeRefs(Validation::deepSanitize($rawData));
            $items = Storage::readCollection(self::COLLECTION_FILE);
            $now = gmdate('Y-m-d\TH:i:s.') . substr(sprintf('%03d', (int) ((microtime(true) - (int) microtime(true)) * 1000)), 0, 3) . 'Z';
            // Client data first, then the system-controlled fields — so a client
            // cannot spoof @context, @type, id or the timestamps via the body.
            $item = array_merge(
                $data,
                [
                    '@context' => 'https://schema.org',
                    '@type' => self::TYPE_NAME,
                    'id' => Validation::generateUuid(),
                    'dateCreated' => $now,
                    'dateModified' => $now,
                ],
            );
            $items[] = $item;
            Storage::writeCollection(self::COLLECTION_FILE, $items);
            return $item;
        });
    }

    public static function update(string $id, array $rawData): ?array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($id, $rawData) {
            $items = Storage::readCollection(self::COLLECTION_FILE);
            $normalized = Validation::normalizeUuid($id);
            $index = null;
            foreach ($items as $i => $item) {
                if (($item['id'] ?? null) === $normalized) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return null;
            }
            $current = $items[$index];
            $data = self::normalizeRefs(Validation::deepSanitize($rawData));
            $now = gmdate('Y-m-d\TH:i:s.') . substr(sprintf('%03d', (int) ((microtime(true) - (int) microtime(true)) * 1000)), 0, 3) . 'Z';
            $updated = array_merge(
                $current,
                $data,
                [
                    '@context' => $current['@context'] ?? 'https://schema.org',
                    '@type' => $current['@type'] ?? self::TYPE_NAME,
                    'id' => $current['id'],
                    'dateCreated' => $current['dateCreated'] ?? $now,
                    'dateModified' => $now,
                ],
            );
            $items[$index] = $updated;
            Storage::writeCollection(self::COLLECTION_FILE, $items);
            return $updated;
        });
    }

    public static function remove(string $id): bool
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($id) {
            $items = Storage::readCollection(self::COLLECTION_FILE);
            $normalized = Validation::normalizeUuid($id);
            $filtered = array_values(array_filter($items, static fn ($item) => ($item['id'] ?? null) !== $normalized));
            if (count($filtered) === count($items)) {
                return false;
            }
            Storage::writeCollection(self::COLLECTION_FILE, $filtered);
            return true;
        });
    }

    public static function etagOf(array $item): string
    {
        return Validation::etagFor($item);
    }
}
