<?php /** Emails to and from the contact. Data: c, emails, tz */ ?>
<?= view('shared/record-emails.php', ['prefix' => 'contact', 'emails' => $emails, 'tz' => $tz, 'back' => '/contacts/' . (int) $c['contact_id'] . '?tab=emails']) ?>
