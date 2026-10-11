<?php
/**
 * Helpers for the slice 6 proofs (campaigns-lists.md "Proof") — slice 5's (mail helpers, the fake MaluMail) and slice 4's (worker, act_id, at) and a few of their own: a member signed on with another
 * role's claims (Marketing, a Rep with the export-everything right …), leads and contacts for a campaign, the worker's pass at a chosen clock, the files an export wrote.
 * Actors: 1 the admin, 26 Priya a Rep, 27 Marco the Sales Manager, 29 Ann a Viewer, 30 Dana a Rep (Partnerships), 31 Lee (here: Marketing), 40 the Expert (an agent, Rep).
 */
require dirname(__DIR__) . '/slice5/lib.php';

/** A member signed on with the claims of a role (their fixture claims, the role swapped): [jar, csrf]. Cached per member and role. */
function role_actor(int $m, string $role): array
{
    static $cache = [];
    $k = "$m/$role";
    if (!isset($cache[$k])) {
        $claims = fixture()['claims'][(string) $m];
        $claims['roles'] = [$role];
        $claims['role'] = $role;
        [$j, ] = sign_on($m, ['claims' => $claims]);
        $cache[$k] = [$j, page_csrf($j)];
    }
    return $cache[$k];
}
/** A JSON POST as a role-actor. */
function post_role(array $a, string $path, array $form): array
{
    $r = req('POST', $path, ['jar' => $a[0], 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $a[1]], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function get_role(array $a, string $path, array $headers = []): array
{
    $r = req('GET', $path, ['jar' => $a[0], 'headers' => $headers ?: ['Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A lead made as a member: the id. */
function lead_make(int $m, array $f = []): int
{
    $x = run_tag() . '-' . bin2hex(random_bytes(2));
    $r = post_as($m, '/leads/save.php', $f + ['first_name' => 'Lea', 'last_name' => "Lead $x", 'company' => "Co $x", 'email' => "lead-$x@lead.example.invalid", 'owner' => $m]);      // owned by the maker (the assignment rules would leave it in the inbox)
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'lead_make failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** A campaign made as Marco (the Sales Manager holds campaigns.manage): the id. */
function campaign_make(array $f = [], int $m = 27): int
{
    $x = run_tag() . '-' . bin2hex(random_bytes(2));
    $r = post_as($m, '/campaigns/save.php', $f + ['name' => "Camp $x", 'kind' => 'email', 'status' => 'active']);
    if (($r['json']['record_id'] ?? null) === null) { fwrite(STDERR, 'campaign_make failed: ' . $r['body'] . "\n"); }
    return (int) ($r['json']['record_id'] ?? 0);
}
/** The worker's passes at a clock (CRM_WORKER_NOW) with the pass named; the report's `ran` for it. */
function pass(string $name, ?string $now = null): array
{
    $r = worker_at($name, $now);
    return $r['ran'][$name] ?? ['error' => json_encode($r)];
}
/** An export's file as text (the storage directory of the scratch database), or null when it is gone. */
function export_text(int $id): ?string
{
    clearstatcache();
    $p = need('CRM_STORAGE_DIR') . '/exports/' . $id;
    foreach (['.csv', '.json'] as $ext) { if (is_file($p . $ext)) { return (string) file_get_contents($p . $ext); } }
    return null;
}
/** Rows of a CSV text as arrays (header first). */
function csv_rows_of(string $text): array
{
    $fh = fopen('php://memory', 'w+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { $rows[] = $r; }
    return $rows;
}
/** Member rows of a campaign: [campaign_member rows] as the application's role reads them. */
function members_of(int $campaign): array
{
    return q('SELECT * FROM campaign_members WHERE campaign_id = :c ORDER BY id', ['c' => $campaign]);
}
/** The status of one contact in a campaign, or null. */
function member_status(int $campaign, int $contact): ?string
{
    $v = one('SELECT status FROM campaign_members WHERE campaign_id = :c AND contact_id = :k', ['c' => $campaign, 'k' => $contact]);
    return $v === false ? null : (string) $v;
}
/** "{1,2,3}" for a bigint[] parameter. */
function lit(array $ids): string { return '{' . implode(',', array_map('intval', $ids)) . '}'; }
