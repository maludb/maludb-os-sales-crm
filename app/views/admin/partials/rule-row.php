<?php /** One assignment rule inside the rules form (admin-rules; id rule-row-{n}; field names rules[n][…]). Data: n (1-based place; 0 = the blank rule to add), r (a rule of rules(), or null for the blank), sources (active lead sources), departments (find_live_departments) */
$blank = $r === null;
$k = $blank ? 'new' : (string) $n;
$kind = $blank ? 'member' : $r['assign_kind'];
$match = $blank ? [] : $r['match'];
$pickedSources = array_map('intval', (array) ($match['source_id'] ?? []));
$campaigns = array_map('intval', (array) ($match['campaign_id'] ?? []));
$people = $blank ? [] : array_map(static fn (array $m): int => (int) $m['id'], $r['members']);
$list = static fn (string $key): string => implode(', ', (array) ($match[$key] ?? [])); ?>
<div class="card mb-3 rule-row" id="rule-row-<?= e($k) ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h6 class="mb-0 flex-grow-1" id="rule-row-<?= e($k) ?>-title"><?= $blank ? 'Add a rule' : 'Rule ' . (int) $n ?>
            <?php if (!$blank): ?><span class="badge bg-soft-<?= e(RULE_TONE[$kind]) ?> text-<?= e(RULE_TONE[$kind]) ?> ms-1" id="rule-row-<?= e($k) ?>-kind"><?= e(RULE_KINDS[$kind]) ?></span><?php endif; ?></h6>
        <?php if (!$blank): ?><div class="fs-12 text-muted w-100" id="rule-row-<?= e($k) ?>-words">If a lead is <?= e(rule_match_words($r)) ?> →
            <?= $kind === 'member' ? e((string) $r['member_name']) : ($kind === 'department' ? "the manager of " . e((string) $r['department_name']) : 'in turn: ' . e(implode(', ', array_map(static fn (array $m): string => $m['name'], $r['members'])))) ?></div><?php endif; ?>
    </div>
    <div class="card-body"><div class="row g-3">
        <?php if (!$blank): ?><input type="hidden" name="rules[<?= e($k) ?>][id]" value="<?= (int) $r['id'] ?>"><?php endif; ?>
        <div class="col-md-6"><label class="form-label" for="rules-<?= e($k) ?>-name">Name</label>
            <input type="text" class="form-control btn-touch" id="rules-<?= e($k) ?>-name" name="rules[<?= e($k) ?>][name]" value="<?= e((string) ($r['name'] ?? '')) ?>" maxlength="80" placeholder="<?= $blank ? 'Germany to Dana' : '' ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label" for="rules-<?= e($k) ?>-position">Order</label>
            <input type="number" class="form-control btn-touch" id="rules-<?= e($k) ?>-position" name="rules[<?= e($k) ?>][position]" value="<?= $blank ? '' : (int) $r['position'] ?>" min="0" placeholder="last"></div>
        <div class="col-6 col-md-3 d-flex align-items-end"><input type="hidden" name="rules[<?= e($k) ?>][active]" value="no"><div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="rules[<?= e($k) ?>][active]" value="yes" id="rules-<?= e($k) ?>-active" <?= $blank || $r['active'] ? 'checked' : '' ?>><label class="form-check-label" for="rules-<?= e($k) ?>-active">In use</label></div></div>
        <div class="col-12"><div class="fs-12 fw-semibold text-muted">When a lead is… <span class="fw-normal">(leave all empty to match every lead)</span></div></div>
        <div class="col-12"><div class="form-label" id="rules-<?= e($k) ?>-source-label">From a source</div>
            <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="rules-<?= e($k) ?>-source-label">
            <?php foreach ($sources as $s): $cid = 'rules-' . $k . '-source-' . (int) $s['id']; ?>
                <input type="checkbox" class="btn-check" name="rules[<?= e($k) ?>][source][]" value="<?= (int) $s['id'] ?>" id="<?= e($cid) ?>" <?= in_array((int) $s['id'], $pickedSources, true) ? 'checked' : '' ?> autocomplete="off">
                <label class="btn btn-outline-secondary btn-sm btn-touch" for="<?= e($cid) ?>"><?= e($s['name']) ?></label>
            <?php endforeach; ?></div></div>
        <div class="col-md-6"><div class="form-label" id="rules-<?= e($k) ?>-campaign-label">From a campaign</div>
            <?php foreach (array_merge($campaigns, [0]) as $ci => $cid): ?>
                <div class="mb-1"><?= picker_field(['id' => 'rules-' . $k . '-campaign-' . $ci, 'name' => 'rules[' . $k . '][campaign][]', 'source' => 'campaign', 'value' => $cid ?: null, 'labelledby' => 'rules-' . $k . '-campaign-label',
                    'title' => 'A campaign', 'placeholder' => $cid ? 'Choose a campaign' : ($campaigns === [] ? 'Any campaign' : 'Another campaign')]) ?></div>
            <?php endforeach; ?></div>
        <div class="col-md-6"><label class="form-label" for="rules-<?= e($k) ?>-country">In a country</label>
            <input type="text" class="form-control btn-touch" id="rules-<?= e($k) ?>-country" name="rules[<?= e($k) ?>][country]" value="<?= e($list('country')) ?>" placeholder="US, DE">
            <div class="form-text">Two-letter codes, separated by commas.</div></div>
        <div class="col-md-6"><label class="form-label" for="rules-<?= e($k) ?>-domain">Whose email ends</label>
            <input type="text" class="form-control btn-touch" id="rules-<?= e($k) ?>-domain" name="rules[<?= e($k) ?>][domain_suffix]" value="<?= e($list('domain_suffix')) ?>" placeholder=".edu, acme.com">
            <div class="form-text">After the @, separated by commas.</div></div>
        <div class="col-md-6"><label class="form-label" for="rules-<?= e($k) ?>-utm">With a utm source</label>
            <input type="text" class="form-control btn-touch" id="rules-<?= e($k) ?>-utm" name="rules[<?= e($k) ?>][utm_source]" value="<?= e($list('utm_source')) ?>" placeholder="newsletter, google">
            <div class="form-text">Separated by commas.</div></div>
        <div class="col-12"><div class="fs-12 fw-semibold text-muted">…assign it to</div></div>
        <div class="col-md-4"><label class="form-label" for="rules-<?= e($k) ?>-kind">Assign</label>
            <select class="form-select btn-touch" id="rules-<?= e($k) ?>-kind" name="rules[<?= e($k) ?>][assign_kind]"><?php foreach (RULE_KINDS as $kk => $l): ?><option value="<?= e($kk) ?>" <?= $kind === $kk ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><div class="form-label" id="rules-<?= e($k) ?>-member-label">A person (for "A person")</div>
            <?= picker_field(['id' => 'rules-' . $k . '-member', 'name' => 'rules[' . $k . '][member]', 'source' => 'owner', 'value' => $blank ? null : $r['member_id'], 'labelledby' => 'rules-' . $k . '-member-label', 'title' => 'A person', 'placeholder' => 'Choose a person']) ?></div>
        <div class="col-md-4"><label class="form-label" for="rules-<?= e($k) ?>-department">A department (for "A department's manager")</label>
            <select class="form-select btn-touch" id="rules-<?= e($k) ?>-department" name="rules[<?= e($k) ?>][department]"><option value="">—</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= !$blank && (int) $r['department_id'] === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><div class="form-label" id="rules-<?= e($k) ?>-members-label">People taking turns (for "In turn")</div>
            <?php foreach (array_merge($people, [0]) as $pi => $pid): ?>
                <div class="mb-1"><?= picker_field(['id' => 'rules-' . $k . '-members-' . $pi, 'name' => 'rules[' . $k . '][members][]', 'source' => 'owner', 'value' => $pid ?: null, 'labelledby' => 'rules-' . $k . '-members-label',
                    'title' => 'A person', 'placeholder' => $pid ? 'Choose a person' : ($people === [] ? 'Add a person' : 'Add another person')]) ?></div>
            <?php endforeach; ?></div>
        <?php if (!$blank): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="rules[<?= e($k) ?>][remove]" value="1" id="rules-<?= e($k) ?>-remove">
            <label class="form-check-label text-danger" for="rules-<?= e($k) ?>-remove">Remove this rule when I save</label></div></div><?php endif; ?>
    </div></div>
</div>
