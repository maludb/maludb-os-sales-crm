<?php
declare(strict_types=1);

/**
 * The import's batch code (leads-and-conversion.md §4) — the worker's `imports` pass runs it, and the dry run is the same code inside a transaction
 * it rolls back. It acts as the import's maker (app.member_id): the dedupe looks through the views, so a record behind the wall is never matched,
 * named or updated. Each row is its own savepoint: a row the database refuses is `refused` with the database's sentence, never the batch.
 *
 *   import_execute(PDO, id, dryRun) → [created, updated, skipped, refused, rows, finished]
 * Dry run: every row in ONE transaction (a later row may match an earlier one), rolled back; then the rows' results written. Real run: batches of 500,
 * each committed with its import_rows (so a run interrupted resumes after the last committed row); at the end rollback_until is set.
 */
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/records/queries.php';
require_once dirname(__DIR__) . '/accounts/queries.php';
require_once dirname(__DIR__) . '/contacts/queries.php';
require_once dirname(__DIR__) . '/leads/queries.php';
require_once dirname(__DIR__) . '/tags/queries.php';
require_once dirname(__DIR__) . '/tags/write.php';
require_once dirname(__DIR__) . '/attachments/store.php';

function import_execute(PDO $pdo, int $id, bool $dryRun): array
{
    $imp = $pdo->query('SELECT * FROM imports WHERE id = ' . (int) $id)->fetch();
    if ($imp === false) {
        throw new DomainException('No such import.');
    }
    $pdo->prepare("SELECT set_config('app.member_id', :m, false)")->execute(['m' => (string) (int) $imp['created_by']]);
    $abs = attachment_storage_dir() . '/' . $imp['storage_path'];
    $sniff = csv_sniff($abs);
    $mapping = json_decode((string) $imp['mapping'], true) ?: [];
    $index = [];
    foreach ($sniff['headers'] as $i => $h) {
        if (($mapping[$h] ?? 'skip') !== 'skip') { $index[$i] = $mapping[$h]; }
    }
    $ctx = import_context($pdo, $imp);
    $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'refused' => 0];
    $results = [];
    $resumeAfter = 0;
    if (!$dryRun) {
        $resumeAfter = (int) one_value($pdo, 'SELECT COALESCE(max(row_number), 0) FROM import_rows WHERE import_id = :id', ['id' => $id]);
        foreach ($pdo->query("SELECT result, count(*) AS n FROM import_rows WHERE import_id = $id GROUP BY result")->fetchAll() as $r) { $counts[$r['result']] = (int) $r['n']; }
    }
    $pdo->beginTransaction();
    $inBatch = 0;
    $flush = static function () use ($pdo, $id, &$results, &$counts): void {
        $st = $pdo->prepare('INSERT INTO import_rows (import_id, row_number, result, record_id, message) VALUES (:i, :n, :r, :rec, :m)
                             ON CONFLICT (import_id, row_number) DO UPDATE SET result = EXCLUDED.result, record_id = EXCLUDED.record_id, message = EXCLUDED.message');
        foreach ($results as [$n, $r, $rec, $m]) {
            $st->execute(['i' => $id, 'n' => $n, 'r' => $r, 'rec' => $rec, 'm' => $m === null ? null : mb_substr($m, 0, 300)]);
        }
        $pdo->prepare('UPDATE imports SET rows_made = :c, rows_updated = :u, rows_skipped = :s, unsupported = :f WHERE id = :id')
            ->execute(['c' => $counts['created'], 'u' => $counts['updated'], 's' => $counts['skipped'], 'f' => $counts['refused'], 'id' => $id]);
        $results = [];
    };
    foreach (csv_rows($abs, $sniff['delimiter'], $sniff['encoding']) as [$n, $values]) {
        if ($n <= $resumeAfter) { continue; }
        $row = [];
        foreach ($index as $i => $field) { $row[$field] = $values[$i] ?? ''; }
        $pdo->exec('SAVEPOINT import_row');
        try {
            [$result, $rec, $msg] = import_row($pdo, $imp, $ctx, $row);
            $pdo->exec('RELEASE SAVEPOINT import_row');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT import_row');
            [$result, $rec, $msg] = ['refused', null, $e instanceof DomainException ? $e->getMessage() : db_message($e, 'The row could not be saved.')];
        }
        $counts[$result]++;
        $results[] = [$n, $result, $rec, $msg];
        if (!$dryRun && ++$inBatch >= IMPORT_BATCH) {
            $flush();
            $pdo->commit();
            $pdo->beginTransaction();
            $inBatch = 0;
        }
    }
    if ($dryRun) {
        $pdo->rollBack();                                         // nothing the dry run did stays
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM import_rows WHERE import_id = :id')->execute(['id' => $id]);
    }
    $flush();
    $pdo->prepare("UPDATE imports SET status = 'done', finished_at = now(), rollback_until = CASE WHEN :real THEN now() + make_interval(hours => CAST(:h AS integer)) ELSE NULL END WHERE id = :id")
        ->execute(['real' => $dryRun ? 'f' : 't', 'h' => (int) (one_value($pdo, 'SELECT import_rollback_hours FROM mcp_settings') ?: 24), 'id' => $id]);
    $pdo->commit();
    $pdo->exec("SELECT set_config('app.member_id', '', false)");
    return $counts + ['rows' => array_sum($counts), 'finished' => true];
}

