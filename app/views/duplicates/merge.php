<?php /** The merge (merge): two columns, every differing field marked, a radio per column choosing the survivor (the older by default). The survivor keeps its values and takes the
 * other's where empty; everything attached to the other moves to it. Data: kind, a, b, m (merge_preview), may */
$cols = ['a' => [$m['a'], $a], 'b' => [$m['b'], $b]]; ?>
<?= view('shared/header.php', ['id' => 'merge', 'title' => 'Merge two ' . strtolower(DUPLICATE_KINDS[$kind]), 'crumbs' => [['Today', '/'], ['Duplicates', '/duplicates?kind=' . $kind], ['Merge', null]], 'back' => ['/duplicates?kind=' . $kind, 'Duplicates']]) ?>
<div class="main-content" id="merge-content">
    <form method="post" action="/duplicates/merge.php" hx-post="/duplicates/merge.php" hx-target="#flash" hx-confirm="Merge them? The one not kept becomes a tombstone." id="merge-form">
        <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <div class="card mb-3"><div class="table-responsive"><table class="table mb-0 fs-12" id="merge-table">
            <thead class="thead-light"><tr><th class="w-25"></th>
                <?php foreach ($cols as $side => [$r, $rid]): ?><th id="merge-survivor-<?= $side ?>-head"><label class="d-flex align-items-center gap-2 btn-touch mb-0" for="merge-survivor-<?= $side ?>"><input type="radio" class="form-check-input mt-0" name="survivor" value="<?= (int) $rid ?>" id="merge-survivor-<?= $side ?>" <?= (int) $m['survivor'] === (int) $rid ? 'checked' : '' ?>>Keep this one</label></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($m['fields'] as $col => $label): $d = $m['differs'][$col]; ?>
                <tr id="merge-row-<?= e($col) ?>" class="<?= $d ? 'table-warning' : '' ?>"><th class="text-muted fw-normal"><?= e($label) ?><?= $d ? ' <span class="badge bg-soft-warning text-warning">differs</span>' : '' ?></th>
                    <?php foreach ($cols as $side => [$r, $rid]): ?><td class="text-break"><?= e($col === 'created_at' ? format_ts($r[$col], member_timezone(), 'M j, Y') : (string) ($r[$col] ?? '')) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <input type="hidden" name="a" value="<?= (int) $a ?>"><input type="hidden" name="b" value="<?= (int) $b ?>">
        <p class="fs-12 text-muted">The one kept keeps its own values and takes the other's only where it has none. Every email, activity, deal, note, file, tag and list the other has moves to it.</p>
        <?php if ($may['merge']): ?><button type="submit" class="btn btn-primary btn-touch" id="merge-form-save-btn"><i class="feather-git-merge me-1"></i>Merge</button>
        <?php else: ?><div class="alert alert-secondary fs-12" id="merge-not-allowed">A manager merges records.</div><?php endif; ?>
    </form>
</div>
