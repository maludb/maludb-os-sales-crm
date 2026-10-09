<?php /** Notes on a record: the form, then the notes newest first. Data: kind, id, notes, may (add, delete_any), me, tz, back */ ?>
<div class="card mb-3" id="notes-panel">
    <div class="card-header"><h5 class="card-title mb-0">Notes</h5></div>
    <div class="card-body">
        <?php if ($may['add']): ?>
        <form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" id="note-form" class="mb-3">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="record" value="<?= (int) $id ?>">
            <input type="hidden" name="return_to" value="<?= e($back) ?>">
            <label class="form-label visually-hidden" for="note-form-field-body">A note</label>
            <textarea name="body" id="note-form-field-body" class="form-control mb-2" rows="3" maxlength="10000" placeholder="What happened, what was agreed, what to remember…" required></textarea>
            <button type="submit" class="btn btn-primary btn-touch" id="note-form-save-btn"><i class="feather-plus me-1"></i>Add the note</button>
        </form>
        <?php endif; ?>
        <?php if ($notes === []): ?>
            <div class="fs-12 text-muted" id="notes-empty">No notes yet.</div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="notes-list">
            <?php foreach ($notes as $n): $nid = (int) $n['note_id']; ?>
                <div class="list-group-item px-0" id="note-<?= $nid ?>">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fs-12 text-muted"><?= e((string) ($n['author'] ?? 'Someone')) ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, Y g:i A')) ?></div>
                        <?php if ((int) $n['member_id'] === $me || $may['delete_any']): ?>
                        <form method="post" action="/notes/delete.php" hx-post="/notes/delete.php" hx-target="#flash" hx-confirm="Delete this note?"><?= csrf_field() ?>
                            <input type="hidden" name="note" value="<?= $nid ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" id="note-<?= $nid ?>-delete-btn" aria-label="Delete the note"><i class="feather-trash-2"></i></button></form>
                        <?php endif; ?>
                    </div>
                    <div class="text-break" style="white-space: pre-wrap"><?= e($n['body']) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
