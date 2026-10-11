<?php
declare(strict_types=1);

/**
 * Merge fields and the Markdown of an email's body (email.md "merge.php"). A template — and a person typing — may use {{contact.first_name}} … {{sender.signature}}:
 * merge_context() gathers the values for a contact, a deal, an account, a meeting and the sender (all through the walled views, so a field never carries what
 * the sender may not see); merge_render() fills the ones that have a value and LEAVES THE REST AS TYPED, naming them — an unknown field and a known one with
 * nothing to fill it (no deal on this email) — so the preview marks them and the send refuses them. md_render() turns the Markdown body into the HTML part:
 * a whitelist renderer, no library — paragraphs, headings, bold, italic, code, lists, quotes, links (http, https, mailto), everything else escaped text.
 */

require_once __DIR__ . '/../settings/queries.php';          // my_signature()

/** The merge fields and what each is, in the order the template form lists them. */
const MERGE_FIELDS = [
    'contact.first_name' => 'the contact\'s first name',
    'contact.name'       => 'the contact\'s full name',
    'account.name'       => 'the company',
    'deal.title'         => 'the deal',
    'deal.value'         => 'the deal\'s value',
    'activity.subject'   => 'the meeting\'s subject',
    'activity.date'      => 'the meeting\'s day',
    'activity.time'      => 'the meeting\'s time',
    'sender.name'        => 'your name',
    'sender.company'     => 'your company',
    'sender.signature'   => 'your signature',
];

/** A merge field written in a text: {{ name.field }}. */
const MERGE_PATTERN = '/\{\{\s*([A-Za-z_]+\.[A-Za-z_]+)\s*\}\}/';

/** The fields a text uses, in order of appearance, once each (written as the template wrote them, lower-cased). */
function merge_fields_in(string ...$texts): array
{
    $found = [];
    foreach ($texts as $t) {
        if (preg_match_all(MERGE_PATTERN, $t, $m)) {
            foreach ($m[1] as $f) { $found[strtolower($f)] = true; }
        }
    }
    return array_keys($found);
}

/**
 * The values of the known fields for this email: [field => string|null] (null = nothing to fill it with). $contact, $deal, $account, $activity are ids the
 * caller already judged visible; the deal brings its account and primary contact when those are not given. $sender is the member sending.
 */
function merge_context(PDO $pdo, ?int $contact, ?int $deal, ?int $account, ?int $activity, int $sender): array
{
    $d = $deal === null ? null : one_row($pdo, 'SELECT deal_id, title, value, currency, account_id, primary_contact_id FROM mcp_deals WHERE deal_id = :d', ['d' => $deal]);
    $contact ??= $d['primary_contact_id'] ?? null;
    $c = $contact === null ? null : one_row($pdo, 'SELECT contact_id, first_name, name, account_id FROM mcp_contacts WHERE contact_id = :c', ['c' => (int) $contact]);
    $account ??= $d['account_id'] ?? ($c['account_id'] ?? null);
    $a = $account === null ? null : one_row($pdo, 'SELECT name FROM mcp_accounts WHERE account_id = :a', ['a' => (int) $account]);
    $act = $activity === null ? null : one_row($pdo, 'SELECT subject, COALESCE(starts_at, due_at) AS at, timezone FROM mcp_activities WHERE activity_id = :a', ['a' => $activity]);
    $tz = (string) (one_value($pdo, 'SELECT timezone FROM members WHERE id = :m', ['m' => $sender]) ?: 'UTC');
    $when = $act === null || $act['at'] === null ? null : (string) $act['at'];
    $ctz = (string) ($act['timezone'] ?? '') !== '' ? (string) $act['timezone'] : $tz;
    $fn = $c === null ? null : (string) ($c['first_name'] ?? '');
    return [
        'contact.first_name' => $fn !== null && $fn !== '' ? $fn : ($c !== null && (string) $c['name'] !== '' ? (string) $c['name'] : null),
        'contact.name'       => $c === null || (string) $c['name'] === '' ? null : (string) $c['name'],
        'account.name'       => $a === null ? null : (string) $a['name'],
        'deal.title'         => $d === null ? null : (string) $d['title'],
        'deal.value'         => $d === null || $d['value'] === null ? null : money($d['value'], (string) $d['currency']),
        'activity.subject'   => $act === null ? null : (string) $act['subject'],
        'activity.date'      => $when === null ? null : format_ts($when, $ctz, 'l, F j'),
        'activity.time'      => $when === null ? null : format_ts($when, $ctz, 'g:i A T'),
        'sender.name'        => ($n = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $sender])) === '' ? null : $n,
        'sender.company'     => business_name($pdo),
        'sender.signature'   => trim(my_signature($pdo, $sender)),                       // none written: nothing under the email, not a field left standing
    ];
}

