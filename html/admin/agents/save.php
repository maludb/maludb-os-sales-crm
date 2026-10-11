<?php
declare(strict_types=1);
/**
 * Action `agents_settings_save` (log `agents_settings.save`; pauses for an agent as `other`, then refuses it: people only): the Assistant's switches — morning_note_enabled (yes or no), morning_note_hour (0 to 23),
 * assistant_proposals (yes or no), lead_untouched_days (1 to 365), account_untouched_days (1 to 3650) and ask_assistant_stages[] (the open stages that wake the Assistant when a deal enters them; the whole list). A field
 * left out stays as it was.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/write.php';
crm_handler_begin();
require_human();
require_right('agents.settings');
$pdo = db();
$now = agent_settings($pdo);
$errors = [];
$f = [];
$f['morning_note_enabled'] = crm_yes('morning_note_enabled', $now['morning_note_enabled']);
$f['assistant_proposals'] = crm_yes('assistant_proposals', $now['assistant_proposals']);
$f['morning_note_hour'] = crm_int('morning_note_hour', $now['morning_note_hour'], 0, 23, 'The hour', $errors);
$f['lead_untouched_days'] = crm_int('lead_untouched_days', $now['lead_untouched_days'], 1, 365, 'Days a lead may sit', $errors);
$f['account_untouched_days'] = crm_int('account_untouched_days', $now['account_untouched_days'], 1, 3650, 'Days an account may sit', $errors);
$stages = null;
if (req_has('ask_assistant_stages') || req_has('ask_assistant_stages_present')) {
    $stages = [];
    $valid = array_map(static fn (array $s): int => $s['stage_id'], $now['stages']);
    foreach (request_list('ask_assistant_stages') ?? [] as $s) {
        if (!ctype_digit($s) || !in_array((int) $s, $valid, true)) { $errors['ask_assistant_stages'] = 'Choose stages from the list (open stages of a live pipeline).'; continue; }
        $stages[] = (int) $s;
    }
}
if ($errors !== []) { crm_refuse_fields($errors); }
$diff = crm_guard($pdo, static function () use ($pdo, $f, $stages): array {
    $pdo->beginTransaction();
    $d = agents_settings_save($pdo, $f, $stages);
    if ($d['after'] !== []) {
        log_activity($pdo, 'agents_settings.save', 'settings', null, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
crm_done($diff['after'] === [] ? 'Nothing to change' : 'Saved the agents\' settings', 1, crm_land('/admin/agents', 'saved'), 'settingsChanged');
