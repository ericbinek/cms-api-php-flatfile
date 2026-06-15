<?php
declare(strict_types=1);

namespace Cms\Models;

use Cms\Lib\Storage;
use Cms\Lib\Validation;

// Internal account store: credentials and roles for the auth layer. Never routed
// through the entity CRUD path and never serialized to the public API.
final class Account
{
    private const COLLECTION_FILE = 'accounts.json';

    // password_hash with the default (currently bcrypt) algorithm — a built-in,
    // salted, slow KDF that embeds the algo, cost and salt in the stored string,
    // so a future cost bump can verify old hashes and rehash on next login.
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, mixed $stored): bool
    {
        return is_string($stored) && password_verify($password, $stored);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        foreach (Storage::readCollection(self::COLLECTION_FILE) as $account) {
            if (($account['username'] ?? null) === $username) {
                return $account;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(string $id): ?array
    {
        foreach (Storage::readCollection(self::COLLECTION_FILE) as $account) {
            if (($account['id'] ?? null) === $id) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Authenticates a username/password pair. An unknown username still runs one
     * password_verify against a freshly computed dummy hash, so the response time
     * does not reveal whether the username existed (no user enumeration).
     *
     * @return array<string, mixed>|null
     */
    public static function authenticate(string $username, string $password): ?array
    {
        $account = self::findByUsername($username);
        $stored = $account !== null ? ($account['passwordHash'] ?? '') : self::dummyHash();
        $ok = self::verifyPassword($password, $stored);
        return ($ok && $account !== null) ? $account : null;
    }

    private static function dummyHash(): string
    {
        return self::hashPassword(bin2hex(random_bytes(16)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function createAccount(string $username, string $password, string $role): array
    {
        return Storage::withLock(self::COLLECTION_FILE, function () use ($username, $password, $role): array {
            $accounts = Storage::readCollection(self::COLLECTION_FILE);
            foreach ($accounts as $existing) {
                if (($existing['username'] ?? null) === $username) {
                    throw new \RuntimeException("Account already exists: $username");
                }
            }
            $account = [
                'id' => Validation::generateUuid(),
                'username' => $username,
                'passwordHash' => self::hashPassword($password),
                'role' => $role,
            ];
            $accounts[] = $account;
            Storage::writeCollection(self::COLLECTION_FILE, $accounts);
            return $account;
        });
    }

    /**
     * Bootstrap: with an empty store and ADMIN_USER/ADMIN_PASSWORD set, the first
     * start creates a single admin. Idempotent — a populated store is a no-op, and
     * missing env vars leave the store empty (every protected write then 401s).
     *
     * @return array<string, mixed>|null
     */
    public static function seedAdmin(): ?array
    {
        return Storage::withLock(self::COLLECTION_FILE, function (): ?array {
            $user = getenv('ADMIN_USER') ?: ($_ENV['ADMIN_USER'] ?? '');
            $password = getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? '');
            if ($user === '' || $password === '') {
                return null;
            }
            $accounts = Storage::readCollection(self::COLLECTION_FILE);
            if (count($accounts) > 0) {
                return null;
            }
            $account = [
                'id' => Validation::generateUuid(),
                'username' => $user,
                'passwordHash' => self::hashPassword($password),
                'role' => 'admin',
            ];
            Storage::writeCollection(self::COLLECTION_FILE, [$account]);
            return $account;
        });
    }
}