/**
 * Fill $text. Answers ['text' => the text, 'unknown' => fields that are none of ours, 'empty' => known fields with nothing to fill them — both left as typed,
 * 'filled' => field => value used]. A value is inserted as text; the Markdown renderer escapes it.
 */
function merge_render(string $text, array $ctx): array
{
    $unknown = [];
    $empty = [];
    $filled = [];
    $out = preg_replace_callback(MERGE_PATTERN, static function (array $m) use ($ctx, &$unknown, &$empty, &$filled): string {
        $f = strtolower($m[1]);
        if (!array_key_exists($f, MERGE_FIELDS)) { $unknown[$f] = true; return $m[0]; }
        $v = $ctx[$f] ?? null;
        if ($v === null) { $empty[$f] = true; return $m[0]; }
        if ($v !== '') { $filled[$f] = $v; }
        return $v;
    }, $text) ?? $text;
    return ['text' => $out, 'unknown' => array_keys($unknown), 'empty' => array_keys($empty), 'filled' => $filled];
}

/** The merge fields still standing in a text, written as typed: the send refuses an email that would go out with {{deal.title}} in it. */
function merge_leftovers(string ...$texts): array
{
    $left = [];
    foreach ($texts as $t) {
        if (preg_match_all(MERGE_PATTERN, $t, $m)) { foreach ($m[0] as $x) { $left[$x] = true; } }
    }
    return array_keys($left);
}

/** Markdown → HTML for the email's body: a whitelist renderer (see the file's note). Raw HTML in the source is escaped. */
function md_render(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $out = [];
    $i = 0;
    $n = count($lines);
    $para = [];
    $flush = static function () use (&$out, &$para): void {
        if ($para !== []) { $out[] = '<p>' . md_inline(implode("\n", $para)) . '</p>'; $para = []; }
    };
    while ($i < $n) {
        $t = rtrim($lines[$i]);
        if (preg_match('/^```/', $t)) {
            $flush();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', rtrim($lines[$i]))) { $code[] = $lines[$i]; $i++; }
            $i++;
            $out[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m)) {
            $flush();
            $h = strlen($m[1]) + 2;
            $out[] = '<h' . $h . '>' . md_inline($m[2]) . '</h' . $h . '>';
            $i++;
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) { $flush(); $out[] = '<hr>'; $i++; continue; }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $t, $m)) {
            $flush();
            $ordered = ctype_digit($m[2][0]);
            $items = [];
            while ($i < $n && preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', rtrim($lines[$i]), $mm)) { $items[] = '<li>' . md_inline($mm[1]) . '</li>'; $i++; }
            $out[] = ($ordered ? '<ol>' : '<ul>') . implode('', $items) . ($ordered ? '</ol>' : '</ul>');
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t)) {
            $flush();
            $q = [];
            while ($i < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $mm)) { $q[] = $mm[1]; $i++; }
            $out[] = '<blockquote>' . md_render(implode("\n", $q)) . '</blockquote>';
            continue;
        }
        if ($t === '') { $flush(); $i++; continue; }
        $para[] = $t;
        $i++;
    }
    $flush();
    return implode("\n", $out);
}

/** Inline marks over escaped text: code, bold, italic, links (http, https, mailto; never an image). */
function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $url = html_entity_decode($m[2], ENT_QUOTES);
        return preg_match('#^(https?://|mailto:)#i', $url) ? '<a href="' . e($url) . '">' . $m[1] . '</a>' : $m[0];
    }, $s) ?? $s;
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $s) ?? $s;
    return nl2br($s, false);
}
