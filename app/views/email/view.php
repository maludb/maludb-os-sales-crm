<?php /** One email (email-view): the headers, how it was matched, the body when the caller may read it, the files, the people and the deal it is on, the activities it made and the conversation;
 * Reply · Mark private · Attach to contact · Make lead · Ignore by right. Data: e, may, contacts, files, activities, thread, deal, lead, tz, back, me, notice */
$in = $e['direction'] === 'in';
$id = (int) $e['email_id'];
$here = '/email/' . $e['direction'] . '/' . $id;
$subject = (string) ($e['subject'] ?: '(no subject)');
if ($in) { [$words, $tone] = EMAIL_MATCH_WORDS[$e['match_status']] ?? [$e['match_status'], 'secondary']; }
else { [$words, $tone] = EMAIL_OUT_WORDS[$e['status']] ?? [$e['status'], 'secondary']; }
$to = pg_text_array((string) $e['to_addresses']);
$cc = pg_text_array((string) $e['cc_addresses']); ?>
<?= view('shared/header.php', ['id' => 'email-view', 'title' => $subject, 'crumbs' => [['Today', '/'], ['Email', '/email/unmatched'], [mb_strimwidth($subject, 0, 40, '…'), null]], 'back' => ($b = back_link()) ]) ?>
<div class="main-content" id="email-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3" id="email-view-actions">
        <span class="badge bg-soft-<?= $in ? 'info text-info' : 'primary text-primary' ?>" id="email-view-direction"><?= $in ? 'received' : ($e['status'] === 'sent' ? 'sent' : 'draft') ?></span>
        <?php if ($in || $e['status'] !== 'sent'): ?><span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="email-view-state"><?= e($words) ?></span><?php endif; ?>
        <?php if ($e['is_private']): ?><span class="badge bg-soft-warning text-warning" id="email-view-private"><i class="feather-lock me-1"></i>private</span><?php endif; ?>
        <span class="ms-auto d-flex flex-wrap gap-2">
        <?php if ($may['reply']): ?><?= hx_link('/email/compose?reply=' . $id . '&back=' . rawurlencode($here), '<i class="feather-corner-up-left me-1"></i>Reply', 'btn btn-light btn-touch', 'id="email-reply-btn"') ?><?php endif; ?>
        <?php if ($may['edit']): ?><?= hx_link('/email/compose?draft=' . $id . '&back=' . rawurlencode($here), '<i class="feather-edit-2 me-1"></i>Edit and send', 'btn btn-primary btn-touch', 'id="email-edit-btn"') ?><?php endif; ?>
        <?php if ($may['private_set']): ?><form method="post" action="/email/private.php" hx-post="/email/private.php" hx-target="#flash"><?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= $id ?>"><input type="hidden" name="direction" value="<?= e($e['direction']) ?>"><input type="hidden" name="private" value="<?= $e['is_private'] ? 'no' : 'yes' ?>">
            <button type="submit" class="btn btn-light btn-touch" id="email-private-btn"><i class="feather-<?= $e['is_private'] ? 'unlock' : 'lock' ?> me-1"></i><?= $e['is_private'] ? 'Make public' : 'Mark private' ?></button></form><?php endif; ?>
        </span>
    </div>
    <div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3" id="email-headers"><div class="card-body fs-12">
            <div class="d-flex"><span class="text-muted me-2" style="min-width: 3.5rem">From</span><span class="fw-semibold text-break" id="email-view-from"><?= e((string) ($e['from_name'] ?: $e['from_address'])) ?><?= $e['from_name'] ? ' <span class="text-muted fw-normal">' . e((string) $e['from_address']) . '</span>' : '' ?></span></div>
            <div class="d-flex"><span class="text-muted me-2" style="min-width: 3.5rem">To</span><span class="text-break" id="email-view-to"><?= e(implode(', ', $to)) ?></span></div>
            <?php if ($cc !== []): ?><div class="d-flex"><span class="text-muted me-2" style="min-width: 3.5rem">Cc</span><span class="text-break" id="email-view-cc"><?= e(implode(', ', $cc)) ?></span></div><?php endif; ?>
            <div class="d-flex"><span class="text-muted me-2" style="min-width: 3.5rem">When</span><span id="email-view-when"><?= e(format_ts($e['at'], $tz, 'D, M j, Y g:i A')) ?></span></div>
            <?php if ($in && $e['mailbox_address']): ?><div class="d-flex"><span class="text-muted me-2" style="min-width: 3.5rem">Via</span><span class="text-break" id="email-view-mailbox"><?= e((string) $e['mailbox_address']) ?></span></div><?php endif; ?>
            <?php if ($e['error']): ?><div class="text-danger mt-1" id="email-view-error"><i class="feather-alert-circle me-1"></i><?= e((string) $e['error']) ?></div><?php endif; ?>
            <?php if ($e['has_ics']): ?><div class="mt-1"><i class="feather-calendar me-1"></i>With the meeting's invitation attached.</div><?php endif; ?>
        </div></div>
        <div class="card mb-3" id="email-view-body-card"><div class="card-body">
            <?php if ($e['body_text'] === null): ?><div class="fs-12 text-muted" id="email-view-body-private"><i class="feather-lock me-1"></i>This email is private to its sender and the managers.</div>
            <?php elseif (trim($e['body_text']) === ''): ?><div class="fs-12 text-muted" id="email-view-body">An email with no text.</div>
            <?php elseif ($in): $read = email_reading_text($e['body_text']); ?>
                <div class="text-break" id="email-view-body"><?= nl2br(e($read)) ?></div>
                <?php if (trim($read) !== trim($e['body_text'])): ?><details class="mt-2" id="email-view-quoted"><summary class="fs-12 text-muted">The whole message, with what they quoted</summary><div class="text-break fs-12 mt-1"><?= nl2br(e($e['body_text'])) ?></div></details><?php endif; ?>
            <?php else: ?><div class="text-break" id="email-view-body"><?= md_render($e['body_text']) ?></div><?php endif; ?>
        </div></div>
        <?php if ($files !== []): ?><div class="card mb-3" id="email-view-files"><div class="card-header"><h5 class="card-title mb-0">Files</h5></div><div class="list-group list-group-flush">
            <?php foreach ($files as $f): $fid = (int) $f['attachment_id']; ?><div class="list-group-item d-flex align-items-center gap-2" id="email-file-<?= $fid ?>"><i class="feather-file text-muted"></i>
                <a href="/attachments/<?= $fid ?>" class="fw-semibold text-dark text-truncate" id="email-file-<?= $fid ?>-link"><?= e($f['filename']) ?></a><span class="fs-11 text-muted ms-auto"><?= e(bytes_label((int) $f['byte_size'])) ?></span></div><?php endforeach; ?></div></div><?php endif; ?>
        <?= view('email/partials/thread.php', ['thread' => $thread, 'tz' => $tz, 'back' => $here]) ?>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3" id="email-view-people"><div class="card-header"><h5 class="card-title mb-0">On the record</h5></div><div class="card-body fs-12">
            <?php foreach ($contacts as $c): ?><div class="mb-1" id="email-contact-<?= (int) $c['contact_id'] ?>"><i class="feather-user me-1 text-muted"></i><?= hx_link(with_back('/contacts/' . (int) $c['contact_id'], $here), e($c['name']), 'fw-semibold text-dark') ?><?= $c['account_name'] ? ' <span class="text-muted">' . e($c['account_name']) . '</span>' : '' ?></div><?php endforeach; ?>
            <?php if ($deal !== null): ?><div class="mb-1" id="email-view-deal"><i class="feather-dollar-sign me-1 text-muted"></i><?= hx_link(with_back('/deals/' . (int) $deal['deal_id'], $here), e($deal['title']), 'fw-semibold text-dark') ?></div><?php endif; ?>
            <?php if ($lead !== null): ?><div class="mb-1" id="email-view-lead"><i class="feather-user-plus me-1 text-muted"></i><?= hx_link(with_back('/leads/' . $lead, $here), 'The proposed lead', 'fw-semibold text-dark') ?></div><?php endif; ?>
            <?php if ($contacts === [] && $deal === null && $lead === null): ?><div class="text-muted" id="email-view-nobody">On no record yet.</div><?php endif; ?>
            <?php foreach ($activities as $a): ?><div class="fs-11 text-muted" id="email-activity-<?= (int) $a['activity_id'] ?>"><?= hx_link(with_back('/activities/' . (int) $a['activity_id'], $here), 'Logged as an activity' . ($a['contact_name'] ? ' on ' . e($a['contact_name']) : ''), 'text-muted') ?></div><?php endforeach; ?>
        </div></div>
        <?php if ($may['attach'] || $may['make_lead'] || $may['ignore']): ?>
        <div class="card mb-3" id="email-view-open"><div class="card-header"><h5 class="card-title mb-0">Nobody has claimed it</h5></div><div class="card-body">
            <?php if ($may['make_lead']): ?><form method="post" action="/email/make-lead.php" hx-post="/email/make-lead.php" hx-target="#flash" class="mb-2"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100" id="email-make-lead-btn"><i class="feather-user-plus me-1"></i><?= $lead !== null ? 'Accept as a lead' : 'Make lead' ?></button></form><?php endif; ?>
            <?php if ($may['attach']): ?><form method="post" action="/email/attach.php" hx-post="/email/attach.php" hx-target="#flash" class="mb-2"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
                <label class="form-label fs-11 mb-0" id="email-attach-label" for="email-attach-contact">Attach to a contact</label>
                <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'email-attach-contact', 'name' => 'contact', 'source' => 'contact', 'value' => null, 'labelledby' => 'email-attach-label', 'title' => 'Whose address is it', 'placeholder' => 'Choose a contact', 'required' => true]) ?></div>
                    <button type="submit" class="btn btn-light btn-touch" id="email-attach-btn">Attach</button></div></form><?php endif; ?>
            <?php if ($may['ignore']): ?><form method="post" action="/email/ignore.php" hx-post="/email/ignore.php" hx-target="#flash" hx-confirm="Hide this email from the list?"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="email-ignore-btn">Ignore</button></form><?php endif; ?>
        </div></div>
        <?php endif; ?>
    </div>
    </div>
</div>
