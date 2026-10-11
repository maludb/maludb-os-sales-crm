<?php
declare(strict_types=1);

/**
 * Email templates' writes (actions template_save, template_delete). Own templates are their owner's; shared ones need templates.manage to make or to change. A template
 * that has been used is ARCHIVED, never deleted (the sent emails name it); one never used is deleted. The handlers log ids and the name, never the body.
 */

/** Insert ($id null) or change a template. $f: name, subject, body, kind, is_shared. Answers the id. */
function email_template_write(PDO $pdo, ?int $id, array $f, int $me): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO email_templates (name, subject, body, kind, is_shared, owner_member_id, created_by) VALUES (:n, :s, :b, :k, CAST(:sh AS boolean), :me, :me) RETURNING id');
        $st->execute(['n' => $f['name'], 's' => $f['subject'], 'b' => $f['body'], 'k' => $f['kind'], 'sh' => $f['is_shared'] ? 't' : 'f', 'me' => $me]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE email_templates SET name = :n, subject = :s, body = :b, kind = :k, is_shared = CAST(:sh AS boolean) WHERE id = :id')
        ->execute(['n' => $f['name'], 's' => $f['subject'], 'b' => $f['body'], 'k' => $f['kind'], 'sh' => $f['is_shared'] ? 't' : 'f', 'id' => $id]);
    return $id;
}

/** Take a template away: deleted when never used, else archived (out of the lists and the picker, still named by the emails sent with it). Answers 'deleted' or 'archived'. */
function email_template_remove(PDO $pdo, int $id): string
{
    $used = (int) one_value($pdo, 'SELECT usage_count FROM email_templates WHERE id = :id', ['id' => $id]) > 0
        || one_value($pdo, 'SELECT 1 FROM outbound_email WHERE template_id = :id LIMIT 1', ['id' => $id]) !== null;
    if ($used) {
        $pdo->prepare('UPDATE email_templates SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
        return 'archived';
    }
    $pdo->prepare('DELETE FROM email_templates WHERE id = :id')->execute(['id' => $id]);
    return 'deleted';
}
