<?php
declare(strict_types=1);

/**
 * The admin area's reads (admin-settings.md; screens admin, admin-pipelines, admin-fields, admin-tags, admin-settings, admin-tokens …). The admin sees the book's RULES, not its records: pipelines and
 * stages, the vocabularies, custom fields, tags with their use, the settings row, every key. These read the tables the application role holds (the mcp_* views leave out what an editor needs — a stage's own
 * rotting days, a field's archived rows) — each screen is gated by its right before it asks; the database is still the referee of every write. The settings' catalogue and the roles' members are the reads
 * Phase 4's tools `settings_catalogue` and `members_with_roles` answer from the mcp_* views, here as the same shapes.
 */

require_once dirname(__DIR__) . '/mailbox/queries.php';
require_once dirname(__DIR__) . '/tombstones/queries.php';

const STAGE_KINDS = ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost'];
const STAGE_KIND_TONE = ['won' => 'success', 'lost' => 'danger'];                       // an open stage wears its own colour
/** What a stage can require of a deal before it is entered (crm_stage_missing_fields): the deal's columns, by their names; the deal's custom fields are added by key. */
const STAGE_REQUIRED_COLUMNS = ['primary_contact_id' => 'Primary contact', 'account_id' => 'Account', 'value' => 'Value', 'expected_close_date' => 'Expected close date',
    'description' => 'Description', 'source_id' => 'Source', 'products' => 'Product lines'];
const FIELD_KINDS = ['lead' => 'Leads', 'account' => 'Accounts', 'contact' => 'Contacts', 'deal' => 'Deals', 'activity' => 'Activities'];
const FIELD_TABLES = ['lead' => 'leads', 'account' => 'accounts', 'contact' => 'contacts', 'deal' => 'deals', 'activity' => 'activities'];
const FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'select' => 'One choice', 'multi_select' => 'Several choices', 'checkbox' => 'Yes or no',
    'url' => 'Web address', 'email' => 'Email address', 'money' => 'Money', 'member' => 'A person'];

/** The admin home's cards: [area => numbers], the mailbox's health and the tombstones due — one statement. */
function admin_summary(PDO $pdo): array
{
    $r = $pdo->query("SELECT
        (SELECT count(*) FROM pipelines WHERE archived_at IS NULL) AS pipelines, (SELECT count(*) FROM stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE s.archived_at IS NULL AND p.archived_at IS NULL) AS stages,
        (SELECT count(*) FROM lead_sources WHERE active) AS sources, (SELECT count(*) FROM lost_reasons WHERE active) AS reasons,
        (SELECT count(*) FROM assignment_rules WHERE active) AS rules, (SELECT count(*) FROM assignment_rules) AS rules_all,
        (SELECT count(*) FROM custom_field_defs WHERE archived_at IS NULL) AS fields, (SELECT count(*) FROM tags) AS tags,
        (SELECT count(*) FROM quotas WHERE month >= date_trunc('year', current_date)::date AND month < (date_trunc('year', current_date) + interval '1 year')::date) AS quotas,
        (SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())) AS keys,
        (SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api' AND lead_source_id IS NOT NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())) AS capture_keys,
        (SELECT updated_at FROM crm_settings WHERE id = 1) AS settings_updated_at")->fetch();
    $r['mailbox'] = mailbox_summary($pdo);
    $r['tombstones'] = tombstone_counts($pdo);
    return $r;
}

/** The pipelines (live, or the archived ones) each with its stages — archived stages last — and the deals in each. */
function pipelines_with_stages(PDO $pdo, bool $archived): array
{
    $pipes = $pdo->query('SELECT p.id, p.key, p.name, p.is_default, p.currency, p.rotting_days, p.position, p.archived_at, p.id = (SELECT default_pipeline_id FROM crm_settings WHERE id = 1) AS settings_default,
                                 (SELECT count(*) FROM deals d WHERE d.pipeline_id = p.id AND d.deleted_at IS NULL AND d.status = \'open\') AS deals_open,
                                 (SELECT count(*) FROM deals d WHERE d.pipeline_id = p.id) AS deals_all
                            FROM pipelines p WHERE ' . ($archived ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL') . ' ORDER BY p.position, p.id')->fetchAll();
    $stages = $pdo->query("SELECT s.id, s.pipeline_id, s.name, s.position, s.probability, s.kind, s.rotting_days, s.required_fields::text AS required_fields, s.ask_assistant, s.colour, s.wip_limit, s.archived_at,
                                  (SELECT count(*) FROM deals d WHERE d.stage_id = s.id AND d.deleted_at IS NULL AND d.status = 'open') AS deals_open,
                                  (SELECT count(*) FROM deals d WHERE d.stage_id = s.id) AS deals_all,
                                  (SELECT count(*) FROM deal_stage_history h WHERE h.to_stage_id = s.id OR h.from_stage_id = s.id) AS history
                             FROM stages s ORDER BY s.archived_at IS NOT NULL, CASE s.kind WHEN 'open' THEN 0 WHEN 'won' THEN 1 ELSE 2 END, s.position, s.id")->fetchAll();
    $by = [];
    foreach ($stages as $s) {
        $by[(int) $s['pipeline_id']][] = stage_row($s);
    }
    return array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'rotting_days' => $p['rotting_days'] === null ? null : (int) $p['rotting_days'], 'position' => (int) $p['position'],
        'deals_open' => (int) $p['deals_open'], 'deals_all' => (int) $p['deals_all'], 'stages' => $by[(int) $p['id']] ?? []] + $p, $pipes);
}

