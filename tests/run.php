<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

$tests = [];
function test(string $name, callable $operation): void
{
    global $tests;
    $tests[$name] = $operation;
}

test('parser handles links, references, multiline templates and vertical cells', function () {
    $rows = CouncilSync::parseWikitext(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'parser.wikitext'));
    same(6, count($rows));
    same('Fort Worth', $rows[0]['city']);
    $options = CouncilSync::generateOptions($rows);
    same('Longhorn Council, TX (662)', $options['longhorn-council-tx-662']);
    same("Patriots' Path Council, NJ (358)", $options['patriots-path-council-nj-358']);
    same('Far East Council, Japan (803)', $options['far-east-council-japan-803']);
    same('Transatlantic Council, Belgium (802)', $options['transatlantic-council-belgium-802']);
    same('Northeast Region (901)', $options['northeast-region-901']);
    same('Puerto Rico Council, PR (661)', $options['puerto-rico-council-pr-661']);
});
test('malformed required row aborts rather than skipping a council', function () {
    issue('parse', fn() => CouncilSync::parseWikitext(wiki([council('Missing', 1, '')])));
    issue('parse', fn() => CouncilSync::parseWikitext(wiki([council('{{unknown|Name}}', 1)])));
    issue('table_missing', fn() => CouncilSync::parseWikitext("{| \n! Name\n|-\n| Other\n|}"));
    $broken = preg_replace('~\}\}~', '', wiki([council('A', 1), council('B', 2)]), 1);
    issue('parse', fn() => CouncilSync::parseWikitext($broken));
});
test('safe Wiki formatting and entities preserve plain labels and established slugs', function () {
    $rows = [
        council("'''Patriots&#39; Path Council'''", 358, 'New&nbsp;Jersey'),
        council('{{nowrap|A&amp;M<br />Council}}', 2),
        council("[[Untrusted destination|R\u{00ED}o Grande &amp; St. Anne-S\u{00E3}o Council]]", 3)
    ];
    $rows[2]['city'] = "[[Ignored link|S\u{00E3}o Jos\u{00E9}]]";
    $options = CouncilSync::generateOptions(CouncilSync::parseWikitext(wiki($rows)));
    same("Patriots' Path Council, NJ (358)", $options['patriots-path-council-nj-358']);
    same('A&M Council, TX (2)', $options['a-m-council-tx-2']);
    same("R\u{00ED}o Grande & St. Anne-S\u{00E3}o Council, TX (3)", $options['r-o-grande-st-anne-s-o-council-tx-3']);
    $page = str_replace('Council number', 'Council&nbsp;number', wiki($rows));
    same($options, CouncilSync::generateOptions(CouncilSync::parseWikitext($page)));
    same(CouncilSync::generateOptions([council('A', 1)]), CouncilSync::generateOptions(['first' => council('A', 1)]));
});
test('raw and encoded active content is rejected in every imported text column', function () {
    foreach ([
        '<script>COUNCIL_ATTACK_MARKER</script>', '<img src=x onerror=alert(1)>',
        '<br onmouseover=alert(1)>', '<svg/onload=alert(1)>', '<script',
        '&lt;script&gt;COUNCIL_ATTACK_MARKER&lt;/script&gt;',
        '&#60;img src=x onerror=alert(1)&#62;',
        '&amp;lt;img src=x onerror=alert(1)&amp;gt;',
        '&ltscript&gtalert(1)&lt/script&gt',
        '%3Cscript%3E', 'javascript:alert(1)', '${template}', '`command`',
        "Robert'); DROP TABLE clients;--"
    ] as $payload) {
        foreach (['name', 'city', 'state'] as $field) {
            $row = council('Safe Council', 1);
            $row[$field] = $payload;
            issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
        }
    }
});
test('malformed UTF-8 and raw or entity-encoded controls cannot be normalized away', function () {
    foreach (["Bad\xC3\x28", "Bad\0Name", "Bad\x1BName", "Bad\x0BName", "Bad\u{202E}Name", "Bad\u{200B}Name",
        'Bad&#0;Name', 'Bad&#10;Name', 'Bad&#x1b;Name', 'Bad&Tab;Name', 'Bad&NewLine;Name'] as $payload) {
        $row = council($payload, 1);
        issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
        issue('unsafe_content', fn() => CouncilSync::generateOptions([$row]));
    }
});
test('council numbers are bounded ASCII digits before integer conversion', function () {
    foreach (['0', '-1', '10000', '999999999999999999999999', '1e3', '1.5', "\u{0661}", '1 OR 1=1'] as $number) {
        $row = council('Safe Council', 1);
        $row['number'] = $number;
        issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
        issue('unsafe_content', fn() => CouncilSync::generateOptions([$row]));
    }
    $first = council('First Council', 1);
    $first['number'] = '0001';
    $options = CouncilSync::generateOptions(CouncilSync::parseWikitext(wiki([$first, council('Last Council', 9999)])));
    same('First Council, TX (1)', $options['first-council-tx-1']);
    same('Last Council, TX (9999)', $options['last-council-tx-9999']);
});
test('field lengths and raw cell sizes are checked at their boundaries', function () {
    foreach (['name', 'city'] as $field) {
        $row = council('Safe Council', 1);
        $row[$field] = str_repeat("\u{00E9}", 200);
        same(1, count(CouncilSync::parseWikitext(wiki([$row]))));
        $row[$field] .= "\u{00E9}";
        issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
    }
    $row = council('Safe Council', 1, str_repeat('A', 64));
    same(1, count(CouncilSync::parseWikitext(wiki([$row]))));
    $row['state'] .= 'A';
    issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
    $row = council('[[' . str_repeat('x', 8184) . '|A]]', 1);
    same('A', CouncilSync::parseWikitext(wiki([$row]))[0]['name']);
    $row['name'] = str_replace('|A]]', 'x|A]]', $row['name']);
    issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki([$row])));
});
test('document and row limits reject oversize input without truncation', function () {
    $table = wiki([council('Safe Council', 1)]);
    $page = str_repeat('x', 4194304 - strlen($table) - 1) . "\n" . $table;
    same(1, count(CouncilSync::parseWikitext($page)));
    issue('unsafe_content', fn() => CouncilSync::parseWikitext('x' . $page));
    $page = str_repeat("\n", 20000 - substr_count($table, "\n")) . $table;
    same(1, count(CouncilSync::parseWikitext($page)));
    issue('unsafe_content', fn() => CouncilSync::parseWikitext("\n" . $page));
    $rows = [];
    for ($number = 1; $number <= 1000; $number++) {
        $rows[] = council('A', $number);
    }
    same(1000, count(CouncilSync::generateOptions(CouncilSync::parseWikitext(wiki($rows)))));
    $rows[] = council('A', 1001);
    issue('unsafe_content', fn() => CouncilSync::parseWikitext(wiki($rows)));
    issue('unsafe_content', fn() => CouncilSync::generateOptions($rows));
});
test('parser resource failures are explicit fetch issues rather than PHP type errors', function () {
    $limit = ini_get('pcre.backtrack_limit');
    try {
        ini_set('pcre.backtrack_limit', '100');
        issue('parse', fn() => CouncilSync::parseWikitext('<!--' . str_repeat('x', 10000) . '-->'));
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
    }
});
test('one malicious row leaves the complete encrypted import state untouched', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1'], true);
    $model->source([council('A', 1)]);
    $model->synchronize(1, 'cron', null);
    $before = $model->Clients->getCustomField(1)->values;
    foreach (['name', 'city', 'state', 'number'] as $field) {
        $row = council('Bad Council', 2);
        $row[$field] = '&lt;img src=x onerror=alert(1)&gt;';
        $model->source([council('A', 1), $row]);
        issue('unsafe_content', fn() => $model->synchronize(1, 'cron', null));
        same($before, $model->Clients->getCustomField(1)->values);
        same(1, $model->settings(1)['previous_count']);
        same([], $model->history(1));
        same([], $model->Clients->writes);
        same('enc:' . base64_encode('a-tx-1'), $model->Record->query('SELECT value FROM client_values WHERE client_id = 1')->fetchColumn());
    }
});
test('cached import previews cannot bypass label and slug validation', function () {
    $model = database(['old' => 'Old'], [1 => 'old'], true);
    $model->source([council('New Council', 3)]);
    $preview = $model->preview(1);
    foreach ([
        ['safe-tx-3' => '<img src=x onerror=alert(1)>'],
        ['safe-tx-3' => '&lt;script&gt;'],
        ['../../file.php' => 'Safe Council'],
        ['safe" onclick="alert(1)' => 'Safe Council'],
        [str_repeat('a', 241) => 'Safe Council']
    ] as $options) {
        $preview['after'] = $options;
        issue('unsafe_content', fn() => $model->apply(1, $preview, [], 'manual', 1));
        same(['Old' => 'old'], $model->Clients->getCustomField(1)->values);
        same([], $model->history(1));
        same([], $model->Clients->writes);
    }
});
test('serialized imported options fit the native TEXT column exactly or fail before writes', function () {
    $model = database([]);
    $model->source([council('A', 1)]);
    $preview = $model->preview(1);
    $options = [];
    for ($index = 1; strlen(json_encode(array_flip($options))) < 65535; $index++) {
        $value = 'council-' . $index;
        $options[$value] = str_pad('Council ' . $index, 250, 'a');
    }
    $excess = strlen(json_encode(array_flip($options))) - 65535;
    $options[$value] = substr($options[$value], 0, 250 - $excess);
    same(65535, strlen(json_encode(array_flip($options))));
    $preview['after'] = $options;
    same('dry-run', $model->apply(1, $preview, [], 'manual', 1, true)['status']);
    $preview['after'][$value] .= 'a';
    same(65536, strlen(json_encode(array_flip($preview['after']))));
    issue('unsafe_content', fn() => $model->apply(1, $preview, [], 'manual', 1));
    same([], $model->Clients->getCustomField(1)->values);
    same([], $model->history(1));
});
test('slug punctuation and case, with sorting by name then council number', function () {
    $options = CouncilSync::generateOptions([
        council('zeta', 9), council('Alpha', 20), council('alpha', 2), council("Patriots\u{2019} Path Council", 358, 'NJ')
    ]);
    same(['alpha-tx-2', 'alpha-tx-20', 'patriots-path-council-nj-358', 'zeta-tx-9'], array_keys($options));
});
test('duplicate generated slugs abort, including identical rows', function () {
    issue('collision', fn() => CouncilSync::generateOptions([council('A-B', 1), council('A B', 1)]));
    issue('collision', fn() => CouncilSync::generateOptions([council('Same', 2), council('Same', 2)]));
});
test('row-count threshold is exact and never allows zero', function () {
    CouncilSync::assertRowCount(216, 240, 10);
    CouncilSync::assertRowCount(300, 240, 10);
    issue('row_count', fn() => CouncilSync::assertRowCount(215, 240, 10));
    issue('empty', fn() => CouncilSync::assertRowCount(0, null, 99));
    issue('settings', fn() => CouncilSync::assertRowCount(1, 0, 10));
    issue('state_unknown', fn() => CouncilSync::stateAbbreviation('Unknown location'));
});
test('required contact builds the precise Wikimedia User-Agent', function () {
    same('CouncilSync-Blesta/1.2.0 (https://example.com/council-sync) PHP/' . PHP_VERSION, CouncilSync::userAgent('https://example.com/council-sync'));
    same('support@example.com', CouncilSync::validateContact('support@example.com'));
    foreach (['', "support@example.com\r\nInjected: header", 'not-a-contact', 'ftp://example.com'] as $contact) {
        issue('contact', fn() => CouncilSync::validateContact($contact));
    }
});
test('silent additions, label changes and unused removals are not conflicts', function () {
    $diff = CouncilSync::diff(['a' => 'Old label', 'b' => 'Unused'], ['a' => 'New label', 'c' => 'Added'], [['id' => 1, 'value' => 'a']]);
    same([], $diff['conflicts']);
    same(['b' => 'Unused'], $diff['changes']['removed']);
    same(['c' => 'Added'], $diff['changes']['added']);
    same(['a' => ['before' => 'Old label', 'after' => 'New label']], $diff['changes']['labels']);
});
test('number changes conflict and notification hash uses only old value and count', function () {
    $before = CouncilSync::generateOptions([council('Council', 1)]);
    $after = CouncilSync::generateOptions([council('Council', 2)]);
    $diff = CouncilSync::diff($before, $after, [['id' => 8, 'value' => 'council-tx-1']]);
    same(1, $diff['conflicts']['council-tx-1']['count']);
    $other = $diff['conflicts'];
    $other['council-tx-1']['label'] = 'Changed display label';
    $other['council-tx-1']['clients'][0]['id'] = 99;
    same(CouncilSync::conflictHash($diff['conflicts']), CouncilSync::conflictHash($other));
    $other['council-tx-1']['count'] = 2;
    same(false, CouncilSync::conflictHash($diff['conflicts']) === CouncilSync::conflictHash($other));
});
test('any cron conflict is an entire no-op', function () {
    $model = database(['a-tx-1' => 'A, TX (1)', 'unused' => 'Unused'], [1 => 'a-tx-1'], true);
    $model->source([council('X', 3)]);
    same('conflicts', $model->synchronize(1, 'cron', null)['status']);
    same(['a-tx-1' => 'A, TX (1)', 'unused' => 'Unused'], array_flip($model->Clients->getCustomField(1)->values));
    same([], $model->history(1));
    same(null, $model->settings(1)['previous_count']);
    same([], $model->Clients->writes);
});
test('fetch 403/429, invalid JSON and wrong response shapes make no changes', function () {
    $model = database([]);
    foreach ([403, 429, 302] as $status) {
        $model->response = ['status' => $status, 'body' => '{}'];
        issue('http_status', fn() => $model->synchronize(1, 'cron', null));
    }
    $model->response = ['status' => 200, 'body' => 'not-json'];
    issue('response', fn() => $model->synchronize(1, 'cron', null));
    $model->response = ['status' => 200, 'body' => '{"parse":{"title":"Wrong","text":"<table></table>"}}'];
    issue('response', fn() => $model->synchronize(1, 'cron', null));
    same([], $model->history(1));
    same([], $model->Clients->getCustomField(1)->values);
    same(5, $model->requests);
});
test('many-to-one remaps use encrypted model writes and rollback atomically', function () {
    $model = database(['a-tx-1' => 'A, TX (1)', 'b-tx-2' => 'B, TX (2)'], [1 => 'a-tx-1', 2 => 'b-tx-2'], true);
    $model->source([council('X', 3)]);
    $preview = $model->preview(1);
    $choices = ['a-tx-1' => ['mode' => 'remap', 'target' => 'x-tx-3'], 'b-tx-2' => ['mode' => 'remap', 'target' => 'x-tx-3']];
    $result = $model->apply(1, $preview, $choices, 'manual', 1);
    same(2, $result['summary']['remapped']);
    same([[1, 'x-tx-3'], [2, 'x-tx-3']], $model->Clients->writes);
    same('enc:' . base64_encode('x-tx-3'), $model->Record->query('SELECT value FROM client_values WHERE client_id = 1')->fetchColumn());
    same($model->Record->pdo, $model->Clients->Record->pdo);
    $audit = $model->rollback(1, $result['batch_id'], 1);
    same('a-tx-1', $model->Clients->getCustomFieldValues(1)[0]->value);
    same('b-tx-2', $model->Clients->getCustomFieldValues(2)[0]->value);
    same('rolled back', $model->batch(1, $result['batch_id'])->status);
    same('rollback', $model->batch(1, $audit)->kind);
});
test('one-to-many allows remap-all with individual overrides', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1', 2 => 'a-tx-1']);
    $model->source([council('X', 3), council('Y', 4)]);
    $model->apply(1, $model->preview(1), ['a-tx-1' => ['mode' => 'clients', 'all_target' => 'x-tx-3', 'clients' => [2 => 'y-tx-4']]], 'manual', 1);
    same('x-tx-3', $model->Clients->getCustomFieldValues(1)[0]->value);
    same('y-tx-4', $model->Clients->getCustomFieldValues(2)[0]->value);
});
test('explicit choices, valid targets and conflict-owned client IDs are required', function () {
    $conflicts = ['a' => ['label' => 'A', 'count' => 2, 'clients' => [['id' => 1], ['id' => 2]]]];
    issue('choice', fn() => CouncilSync::resolveChoices($conflicts, ['x' => 'X'], []));
    issue('target', fn() => CouncilSync::resolveChoices($conflicts, ['x' => 'X'], ['a' => ['mode' => 'remap', 'target' => 'invalid']]));
    issue('target', fn() => CouncilSync::resolveChoices($conflicts, ['x' => 'X'], ['a' => ['mode' => 'clients', 'clients' => [1 => 'x']]]));
    issue('choice', fn() => CouncilSync::resolveChoices($conflicts, ['x' => 'X'], ['a' => ['mode' => 'clients', 'clients' => [99 => 'x']]]));
    issue('orphan_confirmation', fn() => CouncilSync::resolveChoices($conflicts, ['x' => 'X'], ['a' => ['mode' => 'accept']]));
});
test('accepting orphans leaves client values untouched and audits each client', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $result = $model->apply(1, $model->preview(1), ['a-tx-1' => ['mode' => 'accept', 'confirm_orphan' => '1']], 'manual', 1);
    same([], $model->Clients->writes);
    same(1, $result['summary']['accepted_orphans']);
    same('accepted-orphan', $model->batchItems(1, $result['batch_id'])[0]->action);
    $model->rollback(1, $result['batch_id'], 1);
    same('a-tx-1', $model->Clients->getCustomFieldValues(1)[0]->value);
});
test('a later client write failure rolls back options, clients, batches and items', function () {
    foreach ([false, true] as $silentFailure) {
        $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1', 2 => 'a-tx-1'], true);
        $model->source([council('X', 3)]);
        $model->Clients->failClient = 2;
        $model->Clients->silentFailure = $silentFailure;
        issue('client_write', fn() => $model->apply(1, $model->preview(1), ['a-tx-1' => ['mode' => 'remap', 'target' => 'x-tx-3']], 'manual', 1));
        same(['A, TX (1)' => 'a-tx-1'], $model->Clients->getCustomField(1)->values);
        same('a-tx-1', $model->Clients->getCustomFieldValues(1)[0]->value);
        same([], $model->history(1));
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM council_sync_items')->fetchColumn());
        same(null, $model->settings(1)['previous_count']);
    }
});
test('option-only changes log a batch and clear an invalid default; rollback restores both', function () {
    $model = database(['unused' => 'Unused'], [], false, 'unused');
    $model->source([council('X', 3)]);
    $result = $model->synchronize(1, 'cron', null);
    same('applied', $result['status']);
    same(null, $model->Clients->getCustomField(1)->default);
    same(1, count($model->history(1)));
    $model->rollback(1, $result['batch_id'], 1);
    same('unused', $model->Clients->getCustomField(1)->default);
});
test('dry run writes neither options, values nor audit rows', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    same('conflicts', $model->synchronize(1, 'manual', 1, true)['status']);
    $result = $model->apply(1, $model->preview(1), ['a-tx-1' => ['mode' => 'remap', 'target' => 'x-tx-3']], 'manual', 1, true);
    same('dry-run', $result['status']);
    same([], $model->history(1));
    same([], $model->Clients->writes);
    same(null, $model->settings(1)['previous_count']);
});
test('stale previews, changed settings, cross-company and expired sessions are blocked', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $preview = $model->preview(1);
    issue('stale', fn() => $model->apply(2, $preview, [], 'manual', 1));
    $expired = $preview;
    $expired['fetched_at'] -= 3601;
    issue('stale', fn() => $model->apply(1, $expired, [], 'manual', 1));
    $model->Record->query('UPDATE client_fields SET name = ?', 'Changed');
    issue('stale', fn() => $model->apply(1, $preview, [], 'manual', 1));
    $preview = $model->preview(1);
    $model->Record->query('UPDATE council_sync_settings SET max_drop = 20');
    issue('stale', fn() => $model->apply(1, $preview, [], 'manual', 1));
    same([], $model->history(1));
});
test('rollback is limited to the latest update and itself creates an audit entry', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $first = $model->synchronize(1, 'cron', null)['batch_id'];
    $model->source([council('X', 3), council('Y', 4)]);
    $second = $model->synchronize(1, 'cron', null)['batch_id'];
    issue('rollback_latest', fn() => $model->rollback(1, $first, 1));
    $model->rollback(1, $second, 1);
    $model->rollback(1, $first, 1);
    same(4, count($model->history(1)));
});
test('rollback blocks new orphaned clients and externally changed options', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $batch = $model->synchronize(1, 'cron', null)['batch_id'];
    $model->Record->insert('client_values', ['client_field_id' => 1, 'client_id' => 99, 'value' => 'x-tx-3', 'encrypted' => 0]);
    issue('rollback_orphan', fn() => $model->rollback(1, $batch, 1));
    $model->Record->query('DELETE FROM client_values WHERE client_id = 99');
    $model->Record->query('UPDATE client_fields SET `values` = ?', '{"Different":"x-tx-3"}');
    issue('rollback_changed', fn() => $model->rollback(1, $batch, 1));
    same('applied', $model->batch(1, $batch)->status);
});
test('no repeatable-read snapshot is established before all field locks', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $preview = $model->preview(1);
    $model->Record->trace = [];
    $model->Clients->Record = $model->Record;
    $model->apply(1, $preview, [], 'manual', 1);
    $begin = array_search('begin', $model->Record->trace, true);
    $firstRead = array_search('model-field-read', $model->Record->trace, true);
    $between = array_slice($model->Record->trace, $begin + 1, $firstRead - $begin - 1);
    same(3, count($between));
    foreach ($between as $sql) {
        same(true, str_ends_with($sql, 'FOR UPDATE'));
    }
});
test('busy and nested transactions are explicit errors', function () {
    $model = database([]);
    $model->Record->lockAvailable = false;
    issue('busy', fn() => $model->locked(1, fn() => null));
    $model->Record->lockAvailable = true;
    $model->Record->pdo->beginTransaction();
    issue('nested_transaction', fn() => $model->atomic(fn() => null));
    $model->Record->pdo->rollBack();
});

require __DIR__ . DIRECTORY_SEPARATOR . 'test_plugin.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'test_software.php';
echo 'PHP ' . PHP_VERSION . "\n";
$failed = 0;
foreach ($tests as $name => $operation) {
    try {
        $operation();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL: $name: " . get_class($error) . ': ' . $error->getMessage() . "\n");
    }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed === 0 ? 0 : 1);
