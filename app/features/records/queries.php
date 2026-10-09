<?php
declare(strict_types=1);

/**
 * The record kinds and the rules every record screen and handler shares (slice 1, the CRUD exemplar; design §3): which table, which view,
 * which write right, which URL. THE WALL is the database's (crm_wall inlined in every mcp_* view): a record the caller may not see does not
 * exist to them — the same 404 as a missing one. EDITING is crm_edit_wall(): the owner, an unowned record with the kind's write right, a
 * manager. PHP asks the database; it never re-implements either rule.
 */
const RECORD_KINDS = [
    'lead'     => ['table' => 'leads',      'view' => 'mcp_leads',      'id' => 'lead_id',      'right' => 'leads.write',      'url' => '/leads/',      'noun' => 'lead',     'name' => 'crm_lead_label(t)'],
    'account'  => ['table' => 'accounts',   'view' => 'mcp_accounts',   'id' => 'account_id',   'right' => 'accounts.write',   'url' => '/accounts/',   'noun' => 'account',  'name' => 't.name'],
    'contact'  => ['table' => 'contacts',   'view' => 'mcp_contacts',   'id' => 'contact_id',   'right' => 'contacts.write',   'url' => '/contacts/',   'noun' => 'contact',  'name' => 't.name'],
    'deal'     => ['table' => 'deals',      'view' => 'mcp_deals',      'id' => 'deal_id',      'right' => 'deals.write',      'url' => '/deals/',      'noun' => 'deal',     'name' => 't.title'],
    'activity' => ['table' => 'activities', 'view' => 'mcp_activities', 'id' => 'activity_id',  'right' => 'activities.write', 'url' => '/activities/', 'noun' => 'activity', 'name' => 't.subject'],
    'campaign' => ['table' => 'campaigns',  'view' => 'mcp_campaigns',  'id' => 'campaign_id',  'right' => 'campaigns.manage', 'url' => '/campaigns/',  'noun' => 'campaign', 'name' => 't.name'],
];
/** The kinds a restriction applies to (the wall's three columns exist on these). */
const RESTRICTABLE_KINDS = ['lead', 'account', 'contact', 'deal'];

/** A kind from the request (`kind`), refused in words when it is not one of $allowed. */
function record_kind_from_request(array $allowed = ['lead', 'account', 'contact', 'deal', 'activity', 'campaign']): string
{
    $k = strtolower((string) (req_val('kind') ?? request_string('kind')));
    if (!in_array($k, $allowed, true)) {
        refuse(422, 'Say which kind of record: ' . implode(', ', $allowed) . '.');
    }
    return $k;
}

/**
 * The base row of a record the caller may SEE (through the kind's view: the wall), with the facts the rules need — owner, restriction,
 * the name, the account it belongs to. Null when it does not exist, is tombstoned, or is behind the wall: the caller cannot tell which.
 */
