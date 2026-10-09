<?php /** Emails: subject, direction, date — to and from the account's people and on its deals. Data: a, emails, tz */ ?>
<?= view('shared/record-emails.php', ['prefix' => 'account', 'emails' => $emails, 'tz' => $tz, 'back' => '/accounts/' . (int) $a['account_id'] . '?tab=emails']) ?>
