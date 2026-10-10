<?php /** The mapping an import used, and saving it as a template for the next file. Data: i */
$m = json_decode((string) $i['mapping'], true) ?: [];
$fields = IMPORT_FIELDS[$i['kind']]; ?>
<div class="card" id="import-mapping"><div class="card-header"><h5 class="card-title mb-0">Columns</h5></div>
    <div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>In the file</th><th>Became</th></tr></thead><tbody>
    <?php foreach ($m as $h => $f): ?><tr><td><?= e((string) $h) ?></td><td class="<?= $f === 'skip' ? 'text-muted' : '' ?>"><?= e($f === 'skip' ? 'skipped' : (str_starts_with((string) $f, 'custom:') ? 'custom field ' . substr((string) $f, 7) : ($fields[$f][0] ?? $f))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <form method="post" action="/import/template-save.php" hx-post="/import/template-save.php" hx-target="#flash" class="card-footer d-flex flex-wrap gap-2 align-items-center" id="import-template-form"><?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($i['kind']) ?>"><input type="hidden" name="mapping" value="<?= e(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>"><input type="hidden" name="dedupe_policy" value="<?= e($i['dedupe_policy']) ?>">
        <input type="hidden" name="return_to" value="/import/<?= (int) $i['import_id'] ?>?notice=template_saved">
        <label class="visually-hidden" for="import-template-field-name">The template's name</label>
        <input type="text" name="name" class="form-control btn-touch flex-grow-1" style="min-width: 10rem" id="import-template-field-name" maxlength="120" placeholder="Save as a template…" required>
        <button type="submit" class="btn btn-light btn-touch" id="import-template-save-btn">Save</button>
    </form>
</div>
