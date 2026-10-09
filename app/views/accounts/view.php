<?php /** One account (account-view): the header, then the tabs by anchor — each its own partial. Data: everything html/accounts/view.php gathers. */
$aid = (int) $a['account_id'];
$tabs = ['overview' => 'Overview', 'contacts' => 'Contacts', 'deals' => 'Deals', 'activities' => 'Activities', 'emails' => 'Emails', 'notes' => 'Notes & files', 'tickets' => 'Tickets', 'history' => 'History'];
$counts = ['contacts' => count($contacts), 'deals' => count($deals), 'activities' => count($activities['open']), 'notes' => count($notes) + count($files)]; ?>
<?= view('accounts/partials/header.php', ['a' => $a, 'may' => $may]) ?>
<div class="main-content" id="account-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3"><?= view('shared/tag-editor.php', ['kind' => 'account', 'id' => $aid, 'tags' => $tags, 'may' => ['edit' => $may['tag']], 'back' => '/accounts/' . $aid]) ?></div>
    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" id="account-tabs" role="tablist">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item" role="presentation"><a class="nav-link text-nowrap<?= $tab === $k ? ' active' : '' ?>" id="account-tab-<?= $k ?>-link" href="/accounts/<?= $aid ?>?tab=<?= $k ?>#account-tab-<?= $k ?>"
                data-bs-toggle="tab" data-bs-target="#account-tab-<?= $k ?>" role="tab" aria-controls="account-tab-<?= $k ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?><?= isset($counts[$k]) && $counts[$k] > 0 ? ' <span class="badge bg-soft-secondary text-secondary">' . $counts[$k] . '</span>' : '' ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="tab-content" id="account-tab-content">
        <?php foreach (array_keys($tabs) as $k): ?>
            <div class="tab-pane fade<?= $tab === $k ? ' show active' : '' ?>" id="account-tab-<?= $k ?>" role="tabpanel" aria-labelledby="account-tab-<?= $k ?>-link">
                <?= view('accounts/partials/tab-' . $k . '.php', get_defined_vars()) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
