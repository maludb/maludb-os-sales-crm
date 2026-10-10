<?php
declare(strict_types=1);

/** Imports (screens `import-list`, `import-new`, `import-view`; the records server's find_imports / get_import / import_rows): mcp_imports shows the caller's own, or all with imports.shared. */
const IMPORT_KINDS = ['leads_csv' => 'Leads', 'contacts_csv' => 'Contacts', 'accounts_csv' => 'Accounts', 'deals_csv' => 'Deals'];
const IMPORT_RECORD_KIND = ['leads_csv' => 'lead', 'contacts_csv' => 'contact', 'accounts_csv' => 'account', 'deals_csv' => 'deal'];
const IMPORT_STATUS_BADGE = ['queued' => 'secondary', 'running' => 'primary', 'done' => 'success', 'failed' => 'danger', 'rolled_back' => 'dark'];
const IMPORT_RESULTS = ['created' => 'Created', 'updated' => 'Updated', 'skipped' => 'Skipped', 'refused' => 'Refused'];
const IMPORT_BATCH = 500;
/** The fields a column can be mapped to, per kind, with their labels and the header words that map to them by themselves. */
const IMPORT_FIELDS = [
    'leads_csv' => ['first_name' => ['First name', ['first', 'firstname', 'given_name']], 'last_name' => ['Last name', ['last', 'lastname', 'surname', 'family_name']],
        'company' => ['Company', ['company_name', 'organisation', 'organization', 'account', 'account_name']], 'title' => ['Title', ['job_title', 'position']],
        'email' => ['Email', ['e_mail', 'email_address', 'mail']], 'phone' => ['Phone', ['telephone', 'phone_number', 'mobile']], 'website' => ['Website', ['url', 'web', 'site']],
        'city' => ['City', ['town']], 'region' => ['Region', ['state', 'province', 'county']], 'country' => ['Country (2 letters)', ['country_code']], 'source' => ['Source (a lead source\'s name)', ['lead_source']],
        'campaign' => ['Campaign (its name)', []], 'message' => ['Message', ['notes', 'comment', 'comments']], 'owner_email' => ['Owner\'s email', ['owner']], 'tags' => ['Tags (comma-separated)', ['tag', 'labels']]],
    'contacts_csv' => ['first_name' => ['First name', ['first', 'firstname', 'given_name']], 'last_name' => ['Last name', ['last', 'lastname', 'surname', 'family_name']],
        'title' => ['Title', ['job_title', 'position']], 'email' => ['Email', ['e_mail', 'email_address', 'mail']], 'phone' => ['Phone', ['telephone', 'phone_number']], 'mobile' => ['Mobile', ['cell', 'mobile_phone']],
        'role' => ['Role', []], 'city' => ['City', ['town']], 'region' => ['Region', ['state', 'province']], 'country' => ['Country (2 letters)', ['country_code']], 'linkedin_url' => ['LinkedIn', ['linkedin']],
        'birthday' => ['Birthday (YYYY-MM-DD)', ['date_of_birth', 'dob']], 'account_name' => ['Account (its name)', ['company', 'company_name', 'organisation', 'organization', 'account']],
        'account_domain' => ['Account (its domain)', ['domain', 'company_domain', 'website']], 'owner_email' => ['Owner\'s email', ['owner']], 'tags' => ['Tags (comma-separated)', ['tag', 'labels']]],
    'accounts_csv' => ['name' => ['Name', ['account', 'account_name', 'company', 'company_name', 'organisation', 'organization']], 'legal_name' => ['Legal name', []], 'domain' => ['Domain', []],
        'website' => ['Website', ['url', 'web', 'site']], 'industry' => ['Industry', ['sector']], 'employees' => ['Size (a band like 51-200)', ['size', 'headcount']], 'phone' => ['Phone', ['telephone']],
        'email' => ['Email', ['e_mail']], 'city' => ['City', ['town']], 'region' => ['Region', ['state', 'province']], 'country' => ['Country (2 letters)', ['country_code']],
        'description' => ['About', ['notes', 'description']], 'owner_email' => ['Owner\'s email', ['owner']], 'tags' => ['Tags (comma-separated)', ['tag', 'labels']]],
    'deals_csv' => ['title' => ['Title', ['deal', 'deal_name', 'name', 'opportunity']], 'account_name' => ['Account (its name)', ['account', 'company', 'company_name']],
        'contact_email' => ['Contact\'s email', ['email', 'contact']], 'pipeline' => ['Pipeline (its name or key)', []], 'stage' => ['Stage (its name)', ['deal_stage']],
        'value' => ['Value', ['amount', 'deal_value']], 'currency' => ['Currency', []], 'close_date' => ['Close date (YYYY-MM-DD)', ['expected_close', 'close', 'expected_close_date']], 'owner_email' => ['Owner\'s email', ['owner']]],
];