/** What every row of an import shares: the owners by email, the sources and campaigns by name, the pipelines and stages, the custom fields' types, the list. */
function import_context(PDO $pdo, array $imp): array
{
    $kind = (string) $imp['kind'];
    $ctx = ['owners' => [], 'sources' => [], 'campaigns' => [], 'custom' => [], 'pipelines' => [], 'stages' => [], 'import_source' => null];
    foreach ($pdo->query('SELECT member_id, lower(email) AS email FROM mcp_members WHERE email IS NOT NULL')->fetchAll() as $m) { $ctx['owners'][$m['email']] = (int) $m['member_id']; }
    foreach ($pdo->query('SELECT source_id, lower(name) AS name FROM mcp_lead_sources')->fetchAll() as $s) { $ctx['sources'][$s['name']] = (int) $s['source_id']; }
    $ctx['import_source'] = $ctx['sources']['import'] ?? null;
    foreach ($pdo->query('SELECT campaign_id, lower(name) AS name FROM mcp_campaigns')->fetchAll() as $c) { $ctx['campaigns'][$c['name']] = (int) $c['campaign_id']; }
    $st = $pdo->prepare('SELECT key, field_type FROM mcp_custom_fields WHERE applies_to = :k AND archived_at IS NULL');
    $st->execute(['k' => IMPORT_RECORD_KIND[$kind]]);
    foreach ($st->fetchAll() as $c) { $ctx['custom'][$c['key']] = $c['field_type']; }
    foreach ($pdo->query('SELECT pipeline_id, key, lower(name) AS name, is_default FROM mcp_pipelines WHERE archived_at IS NULL')->fetchAll() as $p) {
        $ctx['pipelines'][strtolower((string) $p['key'])] = (int) $p['pipeline_id'];
        $ctx['pipelines'][$p['name']] = (int) $p['pipeline_id'];
        if ($p['is_default']) { $ctx['default_pipeline'] = (int) $p['pipeline_id']; }
    }
    $ctx['default_pipeline'] = (int) (one_value($pdo, 'SELECT default_pipeline_id FROM mcp_settings') ?: ($ctx['default_pipeline'] ?? 0));
    foreach ($pdo->query("SELECT stage_id, pipeline_id, lower(name) AS name, kind, position FROM mcp_stages WHERE archived_at IS NULL ORDER BY position")->fetchAll() as $s) {
        $ctx['stages'][(int) $s['pipeline_id']][$s['name']] = (int) $s['stage_id'];
        if ($s['kind'] === 'open') { $ctx['first_stage'][(int) $s['pipeline_id']] ??= (int) $s['stage_id']; }
    }
    return $ctx;
}

