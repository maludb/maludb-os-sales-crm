<?php
/** Helpers for the slice 3 proofs (deals-board.md, pipeline-board.md §7) — slice 2's helpers and a few of their own. */
require dirname(__DIR__) . '/slice2/lib.php';

/** A stage's id by its name in a pipeline (1 = New business, 2 = Renewals). */
function stage(string $name, int $pipeline = 1): int
{
    return (int) one('SELECT id FROM stages WHERE pipeline_id = :p AND name = :n', ['p' => $pipeline, 'n' => $name]);
}
/** A deal made through deal_create as a member, with an account and a contact so every stage admits it; the id. */
function deal_make(int $m, array $f = []): int
{
    $x = run_tag() . '-' . bin2hex(random_bytes(2));
    if (!array_key_exists('account', $f)) {
        $f['account'] = (int) post_as($m, '/accounts/save.php', ['name' => "Acct $x"])['json']['record_id'];
    }
    if (!array_key_exists('primary_contact', $f)) {
        $f['primary_contact'] = (int) post_as($m, '/contacts/save.php', ['first_name' => 'Pat', 'last_name' => "P $x", 'account' => $f['account']])['json']['record_id'];
    }
    $r = post_as($m, '/deals/save.php', $f + ['title' => "Deal $x", 'value' => 10000, 'expected_close_date' => date('Y-m-d', strtotime('+20 days'))]);
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'deal_make failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** A next step on a deal (an activity owned by the member) at a moment ('-2 days', 'today 23:00', '+3 days'): the activity's id. */
function next_step(int $deal, int $m, string $when, string $kind = 'call'): int
{
    return (int) pg("INSERT INTO activities (kind, subject, deal_id, owner_member_id, created_by, starts_at) VALUES ('$kind', 'Step', $deal, $m, $m, " . ($when === 'today' ? "date_trunc('day', now()) + interval '23 hours 30 minutes'" : "now() + interval '$when'") . ') RETURNING id');
}
/** The columns region for a member (HTMX), the HTML. */
function columns_html(int $m, string $query = ''): string
{
    return html_as($m, '/pipeline/new_business/columns' . ($query === '' ? '' : '?' . $query), ['HX-Request: true'])['body'];
}
/** The ids of the cards inside one column's body in the HTML. */
function cards_in(string $html, int $stage): array
{
    if (!preg_match('/<div class="board-col-body"[^>]*id="board-col-' . $stage . '-body">(.*?)<\/section>/s', $html, $m)) { return []; }
    preg_match_all('/id="deal-card-(\d+)"/', $m[1], $c);
    return array_map('intval', $c[1]);
}
/** A board's stage head from the JSON (by stage id). */
function head_of(array $board, int $stage): ?array
{
    foreach ($board['stages'] as $s) { if ($s['stage_id'] === $stage) { return $s; } }
    return null;
}
