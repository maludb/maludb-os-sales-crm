<?php /** The compose screen (email-compose, #compose-form): To as chips, a template (the record picker; one HTMX refresh of #compose-fill, links without JavaScript), the subject, the
 * body (Markdown), the merge preview, files from the record, the meeting's ICS; Save draft · Send. Data: s (the state), notes, filled, back, account, contact, may_send, tpl, tpl_label, perPerson, notice */
$draft = $s['draft_id'];
$sel = array_values(array_map(static fn (array $p): int => $p['contact_id'], array_filter($s['contacts'], static fn (array $p): bool => $p['selected'])));
$title = $draft !== null ? 'Edit draft' : ($s['reply_to'] !== null ? 'Reply' : 'New email');
$link = static function (array $over = []) use ($s, $sel, $account, $back): string {
    $q = ['contacts' => $sel, 'deal' => $s['deal_id'], 'account' => $account, 'activity' => $s['activity_id'], 'reply' => $s['reply_to'], 'draft' => $s['draft_id'], 'back' => $back];
    return '/email/compose?' . http_build_query(array_filter($over + $q, static fn ($v) => $v !== null && $v !== []));
};
$f = static fn (string $name): string => 'compose-' . $name; ?>
<?= view('shared/header.php', ['id' => 'email-compose', 'title' => $title, 'crumbs' => [['Today', '/'], ['Email', '/email/unmatched'], [$title, null]], 'back' => [$back, 'where you were']]) ?>
<div class="main-content" id="email-compose-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/email/draft.php" hx-post="/email/draft.php" hx-target="#flash" id="compose-form"><?= csrf_field() ?>
        <input type="hidden" name="confirm" value="yes"><input type="hidden" name="return_to" value="<?= e($back) ?>">
        <?php if ($draft !== null): ?><input type="hidden" name="draft" value="<?= (int) $draft ?>"><?php endif; ?>
        <?php if ($s['deal_id'] !== null): ?><input type="hidden" name="deal" value="<?= (int) $s['deal_id'] ?>"><?php endif; ?>
        <?php if ($s['activity_id'] !== null): ?><input type="hidden" name="activity" value="<?= (int) $s['activity_id'] ?>"><?php endif; ?>
        <?php if ($s['reply_to'] !== null): ?><input type="hidden" name="reply" value="<?= (int) $s['reply_to'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12" id="compose-to"><div class="form-label" id="compose-to-label">To</div>
                <?php if ($s['contacts'] === []): ?><div class="fs-12 text-muted mb-2" id="compose-to-empty">Choose who it is to.</div><?php endif; ?>
                <?= view('email/partials/to-chips.php', ['contacts' => $s['contacts']]) ?>
                <div class="mt-2"><label class="form-label fs-12 mb-1" id="compose-contact-label" for="compose-contact">Someone else</label>
                    <?= picker_field(['id' => 'compose-contact', 'name' => 'contact', 'source' => 'contact', 'value' => null, 'labelledby' => 'compose-contact-label', 'title' => 'Who it is to', 'placeholder' => 'Add a contact']) ?></div>
            </div>
            <div class="col-12" id="compose-template-row"><label class="form-label" id="compose-template-label" for="compose-template">Template</label>
                <?= picker_field(['id' => 'compose-template', 'name' => 'template', 'source' => 'template', 'value' => $s['template_id'], 'labelledby' => 'compose-template-label', 'title' => 'Start from a template', 'placeholder' => 'None',
                    'extra' => ' hx-get="/email/compose" hx-trigger="change" hx-include="#compose-form" hx-target="#compose-fill" hx-select="#compose-fill" hx-swap="outerHTML" hx-push-url="false"']) ?>
                <?php if ($s['templates'] !== []): ?><div class="fs-12 text-muted mt-1" id="compose-template-links">Or start from: <?php foreach ($s['templates'] as $t): ?>
                    <a href="<?= e($link(['template' => (int) $t['template_id']])) ?>" id="compose-template-link-<?= (int) $t['template_id'] ?>"><?= e($t['name']) ?></a> <?php endforeach; ?></div><?php endif; ?></div>
            <div id="compose-fill" class="col-12"><div class="row g-3">
                <div class="col-12"><label class="form-label" for="compose-subject">Subject</label><input type="text" class="form-control btn-touch" id="compose-subject" name="subject" maxlength="300" value="<?= e($s['subject']) ?>" placeholder="What it is about"></div>
                <div class="col-12"><label class="form-label" for="compose-body">Message <span class="fs-11 text-muted">(Markdown: **bold**, *italic*, - lists, [links](https://…))</span></label>
                    <textarea class="form-control" id="compose-body" name="body" rows="10" maxlength="100000"><?= e($s['body']) ?></textarea></div>
                <div class="col-12"><?= view('email/partials/merge-preview.php', ['s' => $s, 'notes' => $notes, 'filled' => $filled, 'perPerson' => $perPerson, 'tpl_label' => $tpl_label]) ?></div>
                <?php if ($s['files'] !== []): ?><div class="col-12" id="compose-files"><div class="form-label">Files from the record</div><input type="hidden" name="attachments[]" value="">
                    <?php foreach ($s['files'] as $a): $aid = (int) $a['attachment_id']; ?><label class="form-check d-block"><input type="checkbox" class="form-check-input" name="attachments[]" value="<?= $aid ?>" id="compose-file-<?= $aid ?>"<?= $a['attached'] ? ' checked' : '' ?>>
                        <?= e($a['filename']) ?> <span class="fs-11 text-muted"><?= e(bytes_label((int) $a['byte_size'])) ?></span></label><?php endforeach; ?></div><?php endif; ?>
                <?php if ($s['meeting'] !== null): ?><div class="col-12" id="compose-ics"><div class="alert alert-info mb-0"><i class="feather-calendar me-1"></i>The invitation for <strong><?= e($s['meeting']['subject']) ?></strong> goes along as a calendar file they can accept.</div></div><?php endif; ?>
            </div></div>
            <div class="col-12 fs-11 text-muted" id="compose-replyto"><?= $s['reply_to_mailbox'] !== null ? 'Replies go to ' . e($s['reply_to_mailbox']) . ' and land on the timeline.' : 'No logging mailbox is set: their replies will not be logged here.' ?>
                The footer and the unsubscribe link are added.</div>
        </div></div></div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($may_send): ?><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="compose-send-btn" formaction="/email/send.php" hx-post="/email/send.php" hx-confirm="Send this email now?"><i class="feather-send me-1"></i>Send</button><?php endif; ?>
            <button type="submit" class="btn btn-light btn-touch" id="compose-draft-btn"><i class="feather-save me-1"></i>Save draft</button>
            <?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="compose-cancel-btn"') ?>
        </div>
    </form>
</div>
