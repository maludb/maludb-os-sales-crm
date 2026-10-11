<?php /** Who else takes part (#activity-participants): contacts (with their account) and colleagues; Remove each; Add a contact or a colleague (the record
 * picker). Data: a, participants, may */
$id = (int) $a['activity_id']; ?>
<div class="card mb-3" id="activity-participants"><div class="card-header"><h6 class="card-title mb-0">With</h6></div>
    <?php if ($participants === []): ?><div class="card-body fs-12 text-muted" id="activity-participants-empty">Nobody else yet.</div>
    <?php else: ?><div class="list-group list-group-flush">
        <?php foreach ($participants as $p): $isContact = $p['contact_id'] !== null; $pid = (int) ($isContact ? $p['contact_id'] : $p['member_id']); $key = ($isContact ? 'contact-' : 'member-') . $pid; ?>
        <div class="list-group-item d-flex align-items-center gap-2" id="activity-participant-<?= $key ?>"><i class="<?= $isContact ? 'feather-user' : 'feather-users' ?> text-muted"></i>
            <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= $isContact ? hx_link('/contacts/' . $pid, e($p['name']), 'text-dark') : e($p['name']) ?></div><div class="fs-11 text-muted"><?= e((string) ($p['detail'] ?? '')) ?></div></div>
            <?php if ($may['edit']): ?><form method="post" action="/activities/participants/remove.php" hx-post="/activities/participants/remove.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
                <input type="hidden" name="<?= $isContact ? 'contact' : 'member' ?>" value="<?= $pid ?>"><button type="submit" class="btn btn-sm btn-light" id="activity-participant-<?= $key ?>-remove" aria-label="Take <?= e($p['name']) ?> off"><i class="feather-x"></i></button></form><?php endif; ?>
        </div>
        <?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($may['edit']): ?><div class="card-body border-top">
        <form method="post" action="/activities/participants/add.php" hx-post="/activities/participants/add.php" hx-target="#flash" class="mb-2" id="activity-participant-add-contact-form"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
            <label class="form-label fs-11 mb-0" id="activity-participant-contact-label" for="activity-participant-contact">Add a contact</label>
            <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'activity-participant-contact', 'name' => 'contact', 'source' => 'contact', 'value' => null, 'labelledby' => 'activity-participant-contact-label', 'title' => 'A contact', 'placeholder' => 'Choose a contact']) ?></div>
                <button type="submit" class="btn btn-sm btn-light btn-touch" id="activity-participant-add-contact-btn">Add</button></div></form>
        <form method="post" action="/activities/participants/add.php" hx-post="/activities/participants/add.php" hx-target="#flash" id="activity-participant-add-member-form"><?= csrf_field() ?><input type="hidden" name="activity" value="<?= $id ?>">
            <label class="form-label fs-11 mb-0" id="activity-participant-member-label" for="activity-participant-member">Add a colleague</label>
            <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'activity-participant-member', 'name' => 'member', 'source' => 'member', 'value' => null, 'labelledby' => 'activity-participant-member-label', 'title' => 'A colleague', 'placeholder' => 'Choose a colleague']) ?></div>
                <button type="submit" class="btn btn-sm btn-light btn-touch" id="activity-participant-add-member-btn">Add</button></div></form>
    </div><?php endif; ?>
</div>
