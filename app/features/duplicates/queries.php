<?php
declare(strict_types=1);

/**
 * Duplicates (screens `duplicates`, `merge`; the records server's find_duplicates): crm_duplicates_open(kind) — the pairs by exact email, domain or
 * phone, or a name alike, less the pairs a person said are not duplicates (db/017). A pair is shown only when the caller may see both records.
 */
const DUPLICATE_KINDS = ['contact' => 'Contacts', 'account' => 'Accounts', 'lead' => 'Leads'];
/** The fields the merge page compares, per kind: [column => label]. The survivor keeps its values; the loser's fill the empty ones (crm_records_merge). */
const MERGE_FIELDS = [
    'contact' => ['name' => 'Name', 'email' => 'Email', 'email_alt' => 'Other email', 'title' => 'Title', 'phone' => 'Phone', 'mobile' => 'Mobile', 'account_name' => 'Account', 'role' => 'Role',
                  'city' => 'City', 'country' => 'Country', 'lifecycle' => 'Lifecycle', 'owner_name' => 'Owner', 'created_at' => 'Added'],
    'account' => ['name' => 'Name', 'domain' => 'Domain', 'website' => 'Website', 'industry' => 'Industry', 'employees' => 'Size', 'phone' => 'Phone', 'city' => 'City', 'country' => 'Country',
                  'kind' => 'Kind', 'owner_name' => 'Owner', 'contacts_count' => 'Contacts', 'open_deals_count' => 'Open deals', 'created_at' => 'Added'],
    'lead' => ['label' => 'Name', 'email' => 'Email', 'company' => 'Company', 'title' => 'Title', 'phone' => 'Phone', 'status' => 'Status', 'source' => 'Source', 'owner_name' => 'Owner', 'created_at' => 'Added'],
];

/** One record of a kind for the duplicates and merge pages (through its view: the wall), with the owner's name. */
function duplicate_record(PDO $pdo, string $kind, int $id): ?array
{
    $v = RECORD_KINDS[$kind];
    $st = $pdo->prepare("SELECT r.*, m.display_name AS owner_name FROM {$v['view']} r LEFT JOIN members m ON m.id = r.owner_member_id WHERE r.{$v['id']} = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The open pairs of a kind the caller may see both sides of: [{a, b, matched_on, similarity}], a and b the records. */
function duplicates(PDO $pdo, string $kind, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT a_id, b_id, matched_on, similarity FROM crm_duplicates_open(:k, :l)');
    $st->bindValue('k', $kind);
    $st->bindValue('l', $limit * 2, PDO::PARAM_INT);
    $st->execute();
    $out = [];
    $seen = [];
    foreach ($st->fetchAll() as $p) {
        $a = min((int) $p['a_id'], (int) $p['b_id']);
        $b = max((int) $p['a_id'], (int) $p['b_id']);
        if (isset($seen["$a-$b"])) { continue; }
        $seen["$a-$b"] = true;
        $ra = duplicate_record($pdo, $kind, $a);
        $rb = duplicate_record($pdo, $kind, $b);
        if ($ra === null || $rb === null) { continue; }
        $out[] = ['a' => $ra, 'b' => $rb, 'a_id' => $a, 'b_id' => $b, 'matched_on' => $p['matched_on'], 'similarity' => round((float) $p['similarity'], 2)];
        if (count($out) >= $limit) { break; }
    }
    return $out;
}

/** The two records side by side, the survivor defaulting to the older: [a, b, fields [column => label], differs [column => bool], survivor (id)]. */
function merge_preview(PDO $pdo, string $kind, int $a, int $b): array
{
    $ra = duplicate_record($pdo, $kind, $a);
    $rb = duplicate_record($pdo, $kind, $b);
    if ($ra === null || $rb === null) {
        return [];
    }
    $differs = [];
    foreach (MERGE_FIELDS[$kind] as $col => $label) {
        $differs[$col] = (string) ($ra[$col] ?? '') !== (string) ($rb[$col] ?? '');
    }
    $survivor = strcmp((string) $ra['created_at'], (string) $rb['created_at']) <= 0 ? $a : $b;
    return ['a' => $ra, 'b' => $rb, 'fields' => MERGE_FIELDS[$kind], 'differs' => $differs, 'survivor' => $survivor];
}

/** A record's name on these pages. */
function duplicate_name(string $kind, array $r): string
{
    return (string) ($kind === 'lead' ? $r['label'] : ($r['name'] !== '' && $r['name'] !== null ? $r['name'] : ($r['email'] ?? '')));
}
