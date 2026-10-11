<?php /** The bulk bar (shared/bulk-bar.php — every list screen and a list's page): ticks on the cards (form="bulk-bar"), "selected" or "all N" (the screen's filters, or the list), and the actions. One form;
 * each button posts it to its own handler (formaction), so it works with JavaScript off. Data: kind, filters_json (the screen's filters; null on a list's page), list (its id or null), total, may, fields, back, limit */
$noun = RECORD_KINDS[$kind]['noun'];
$nouns = $noun . 's'; ?>
<form method="post" action="/bulk/tag.php" id="bulk-bar" class="card mb-3" data-kind="<?= e($kind) ?>" data-total="<?= (int) $total ?>" data-limit="<?= (int) $limit ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
    <?php if ($list !== null): ?><input type="hidden" name="list" value="<?= (int) $list ?>"><?php else: ?><input type="hidden" name="filters" value="<?= e((string) $filters_json) ?>"><?php endif; ?>
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center fs-12">
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="bulk-select-all" aria-controls="bulk-count"><label class="form-check-label" for="bulk-select-all">Select this page</label></div>
            <span class="fw-semibold" id="bulk-count" aria-live="polite">0 selected</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Act on" id="bulk-scope">
                <input type="radio" class="btn-check" name="scope" id="bulk-scope-selected" value="selected" checked><label class="btn btn-outline-primary" for="bulk-scope-selected">The ticked <?= e($nouns) ?></label>
                <input type="radio" class="btn-check" name="scope" id="bulk-scope-all" value="all"><label class="btn btn-outline-primary" for="bulk-scope-all" id="bulk-scope-all-label"><?= $list !== null ? 'The whole list' : 'All ' . (int) $total . ' matching' ?></label>
            </div>
            <?php if ((int) $total > (int) $limit): ?><span class="text-danger" id="bulk-over-limit">More than <?= number_format((int) $limit) ?>: narrow it to act on all.</span><?php endif; ?>
        </div>
        <details class="mt-2" id="bulk-actions"><summary class="fs-12 fw-semibold" id="bulk-actions-summary">Do something with them</summary>
            <div class="row g-3 mt-1">
                <?php if ($may['assign']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" id="bulk-owner-label" for="bulk-owner">Give to</label>
                    <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'bulk-owner', 'name' => 'owner', 'source' => 'owner', 'labelledby' => 'bulk-owner-label', 'title' => 'A person or an agent', 'placeholder' => 'Choose an owner']) ?></div>
                        <button type="submit" class="btn btn-light btn-touch" formaction="/bulk/assign.php" id="bulk-assign-btn">Assign</button></div></div><?php endif; ?>
                <?php if ($may['tag']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" for="bulk-tag">Tag</label>
                    <div class="d-flex flex-wrap gap-2"><input type="text" name="tag" id="bulk-tag" class="form-control btn-touch flex-grow-1" maxlength="60" placeholder="a tag's name" autocomplete="off">
                        <div class="form-check align-self-center mb-0"><input class="form-check-input" type="checkbox" name="remove" value="yes" id="bulk-tag-remove"><label class="form-check-label fs-12" for="bulk-tag-remove">take it off</label></div>
                        <button type="submit" class="btn btn-light btn-touch" formaction="/bulk/tag.php" id="bulk-tag-btn">Tag</button></div></div><?php endif; ?>
                <?php if ($may['update'] && $fields !== []): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" for="bulk-field">Set a field</label>
                    <div class="d-flex flex-wrap gap-2"><select name="field" id="bulk-field" class="form-select btn-touch w-auto"><?php foreach ($fields as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                        <input type="text" name="value" id="bulk-value" class="form-control btn-touch flex-grow-1" maxlength="200" placeholder="the new value (empty clears it)" aria-label="The new value">
                        <button type="submit" class="btn btn-light btn-touch" formaction="/bulk/update.php" id="bulk-update-btn">Set</button></div>
                    <div class="form-text">A source or a campaign takes its id; a date is year-month-day.</div></div><?php endif; ?>
                <?php if ($may['campaign']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" id="bulk-campaign-label" for="bulk-campaign">Add to a campaign</label>
                    <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'bulk-campaign', 'name' => 'campaign', 'source' => 'campaign', 'labelledby' => 'bulk-campaign-label', 'title' => 'A campaign', 'placeholder' => 'Choose a campaign']) ?></div>
                        <button type="submit" class="btn btn-light btn-touch" formaction="/bulk/campaign.php" id="bulk-campaign-btn">Add</button></div></div><?php endif; ?>
                <?php if ($may['list']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" id="bulk-list-label" for="bulk-target-list">Add to a list</label>
                    <div class="d-flex gap-2"><div class="flex-grow-1"><?= picker_field(['id' => 'bulk-target-list', 'name' => 'target_list', 'source' => 'list', 'params' => ['writable' => 1], 'labelledby' => 'bulk-list-label', 'title' => 'A static list of ' . $nouns, 'placeholder' => 'Choose a list']) ?></div>
                        <button type="submit" class="btn btn-light btn-touch" formaction="/bulk/list.php" id="bulk-list-btn">Add</button></div></div><?php endif; ?>
                <?php if ($may['export']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12" for="bulk-format">Export all <?= $list !== null ? 'in the list' : 'matching' ?></label>
                    <div class="d-flex gap-2"><select name="format" id="bulk-format" class="form-select btn-touch w-auto"><option value="csv">CSV</option><option value="json">JSON</option></select>
                        <input type="hidden" name="record_kind" value="<?= e($kind) ?>">
                        <button type="submit" class="btn btn-light btn-touch" formaction="/exports/create.php" id="bulk-export-btn"><i class="feather-download me-1"></i>Export</button></div></div><?php endif; ?>
                <?php if ($may['delete']): ?><div class="col-12 col-lg-6"><label class="form-label fs-12 text-danger">Delete</label>
                    <div><button type="submit" class="btn btn-light text-danger btn-touch" formaction="/bulk/delete.php" id="bulk-delete-btn" data-confirm="Delete them? They can be restored for a while."><i class="feather-trash-2 me-1"></i>Delete</button></div></div><?php endif; ?>
            </div>
        </details>
    </div>
</form>
