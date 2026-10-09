<?php /** The banner a finished action landed with (?notice=). Data: notice = [kind, message]|null, link? = [url, label] */
if (!empty($notice)): ?>
<div class="alert alert-<?= e($notice[0]) ?> mx-0 mb-3" role="status" id="notice-banner">
    <?= e($notice[1]) ?>
    <?php if (!empty($link)): ?> <?= hx_link($link[0], e($link[1]), 'alert-link') ?><?php endif; ?>
</div>
<?php endif; ?>
