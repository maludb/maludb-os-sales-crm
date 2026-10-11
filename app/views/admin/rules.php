<?php /** Assignment rules (screen `admin-rules`; ids rules-form, rules-list, rule-row-{n}, rule-test, rules-save-btn). Data: rules (rules()), sources (active lead sources), departments, notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-rules', 'title' => 'Assignment rules', 'crumbs' => [['Today', '/'], ['Admin', '/admin'], ['Assignment rules', null]]]) ?>
<div class="main-content" id="admin-rules-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="alert alert-light border fs-12" id="rules-help">A new lead goes to the <strong>first rule in use</strong> that it matches, top to bottom; a lead no rule matches waits, unowned, in the lead inbox. Saving replaces the whole list, so a rule you remove here is gone.</div>
            <form method="post" action="/admin/rules/save.php" hx-post="/admin/rules/save.php" hx-target="#flash" id="rules-form"><?= csrf_field() ?>
                <div id="rules-list">
                <?php foreach ($rules as $i => $r) { echo view('admin/partials/rule-row.php', ['n' => $i + 1, 'r' => $r, 'sources' => $sources, 'departments' => $departments]); } ?>
                <?php if ($rules === []): ?><div class="card mb-3"><div class="card-body text-center text-muted fs-12" id="rules-empty">No rule yet — every new lead waits in the inbox. Add one below.</div></div><?php endif; ?>
                </div>
                <?= view('admin/partials/rule-row.php', ['n' => 0, 'r' => null, 'sources' => $sources, 'departments' => $departments]) ?>
                <div class="admin-save-bar d-flex gap-2 mb-3"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="rules-save-btn">Save the rules</button>
                    <?= hx_link('/admin/rules', 'Cancel', 'btn btn-light btn-touch', 'id="rules-cancel-btn"') ?></div>
            </form>
        </div>
        <div class="col-xl-4">
            <div class="card mb-3" id="rules-test-card"><div class="card-header"><h5 class="card-title mb-0">Test a lead</h5></div><div class="card-body">
                <p class="fs-12 text-muted">Which rule would take a lead like this? Nothing is assigned and no lead is made.</p>
                <form method="get" action="/admin/rules/test" hx-get="/admin/rules/test" hx-target="#rule-test-result" hx-swap="innerHTML" id="rule-test-form" class="row g-2">
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="rule-test-source">Source</label>
                        <select class="form-select btn-touch" id="rule-test-source" name="source"><option value="">—</option><?php foreach ($sources as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><div class="form-label fs-12 text-muted" id="rule-test-campaign-label">Campaign</div>
                        <?= picker_field(['id' => 'rule-test-campaign', 'name' => 'campaign', 'source' => 'campaign', 'value' => null, 'labelledby' => 'rule-test-campaign-label', 'title' => 'A campaign', 'placeholder' => 'Any']) ?></div>
                    <div class="col-4"><label class="form-label fs-12 text-muted" for="rule-test-country">Country</label><input type="text" class="form-control btn-touch" id="rule-test-country" name="country" maxlength="2" placeholder="US"></div>
                    <div class="col-8"><label class="form-label fs-12 text-muted" for="rule-test-email">Email</label><input type="email" class="form-control btn-touch" id="rule-test-email" name="email" placeholder="pat@acme.edu"></div>
                    <div class="col-12"><label class="form-label fs-12 text-muted" for="rule-test-utm">utm source</label><input type="text" class="form-control btn-touch" id="rule-test-utm" name="utm_source" maxlength="100"></div>
                    <div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100" id="rule-test-btn"><i class="feather-play me-1"></i>Test</button></div>
                </form>
                <div class="mt-3" id="rule-test-result" aria-live="polite"></div>
            </div></div>
        </div>
    </div>
</div>
