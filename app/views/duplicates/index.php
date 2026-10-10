<?php /** Duplicates (duplicates): the kinds as chips, then each pair with what matched highlighted — Merge · Not a duplicate. Data: kind, pairs, may, notice */
$hl = static fn (array $p, string $side): array => [
    'email' => $p['matched_on'] === 'email' ? 'mark' : '', 'domain' => $p['matched_on'] === 'domain' ? 'mark' : '', 'phone' => $p['matched_on'] === 'phone' ? 'mark' : '', 'name' => $p['matched_on'] === 'name' ? 'mark' : ''];
$val = static function (string $kind, array $r, string $field, string $mark): string {
    $v = (string) ($r[$field] ?? '');
    if ($v === '') { return ''; }
    return $mark === 'mark' ? '<mark>' . e($v) . '</mark>' : e($v);
}; ?>
<?= view('shared/header.php', ['id' => 'duplicates', 'title' => 'Duplicates', 'crumbs' => [['Today', '/'], ['Duplicates', null]]]) ?>
<div class="main-content" id="duplicates-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="duplicates-kinds">
        <?php foreach (DUPLICATE_KINDS as $k => $label): ?><?= hx_link('/duplicates?kind=' . $k, e($label), 'btn btn-touch btn-sm ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="duplicates-kind-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($pairs === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="duplicates-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-copy"></i></span><div><div class="fw-semibold">No possible duplicates</div><div class="fs-12 text-muted">Records with the same email, domain or phone, or a name alike, appear here.</div></div></div></div></div>
    <?php else: ?>
    <div class="d-flex flex-column gap-2" id="duplicates-list">
    <?php foreach ($pairs as $p): $a = $p['a']; $b = $p['b']; $id = $p['a_id'] . '-' . $p['b_id']; $h = $hl($p, 'a'); ?>
        <div class="card mb-0" id="duplicates-pair-<?= e($id) ?>"><div class="card-body">
            <div class="fs-12 text-muted mb-2">The same <?= e($p['matched_on'] === 'name' ? 'name, nearly (' . (int) round($p['similarity'] * 100) . '%)' : $p['matched_on']) ?></div>
            <div class="row g-2">
            <?php foreach (['a' => [$a, $p['a_id']], 'b' => [$b, $p['b_id']]] as $side => [$r, $rid]): ?>
                <div class="col-md-6"><div class="border rounded p-2 h-100" id="duplicates-pair-<?= e($id) ?>-<?= $side ?>">
                    <div class="fw-semibold"><?= hx_link(RECORD_KINDS[$kind]['url'] . $rid, ($h['name'] ? '<mark>' : '') . e(duplicate_name($kind, $r)) . ($h['name'] ? '</mark>' : ''), 'text-dark') ?></div>
                    <div class="fs-12 text-muted text-break"><?= implode(' · ', array_filter([$val($kind, $r, 'email', $h['email']), $val($kind, $r, 'domain', $h['domain']), $val($kind, $r, 'phone', $h['phone']), e((string) ($r['owner_name'] ?? ''))])) ?></div>
                </div></div>
            <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <?php if ($may['merge']): ?><?= hx_link('/duplicates/merge?' . http_build_query(['kind' => $kind, 'a' => $p['a_id'], 'b' => $p['b_id']]), '<i class="feather-git-merge me-1"></i>Merge', 'btn btn-primary btn-sm btn-touch', 'id="duplicates-pair-' . $id . '-merge"') ?><?php endif; ?>
                <form method="post" action="/duplicates/dismiss.php" hx-post="/duplicates/dismiss.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="a" value="<?= (int) $p['a_id'] ?>"><input type="hidden" name="b" value="<?= (int) $p['b_id'] ?>">
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="duplicates-pair-<?= e($id) ?>-dismiss">Not a duplicate</button></form>
            </div>
        </div></div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
