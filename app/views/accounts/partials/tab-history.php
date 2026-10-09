<?php /** History: crm_timeline — activities, notes, changes in order. Data: a, timeline, tz */ ?>
<?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'account']) ?>
