<?php
/**
 * The app shell (design-system nxl skeleton), PHONE FIRST — sso-shell.md "The shell". Wraps a screen's page HTML.
 * Data: title, content, activeNav?, screen?, entity?, recordId?
 * Load-bearing: nxl-* classes, #mobile-collapse, #menu-mini-button, asset order, theme-customizer-init.min.js last.
 * At 1280 px: the sidebar (Today · Leads · Accounts · Contacts · Pipeline · Calendar · Activities · Email · Campaigns · Lists · Reports, Me,
 * Admin for a holder of an admin area's right) and the main pane #page-content (the HTMX target); the header carries the ONE search box.
 * At 375 px: the header's menu button (or the More tab) opens the sidebar as an offcanvas; the bottom tab bar carries Today · Pipeline ·
 * Calendar · Log (a dropup of the quick-log links) · More; the command bar sits above it; search is the header's magnifier.
 */
$title     = $title     ?? app_name();
$content   = $content   ?? '';
$activeNav = $activeNav ?? '';
$screen    = $screen    ?? '';
$entity    = $entity    ?? '';
$recordId  = $recordId  ?? '';
$pdo       = db();
$m         = current_member();
$me        = (int) ($m['id'] ?? 0);
$initials  = strtoupper(mb_substr((string) ($m['display_name'] ?? '?'), 0, 1));
$roleBadge = role_badge();
$badgeKind = match ($roleBadge) { 'CRM admin', 'Super-admin' => 'danger', 'Sales Manager' => 'primary', 'Marketing' => 'info', 'Viewer' => 'warning', default => 'secondary' };
$unread    = $m !== null ? bell_count($pdo, $me) : 0;
$inbox     = $m !== null && has_right('records.read') ? inbox_count($pdo) : 0;
$q         = $screen === 'search' ? (string) ($_GET['q'] ?? '') : '';
$navlink = function (string $id, string $url, string $icon, string $label) use ($activeNav, $inbox): string {
    $active = $activeNav === $id ? ' active' : '';
    $badge = $id === 'leads' && $inbox > 0 ? '<span class="nxl-badge"><span class="badge text-bg-warning" id="nav-leads-inbox" title="Proposed leads waiting">' . ($inbox > 99 ? '99+' : $inbox) . '</span></span>' : '';
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . $active . '" href="' . e($url) . '"'
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML"'
        . ' hx-push-url="' . e($url) . '">'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span>'
        . '<span class="nxl-mtext">' . e($label) . '</span>' . $badge
        . '</a></li>';
};
$groups = nav_groups();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="x-ua-compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="<?= e(app_name()) ?>" />
    <meta name="theme-color" content="#3454d1" />
    <!-- htmx 2 swaps nothing above 3xx by default; a refused write (422) re-renders its form or lands in #flash (HX-Retarget). -->
    <meta name="htmx-config" content='{"responseHandling":[{"code":"204","swap":false},{"code":"[23]..","swap":true},{"code":"422","swap":true,"error":false},{"code":"[45]..","swap":false,"error":true}]}'>
    <meta name="csrf-token" id="csrf-token-meta" content="<?= e(csrf_token()) ?>" />
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png" />
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png" />
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/select2.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css" />
</head>
<body>
    <!--! [Start] Navigation (the sidebar on a desktop; the offcanvas from the menu button on a phone) !-->
    <nav class="nxl-navigation" id="left-sidenav">
        <div class="navbar-wrapper">
            <div class="m-header">
                <a href="/" class="b-brand" hx-get="/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/">
                    <img src="/assets/images/logo-full.png" alt="<?= e(app_name()) ?>" class="logo logo-lg" />
                    <img src="/assets/images/logo-abbr.png" alt="" class="logo logo-sm" />
                </a>
            </div>
            <div class="navbar-content">
                <ul class="nxl-navbar" id="shell-menu">
                    <?php foreach ($groups as $groupLabel => $items): ?>
                        <?php $shown = array_filter($items, static fn (array $i): bool => nav_has_right($i[4])); if ($shown === []) { continue; } ?>
                        <?php if ($groupLabel !== 'Sales CRM'): ?><li class="nxl-item nxl-caption"><label><?= e($groupLabel) ?></label></li><?php endif; ?>
                        <?php foreach ($shown as $item) { echo $navlink($item[0], $item[1], $item[2], $item[3]); } ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </nav>
    <!--! [End] Navigation !-->

    <!--! [Start] Header !-->
    <header class="nxl-header" id="top-header">
        <div class="header-wrapper">
            <div class="header-left d-flex align-items-center gap-3">
                <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse" aria-label="Menu">
                    <div class="hamburger hamburger--arrowturn">
                        <div class="hamburger-box"><div class="hamburger-inner"></div></div>
                    </div>
                </a>
                <div class="nxl-navigation-toggle">
                    <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                    <a href="javascript:void(0);" id="menu-expend-button" style="display: none"><i class="feather-arrow-right"></i></a>
                </div>
                <div class="header-role d-flex align-items-center" id="header-person">
                    <span class="fw-semibold text-dark text-truncate" id="header-business"><?= e(business_name($pdo)) ?></span>
                    <?php if ($roleBadge !== ''): ?><span class="badge bg-soft-<?= e($badgeKind) ?> text-<?= e($badgeKind) ?> ms-2 text-nowrap" id="header-role-badge"><?= e($roleBadge) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center">
                    <!-- THE ONE SEARCH BOX (crm_search over leads, accounts, contacts and deals; the wall per row). A plain GET form: works without JavaScript. -->
                    <form class="header-search d-none d-md-block me-2" method="get" action="/search" role="search" id="header-search-form"
                          hx-get="/search" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="feather-search"></i></span>
                            <input type="search" name="q" class="form-control" id="header-search" placeholder="Search leads, accounts, contacts, deals" value="<?= e($q) ?>" maxlength="200" aria-label="Search" autocomplete="off" />
                        </div>
                    </form>
                    <div class="nxl-h-item d-md-none">
                        <a href="/search" class="nxl-head-link me-0" id="header-search-link" aria-label="Search" hx-get="/search" hx-target="#page-content" hx-push-url="/search"><i class="feather-search"></i></a>
                    </div>
                    <div class="nxl-h-item">
                        <a href="/notifications" class="nxl-head-link me-0 position-relative" id="header-bell" aria-label="Notifications" hx-get="/notifications" hx-target="#page-content" hx-push-url="/notifications">
                            <i class="feather-bell"></i>
                            <?= view('notifications/partials/bell.php', ['unread' => $unread]) ?>
                        </a>
                    </div>
                    <?= view('shared/app-switcher.php') ?>
                    <div class="nxl-h-item dark-light-theme d-none d-sm-flex">
                        <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                        <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none"><i class="feather-sun"></i></a>
                    </div>
                    <div class="dropdown nxl-h-item">
                        <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" id="header-user-menu" aria-label="Your account">
                            <span class="avatar-text avatar-md user-avtar me-0"><?= e($initials) ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                            <div class="dropdown-header">
                                <div class="d-flex align-items-center">
                                    <span class="avatar-text avatar-md user-avtar"><?= e($initials) ?></span>
                                    <div class="min-w-0">
                                        <h6 class="text-dark mb-0" id="header-user-name"><?= e($m['display_name'] ?? 'Member') ?></h6>
                                        <span class="fs-12 fw-medium text-muted" id="header-user-status"><?= e($m['email'] ?? '') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="/settings/" class="dropdown-item" hx-get="/settings/" hx-target="#page-content" hx-push-url="/settings/" id="header-settings-link">
                                <i class="feather-settings"></i><span>My settings</span>
                            </a>
                            <a href="/settings/tokens/" class="dropdown-item" hx-get="/settings/tokens/" hx-target="#page-content" hx-push-url="/settings/tokens/" id="header-tokens-link">
                                <i class="feather-key"></i><span>Tokens</span>
                            </a>
                            <a href="<?= e(launcher_url()) ?>" class="dropdown-item" id="header-launcher-item">
                                <i class="feather-grid"></i><span>All applications</span>
                            </a>
                            <div class="dropdown-divider"></div>
                            <form method="post" action="/logout.php" class="px-2">
                                <?= csrf_field() ?>
                                <button type="submit" id="header-logout-btn" class="dropdown-item border-0 bg-transparent w-100 text-start">
                                    <i class="feather-log-out"></i><span>Sign out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <!--! [End] Header !-->

    <!--! [Start] Main Content !-->
    <main class="nxl-container app-has-assistant-bar app-has-tabbar">
        <div id="flash"></div>
        <div class="app-panes" id="app-panes">
            <div class="nxl-content" id="page-content"
                 data-screen="<?= e($screen) ?>" data-entity="<?= e($entity) ?>" data-record-id="<?= e($recordId) ?>">
                <?= $content ?>
            </div>
        </div>
        <footer class="footer" id="page-footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright">
                <span>© <?= date('Y') ?> <?= e(app_name()) ?> · an application for the Business OS</span>
            </p>
            <div class="d-flex align-items-center gap-4">
                <a href="/activity" class="fs-11 fw-semibold text-uppercase" hx-get="/activity" hx-target="#page-content" hx-push-url="/activity">My activity</a>
                <a href="<?= e(launcher_url()) ?>" class="fs-11 fw-semibold text-uppercase">Applications</a>
            </div>
        </footer>
    </main>
    <!--! [End] Main Content !-->

    <!-- RECORD PICKER (references/record-picker.md): the ONE modal that chooses a record for any picker field on the screen —
         outside the swap target so swaps keep it. The second exemption to the no-modal rule, beside hx-confirm. -->
    <div class="modal fade" id="record-picker" tabindex="-1" aria-labelledby="record-picker-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="record-picker-title">Choose</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="record-picker-close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="record-picker-search p-3 border-bottom">
                        <div class="input-group">
                            <span class="input-group-text"><i class="feather-search"></i></span>
                            <input type="search" class="form-control" id="record-picker-search" placeholder="Search…" autocomplete="off" aria-controls="record-picker-results"
                                   hx-get="/pick/" hx-trigger="input changed delay:300ms, search, pickerload" hx-target="#record-picker-results" hx-swap="innerHTML"
                                   hx-sync="this:replace" hx-vals="js:{...recordPickerValues()}" />
                        </div>
                    </div>
                    <div id="record-picker-results" aria-live="polite"></div>
                </div>
                <div class="modal-footer d-none" id="record-picker-footer">
                    <button type="button" class="btn btn-light-brand" id="record-picker-create-btn"><i class="feather-plus me-1"></i>New</button>
                </div>
            </div>
        </div>
    </div>

    <?= view('shared/assistant-bar.php') ?>
    <?= view('shared/tab-bar.php', ['activeNav' => $activeNav]) ?>

    <script src="/assets/vendors/js/vendors.min.js"></script>
    <script src="/assets/vendors/js/select2.min.js"></script>
    <script src="/assets/vendors/js/select2-active.min.js"></script>
    <script src="/assets/vendors/js/htmx.min.js"></script>
    <script>
        // CSRF over HTMX (php-session-auth): every non-GET request carries the session's token.
        document.body.addEventListener('htmx:configRequest', function (e) {
            if (e.detail.verb && e.detail.verb.toLowerCase() !== 'get') {
                var meta = document.querySelector('#csrf-token-meta');
                if (meta) { e.detail.headers['X-CSRF-Token'] = meta.content; }
            }
        });
        // The command bar shows Send only while in use.
        (function () { var f = document.getElementById('assistant-form'), i = document.getElementById('assistant-input'); if (!f || !i) return;
            i.addEventListener('input', function () { f.classList.toggle('in-use', i.value.trim() !== ''); }); })();
        // A swapped screen: title, sidebar and tab highlight, context stamps, theme widgets that bind on ready.
        document.body.addEventListener('htmx:afterSwap', function (e) {
            var pc = document.getElementById('page-content');
            if (!pc) return;
            var xt = e.detail && e.detail.xhr ? e.detail.xhr.getResponseHeader('HX-Title') : null;
            if (xt) { document.title = decodeURIComponent(xt); }
            if (e.detail && e.detail.target && e.detail.target.id === 'flash') { window.scrollTo({ top: 0, behavior: 'smooth' }); }
            var xhr = e.detail && e.detail.xhr;
            if (xhr && xhr.getResponseHeader('X-Screen') !== null) {
                pc.dataset.screen = xhr.getResponseHeader('X-Screen') || '';
                pc.dataset.entity = xhr.getResponseHeader('X-Entity') || '';
                pc.dataset.recordId = xhr.getResponseHeader('X-Record-Id') || '';
            }
            if (!e.detail || !e.detail.target || e.detail.target.id !== 'page-content') return;
            var screen = pc.dataset.screen || '';
            var navId = { 'settings': 'my-settings', 'settings-tokens': 'tokens', 'board': 'pipeline', 'deal-list': 'pipeline', 'forecast': 'pipeline', 'deal-view': 'pipeline', 'deal-add': 'pipeline', 'deal-edit': 'pipeline', 'deal-won': 'pipeline', 'deal-lost': 'pipeline', 'saved-views': 'pipeline',
                          'account-list': 'accounts', 'account-view': 'accounts', 'account-add': 'accounts', 'account-edit': 'accounts', 'contact-list': 'contacts', 'contact-view': 'contacts', 'contact-add': 'contacts', 'contact-edit': 'contacts',
                          'lead-list': 'leads', 'lead-inbox': 'leads', 'lead-view': 'leads', 'lead-add': 'leads', 'lead-edit': 'leads', 'lead-convert': 'leads', 'import-list': 'leads', 'import-new': 'leads', 'import-view': 'leads', 'duplicates': 'leads', 'merge': 'leads',
                          'activity-list': 'activities', 'activity-view': 'activities', 'activity-add': 'activities', 'activity-edit': 'activities', 'log-call': 'activities', 'log-meeting': 'activities', 'email-unmatched': 'email', 'campaign-list': 'campaigns', 'list-list': 'lists' }[screen] || (screen.indexOf('admin-') === 0 ? 'admin' : screen);        // every admin-* screen (slices 8 and 9) keeps Admin lit
            document.querySelectorAll('.nxl-navbar .nxl-link, #app-tabbar .app-tab').forEach(function (a) { a.classList.remove('active'); });
            var link = document.querySelector('#nav-' + navId + ' .nxl-link');
            if (link) { link.classList.add('active'); }
            var tab = document.getElementById('tab-' + navId);
            if (tab) { tab.classList.add('active'); }
            if (window.bootstrap && bootstrap.Dropdown) { var lt = document.getElementById('tab-log'); if (lt) { var dd = bootstrap.Dropdown.getInstance(lt); if (dd) dd.hide(); } }
            if (window.jQuery) {
                if (jQuery.fn.tooltip) { jQuery('[data-bs-toggle="tooltip"]').tooltip(); }
                if (jQuery.fn.select2) { jQuery('#page-content select[data-select2-selector]').each(function () { if (!jQuery(this).hasClass('select2-hidden-accessible')) { jQuery(this).select2({ width: '100%' }); } }); }
            }
            var nav = document.querySelector('nav.nxl-navigation');
            if (nav && nav.classList.contains('mob-navigation-active')) { nav.classList.remove('mob-navigation-active'); var ov = document.querySelector('.nxl-menu-overlay'); if (ov) ov.remove(); }
        });
        // A record's tabs by anchor (slice 1): /accounts/7#account-tab-notes opens that pane — on a page load and after an HTMX navigation.
        function showTabFromHash() {
            var h = window.location.hash;
            if (!h || !window.bootstrap || !bootstrap.Tab) return;
            var link = document.querySelector('[data-bs-toggle="tab"][data-bs-target="' + h.replace(/"/g, '') + '"]');
            if (link) { bootstrap.Tab.getOrCreateInstance(link).show(); }
        }
        window.addEventListener('load', showTabFromHash);
        window.addEventListener('hashchange', showTabFromHash);
        document.body.addEventListener('htmx:afterSettle', function (e) { if (e.detail && e.detail.target && e.detail.target.id === 'page-content') { showTabFromHash(); } });
        // A refused request (4xx) is retargeted to #flash by the server; a network failure says so.
        document.body.addEventListener('htmx:responseError', function (e) {
            var f = document.getElementById('flash');
            if (f && e.detail.xhr && e.detail.xhr.status >= 500) { f.innerHTML = '<div class="alert alert-danger m-3">Something went wrong. Try again.</div>'; }
        });
    </script>
    <script src="/assets/js/common-init.min.js"></script>
    <script src="/assets/vendors/sortablejs/Sortable.min.js"></script>   <!-- the board's drag (pipeline-board.md §2); the selects work without it -->
    <script src="/assets/js/board.js"></script>
    <script src="/assets/js/stage-order.js"></script>   <!-- the admin's stage order drag (slice 9); the Move up / Move down buttons work without it -->
    <script src="/assets/js/calendar.js"></script>   <!-- the calendar's drag and the activity form's kind (activities-calendar.md §1–§2); the links work without it -->
    <script src="/assets/js/record-picker.js"></script>   <!-- the record picker (design-system record-picker.md): after common-init -->
    <script src="/assets/js/tag-editor.js"></script>      <!-- the tag editor's suggestions (slice 1) -->
    <script src="/assets/js/bulk.js"></script>           <!-- the bulk bar's count and confirm (slice 6); the bar posts without it -->
    <script src="/assets/js/theme-customizer-init.min.js"></script>
</body>
</html>
