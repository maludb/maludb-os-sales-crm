<?php
declare(strict_types=1);

/**
 * The five K7 shares and the bridge's door (agents-k7-api.md "The bridge"; tool surface "The shares, for the kernel's token" and "The bridge"). A sibling reads
 * `accounts_index`, `contacts_index`, `deals_won`, `deals_open_by_account` and `account_timeline_summary` through the kernel; the records server (Phase 4) receives
 * the kernel's call with X-OS-Consumer (K26: the consumer's catalog key, which the kernel sets itself and from which it strips any `consumer` the consumer put in the
 * arguments) and reaches these functions through the loopback bridge (html/internal/bridge.php). THE SHARES READ AS THE APPLICATION — the db/017 `crm_share_*`
 * functions are SECURITY DEFINER and carry the walls that matter to a sibling: a restricted account, contact or deal is never in an answer, no email body and no note
 * ever crosses, no member identity but an owner's display name. A call without a consumer is refused (`consumer_required`) and nothing is read. Every call is logged
 * `share.read` ({application, tool, rows, ms} — and the account or window asked about, never a row).
 *
 * The bridge itself: loopback only, `X-CRM-Bridge: {unix ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "crm-bridge:" + ts + "." + sha256(raw body))}` within 30 seconds,
 * and a whitelist of ops — any other op is a 404. The key is the one the kernel's actions server already relays with; it never leaves the server.
 */

const BRIDGE_SKEW = 30;
const BRIDGE_OPS = ['k7_read', 'share_accounts_index', 'share_contacts_index', 'share_deals_won', 'share_deals_open_by_account', 'share_account_timeline_summary', 'notify'];
/** The K7 reads the bridge's `k7_read` op may make (the application's declared `reads[]` that an account page uses): [provider, tool]. */
const BRIDGE_K7_READS = ['account_tickets' => ['helpdesk', 'tickets_by_organization'], 'account_ledger' => ['gl', 'customer_by_crm_account']];

/** The refusal code (`not_loopback`, `bad_signature`) or null when the request may pass. Constant-time compare; loopback judged on REMOTE_ADDR alone (never a forwarded header). */
function bridge_verify(string $rawBody, string $header, string $remoteAddr, int $now): ?string
{
    if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
        return 'not_loopback';
    }
    if (!preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', trim($header), $m) || abs($now - (int) $m[1]) > BRIDGE_SKEW) {
        return 'bad_signature';
    }
    $key = (string) env('ACTIONS_RELAY_KEY', '');
    if (strlen($key) < 32) {
        return 'bad_signature';
    }
    return hash_equals(hash_hmac('sha256', 'crm-bridge:' . $m[1] . '.' . hash('sha256', $rawBody), $key), $m[2]) ? null : 'bad_signature';
}

/** The signature header for a body (the proofs and the Phase 4 server sign with the same recipe). */
function bridge_sign(string $rawBody, ?int $now = null): string
{
    $ts = (string) ($now ?? time());
    return $ts . '.' . hash_hmac('sha256', 'crm-bridge:' . $ts . '.' . hash('sha256', $rawBody), (string) env('ACTIONS_RELAY_KEY', ''));
}

/** A consumer's catalog key, or null: lower case letters, digits and underscore, 2 to 32 characters, starting with a letter. */
function share_consumer(mixed $v): ?string
{
    return is_string($v) && preg_match('/^[a-z][a-z0-9_]{1,31}$/', $v) ? $v : null;
}

/** A timestamp argument (ISO 8601 or a date) as UTC ISO, null when absent; an unreadable one is an InvalidArgumentException naming the argument. */
function share_time(array $args, string $name): ?string
{
    $v = $args[$name] ?? null;
    if ($v === null || $v === '') { return null; }
    try {
        return (new DateTimeImmutable((string) $v))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    } catch (Exception) {
        throw new InvalidArgumentException("$name is a time like 2026-10-01T00:00:00Z.");
    }
}

