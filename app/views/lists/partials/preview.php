<?php /** The builder's live count (#filter-preview). Data: n, kind */ ?>
<span id="filter-preview" class="fs-12"><span class="fw-semibold" id="filter-preview-count"><?= (int) $n ?></span> <?= e(RECORD_KINDS[$kind]['noun']) ?><?= (int) $n === 1 ? '' : 's' ?> match now</span>
