<?php /** A reminder's mail body (the outbox row crm_deliver_reminder queued: its title is "kind: subject — record", its body "At <when>. <where>"). One source: the notification layout with the reminder's lead
 * and button. Data: format, title, body, url, app */
echo view('emails/notification.php', ['format' => $format, 'title' => $title, 'body' => $body, 'url' => $url, 'app' => $app, 'lead' => 'Reminder', 'cta' => 'Open the activity']);
