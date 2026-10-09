<?php
declare(strict_types=1);
/**
 * Actions `contact_create` (no `contact`; log contact.create — contacts.write) and `contact_update` (`contact` + any field of contact_create; log
 * contact.update — the edit rule). A name or an email is required. Without an account, a new contact is placed at the account whose domain their
 * address carries (never a free-mail domain). The database mirrors every address into contact_emails, makes the first contact of an account its
 * primary, and — when the business blocks duplicates — refuses an address another contact holds (named on the `email` field, pointing at them).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/contacts/write.php';
require_once dirname(__DIR__, 2) . '/app/features/custom_fields/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/tags/write.php';
crm_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('contact') ?? request_integer('id');
if ($id === null) {
    require_right('contacts.write');
    $cur = array_merge(array_fill_keys(CONTACT_COLUMNS, null), ['custom_values' => [], 'is_primary' => false, 'do_not_call' => false]);
} else {
    $row = require_record($pdo, 'contact', $id);
    require_editable($pdo, 'contact', $row);
    $cur = contact_current($pdo, $id);
}
$errors = [];
$f = [];
foreach (['first_name' => [100, 'The first name'], 'last_name' => [100, 'The last name'], 'title' => [150, 'The title'], 'department_text' => [120, 'The department'],
          'phone' => [40, 'The phone'], 'mobile' => [40, 'The mobile'], 'linkedin_url' => [300, 'The LinkedIn address'], 'address' => [500, 'The address'],
          'city' => [120, 'The city'], 'region' => [120, 'The region'], 'timezone' => [64, 'The time zone']] as $k => [$max, $label]) {
    $f[$k] = crm_text($k, $cur[$k], $max, $label, $errors);
}
$f['email'] = crm_email('email', $cur['email'], 'The email', $errors);
$f['email_alt'] = crm_email('email_alt', $cur['email_alt'], 'The other email', $errors);
if ($f['linkedin_url'] !== null && req_has('linkedin_url') && !preg_match('#^https?://#i', $f['linkedin_url'])) { $errors['linkedin_url'] = 'The LinkedIn address starts with https://.'; }
if ($f['timezone'] !== null && req_has('timezone') && !in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) { $errors['timezone'] = 'The time zone is a name like America/Chicago.'; }
$country = crm_text('country', $cur['country'], 2, 'The country', $errors);
if ($country !== null && !preg_match('/^[A-Za-z]{2}$/', $country)) { $errors['country'] = 'The country is a two-letter code like US or DE.'; }
$f['country'] = $country === null ? null : strtoupper($country);
$f['role'] = crm_choice('role', $cur['role'], array_keys(CONTACT_ROLES), 'The role', $errors);
$f['preferred_channel'] = crm_choice('preferred_channel', $cur['preferred_channel'], array_keys(CONTACT_CHANNELS), 'The preferred channel', $errors);
if (req_has('birthday')) {
    $b = (string) req_val('birthday');
    $d = $b === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $b);
    if ($b !== '' && ($d === false || $d->format('Y-m-d') !== $b || $d > new DateTimeImmutable('today'))) { $errors['birthday'] = 'The birthday is a date in the past (YYYY-MM-DD).'; }
    $f['birthday'] = $b === '' ? null : $b;
} else {
    $f['birthday'] = $cur['birthday'];
}
$f['account_id'] = crm_ref($pdo, 'account', $cur['account_id'], 'SELECT 1 FROM mcp_accounts WHERE account_id = :id', 'account', $errors);
$placed = false;
if ($id === null && !req_has('account') && $f['email'] !== null && ($acc = account_by_email_domain($pdo, $f['email'])) !== null) {
    $f['account_id'] = $acc;
    $placed = true;
}
$f['is_primary'] = crm_yes('is_primary', $cur['is_primary']);
$f['do_not_call'] = crm_yes('do_not_call', $cur['do_not_call']);
if ($f['is_primary'] && $f['account_id'] === null) { $errors['is_primary'] = 'Only a contact at an account can be its primary contact.'; }
$f['owner_member_id'] = crm_ref($pdo, 'owner', $id === null ? $me : $cur['owner_member_id'], 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'owner', $errors);
$f['source_id'] = crm_ref($pdo, 'source', $cur['source_id'], 'SELECT 1 FROM mcp_lead_sources WHERE source_id = :id', 'source', $errors);
$custom = custom_values_from_request($pdo, 'contact', $cur['custom_values']);
$f['custom_values'] = $custom ?? $cur['custom_values'];
if ($f['first_name'] === null && $f['last_name'] === null && $f['email'] === null && !isset($errors['email'])) {
    $errors[req_has('email') ? 'email' : 'first_name'] = 'A contact needs a name or an email address.';
}
$tags = $id === null ? (request_list('tags') ?? []) : [];
if ($tags !== [] && !has_right('tags.apply')) { $errors['tags'] = 'You may not tag this.'; }
if ($errors !== []) {
    crm_refuse_fields($errors);
}
$label = static fn (array $x): string => trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? '')) ?: (string) $x['email'];
$emailTaken = static function (PDOException $e) use ($pdo): never {
    $other = preg_match('/Another contact \((\d+)\)/', $e->getMessage(), $m) ? (int) $m[1] : null;
    $name = $other === null ? null : contact_label($pdo, $other);
    emit_action_status(false, ['errors' => ['That address belongs to another contact.'], 'fields' => ['email' => 'That address belongs to another contact' . ($name ? ' (' . $name . ')' : '') . '.'], 'record_id' => $name ? $other : null]);
    crm_refuse_fields(['email' => 'That address belongs to another contact' . ($name ? ' (' . $name . ')' : '') . '.']);
};
if ($id === null) {
    $f = array_filter($f, static fn ($v) => $v !== null);
    $newId = crm_guard($pdo, static function () use ($pdo, $f, $me, $tags, $placed, $emailTaken): int {
        $pdo->beginTransaction();
        try {
            $newId = contact_save($pdo, null, $f, $me);
            $labels = [];
            foreach ($tags as $t) { [$tid, $tl] = tag_ensure($pdo, $t); tag_apply($pdo, 'contact', [$newId], $tid); $labels[] = $tl; }
            $saved = contact_current($pdo, $newId);
            log_activity($pdo, 'contact.create', 'contact', $newId, ['account_id' => $saved['account_id'], 'after' => ['name' => trim(($saved['first_name'] ?? '') . ' ' . ($saved['last_name'] ?? '')), 'email' => $saved['email'],
                'account_id' => $saved['account_id'], 'placed_by_domain' => $placed, 'is_primary' => $saved['is_primary'], 'owner_member_id' => $saved['owner_member_id'], 'tags' => $labels]]);
            $pdo->commit();
            return $newId;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505' && str_contains($e->getMessage(), 'Another contact')) {
                $emailTaken($e);
            }
            throw $e;
        }
    });
    crm_done('Added the contact ' . $label($f), $newId, crm_land('/contacts/' . $newId, 'created'), 'contactChanged', ['account_id' => $f['account_id'] ?? null]);
}
$diff = crm_diff($cur, $f);
if ($diff['after'] !== []) {
    crm_guard($pdo, static function () use ($pdo, $id, $diff, $f, $cur, $emailTaken): void {
        $pdo->beginTransaction();
        try {
            contact_save($pdo, $id, $diff['after'], (int) current_member_id());
            $b = $diff['before'];
            $a = $diff['after'];
            if (array_key_exists('custom_values', $a)) { $b['custom_values'] = '(changed)'; $a['custom_values'] = array_keys($f['custom_values']); }
            if (array_key_exists('address', $a)) { $b['address'] = '(changed)'; $a['address'] = '(changed)'; }
            log_activity($pdo, 'contact.update', 'contact', $id, ['account_id' => $f['account_id'] ?? $cur['account_id'], 'before' => $b, 'after' => $a]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() === '23505' && str_contains($e->getMessage(), 'Another contact')) {
                $emailTaken($e);
            }
            throw $e;
        }
    });
}
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the contact ' . $label($f + $cur), $id, crm_land(return_path('/contacts/' . $id), 'updated'), 'contactChanged');
