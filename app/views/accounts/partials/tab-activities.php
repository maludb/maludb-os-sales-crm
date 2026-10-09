<?php /** Activities: the agenda (open, overdue first) then the last done; Schedule when slice 4 has shipped. Data: a, activities, may, tz */ $aid = (int) $a['account_id']; ?>
<?= view('shared/record-agenda.php', ['kind' => 'account', 'id' => $aid, 'activities' => $activities, 'may' => $may, 'tz' => $tz]) ?>
