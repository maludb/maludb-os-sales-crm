<?php /** One unmatched or proposed email (#unmatched-{id}): who wrote, the subject, how it stands; Make lead · Attach to contact (the picker) · Ignore. Data: r, tz, may, back */
$id = (int) $r['email_id'];
$proposed = $r['match_status'] === 'proposed_lead';
[$words, $tone] = EMAIL_MATCH_WORDS[$r['match_status']] ?? [$r['match_status'], 'secondary']; ?>
<div class="list-group-item" id="unmatched-<?= $id ?>">
    <div class="d-flex align-items-start gap-2">
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold text-truncate"><?php if ($r['is_private']): ?><i class="feather-lock me-1 text-muted"></i><?php endif; ?><?= hx_link(with_back('/email/in/' . $id, $back), e((string) ($r['subject'] ?: '(no subject)')), 'text-dark', 'id="unmatched-' . $id . '-link"') ?></div>
            <div class="fs-11 text-muted text-truncate"><?= e((string) ($r['from_name'] ?: $r['from_address'])) ?><?= $r['from_name'] ? ' · ' . e((string) $r['from_address']) : '' ?> · <?= e(format_ts($r['at'], $tz, 'M j, Y g:i A')) ?><?= (int) $r['files'] > 0 ? ' · ' . (int) $r['files'] . ' file' . ((int) $r['files'] === 1 ? '' : 's') : '' ?></div>
        </div>
        <span class="badge bg-soft-<?= e($tone) ?> text-<?= e($tone) ?>" id="unmatched-<?= $id ?>-state"><?= e($words) ?></span>
    </div>
    <?php if ($proposed && $r['lead_id'] !== null): ?><div class="fs-12 mt-1" id="unmatched-<?= $id ?>-lead">A lead is proposed from it — <?= hx_link(with_back('/leads/' . (int) $r['lead_id'], $back), 'see the lead', 'fw-semibold') ?>, or accept it here.</div><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 align-items-end mt-2">
        <?php if ($may['lead']): ?><form method="post" action="/email/make-lead.php" hx-post="/email/make-lead.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-primary btn-touch" id="unmatched-<?= $id ?>-lead-btn"><i class="feather-user-plus me-1"></i><?= $proposed ? 'Accept as a lead' : 'Make lead' ?></button></form><?php endif; ?>
        <?php if ($may['attach']): ?><form method="post" action="/email/attach.php" hx-post="/email/attach.php" hx-target="#flash" class="d-flex flex-wrap gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
            <div style="min-width: 12rem"><span class="visually-hidden" id="unmatched-<?= $id ?>-contact-label">Attach to a contact</span><?= picker_field(['id' => 'unmatched-' . $id . '-contact', 'name' => 'contact', 'source' => 'contact', 'value' => null, 'labelledby' => 'unmatched-' . $id . '-contact-label', 'title' => 'Whose address is it', 'placeholder' => 'Attach to a contact', 'required' => true]) ?></div>
            <button type="submit" class="btn btn-sm btn-light btn-touch" id="unmatched-<?= $id ?>-attach-btn">Attach</button></form><?php endif; ?>
        <?php if ($may['lead']): ?><form method="post" action="/email/ignore.php" hx-post="/email/ignore.php" hx-target="#flash" hx-confirm="Hide this email from the list?"><?= csrf_field() ?><input type="hidden" name="email" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-light btn-touch" id="unmatched-<?= $id ?>-ignore-btn">Ignore</button></form><?php endif; ?>
    </div>
</div>
