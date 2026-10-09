<?php /** Activities: the agenda. Data: c, activities, may, tz */ ?>
<?= view('shared/record-agenda.php', ['kind' => 'contact', 'id' => (int) $c['contact_id'], 'activities' => $activities, 'may' => $may, 'tz' => $tz]) ?>
