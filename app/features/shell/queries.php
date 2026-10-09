<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bell's count (mcp_notifications), the lead inbox's
 * count (proposed leads waiting for a person — the Leads item's badge), the business name, the base currency. Each is cached
 * for the request — one query per render.
 */

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** The lead inbox: proposed leads (from the mailbox, the Assistant, a capture key routed to the inbox) the caller may see and nobody accepted yet. */
function inbox_count(PDO $pdo): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, "SELECT count(*) FROM mcp_leads WHERE is_proposed AND status = 'new' AND converted_at IS NULL");
}

/** The business's name on the header (crm_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The base currency (design (a): one base currency; amounts in another are counted apart, never converted). */
function base_currency(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT currency FROM mcp_settings') ?: 'USD');
}

/** An amount in words a seller reads: "$12,400" (whole units), "€980" — the symbol for the common currencies, else the ISO code. */
function money(float|int|string|null $amount, ?string $currency = null): string
{
    $currency = strtoupper($currency ?? base_currency(db()));
    $v = (float) ($amount ?? 0);
    $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF ', 'INR' => '₹'];
    $digits = abs($v) >= 100 || floor($v) == $v ? 0 : 2;
    $text = number_format(abs($v), $digits);
    return ($v < 0 ? '−' : '') . (isset($symbols[$currency]) ? $symbols[$currency] . $text : $text . ' ' . $currency);
}

/** A kind's icon (the six kinds of activities, db/010). */
function activity_kind_icon(string $kind): string
{
    return ['call' => 'feather-phone', 'email' => 'feather-mail', 'meeting' => 'feather-users', 'task' => 'feather-check-square', 'deadline' => 'feather-flag', 'lunch' => 'feather-coffee'][$kind] ?? 'feather-circle';
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}
