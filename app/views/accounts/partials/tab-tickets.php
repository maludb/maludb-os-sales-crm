<?php /** Tickets: Help Desk's tickets for this company through K7 (helpdesk.tickets_by_organization, cached five minutes; a failing read shows words, never an error). Data: a, tickets ({status, rows}) */ ?>
<div class="card" id="account-tickets"><div class="card-body">
<?php if ($tickets['status'] === 'no_connection'): ?>
    <div class="empty-state" id="account-tickets-no-connection"><span class="avatar-text avatar-lg rounded"><i class="feather-life-buoy"></i></span>
        <div><div class="fw-semibold">Help Desk is not connected</div><div class="fs-12 text-muted">When a super-admin connects Help Desk to Sales CRM, this company's support tickets show here.</div></div></div>
<?php elseif ($tickets['status'] === 'unavailable'): ?>
    <div class="fs-12 text-muted" id="account-tickets-unavailable"><i class="feather-alert-circle me-1"></i>Help Desk did not answer just now, so this company's tickets are not shown. Try again in a minute.</div>
<?php elseif ($tickets['rows'] === []): ?>
    <div class="fs-12 text-muted" id="account-tickets-empty">No tickets for this company.</div>
<?php else: ?>
    <div class="list-group list-group-flush" id="account-tickets-list">
    <?php foreach ($tickets['rows'] as $t): ?><div class="list-group-item px-0 fs-12"><span class="fw-semibold"><?= e((string) ($t['number'] ?? '')) ?></span> <?= e((string) ($t['subject'] ?? '')) ?> <span class="badge bg-soft-secondary text-secondary"><?= e((string) ($t['status'] ?? '')) ?></span></div><?php endforeach; ?>
    </div>
<?php endif; ?>
</div></div>
