<?php /** The day: the week's grid with one column (the hours, the all-day strip, the blocks). Data: cal, colours, may */ ?>
<?= view('calendar/partials/grid-week.php', ['cal' => $cal, 'colours' => $colours, 'may' => $may]) ?>
