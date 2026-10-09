<?php /** Search (screen `search`). Data: q, kinds, page, result {rows, total}, tooShort. Results grouped by kind in the order they rank. */
$groups = [];
foreach ($result['rows'] as $r) { $groups[$r['entity_kind']][] = $r; }
$chip = static function (string $k, string $label) use ($q, $kinds): string {
    $on = $k === '' ? $kinds === [] : $kinds === [$k];
    $url = '/search?' . http_build_query(array_filter(['q' => $q, 'kinds' => $k === '' ? null : [$k]]));
    return hx_link($url, e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="search-kind-' . e($k === '' ? 'all' : $k) . '"');
};
$more = $page * SEARCH_PAGE < $result['total'];
?>
<?= view('shared/header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Today', '/'], ['Search', null]]]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" class="mb-3" id="search-form" hx-get="/search" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" role="search">
        <?php foreach ($kinds as $k): ?><input type="hidden" name="kinds[]" value="<?= e($k) ?>"><?php endforeach; ?>
        <div class="input-group">
            <input type="search" name="q" id="search-field-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="A name, a company, an email, a deal number…" maxlength="200" autocomplete="off" aria-label="Search">
            <button type="submit" class="btn btn-primary btn-touch" id="search-submit-btn" aria-label="Search"><i class="feather-search"></i></button>
        </div>
        <div class="fs-11 text-muted mt-1">"exact words", -leave-out, or a name close enough. You see only what you may open.</div>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="search-kinds">
        <?= $chip('', 'Everything') ?><?php foreach (SEARCH_KINDS as $k => $label): ?><?= $chip($k, $label) ?><?php endforeach; ?>
    </div>
    <?php if ($tooShort): ?>
        <div class="alert alert-warning" id="search-too-short">Type at least two characters.</div>
    <?php elseif ($q === ''): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div><div class="fw-semibold">Find anyone and anything</div><div class="fs-12 text-muted">Leads, accounts, contacts and deals by name, company, email, web domain, deal number or tag.</div></div></div></div></div>
    <?php elseif ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-none"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div><div class="fw-semibold">Nothing matches “<?= e($q) ?>”</div><div class="fs-12 text-muted">Try fewer words, or part of a name.</div></div></div></div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="search-total"><?= (int) $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></div>
        <?php foreach (SEARCH_KINDS as $k => $label): if (empty($groups[$k])) { continue; } ?>
            <div class="card mb-3" id="search-group-<?= e($k) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($label) ?></h5></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($groups[$k] as $r): ?><?= view('search/partials/result.php', ['r' => $r, 'q' => $q]) ?><?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($page > 1 || $more): ?>
            <nav id="search-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): $u = '/search?' . http_build_query(['q' => $q, 'kinds' => $kinds ?: null, 'page' => $page - 1]); ?><li class="page-item"><?= hx_link($u, 'Previous', 'page-link') ?></li><?php endif; ?>
                <?php if ($more): $u = '/search?' . http_build_query(['q' => $q, 'kinds' => $kinds ?: null, 'page' => $page + 1]); ?><li class="page-item"><?= hx_link($u, 'Next', 'page-link', 'id="search-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
