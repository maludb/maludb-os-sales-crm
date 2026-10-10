<?php /** The lead inbox (lead-inbox): Proposed (Accept · Dismiss) and Unowned (Take, or select several and assign with the owner picker). Data: box, tz, may (write, assign), me, notice */ ?>
<?= view('shared/header.php', ['id' => 'lead-inbox', 'title' => 'Lead inbox', 'crumbs' => [['Today', '/'], ['Leads', '/leads'], ['Inbox', null]], 'back' => ['/leads', 'Leads']]) ?>
<div class="main-content" id="lead-inbox-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card" id="inbox-proposed"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Proposed</h5><span class="badge bg-soft-info text-info"><?= count($box['proposed']) ?></span></div>
            <?php if ($box['proposed'] === []): ?><div class="card-body fs-12 text-muted" id="inbox-proposed-empty">Nothing proposed. Strangers who write to the logging mailbox and website forms land here for a person to accept.</div>
            <?php else: ?><div class="list-group list-group-flush"><?php foreach ($box['proposed'] as $r) { echo view('leads/partials/inbox-row.php', ['r' => $r, 'kind' => 'proposed', 'tz' => $tz, 'may' => $may, 'me' => $me]); } ?></div><?php endif; ?>
        </div></div>
        <div class="col-lg-6"><div class="card" id="inbox-unowned"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Unowned</h5><span class="badge bg-soft-secondary text-secondary"><?= count($box['unowned']) ?></span></div>
            <?php if ($box['unowned'] === []): ?><div class="card-body fs-12 text-muted" id="inbox-unowned-empty">Every lead has an owner.</div>
            <?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($box['unowned'] as $r) { echo view('leads/partials/inbox-row.php', ['r' => $r, 'kind' => 'unowned', 'tz' => $tz, 'may' => $may, 'me' => $me]); } ?></div>
                <?php if ($may['assign']): ?>
                <form method="post" action="/leads/assign.php" hx-post="/leads/assign.php" hx-target="#flash" id="inbox-bulk-form" class="card-footer d-flex flex-wrap gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="return_to" value="/leads/inbox">
                    <span class="fs-12 text-muted" id="inbox-bulk-label">Give the selected leads to</span>
                    <div class="flex-grow-1" style="min-width: 12rem"><?= picker_field(['id' => 'inbox-bulk-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $may['assign_any'] ? null : $me, 'labelledby' => 'inbox-bulk-label', 'title' => 'Give them to', 'required' => true]) ?></div>
                    <button type="submit" class="btn btn-primary btn-touch" id="inbox-bulk-btn">Assign</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div></div>
    </div>
</div>
