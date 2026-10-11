<?php
declare(strict_types=1);

/**
 * Email templates' reads (screens `template-list`, `template-add`, `template-edit`; the `find_templates` resolver): mcp_email_templates (db/016) shows the shared ones
 * and the caller's own (a CRM admin all). A template is Markdown with merge fields (merge.php); Marketing and the Sales Manager keep the shared ones, a seller their own.
 */
require_once __DIR__ . '/../email/merge.php';

const TEMPLATE_KINDS = ['general' => 'General', 'intro' => 'Introduction', 'follow_up' => 'Follow-up', 'proposal' => 'Proposal', 'meeting' => 'Meeting', 'thank_you' => 'Thank you',
    'renewal' => 'Renewal', 'win_back' => 'Win-back'];
const TEMPLATE_KIND_BADGE = ['general' => 'secondary', 'intro' => 'primary', 'follow_up' => 'info', 'proposal' => 'warning', 'meeting' => 'success', 'thank_you' => 'success', 'renewal' => 'info', 'win_back' => 'danger'];

/** The templates the caller may use: filters q (name or subject), kind, mine, archived. Shared first, then by name. [rows, total] */
function find_email_templates(PDO $pdo, array $f = [], int $limit = 100): array
{
    $w = [empty($f['archived']) ? 't.archived_at IS NULL' : 't.archived_at IS NOT NULL'];
    $a = [];
    if (($f['q'] ?? '') !== '') { $w[] = "(t.name ILIKE '%' || :q || '%' OR t.subject ILIKE '%' || :q || '%')"; $a['q'] = mb_substr((string) $f['q'], 0, 120); }
    if (isset($f['kind']) && isset(TEMPLATE_KINDS[$f['kind']])) { $w[] = 't.kind = :kind'; $a['kind'] = $f['kind']; }
    if (!empty($f['mine'])) { $w[] = 't.owner_member_id = :me'; $a['me'] = (int) current_member_id(); }
    $st = $pdo->prepare('SELECT t.template_id, t.name, t.subject, t.body, t.kind, t.is_shared, t.owner_member_id, t.usage_count, t.archived_at, m.display_name AS owner_name, count(*) OVER () AS total
                           FROM mcp_email_templates t LEFT JOIN members m ON m.id = t.owner_member_id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.is_shared DESC, lower(t.name) LIMIT ' . max(1, min(500, $limit)));
    $st->execute($a);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'total' => $rows === [] ? 0 : (int) $rows[0]['total']];
}

/** One template the caller may see, with its owner's name; null when not. */
function find_email_template(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT t.template_id, t.name, t.subject, t.body, t.kind, t.is_shared, t.owner_member_id, t.usage_count, t.archived_at, m.display_name AS owner_name
                            FROM mcp_email_templates t LEFT JOIN members m ON m.id = t.owner_member_id WHERE t.template_id = :id', ['id' => $id]);
}

/** What the caller may do with a template: edit and delete — its owner, or the holder of templates.manage; making or keeping a SHARED one needs templates.manage. */
function email_template_may(?array $t): array
{
    $manage = has_right('templates.manage');
    $own = $t !== null && (int) ($t['owner_member_id'] ?? 0) === (int) current_member_id();
    $can = $t === null ? true : ($own || $manage);
    return ['edit' => $can, 'delete' => $t !== null && $can, 'share' => $manage, 'use' => has_right('email.send')];
}

/** The picker: templates the caller may use (not archived), by name; `kind` narrows. */
function email_templates_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    return crm_simple_pick($pdo, "SELECT template_id, name, kind, is_shared, count(*) OVER () AS total FROM mcp_email_templates
                                   WHERE archived_at IS NULL AND (:q = '' OR name ILIKE '%' || :q || '%' OR subject ILIKE '%' || :q || '%') ORDER BY is_shared DESC, lower(name)", $q, $page,
        static fn (array $r): array => ['id' => (int) $r['template_id'], 'label' => $r['name'], 'detail' => TEMPLATE_KINDS[$r['kind']] ?? $r['kind'], 'badge' => $r['is_shared'] ? 'shared' : 'mine', 'color' => $r['is_shared'] ? 'info' : 'secondary']);
}

function email_template_label(PDO $pdo, int $id): ?string
{
    $v = one_value($pdo, 'SELECT name FROM mcp_email_templates WHERE template_id = :id AND archived_at IS NULL', ['id' => $id]);
    return $v === null || $v === false ? null : (string) $v;
}

/** The JSON shape of a template (find_templates): whitelisted, with the merge fields it uses. */
function present_email_template(array $t): array
{
    return ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'subject' => $t['subject'], 'body' => $t['body'], 'kind' => $t['kind'], 'is_shared' => (bool) $t['is_shared'],
        'owner_member_id' => $t['owner_member_id'] === null ? null : (int) $t['owner_member_id'], 'usage_count' => (int) $t['usage_count'], 'archived' => $t['archived_at'] !== null,
        'merge_fields' => merge_fields_in((string) $t['subject'], (string) $t['body'])];
}