/** A stages row with its numbers typed and its required fields a list. */
function stage_row(array $s): array
{
    return ['id' => (int) $s['id'], 'pipeline_id' => (int) $s['pipeline_id'], 'position' => (int) $s['position'], 'probability' => (int) $s['probability'],
        'rotting_days' => $s['rotting_days'] === null ? null : (int) $s['rotting_days'], 'required_fields' => pg_text_array((string) $s['required_fields']),
        'ask_assistant' => (bool) $s['ask_assistant'], 'wip_limit' => $s['wip_limit'] === null ? null : (int) $s['wip_limit'],
        'deals_open' => (int) ($s['deals_open'] ?? 0), 'deals_all' => (int) ($s['deals_all'] ?? 0), 'history' => (int) ($s['history'] ?? 0)] + $s;
}

/** One pipeline (any state) or null. */
function pipeline_row(PDO $pdo, int $id): ?array
{
    foreach ([false, true] as $arch) {
        foreach (pipelines_with_stages($pdo, $arch) as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
    }
    return null;
}

/** One stage (any state) with its pipeline's name, or null. */
function stage_one(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, "SELECT s.id, s.pipeline_id, s.name, s.position, s.probability, s.kind, s.rotting_days, s.required_fields::text AS required_fields, s.ask_assistant, s.colour, s.wip_limit, s.archived_at,
                               (SELECT count(*) FROM deals d WHERE d.stage_id = s.id AND d.deleted_at IS NULL AND d.status = 'open') AS deals_open,
                               (SELECT count(*) FROM deals d WHERE d.stage_id = s.id) AS deals_all,
                               (SELECT count(*) FROM deal_stage_history h WHERE h.to_stage_id = s.id OR h.from_stage_id = s.id) AS history, p.name AS pipeline_name
                          FROM stages s JOIN pipelines p ON p.id = s.pipeline_id WHERE s.id = :id", ['id' => $id]);
    return $r === null ? null : stage_row($r) + ['pipeline_name' => $r['pipeline_name']];
}

/** What a stage's required-fields chips offer: the deal's columns and the live custom fields of deals. [key => label] */
function stage_required_choices(PDO $pdo): array
{
    $out = STAGE_REQUIRED_COLUMNS;
    foreach ($pdo->query("SELECT key, label FROM custom_field_defs WHERE applies_to = 'deal' AND archived_at IS NULL ORDER BY sort_order, label")->fetchAll() as $f) {
        $out[$f['key']] = $f['label'] . ' (custom)';
    }
    return $out;
}

/** The custom field definitions of a kind, the archived ones last, each with how many records carry a value for it (a type change is refused while any does). */
function fields(PDO $pdo, string $kind): array
{
    $table = FIELD_TABLES[$kind] ?? null;
    if ($table === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, key, label, field_type, options::text AS options, applies_to, type_ids::text AS type_ids, required_on_solve, sort_order, archived_at FROM custom_field_defs WHERE applies_to = :k
                          ORDER BY archived_at IS NOT NULL, sort_order, label');
    $st->execute(['k' => $kind]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $use = $pdo->prepare("SELECT count(*) FROM $table WHERE jsonb_exists(custom_values, CAST(:key AS text)) AND jsonb_typeof(custom_values -> CAST(:key AS text)) <> 'null'");
        $use->execute(['key' => $f['key']]);
        $out[] = ['id' => (int) $f['id'], 'options_list' => pg_text_array((string) $f['options']), 'pipeline_ids' => pg_int_array($f['type_ids']), 'required_to_win' => (bool) $f['required_on_solve'],
            'in_use' => (int) $use->fetchColumn(), 'sort_order' => (int) $f['sort_order']] + $f;
    }
    return $out;
}

/** One field definition (any state) or null. */
function field_one(PDO $pdo, int $id): ?array
{
    $kind = one_value($pdo, 'SELECT applies_to FROM custom_field_defs WHERE id = :id', ['id' => $id]);
    if ($kind === null) {
        return null;
    }
    foreach (fields($pdo, (string) $kind) as $f) {
        if ($f['id'] === $id) {
            return $f;
        }
    }
    return null;
}

/** The tag vocabulary with its use, most used first. $q narrows by name. */
function tags_with_counts(PDO $pdo, string $q = '', int $limit = 300): array
{
    $st = $pdo->prepare("SELECT id AS tag_id, slug, label, usage_count, created_at FROM tags WHERE :q = '' OR label ILIKE '%' || :q || '%' OR slug ILIKE '%' || :q || '%' ORDER BY usage_count DESC, label LIMIT :l");
    $st->bindValue('q', $q);
    $st->bindValue('l', max(1, min(1000, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function tag_one(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT id AS tag_id, slug, label, usage_count FROM tags WHERE id = :id', ['id' => $id]);
}

/** The settings row (admin-settings): every column the form edits and the ones it shows. */
function settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT * FROM crm_settings WHERE id = 1')->fetch() ?: [];
    unset($r['id']);
    return $r;
}

/** Every key and token — whose, what, the limits, calls today, last use — never a hash (mcp_tokens leaves it out). Live ones first. */
function tokens_all(PDO $pdo): array
{
    return $pdo->query("SELECT t.key_id, t.member_id, m.display_name AS member_name, t.label, t.scope, t.rate_per_minute, t.rate_per_day, t.lead_source_id, s.name AS source_name, t.rotated_from, t.calls_today,
                               t.created_at, t.last_used_at, t.expires_at, t.revoked_at
                          FROM mcp_tokens t JOIN members m ON m.id = t.member_id LEFT JOIN lead_sources s ON s.id = t.lead_source_id
                         ORDER BY (t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())) DESC, t.created_at DESC, t.key_id DESC")->fetchAll();
}

/**
 * The custom fields, pipelines with stages, sources, reasons, assignment rules and settings in one read (Phase 4's `settings_catalogue`): every part through its mcp_* view, so the rules come only to a
 * holder of leads.assign and the mailbox id only to the admin. $part narrows to one.
 */
function settings_catalogue(PDO $pdo, ?string $part = null): array
{
    $parts = ['fields', 'pipelines', 'sources', 'reasons', 'rules', 'settings'];
    $want = $part === null ? $parts : array_values(array_intersect($parts, [$part]));
    $out = [];
    foreach ($want as $p) {
        $out[$p] = match ($p) {
            'fields' => $pdo->query('SELECT field_id, key, label, field_type, options, applies_to, pipeline_ids, required_to_win, sort_order, archived_at FROM mcp_custom_fields WHERE archived_at IS NULL ORDER BY applies_to, sort_order, label')->fetchAll(),
            'pipelines' => array_map(static function (array $p) use ($pdo): array {
                $st = $pdo->prepare('SELECT stage_id, name, position, probability, kind, rotting_days, required_fields, ask_assistant, colour, wip_limit FROM mcp_stages WHERE pipeline_id = :p AND archived_at IS NULL ORDER BY position, stage_id');
                $st->execute(['p' => $p['pipeline_id']]);
                return $p + ['stages' => $st->fetchAll()];
            }, $pdo->query('SELECT pipeline_id, key, name, is_default, currency, rotting_days, position FROM mcp_pipelines WHERE archived_at IS NULL ORDER BY position, pipeline_id')->fetchAll()),
            'sources' => $pdo->query('SELECT source_id, name, kind, active, sort_order FROM mcp_lead_sources ORDER BY sort_order, name')->fetchAll(),
            'reasons' => $pdo->query('SELECT lost_reason_id, kind, label, active, sort_order FROM mcp_lost_reasons ORDER BY kind, sort_order, label')->fetchAll(),
            'rules' => $pdo->query('SELECT rule_id, name, position, active, match, assign_kind, member_id, department_id, members FROM mcp_assignment_rules ORDER BY position, rule_id')->fetchAll(),
            'settings' => $pdo->query('SELECT * FROM mcp_settings')->fetch() ?: [],
        };
    }
    return $out;
}

/** Who holds which role here and which agents work here (Phase 4's `members_with_roles`): rows of mcp_members. */
function members_with_roles(PDO $pdo, ?string $role = null, bool $agentsOnly = false, string $q = '', int $limit = 100): array
{
    $st = $pdo->prepare("SELECT member_id, display_name, member_kind, is_agent, roles_resolved::text AS roles, department_ids::text AS department_ids, timezone FROM mcp_members
                          WHERE (CAST(:r AS text) IS NULL OR CAST(:r AS text) = ANY (roles_resolved)) AND (NOT CAST(:a AS boolean) OR member_kind = 'agent') AND (:q = '' OR display_name ILIKE '%' || :q || '%')
                          ORDER BY (member_kind <> 'human'), display_name LIMIT :l");
    $st->bindValue('r', $role);
    $st->bindValue('a', $agentsOnly ? 't' : 'f');
    $st->bindValue('q', $q);
    $st->bindValue('l', max(1, min(500, $limit)), PDO::PARAM_INT);
    $st->execute();
    return array_map(static fn (array $m): array => ['member_id' => (int) $m['member_id'], 'is_agent' => (bool) $m['is_agent'], 'roles_resolved' => pg_text_array((string) $m['roles']),
        'department_ids' => pg_int_array($m['department_ids'])] + $m, $st->fetchAll());
}
