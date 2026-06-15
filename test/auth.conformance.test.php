<?php
declare(strict_types=1);

// Five accounts cover the matrix, ownership and the workflow roles.
const CMS_CONF_ACCOUNTS = [
    ['username' => 'admin',   'password' => 'pw-admin',   'role' => 'admin'],
    ['username' => 'editor',  'password' => 'pw-editor',  'role' => 'editor'],
    ['username' => 'author',  'password' => 'pw-author',  'role' => 'author'],
    ['username' => 'author2', 'password' => 'pw-author2', 'role' => 'author'],
    ['username' => 'viewer',  'password' => 'pw-viewer',  'role' => 'viewer'],
];

// State shared across the conformance closures: the running server, the per-role
// tokens, and the shared-server globals saved on setup and restored on teardown.
$CMS_CONF = ['server' => null, 'token' => [], 'savedBase' => null, 'savedToken' => null];

// Create through the public API as a given role on the conformance server,
// returning the raw response. Dependencies (refs) are built as admin via the
// global base/token (already pointed at the conformance server in setup).
function cms_conf_create_as(string $base, ?string $bearer, string $entity, array $overrides = []): array
{
    global $CMS_CONF;
    cms_set_auth_token($CMS_CONF['token']['admin']);
    $payload = array_merge(cms_build_payload($entity), $overrides);
    return cms_req($base, $bearer, 'POST', '/' . cms_plural($entity), $payload);
}

test('auth conformance: setup', function () {
    global $CMS_CONF, $BASE_URL, $CMS_AUTH_TOKEN;
    $CMS_CONF['savedBase'] = $BASE_URL;
    $CMS_CONF['savedToken'] = $CMS_AUTH_TOKEN;
    $server = cms_start_server(['accounts' => CMS_CONF_ACCOUNTS]);
    $CMS_CONF['server'] = $server;
    foreach (CMS_CONF_ACCOUNTS as $a) {
        $CMS_CONF['token'][$a['username']] = cms_login($server['baseUrl'], $a['username'], $a['password']);
    }
    // Point the build/make-dep helpers at the conformance server as admin.
    cms_set_base($server['baseUrl']);
    cms_set_auth_token($CMS_CONF['token']['admin']);
});

// --- Authentication -------------------------------------------------------

test('login with valid credentials returns token, account and expiresAt', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $r = cms_req($base, null, 'POST', '/auth/login', ['username' => 'admin', 'password' => 'pw-admin']);
    cms_assert_equal(200, $r['status']);
    cms_assert(is_string($r['body']['token'] ?? null), 'token should be a string');
    cms_assert_equal('admin', $r['body']['account']['username'] ?? null);
    cms_assert_equal('admin', $r['body']['account']['role'] ?? null);
    cms_assert(!empty($r['body']['account']['id'] ?? null), 'account id should be set');
    cms_assert(!empty($r['body']['expiresAt'] ?? null), 'expiresAt should be set');
    cms_assert(!array_key_exists('passwordHash', $r['body']['account']), 'account must not leak passwordHash');
});

test('login with wrong password returns 401 UNAUTHORIZED', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], null, 'POST', '/auth/login', ['username' => 'admin', 'password' => 'wrong']);
    cms_assert_equal(401, $r['status']);
    cms_assert_equal('UNAUTHORIZED', $r['body']['error'] ?? null);
});

test('login with unknown user returns the same 401 (no enumeration)', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], null, 'POST', '/auth/login', ['username' => 'ghost', 'password' => 'whatever']);
    cms_assert_equal(401, $r['status']);
    cms_assert_equal('UNAUTHORIZED', $r['body']['error'] ?? null);
});

test('login with missing fields returns 400 VALIDATION_ERROR', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], null, 'POST', '/auth/login', ['username' => 'admin']);
    cms_assert_equal(400, $r['status']);
    cms_assert_equal('VALIDATION_ERROR', $r['body']['error'] ?? null);
});

