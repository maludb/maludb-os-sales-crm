<?php
declare(strict_types=1);

/**
 * The admin's writes about agents (actions `dispatch_cancel`, `agents_settings_save`). The settings are the Assistant's switches in crm_settings (the morning note and its hour, proposals on or off, how long a lead or an
 * account may sit untouched before the note names it) and the stages that wake it on entry (stages.ask_assistant — the trigger of db/013 writes the dispatch).
 */
require_once __DIR__ . '/queries.php';

/** Cancel a dispatch that has not been answered: refused, in the words "Cancelled by NAME". False when it was not waiting. (The status vocabulary of the reused table has no `cancelled`; a refusal in these words is the same fact.) */
function dispatch_cancel(PDO $pdo, int $id, int $by): bool
{
    $name = (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]) ?: 'an admin');
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'refused', detail = :d, answered_at = now() WHERE id = :id AND status IN ('sent', 'awaiting_approval') RETURNING id");
    $st->execute(['d' => mb_substr('Cancelled by ' . $name, 0, 200), 'id' => $id]);
    return $st->fetchColumn() !== false;
}

/**
 * Save the Assistant's settings: $f the crm_settings columns that changed, $askStages the stage ids that wake the Assistant (null = leave the stages alone). Answers ['before' => …, 'after' => …] of what changed
 * (columns, and `ask_assistant_stages` as the id lists).
 */
function agents_settings_save(PDO $pdo, array $f, ?array $askStages): array
{
    $cols = ['morning_note_enabled', 'morning_note_hour', 'assistant_proposals', 'lead_untouched_days', 'account_untouched_days'];
    $now = agent_settings($pdo);
    $before = [];
    $after = [];
    $set = [];
    $args = [];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $f) || $f[$c] === $now[$c]) { continue; }
        $before[$c] = $now[$c];
        $after[$c] = $f[$c];
        $set[] = "$c = :$c";
        $args[$c] = is_bool($f[$c]) ? ($f[$c] ? 't' : 'f') : $f[$c];
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE crm_settings SET ' . implode(', ', $set) . ' WHERE id = 1')->execute($args);
    }
    if ($askStages !== null) {
        $was = array_values(array_map(static fn (array $s): int => $s['stage_id'], array_filter($now['stages'], static fn (array $s): bool => $s['ask_assistant'])));
        sort($was);
        $want = array_values(array_unique(array_map('intval', $askStages)));
        sort($want);
        if ($was !== $want) {
            $pdo->prepare("UPDATE stages SET ask_assistant = (id = ANY (CAST(:ids AS bigint[]))) WHERE kind = 'open' AND archived_at IS NULL AND ask_assistant IS DISTINCT FROM (id = ANY (CAST(:ids AS bigint[])))")
                ->execute(['ids' => pg_array_literal($want)]);
            $before['ask_assistant_stages'] = $was;
            $after['ask_assistant_stages'] = $want;
        }
    }
    return ['before' => $before, 'after' => $after];
}
