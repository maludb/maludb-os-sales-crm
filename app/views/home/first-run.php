<?php /** The first-run home: no lead, no account, no deal the person may see — ONE button (sso-shell.md). Data: s */ $may = $s['may']; ?>
<div class="card" id="home-first-run"><div class="card-body">
    <div class="empty-state text-center py-4" id="home-empty">
        <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-target"></i></span>
        <div class="fw-semibold mb-1">Your sales, in one place</div>
        <div class="fs-12 text-muted mb-3">Add the people you are talking to, turn the promising ones into deals, and never miss the next call: every activity has a reminder and every deal a next step.</div>
        <?php if ($may['lead']): ?>
            <a href="/leads/new" class="btn btn-primary btn-touch" id="home-first-run-btn"<?= nav_built('/leads/form.php') ? ' hx-get="/leads/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/leads/new"' : '' ?>><i class="feather-user-plus me-1"></i>Add your first lead</a>
        <?php else: ?>
            <div class="fs-12 text-muted" id="home-first-run-viewer">Nothing has been added yet. When your team adds leads, accounts and deals, they appear here.</div>
        <?php endif; ?>
    </div>
</div></div>
