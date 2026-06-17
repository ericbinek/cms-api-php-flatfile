<?php
declare(strict_types=1);

$ENTITY = 'VideoObject';
$BASE = '/video-objects';

test("$ENTITY: create returns 201 with @type and id", function () use ($ENTITY, $BASE) {
    $payload = cms_build_payload($ENTITY);
    $r = cms_request('POST', $BASE, $payload);
    cms_assert_equal(201, $r['status'], 'expected 201');
    cms_assert_equal($ENTITY, $r['body']['@type'] ?? null);
    cms_assert_equal('https://schema.org', $r['body']['@context'] ?? null);
    cms_assert(!empty($r['body']['id']), 'id should be set');
});

test("$ENTITY: GET by id returns 200 with ETag", function () use ($ENTITY, $BASE) {
    $payload = cms_build_payload($ENTITY);
    $created = cms_request('POST', $BASE, $payload);
    $r = cms_request('GET', "$BASE/{$created['body']['id']}");
    cms_assert_equal(200, $r['status']);
    cms_assert(!empty($r['headers']['etag'] ?? ''), 'ETag header should be present');
});

test("$ENTITY: list returns { items, total } envelope", function () use ($ENTITY, $BASE) {
    cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $r = cms_request('GET', $BASE);
    cms_assert_equal(200, $r['status']);
    cms_assert(is_array($r['body']['items'] ?? null), 'items array expected');
    cms_assert(is_int($r['body']['total'] ?? null), 'total int expected');
});

test("$ENTITY: PUT partial update returns 200", function () use ($ENTITY, $BASE) {
    $created = cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $partial = cms_build_payload($ENTITY, partial: true);
    $r = cms_request('PUT', "$BASE/{$created['body']['id']}", $partial);
    cms_assert_equal(200, $r['status'], "PUT expected 200, got " . $r['status'] . ': ' . $r['raw']);
});

test("$ENTITY: DELETE returns 204 and subsequent GET returns 404", function () use ($ENTITY, $BASE) {
    $created = cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $del = cms_request('DELETE', "$BASE/{$created['body']['id']}");
    cms_assert_equal(204, $del['status']);
    $get = cms_request('GET', "$BASE/{$created['body']['id']}");
    cms_assert_equal(404, $get['status']);
});

test("$ENTITY: invalid UUID returns 400 INVALID_ID", function () use ($ENTITY, $BASE) {
    $r = cms_request('GET', "$BASE/not-a-uuid");
    cms_assert_equal(400, $r['status']);
    cms_assert_equal('INVALID_ID', $r['body']['error'] ?? null);
});

test("$ENTITY: unknown id returns 404 NOT_FOUND", function () use ($ENTITY, $BASE) {
    $r = cms_request('GET', "$BASE/00000000-0000-0000-0000-000000000000");
    cms_assert_equal(404, $r['status']);
    cms_assert_equal('NOT_FOUND', $r['body']['error'] ?? null);
});

test("$ENTITY: pagination — limit + offset honour total", function () use ($ENTITY, $BASE) {
    cms_request('POST', $BASE, cms_build_payload($ENTITY));
    cms_request('POST', $BASE, cms_build_payload($ENTITY));
    cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $r = cms_request('GET', "$BASE?limit=2&offset=0");
    cms_assert($r['body']['total'] >= 3);
    cms_assert(count($r['body']['items']) <= 2);
});

test("$ENTITY: sort by name accepted", function () use ($ENTITY, $BASE) {
    $r = cms_request('GET', "$BASE?sort=name&order=asc");
    cms_assert_equal(200, $r['status']);
});

test("$ENTITY: unknown sort field rejected with 400", function () use ($ENTITY, $BASE) {
    $r = cms_request('GET', "$BASE?sort=definitely-not-a-field");
    cms_assert_equal(400, $r['status']);
});



test("$ENTITY: filter on text field \"name\" returns matches", function () use ($ENTITY, $BASE) {
    $created = cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $needle = substr((string) ($created['body']['name'] ?? ''), 0, 4);
    if ($needle === '') return;
    $r = cms_request('GET', "$BASE?name=" . rawurlencode($needle));
    $found = false;
    foreach ($r['body']['items'] as $item) {
        if (($item['id'] ?? null) === $created['body']['id']) { $found = true; break; }
    }
    cms_assert($found, 'created item not found via filter');
});


test("$ENTITY: stale If-Match on PUT returns 412", function () use ($ENTITY, $BASE) {
    $created = cms_request('POST', $BASE, cms_build_payload($ENTITY));
    $r = cms_request('PUT', "$BASE/{$created['body']['id']}", [], ['If-Match' => '"0000000000000000"']);
    cms_assert_equal(412, $r['status']);
});

test("$ENTITY: CORS preflight returns 204 with allow headers", function () use ($ENTITY, $BASE) {
    $r = cms_request('OPTIONS', $BASE, null, [
        'Origin' => 'https://example.com',
        'Access-Control-Request-Method' => 'POST',
    ]);
    cms_assert_equal(204, $r['status']);
    cms_assert_equal('*', $r['headers']['access-control-allow-origin'] ?? null);
});

test("$ENTITY: deeply nested JSON body rejected with 400 INVALID_JSON", function () use ($ENTITY, $BASE) {
    $depth = 2000;
    $deep = str_repeat('[', $depth) . str_repeat(']', $depth);
    $r = cms_request('POST', $BASE, null, [], $deep);
    cms_assert_equal(400, $r['status']);
    cms_assert_equal('INVALID_JSON', $r['body']['error'] ?? null);
});

test("$ENTITY: GET by id embeds \"creator\" as an object; list stays flat", function () use ($ENTITY, $BASE) {
    $payload = cms_build_payload($ENTITY, partial: true);
    $created = cms_request('POST', $BASE, $payload)['body'];

    // POST response keeps refs flat (UUID strings).
    $refId = $created['creator'] ?? null;
    cms_assert(is_string($refId), 'POST response ref should stay a UUID string');

    // Single-resource GET embeds the referenced entity one level deep.
    $got = cms_request('GET', "$BASE/{$created['id']}")['body'];
    $embedded = $got['creator'] ?? null;
    cms_assert(is_array($embedded), 'GET by id should embed the ref as an object');
    cms_assert_equal('Person', $embedded['@type'] ?? null);
    cms_assert_equal($refId, $embedded['id'] ?? null);

    // List responses stay flat — refs remain UUID strings.
    $list = cms_request('GET', "$BASE?limit=100")['body'];
    $inList = null;
    foreach ($list['items'] as $i) {
        if (($i['id'] ?? null) === $created['id']) { $inList = $i; break; }
    }
    cms_assert($inList !== null, 'created item should appear in the list');
    cms_assert(is_string($inList['creator'] ?? null), 'list ref should stay a UUID string');
});

test("$ENTITY: GET by id leaves an unresolvable \"creator\" ref as its UUID", function () use ($ENTITY, $BASE) {
    $dangling = '00000000-0000-0000-0000-000000000000';
    $payload = cms_build_payload($ENTITY, partial: true);
    $payload['creator'] = $dangling;
    $created = cms_request('POST', $BASE, $payload)['body'];
    $got = cms_request('GET', "$BASE/{$created['id']}")['body'];
    cms_assert_equal($dangling, $got['creator'] ?? null);
});