function share_date(array $args, string $name, bool $required): ?string
{
    $v = $args[$name] ?? null;
    if ($v === null || $v === '') {
        if ($required) { throw new InvalidArgumentException("$name is required (a date like 2026-10-01)."); }
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
    if ($d === false || $d->format('Y-m-d') !== (string) $v) { throw new InvalidArgumentException("$name is a date like 2026-10-01."); }
    return (string) $v;
}

function share_int(array $args, string $name, int $default, int $min, int $max): int
{
    $v = $args[$name] ?? null;
    if ($v === null || $v === '') { return $default; }
    if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < $min || (int) $v > $max) { throw new InvalidArgumentException("$name is a whole number from $min to $max."); }
    return (int) $v;
}

function share_id(array $args, string $name): int
{
    $v = $args[$name] ?? null;
    if ($v === null || filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) { throw new InvalidArgumentException("$name is a record id."); }
    return (int) $v;
}

/** share.read: the application that asked, the tool, how many rows, how long — and what it asked about (an account id, a window), never an answer. */
function share_log(PDO $pdo, string $consumer, string $tool, int $rows, float $t0, array $about = []): void
{
    $agent = $GLOBALS['__share_consumer_agent'] ?? null;                                  // X-OS-Consumer-Agent (K26): the agent of the consuming application that asked, when one did
    log_activity($pdo, 'share.read', 'share', null, ['actor_member_id' => null, 'source' => 'application',
        'after' => ['application' => $consumer, 'tool' => $tool, 'rows' => $rows, 'ms' => (int) round((microtime(true) - $t0) * 1000)] + ($agent === null ? [] : ['consumer_agent' => $agent]) + $about]);
}

function share_num(mixed $v): ?float { return $v === null ? null : (float) $v; }
function share_int_or_null(mixed $v): ?int { return $v === null ? null : (int) $v; }