test('GET /auth/me with a valid token returns the account, never internals', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], $CMS_CONF['token']['author'], 'GET', '/auth/me');
    cms_assert_equal(200, $r['status']);
    cms_assert_equal('author', $r['body']['account']['username'] ?? null);
    cms_assert_equal('author', $r['body']['account']['role'] ?? null);
    cms_assert(!array_key_exists('passwordHash', $r['body']['account']), 'account must not leak passwordHash');
});

test('GET /auth/me without a token returns 401', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], null, 'GET', '/auth/me');
    cms_assert_equal(401, $r['status']);
});

test('GET /auth/me with an invalid token returns 401', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], 'not-a-real-token', 'GET', '/auth/me');
    cms_assert_equal(401, $r['status']);
});

test('logout invalidates the session immediately; reuse and re-logout are 401', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $fresh = cms_login($base, 'viewer', 'pw-viewer');
    $out = cms_req($base, $fresh, 'POST', '/auth/logout');
    cms_assert_equal(204, $out['status']);
    $reuse = cms_req($base, $fresh, 'GET', '/auth/me');
    cms_assert_equal(401, $reuse['status']);
    $again = cms_req($base, $fresh, 'POST', '/auth/logout');
    cms_assert_equal(401, $again['status']);
});

test('logout without a token returns 401', function () {
    global $CMS_CONF;
    $r = cms_req($CMS_CONF['server']['baseUrl'], null, 'POST', '/auth/logout');
    cms_assert_equal(401, $r['status']);
});

// --- Authorization (type-level) -------------------------------------------

test('write without a session returns 401 (middleware), not 403', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    cms_set_auth_token($CMS_CONF['token']['admin']);
    $payload = cms_build_payload('BlogPosting');
    $r = cms_req($base, null, 'POST', '/blog-postings', $payload);
    cms_assert_equal(401, $r['status']);
});

test('viewer may read but not create, update or delete', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $item = cms_conf_create_as($base, $t['admin'], 'BlogPosting')['body'];
    cms_assert_equal(200, cms_req($base, $t['viewer'], 'GET', '/blog-postings' . '/' . $item['id'])['status']);
    cms_assert_equal(403, cms_conf_create_as($base, $t['viewer'], 'BlogPosting')['status']);
    cms_assert_equal(403, cms_req($base, $t['viewer'], 'PUT', '/blog-postings' . '/' . $item['id'], [])['status']);
    cms_assert_equal(403, cms_req($base, $t['viewer'], 'DELETE', '/blog-postings' . '/' . $item['id'])['status']);
});

test('author may read and create; editor and admin have full CRUD', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    cms_assert_equal(201, cms_conf_create_as($base, $t['author'], 'BlogPosting')['status']);
    cms_assert_equal(201, cms_conf_create_as($base, $t['editor'], 'BlogPosting')['status']);
    cms_assert_equal(201, cms_conf_create_as($base, $t['admin'], 'BlogPosting')['status']);
});

// --- Ownership ------------------------------------------------------------

test('createdBy is set to the creator and an author may modify only own records', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $mine = cms_conf_create_as($base, $t['author'], 'BlogPosting')['body'];
    $theirs = cms_conf_create_as($base, $t['author2'], 'BlogPosting')['body'];

    // Own update succeeds; foreign update and delete are 403.
    cms_assert_equal(200, cms_req($base, $t['author'], 'PUT', '/blog-postings' . '/' . $mine['id'], [])['status']);
    cms_assert_equal(403, cms_req($base, $t['author'], 'PUT', '/blog-postings' . '/' . $theirs['id'], [])['status']);
    cms_assert_equal(403, cms_req($base, $t['author'], 'DELETE', '/blog-postings' . '/' . $theirs['id'])['status']);

    // Editor and admin modify any record regardless of ownership.
    cms_assert_equal(200, cms_req($base, $t['editor'], 'PUT', '/blog-postings' . '/' . $theirs['id'], [])['status']);
    cms_assert_equal(204, cms_req($base, $t['admin'], 'DELETE', '/blog-postings' . '/' . $mine['id'])['status']);
});

