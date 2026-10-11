<?php /** One tick on a card or a row, a member of the bulk bar's form (form="bulk-bar"). Data: kind, id, label */ ?>
<input type="checkbox" class="form-check-input bulk-tick flex-shrink-0 mt-2" name="records[]" value="<?= (int) $id ?>" form="bulk-bar" id="bulk-tick-<?= e($kind) ?>-<?= (int) $id ?>" aria-label="Select <?= e($label) ?>">
