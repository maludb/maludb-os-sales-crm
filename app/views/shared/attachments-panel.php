<?php /** Files on a record: the upload (a plain multipart form), then the list. Data: kind, id, files, may (add, delete_any), me, tz, back, max_mb */ ?>
<div class="card mb-3" id="attachments-panel">
    <div class="card-header"><h5 class="card-title mb-0">Files</h5></div>
    <div class="card-body">
        <?php if ($may['add']): ?>
        <form method="post" action="/attachments/add.php" enctype="multipart/form-data" id="attachment-form" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>">
            <input type="hidden" name="return_to" value="<?= e($back) ?>">
            <label class="visually-hidden" for="attachment-form-field-file">A file</label>
            <input type="file" name="file" id="attachment-form-field-file" class="form-control btn-touch flex-grow-1" style="min-width: 0" required>
            <button type="submit" class="btn btn-light btn-touch" id="attachment-form-save-btn"><i class="feather-paperclip me-1"></i>Attach</button>
            <div class="fs-11 text-muted w-100">Up to <?= (int) $max_mb ?> MB. Programs cannot be attached.</div>
        </form>
        <?php endif; ?>
        <?php if ($files === []): ?>
            <div class="fs-12 text-muted" id="attachments-empty">No files yet.</div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="attachments-list">
            <?php foreach ($files as $f): $fid = (int) $f['attachment_id']; ?>
                <div class="list-group-item px-0 d-flex align-items-center gap-2" id="attachment-<?= $fid ?>">
                    <i class="feather-file text-muted"></i>
                    <div class="min-w-0 flex-grow-1">
                        <a href="/attachments/<?= $fid ?>" class="fw-semibold text-dark text-truncate d-block" id="attachment-<?= $fid ?>-link"><?= e($f['filename']) ?></a>
                        <div class="fs-11 text-muted"><?= e(bytes_label((int) $f['byte_size'])) ?> · <?= e((string) ($f['uploader'] ?? '')) ?> · <?= e(format_ts($f['created_at'], $tz, 'M j, Y')) ?></div>
                    </div>
                    <?php if ((int) $f['uploaded_by'] === $me || $may['delete_any']): ?>
                    <form method="post" action="/attachments/delete.php" hx-post="/attachments/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($f['filename']) ?>?"><?= csrf_field() ?>
                        <input type="hidden" name="attachment" value="<?= $fid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                        <button type="submit" class="btn btn-link btn-sm text-danger p-0" id="attachment-<?= $fid ?>-delete-btn" aria-label="Delete the file"><i class="feather-trash-2"></i></button></form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
