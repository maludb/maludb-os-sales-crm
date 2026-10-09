<?php
/**
 * Proof: the write guard reads the schema's refusals as sentences (found in Phase 2, 2026-10-09 — the kit read only P0001, so the schema's
 * check_violation, unique_violation, foreign_key_violation and no_data_found refusals would have answered 500). db_message() against the real
 * schema on the scratch database, each case rolled back; PostgreSQL's own constraint text is never shown to a person.
 */
require __DIR__ . '/lib.php';
require dirname(__DIR__, 2) . '/app/db.php';
ini_set('error_log', '/dev/null');                                       // db_message() logs every error it reads: these are expected
seed_book();
$cases = [
    ['23514', 'A deal needs a title', "INSERT INTO deals (title, pipeline_id, stage_id, owner_member_id, created_by) SELECT '', p.id, s.id, 26, 26 FROM pipelines p JOIN stages s ON s.pipeline_id = p.id LIMIT 1"],
    ['23503', 'No such stage', "UPDATE deals SET stage_id = 999999 WHERE id = (SELECT min(id) FROM deals WHERE status = 'open')"],
    ['P0002', 'No such deal', 'SELECT crm_deal_move(999999, 1, NULL)'],
    ['23505', 'Another contact', "UPDATE crm_settings SET duplicates_policy = 'block_exact_email'; INSERT INTO contacts (first_name, last_name, email, owner_member_id, created_by) VALUES ('Dup', 'Licate', 'dana.reyes@acme.example.invalid', 26, 26)"],
    ['23505', '(fallback)', "INSERT INTO pipelines (key, name) VALUES ('new_business', 'Again')"],
];
foreach ($cases as [$code, $want, $sql]) {
    as_member(26);
    $p = pdo();
    $p->beginTransaction();
    try { $p->exec($sql); $got = null; $msg = 'no error'; } catch (PDOException $e) { $got = (string) $e->getCode(); $msg = db_message($e, '(fallback)'); }
    $p->rollBack();
    ok($got === $code && str_starts_with($msg, $want), "$code answers \"" . mb_substr($msg, 0, 70) . "\"" . ($want === '(fallback)' ? ' — a constraint\'s own text falls back to the handler\'s sentence' : ' — the schema\'s sentence'));
}
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/handler.php');
ok(str_contains($src, "['P0001', '23514', '23503']") && str_contains($src, "=== 'P0002'") && str_contains($src, "db_message(\$e, 'That is already taken.')"), 'crm_guard() answers 422 for a rule refused, a missing referent and a taken value, 404 for no_data_found');
finish();