/** The caller's imports (or everyone's with imports.shared), newest first. $filters: status, kind. */
function find_imports(PDO $pdo, array $filters = []): array
{
    $w = [];
    $a = [];
    if (($filters['status'] ?? null) !== null) { $w[] = 'i.status = :s'; $a['s'] = $filters['status']; }
    if (($filters['kind'] ?? null) !== null) { $w[] = 'i.kind = :k'; $a['k'] = $filters['kind']; }
    $st = $pdo->prepare('SELECT i.*, m.display_name AS created_by_name FROM mcp_imports i LEFT JOIN members m ON m.id = i.created_by' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY i.created_at DESC LIMIT 200');
    $st->execute($a);
    return $st->fetchAll();
}

function find_import(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, m.display_name AS created_by_name, o.display_name AS owner_name, s.name AS source_name, l.name AS list_name, t.name AS template_name
                           FROM mcp_imports i LEFT JOIN members m ON m.id = i.created_by LEFT JOIN members o ON o.id = i.owner_member_id LEFT JOIN lead_sources s ON s.id = i.source_id
                           LEFT JOIN lists l ON l.id = i.list_id LEFT JOIN import_templates t ON t.id = i.template_id WHERE i.import_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** One page of an import's rows, filtered by result: ['rows', 'total']. */
function import_rows(PDO $pdo, int $id, ?string $result, int $page): array
{
    $size = 100;
    $st = $pdo->prepare('SELECT row_number, result, record_id, message, count(*) OVER () AS total FROM mcp_import_rows WHERE import_id = :id AND (CAST(:r AS text) IS NULL OR result = CAST(:r AS text))
                          ORDER BY row_number LIMIT :l OFFSET :o');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('r', $result);
    $st->bindValue('l', $size, PDO::PARAM_INT);
    $st->bindValue('o', (max(1, $page) - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total'], 'page_size' => $size];
}

/** A saved mapping the caller may use (their own or a shared one). */
function find_template(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, name, kind, mapping, dedupe_policy, is_shared, owner_member_id FROM import_templates WHERE id = :id AND (is_shared OR owner_member_id = app_current_member_id())');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r + ['mapping_list' => json_decode((string) $r['mapping'], true) ?: []];
}

/** The ceiling on rows: the setting (50,000), never above CRM_MAX_IMPORT_ROWS when set. */
function import_max_rows(PDO $pdo): int
{
    $s = (int) (one_value($pdo, 'SELECT import_max_rows FROM mcp_settings') ?: 50000);
    $env = (int) env('CRM_MAX_IMPORT_ROWS', '0');
    return $env > 0 ? min($s, $env) : $s;
}

/**
 * A mapping for these headers: a template's (by header), else each header by its own words (a field's name or one of its words, or a custom field's key
 * or label as custom:<key>), else skip. [header => field | custom:<key> | skip]
 */
function import_auto_mapping(PDO $pdo, string $kind, array $headers, array $template = []): array
{
    $fields = IMPORT_FIELDS[$kind];
    $custom = [];
    $st = $pdo->prepare('SELECT key, label FROM mcp_custom_fields WHERE applies_to = :k AND archived_at IS NULL');
    $st->execute(['k' => IMPORT_RECORD_KIND[$kind]]);
    foreach ($st->fetchAll() as $c) { $custom[csv_norm((string) $c['key'])] = $c['key']; $custom[csv_norm((string) $c['label'])] = $c['key']; }
    $out = [];
    $used = [];
    foreach ($headers as $h) {
        if (array_key_exists($h, $template)) { $out[$h] = (string) $template[$h]; continue; }
        $n = csv_norm($h);
        $hit = 'skip';
        foreach ($fields as $f => [, $words]) {
            if (!isset($used[$f]) && ($n === $f || in_array($n, $words, true))) { $hit = $f; break; }
        }
        if ($hit === 'skip' && isset($custom[$n])) { $hit = 'custom:' . $custom[$n]; }
        if ($hit !== 'skip') { $used[$hit] = true; }
        $out[$h] = $hit;
    }
    return $out;
}
