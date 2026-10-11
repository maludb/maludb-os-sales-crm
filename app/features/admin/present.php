<?php
declare(strict_types=1);

/**
 * The admin screens' JSON (screens admin, admin-pipelines, admin-sources, admin-rules, admin-fields, admin-tags, admin-mailbox, admin-quotas, admin-settings, admin-tokens, admin-purge): whitelisted fields, ids as
 * numbers, times as ISO 8601 UTC. Never a key's hash or a secret: the mailbox shows the NAME of its secret's key and whether it is set.
 */
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/keys/queries.php';
require_once dirname(__DIR__) . '/rules/queries.php';
require_once dirname(__DIR__) . '/settings/admin_write.php';

function present_stage(array $s): array
{
    return ['stage_id' => $s['id'], 'pipeline_id' => $s['pipeline_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'position' => $s['position'], 'probability' => $s['probability'], 'rotting_days' => $s['rotting_days'],
        'required_fields' => $s['required_fields'], 'ask_assistant' => $s['ask_assistant'], 'colour' => $s['colour'], 'wip_limit' => $s['wip_limit'], 'archived' => $s['archived_at'] !== null,
        'deals_open' => $s['deals_open'], 'deals_all' => $s['deals_all']];
}

function present_pipeline(array $p): array
{
    return ['pipeline_id' => $p['id'], 'key' => $p['key'], 'name' => $p['name'], 'is_default' => (bool) $p['is_default'], 'currency' => $p['currency'], 'rotting_days' => $p['rotting_days'], 'position' => $p['position'],
        'archived' => $p['archived_at'] !== null, 'deals_open' => $p['deals_open'], 'deals_all' => $p['deals_all'], 'stages' => array_map('present_stage', $p['stages'])];
}

function present_field(array $f): array
{
    return ['field_id' => $f['id'], 'applies_to' => $f['applies_to'], 'key' => $f['key'], 'label' => $f['label'], 'field_type' => $f['field_type'], 'options' => $f['options_list'], 'pipeline_ids' => $f['pipeline_ids'],
        'required_to_win' => $f['required_to_win'], 'sort_order' => $f['sort_order'], 'archived' => $f['archived_at'] !== null, 'in_use' => $f['in_use']];
}

function present_source(array $s): array
{
    return ['source_id' => $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'active' => $s['active'], 'sort_order' => $s['sort_order'], 'leads' => $s['leads'], 'deals' => $s['deals'], 'accounts' => $s['accounts'],
        'contacts' => $s['contacts'], 'keys' => $s['keys'], 'rules' => $s['rules']];
}

function present_reason(array $r): array
{
    return ['lost_reason_id' => $r['id'], 'kind' => $r['kind'], 'label' => $r['label'], 'active' => $r['active'], 'sort_order' => $r['sort_order'], 'deals' => $r['deals'], 'leads' => $r['leads']];
}

function present_tag_row(array $t): array
{
    return ['tag_id' => (int) $t['tag_id'], 'slug' => $t['slug'], 'label' => $t['label'], 'usage_count' => (int) $t['usage_count']];
}

/** The settings by FORM name, with the group and kind of each (so an agent can fill the form), and the choices. */
function present_settings(array $row): array
{
    $out = [];
    foreach (SETTINGS_FIELDS as $name => [$group, $kind, $label, $column]) {
        $v = $row[$column] ?? null;
        $out[$name] = is_bool($v) ? $v : ($v === null ? null : (is_numeric($v) ? $v + 0 : $v));
    }
    return $out;
}

/** A key or token row (admin-tokens): never the hash. */
function present_token_row(array $t): array
{
    $live = $t['revoked_at'] === null && ($t['expires_at'] === null || strtotime((string) $t['expires_at']) > time());
    return ['key_id' => (int) $t['key_id'], 'kind' => $t['scope'] === 'mcp' ? 'mcp_token' : ($t['lead_source_id'] === null ? 'api_key' : 'lead_capture_key'), 'label' => $t['label'], 'member_id' => (int) $t['member_id'], 'member_name' => $t['member_name'],
        'lead_source_id' => $t['lead_source_id'] === null ? null : (int) $t['lead_source_id'], 'source_name' => $t['source_name'], 'rate_per_minute' => $t['rate_per_minute'] === null ? null : (int) $t['rate_per_minute'],
        'rate_per_day' => $t['rate_per_day'] === null ? null : (int) $t['rate_per_day'], 'calls_today' => (int) $t['calls_today'], 'created_at' => json_ts($t['created_at']), 'last_used_at' => json_ts($t['last_used_at']),
        'expires_at' => json_ts($t['expires_at']), 'revoked' => $t['revoked_at'] !== null, 'live' => $live];
}

function present_quota_grid(array $g): array
{
    return ['year' => $g['year'], 'currency' => $g['currency'], 'months' => $g['months'], 'sellers' => array_map(static fn (array $s): array => ['member_id' => $s['member_id'], 'display_name' => $s['display_name'],
        'cells' => array_map(static fn ($v): ?float => $v === null ? null : (float) $v, $s['cells'])], $g['sellers'])];
}

/** The home's cards, each with the right that opens it and a number in words: [id, url, icon, title, right, words, tone?]. A card shows only to a holder of its right. */
function admin_areas(array $s): array
{
    $n = static fn (int $c, string $one, string $many): string => $c . ' ' . ($c === 1 ? $one : $many);
    $mb = $s['mailbox'];
    $due = (int) $s['tombstones']['due'];
    return [
        ['pipelines', '/admin/pipelines', 'feather-columns', 'Pipelines and stages', 'pipelines.manage', $n((int) $s['pipelines'], 'pipeline', 'pipelines') . ' · ' . $n((int) $s['stages'], 'stage', 'stages'), null],
        ['sources', '/admin/sources', 'feather-compass', 'Sources and lost reasons', 'sources.manage', $n((int) $s['sources'], 'source', 'sources') . ' · ' . $n((int) $s['reasons'], 'reason', 'reasons'), null],
        ['rules', '/admin/rules', 'feather-shuffle', 'Lead assignment rules', 'assignment.rules', $n((int) $s['rules'], 'rule', 'rules') . ' in use', null],
        ['fields', '/admin/fields', 'feather-sliders', 'Custom fields', 'fields.manage', $n((int) $s['fields'], 'field', 'fields'), null],
        ['tags', '/admin/tags', 'feather-tag', 'Tags', 'tags.manage', $n((int) $s['tags'], 'tag', 'tags'), null],
        ['mailbox', '/admin/mailbox', 'feather-mail', 'The logging mailbox', 'mailbox.manage', ($mb['address'] !== null ? $mb['address'] . ' — ' : '') . $mb['words'], ['ok' => 'success', 'quiet' => 'warning', 'error' => 'danger', 'off' => 'secondary', 'none' => 'secondary'][$mb['state']] ?? 'secondary'],
        ['quotas', '/admin/quotas', 'feather-target', 'Quotas', 'quotas.manage', $n((int) $s['quotas'], 'quota', 'quotas') . ' set this year', null],
        ['settings', '/admin/settings', 'feather-settings', 'Settings', 'settings.manage', 'Currency, rotting, duplicates, reminders, the API', null],
        ['agents', '/admin/agents', 'feather-cpu', 'Agents', 'agents.settings', 'The Sales Assistant and what woke it', null],
        ['tokens', '/admin/tokens', 'feather-key', 'Keys and tokens', 'tokens.manage', $n((int) $s['keys'], 'live key', 'live keys') . ($s['capture_keys'] > 0 ? ' (' . (int) $s['capture_keys'] . ' lead-capture)' : ''), null],
        ['purge', '/admin/purge', 'feather-trash-2', 'Deleted records', 'records.purge', $due > 0 ? $n($due, 'record is', 'records are') . ' due to be purged' : ($s['tombstones']['waiting'] > 0 ? $n((int) $s['tombstones']['waiting'], 'record waits', 'records wait') . ' in their window' : 'Nothing waits'), $due > 0 ? 'warning' : null],
    ];
}