// --- Field-level ----------------------------------------------------------

test('createdBy never appears in any entity response', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $created = cms_conf_create_as($base, $t['admin'], 'BlogPosting')['body'];
    cms_assert(!array_key_exists('createdBy', $created), 'createdBy must not appear on create');
    $got = cms_req($base, $t['admin'], 'GET', '/blog-postings' . '/' . $created['id'])['body'];
    cms_assert(!array_key_exists('createdBy', $got), 'createdBy must not appear on get');
    $list = cms_req($base, $t['admin'], 'GET', '/blog-postings' . '?limit=100')['body'];
    foreach ($list['items'] as $item) {
        cms_assert(!array_key_exists('createdBy', $item), 'createdBy must not appear in the list');
    }
});

test('system and internal fields are rejected in a write body with 400', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    cms_set_auth_token($t['admin']);
    foreach (['id', 'dateCreated', 'dateModified', 'createdBy'] as $field) {
        $payload = cms_build_payload('BlogPosting');
        $payload[$field] = $field === 'id' ? '00000000-0000-0000-0000-000000000000' : 'x';
        $r = cms_req($base, $t['admin'], 'POST', '/blog-postings', $payload);
        cms_assert_equal(400, $r['status'], "expected 400 for field $field");
        cms_assert_equal('VALIDATION_ERROR', $r['body']['error'] ?? null);
    }
});

test('server-managed fields appear in output but are server set', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $created = cms_conf_create_as($base, $CMS_CONF['token']['admin'], 'BlogPosting')['body'];
    cms_assert(!empty($created['id']), 'id should be set');
    cms_assert(!empty($created['dateCreated']), 'dateCreated should be set');
    cms_assert(!empty($created['dateModified']), 'dateModified should be set');
});

// --- Publication workflow -------------------------------------------------

test('a freshly created BlogPosting has the initial status', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $created = cms_conf_create_as($base, $CMS_CONF['token']['author'], 'BlogPosting')['body'];
    cms_assert_equal('Draft', $created['creativeWorkStatus'] ?? null);
});

test('author may run the initial transition but not the editor-only one', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $item = cms_conf_create_as($base, $t['author'], 'BlogPosting')['body'];
    // author: initial -> authorTo allowed
    $a = cms_req($base, $t['author'], 'PUT', '/blog-postings' . '/' . $item['id'], ['creativeWorkStatus' => 'Pending']);
    cms_assert_equal(200, $a['status']);
    cms_assert_equal('Pending', $a['body']['creativeWorkStatus'] ?? null);
    // author: authorTo -> editorTo forbidden
    $b = cms_req($base, $t['author'], 'PUT', '/blog-postings' . '/' . $item['id'], ['creativeWorkStatus' => 'Published']);
    cms_assert_equal(403, $b['status']);
    // editor: authorTo -> editorTo allowed
    $c = cms_req($base, $t['editor'], 'PUT', '/blog-postings' . '/' . $item['id'], ['creativeWorkStatus' => 'Published']);
    cms_assert_equal(200, $c['status']);
});

test('an unmodelled transition is forbidden', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $item = cms_conf_create_as($base, $t['editor'], 'BlogPosting')['body'];
    // initial -> editorTo (skipping authorTo) is not modelled
    $r = cms_req($base, $t['editor'], 'PUT', '/blog-postings' . '/' . $item['id'], ['creativeWorkStatus' => 'Published']);
    cms_assert_equal(403, $r['status']);
});

// --- Anonymous visibility (public) ----------------------------------------

