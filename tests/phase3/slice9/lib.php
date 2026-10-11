<?php
/**
 * Helpers for the slice 9 proofs (admin-settings.md "Proof") — slice 8's (and so slices 1–6's) and a few of their own. Actors: 1 the admin (a super-admin: every right), 26 Priya a Rep, 27 Marco the Sales Manager (pipelines, sources,
 * rules, tags, quotas), 29 Ann a Viewer, 30 Dana a Rep (Partnerships), 31 Lee (here: Marketing — sources and tags), 40 the Expert (an agent, Rep), 42 the Assistant (an agent).
 * The admin's handlers are people's and agents' alike (an agent without the right is a 403; the registry holds which pause), so `adm()` is the admin as a person.
 */
require dirname(__DIR__) . '/slice8/lib.php';

/** The admin (member 1) posts to a handler: the response with `json`. */
function adm(string $path, array $form): array { return post_as(1, $path, $form); }
/** Everything of the form's error a refusal named: the sentence, in one string. */
function says(array $r): string { return message_of($r) . ' ' . json_encode($r['json']['error']['fields'] ?? []); }
/** A pipeline made by the admin: [id, wonStageId, lostStageId]. */
function pipeline_made(string $name, array $more = []): array
{
    $r = adm('/admin/pipelines/save.php', ['name' => $name] + $more);
    $id = (int) ($r['json']['record_id'] ?? 0);
    if ($id === 0) { fwrite(STDERR, 'pipeline_made failed: ' . $r['body'] . "\n"); }
    return [$id, (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'won'", ['p' => $id]), (int) one("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'lost'", ['p' => $id])];
}
/** An open stage added by the admin: its id. */
function stage_made(int $pipeline, string $name, array $more = []): int
{
    $r = adm('/admin/stages/save.php', ['pipeline' => $pipeline, 'name' => $name] + $more);
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'stage_made failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** The open, live stage ids of a pipeline in order. */
function stage_order(int $pipeline): array
{
    return array_map('intval', array_column(q("SELECT id FROM stages WHERE pipeline_id = :p AND kind = 'open' AND archived_at IS NULL ORDER BY position, id", ['p' => $pipeline]), 'id'));
}
/** A deal sitting in a stage of a pipeline, owned by Priya (a direct insert: the proofs set the board up themselves). */
function deal_in(int $pipeline, int $stage, string $title, array $more = []): int
{
    $cols = ['title' => $title, 'pipeline_id' => $pipeline, 'stage_id' => $stage, 'owner_member_id' => 26, 'created_by' => 26, 'currency' => 'USD', 'value' => 0] + $more;
    as_member(26);
    $st = pdo()->prepare('INSERT INTO deals (' . implode(', ', array_keys($cols)) . ') VALUES (:' . implode(', :', array_keys($cols)) . ') RETURNING id');
    $st->execute($cols);
    return (int) $st->fetchColumn();
}
/** The decoded `data` of an admin screen as a member. */
function screen(int $m, string $path): array { return get_as($m, $path)['json']['data'] ?? []; }
/** Plant a tombstone: a record of a kind deleted $days ago (as postgres). Answers its id. */
function tombstone(string $kind, int $daysAgo, string $label): int
{
    $table = ['lead' => 'leads', 'account' => 'accounts', 'contact' => 'contacts', 'deal' => 'deals', 'activity' => 'activities'][$kind];
    $when = "now() - interval '$daysAgo days'";
    $sql = match ($kind) {
        'lead' => "INSERT INTO leads (company, email, owner_member_id, created_by, deleted_at, deleted_by) VALUES ('$label', '" . strtolower(preg_replace('/\W+/', '', $label)) . "@purge.example.invalid', 26, 26, $when, 26) RETURNING id",
        'account' => "INSERT INTO accounts (name, owner_member_id, created_by, deleted_at, deleted_by) VALUES ('$label', 26, 26, $when, 26) RETURNING id",
        'contact' => "INSERT INTO contacts (first_name, last_name, owner_member_id, created_by, deleted_at, deleted_by) VALUES ('Ti', '$label', 26, 26, $when, 26) RETURNING id",
        'deal' => "INSERT INTO deals (title, pipeline_id, stage_id, owner_member_id, created_by, currency, deleted_at, deleted_by) SELECT '$label', pipeline_id, id, 26, 26, 'USD', $when, 26 FROM stages WHERE name = 'Qualification' AND pipeline_id = 1 RETURNING id",
        'activity' => "INSERT INTO activities (kind, subject, owner_member_id, created_by, due_at, deleted_at, deleted_by) VALUES ('task', '$label', 26, 26, now(), $when, 26) RETURNING id",
    };
    return (int) strtok(trim(pg($sql)), "\n");
}
function exists_row(string $table, int $id): bool { return (int) one("SELECT count(*) FROM $table WHERE id = :i", ['i' => $id]) === 1; }
/** A text[] column's value ("{a,b}" or "{}") as a list (values without commas — the proofs' own). */
function texts(?string $literal): array { $t = trim((string) $literal, '{}'); return $t === '' ? [] : array_map(static fn (string $s): string => trim($s, '"'), explode(',', $t)); }
