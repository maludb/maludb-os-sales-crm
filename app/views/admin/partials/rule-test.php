<?php /** The test box's answer (admin-rules; id rule-test). Data: hit (rule_test() or null). Nothing was assigned. */ ?>
<div class="alert alert-<?= $hit === null ? 'warning' : 'info' ?> mb-0" id="rule-test" role="status">
    <?php if ($hit === null): ?>
        <strong>No rule matches.</strong> A lead like this would stay unowned, in the lead inbox.
    <?php else: ?>
        <?php $num = $hit['position'] % 10 === 0 ? intdiv($hit['position'], 10) : $hit['position']; ?><strong>Rule <?= $num ?>, <?= e($hit['rule_name']) ?></strong> matches
        — <?= e(RULE_KINDS[$hit['assign_kind']] ?? $hit['assign_kind']) ?>:
        <?= $hit['owner_member_id'] === null ? 'but it names no one (the department has no manager), so the lead would stay unowned' : 'the lead would be assigned to <strong>' . e((string) $hit['owner_name']) . '</strong>' ?>.
    <?php endif; ?>
    <div class="fs-12 text-muted mt-1" id="rule-test-note">This was only a test: nothing was assigned and no turn moved.</div>
</div>
