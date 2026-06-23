<?php
declare(strict_types=1);

namespace Cms\Routers;

use Cms\Http;
use Cms\Errors;
use Cms\Lib\Access;
use Cms\Models\DefinedTermSet;

final class DefinedTermSetRouter
{
    public const BASE = '/defined-term-sets';
    private const ENTITY = 'DefinedTermSet';
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;
    private const SYSTEM_FILTER_KEYS = ['limit', 'offset', 'sort', 'order'];

    /**
     * @param array{role: string, accountId: ?string, username: ?string} $principal
     */
    public static function handle(string $method, string $path, string $requestPath, array $principal): bool
    {
        if ($path === self::BASE) {
            self::handleCollection($method, $requestPath, $principal);
            return true;
        }
        if (str_starts_with($path, self::BASE . '/')) {
            $rest = substr($path, strlen(self::BASE) + 1);
            if (str_contains($rest, '/')) {
                return false;
            }
            self::handleItem($method, $rest, $requestPath, $principal);
            return true;
        }
        return false;
    }

    /**
     * @param array{role: string, accountId: ?string, username: ?string} $principal
     */
    private static function handleCollection(string $method, string $requestPath, array $principal): void
    {
        $role = $principal['role'];

        if ($method === 'GET') {
            if (!Access::can($role, self::ENTITY, 'read')) {
                Http::jsonError(Errors::forbidden("Role \"$role\" may not read " . self::ENTITY . '.', $requestPath));
                return;
            }
            $opts = self::parseListOptions();
            if (!empty($opts['errors'])) {
                Http::jsonError(Errors::validation($opts['errors'], $requestPath));
                return;
            }
            // Apply read visibility on the full filtered set, then paginate, so
            // total counts only the records this principal may see. Internal
            // fields stripped.
            $offset = $opts['offset'];
            $limit = $opts['limit'];
            $opts['offset'] = 0;
            $opts['limit'] = PHP_INT_MAX;
            unset($opts['errors']);
            $all = DefinedTermSet::findAll($opts);
            $visible = array_values(array_filter(
                $all['items'],
                static fn ($item) => Access::isVisible($role, self::ENTITY, $item),
            ));
            $items = array_map(
                static fn ($item) => Access::stripFields($role, $item),
                array_slice($visible, $offset, $limit),
            );
            Http::json(200, ['items' => $items, 'total' => count($visible)]);
            return;
        }

        if ($method === 'POST') {
            if (!Access::can($role, self::ENTITY, 'create')) {
                Http::jsonError(Errors::forbidden("Role \"$role\" may not create " . self::ENTITY . '.', $requestPath));
                return;
            }
            $body = DefinedTermSet::sanitize(Http::parseBody());
            $readonly = Access::readonlyViolations($role, $body);
            if (!empty($readonly)) {
                Http::jsonError(Errors::validation(['Fields are not writable: ' . implode(', ', $readonly) . '.'], $requestPath));
                return;
            }
            $errors = DefinedTermSet::validate($body);
            if (!empty($errors)) {
                Http::jsonError(Errors::validation($errors, $requestPath));
                return;
            }
            $created = DefinedTermSet::create(Access::applyCreateDefaults(self::ENTITY, $body, $principal['accountId']));
            Http::setLocation(self::BASE . '/' . $created['id']);
            Http::json(201, Access::stripFields($role, $created));
            return;
        }

        Http::jsonError(Errors::methodNotAllowed(['GET', 'POST'], $requestPath));
    }

