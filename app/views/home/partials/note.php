<?php /** The Sales Assistant's morning note for today (a notification of kind morning_note; slice 8 makes the Assistant write it). Data: note */ ?>
<div class="alert alert-info d-flex gap-2 mb-3" id="home-note" role="note"><i class="feather-sunrise flex-shrink-0 mt-1"></i>
    <div class="min-w-0"><div class="fw-semibold mb-1">This morning</div><div class="fs-12" id="home-note-text"><?= nl2br(e($note)) ?></div></div>
</div>
