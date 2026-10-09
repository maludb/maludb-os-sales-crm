<?php
/** One exchange from the kernel's chat endpoint. Data: reply?, status?, actions?, approval?, finished?, navigate?, error? */
$actions = $actions ?? [];
$reply = $reply ?? '';
?>
<div class="card mb-0" id="assistant-reply-card">
    <div class="card-body py-2 px-3">
        <?php if (!empty($error)): ?>
            <div class="text-danger" id="assistant-reply-error"><i class="feather-alert-circle me-1"></i><?= e($error) ?></div>
        <?php else: ?>
            <div id="assistant-reply-text"><?= nl2br(e($reply !== '' ? $reply : 'Done.')) ?></div>
            <?php if (($status ?? '') === 'awaiting_approval'): ?>
                <div class="text-warning fs-12 mt-1" id="assistant-reply-approval"><i class="feather-clock me-1"></i>Waits for a person's approval<?= !empty($approval) ? ' (request #' . (int) $approval . ')' : '' ?>.</div>
            <?php elseif (($status ?? '') === 'running' || empty($finished)): ?>
                <div class="text-muted fs-12 mt-1"><i class="feather-loader me-1"></i>Still working — ask again in a moment for the result.</div>
            <?php endif; ?>
            <?php if (!empty($navigate)): ?><div class="fs-12 mt-1" id="assistant-reply-navigate"><?= hx_link($navigate, '<i class="feather-arrow-right me-1"></i>Opening ' . e($navigate), 'fw-semibold') ?></div><?php endif; ?>
            <?php if ($actions !== []): ?>
                <ul class="list-unstyled fs-12 text-muted mb-0 mt-1" id="assistant-reply-actions">
                    <?php foreach ($actions as $a): ?>
                        <li><i class="feather-<?= ($a['status'] ?? '') === 'ok' ? 'check text-success' : (($a['status'] ?? '') === 'awaiting_approval' ? 'clock text-warning' : 'x text-danger') ?> me-1"></i><?= e($a['tool'] ?? '') ?><?= ($a['status'] ?? '') === 'awaiting_approval' ? ' — waits for approval' : '' ?><?= !empty($a['record_id']) ? ' #' . (int) $a['record_id'] : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