    /**
     * @param array{role: string, accountId: ?string, username: ?string} $principal
     */
    private static function handleItem(string $method, string $id, string $requestPath, array $principal): void
    {
        $role = $principal['role'];

        if (!Http::isValidUuid($id)) {
            Http::jsonError(Errors::invalidId($requestPath));
            return;
        }

        if ($method === 'GET') {
            if (!Access::can($role, self::ENTITY, 'read')) {
                Http::jsonError(Errors::forbidden("Role \"$role\" may not read " . self::ENTITY . '.', $requestPath));
                return;
            }
            $item = DefinedTermSet::findById($id);
            // A record the principal may not see is indistinguishable from a
            // missing one (404, never 403) so its existence is not disclosed.
            if ($item === null || !Access::isVisible($role, self::ENTITY, $item)) {
                Http::jsonError(Errors::notFound(DefinedTermSet::TYPE_NAME, $requestPath));
                return;
            }
            Http::json(200, Access::stripFields($role, DefinedTermSet::embedRefs($item)));
            return;
        }

        if ($method === 'PUT') {
            if (!Access::can($role, self::ENTITY, 'update')) {
                Http::jsonError(Errors::forbidden("Role \"$role\" may not update " . self::ENTITY . '.', $requestPath));
                return;
            }
            $body = DefinedTermSet::sanitize(Http::parseBody());
            $readonly = Access::readonlyViolations($role, $body);
            if (!empty($readonly)) {
                Http::jsonError(Errors::validation(['Fields are not writable: ' . implode(', ', $readonly) . '.'], $requestPath));
                return;
            }
            $errors = DefinedTermSet::validate($body, partial: true);
            if (!empty($errors)) {
                Http::jsonError(Errors::validation($errors, $requestPath));
                return;
            }
            $current = DefinedTermSet::findById($id);
            if ($current === null) {
                Http::jsonError(Errors::notFound(DefinedTermSet::TYPE_NAME, $requestPath));
                return;
            }
            $ownerField = Access::ownershipField($role, 'update');
            if ($ownerField !== null && ($current[$ownerField] ?? null) !== $principal['accountId']) {
                Http::jsonError(Errors::forbidden('You may only modify your own records.', $requestPath));
                return;
            }
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
            if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== DefinedTermSet::etagOf($current)) {
                Http::jsonError(Errors::preconditionFailed($requestPath));
                return;
            }
            $status = Access::statusProperty(self::ENTITY);
            if ($status !== null && array_key_exists($status, $body) && ($body[$status] ?? null) !== ($current[$status] ?? null)) {
                if (!Access::transitionAllowed(self::ENTITY, $current[$status] ?? null, $body[$status], $role)) {
                    Http::jsonError(Errors::forbidden(
                        'Status transition ' . ($current[$status] ?? 'null') . ' -> ' . $body[$status] . " is not allowed for role \"$role\".",
                        $requestPath,
                    ));
                    return;
                }
            }
            $updated = DefinedTermSet::update($id, $body);
            Http::json(200, Access::stripFields($role, $updated));
            return;
        }

        if ($method === 'DELETE') {
            if (!Access::can($role, self::ENTITY, 'delete')) {
                Http::jsonError(Errors::forbidden("Role \"$role\" may not delete " . self::ENTITY . '.', $requestPath));
                return;
            }
            $current = DefinedTermSet::findById($id);
            if ($current === null) {
                Http::jsonError(Errors::notFound(DefinedTermSet::TYPE_NAME, $requestPath));
                return;
            }
            $ownerField = Access::ownershipField($role, 'delete');
            if ($ownerField !== null && ($current[$ownerField] ?? null) !== $principal['accountId']) {
                Http::jsonError(Errors::forbidden('You may only delete your own records.', $requestPath));
                return;
            }
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
            if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== DefinedTermSet::etagOf($current)) {
                Http::jsonError(Errors::preconditionFailed($requestPath));
                return;
            }
            DefinedTermSet::remove($id);
            Http::json(204, null);
            return;
        }

        Http::jsonError(Errors::methodNotAllowed(['GET', 'PUT', 'DELETE'], $requestPath));
    }

    private static function parseListOptions(): array
    {
        $errors = [];
        $sp = $_GET;

        $limit = self::DEFAULT_LIMIT;
        if (isset($sp['limit'])) {
            $raw = $sp['limit'];
            if (!ctype_digit((string) $raw) || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
                $errors[] = 'Query "limit" must be an integer between 1 and ' . self::MAX_LIMIT . '.';
            } else {
                $limit = (int) $raw;
            }
        }

        $offset = 0;
        if (isset($sp['offset'])) {
            $raw = $sp['offset'];
            if (!ctype_digit((string) $raw)) {
                $errors[] = 'Query "offset" must be a non-negative integer.';
            } else {
                $offset = (int) $raw;
            }
        }

        $sort = 'dateCreated';
        if (isset($sp['sort'])) {
            if (!in_array($sp['sort'], DefinedTermSet::SORTABLE_FIELDS, true)) {
                $sorted = DefinedTermSet::SORTABLE_FIELDS;
                sort($sorted);
                $errors[] = 'Query "sort" must be one of: ' . implode(', ', $sorted) . '.';
            } else {
                $sort = $sp['sort'];
            }
        }

        $order = 'desc';
        if (isset($sp['order'])) {
            if ($sp['order'] !== 'asc' && $sp['order'] !== 'desc') {
                $errors[] = 'Query "order" must be "asc" or "desc".';
            } else {
                $order = $sp['order'];
            }
        }

        $filter = [];
        foreach ($sp as $key => $value) {
            if (in_array($key, self::SYSTEM_FILTER_KEYS, true)) continue;
            if (!in_array($key, DefinedTermSet::SEARCHABLE_FIELDS, true)) {
                $errors[] = "Unknown filter field \"$key\".";
                continue;
            }
            $filter[$key] = $value;
        }

        return [
            'limit' => $limit,
            'offset' => $offset,
            'sort' => $sort,
            'order' => $order,
            'filter' => $filter,
            'errors' => $errors,
        ];
    }
}
