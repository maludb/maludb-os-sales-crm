<?php
declare(strict_types=1);

/**
 * settings_save (log settings.save: the fields that changed, before and after): the application's one settings row (crm_settings, db/005). SETTINGS_FIELDS is the whole catalogue — each setting's group, kind, bounds and
 * words — so the form, the reader of the request and the JSON all say the same thing. Every value is checked with its field NAMED; the schema's CHECKs are the last word (crm_guard turns one into a 422). A field left out
 * of the request stays as it was. The default pipeline is two facts — pipelines.is_default and crm_settings.default_pipeline_id — and moves as one.
 * Not here: the Sales Assistant's morning note and proposals (slice 8's agents screen), the key rotation overlap, the attachment limits and the storage directory (the install's).
 */
require_once dirname(__DIR__) . '/admin/queries.php';

const SETTINGS_GROUPS = ['business' => 'The business', 'pipeline' => 'The pipeline', 'duplicates' => 'Duplicates', 'reminders' => 'Reminders and the next step',
    'imports' => 'Imports and bulk changes', 'api' => 'The API', 'records' => 'Records'];
const WEIGHTED_METHODS = ['stage' => 'By the stage\'s probability', 'override_or_stage' => 'By the deal\'s own probability when it has one, else the stage\'s'];
const DUPLICATE_POLICIES = ['off' => 'Do not look', 'warn' => 'Warn, and let it through', 'block_exact_email' => 'Refuse a second contact with the same email'];
const MONTH_NAMES = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
/** name => [group, kind, label, column (when it differs), options]. kind: text(max)|currency|tz|month|pipeline|int(min,max)|int?(min,max: blank = off)|choice|decimal(min,max)|bool|memo(max). */
const SETTINGS_FIELDS = [
    'business_name' => ['business', 'text:120', 'Business name', 'business_name', 'On the pages and in the emails the application sends.'],
    'currency' => ['business', 'currency', 'Currency', 'currency', 'The one base currency; a deal in another is counted apart, never converted.'],
    'fiscal_year_start_month' => ['business', 'month', 'The fiscal year starts in', 'fiscal_year_start_month', 'The forecast\'s quarters follow it.'],
    'timezone' => ['business', 'tz', 'Time zone', 'timezone', 'Where "today" is for the business when a person has not chosen one.'],
    'default_pipeline' => ['pipeline', 'pipeline', 'Default pipeline', 'default_pipeline_id', 'A new deal and a converted lead start here.'],
    'rotting_days' => ['pipeline', 'int?:1:365', 'A deal rots after (days in a stage)', 'rotting_days', 'Blank turns rotting off; a pipeline or a stage can set its own.'],
    'weighted_method' => ['pipeline', 'choice', 'The weighted value is', 'weighted_method', ''],
    'forecast_months' => ['pipeline', 'int:1:24', 'The forecast looks ahead (months)', 'forecast_months', ''],
    'duplicates_policy' => ['duplicates', 'choice', 'When a record looks like another', 'duplicates_policy', ''],
    'duplicates_name_similarity' => ['duplicates', 'decimal:0.30:1.00', 'How alike two names must be (0.30 to 1.00)', 'duplicates_name_similarity', 'Names and cities, by trigram; 1.00 is the same spelling.'],
    'default_remind_minutes' => ['reminders', 'int:0:10080', 'A new activity reminds this long before (minutes)', 'default_remind_minutes', '0 is at the time; 10080 is a week.'],
    'next_step_prompt' => ['reminders', 'bool', 'Ask for the next step when an activity is done', 'next_step_prompt', ''],
    'lead_untouched_days' => ['reminders', 'int:1:365', 'A lead untouched for this many days is named in the morning note', 'lead_untouched_days', ''],
    'account_untouched_days' => ['reminders', 'int:1:3650', 'An account untouched for this many days is named', 'account_untouched_days', ''],
    'import_max_rows' => ['imports', 'int:100:1000000', 'The most rows an import takes', 'import_max_rows', ''],
    'import_rollback_hours' => ['imports', 'int:1:168', 'An import can be rolled back for (hours)', 'import_rollback_hours', ''],
    'bulk_sync_rows' => ['imports', 'int:10:100000', 'A bulk change runs at once up to (rows)', 'bulk_sync_rows', 'Beyond it the worker does it and tells the person.'],
    'key_rate_per_minute' => ['api', 'int:1:100000', 'A key may call this often (a minute)', 'key_rate_per_minute', 'The default and the ceiling for a person\'s own key.'],
    'key_rate_per_day' => ['api', 'int:1:10000000', 'A key may call this often (a day)', 'key_rate_per_day', ''],
    'capture_rate_per_day' => ['api', 'int:1:10000000', 'A lead-capture key may post this many leads a day', 'capture_rate_per_day', 'The website\'s form.'],
    'tombstone_days' => ['records', 'int:1:3650', 'A deleted record waits (days) before it is purged', 'tombstone_days', 'Until then it can be restored.'],
    'allow_restricted_records' => ['records', 'bool', 'A manager may restrict a record to its owner and a department', 'allow_restricted_records', ''],
    'ics_days_ahead' => ['records', 'int:7:730', 'The calendar feed looks ahead (days)', 'ics_days_ahead', ''],
    'ics_days_back' => ['records', 'int:0:365', 'and back (days)', 'ics_days_back', ''],
    'email_footer' => ['records', 'memo:2000', 'Under every email the application sends', 'email_footer', 'Markdown. The unsubscribe link is always added.'],
];

