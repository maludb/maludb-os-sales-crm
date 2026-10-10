<?php /** A new import (import-new): the kind, the file, the columns (mapped in the browser from the header row when JavaScript is on), what to do with a match, the owner, the source,
 * a list — then the dry run. Data: kind, template, custom, sources, max_rows, me, may_shared */
$fields = IMPORT_FIELDS[$kind];
$clientFields = ['skip' => ['— skip —', []]] + $fields;
foreach ($custom as $c) { $clientFields['custom:' . $c['key']] = ['Custom: ' . $c['label'], [csv_norm((string) $c['key']), csv_norm((string) $c['label'])]]; }
$tplMap = $tpl['mapping_list'] ?? []; ?>
<?= view('shared/header.php', ['id' => 'import-new', 'title' => 'Import ' . strtolower(IMPORT_KINDS[$kind]), 'crumbs' => [['Today', '/'], ['Imports', '/import'], ['New', null]], 'back' => ['/import', 'Imports']]) ?>
<div class="main-content" id="import-new-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="import-kinds">
        <?php foreach (IMPORT_KINDS as $k => $label): ?><?= hx_link('/import/new?kind=' . $k, e($label), 'btn btn-touch btn-sm ' . ($k === $kind ? 'btn-primary' : 'btn-light'), 'id="import-kind-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <form method="post" action="/import/start.php" enctype="multipart/form-data" hx-post="/import/start.php" hx-encoding="multipart/form-data" hx-target="#flash" id="import-form" data-fields='<?= e(json_encode($clientFields, JSON_UNESCAPED_UNICODE)) ?>' data-template='<?= e(json_encode((object) $tplMap, JSON_UNESCAPED_UNICODE)) ?>'>
        <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="dry_run" value="yes">
        <?php if ($tpl !== null): ?><input type="hidden" name="template" value="<?= (int) $tpl['id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label" for="import-form-field-file">The CSV file</label>
            <input type="file" class="form-control btn-touch" name="file" id="import-form-field-file" accept=".csv,text/csv" required>
            <div class="form-text">A header row, then up to <?= number_format($max_rows) ?> rows; commas or semicolons; UTF-8 or Latin-1.<?= $tpl !== null ? ' Using the saved mapping <strong>' . e($tpl['name']) . '</strong>.' : '' ?></div>
        </div></div>
        <div class="card mb-3" id="import-mapping-step"><div class="card-header"><h5 class="card-title mb-0">Columns</h5></div><div class="card-body">
            <div class="fs-12 text-muted" id="import-mapping-hint">Each column is matched by its name<?= $tpl !== null ? ' and the saved mapping' : '' ?>; choose the file to see and change it here. The dry run shows the result before anything is written.</div>
            <div id="import-mapping-rows" class="row g-2 mt-1"></div>
        </div></div>
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
            <div class="col-12"><div class="form-label">When a row matches a record already here</div>
                <?php foreach (['skip' => 'Skip the row (and say which record it matched)', 'update' => 'Fill that record\'s empty fields', 'create' => 'Make a new record anyway'] as $p => $label): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="import-form-policy-<?= $p ?>"><input type="radio" class="form-check-input mt-0" name="dedupe_policy" value="<?= $p ?>" id="import-form-policy-<?= $p ?>" <?= ($tpl['dedupe_policy'] ?? 'skip') === $p ? 'checked' : '' ?>><?= e($label) ?></label>
                <?php endforeach; ?></div>
            <div class="col-md-6"><label class="form-label" id="import-form-owner-label" for="import-form-field-owner">Owner when a row names none</label>
                <?= picker_field(['id' => 'import-form-field-owner', 'name' => 'owner', 'source' => 'owner', 'value' => $me, 'labelledby' => 'import-form-owner-label', 'title' => 'Their owner']) ?></div>
            <?php if ($kind === 'leads_csv' || $kind === 'deals_csv'): ?>
            <div class="col-md-6"><label class="form-label" for="import-form-field-source">Source when a row names none</label><select name="source" class="form-select btn-touch" id="import-form-field-source"><option value="">Import</option>
                <?php foreach ($sources as $s): ?><option value="<?= (int) $s['source_id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label" id="import-form-list-label" for="import-form-field-list">Put what is made into a list</label>
                <?= picker_field(['id' => 'import-form-field-list', 'name' => 'list', 'source' => 'list', 'value' => null, 'labelledby' => 'import-form-list-label', 'title' => 'A static list', 'placeholder' => 'No list', 'params' => ['record_kind' => IMPORT_RECORD_KIND[$kind]]]) ?></div>
        </div></div></div>
        <button type="submit" class="btn btn-primary btn-touch mb-3" id="import-form-save-btn"><i class="feather-eye me-1"></i>Dry run</button>
    </form>
</div>
<script>
(function () {
  var form = document.getElementById('import-form'), input = document.getElementById('import-form-field-file'), box = document.getElementById('import-mapping-rows');
  if (!form || !input || !box || form.dataset.bound) return;
  form.dataset.bound = '1';
  var fields = JSON.parse(form.dataset.fields), tpl = JSON.parse(form.dataset.template);
  var norm = function (s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, ''); };
  var split = function (line, d) { var out = [], cur = '', q = false; for (var i = 0; i < line.length; i++) { var c = line[i];
      if (q) { if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; } else if (c === '"') { q = false; } else { cur += c; } }
      else if (c === '"') { q = true; } else if (c === d) { out.push(cur.trim()); cur = ''; } else { cur += c; } } out.push(cur.trim()); return out; };
  input.addEventListener('change', function () {
    var f = input.files && input.files[0]; if (!f) return;
    f.slice(0, 65536).text().then(function (t) {
      var line = t.replace(/^﻿/, '').split(/\r?\n/)[0] || '';
      var counts = { ',': line.split(',').length, ';': line.split(';').length, '\t': line.split('\t').length };
      var d = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; })[0];
      var headers = split(line, d), used = {};
      box.innerHTML = '';
      headers.forEach(function (h, i) {
        var n = norm(h), pick = tpl[h] || 'skip';
        if (!tpl[h]) { Object.keys(fields).some(function (k) { if (k !== 'skip' && !used[k] && (k === n || (k.indexOf('custom:') === 0 ? fields[k][1].indexOf(n) >= 0 : fields[k][1].indexOf(n) >= 0))) { pick = k; return true; } return false; }); }
        if (pick !== 'skip') used[pick] = true;
        var col = document.createElement('div'); col.className = 'col-md-6';
        var id = 'import-map-' + i, sel = '<select class="form-select btn-touch" id="' + id + '" name="mapping[' + h.replace(/"/g, '&quot;') + ']">';
        Object.keys(fields).forEach(function (k) { sel += '<option value="' + k + '"' + (k === pick ? ' selected' : '') + '>' + fields[k][0].replace(/</g, '&lt;') + '</option>'; });
        col.innerHTML = '<label class="form-label fs-12" for="' + id + '"></label>' + sel + '</select>';
        col.querySelector('label').textContent = h || '(no name)';
        box.appendChild(col);
      });
      document.getElementById('import-mapping-hint').textContent = headers.length + ' columns. Check what each becomes; the dry run shows the result before anything is written.';
    });
  });
})();
</script>
