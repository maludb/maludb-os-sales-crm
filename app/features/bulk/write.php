<?php
declare(strict_types=1);

/**
 * Bulk writes (bulk_assign, bulk_tag, bulk_update, bulk_campaign_add, bulk_list_add, bulk_delete): each takes a kind and the ids bulk_select() resolved, and runs inside the handler's one
 * transaction. The handler has already checked that the caller may CHANGE every record (bulk_require_editable — one that is not refuses the whole action by name); the database keeps its own
 * rules besides (a converted lead is read-only, a custom value is typed). Every function answers how many records it changed.
 */
require_once __DIR__ . '/select.php';
require_once dirname(__DIR__) . '/leads/write.php';
require_once dirname(__DIR__) . '/deals/write.php';
require_once dirname(__DIR__) . '/tags/queries.php';
require_once dirname(__DIR__) . '/tags/write.php';
require_once dirname(__DIR__) . '/records/write.php';
require_once dirname(__DIR__) . '/custom_fields/queries.php';
require_once dirname(__DIR__) . '/campaigns/write.php';
require_once dirname(__DIR__) . '/lists/write.php';

/** A value read for a field: [column, value|null] (null = cleared). A DomainException says why in words. */
function bulk_field_value(PDO $pdo, string $kind, string $field, ?string $value): array
{
    $spec = BULK_FIELDS[$kind][$field] ?? throw new DomainException('A ' . RECORD_KINDS[$kind]['noun'] . '\'s ' . str_replace('_', ' ', $field) . ' cannot be set in bulk. Choose one of: ' . implode(', ', array_keys(BULK_FIELDS[$kind])) . '.');
    [$col, $type, $arg, $nullable] = $spec;
    $word = str_replace('_', ' ', $field);
    $v = $value === null ? '' : trim($value);
    if ($v === '') {
        if (!$nullable) { throw new DomainException('Say the new value for ' . $word . '.'); }
        return [$col, null];
    }
    switch ($type) {
        case 'ref':
            [$view, $idcol] = explode(':', $arg);
            if (!ctype_digit($v) || one_value($pdo, "SELECT 1 FROM $view WHERE $idcol = :id", ['id' => (int) $v]) === null) { throw new DomainException('That ' . $word . ' is not here.'); }
            return [$col, (int) $v];
        case 'country':
            if (!preg_match('/^[A-Za-z]{2}$/', $v)) { throw new DomainException('The country is a two-letter code like US or DE.'); }
            return [$col, strtoupper($v)];
        case 'enum':
            if (!in_array($v, explode('|', $arg), true)) { throw new DomainException(ucfirst($word) . ' is one of: ' . implode(', ', explode('|', $arg)) . '.'); }
            return [$col, $v];
        case 'text':
            if (mb_strlen($v) > (int) $arg) { throw new DomainException(ucfirst($word) . ' is up to ' . (int) $arg . ' characters.'); }
            return [$col, $v];
        case 'date':
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { throw new DomainException('The date is year-month-day, like 2026-11-30.'); }
            return [$col, $v];
        case 'band':
            if (!in_array($v, EMPLOYEE_BANDS, true)) { throw new DomainException('The size is one of: ' . implode(', ', EMPLOYEE_BANDS) . '.'); }
            return [$col, $v];
    }
    throw new DomainException('That field cannot be set in bulk.');
}

