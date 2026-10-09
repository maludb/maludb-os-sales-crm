<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/accounts-contacts.md "Proof"), on top of Phase 2's (tests/phase2/lib.php: sign_on, req, page, the
 * fake kernel, person and run tokens). Run through tests/phase3/slice1/run.sh: scratch database crm_dev1, the Phase 2 servers (:8801 the app).
 * Actors: 1 the admin, 26 Priya a Rep (Sales, dept 7), 27 Marco the Sales Manager (Sales), 28 Bea a Rep by capability (Accounting), 29 Ann a Viewer,
 * 30 Dana a Rep (Partnerships, dept 8), 40 the Expert (an agent, Sales Rep).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

/** A signed-on actor: [jar, csrf]. Cached per member for the process. */
function actor(int $m): array
{
    static $cache = [];
    if (!isset($cache[$m])) {
        [$j, ] = sign_on($m);
        $cache[$m] = [$j, page_csrf($j)];
    }
    return $cache[$m];
}
/** A JSON POST as a member (CSRF in the header): the response with the decoded body under 'json'. */
function post_as(int $m, string $path, array $form): array
{
    [$j, $t] = actor($m);
    $r = req('POST', $path, ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A JSON GET as a member. */
function get_as(int $m, string $path): array
{
    [$j, ] = actor($m);
    $r = req('GET', $path, ['jar' => $j, 'headers' => ['Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** An HTML page as a member. */
function html_as(int $m, string $path, array $headers = []): array
{
    [$j, ] = actor($m);
    return req('GET', $path, ['jar' => $j, 'headers' => $headers]);
}
/** A multipart upload as a member: $file = [path, mime, name]. */
function upload_as(int $m, string $path, array $fields, array $file): array
{
    [$j, $t] = actor($m);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $j, CURLOPT_COOKIEJAR => $j, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $t], CURLOPT_POSTFIELDS => $fields + ['file' => new CURLFile($file[0], $file[1], $file[2])]]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs), 'json' => json_decode(substr($raw, $hs), true) ?: []];
}
/** The field a 422 named: the first key of error.fields. */
function field_of(array $r): ?string { $f = $r['json']['error']['fields'] ?? []; return is_array($f) && $f !== [] ? (string) array_key_first($f) : null; }
function message_of(array $r): string { return (string) ($r['json']['error']['message'] ?? ''); }
/** A database setting changed for the proof (as postgres: the application's role may not write crm_settings outside the admin's handler). */
function setting(string $key, string $sqlValue): void
{
    exec('sudo -n -u postgres psql -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg("UPDATE crm_settings SET $key = $sqlValue"), $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "setting $key failed\n"); }
}
/** Rows as postgres (for facts the application's role may not read directly). */
function pg(string $sql): string
{
    return trim((string) shell_exec('sudo -n -u postgres psql -At -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1'));
}
/** A unique suffix per run, so the proofs can run twice on one database. */
function run_tag(): string { static $t = null; return $t ??= substr(bin2hex(random_bytes(3)), 0, 6); }