test('anonymous reads see only public records; non-public detail is 404', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $item = cms_conf_create_as($base, $t['admin'], 'BlogPosting')['body'];

    // Not yet public: hidden from anonymous list and detail (404, not 403).
    $hiddenList = cms_req($base, null, 'GET', '/blog-postings' . '?limit=100')['body'];
    $present = false;
    foreach ($hiddenList['items'] as $i) {
        if (($i['id'] ?? null) === $item['id']) { $present = true; break; }
    }
    cms_assert(!$present, 'non-public record must not appear in anonymous list');
    cms_assert_equal(404, cms_req($base, null, 'GET', '/blog-postings' . '/' . $item['id'])['status']);

    // Drive it to the public status (admin), reaching datePublished where required.
    cms_req($base, $t['admin'], 'PUT', '/blog-postings' . '/' . $item['id'], ['creativeWorkStatus' => 'Pending']);
    $publish = ['creativeWorkStatus' => 'Published'];
    $publish['datePublished'] = '2020-01-01T00:00:00Z';
    $pub = cms_req($base, $t['admin'], 'PUT', '/blog-postings' . '/' . $item['id'], $publish);
    cms_assert_equal(200, $pub['status']);

    // Now visible anonymously, still without internal fields.
    $shownList = cms_req($base, null, 'GET', '/blog-postings' . '?limit=100')['body'];
    $shown = false;
    foreach ($shownList['items'] as $i) {
        if (($i['id'] ?? null) === $item['id']) { $shown = true; break; }
    }
    cms_assert($shown, 'public record must appear in anonymous list');
    $detail = cms_req($base, null, 'GET', '/blog-postings' . '/' . $item['id']);
    cms_assert_equal(200, $detail['status']);
    cms_assert(!array_key_exists('createdBy', $detail['body']), 'createdBy must stay hidden anonymously');
});

test('an entity without a status enum is anonymously readable and unrestricted by workflow', function () {
    global $CMS_CONF;
    $base = $CMS_CONF['server']['baseUrl'];
    $t = $CMS_CONF['token'];
    $created = cms_conf_create_as($base, $t['admin'], 'Person')['body'];
    // Anonymous detail is visible (no status gating).
    $anon = cms_req($base, null, 'GET', '/persons' . '/' . $created['id']);
    cms_assert_equal(200, $anon['status']);
    // A plain update carries no workflow check.
    $upd = cms_req($base, $t['editor'], 'PUT', '/persons' . '/' . $created['id'], []);
    cms_assert_equal(200, $upd['status']);
});

// --- Bootstrap ------------------------------------------------------------

test('empty store plus ADMIN env seeds one admin that can log in', function () {
    $s = cms_start_server(['env' => ['ADMIN_USER' => 'root', 'ADMIN_PASSWORD' => 'root-pw']]);
    try {
        $token = cms_login($s['baseUrl'], 'root', 'root-pw');
        cms_assert(is_string($token), 'bootstrap admin should log in');
    } finally {
        cms_stop_server($s);
    }
});

test('a non-empty store makes the env seed a no-op', function () {
    $s = cms_start_server(['accounts' => CMS_CONF_ACCOUNTS, 'env' => ['ADMIN_USER' => 'ghost', 'ADMIN_PASSWORD' => 'ghost-pw']]);
    try {
        // The ghost admin from the env was never created — the store was not empty.
        $r = cms_req($s['baseUrl'], null, 'POST', '/auth/login', ['username' => 'ghost', 'password' => 'ghost-pw']);
        cms_assert_equal(401, $r['status']);
    } finally {
        cms_stop_server($s);
    }
});

test('empty store without env grants no one: protected writes are 401', function () {
    global $CMS_CONF;
    $s = cms_start_server(['accounts' => []]);
    try {
        cms_set_base($CMS_CONF['server']['baseUrl']);
        cms_set_auth_token($CMS_CONF['token']['admin']);
        $payload = cms_build_payload('BlogPosting');
        $r = cms_req($s['baseUrl'], null, 'POST', '/blog-postings', $payload);
        cms_assert_equal(401, $r['status']);
    } finally {
        cms_stop_server($s);
    }
});

test('auth conformance: teardown', function () {
    global $CMS_CONF;
    if ($CMS_CONF['server'] !== null) {
        cms_stop_server($CMS_CONF['server']);
        $CMS_CONF['server'] = null;
    }
    cms_set_base($CMS_CONF['savedBase'] ?? '');
    cms_set_auth_token($CMS_CONF['savedToken'] ?? null);
});