/** A value as the record's column wants it: trimmed, empty → null. */
function import_val(array $row, string $k): ?string
{
    $v = trim((string) ($row[$k] ?? ''));
    return $v === '' ? null : $v;
}

/** The custom values a row carries (custom:<key> columns), typed by the definition — a value that does not fit is kept as text so the database refuses it, naming the field. */
function import_custom(array $ctx, array $row): array
{
    $out = [];
    foreach ($row as $k => $v) {
        if (!str_starts_with((string) $k, 'custom:') || trim((string) $v) === '') { continue; }
        $key = substr((string) $k, 7);
        $t = $ctx['custom'][$key] ?? 'text';
        $v = trim((string) $v);
        $out[$key] = match ($t) {
            'number', 'money', 'member' => is_numeric($v) ? $v + 0 : $v,
            'checkbox' => in_array(strtolower($v), ['1', 'true', 'yes', 'y', 'x'], true),
            'multi_select' => array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($x) => $x !== '')),
            default => $v,
        };
    }
    return $out;
}

/** The owner a row names (owner_email), else the import's, else its maker. */
function import_owner(array $ctx, array $imp, array $row): int
{
    $e = strtolower((string) import_val($row, 'owner_email'));
    if ($e !== '' && isset($ctx['owners'][$e])) { return $ctx['owners'][$e]; }
    if ($e !== '') { throw new DomainException('No one here has the owner\'s email ' . $e . '.'); }
    return (int) ($imp['owner_member_id'] ?? $imp['created_by']);
}

/** A two-letter country, upper-cased; anything else refused in words. */
function import_country(array $row): ?string
{
    $c = import_val($row, 'country');
    if ($c === null) { return null; }
    if (!preg_match('/^[A-Za-z]{2}$/', $c)) { throw new DomainException('The country is a two-letter code (got ' . mb_substr($c, 0, 20) . ').'); }
    return strtoupper($c);
}

/**
 * An account the maker may see, by its domain (the unique index) or its exact name (case-insensitive, through the name's trigram index): the base table
 * with the wall checked on the candidate row — the same rule mcp_accounts applies, without computing the view's columns for every account (slice 2:
 * the view made each row of a 2,000-row import scan the book). Null when none.
 */
function import_account_by(PDO $pdo, string $by, string $value): ?int
{
    $sql = $by === 'domain'
        ? 'SELECT a.id FROM accounts a WHERE a.domain = CAST(:v AS citext)'
        : "SELECT a.id FROM accounts a WHERE a.name ILIKE :v ESCAPE '\\' AND lower(a.name) = lower(:raw)";
    $args = $by === 'domain' ? ['v' => $value] : ['v' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value), 'raw' => $value];
    $v = one_value($pdo, $sql . ' AND a.deleted_at IS NULL AND a.merged_into_id IS NULL AND crm_wall(a.owner_member_id, a.restricted_to_member_id, a.restricted_to_department_id) ORDER BY a.id LIMIT 1', $args);
    return $v === null || $v === false ? null : (int) $v;
}

/** Tags and the list for a record just made. */
function import_after_create(PDO $pdo, array $imp, string $recordKind, int $recordId, array $row): void
{
    foreach (array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? ''))), static fn ($t) => $t !== '') as $t) {
        [$tid] = tag_ensure($pdo, $t);
        tag_apply($pdo, $recordKind, [$recordId], $tid);
    }
    if ($imp['list_id'] !== null) {
        $pdo->prepare('INSERT INTO list_members (list_id, record_id, added_by) VALUES (:l, :r, :by) ON CONFLICT DO NOTHING')->execute(['l' => $imp['list_id'], 'r' => $recordId, 'by' => $imp['created_by']]);
    }
}

