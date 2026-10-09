<?php /** My calendar link (the ICS feed of my activities for Google, Outlook or Apple Calendar): feed_rotate makes and replaces it — slice 4. Data: feed, tz */ ?>
<div class="card mb-3" id="settings-feed"><div class="card-header"><h5 class="card-title mb-0">Your calendar link</h5></div><div class="card-body fs-12">
    <?php if ($feed !== null): ?>
        <div id="settings-feed-state">You have a calendar link, made <?= e(format_ts($feed['created_at'], $tz, 'M j, Y')) ?><?= $feed['last_used_at'] ? ', last read ' . e(format_ts($feed['last_used_at'], $tz, 'M j, g:i A')) : '' ?>. A new link replaces it.</div>
    <?php else: ?>
        <div id="settings-feed-state">Your activities in Google, Outlook or Apple Calendar through a private link — read-only, your own activities only.</div>
    <?php endif; ?>
    <?php if (nav_built('/api/v1/calendar_ics.php')): ?>
        <form method="post" action="/settings/feed.php" hx-post="/settings/feed.php" hx-target="#page-content" hx-swap="innerHTML" hx-confirm="Make a new calendar link? The old one stops working." class="mt-2"><?= csrf_field() ?><input type="hidden" name="confirm" value="yes">
            <button type="submit" class="btn btn-light btn-touch w-100" id="settings-feed-btn"><?= $feed !== null ? 'Make a new link' : 'Make my link' ?></button></form>
    <?php else: ?>
        <div class="text-muted mt-2" id="settings-feed-coming">Arrives with the calendar (slice 4).</div>
    <?php endif; ?>
</div></div>
