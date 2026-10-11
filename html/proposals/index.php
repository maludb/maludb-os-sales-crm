<?php
declare(strict_types=1);
/**
 * /proposals?kind=&status=&who= (screen `proposals`): what the Sales Assistant (or a colleague) proposed for me — a next step on a deal that has none, a lead's qualification, a possible duplicate, a deal going stale —
 * as cards with Accept and Dismiss. Waiting ones by default; status=all shows what was decided; a manager's `who` is everyone's (all) or one person's (a member id). JSON: the page of proposals, whitelisted.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/records/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/proposals/present.php';
require_login();
require_right('records.read');
$pdo = db();
$f = proposal_filters_from_request();
$page = max(1, request_integer('page') ?? 1);
$result = find_proposals($pdo, $f, $page);
$cards = array_map('proposal_render', $result['rows']);
log_screen_view($pdo, 'proposals');
if (wants_json()) {
    respond_screen(['filters' => $f, 'page' => $page, 'total' => $result['total'], 'more' => $page * PROPOSAL_PAGE < $result['total'], 'waiting' => proposals_waiting($pdo, (int) current_member_id()), 'proposals' => array_map('present_proposal', $cards)]);
}
$notices = ['accepted' => ['success', 'Accepted.'], 'dismissed' => ['success', 'Dismissed.']];
render_screen('Proposals', view('proposals/index.php', ['cards' => $cards, 'filters' => $f, 'total' => $result['total'], 'page' => $page, 'manager' => is_manager(), 'members' => is_manager() ? find_owners($pdo) : [],
    'waiting' => proposals_waiting($pdo, (int) current_member_id()), 'notice' => $notices[$_GET['notice'] ?? ''] ?? null, 'back' => here_url()]), ['activeNav' => 'proposals', 'screen' => 'proposals', 'entity' => 'proposal']);
