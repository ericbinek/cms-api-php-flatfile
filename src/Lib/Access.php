<?php
declare(strict_types=1);

namespace Cms\Lib;

// Compiled access policy for this target, derived from the project-wide access/
// authority (roles.json, field-access.json, workflow.json). Pure data plus pure
// helpers — no IO, no request handling. The router and server enforce it.
final class Access
{
    private const POLICY = [
        'operations' => [
            'read',
            'create',
            'update',
            'delete',
        ],
        'roles' => [
            'admin' => [
                'description' => 'Full access to every entity plus account management.',
                'matrix' => [
                    '*' => [
                        'read',
                        'create',
                        'update',
                        'delete',
                    ],
                ],
                'accountManagement' => true,
            ],
            'editor' => [
                'description' => 'Full CRUD on every entity. Drives the publication workflow.',
                'matrix' => [
                    '*' => [
                        'read',
                        'create',
                        'update',
                        'delete',
                    ],
                ],
            ],
            'author' => [
                'description' => 'Reads and creates every entity, but updates and deletes only own records.',
                'matrix' => [
                    '*' => [
                        'read',
                        'create',
                        'update',
                        'delete',
                    ],
                ],
                'ownership' => [
                    'scope' => 'own',
                    'operations' => [
                        'update',
                        'delete',
                    ],
                    'field' => 'createdBy',
                ],
            ],
            'viewer' => [
                'description' => 'Authenticated read only across every entity, including non public status.',
                'matrix' => [
                    '*' => [
                        'read',
                    ],
                ],
            ],
            'anonymous' => [
                'description' => 'Unauthenticated read, no session. Restricted to publicly visible records via the read visibility rule.',
                'matrix' => [
                    '*' => [
                        'read',
                    ],
                ],
                'read' => [
                    'visibility' => 'public',
                ],
            ],
        ],
        'visibility' => [
            'description' => 'Read visibility scopes a role read rule can reference. "all" returns every record, so reads stay backward compatible with the current auth free API. "public" restricts status bearing entities to their public states defined in access/workflow.json, and where a datePublished property exists it must be reached; entities without a status enum stay fully readable either way. Which scope the anonymous role ships with at rollout is the open decision for the API auth block, see docs/auth/implementation-plan.md.',
            'scopes' => [
                'all',
                'public',
            ],
        ],
        'fieldGroups' => [
            'system' => [
                'id',
                'dateCreated',
                'dateModified',
            ],
            'internal' => [
                'createdBy',
            ],
        ],
        'fieldRules' => [
            '*' => [
                'read' => [
                    'deny' => [
                        '@internal',
                    ],
                ],
                'write' => [
                    'deny' => [
                        '@system',
                        '@internal',
                    ],
                ],
            ],
        ],
        'workflow' => [
            'BlogPosting' => [
                'statusProperty' => 'creativeWorkStatus',
                'initial' => 'Draft',
                'public' => [
                    'Published',
                ],
                'transitions' => [
                    [
                        'from' => 'Draft',
                        'to' => 'Pending',
                        'roles' => [
                            'author',
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Pending',
                        'to' => 'Draft',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Pending',
                        'to' => 'Published',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Published',
                        'to' => 'Archived',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Archived',
                        'to' => 'Published',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                ],
                'hasPublishDate' => true,
            ],
            'WebPage' => [
                'statusProperty' => 'creativeWorkStatus',
                'initial' => 'Draft',
                'public' => [
                    'Published',
                ],
                'transitions' => [
                    [
                        'from' => 'Draft',
                        'to' => 'Pending',
                        'roles' => [
                            'author',
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Pending',
                        'to' => 'Draft',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Pending',
                        'to' => 'Published',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Published',
                        'to' => 'Archived',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Archived',
                        'to' => 'Published',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                ],
                'hasPublishDate' => true,
            ],
            'Comment' => [
                'statusProperty' => 'creativeWorkStatus',
                'initial' => 'Pending',
                'public' => [
                    'Approved',
                ],
                'transitions' => [
                    [
                        'from' => 'Pending',
                        'to' => 'Approved',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Pending',
                        'to' => 'Spam',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Approved',
                        'to' => 'Spam',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Approved',
                        'to' => 'Trash',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                    [
                        'from' => 'Spam',
                        'to' => 'Trash',
                        'roles' => [
                            'editor',
                            'admin',
                        ],
                    ],
                ],
                'hasPublishDate' => false,
            ],
        ],
    ];

    /**
     * Resolves a role's field rule for a mode (read/write) into a concrete deny
     * set, expanding the group references @system and @internal. A per-role rule
     * wins over the "*" default. "deny" wins; an absent rule denies nothing.
     *
     * @return array<string, true>
     */
    private static function denySet(string $role, string $mode): array
    {
        $rules = self::POLICY['fieldRules'];
        $rule = $rules[$role][$mode] ?? $rules['*'][$mode] ?? [];
        $groups = self::POLICY['fieldGroups'];
        $deny = [];
        foreach ($rule['deny'] ?? [] as $entry) {
            if ($entry === '@system') {
                foreach ($groups['system'] ?? [] as $f) {
                    $deny[$f] = true;
                }
            } elseif ($entry === '@internal') {
                foreach ($groups['internal'] ?? [] as $f) {
                    $deny[$f] = true;
                }
            } else {
                $deny[$entry] = true;
            }
        }
        return $deny;
    }

    /**
     * The fields no client may ever write (system + internal), i.e. the default
     * write deny resolved. Exposed for request builders and tests.
     *
     * @return list<string>
     */
    public static function readonlyFields(): array
    {
        return array_keys(self::denySet('*', 'write'));
    }

    // Type-level: may $role perform $op on $entity? A per-entity matrix entry
    // overrides the "*" default for that entity only.
    public static function can(string $role, string $entity, string $op): bool
    {
        $roles = self::POLICY['roles'];
        if (!isset($roles[$role]['matrix'])) {
            return false;
        }
        $matrix = $roles[$role]['matrix'];
        $ops = array_key_exists($entity, $matrix) ? $matrix[$entity] : ($matrix['*'] ?? []);
        return is_array($ops) && in_array($op, $ops, true);
    }

    // Ownership: the owner field name if $role is restricted to its own records
    // for $op (e.g. author update/delete -> "createdBy"), else null.
    public static function ownershipField(string $role, string $op): ?string
    {
        $own = self::POLICY['roles'][$role]['ownership'] ?? null;
        if ($own === null || !in_array($op, $own['operations'], true)) {
            return null;
        }
        return $own['field'];
    }

    public static function isGoverned(string $entity): bool
    {
        return array_key_exists($entity, self::POLICY['workflow']);
    }

    public static function statusProperty(string $entity): ?string
    {
        return self::isGoverned($entity) ? self::POLICY['workflow'][$entity]['statusProperty'] : null;
    }

    public static function initialStatus(string $entity): ?string
    {
        return self::isGoverned($entity) ? self::POLICY['workflow'][$entity]['initial'] : null;
    }

    // May $role move $entity from $from to $to? Non-governed entities and no-op
    // transitions ($from === $to) are always allowed; everything else must be
    // modelled.
    public static function transitionAllowed(string $entity, mixed $from, mixed $to, string $role): bool
    {
        if (!self::isGoverned($entity)) {
            return true;
        }
        if ($from === $to) {
            return true;
        }
        foreach (self::POLICY['workflow'][$entity]['transitions'] as $t) {
            if ($t['from'] === $from && $t['to'] === $to && in_array($role, $t['roles'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Field-level write: the names in $body a $role is not allowed to set (system
     * and internal fields). Any hit is a 400, not a silent drop.
     *
     * @return list<string>
     */
    public static function readonlyViolations(string $role, mixed $body): array
    {
        if (!is_array($body) || array_is_list($body)) {
            return [];
        }
        $deny = self::denySet($role, 'write');
        $hits = [];
        foreach (array_keys($body) as $key) {
            if (is_string($key) && isset($deny[$key])) {
                $hits[] = $key;
            }
        }
        return $hits;
    }

    // Field-level read: strip denied (internal) fields from a value before it
    // leaves the server, recursing into arrays and embedded objects so embeds are
    // covered.
    public static function stripFields(string $role, mixed $value): mixed
    {
        $deny = self::denySet($role, 'read');
        return self::walkStrip($value, $deny);
    }

    private static function walkStrip(mixed $value, array $deny): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn ($v) => self::walkStrip($v, $deny), $value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && isset($deny[$k])) {
                continue;
            }
            $out[$k] = self::walkStrip($v, $deny);
        }
        return $out;
    }

    /**
     * On create the server stamps ownership (createdBy) and forces the workflow
     * entry state, overriding any client-supplied status.
     */
    public static function applyCreateDefaults(string $entity, array $data, ?string $accountId): array
    {
        $data['createdBy'] = $accountId;
        $initial = self::initialStatus($entity);
        if ($initial !== null) {
            $data[self::statusProperty($entity)] = $initial;
        }
        return $data;
    }

    // Anonymous read visibility: "public" gates status-bearing entities to their
    // public states (and a reached datePublished where the entity has one); "all"
    // returns every record. Internal fields are stripped under either scope.
    private static function readVisibility(string $role): string
    {
        return self::POLICY['roles'][$role]['read']['visibility'] ?? 'all';
    }

    public static function isVisible(string $role, string $entity, array $item): bool
    {
        if (self::readVisibility($role) !== 'public') {
            return true;
        }
        if (!self::isGoverned($entity)) {
            return true;
        }
        $wf = self::POLICY['workflow'][$entity];
        if (!in_array($item[$wf['statusProperty']] ?? null, $wf['public'], true)) {
            return false;
        }
        if ($wf['hasPublishDate']) {
            $published = $item['datePublished'] ?? null;
            if (!is_string($published)) {
                return false;
            }
            $at = strtotime($published);
            if ($at === false || $at > time()) {
                return false;
            }
        }
        return true;
    }
}
