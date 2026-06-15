<?php
declare(strict_types=1);

namespace Cms\Lib;

use Cms\Models\Account;

// Thrown when a credential is presented but does not resolve. The server maps it
// to 401 UNAUTHORIZED. A missing credential is not an error — it is anonymous.
final class UnauthorizedException extends \RuntimeException
{
}

final class Auth
{
    // HTTP methods that mutate state. No role grants anonymous writes, so any of
    // these without a session is a 401 before routing.
    public const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public const ANONYMOUS = ['role' => 'anonymous', 'accountId' => null, 'username' => null];

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($header === null) {
            return null;
        }
        if (preg_match('/^Bearer (.+)$/', trim($header), $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * Resolves the request principal. No Authorization header -> anonymous. A
     * Bearer token that does not resolve to a live session (or a malformed header)
     * throws UnauthorizedException. Fails closed: a presented credential must be
     * valid.
     *
     * @return array{role: string, accountId: ?string, username: ?string}
     */
    public static function resolvePrincipal(): array
    {
        $token = self::bearerToken();
        if ($token === null) {
            return self::ANONYMOUS;
        }
        if ($token === '') {
            throw new UnauthorizedException('Authentication required.');
        }
        $session = Sessions::resolveSession($token);
        if ($session === null) {
            throw new UnauthorizedException('Authentication required.');
        }
        $account = Account::findById($session['accountId']);
        if ($account === null) {
            throw new UnauthorizedException('Authentication required.');
        }
        return [
            'role' => $account['role'],
            'accountId' => $account['id'],
            'username' => $account['username'],
        ];
    }

    /**
     * A write method by an unauthenticated principal needs a session: 401 (Guards
     * for an authenticated-but-unauthorized principal are the router's 403).
     *
     * @param array{role: string, accountId: ?string, username: ?string} $principal
     */
    public static function requiresSession(string $method, array $principal): bool
    {
        return in_array($method, self::WRITE_METHODS, true) && $principal['role'] === 'anonymous';
    }
}
