<?php
/**
 * Proof — email templates (email.md "Proof"): shared and own; a Rep cannot make or change a shared one (403, in words), Marketing and the Sales Manager can; another seller's own template does not
 * exist to them; a template never used is deleted, one that has been used is archived (out of the lists and the picker, still named by what was sent with it); the merge fields listed on the form;
 * the picker; the log never holds a body.
 */
require __DIR__ . '/lib.php';
$x = run_tag();
ensure_mailbox();
$since = last_activity_id();
$mkt = fixture()['claims']['31']; $mkt['roles'] = ['marketing']; $mkt['role'] = 'marketing';
$mj = sign_on(31, ['claims' => $mkt])[0];
$mcsrf = page_csrf($mj);
$as_mkt = static function (string $path, array $form) use ($mj, $mcsrf): array {
    $r = req('POST', $path, ['jar' => $mj, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $mcsrf], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
};
$msg = static fn (array $r): string => (string) ($r['json']['error']['message'] ?? $r['body']);

echo "1. Shared and own\n";
$j = get_as(26, '/email/templates')['json']['data'] ?? [];
$names = array_column($j['rows'] ?? [], 'name');
ok(in_array('Introduction', $names, true) && in_array('Follow-up', $names, true) && in_array('Meeting confirmation', $names, true), 'the three shared templates the schema seeds are there for a Rep');
ok(isset($j['rows'][0]['merge_fields']) && in_array('contact.first_name', array_column($j['rows'], 'merge_fields')[0] ?? [], true) && in_array('sender.signature', $j['merge_fields'] ?? [], true), 'in JSON each row lists the merge fields it uses; the catalogue of fields too');
$r = post_as(26, '/email/templates/save.php', ['name' => "Mine $x", 'subject' => 'Hello {{contact.first_name}}', 'body' => "Hi {{contact.first_name}},\n\nTEMPLATE-SECRET-BODY", 'kind' => 'follow_up']);
$mine = (int) ($r['json']['record_id'] ?? 0);
$row = q('SELECT * FROM email_templates WHERE id = :i', ['i' => $mine])[0] ?? [];
ok($r['code'] === 200 && $mine > 0 && $row['is_shared'] === false && (int) $row['owner_member_id'] === 26 && $row['kind'] === 'follow_up' && (int) $row['usage_count'] === 0, 'a Rep makes an own template: not shared, hers, unused: ' . ($r['json']['did'] ?? $r['body']));
$r = post_as(26, '/email/templates/save.php', ['name' => "Shared try $x", 'subject' => 's', 'body' => 'b', 'is_shared' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'is_shared' && str_contains($msg($r), 'Marketing'), 'a Rep making a shared one: refused in words, the field named: ' . $msg($r));
$r = $as_mkt('/email/templates/save.php', ['name' => "Shared $x", 'subject' => 'Shared {{contact.first_name}}', 'body' => 'Shared body.', 'is_shared' => 'yes', 'kind' => 'general']);
$shared = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $shared > 0 && one('SELECT is_shared FROM email_templates WHERE id = :i', ['i' => $shared]) === true, 'Marketing makes a shared one');
$names = array_column(get_as(26, '/email/templates')['json']['data']['rows'] ?? [], 'template_id');
ok(in_array($shared, $names, true) && in_array($mine, $names, true), 'a Rep sees the shared one and her own');
ok(!in_array($mine, array_column(get_as(30, '/email/templates')['json']['data']['rows'] ?? [], 'template_id'), true) && in_array($shared, array_column(get_as(30, '/email/templates')['json']['data']['rows'] ?? [], 'template_id'), true), 'another seller sees the shared one but not Priya\'s own');
ok(html_as(30, "/email/templates/$mine/edit")['code'] === 404, 'and her own template is a 404 to them');
$r = post_as(30, '/email/templates/save.php', ['template' => $mine, 'name' => 'stolen']);
ok($r['code'] === 404, 'they cannot change it: 404');
$r = post_as(26, '/email/templates/save.php', ['template' => $shared, 'name' => 'Priya renames shared']);
ok($r['code'] === 403, 'a Rep may not change a shared template: 403 ' . $msg($r));
$r = $as_mkt('/email/templates/save.php', ['template' => $shared, 'subject' => "Shared again {{contact.first_name}}"]);
ok($r['code'] === 200 && one('SELECT subject FROM email_templates WHERE id = :i', ['i' => $shared]) === 'Shared again {{contact.first_name}}' && one('SELECT name FROM email_templates WHERE id = :i', ['i' => $shared]) === "Shared $x", 'Marketing changes it; a field left out stays as it was');
$r = post_as(27, '/email/templates/save.php', ['template' => $shared, 'body' => 'The manager keeps it too.']);
ok($r['code'] === 200, 'the Sales Manager keeps shared templates too');
$r = post_as(1, '/email/templates/save.php', ['template' => $mine, 'body' => 'The admin edits a Rep\'s own template.']);
ok($r['code'] === 200, 'the admin, who sees every template, may change it');
$r = post_as(26, '/email/templates/save.php', ['template' => $mine, 'is_shared' => 'yes']);
ok($r['code'] === 422 && field_of($r) === 'is_shared', 'a Rep making her own template shared: refused');
$r = post_as(27, '/email/templates/save.php', ['template' => $mine, 'is_shared' => 'yes']);
ok($r['code'] === 404, 'a manager does not see a Rep\'s own template either: 404');

echo "2. Validation, in words\n";
$r = post_as(26, '/email/templates/save.php', ['subject' => 's', 'body' => 'b']);
ok($r['code'] === 422 && field_of($r) === 'name', 'no name: named');
$r = post_as(26, '/email/templates/save.php', ['name' => 'n', 'body' => 'b']);
ok($r['code'] === 422 && field_of($r) === 'subject', 'no subject: named');
$r = post_as(26, '/email/templates/save.php', ['name' => 'n', 'subject' => 's', 'body' => 'b', 'kind' => 'poetry']);
ok($r['code'] === 422 && field_of($r) === 'kind', 'a kind that is none: named');
$r = post_as(26, '/email/templates/save.php', ['name' => str_repeat('n', 121), 'subject' => 's', 'body' => 'b']);
ok($r['code'] === 422 && field_of($r) === 'name', 'a name over 120: named');
$r = post_as(29, '/email/templates/save.php', ['name' => 'n', 'subject' => 's', 'body' => 'b']);
ok($r['code'] === 403, 'a Viewer: 403');

echo "3. The screens\n";
$h = html_as(26, '/email/templates')['body'];
ok(str_contains($h, 'id="template-card-' . $mine . '"') && str_contains($h, 'id="template-card-' . $mine . '-use"') && str_contains($h, 'id="template-card-' . $mine . '-delete"') && str_contains($h, 'id="template-new-btn"'), 'the list: cards, Use, Edit, Delete, New');
ok(!str_contains($h, 'id="template-card-' . $shared . '-delete"') && !str_contains($h, 'id="template-card-' . $shared . '-edit"') && str_contains($h, 'id="template-card-' . $shared . '-use"'), 'a shared card shows a Rep Use only — no Edit, no Delete');
ok(str_contains(page($mj, '/email/templates')['body'], 'id="template-card-' . $shared . '-edit"'), 'Marketing sees Edit on it');
$h = html_as(26, '/email/templates/new')['body'];
ok(str_contains($h, 'id="template-form"') && str_contains($h, 'id="template-merge-fields"') && substr_count($h, 'id="merge-field-') === 11 && str_contains($h, '{{sender.signature}}') && !str_contains($h, 'name="is_shared" value="yes"'), 'the form lists the eleven merge fields; a Rep is not offered "shared"');
ok(str_contains(page($mj, '/email/templates/new')['body'], 'name="is_shared" value="yes"'), 'Marketing is');
$h = html_as(26, "/email/templates/$mine/edit")['body'];
ok(str_contains($h, 'value="Mine ' . $x . '"') && str_contains($h, 'name="template" value="' . $mine . '"'), 'the edit form holds the template');
ok(html_as(26, '/email/templates?q=' . urlencode("Mine $x"))['code'] === 200 && str_contains(html_as(26, '/email/templates?q=' . urlencode("Mine $x"))['body'], 'template-card-' . $mine) && !str_contains(html_as(26, '/email/templates?q=' . urlencode("Mine $x"))['body'], 'template-card-' . $shared), 'the search narrows the cards');
ok(count(get_as(26, '/email/templates?kind=follow_up')['json']['data']['rows'] ?? []) >= 2 && array_filter(get_as(26, '/email/templates?kind=intro')['json']['data']['rows'] ?? [], static fn ($t) => $t['kind'] !== 'intro') === [], 'the kind filter');
ok(array_filter(get_as(26, '/email/templates?mine=1')['json']['data']['rows'] ?? [], static fn ($t) => (int) $t['owner_member_id'] !== 26) === [], 'Mine only');
$pk = html_as(26, '/pick/?source=template&q=' . urlencode("Mine $x"))['body'];
ok(!str_contains($pk, 'record-picker-empty') && str_contains($pk, "Mine $x") && !str_contains($pk, "Shared $x"), 'the picker finds a template by name');
ok(html_as(29, '/pick/?source=template')['code'] === 403, 'and a Viewer may not use that picker');

echo "4. Used is archived, unused is deleted\n";
$acc = (int) post_as(26, '/accounts/save.php', ['name' => "Tpl Acct $x"])['json']['record_id'];
$ann = contact_with("ann-$x@tpl-$x.example.invalid", 'Ann', $acc);
$r = post_as(26, '/email/send.php', ['contact' => $ann, 'template' => $mine, 'subject' => "Via template $x", 'body' => 'Hi', 'confirm' => 'yes']);
ok($r['code'] === 200 && (int) one('SELECT usage_count FROM email_templates WHERE id = :i', ['i' => $mine]) === 1, 'sending with it counts its use');
$r = post_as(26, '/email/templates/delete.php', ['template' => $mine]);
ok($r['code'] === 200 && one('SELECT archived_at IS NOT NULL FROM email_templates WHERE id = :i', ['i' => $mine]) === true && str_contains($r['json']['did'] ?? '', 'Archived'), 'delete on a used one archives it: ' . ($r['json']['did'] ?? $r['body']));
ok(!in_array($mine, array_column(get_as(26, '/email/templates')['json']['data']['rows'] ?? [], 'template_id'), true) && str_contains(html_as(26, '/pick/?source=template&q=' . urlencode("Mine $x"))['body'], 'record-picker-empty'), 'it is out of the list and the picker');
ok((int) one('SELECT count(*) FROM outbound_email WHERE template_id = :i', ['i' => $mine]) === 1, 'and the email sent with it still names it');
ok(html_as(26, "/email/compose?contact=$ann&template=$mine")['code'] === 404, 'compose does not start from an archived one');
$un = (int) post_as(26, '/email/templates/save.php', ['name' => "Unused $x", 'subject' => 's', 'body' => 'b'])['json']['record_id'];
$r = post_as(26, '/email/templates/delete.php', ['template' => $un]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM email_templates WHERE id = :i', ['i' => $un]) === 0, 'one never used is deleted: ' . ($r['json']['did'] ?? $r['body']));
$r = post_as(26, '/email/templates/delete.php', ['template' => $shared]);
ok($r['code'] === 403, 'a Rep may not delete a shared template: 403');
$r = post_as(30, '/email/templates/delete.php', ['template' => $mine]);
ok($r['code'] === 404, 'nor see another\'s own to delete it: 404');
$r = $as_mkt('/email/templates/delete.php', ['template' => $shared]);
ok($r['code'] === 200, 'Marketing deletes (or archives) a shared one');
$r = post_as(26, '/email/templates/delete.php', ['template' => 999999999]);
ok($r['code'] === 404, 'a template that is not there: 404');

echo "5. The log\n";
$rows = q("SELECT action, after::text AS after, before::text AS before FROM activity_log WHERE id > :s AND action LIKE 'template.%'", ['s' => $since]);
$seen = array_unique(array_column($rows, 'action'));
ok(in_array('template.save', $seen, true) && in_array('template.delete', $seen, true), 'template.save and template.delete are written');
ok(!str_contains(json_encode($rows), 'TEMPLATE-SECRET-BODY') && !str_contains(json_encode($rows), 'The manager keeps it too'), 'no body is in any payload');
$one = array_values(array_filter($rows, static fn ($r) => $r['action'] === 'template.save' && str_contains($r['after'], 'merge_fields')))[0] ?? null;
ok($one !== null && in_array('contact.first_name', json_decode($one['after'], true)['merge_fields'], true), 'a save names the merge fields it uses');
finish();
