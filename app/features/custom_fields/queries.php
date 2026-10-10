<?php
declare(strict_types=1);

/**
 * Custom fields for any kind (custom_field_defs, Help Desk's table reused; db/006). The database validates the values (crm_validate_custom_values:
 * an unknown key refused, a wrong type refused naming the field); PHP turns a form's strings into the JSON types the definition names, keeps a
 * value it cannot convert as it came (so the database's sentence names the field), and leaves a key left out as it was.
 */

/** The live definitions of a kind, in order. A deal's field may be for some pipelines only (`pipeline_ids`; empty = all): $pipeline narrows to the pipeline's. */
function custom_field_defs(PDO $pdo, string $kind, ?int $pipeline = null): array
{
    $st = $pdo->prepare('SELECT field_id, key, label, field_type, options, sort_order, required_to_win FROM mcp_custom_fields WHERE applies_to = :k AND archived_at IS NULL
                           AND (CAST(:p AS bigint) IS NULL OR cardinality(pipeline_ids) = 0 OR CAST(:p AS bigint) = ANY (pipeline_ids)) ORDER BY sort_order, label');
    $st->execute(['k' => $kind, 'p' => $pipeline]);
    return array_map(static fn (array $d): array => $d + ['options_list' => pg_text_array((string) $d['options'])], $st->fetchAll());
}

/**
 * The custom values a request sets, merged over $current: a form's `custom[key]` fields (a key whose `custom_present[key]` marker came but whose
 * value did not — an unticked checkbox, an empty multi-select — is set empty), or an agent's `custom_values` (a JSON object, or an array). Null when
 * the request names none (left as it was).
 */
function custom_values_from_request(PDO $pdo, string $kind, array $current, ?int $pipeline = null): ?array
{
    $defs = [];
    foreach (custom_field_defs($pdo, $kind, $pipeline) as $d) {
        $defs[$d['key']] = $d;
    }
    $given = null;
    if (array_key_exists('custom_values', $_POST)) {
        $raw = $_POST['custom_values'];
        $given = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($given)) {
            refuse(422, 'custom_values is an object of the custom fields\' keys.');
        }
    }
    if (isset($_POST['custom']) || isset($_POST['custom_present'])) {
        $given ??= [];
        $form = is_array($_POST['custom'] ?? null) ? $_POST['custom'] : [];
        foreach (array_keys(is_array($_POST['custom_present'] ?? null) ? $_POST['custom_present'] : []) as $k) {
            $given[$k] = $form[$k] ?? null;
        }
        foreach ($form as $k => $v) {
            $given[$k] = $v;
        }
    }
    if ($given === null) {
        return null;
    }
    $out = $current;
    foreach ($given as $k => $v) {
        $k = (string) $k;
        $d = $defs[$k] ?? null;
        $empty = $v === null || $v === '' || $v === [];
        if ($d === null) {
            $out[$k] = $v;                                   // the database refuses an unknown key, naming it
            continue;
        }
        if ($empty && $d['field_type'] !== 'checkbox') {
            unset($out[$k]);
            continue;
        }
        $out[$k] = match ($d['field_type']) {
            'number', 'money' => is_numeric($v) ? (float) $v + 0 : $v,
            'checkbox' => in_array(strtolower(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v), ['1', 'true', 'on', 'yes'], true),
            'multi_select' => is_array($v) ? array_values(array_map('strval', $v)) : array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn ($x) => $x !== '')),
            'member' => is_numeric($v) ? (int) $v : $v,
            default => is_scalar($v) ? trim((string) $v) : $v,
        };
    }
    return $out;
}

/** The values as a person reads them: [label => text] for the set ones, in the definitions' order. */
function custom_values_display(PDO $pdo, string $kind, array $values, ?int $pipeline = null): array
{
    $out = [];
    foreach (custom_field_defs($pdo, $kind, $pipeline) as $d) {
        if (!array_key_exists($d['key'], $values) || $values[$d['key']] === null) {
            continue;
        }
        $v = $values[$d['key']];
        $out[$d['label']] = match ($d['field_type']) {
            'checkbox' => $v ? 'Yes' : 'No',
            'multi_select' => implode(', ', (array) $v),
            'money' => money($v),
            'member' => (string) (member_name($pdo, (int) $v) ?? ''),
            default => (string) $v,
        };
    }
    return $out;
}
