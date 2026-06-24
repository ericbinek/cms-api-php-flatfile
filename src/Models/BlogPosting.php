<?php
declare(strict_types=1);

namespace Cms\Models;

use Cms\Lib\Storage;
use Cms\Lib\Validation;

final class BlogPosting
{
    public const TYPE_NAME = 'BlogPosting';
    public const COLLECTION_FILE = 'blog-postings.json';

    public const FIELDS = [
        'headline' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one', 'maxLength' => 256],
        'alternativeHeadline' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one', 'maxLength' => 256],
        'description' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one', 'maxLength' => 5000, 'multiline' => true],
        'articleBody' => ['kind' => 'scalar', 'type' => 'Text', 'cardinality' => 'one', 'maxLength' => 65536, 'multiline' => true],
        'author' => ['kind' => 'ref', 'targets' => ['Person'], 'cardinality' => 'one'],
        'publisher' => ['kind' => 'ref', 'targets' => ['Organization'], 'cardinality' => 'one'],
        'image' => ['kind' => 'ref', 'targets' => ['ImageObject'], 'cardinality' => 'many'],
        'video' => ['kind' => 'ref', 'targets' => ['VideoObject'], 'cardinality' => 'many'],
        'audio' => ['kind' => 'ref', 'targets' => ['AudioObject'], 'cardinality' => 'many'],
        'keywords' => ['kind' => 'ref', 'targets' => ['DefinedTerm'], 'cardinality' => 'many'],
        'about' => ['kind' => 'ref', 'targets' => ['CategoryCode'], 'cardinality' => 'many'],
        'datePublished' => ['kind' => 'scalar', 'type' => 'DateTime', 'cardinality' => 'one'],
        'dateModified' => ['kind' => 'scalar', 'type' => 'DateTime', 'cardinality' => 'one'],
        'dateCreated' => ['kind' => 'scalar', 'type' => 'DateTime', 'cardinality' => 'one'],
        'url' => ['kind' => 'scalar', 'type' => 'URL', 'cardinality' => 'one', 'maxLength' => 2048],
        'inLanguage' => ['kind' => 'embed', 'type' => 'Language', 'cardinality' => 'one'],
        'isAccessibleForFree' => ['kind' => 'scalar', 'type' => 'Boolean', 'cardinality' => 'one'],
        'wordCount' => ['kind' => 'scalar', 'type' => 'Integer', 'cardinality' => 'one'],
        'creativeWorkStatus' => ['kind' => 'enum', 'values' => ['Draft', 'Pending', 'Published', 'Archived'], 'cardinality' => 'one'],
    ];

    public const REQUIRED_FIELDS = ['headline', 'articleBody', 'author', 'url'];
    public const SEARCHABLE_FIELDS = ['headline', 'alternativeHeadline', 'description', 'articleBody'];
    public const SORTABLE_FIELDS = ['dateCreated', 'dateModified', 'headline', 'alternativeHeadline', 'description', 'articleBody', 'datePublished', 'dateModified', 'dateCreated', 'url', 'isAccessibleForFree', 'wordCount', 'creativeWorkStatus'];

    // Properties whose combined value must be unique across the collection.
    // Empty when the entity allows duplicates.
    public const UNIQUE_KEY = ['url'];

    private const SYSTEM_FIELDS = ['id', 'dateCreated', 'dateModified', '@context', '@type'];

    private const REF_COLLECTIONS = ['Person' => 'persons.json', 'Organization' => 'organizations.json', 'ImageObject' => 'image-objects.json', 'VideoObject' => 'video-objects.json', 'AudioObject' => 'audio-objects.json', 'DefinedTerm' => 'defined-terms.json', 'CategoryCode' => 'category-codes.json'];

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
                if (isset($spec['maxLength']) && is_string($value) && mb_strlen($value) > $spec['maxLength']) {
                    return ["Field \"$path\" must be at most {$spec['maxLength']} characters."];
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

    // Field-aware input cleaning, run before validation and storage: each known
    // scalar string is normalized, stripped of control characters and trimmed,
    // with long-form (multiline) fields keeping their internal line breaks. Refs,
    // embeds, arrays and other values fall back to the conservative property-blind
    // sanitizer. The body is cleaned in place: every key is left where it is —
    // dangerous keys (__proto__, …) are deliberately untouched so validate() can
    // reject the body, rather than silently dropped here.
    public static function sanitize(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (is_string($key) && Validation::isDangerousKey($key)) {
                continue;
            }
            $value = $data[$key];
            $spec = self::FIELDS[$key] ?? null;
            if ($spec !== null && $spec['kind'] === 'scalar' && is_string($value)) {
                $data[$key] = Validation::sanitizeString($value, $spec['multiline'] ?? false);
            } else {
                $data[$key] = Validation::deepSanitize($value);
            }
        }
        return $data;
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

    /**
     * A candidate collides when some other record shares every unique-key value.
     * Comparison runs on already-sanitized, ref-normalized data, so equal values
     * are in canonical form. Entities without a key never collide.
     */
    private static function violatesUniqueKey(array $items, array $candidate, ?string $excludeId): bool
    {
        if (count(self::UNIQUE_KEY) === 0) {
            return false;
        }
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $excludeId) {
                continue;
            }
            $match = true;
            foreach (self::UNIQUE_KEY as $field) {
                if (($item[$field] ?? null) !== ($candidate[$field] ?? null)) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }
        return false;
    }

    private static function duplicateError(): \Cms\DuplicateException
    {
        $fields = implode(' and ', self::UNIQUE_KEY);
        $message = 'A ' . self::TYPE_NAME . ' with this ' . $fields . ' already exists.';
        return new \Cms\DuplicateException([$message]);
    }

    public static function create(array $rawData): array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($rawData) {
            $data = self::normalizeRefs($rawData);
            $items = Storage::readCollection(self::COLLECTION_FILE);
            if (self::violatesUniqueKey($items, $data, null)) {
                throw self::duplicateError();
            }
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
            $data = self::normalizeRefs($rawData);
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
            if (self::violatesUniqueKey($items, $updated, $current['id'])) {
                throw self::duplicateError();
            }
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
