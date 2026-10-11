<?php /** Reports (screen `reports`): nine cards, each with one number on its default period. Data: cards, currency, may */ ?>
<?= view('shared/header.php', ['id' => 'reports', 'title' => 'Reports', 'crumbs' => [['Today', '/'], ['Reports', null]]]) ?>
<div class="main-content" id="reports-content">
    <div class="fs-12 text-muted mb-2">One number from each report, on its usual period. Open a report for the chart, the table and the filters.</div>
    <div class="row g-3" id="reports-cards">
        <?php foreach ($cards as $c) { echo view('reports/partials/card.php', ['c' => $c]); } ?>
    </div>
</div>
