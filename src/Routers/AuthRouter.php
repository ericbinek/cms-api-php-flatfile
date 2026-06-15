<?php
declare(strict_types=1);

namespace Cms\Routers;

use Cms\Http;
use Cms\Errors;
use Cms\Models\Account;
use Cms\Lib\Sessions;

final class AuthRouter
{
    private const BASE = '/auth';

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($header === null) {
            return null;
        }
        return preg_match('/^Bearer (.+)$/', trim($header), $m) === 1 ? $m[1] : null;
    }

    /**
     * The principal is resolved by the server middleware before routing. login is
     * reachable anonymously; logout and me require a live session.
     *
     * @param array{role: string, accountId: ?string, username: ?string} $principal
     */
    public static function handle(string $method, string $path, string $requestPath, array $principal): bool
    {
        if ($path === self::BASE . '/login') {
            if ($method !== 'POST') {
                Http::jsonError(Errors::methodNotAllowed(['POST'], $requestPath));
                return true;
            }
            $body = Http::parseBody();
            $username = $body['username'] ?? null;
            $password = $body['password'] ?? null;
            if (!is_string($username) || !is_string($password)) {
                Http::jsonError(Errors::validation(
                    ['Fields "username" and "password" are required.'],
                    $requestPath,
                ));
                return true;
            }
            // Same 401 for unknown user and wrong password — no user enumeration.
            $account = Account::authenticate($username, $password);
            if ($account === null) {
                Http::jsonError(Errors::unauthorized($requestPath));
                return true;
            }
            $session = Sessions::createSession($account['id']);
            Http::json(200, [
                'token' => $session['token'],
                'account' => [
                    'id' => $account['id'],
                    'username' => $account['username'],
                    'role' => $account['role'],
                ],
                'expiresAt' => $session['expiresAt'],
            ]);
            return true;
        }

        if ($path === self::BASE . '/logout') {
            if ($method !== 'POST') {
                Http::jsonError(Errors::methodNotAllowed(['POST'], $requestPath));
                return true;
            }
            // Idempotent by token: a missing or already-deleted token is 401.
            $token = self::bearerToken();
            $removed = $token !== null ? Sessions::deleteSession($token) : false;
            if (!$removed) {
                Http::jsonError(Errors::unauthorized($requestPath));
                return true;
            }
            Http::json(204, null);
            return true;
        }

        if ($path === self::BASE . '/me') {
            if ($method !== 'GET') {
                Http::jsonError(Errors::methodNotAllowed(['GET'], $requestPath));
                return true;
            }
            if ($principal['role'] === 'anonymous') {
                Http::jsonError(Errors::unauthorized($requestPath));
                return true;
            }
            Http::json(200, [
                'account' => [
                    'id' => $principal['accountId'],
                    'username' => $principal['username'],
                    'role' => $principal['role'],
                ],
            ]);
            return true;
        }

        return false;
    }
}
