<?php
declare(strict_types=1);

/**
 * The command bar (chat-actions), run by the kernel (sso-shell.md): the utterance goes to the kernel's chat endpoint as the acting
 * person (ask_assistant()); ONE turn of Sales CRM's expert answers. Renders the reply and the actions (a paused one as "waits for
 * approval"), follows a `navigate` answer by HX-Location, fires HX-Trigger per entity changed, says in words when the kernel is
 * down. Not an action of the manifest — the kernel runs the agent. People only. Logs `assistant.ask` with the utterance's length,
 * the screen and the run id — never the words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

require_login();
require_post();
verify_csrf();
require_human();
$pdo = db();
$me = (int) current_member_id();

$utterance = trim((string) ($_POST['utterance'] ?? $_POST['message'] ?? ''));
$screen = request_string('screen');
$entity = request_string('entity');
$recordId = request_string('record_id');
if ($utterance === '' || mb_strlen($utterance) > 2000) {
    http_response_code(422);
    echo view('shared/assistant-reply.php', ['error' => 'Say what you want in a sentence or two.']);
    exit;
}
$_SESSION['assistant_conversation'] ??= 'crm-' . bin2hex(random_bytes(8));
$a = ask_assistant($pdo, $me, $utterance, ['screen' => $screen, 'entity' => $entity, 'record_id' => $recordId !== '' ? $recordId : null, 'conversation_id' => (string) $_SESSION['assistant_conversation']]);
$logged = ['length' => mb_strlen($utterance), 'screen' => $screen, 'record_id' => $recordId !== '' ? mb_substr($recordId, 0, 40) : null, 'kernel' => $a['http']];
if ($a['error'] !== null) {
    log_activity($pdo, 'assistant.ask', null, null, ['screen' => $screen, 'after' => $logged]);
    http_response_code($a['http'] >= 400 && $a['http'] < 600 ? $a['http'] : 503);
    echo view('shared/assistant-reply.php', ['error' => $a['error']]);
    exit;
}
$events = assistant_refresh_events($a['actions']);
if ($events !== []) {
    hx_trigger(implode(', ', $events));
}
if ($a['navigate'] !== null) {
    hx_location($a['navigate']);
    header('HX-Push-Url: ' . $a['navigate']);
}
log_activity($pdo, 'assistant.ask', null, null, ['screen' => $screen, 'after' => $logged + ['run_id' => $a['run_id'], 'status' => $a['status'], 'actions' => count($a['actions']), 'navigate' => $a['navigate'], 'cost' => $a['cost']]]);
echo view('shared/assistant-reply.php', ['reply' => $a['reply'], 'status' => (string) $a['status'], 'actions' => $a['actions'], 'approval' => $a['approval'], 'finished' => $a['finished'], 'navigate' => $a['navigate']]);