/** Give records an owner: leads and deals through their own writers, accounts and contacts directly. Answers the ids whose owner changed. */
function bulk_assign(PDO $pdo, string $kind, array $ids, int $owner): array
{
    if ($kind === 'lead') { return lead_assign($pdo, $ids, $owner); }
    if ($kind === 'deal') {
        $prev = deal_assign($pdo, $ids, $owner);
        return array_keys(array_filter($prev, static fn ($before): bool => $before !== $owner));
    }
    $t = RECORD_KINDS[$kind]['table'];
    $st = $pdo->prepare("UPDATE $t SET owner_member_id = :o WHERE id = ANY (CAST(:ids AS bigint[])) AND owner_member_id IS DISTINCT FROM :o RETURNING id");
    $st->execute(['o' => $owner, 'ids' => pg_array_literal($ids)]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Put a tag on records (or take it off): answers [changed ids, the tag's label, whether a new tag was made]. Taking off a tag nobody made is an error in words. */
function bulk_tag(PDO $pdo, string $kind, array $ids, string $label, bool $remove): array
{
    if ($remove) {
        $row = one_row($pdo, 'SELECT id, label FROM tags WHERE slug = :s OR lower(label) = lower(:l) ORDER BY (slug = :s) DESC LIMIT 1', ['s' => tag_slug($label), 'l' => $label]);
        if ($row === null) { throw new DomainException('There is no tag called ' . $label . '.'); }
        $r = tag_remove($pdo, $kind, $ids, (int) $row['id']);
        return [$r['removed'], (string) $row['label'], false];
    }
    [$tid, $tlabel, $made] = tag_ensure($pdo, $label);
    $r = tag_apply($pdo, $kind, $ids, $tid);
    return [$r['applied'], $tlabel, $made];
}

/** Set one field (a column of the whitelist or custom:<key>) on records: answers the ids it changed. The value is read by bulk_field_value / the custom field's type. */
function bulk_update(PDO $pdo, string $kind, array $ids, string $field, ?string $value): array
{
    $t = RECORD_KINDS[$kind]['table'];
    $arr = pg_array_literal($ids);
    if (str_starts_with($field, 'custom:')) {
        $key = substr($field, 7);
        $defs = array_column(custom_field_defs($pdo, $kind), null, 'key');
        if (!isset($defs[$key])) { throw new DomainException('There is no custom field ' . $key . ' on a ' . RECORD_KINDS[$kind]['noun'] . '.'); }
        if ($value === null || trim($value) === '') {
            $st = $pdo->prepare("UPDATE $t SET custom_values = custom_values - :k WHERE id = ANY (CAST(:ids AS bigint[])) AND jsonb_exists(custom_values, :k) RETURNING id");
            $st->execute(['k' => $key, 'ids' => $arr]);
        } else {
            $saved = $_POST;
            $_POST = ['custom_values' => [$key => trim($value)]];                         // the one reader of a custom value's type: a form's strings → the JSON the definition names
            try { $typed = custom_values_from_request($pdo, $kind, []); } finally { $_POST = $saved; }
            $st = $pdo->prepare("UPDATE $t SET custom_values = custom_values || CAST(:j AS jsonb) WHERE id = ANY (CAST(:ids AS bigint[])) AND NOT (custom_values @> CAST(:j AS jsonb)) RETURNING id");
            $st->execute(['j' => json_encode($typed, JSON_THROW_ON_ERROR), 'ids' => $arr]);
        }
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    [$col, $v] = bulk_field_value($pdo, $kind, $field, $value);
    $st = $pdo->prepare("UPDATE $t SET $col = :v WHERE id = ANY (CAST(:ids AS bigint[])) AND $col IS DISTINCT FROM :v RETURNING id");
    $st->bindValue('v', $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
    $st->bindValue('ids', $arr);
    $st->execute();
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Records to the tombstone (crm_record_delete). An account that still has deals or contacts needs a manager (its people and deals would lose their company). Answers the ids. */
function bulk_delete(PDO $pdo, string $kind, array $ids): array
{
    if ($kind === 'account' && !is_manager()) {
        $st = $pdo->prepare('SELECT a.id, a.name FROM accounts a WHERE a.id = ANY (CAST(:ids AS bigint[])) AND (EXISTS (SELECT 1 FROM deals d WHERE d.account_id = a.id AND d.deleted_at IS NULL) OR EXISTS (SELECT 1 FROM contacts c WHERE c.account_id = a.id AND c.deleted_at IS NULL)) LIMIT 1');
        $st->execute(['ids' => pg_array_literal($ids)]);
        if (($bad = $st->fetch()) !== false) { throw new DomainException('The account ' . $bad['name'] . ' still has deals or contacts: a manager deletes it.'); }
    }
    foreach ($ids as $id) {
        record_delete($pdo, $kind, (int) $id);
    }
    return $ids;
}
