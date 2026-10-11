<?php /** The quick log of a meeting held (#log-meeting-form): the call's form with the meeting's outcomes and when it started. Data: as log/call.php */ ?>
<?= view('log/call.php', ['kind' => 'meeting', 'v' => $v, 'deal' => $deal, 'contact' => $contact, 'deals' => $deals, 'lead' => $lead, 'tz' => $tz, 'prompt' => $prompt, 'back' => $back,
    'next_subject' => $next_subject, 'now' => $now, 'hour_ago' => $hour_ago]) ?>
