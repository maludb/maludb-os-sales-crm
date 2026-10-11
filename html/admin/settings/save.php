<?php
declare(strict_types=1);
/**
 * Action `settings_save` (log settings.save {the fields that changed, before and after}; pauses for an agent as `other`; settings.manage): any of business_name, currency (three letters), fiscal_year_start_month (1 to 12), timezone,
 * default_pipeline, rotting_days (1 to 365 or blank for off), weighted_method (stage|override_or_stage), forecast_months (1 to 24), duplicates_policy (off|warn|block_exact_email), duplicates_name_similarity (0.3 to 1),
 * default_remind_minutes (0 to 10080), next_step_prompt (yes|no), lead_untouched_days, account_untouched_days, tombstone_days, import_max_rows, import_rollback_hours, bulk_sync_rows, key_rate_per_minute, key_rate_per_day,
 * capture_rate_per_day, allow_restricted_records (yes|no), ics_days_ahead, ics_days_back, email_footer (Markdown). A field left out stays as it was; every value is checked with its field named, and the schema's CHECKs are the last word.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/admin_write.php';
crm_handler_begin();
require_right('settings.manage');
$pdo = db();
[$values, $errors] = settings_read_request($pdo, settings($pdo));
if ($errors !== []) { crm_refuse_fields($errors); }
$diff = crm_guard($pdo, static function () use ($pdo, $values): array {
    $pdo->beginTransaction();
    $d = settings_save($pdo, $values);
    if ($d['after'] !== []) {
        log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the settings: ' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($diff['after']))), 1,
    crm_land('/admin/settings', $diff['after'] === [] ? 'nothing' : 'saved'), 'settingsChanged', ['changed' => array_keys($diff['after'])]);
