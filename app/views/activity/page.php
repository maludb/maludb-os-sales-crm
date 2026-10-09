<?php /** My activity (screen `activity`). Data: rows, page, more, filters, query, tz, resultsHtml, record (['lead'|'account'|'contact'|'deal', id]|null) */
$actions = ['' => 'Anything', 'lead.' => 'Leads', 'account.' => 'Accounts', 'contact.' => 'Contacts', 'deal.' => 'Deals', 'activity.' => 'Activities', 'email.' => 'Email',
    'campaign.' => 'Campaigns', 'list.' => 'Lists', 'import.' => 'Imports', 'record.' => 'Deletes and restrictions', 'search.' => 'Searches', 'settings.' => 'Settings',
    'member.' => 'Sign-ons', 'token.' => 'Tokens'];
?>
<?= view('shared/header.php', ['id' => 'activity', 'title' => 'My activity', 'crumbs' => [['Today', '/'], ['My activity', null]], 'back' => back_link()]) ?>
<div class="main-content" id="activity-content">
    <div class="card stretch stretch-full" id="activity-card">
        <div class="card-header">
            <h5 class="card-title"><?= $record !== null ? 'The ' . e($record[0]) . '\'s history' : 'What you did' ?></h5>
            <form id="activity-filters" class="d-flex flex-wrap gap-2" method="get" action="/activity" hx-get="/activity" hx-target="#activity-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <?php if ($record !== null): ?><input type="hidden" name="<?= e($record[0]) ?>" value="<?= e((string) $record[1]) ?>"><?php endif; ?>
                <select name="action" id="activity-filter-action" class="form-select form-select-sm w-auto btn-touch" aria-label="What"><?php foreach ($actions as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['action'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select name="period" id="activity-filter-period" class="form-select form-select-sm w-auto btn-touch" aria-label="Period"><option value="">All time</option><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['since'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
