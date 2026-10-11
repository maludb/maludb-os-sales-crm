<?php /** The outcome as large buttons (#outcome-{value}), the kind's own; the default first and chosen. Data: kind, selected? */
$selected = $selected ?? ACTIVITY_OUTCOMES[$kind][0]; ?>
<div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Outcome" id="outcome-buttons">
    <?php foreach (ACTIVITY_OUTCOMES[$kind] as $o): ?>
        <input type="radio" class="btn-check" name="outcome" id="outcome-<?= e($o) ?>" value="<?= e($o) ?>"<?= $o === $selected ? ' checked' : '' ?>>
        <label class="btn btn-outline-primary btn-lg outcome-btn" for="outcome-<?= e($o) ?>"><?= e(OUTCOME_WORDS[$o]) ?></label>
    <?php endforeach; ?>
</div>