/** Fill a matched record's EMPTY columns from the row (the `update` policy): never overwrites a value. Answers whether anything changed. */
function import_fill(PDO $pdo, string $table, int $id, array $values): bool
{
    $values = array_filter($values, static fn ($v) => $v !== null);
    if ($values === []) { return false; }
    $set = implode(', ', array_map(static fn ($c) => "$c = COALESCE($c, :$c)", array_keys($values)));
    $st = $pdo->prepare("UPDATE $table SET $set WHERE id = :id AND (" . implode(' OR ', array_map(static fn ($c) => "$c IS NULL", array_keys($values))) . ')');
    $st->execute($values + ['id' => $id]);
    return $st->rowCount() > 0;
}

/** One row: [result, record id, message]. Throws a DomainException (a sentence) or lets the database refuse — the caller rolls the savepoint back. */
function import_row(PDO $pdo, array $imp, array $ctx, array $row): array
{
    $policy = (string) $imp['dedupe_policy'];
    $by = (int) $imp['created_by'];
    switch ($imp['kind']) {
        case 'leads_csv':
            $email = import_val($row, 'email') !== null ? strtolower((string) import_val($row, 'email')) : null;
            if (import_val($row, 'first_name') === null && import_val($row, 'last_name') === null && import_val($row, 'company') === null && $email === null) {
                throw new DomainException('The row names no company, no person and no email.');
            }
            $src = import_val($row, 'source');
            $sourceId = $src !== null ? ($ctx['sources'][strtolower($src)] ?? throw new DomainException('No lead source is called ' . $src . '.')) : ($imp['source_id'] !== null ? (int) $imp['source_id'] : $ctx['import_source']);
            $camp = import_val($row, 'campaign');
            $f = ['first_name' => import_val($row, 'first_name'), 'last_name' => import_val($row, 'last_name'), 'company' => import_val($row, 'company'), 'title' => import_val($row, 'title'),
                  'email' => $email, 'phone' => import_val($row, 'phone'), 'website' => import_val($row, 'website'), 'city' => import_val($row, 'city'), 'region' => import_val($row, 'region'),
                  'country' => import_country($row), 'message' => import_val($row, 'message') !== null ? mb_substr((string) import_val($row, 'message'), 0, 2000) : null, 'source_id' => $sourceId,
                  'campaign_id' => $camp !== null ? ($ctx['campaigns'][strtolower($camp)] ?? throw new DomainException('No campaign is called ' . $camp . '.')) : null];
            $match = $email !== null && $policy !== 'create' ? one_value($pdo, 'SELECT l.id FROM leads l WHERE l.email = CAST(:e AS citext) AND l.deleted_at IS NULL AND l.converted_at IS NULL AND crm_wall(l.owner_member_id, l.restricted_to_member_id, l.restricted_to_department_id) ORDER BY l.id LIMIT 1', ['e' => $email]) : null;
            if ($match !== null && $match !== false) {
                if ($policy === 'skip') { return ['skipped', (int) $match, 'The lead ' . lead_label($pdo, (int) $match) . ' has this email.']; }
                return import_fill($pdo, 'leads', (int) $match, array_diff_key($f, ['email' => 1])) ? ['updated', (int) $match, 'Filled the empty fields of ' . lead_label($pdo, (int) $match) . '.'] : ['skipped', (int) $match, 'Nothing new for ' . lead_label($pdo, (int) $match) . '.'];
            }
            $cols = array_filter($f, static fn ($v) => $v !== null) + ['owner_member_id' => import_owner($ctx, $imp, $row)];
            $custom = import_custom($ctx, $row);
            $st = $pdo->prepare('INSERT INTO leads (' . implode(', ', array_keys($cols)) . ', custom_values, created_by) VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols))) . ', CAST(:cv AS jsonb), :by) RETURNING id');
            $st->execute($cols + ['cv' => json_encode((object) $custom), 'by' => $by]);
            $id = (int) $st->fetchColumn();
            import_after_create($pdo, $imp, 'lead', $id, $row);
            return ['created', $id, null];

        case 'contacts_csv':
            $email = import_val($row, 'email') !== null ? strtolower((string) import_val($row, 'email')) : null;
            if (import_val($row, 'first_name') === null && import_val($row, 'last_name') === null && $email === null) {
                throw new DomainException('The row names no person and no email.');
            }
            $accountId = null;
            $note = null;
            $adom = import_val($row, 'account_domain') !== null ? domain_of(import_val($row, 'account_domain'), import_val($row, 'account_domain')) : null;
            if ($adom !== null) { $accountId = import_account_by($pdo, 'domain', $adom); }
            if ($accountId === null && ($an = import_val($row, 'account_name')) !== null) { $accountId = import_account_by($pdo, 'name', $an); }
            if ($accountId === null && (import_val($row, 'account_name') !== null || $adom !== null)) { $note = 'No account ' . (import_val($row, 'account_name') ?? $adom) . ' here — left without one.'; }
            $role = import_val($row, 'role') !== null ? strtolower(str_replace(' ', '_', (string) import_val($row, 'role'))) : null;
            if ($role !== null && !isset(CONTACT_ROLES[$role])) { throw new DomainException('The role ' . $role . ' is not one of: ' . implode(', ', array_keys(CONTACT_ROLES)) . '.'); }
            $bd = import_val($row, 'birthday');
            if ($bd !== null && (($d = DateTimeImmutable::createFromFormat('!Y-m-d', $bd)) === false || $d->format('Y-m-d') !== $bd)) { throw new DomainException('The birthday is a date (YYYY-MM-DD).'); }
            $f = ['first_name' => import_val($row, 'first_name'), 'last_name' => import_val($row, 'last_name'), 'title' => import_val($row, 'title'), 'email' => $email, 'phone' => import_val($row, 'phone'),
                  'mobile' => import_val($row, 'mobile'), 'role' => $role, 'city' => import_val($row, 'city'), 'region' => import_val($row, 'region'), 'country' => import_country($row),
                  'linkedin_url' => import_val($row, 'linkedin_url'), 'birthday' => $bd, 'account_id' => $accountId === null ? null : (int) $accountId];
            $match = null;
            if ($policy !== 'create') {
                if ($email !== null) {
                    $match = one_value($pdo, 'SELECT c.id FROM contacts c WHERE c.id IN (SELECT id FROM contacts WHERE email = CAST(:e AS citext) UNION SELECT contact_id FROM contact_emails WHERE address = CAST(:e AS citext))
                                                AND c.deleted_at IS NULL AND c.merged_into_id IS NULL AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id) ORDER BY c.id LIMIT 1', ['e' => $email]) ?: null;
                }
                $digits = preg_replace('/\D/', '', (string) ($f['mobile'] ?? $f['phone'] ?? ''));
                if ($match === null && strlen((string) $digits) >= 7) {
                    $match = one_value($pdo, "SELECT c.id FROM contacts c WHERE regexp_replace(COALESCE(c.mobile, c.phone, ''), '\\D', '', 'g') = :d AND c.deleted_at IS NULL AND c.merged_into_id IS NULL AND crm_wall(c.owner_member_id, c.restricted_to_member_id, c.restricted_to_department_id) ORDER BY c.id LIMIT 1", ['d' => $digits]) ?: null;
                }
            }
            if ($match !== null) {
                if ($policy === 'skip') { return ['skipped', (int) $match, 'The contact ' . contact_label($pdo, (int) $match) . ' matches.']; }
                return import_fill($pdo, 'contacts', (int) $match, array_diff_key($f, ['email' => 1])) ? ['updated', (int) $match, 'Filled the empty fields of ' . contact_label($pdo, (int) $match) . '.'] : ['skipped', (int) $match, 'Nothing new for ' . contact_label($pdo, (int) $match) . '.'];
            }
            $cols = array_filter($f, static fn ($v) => $v !== null) + ['owner_member_id' => import_owner($ctx, $imp, $row)];
            $st = $pdo->prepare('INSERT INTO contacts (' . implode(', ', array_keys($cols)) . ', custom_values, created_by) VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols))) . ', CAST(:cv AS jsonb), :by) RETURNING id');
            $st->execute($cols + ['cv' => json_encode((object) import_custom($ctx, $row)), 'by' => $by]);
            $id = (int) $st->fetchColumn();
            import_after_create($pdo, $imp, 'contact', $id, $row);
            return ['created', $id, $note];

        case 'accounts_csv':
            $name = import_val($row, 'name');
            if ($name === null) { throw new DomainException('The row has no name.'); }
            $domain = domain_of(import_val($row, 'website'), import_val($row, 'domain'));
            if (import_val($row, 'domain') !== null && $domain === null) { throw new DomainException('The domain ' . import_val($row, 'domain') . ' is not a web domain.'); }
            $emp = import_val($row, 'employees');
            if ($emp !== null && !in_array($emp, EMPLOYEE_BANDS, true)) { throw new DomainException('The size is one of: ' . implode(', ', EMPLOYEE_BANDS) . '.'); }
            $f = ['name' => $name, 'legal_name' => import_val($row, 'legal_name'), 'domain' => $domain, 'website' => import_val($row, 'website'), 'industry' => import_val($row, 'industry'), 'employees' => $emp,
                  'phone' => import_val($row, 'phone'), 'email' => import_val($row, 'email') !== null ? strtolower((string) import_val($row, 'email')) : null, 'city' => import_val($row, 'city'),
                  'region' => import_val($row, 'region'), 'country' => import_country($row), 'description' => import_val($row, 'description')];
            $match = null;
            if ($policy !== 'create') {
                if ($domain !== null) { $match = import_account_by($pdo, 'domain', $domain); }
                if ($match === null) { $match = import_account_by($pdo, 'name', $name); }
            }
            if ($match !== null) {
                if ($policy === 'skip') { return ['skipped', (int) $match, 'The account ' . account_label($pdo, (int) $match) . ' matches.']; }
                return import_fill($pdo, 'accounts', (int) $match, array_diff_key($f, ['name' => 1])) ? ['updated', (int) $match, 'Filled the empty fields of ' . account_label($pdo, (int) $match) . '.'] : ['skipped', (int) $match, 'Nothing new for ' . account_label($pdo, (int) $match) . '.'];
            }
            $cols = array_filter($f, static fn ($v) => $v !== null) + ['owner_member_id' => import_owner($ctx, $imp, $row)];
            $st = $pdo->prepare('INSERT INTO accounts (' . implode(', ', array_keys($cols)) . ', custom_values, created_by) VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols))) . ', CAST(:cv AS jsonb), :by) RETURNING id');
            $st->execute($cols + ['cv' => json_encode((object) import_custom($ctx, $row)), 'by' => $by]);
            $id = (int) $st->fetchColumn();
            import_after_create($pdo, $imp, 'account', $id, $row);
            return ['created', $id, null];

        case 'deals_csv':
            $title = import_val($row, 'title');
            if ($title === null) { throw new DomainException('The row has no title.'); }
            $accountId = ($an = import_val($row, 'account_name')) !== null ? import_account_by($pdo, 'name', $an) : null;
            if ($an !== null && $accountId === null) { throw new DomainException('No account ' . $an . ' here.'); }
            $contactId = ($ce = import_val($row, 'contact_email')) !== null ? (one_value($pdo, 'SELECT crm_contact_by_address(CAST(:e AS citext))', ['e' => strtolower($ce)]) ?: null) : null;
            $pipe = ($pn = import_val($row, 'pipeline')) !== null ? ($ctx['pipelines'][strtolower($pn)] ?? throw new DomainException('No pipeline is called ' . $pn . '.')) : $ctx['default_pipeline'];
            $stage = ($sn = import_val($row, 'stage')) !== null ? ($ctx['stages'][$pipe][strtolower($sn)] ?? throw new DomainException('The pipeline has no stage ' . $sn . '.')) : ($ctx['first_stage'][$pipe] ?? throw new DomainException('The pipeline has no open stage.'));
            $value = import_val($row, 'value');
            if ($value !== null) { $value = str_replace([',', ' '], '', $value); if (!is_numeric($value)) { throw new DomainException('The value ' . import_val($row, 'value') . ' is not an amount.'); } }
            $cur = import_val($row, 'currency');
            if ($cur !== null && !preg_match('/^[A-Za-z]{3}$/', $cur)) { throw new DomainException('The currency is a three-letter code.'); }
            $close = import_val($row, 'close_date');
            if ($close !== null && (($d = DateTimeImmutable::createFromFormat('!Y-m-d', $close)) === false || $d->format('Y-m-d') !== $close)) { throw new DomainException('The close date is a date (YYYY-MM-DD).'); }
            if ($policy !== 'create') {
                $match = one_value($pdo, 'SELECT d.id FROM deals d WHERE d.account_id IS NOT DISTINCT FROM CAST(:a AS bigint) AND lower(d.title) = lower(:t) AND d.deleted_at IS NULL AND crm_wall(d.owner_member_id, d.restricted_to_member_id, d.restricted_to_department_id) ORDER BY d.id LIMIT 1', ['t' => $title, 'a' => $accountId]);
                if ($match !== null && $match !== false) {
                    if ($policy === 'skip') { return ['skipped', (int) $match, 'The deal ' . $title . ' is here already.']; }
                    return import_fill($pdo, 'deals', (int) $match, ['primary_contact_id' => $contactId, 'expected_close_date' => $close]) ? ['updated', (int) $match, 'Filled the empty fields of ' . $title . '.'] : ['skipped', (int) $match, 'Nothing new for ' . $title . '.'];
                }
            }
            $cols = ['title' => $title, 'account_id' => $accountId, 'primary_contact_id' => $contactId, 'pipeline_id' => $pipe, 'stage_id' => $stage, 'value' => $value ?? '0',
                     'currency' => $cur !== null ? strtoupper($cur) : null, 'expected_close_date' => $close, 'source_id' => $imp['source_id'], 'owner_member_id' => import_owner($ctx, $imp, $row)];
            $cols = array_filter($cols, static fn ($v) => $v !== null);
            $st = $pdo->prepare('INSERT INTO deals (' . implode(', ', array_keys($cols)) . ', custom_values, created_by) VALUES (' . implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols))) . ', CAST(:cv AS jsonb), :by) RETURNING id');
            $st->execute($cols + ['cv' => json_encode((object) import_custom($ctx, $row)), 'by' => $by]);
            $id = (int) $st->fetchColumn();
            import_after_create($pdo, $imp, 'deal', $id, $row);
            return ['created', $id, null];
    }
    throw new DomainException('Unknown import kind.');
}

/** Roll back an import's real run: every `created` record to the tombstone (crm_record_delete); updated rows keep their values. Answers how many were tombstoned. */
function import_rollback_execute(PDO $pdo, array $imp): int
{
    $kind = IMPORT_RECORD_KIND[$imp['kind']];
    $n = 0;
    $st = $pdo->prepare("SELECT record_id FROM import_rows WHERE import_id = :id AND result = 'created' AND record_id IS NOT NULL ORDER BY row_number DESC");
    $st->execute(['id' => $imp['import_id'] ?? $imp['id']]);
    $del = $pdo->prepare('SELECT crm_record_delete(:k, :r, false)');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $del->execute(['k' => $kind, 'r' => (int) $rid]);
        $n++;
    }
    return $n;
}
