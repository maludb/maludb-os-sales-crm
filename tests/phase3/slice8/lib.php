<?php
/**
 * Helpers for the slice 8 proofs (agents-k7-api.md "Proof") — slice 6's (role_actor, pass, lead_make, worker_at, sends, contact_with) and a few of their own: a key's calls without any cookie (api), a key made in SQL
 * (capture keys are slice 9's admin — here they are fixtures), the bridge signed as the Phase 4 server signs it (bridge), the fake kernel's K7 and chat state.
 * Actors: 1 the admin, 26 Priya a Rep, 27 Marco the Sales Manager, 29 Ann a Viewer, 30 Dana a Rep (Partnerships), 40 the Expert (an agent, Rep), 42 the Assistant (an agent).
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** One call to the token API with a key and no cookie: code, headers, body, json. $body: array (JSON) | string (raw) | null. */
function api(?string $key, string $method, string $path, array|string|null $body = null, array $headers = []): array
{
    $h = array_merge(['Accept: application/json'], $key === null ? [] : ['Authorization: Bearer ' . $key], $headers);
    $o = ['headers' => $h];
    if (is_array($body)) { $o['json'] = $body; } elseif (is_string($body)) { $o['raw'] = $body; }
    $r = req($method, $path, $o);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A key minted through the action as a person: [id, raw]. */
function key_as(int $m, string $label, array $more = []): array
{
    $r = post_as($m, '/settings/tokens/key-mint.php', ['label' => $label] + $more);
    if (($r['json']['key'] ?? null) === null) { fwrite(STDERR, 'key_as failed: ' . $r['body'] . "\n"); }
    return [(int) ($r['json']['record_id'] ?? 0), (string) ($r['json']['key'] ?? '')];
}
/** A lead-capture key made in SQL as a fixture (the admin's screen for it is slice 9's): [id, raw]. */
function capture_key(int $member, int $source, string $label, ?int $perDay = null): array
{
    $raw = 'crm_' . bin2hex(random_bytes(24));
    $id = (int) one("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, lead_source_id, rate_per_day) VALUES (:m, :l, :h, 'api', :s, :d) RETURNING id",
        ['m' => $member, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $source, 'd' => $perDay]);
    return [$id, $raw];
}
/** A lead source by name (the seeded ones: Website, Outbound, …); its id. */
function source_id(string $name): int
{
    return (int) one('SELECT id FROM lead_sources WHERE name = :n', ['n' => $name]);
}
/** Where the bridge listens: the internal port under Apache (the public name answers 404 under /internal/), the one server under php -S. */
function bridge_base(): string { return getenv('CRM_APP') === 'apache' ? 'http://127.0.0.1:8807' : BASE; }
/** The bridge, signed as the Phase 4 server signs it. $o: ts (a different clock), sig (a replacement signature), headers[], consumer, args, caller. */
function bridge(string $op, array $body = [], array $o = []): array
{
    $raw = json_encode(['op' => $op] + $body, JSON_UNESCAPED_SLASHES);
    $ts = (string) ($o['ts'] ?? time());
    $sig = $o['sig'] ?? hash_hmac('sha256', 'crm-bridge:' . $ts . '.' . hash('sha256', $raw), need('ACTIONS_RELAY_KEY'));
    $h = array_merge(['Content-Type: application/json'], isset($o['unsigned']) ? [] : ['X-CRM-Bridge: ' . $ts . '.' . $sig], $o['headers'] ?? []);
    $r = req($o['method'] ?? 'POST', ($o['base'] ?? bridge_base()) . '/internal/bridge.php', ['headers' => $h, 'raw' => $raw]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** The fake kernel's K7 answers: set `reads` for a provider.tool (null removes it). */
function kernel_reads(array $set): void
{
    kernel_state(static function (array $s) use ($set): array { foreach ($set as $k => $v) { if ($v === null) { unset($s['reads'][$k]); } else { $s['reads'][$k] = $v; } } return $s; });
}
/** What the fake kernel was asked to read (its log), as arrays. */
function kernel_read_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.reads';
    return is_file($f) ? array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($f))))) : [];
}
/** What the fake kernel's chat endpoint was asked (its log), as arrays. */
function kernel_chat_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return is_file($f) ? array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($f))))) : [];
}
/** What the fake kernel was asked to text (its log), as arrays. */
function kernel_sms_log(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.sms';
    return is_file($f) ? array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($f))))) : [];
}
function has_header(array $r, string $name): bool { return (bool) preg_match('/^' . preg_quote($name, '/') . ':/mi', $r['headers']); }
function header_of(array $r, string $name): string { return preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $r['headers'], $m) ? trim($m[1]) : ''; }
/** Every activity_log row since an id, as one string (to prove something is NOT in the log). */
function log_text_since(int $since): string { return json_encode(q('SELECT action, before, after, route, request_id FROM activity_log WHERE id > :s', ['s' => $since])); }
/** A POST under an agent's run token (the kernel's actions server): the response with the decoded body. */
function agent_post(int $agent, int $run, string $path, array $form): array
{
    $r = req('POST', $path, ['headers' => as_agent(run_token($agent, $run)), 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** The proposals screen's JSON for a member (the `data`). */
function proposals_of(int $m, string $query = ''): array
{
    return get_as($m, '/proposals' . $query)['json']['data'] ?? [];
}
/** A proposal row by id. */
function proposal_row(int $id): array
{
    return q('SELECT * FROM assistant_proposals WHERE id = :i', ['i' => $id])[0] ?? [];
}