/** `accounts_index` — os.sales-crm-accounts/1. Args: since, q, limit (≤ 500), offset. */
function share_accounts_index(string $consumer, array $args): array
{
    $t0 = microtime(true);
    if (share_consumer($consumer) === null) { throw new InvalidArgumentException('consumer_required'); }
    $since = share_time($args, 'since');
    $q = isset($args['q']) && $args['q'] !== '' ? mb_substr((string) $args['q'], 0, 100) : null;
    $st = db()->prepare('SELECT * FROM crm_share_accounts_index(:c, CAST(:s AS timestamptz), :q, :l, :o)');
    $st->execute(['c' => $consumer, 's' => $since, 'q' => $q, 'l' => share_int($args, 'limit', 200, 1, 500), 'o' => share_int($args, 'offset', 0, 0, 10000000)]);
    $rows = $st->fetchAll();
    $doc = ['schema' => 'os.sales-crm-accounts/1', 'application' => app_key(), 'accounts' => array_map(static fn (array $r): array => [
        'account_id' => (int) $r['account_id'], 'name' => $r['name'], 'legal_name' => $r['legal_name'], 'domain' => $r['domain'], 'kind' => $r['kind'], 'owner_member_id' => share_int_or_null($r['owner_member_id']),
        'owner_name' => $r['owner_name'], 'gl_customer_id' => share_int_or_null($r['gl_customer_id']), 'helpdesk_organization_id' => share_int_or_null($r['helpdesk_organization_id']), 'country' => $r['country'], 'city' => $r['city'],
        'open_deals_count' => (int) $r['open_deals_count'], 'open_deals_value' => share_num($r['open_deals_value']), 'won_deals_value' => share_num($r['won_deals_value']), 'currency' => $r['currency'], 'updated_at' => json_ts($r['updated_at'])], $rows),
        'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
    share_log(db(), $consumer, 'accounts_index', count($rows), $t0, $since === null ? [] : ['since' => $since]);
    return $doc;
}

/** `contacts_index` — os.sales-crm-contacts/1. Args: account, email, since, limit (≤ 500), offset. */
function share_contacts_index(string $consumer, array $args): array
{
    $t0 = microtime(true);
    if (share_consumer($consumer) === null) { throw new InvalidArgumentException('consumer_required'); }
    $account = isset($args['account']) && $args['account'] !== '' ? share_id($args, 'account') : null;
    $email = isset($args['email']) && $args['email'] !== '' ? mb_substr(strtolower(trim((string) $args['email'])), 0, 254) : null;
    $st = db()->prepare('SELECT * FROM crm_share_contacts_index(:c, :a, :e, CAST(:s AS timestamptz), :l, :o)');
    $st->execute(['c' => $consumer, 'a' => $account, 'e' => $email, 's' => share_time($args, 'since'), 'l' => share_int($args, 'limit', 200, 1, 500), 'o' => share_int($args, 'offset', 0, 0, 10000000)]);
    $rows = $st->fetchAll();
    $doc = ['schema' => 'os.sales-crm-contacts/1', 'application' => app_key(), 'contacts' => array_map(static fn (array $r): array => [
        'contact_id' => (int) $r['contact_id'], 'account_id' => share_int_or_null($r['account_id']), 'account_name' => $r['account_name'], 'name' => $r['name'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
        'title' => $r['title'], 'role' => $r['role'], 'emails' => pg_text_array((string) $r['emails']), 'phone' => $r['phone'], 'mobile' => $r['mobile'], 'lifecycle' => $r['lifecycle'],
        'email_opt_out' => (bool) $r['email_opt_out'], 'sms_opt_out' => (bool) $r['sms_opt_out'], 'owner_member_id' => share_int_or_null($r['owner_member_id']), 'updated_at' => json_ts($r['updated_at'])], $rows),
        'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
    share_log(db(), $consumer, 'contacts_index', count($rows), $t0, $account === null ? [] : ['account_id' => $account]);
    return $doc;
}

/** `deals_won` — os.sales-crm-deals-won/1. Args: from, to (dates, required), limit (≤ 500), offset. */
function share_deals_won(string $consumer, array $args): array
{
    $t0 = microtime(true);
    if (share_consumer($consumer) === null) { throw new InvalidArgumentException('consumer_required'); }
    $from = share_date($args, 'from', true);
    $to = share_date($args, 'to', true);
    if ($from > $to) { throw new InvalidArgumentException('from is not after to.'); }
    $st = db()->prepare('SELECT * FROM crm_share_deals_won(:c, CAST(:f AS date), CAST(:t AS date), :l, :o)');
    $st->execute(['c' => $consumer, 'f' => $from, 't' => $to, 'l' => share_int($args, 'limit', 200, 1, 500), 'o' => share_int($args, 'offset', 0, 0, 10000000)]);
    $rows = $st->fetchAll();
    $doc = ['schema' => 'os.sales-crm-deals-won/1', 'application' => app_key(), 'deals' => array_map(static fn (array $r): array => [
        'deal_id' => (int) $r['deal_id'], 'number' => $r['number'], 'title' => $r['title'],
        'account' => $r['account_id'] === null ? null : ['account_id' => (int) $r['account_id'], 'name' => $r['account_name'], 'legal_name' => $r['account_legal_name'], 'gl_customer_id' => share_int_or_null($r['gl_customer_id'])],
        'primary_contact' => $r['primary_contact_id'] === null ? null : ['contact_id' => (int) $r['primary_contact_id'], 'name' => $r['primary_contact_name'], 'email' => $r['primary_contact_email']],
        'value' => share_num($r['value']), 'currency' => $r['currency'], 'won_at' => json_ts($r['won_at']), 'owner_member_id' => share_int_or_null($r['owner_member_id']), 'owner_name' => $r['owner_name'],
        'pipeline_key' => $r['pipeline_key'], 'products' => json_decode((string) $r['products'], true) ?: []], $rows),
        'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
    share_log(db(), $consumer, 'deals_won', count($rows), $t0, ['from' => $from, 'to' => $to]);
    return $doc;
}

/** `deals_open_by_account` — os.sales-crm-deals-open/1. Args: account. An account that is restricted or missing answers an empty list, never an error that tells the two apart. */
function share_deals_open_by_account(string $consumer, array $args): array
{
    $t0 = microtime(true);
    if (share_consumer($consumer) === null) { throw new InvalidArgumentException('consumer_required'); }
    $account = share_id($args, 'account');
    $st = db()->prepare('SELECT * FROM crm_share_deals_open_by_account(:c, :a)');
    $st->execute(['c' => $consumer, 'a' => $account]);
    $rows = $st->fetchAll();
    $doc = ['schema' => 'os.sales-crm-deals-open/1', 'application' => app_key(), 'account_id' => $account, 'deals' => array_map(static fn (array $r): array => [
        'deal_id' => (int) $r['deal_id'], 'number' => $r['number'], 'title' => $r['title'], 'stage_name' => $r['stage_name'], 'probability' => share_int_or_null($r['probability']), 'value' => share_num($r['value']),
        'weighted_value' => share_num($r['weighted_value']), 'currency' => $r['currency'], 'expected_close_date' => $r['expected_close_date'], 'owner_member_id' => share_int_or_null($r['owner_member_id']),
        'owner_name' => $r['owner_name'], 'next_activity_at' => json_ts($r['next_activity_at']), 'next_colour' => $r['next_colour'], 'stage_entered_at' => json_ts($r['stage_entered_at'])], $rows)];
    share_log(db(), $consumer, 'deals_open_by_account', count($rows), $t0, ['account_id' => $account]);
    return $doc;
}

/** `account_timeline_summary` — os.sales-crm-timeline/1. Args: account, limit (≤ 50). Dates and subjects only. */
function share_account_timeline_summary(string $consumer, array $args): array
{
    $t0 = microtime(true);
    if (share_consumer($consumer) === null) { throw new InvalidArgumentException('consumer_required'); }
    $account = share_id($args, 'account');
    $st = db()->prepare('SELECT * FROM crm_share_account_timeline_summary(:c, :a, :l)');
    $st->execute(['c' => $consumer, 'a' => $account, 'l' => share_int($args, 'limit', 10, 1, 50)]);
    $touches = [];
    $next = null;
    foreach ($st->fetchAll() as $r) {
        $row = ['at' => json_ts($r['at']), 'kind' => $r['kind'], 'subject' => $r['subject'], 'who' => $r['who'], 'direction' => $r['direction'], 'deal_number' => $r['deal_number']];
        if ((bool) $r['is_next_step']) { $next = $next ?? array_diff_key($row, ['direction' => 1]); } else { $touches[] = $row; }
    }
    $doc = ['schema' => 'os.sales-crm-timeline/1', 'application' => app_key(), 'account_id' => $account, 'touches' => $touches] + ($next === null ? [] : ['next_step' => $next]);
    share_log(db(), $consumer, 'account_timeline_summary', count($touches) + ($next === null ? 0 : 1), $t0, ['account_id' => $account]);
    return $doc;
}

/**
 * The bridge's `k7_read` op: the two reads the application consumes, as the member the records server vouches for (the run's or the token's person). The wall on the account is judged
 * first as that member (k7_account_read → mcp_accounts); a hidden or missing account is `not_found`, nothing is asked of the kernel; `no_connection` and `unavailable` are answers, not errors.
 * $args: read (account_tickets | account_ledger), account (id), limit? (tickets, ≤ 25).
 */
function bridge_k7_read(PDO $pdo, array $args): array
{
    $read = BRIDGE_K7_READS[(string) ($args['read'] ?? '')] ?? null;
    if ($read === null) { throw new InvalidArgumentException('read is one of ' . implode(', ', array_keys(BRIDGE_K7_READS)) . '.'); }
    $account = share_id($args, 'account');
    $extra = [];
    if ($read[1] === 'tickets_by_organization') {
        $extra = ['domain' => (string) (one_value($pdo, 'SELECT domain::text FROM mcp_accounts WHERE account_id = :a', ['a' => $account]) ?? '')];
    }
    $r = k7_account_read($pdo, $read[0], $read[1], $account, $extra);
    if ($r['status'] === 'not_found') { return ['status' => 'not_found', 'rows' => []]; }
    $rows = $r['rows'];
    if ($read[1] === 'tickets_by_organization') { $rows = array_slice($rows, 0, share_int($args, 'limit', 25, 1, 25)); }
    return ['status' => $r['status'], 'rows' => $rows];
}

/** The admitted member the body names — {"kind": "member", "member_id": n}: a member, active, holding a grant here — or null. (The caller the records server vouches for after its own gate; never the body's word alone: loopback + HMAC come first.) */
function bridge_caller(PDO $pdo, array $body): ?int
{
    $c = $body['caller'] ?? null;
    if (!is_array($c) || ($c['kind'] ?? '') !== 'member' || !is_int($c['member_id'] ?? null) || $c['member_id'] < 1) { return null; }
    return one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL", ['m' => $c['member_id']]) === null ? null : (int) $c['member_id'];
}
