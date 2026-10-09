<?php
declare(strict_types=1);
/**
 * Actions `account_create` (no `account`; log account.create — accounts.write) and `account_update` (`account` + any field of account_create; log
 * account.update — the edit rule: the owner, an unowned account, a manager). A field left out stays as it was; an emptied one is cleared.
 * The database derives the domain from the website and refuses a second live account on it (named on the `domain` field), sets the kind by hand
 * only for partner, other and former, types the custom values. The parent may not be the account or below it.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('account') ?? request_integer('id');
$row = null;
if ($id === null) {
    require_right('accounts.write');
    $cur = array_merge(array_fill_keys(ACCOUNT_COLUMNS, null), ['custom_values' => []]);
} else {
    $row = require_record($pdo, 'account', $id);
    require_editable($pdo, 'account', $row);
    $cur = account_current($pdo, $id);
}
$errors = [];
$f = [];
$f['name'] = crm_text('name', $cur['name'], 200, 'The name', $errors, true);
if ($id === null && $f['name'] === null && !isset($errors['name'])) {
    $errors['name'] = 'The name is required.';
}
foreach (['legal_name' => [200, 'The legal name'], 'website' => [300, 'The website'], 'industry' => [120, 'The industry'], 'phone' => [40, 'The phone'],
          'billing_address' => [500, 'The billing address'], 'shipping_address' => [500, 'The shipping address'], 'city' => [120, 'The city'], 'region' => [120, 'The region'],
          'description' => [5000, 'The description']] as $k => [$max, $label]) {
    $f[$k] = crm_text($k, $cur[$k], $max, $label, $errors);
}
$f['email'] = crm_email('email', $cur['email'], 'The email', $errors);
// The domain: given → normalised; a new website and no domain → cleared so the trigger derives it from the website; else as it was.
$domain = crm_text('domain', $cur['domain'], 253, 'The domain', $errors);
if (req_has('domain')) {
    if ($domain !== null && domain_of(null, $domain) === null) {
        $errors['domain'] = 'The domain is a web address like acme.com.';
    }
    $f['domain'] = $domain === null ? null : domain_of(null, $domain);
} elseif (req_has('website') && $f['website'] !== $cur['website']) {
    $f['domain'] = null;
} else {
    $f['domain'] = $cur['domain'];
}
$country = crm_text('country', $cur['country'], 2, 'The country', $errors);
if ($country !== null && !preg_match('/^[A-Za-z]{2}$/', $country)) { $errors['country'] = 'The country is a two-letter code like US or DE.'; }
$f['country'] = $country === null ? null : strtoupper($country);
$currency = crm_text('currency', $cur['currency'], 3, 'The currency', $errors);
if ($currency !== null && !preg_match('/^[A-Za-z]{3}$/', $currency)) { $errors['currency'] = 'The currency is a three-letter code like USD.'; }
$f['currency'] = $currency === null ? null : strtoupper($currency);
$f['employees'] = crm_choice('employees', $cur['employees'], EMPLOYEE_BANDS, 'The size', $errors);
if (req_has('annual_revenue')) {
    $rev = str_replace([',', ' '], '', (string) req_val('annual_revenue'));
    if ($rev === '') { $f['annual_revenue'] = null; }
    elseif (!is_numeric($rev) || (float) $rev < 0) { $errors['annual_revenue'] = 'The annual revenue is an amount of 0 or more.'; }
    else { $f['annual_revenue'] = $rev; }
} else {
    $f['annual_revenue'] = $cur['annual_revenue'];
}
$kinds = $id === null ? ['prospect', 'partner', 'other'] : ['prospect', 'partner', 'other', 'former', (string) $cur['kind']];
$kind = crm_choice('kind', $cur['kind'] ?? 'prospect', array_values(array_unique($kinds)), 'The kind', $errors, false);
if ($kind === 'customer' && $kind !== $cur['kind']) { $errors['kind'] = 'An account becomes a customer when a deal is won.'; }
$f['kind'] = $kind ?? 'prospect';
$f['parent_account_id'] = crm_ref($pdo, 'parent_account', $cur['parent_account_id'], 'SELECT 1 FROM mcp_accounts WHERE account_id = :id', 'parent account', $errors);
if ($id !== null && $f['parent_account_id'] !== null && $f['parent_account_id'] !== $cur['parent_account_id']) {
    $cycle = (bool) one_value($pdo, 'WITH RECURSIVE up(id) AS (SELECT CAST(:p AS bigint) UNION SELECT a.parent_account_id FROM accounts a JOIN up ON a.id = up.id WHERE a.parent_account_id IS NOT NULL)
                                     SELECT EXISTS (SELECT 1 FROM up WHERE id = :me)', ['p' => $f['parent_account_id'], 'me' => $id]);
    if ($cycle) { $errors['parent_account'] = 'The parent cannot be the account itself or one of its own subsidiaries.'; }
}
$f['owner_member_id'] = crm_ref($pdo, 'owner', $id === null ? $me : $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
$f['source_id'] = crm_ref($pdo, 'source', $cur['source_id'], 'SELECT 1 FROM mcp_lead_sources WHERE source_id = :id', 'source', $errors);
$custom = custom_values_from_request($pdo, 'account', $cur['custom_values']);
$f['custom_values'] = $custom ?? $cur['custom_values'];
$tags = $id === null ? (request_list('tags') ?? []) : [];
if ($tags !== [] && !has_right('tags.apply')) { $errors['tags'] = 'You may not tag this.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$domainHolder = static function () use ($pdo, $f): string {
    $d = $f['domain'] ?? domain_of($f['website']);
    $n = $d === null ? null : one_value($pdo, 'SELECT name FROM mcp_accounts WHERE domain = :d', ['d' => $d]);
    return 'Another account' . ($n ? ' (' . $n . ')' : '') . ' already has the domain ' . ($d ?? 'of that website') . '.';
};
if ($id === null) {
    $f = array_filter($f, static fn ($v) => $v !== null);
    $newId = crm_guard($pdo, static function () use ($pdo, $f, $me, $tags, $domainHolder): int {
        $pdo->beginTransaction();
        try {
            $newId = account_save($pdo, null, $f, $me);
            $labels = [];
            foreach ($tags as $t) { [$tid, $label] = tag_ensure($pdo, $t); tag_apply($pdo, 'account', [$newId], $tid); $labels[] = $label; }
            $saved = account_current($pdo, $newId);
            log_activity($pdo, 'account.create', 'account', $newId, ['after' => ['name' => $saved['name'], 'domain' => $saved['domain'], 'kind' => $saved['kind'], 'owner_member_id' => $saved['owner_member_id'],
                'parent_account_id' => $saved['parent_account_id'], 'tags' => $labels, 'custom_keys' => array_keys($saved['custom_values'])]]);
            $pdo->commit();
            return $newId;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505' && str_contains($e->getMessage(), 'accounts_domain_uq')) {
                crm_refuse_fields(['domain' => $domainHolder()]);
            }
            throw $e;
        }
    });
    crm_done('Added the account ' . $f['name'], $newId, crm_land('/accounts/' . $newId, 'created'), 'accountChanged');
}
$diff = crm_diff($cur, $f);
if ($diff['after'] !== []) {
    crm_guard($pdo, static function () use ($pdo, $id, $diff, $domainHolder, $f): void {
        $pdo->beginTransaction();
        try {
            account_save($pdo, $id, $diff['after'], (int) current_member_id());
            $b = $diff['before'];
            $a = $diff['after'];
            $saved = account_current($pdo, $id);                              // what the database kept (a domain re-derived from the website)
            foreach (array_keys($a) as $k) { if (array_key_exists($k, $saved)) { $a[$k] = $saved[$k]; } }
            foreach (['description', 'custom_values'] as $k) {
                if (array_key_exists($k, $a)) { $b[$k] = '(changed)'; $a[$k] = $k === 'custom_values' ? array_keys($f['custom_values']) : '(changed)'; }
            }
            log_activity($pdo, 'account.update', 'account', $id, ['before' => $b, 'after' => $a + ['name' => $f['name']]]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505' && str_contains($e->getMessage(), 'accounts_domain_uq')) {
                crm_refuse_fields(['domain' => $domainHolder()]);
            }
            throw $e;
        }
    });
}
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the account ' . $f['name'], $id, crm_land(return_path('/accounts/' . $id), 'updated'), 'accountChanged');
