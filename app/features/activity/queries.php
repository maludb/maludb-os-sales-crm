<?php
declare(strict_types=1);

/**
 * The activity trail the caller may see (mcp_activity_log, db/016: admitted members; a row about a lead, account, contact or deal only when the
 * wall lets them see that record). The screen `activity` shows the person's own rows, or one record's history by its audit key; the actor's
 * name comes from the directory mirror beside it (a name is not a secret here: every admitted member sees the team).
 */
const ACTIVITY_PAGE = 50;
const ACTIVITY_KEYS = ['lead', 'account', 'contact', 'deal'];

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days),
 * lead / account / contact / deal (int: that record's history), entity_type + entity_id (one record's history otherwise).
 */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    foreach (ACTIVITY_KEYS as $k) {
        if (($filters[$k] ?? null) !== null) {
            $where[] = 'l.' . $k . '_id = :' . $k;
            $args[$k] = (int) $filters[$k];
            $keyed = true;
        }
    }
    if (($filters['entity_type'] ?? null) !== null) {
        $where[] = 'l.entity_type = :etype AND l.entity_id = :eid';
        $args['etype'] = (string) $filters['entity_type'];
        $args['eid'] = (int) $filters['entity_id'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'l.actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'l.action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'l.occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT l.log_id AS activity_id, l.occurred_at, l.actor_member_id, m.display_name AS actor_name, COALESCE(m.is_agent, false) AS actor_is_agent, l.source, l.action, l.screen,
                   l.entity_type, l.entity_id, l.after, l.lead_id, l.account_id, l.contact_id, l.deal_id, l.token_id, l.agent_run_id
              FROM mcp_activity_log l LEFT JOIN members m ON m.id = l.actor_member_id' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by the audit key for a lead, an account, a contact or a deal, else by entity. */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 30): array
{
    $f = in_array($type, ACTIVITY_KEYS, true) ? [$type => $id] : ['entity_type' => $type, 'entity_id' => $id];
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    $id = static fn ($v): ?int => $v === null ? null : (int) $v;
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $id($r['actor_member_id']), 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'], 'entity_id' => $id($r['entity_id']),
            'lead_id' => $id($r['lead_id']), 'account_id' => $id($r['account_id']), 'contact_id' => $id($r['contact_id']), 'deal_id' => $id($r['deal_id']),
            'agent_run_id' => $id($r['agent_run_id'])];
}

/** The record a row concerns, as a link: [url, label] or null. The deal first (the most specific), then the contact, the account, the lead. */
function activity_record_link(array $r): ?array
{
    foreach (['deal' => 'the deal', 'contact' => 'the contact', 'account' => 'the account', 'lead' => 'the lead'] as $k => $label) {
        if (($r[$k . '_id'] ?? null) !== null) {
            return ['/' . $k . 's/' . (int) $r[$k . '_id'], $label];
        }
    }
    return null;
}

/** One line for a row, in words: "Priya moved a deal: Acme — 20 seats". Falls back to the event name. The slices' events are named here once. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'Sales CRM';
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $named = $after['title'] ?? $after['name'] ?? $after['subject'] ?? $after['label'] ?? null;
    $title = is_string($named) ? ': ' . mb_substr($named, 0, 80) : '';
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_on.refused' => 'was refused at sign-on', 'member.session_ended' => 'was signed out',
        'directory.sync' => 'refreshed the directory', 'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'settings.prefs' => 'changed how they are told',
        'settings.signature' => 'changed their signature', 'notification.read' => 'read a notification', 'notification.read_all' => 'read every notification', 'assistant.ask' => 'asked the command bar',
        'search.keyword' => 'searched',
        'lead.create' => 'added a lead', 'lead.update' => 'changed a lead', 'lead.qualify' => 'qualified a lead', 'lead.unqualify' => 'unqualified a lead', 'lead.convert' => 'converted a lead',
        'lead.assign' => 'assigned a lead', 'lead.accept' => 'accepted a proposed lead', 'lead.dismiss' => 'dismissed a proposed lead', 'lead.requalify' => 'requalified a lead',
        'import.start' => 'started an import', 'import.run' => 'ran an import for real', 'import.template_save' => 'saved an import mapping', 'import.dry_run' => 'the dry run of an import finished',
        'import.done' => 'an import finished', 'import.failed' => 'an import failed', 'duplicate.dismiss' => 'said two records are not duplicates',
        'account.create' => 'added an account', 'account.update' => 'changed an account', 'contact.create' => 'added a contact', 'contact.update' => 'changed a contact',
        'contact.opt_out' => 'recorded an opt-out', 'contact.primary' => 'set a primary contact',
        'deal.create' => 'added a deal', 'deal.update' => 'changed a deal', 'deal.move' => 'moved a deal', 'deal.won' => 'won a deal', 'deal.lost' => 'lost a deal', 'deal.reopen' => 'reopened a deal',
        'deal.assign' => 'reassigned a deal', 'deal.product_add' => 'added a product to a deal', 'deal.product_update' => 'changed a deal\'s product', 'deal.product_remove' => 'took a product off a deal',
        'deal.contact_add' => 'added a person to a deal', 'deal.contact_update' => 'changed a person\'s role on a deal', 'deal.contact_remove' => 'took a person off a deal',
        'view.save' => 'saved a view', 'view.delete' => 'deleted a view',
        'activity.create' => 'scheduled an activity', 'activity.log' => 'logged an activity', 'activity.update' => 'changed an activity', 'activity.complete' => 'completed an activity',
        'activity.reschedule' => 'rescheduled an activity', 'activity.snooze' => 'snoozed a reminder', 'activity.reopen' => 'reopened an activity',
        'email.log' => 'logged an email', 'email.draft' => 'drafted an email', 'email.send' => 'sent an email', 'email.private' => 'changed whether an email is private', 'email.attach' => 'put an email on a contact',
        'email.make_lead' => 'made a lead of an email', 'email.ignore' => 'ignored an email', 'email.invite' => 'sent a meeting invitation', 'template.save' => 'saved an email template', 'template.delete' => 'removed an email template',
        'mail.received' => 'the logging mailbox received an email', 'mail.matched' => 'an email was matched to a contact', 'mail.proposed' => 'an email from a stranger was proposed as a lead', 'mail.unmatched' => 'an email matched no one',
        'mail.ignored' => 'an email was set aside', 'note.add' => 'added a note', 'attachment.add' => 'attached a file',
        'record.delete' => 'deleted a record', 'record.restore' => 'restored a record', 'record.restrict' => 'restricted a record', 'records.merge' => 'merged records',
        'tag.apply' => 'tagged a record', 'tag.remove' => 'untagged a record', 'import.start' => 'started an import', 'import.rollback' => 'rolled back an import',
        'campaign.create' => 'made a campaign', 'list.create' => 'made a list', 'export.create' => 'asked for an export',
        'proposal.accept' => 'accepted a proposal', 'proposal.dismiss' => 'dismissed a proposal', 'key.mint' => 'minted a key', 'key.rotate' => 'rotated a key', 'key.revoke' => 'revoked a key',
        'share.read' => 'read a share', 'settings.save' => 'changed the settings', 'worker.pass' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'search.keyword' && isset($after['q'])) {
        return $who . ' searched for “' . mb_substr((string) $after['q'], 0, 60) . '”' . (isset($after['count']) ? ' (' . (int) $after['count'] . ' found)' : '');
    }
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen' . (($r['screen'] ?? '') !== '' ? ' (' . $r['screen'] . ')' : '');
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
