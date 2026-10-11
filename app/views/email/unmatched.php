<?php /** Email (email-unmatched): the mail that matched no one, and the strangers' mail proposed as leads — Make lead · Attach to contact · Ignore; the caller's drafts; the doors to compose, the log form and the templates.
 * Data: list, drafts, status, tz, me, may, notice */
$back = '/email/unmatched' . ($list['page'] > 1 ? '?page=' . $list['page'] : '');
$tabs = [null => 'All', 'unmatched' => 'Not matched', 'proposed_lead' => 'Proposed as a lead']; ?>
<?= view('shared/header.php', ['id' => 'email-unmatched', 'title' => 'Email', 'crumbs' => [['Today', '/'], ['Email', null]]]) ?>
<div class="main-content" id="email-unmatched-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 mb-3" id="email-doors">
        <?php if ($may['compose']): ?><?= hx_link('/email/compose', '<i class="feather-edit me-1"></i>New email', 'btn btn-primary btn-touch', 'id="email-compose-btn"') ?><?php endif; ?>
        <?= hx_link('/log/email', '<i class="feather-clipboard me-1"></i>Log an email', 'btn btn-light btn-touch', 'id="email-log-btn"') ?>
        <?= hx_link('/email/templates', '<i class="feather-file-text me-1"></i>Templates', 'btn btn-light btn-touch', 'id="email-templates-btn"') ?>
    </div>
    <?php if ($drafts !== []): ?>
    <div class="card mb-3" id="email-drafts"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Your drafts</h5><span class="badge bg-soft-secondary text-secondary"><?= count($drafts) ?></span></div>
        <div class="list-group list-group-flush"><?php foreach ($drafts as $d): ?>
            <div class="list-group-item d-flex align-items-center gap-2" id="email-draft-<?= (int) $d['id'] ?>">
                <span class="badge bg-soft-<?= $d['status'] === 'failed' ? 'danger text-danger' : 'secondary text-secondary' ?>"><?= $d['status'] === 'failed' ? 'not sent' : 'draft' ?></span>
                <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= hx_link('/email/compose?draft=' . (int) $d['id'] . '&back=' . rawurlencode('/email/unmatched'), e((string) ($d['subject'] ?: '(no subject)')), 'text-dark') ?></div>
                    <div class="fs-11 text-muted text-truncate">To <?= e((string) ($d['contact_name'] ?: $d['to_address'])) ?> · <?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?><?= $d['error'] ? ' · ' . e($d['error']) : '' ?></div></div>
            </div><?php endforeach; ?></div></div>
    <?php endif; ?>
    <div class="card" id="unmatched-list">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><h5 class="card-title mb-0">Mail nobody has claimed</h5>
            <div class="btn-group" role="group" aria-label="Show"><?php foreach ($tabs as $k => $label): $k = (string) $k; ?>
                <?= hx_link('/email/unmatched' . ($k !== '' ? '?status=' . $k : ''), e($label), 'btn btn-sm ' . ((string) $status === $k ? 'btn-primary' : 'btn-light'), 'id="unmatched-tab-' . ($k !== '' ? $k : 'all') . '"') ?><?php endforeach; ?></div></div>
        <?php if ($list['rows'] === []): ?>
            <div class="card-body fs-12 text-muted" id="unmatched-empty">Nothing is waiting. Copy the logging mailbox on an email, or let a contact reply to one you send, and anything the application cannot place lands here.</div>
        <?php else: ?>
            <div class="list-group list-group-flush"><?php foreach ($list['rows'] as $r) { echo view('email/partials/unmatched-row.php', ['r' => $r, 'tz' => $tz, 'may' => $may, 'back' => $back]); } ?></div>
            <?php if ($list['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center fs-12" id="unmatched-pager">
                <span>Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?> · <?= (int) $list['total'] ?> emails</span>
                <span><?php if ($list['page'] > 1): ?><?= hx_link('/email/unmatched?page=' . ($list['page'] - 1) . ($status ? '&status=' . $status : ''), 'Newer', 'btn btn-sm btn-light', 'id="unmatched-prev"') ?><?php endif; ?>
                      <?php if ($list['page'] < $list['pages']): ?><?= hx_link('/email/unmatched?page=' . ($list['page'] + 1) . ($status ? '&status=' . $status : ''), 'Older', 'btn btn-sm btn-light', 'id="unmatched-next"') ?><?php endif; ?></span></div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
