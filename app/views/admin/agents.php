<?php /** Agents (screen `admin-agents`; ids agents-table, dispatches-table, dispatch-{id}, agents-settings-form). Data: agents (agents_here), dispatches (find_dispatches), settings (agent_settings), filters, tz, notice */
$q = static fn (array $over) => '/admin/agents?' . http_build_query(array_filter(array_merge(['status' => $filters['status'], 'kind' => $filters['kind'], 'agent' => $filters['agent']], $over), static fn ($v) => $v !== null && $v !== ''));
$chip = static fn (string $label, array $over, bool $on, string $id) => hx_link($q($over), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="' . $id . '"'); ?>
<?= view('shared/header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Agents', null]]]) ?>
<div class="main-content" id="admin-agents-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-3" id="agents-card"><div class="card-header"><h5 class="card-title mb-0">The agents working here</h5></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="agents-table">
                    <thead class="thead-light"><tr><th>Agent</th><th class="d-none d-xxl-table-cell">Role</th><th>Last woken</th><th class="text-end">Waiting</th><th class="text-end d-none d-md-table-cell">Answered this week</th><th class="text-end d-none d-md-table-cell">Proposed this week</th></tr></thead>
                    <tbody>
                    <?php if ($agents === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="agents-empty">No agent has been granted a role here yet. A super-admin does that in the kernel.</td></tr><?php endif; ?>
                    <?php foreach ($agents as $a): $id = (int) $a['member_id']; ?>
                        <tr id="agent-<?= $id ?>">
                            <td><div class="fw-semibold"><?= e($a['display_name']) ?></div><div class="text-muted"><?= e((string) $a['job_title']) ?></div></td>
                            <td class="d-none d-xxl-table-cell"><?= e(implode(', ', $a['roles'])) ?></td>
                            <td><?= $a['last_at'] ? e(format_ts($a['last_at'], $tz, 'M j, g:i A')) . '<div class="text-muted">' . e(DISPATCH_KINDS[$a['last_kind']] ?? (string) $a['last_kind']) . '</div>' : '<span class="text-muted">never</span>' ?></td>
                            <td class="text-end"><?= (int) $a['waiting'] ?></td>
                            <td class="text-end d-none d-md-table-cell"><?= (int) $a['answered_week'] ?></td>
                            <td class="text-end d-none d-md-table-cell"><?= (int) $a['proposals_week'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div></div></div>
            <div class="card mb-0" id="dispatches-card"><div class="card-header"><h5 class="card-title mb-0">Dispatches</h5></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-1 mb-3" id="dispatches-filters">
                        <?= $chip('Any', ['status' => null], $filters['status'] === null, 'dispatches-status-all') ?>
                        <?php foreach (DISPATCH_STATUS as $k => [$label]): ?><?= $chip($label, ['status' => $k], $filters['status'] === $k, 'dispatches-status-' . $k) ?><?php endforeach; ?>
                    </div>
                    <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="dispatches-table">
                        <thead class="thead-light"><tr><th>When</th><th>What</th><th class="d-none d-md-table-cell">Agent</th><th>Status</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        <?php if ($dispatches === []): ?><tr><td colspan="5" class="text-center text-muted py-4" id="dispatches-empty">No dispatches. A lead from the website's form, a deal entering a stage that asks the Assistant, or an agent's reminder shows here.</td></tr><?php endif; ?>
                        <?php foreach ($dispatches as $d): $p = present_dispatch($d); $id = $p['dispatch_id']; $url = $p['deal_id'] ? '/deals/' . $p['deal_id'] : ($p['lead_id'] ? '/leads/' . $p['lead_id'] : null); ?>
                            <tr id="dispatch-<?= $id ?>">
                                <td class="text-nowrap"><?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></td>
                                <td><?= $url ? hx_link($url, e($p['kind_label']), 'fw-semibold') : '<span class="fw-semibold">' . e($p['kind_label']) . '</span>' ?>
                                    <?php if ($p['reply_excerpt']): ?><div class="text-muted" id="dispatch-<?= $id ?>-excerpt"><?= e($p['reply_excerpt']) ?></div><?php endif; ?>
                                    <?php if ($p['detail'] && !in_array($p['status'], ['answered'], true)): ?><div class="text-muted"><?= e($p['detail']) ?></div><?php endif; ?></td>
                                <td class="d-none d-md-table-cell"><?= e((string) $p['agent_name']) ?><div class="text-muted"><?= $p['acting_name'] ? 'for ' . e($p['acting_name']) : '' ?></div></td>
                                <td><span class="badge bg-soft-<?= e($p['tone']) ?> text-<?= e($p['tone']) ?>" id="dispatch-<?= $id ?>-status"><?= e($p['status_label']) ?></span><?= $p['attempts'] > 1 && $p['status'] === 'sent' ? '<div class="text-muted">try ' . $p['attempts'] . '</div>' : '' ?></td>
                                <td class="text-end"><?php if (in_array($p['status'], ['sent', 'awaiting_approval'], true)): ?>
                                    <form method="post" action="/admin/agents/dispatch-cancel.php" hx-post="/admin/agents/dispatch-cancel.php" hx-confirm="Cancel this dispatch? The agent will not be woken for it." hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $id ?>">
                                        <button type="submit" class="avatar-text avatar-sm border-0" title="Cancel" id="dispatch-<?= $id ?>-cancel" aria-label="Cancel dispatch <?= $id ?>"><i class="feather-x"></i></button></form><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div></div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-0" id="agents-settings-card"><div class="card-header"><h5 class="card-title mb-0">The Sales Assistant's settings</h5></div><div class="card-body">
                <form method="post" action="/admin/agents/save.php" id="agents-settings-form" hx-post="/admin/agents/save.php" hx-target="#page-content" hx-swap="innerHTML">
                    <?= csrf_field() ?>
                    <input type="hidden" name="morning_note_enabled" value="no"><input type="hidden" name="assistant_proposals" value="no"><input type="hidden" name="ask_assistant_stages_present" value="1">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="morning_note_enabled" value="yes" id="agents-settings-field-morning" <?= $settings['morning_note_enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="agents-settings-field-morning">Write each seller a morning note on weekdays</label></div>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="agents-settings-field-hour">Morning note hour (0 to 23, in each person's zone)</label>
                        <input type="number" name="morning_note_hour" id="agents-settings-field-hour" class="form-control btn-touch" min="0" max="23" value="<?= (int) $settings['morning_note_hour'] ?>"></div>
                    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="assistant_proposals" value="yes" id="agents-settings-field-proposals" <?= $settings['assistant_proposals'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="agents-settings-field-proposals">Propose next steps, qualifications and duplicates</label></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="agents-settings-field-lead-days">Name a lead after (days)</label><input type="number" name="lead_untouched_days" id="agents-settings-field-lead-days" class="form-control btn-touch" min="1" max="365" value="<?= (int) $settings['lead_untouched_days'] ?>"></div>
                        <div class="col-6"><label class="form-label fs-12 text-muted" for="agents-settings-field-account-days">Name an account after (days)</label><input type="number" name="account_untouched_days" id="agents-settings-field-account-days" class="form-control btn-touch" min="1" max="3650" value="<?= (int) $settings['account_untouched_days'] ?>"></div>
                    </div>
                    <div class="fs-12 text-muted mb-1">Ask the Assistant for a next step when a deal enters</div>
                    <div class="mb-3" id="agents-settings-stages">
                        <?php if ($settings['stages'] === []): ?><div class="fs-12 text-muted">No open stages.</div><?php endif; ?>
                        <?php foreach ($settings['stages'] as $s): ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="ask_assistant_stages[]" value="<?= (int) $s['stage_id'] ?>" id="agents-settings-stage-<?= (int) $s['stage_id'] ?>" <?= $s['ask_assistant'] ? 'checked' : '' ?>>
                                <label class="form-check-label fs-12" for="agents-settings-stage-<?= (int) $s['stage_id'] ?>"><?= e($s['name']) ?> <span class="text-muted">· <?= e($s['pipeline']) ?></span></label></div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="agents-settings-save-btn"><i class="feather-save me-1"></i>Save</button>
                </form>
            </div></div>
        </div>
    </div>
</div>
