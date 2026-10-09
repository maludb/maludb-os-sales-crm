<?php /** Data: kind (success|danger|warning|info), message */ ?>
<div class="alert alert-<?= e($kind ?? 'info') ?> alert-dismissible m-3" role="alert" id="flash-message">
    <?= e($message ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
