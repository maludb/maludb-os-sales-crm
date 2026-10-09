<?php /** Overview: the fields, every address, the custom fields, the next step. Data: c, addresses, custom, next, tz */
$field = static fn (string $label, ?string $value, string $id) => '<div class="col-sm-6 mb-2" id="contact-field-' . $id . '"><div class="fs-11 text-muted">' . e($label) . '</div><div class="text-break">' . ($value === null || $value === '' ? '<span class="text-muted">—</span>' : e($value)) . '</div></div>';
$words = ['overdue' => 'Overdue', 'today' => 'Today', 'scheduled' => 'Planned']; ?>
<div class="row g-3">
    <div class="col-lg-8"><div class="card mb-3" id="contact-overview-fields"><div class="card-body"><div class="row">
        <?= $field('Lifecycle', CONTACT_LIFECYCLES[$c['lifecycle']] ?? $c['lifecycle'], 'lifecycle') ?>
        <?= $field('Role', $c['role'] ? (CONTACT_ROLES[$c['role']] ?? $c['role']) : null, 'role') ?>
        <?= $field('Phone', $c['phone'], 'phone') ?>
        <?= $field('Mobile', $c['mobile'], 'mobile') ?>
        <?= $field('Department', $c['department_text'], 'department') ?>
        <?= $field('Prefers', $c['preferred_channel'] ? (CONTACT_CHANNELS[$c['preferred_channel']] ?? $c['preferred_channel']) : null, 'preferred_channel') ?>
        <?= $field('Owner', $c['owner_name'], 'owner') ?>
        <?= $field('Source', $c['source_name'], 'source') ?>
        <?= $field('Birthday', $c['birthday'] ? (new DateTimeImmutable((string) $c['birthday']))->format('F j') : null, 'birthday') ?>
        <?= $field('Time zone', $c['timezone'], 'timezone') ?>
        <?= $field('Where', trim(implode(', ', array_filter([$c['city'], $c['region'], $c['country']]))), 'where') ?>
        <div class="col-sm-6 mb-2" id="contact-field-linkedin"><div class="fs-11 text-muted">LinkedIn</div><div class="text-break"><?= $c['linkedin_url'] ? '<a href="' . e($c['linkedin_url']) . '" target="_blank" rel="noopener noreferrer">' . e($c['linkedin_url']) . '</a>' : '<span class="text-muted">—</span>' ?></div></div>
        <?php if ($c['do_not_call']): ?><div class="col-12 mb-2" id="contact-field-do-not-call"><span class="badge text-bg-warning">Do not call</span></div><?php endif; ?>
        <?php foreach ($custom as $label => $value): ?><?= $field($label, $value, 'custom-' . md5($label)) ?><?php endforeach; ?>
    </div></div></div></div>
    <div class="col-lg-4">
        <div class="card mb-3" id="contact-next-step"><div class="card-body">
            <div class="fs-12 text-muted mb-1">Next step</div>
            <?php if ($next === null): ?><span class="next-chip next-none">No next step</span>
            <?php else: ?><div class="fw-semibold text-truncate"><i class="<?= e(activity_kind_icon((string) $next['kind'])) ?> text-muted me-1"></i><?= e($next['subject']) ?></div>
                <div class="d-flex align-items-center gap-2 mt-1"><span class="next-chip next-<?= e($next['state']) ?>"><?= e($words[$next['state']] ?? $next['state']) ?></span><span class="fs-12 text-muted"><?= e(format_ts($next['at'], $tz, 'D M j, g:i A')) ?></span></div><?php endif; ?>
        </div></div>
        <div class="card mb-3" id="contact-addresses"><div class="card-body">
            <div class="fs-12 text-muted mb-1">Email addresses</div>
            <?php if ($addresses === [] && !$c['email']): ?><div class="fs-12 text-muted">None.</div><?php endif; ?>
            <?php foreach ($addresses as $ad): ?><div class="text-break" id="contact-address-<?= (int) $ad['contact_email_id'] ?>"><?= e($ad['address']) ?><?= $ad['is_primary'] ? ' <span class="badge bg-soft-primary text-primary">primary</span>' : '' ?></div><?php endforeach; ?>
            <?php if ($c['email'] && !in_array(strtolower((string) $c['email']), array_map('strtolower', array_column($addresses, 'address')), true)): ?><div class="text-break" id="contact-address-shared"><?= e($c['email']) ?> <span class="badge bg-soft-warning text-warning" title="Another contact holds this address too">shared</span></div><?php endif; ?>
            <?php if ($c['email_alt']): ?><div class="text-break fs-12 text-muted">also <?= e($c['email_alt']) ?></div><?php endif; ?>
        </div></div>
    </div>
</div>