/** The choices of a choice field. */
function settings_choices(string $name): array
{
    return match ($name) { 'weighted_method' => WEIGHTED_METHODS, 'duplicates_policy' => DUPLICATE_POLICIES, default => [] };
}

/**
 * The settings a request names, validated: [values keyed by FORM name, errors keyed by form name]. $now is settings() (a field left out stays as it is). Each error names its field in words.
 */
function settings_read_request(PDO $pdo, array $now): array
{
    $values = [];
    $errors = [];
    foreach (SETTINGS_FIELDS as $name => [$group, $kind, $label, $column]) {
        $keep = $now[$column] ?? null;
        $parts = explode(':', $kind);
        switch ($parts[0]) {
            case 'text':
            case 'memo':
                $v = crm_text($name, $keep === null ? null : (string) $keep, (int) $parts[1], $label, $errors);
                $values[$name] = $v;
                break;
            case 'currency':
                $v = crm_text($name, (string) $keep, 3, $label, $errors, true);
                if (req_has($name) && $v !== null && !preg_match('/^[A-Za-z]{3}$/', $v)) { $errors[$name] = $label . ' is a three-letter code like USD.'; }
                $values[$name] = $v === null ? $keep : strtoupper($v);
                break;
            case 'month':
                $values[$name] = crm_int($name, (int) $keep, 1, 12, $label, $errors);
                break;
            case 'tz':
                $v = crm_text($name, (string) $keep, 64, $label, $errors, true);
                if (req_has($name) && $v !== null && !in_array($v, timezone_identifiers_list(), true)) { $errors[$name] = $label . ' is a zone like America/Chicago or UTC.'; }
                $values[$name] = $v ?? $keep;
                break;
            case 'pipeline':
                $v = crm_ref($pdo, $name, $keep === null ? null : (int) $keep, 'SELECT 1 FROM pipelines WHERE id = :id AND archived_at IS NULL', 'live pipeline', $errors, false);
                $values[$name] = $v;
                break;
            case 'int':
            case 'int?':
                $values[$name] = crm_int($name, $keep === null ? null : (int) $keep, (int) $parts[1], (int) $parts[2], $label, $errors, $parts[0] === 'int?');
                break;
            case 'choice':
                $values[$name] = crm_choice($name, (string) $keep, array_keys(settings_choices($name)), $label, $errors, false);
                break;
            case 'decimal':
                $values[$name] = (string) $keep;
                if (req_has($name)) {
                    $t = str_replace(',', '.', (string) req_val($name));
                    if (!is_numeric($t) || (float) $t < (float) $parts[1] || (float) $t > (float) $parts[2]) { $errors[$name] = $label . ' is a number from ' . $parts[1] . ' to ' . $parts[2] . '.'; }
                    else { $values[$name] = number_format((float) $t, 2, '.', ''); }
                }
                break;
            case 'bool':
                $values[$name] = crm_yes($name, (bool) $keep);
                break;
        }
    }
    return [$values, $errors];
}

/** Save the changed settings. $values keyed by form name (settings_read_request). Answers ['before' => …, 'after' => …] keyed by form name — only what changed; the footer's text is not repeated in the log. */
function settings_save(PDO $pdo, array $values): array
{
    $now = settings($pdo);
    $before = [];
    $after = [];
    $set = [];
    $args = [];
    foreach (SETTINGS_FIELDS as $name => [$group, $kind, $label, $column]) {
        if (!array_key_exists($name, $values)) { continue; }
        $old = $now[$column] ?? null;
        $new = $values[$name];
        if (is_bool($old) || is_bool($new)) { $same = (bool) $old === (bool) $new; }
        elseif ($new === null || $old === null) { $same = $old === $new; }
        elseif (is_numeric($old) && is_numeric($new)) { $same = (float) $old === (float) $new; }
        else { $same = (string) $old === (string) $new; }
        if ($same) { continue; }
        $before[$name] = $old;
        $after[$name] = $new;
        $set[] = "$column = " . (str_starts_with($kind, 'bool') ? "CAST(:$name AS boolean)" : ($kind === 'decimal:0.30:1.00' ? "CAST(:$name AS numeric)" : ":$name"));
        $args[$name] = is_bool($new) ? ($new ? 't' : 'f') : $new;
    }
    if (isset($after['default_pipeline'])) {
        $pdo->prepare('UPDATE pipelines SET is_default = false WHERE is_default AND id <> :id')->execute(['id' => $after['default_pipeline']]);
        $pdo->prepare('UPDATE pipelines SET is_default = true WHERE id = :id')->execute(['id' => $after['default_pipeline']]);
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE crm_settings SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($args);
    }
    if (array_key_exists('email_footer', $after)) {
        $before['email_footer'] = '(changed)';
        $after['email_footer'] = '(changed)';
    }
    return ['before' => $before, 'after' => $after];
}