function record_row(PDO $pdo, string $kind, int $id): ?array
{
    $k = RECORD_KINDS[$kind];
    $restrict = in_array($kind, RESTRICTABLE_KINDS, true) ? 't.restricted_to_member_id, t.restricted_to_department_id' : 'NULL::bigint AS restricted_to_member_id, NULL::bigint AS restricted_to_department_id';
    $account = match ($kind) { 'account' => 't.id', 'contact', 'deal', 'activity' => 't.account_id', default => 'NULL::bigint' };
    $owner = $kind === 'campaign' ? 't.owner_member_id' : 't.owner_member_id';
    $st = $pdo->prepare("SELECT t.id, {$k['name']} AS name, {$owner} AS owner_member_id, {$restrict}, {$account} AS account_id, t.deleted_at
                           FROM {$k['table']} t WHERE t.id = :id AND EXISTS (SELECT 1 FROM {$k['view']} v WHERE v.{$k['id']} = t.id)");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** record_row() or the same 404 for a missing, tombstoned or walled record ("Account not found."). */
function require_record(PDO $pdo, string $kind, ?int $id): array
{
    $row = $id === null ? null : record_row($pdo, $kind, $id);
    if ($row === null) {
        refuse(404, ucfirst(RECORD_KINDS[$kind]['noun']) . ' not found.');
    }
    return $row;
}

/** May the caller edit this record (crm_edit_wall: the owner, an unowned record with the kind's write right, a manager)? */
function record_editable(PDO $pdo, string $kind, array $row): bool
{
    return db_bool($pdo, 'SELECT crm_edit_wall(:o, :r)', ['o' => $row['owner_member_id'], 'r' => RECORD_KINDS[$kind]['right']]);
}

/** The edit rule, refused in words naming the owner: "Only Priya, the owner, or a manager may change this account." */
function require_editable(PDO $pdo, string $kind, array $row): void
{
    if (!has_right(RECORD_KINDS[$kind]['right']) && !is_manager()) {
        require_right(RECORD_KINDS[$kind]['right']);
    }
    if (!record_editable($pdo, $kind, $row)) {
        $owner = $row['owner_member_id'] !== null ? (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => (int) $row['owner_member_id']]) : '';
        refuse(403, 'Only ' . ($owner !== '' ? $owner . ', the owner,' : 'the owner') . ' or a manager may change this ' . RECORD_KINDS[$kind]['noun'] . '.');
    }
}

/** A Sales Manager (deals.any — anyone's deals and activities, restrict, merge, bulk) or the admin. */
function is_manager(): bool
{
    static $v = null;
    return $v ??= db_bool(db(), 'SELECT crm_is_manager()');
}

/** A tombstoned record of a kind (for a restore): the base row, or null. The caller's right to it is the handler's to judge. */
function tombstone_row(PDO $pdo, string $kind, int $id): ?array
{
    $k = RECORD_KINDS[$kind];
    $st = $pdo->prepare("SELECT t.id, {$k['name']} AS name, t.owner_member_id, t.deleted_at, t.deleted_by FROM {$k['table']} t WHERE t.id = :id AND t.deleted_at IS NOT NULL");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The web domain a website names, as accounts_before() derives it (db/007): scheme, www and the path off, lower case; null when it is not one. */
function domain_of(?string $website, ?string $domain = null): ?string
{
    $d = trim((string) ($domain ?? ''));
    if ($d === '' && trim((string) $website) !== '') {
        $d = (string) preg_replace('/[\/:?#].*$/', '', (string) preg_replace('/^https?:\/\/(www\.)?/i', '', trim((string) $website)));
    }
    $d = strtolower((string) preg_replace('/^www\./i', '', $d));
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ? $d : null;
}

/** The free-mail domains a contact's address never places them at an account by (db/009's list). */
const FREE_MAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com', 'live.com', 'me.com'];

/**
 * The duplicate warning as you type (D12): records of the kind that look like what is being entered — an account by its exact domain and by a
 * name like it (the setting's similarity); a contact by an exact address (every address of every contact) and by a name like it. Only what the
 * caller may see; `$except` is the record being edited. [{id, name, matched_on, detail, url}]
 */
function record_duplicates(PDO $pdo, string $kind, array $fields, ?int $except = null): array
{
    $out = [];
    $thr = (float) (one_value($pdo, "SELECT crm_setting_text('duplicates_name_similarity')") ?: 0.6);
    if ($kind === 'account') {
        $domain = domain_of($fields['website'] ?? null, $fields['domain'] ?? null);
        if ($domain !== null) {
            $st = $pdo->prepare('SELECT account_id AS id, name, domain FROM mcp_accounts WHERE domain = :d AND account_id IS DISTINCT FROM :x LIMIT 5');
            $st->execute(['d' => $domain, 'x' => $except]);
            foreach ($st->fetchAll() as $r) { $out[$r['id']] = ['id' => (int) $r['id'], 'name' => $r['name'], 'matched_on' => 'domain', 'detail' => $r['domain'], 'url' => '/accounts/' . (int) $r['id']]; }
        }
        $name = trim((string) ($fields['name'] ?? ''));
        if (mb_strlen($name) >= 3) {
            $st = $pdo->prepare('SELECT account_id AS id, name, domain, similarity(name, :n) AS s FROM mcp_accounts WHERE (name % :n OR lower(name) = lower(:n)) AND similarity(name, :n) >= :t AND account_id IS DISTINCT FROM :x ORDER BY s DESC LIMIT 5');
            $st->execute(['n' => $name, 't' => min($thr, 0.99), 'x' => $except]);
            foreach ($st->fetchAll() as $r) { $out[$r['id']] ??= ['id' => (int) $r['id'], 'name' => $r['name'], 'matched_on' => 'name', 'detail' => $r['domain'], 'url' => '/accounts/' . (int) $r['id']]; }
        }
    } elseif ($kind === 'contact') {
        $email = strtolower(trim((string) ($fields['email'] ?? '')));
        if ($email !== '' && str_contains($email, '@')) {
            $st = $pdo->prepare('SELECT c.contact_id AS id, c.name, c.account_name FROM mcp_contacts c
                                  WHERE (lower(c.email) = :e OR lower(c.email_alt) = :e OR EXISTS (SELECT 1 FROM mcp_contact_emails ce WHERE ce.contact_id = c.contact_id AND lower(ce.address) = :e))
                                    AND c.contact_id IS DISTINCT FROM :x LIMIT 5');
            $st->execute(['e' => $email, 'x' => $except]);
            foreach ($st->fetchAll() as $r) { $out[$r['id']] = ['id' => (int) $r['id'], 'name' => $r['name'], 'matched_on' => 'email', 'detail' => $r['account_name'], 'url' => '/contacts/' . (int) $r['id']]; }
        }
        $name = trim(trim((string) ($fields['first_name'] ?? '')) . ' ' . trim((string) ($fields['last_name'] ?? '')));
        if (mb_strlen($name) >= 5) {
            $st = $pdo->prepare('SELECT contact_id AS id, name, account_name, similarity(name, :n) AS s FROM mcp_contacts WHERE name % :n AND similarity(name, :n) >= :t AND contact_id IS DISTINCT FROM :x ORDER BY s DESC LIMIT 5');
            $st->execute(['n' => $name, 't' => min($thr, 0.99), 'x' => $except]);
            foreach ($st->fetchAll() as $r) { $out[$r['id']] ??= ['id' => (int) $r['id'], 'name' => $r['name'], 'matched_on' => 'name', 'detail' => $r['account_name'], 'url' => '/contacts/' . (int) $r['id']]; }
        }
    }
    return array_values($out);
}

/** The business's duplicates policy: warn (the default) or block_exact_email. */
function duplicates_policy(PDO $pdo): string
{
    return (string) (one_value($pdo, 'SELECT duplicates_policy FROM mcp_settings') ?: 'warn');
}

/** The owners a record can be given (people and agents holding a role here), for the owner picker and the filters. */
function find_owners(PDO $pdo): array
{
    return $pdo->query("SELECT member_id, display_name, member_kind FROM mcp_members ORDER BY (member_kind <> 'human'), display_name")->fetchAll();
}

/** A member's display name (the owner on a card), cached per request. */
function member_name(PDO $pdo, ?int $id): ?string
{
    static $cache = [];
    if ($id === null) {
        return null;
    }
    return $cache[$id] ??= (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $id]) ?? '');
}
