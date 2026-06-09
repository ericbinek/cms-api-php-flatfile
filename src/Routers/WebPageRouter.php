<?php
declare(strict_types=1);

namespace Cms\Routers;

use Cms\Http;
use Cms\Errors;
use Cms\Models\WebPage;

final class WebPageRouter
{
    public const BASE = '/web-pages';
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 20;
    private const SYSTEM_FILTER_KEYS = ['limit', 'offset', 'sort', 'order'];

    public static function handle(string $method, string $path, string $requestPath): bool
    {
        if ($path === self::BASE) {
            self::handleCollection($method, $requestPath);
            return true;
        }
        if (str_starts_with($path, self::BASE . '/')) {
            $rest = substr($path, strlen(self::BASE) + 1);
            if (str_contains($rest, '/')) {
                return false;
            }
            self::handleItem($method, $rest, $requestPath);
            return true;
        }
        return false;
    }

    private static function handleCollection(string $method, string $requestPath): void
    {
        if ($method === 'GET') {
            $opts = self::parseListOptions();
            if (!empty($opts['errors'])) {
                Http::jsonError(Errors::validation($opts['errors'], $requestPath));
                return;
            }
            unset($opts['errors']);
            Http::json(200, WebPage::findAll($opts));
            return;
        }
        if ($method === 'POST') {
            $body = Http::parseBody();
            $errors = WebPage::validate($body);
            if (!empty($errors)) {
                Http::jsonError(Errors::validation($errors, $requestPath));
                return;
            }
            $created = WebPage::create($body);
            Http::setLocation(self::BASE . '/' . $created['id']);
            Http::json(201, $created);
            return;
        }
        Http::jsonError(Errors::methodNotAllowed(['GET', 'POST'], $requestPath));
    }

    private static function handleItem(string $method, string $id, string $requestPath): void
    {
        if (!Http::isValidUuid($id)) {
            Http::jsonError(Errors::invalidId($requestPath));
            return;
        }

        if ($method === 'GET') {
            $item = WebPage::findById($id);
            if ($item === null) {
                Http::jsonError(Errors::notFound(WebPage::TYPE_NAME, $requestPath));
                return;
            }
            Http::json(200, WebPage::embedRefs($item));
            return;
        }

        if ($method === 'PUT') {
            $body = Http::parseBody();
            $errors = WebPage::validate($body, partial: true);
            if (!empty($errors)) {
                Http::jsonError(Errors::validation($errors, $requestPath));
                return;
            }
            $current = WebPage::findById($id);
            if ($current === null) {
                Http::jsonError(Errors::notFound(WebPage::TYPE_NAME, $requestPath));
                return;
            }
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
            if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== WebPage::etagOf($current)) {
                Http::jsonError(Errors::preconditionFailed($requestPath));
                return;
            }
            $updated = WebPage::update($id, $body);
            Http::json(200, $updated);
            return;
        }

        if ($method === 'DELETE') {
            $current = WebPage::findById($id);
            if ($current === null) {
                Http::jsonError(Errors::notFound(WebPage::TYPE_NAME, $requestPath));
                return;
            }
            $ifMatch = $_SERVER['HTTP_IF_MATCH'] ?? null;
            if ($ifMatch !== null && $ifMatch !== '*' && $ifMatch !== WebPage::etagOf($current)) {
                Http::jsonError(Errors::preconditionFailed($requestPath));
                return;
            }
            WebPage::remove($id);
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
            if (!in_array($sp['sort'], WebPage::SORTABLE_FIELDS, true)) {
                $sorted = WebPage::SORTABLE_FIELDS;
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
            if (!in_array($key, WebPage::SEARCHABLE_FIELDS, true)) {
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
