<?php /** One contact (contact-view): the opt-out banner, the header, then tabs by anchor — each its own partial. Data: what html/contacts/view.php gathers. */
$cid = (int) $c['contact_id'];
$tabs = ['overview' => 'Overview', 'deals' => 'Deals', 'activities' => 'Activities', 'emails' => 'Emails', 'notes' => 'Notes & files', 'history' => 'History'];
$counts = ['deals' => count($deals), 'activities' => count($activities['open']), 'notes' => count($notes) + count($files)]; ?>
<?= view('contacts/partials/header.php', ['c' => $c, 'may' => $may]) ?>
<div class="main-content" id="contact-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('contacts/partials/opt-out-banner.php', ['c' => $c, 'may' => $may, 'tz' => $tz]) ?>
    <div class="mb-3"><?= view('shared/tag-editor.php', ['kind' => 'contact', 'id' => $cid, 'tags' => $tags, 'may' => ['edit' => $may['tag']], 'back' => '/contacts/' . $cid]) ?></div>
    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" id="contact-tabs" role="tablist">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item" role="presentation"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" id="contact-tab-<?= $k ?>-link" href="/contacts/<?= $cid ?>?tab=<?= $k ?>#contact-tab-<?= $k ?>"
                data-bs-toggle="tab" data-bs-target="#contact-tab-<?= $k ?>" role="tab" aria-controls="contact-tab-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?><?= isset($counts[$k]) && $counts[$k] > 0 ? ' <span class="badge bg-soft-secondary text-secondary">' . $counts[$k] . '</span>' : '' ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="contact-tab-content">
        <?php foreach (array_keys($tabs) as $k): ?>
            <div class="tab-pane fade<?= $tab === $k ? ' show active' : '' ?>" id="contact-tab-<?= $k ?>" role="tabpanel" aria-labelledby="contact-tab-<?= $k ?>-link">
                <?= view('contacts/partials/tab-' . $k . '.php', get_defined_vars()) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
