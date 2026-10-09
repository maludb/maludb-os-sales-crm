<?php
/**
 * The phone's tab bar (hidden from 992 px, where the sidebar is): Today · Pipeline · Calendar · Log · More (sso-shell.md). Data: activeNav.
 * Log is a dropup of the quick-log links (Bootstrap's dropdown — never a modal); without JavaScript its link opens the activities. A link
 * whose slice has not shipped is shown greyed with the slice's name. More opens the sidebar (the theme's offcanvas); without JavaScript it
 * opens My settings.
 */
?>
<nav class="app-tabbar d-lg-none" id="app-tabbar" aria-label="Main">
    <?php foreach (nav_tabs() as [$tid, $turl, $ticon, $tlabel, $tright, $tkind]): ?>
        <?php if (!nav_has_right($tright)) { continue; } ?>
        <?php if ($tkind === 'sheet'): $sheet = array_filter(nav_log_sheet(), static fn (array $i): bool => nav_has_right($i[4])); if ($sheet === []) { continue; } ?>
            <div class="dropup app-tab-sheet">
                <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab" data-bs-toggle="dropdown" aria-expanded="false" role="button">
                    <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end" id="tab-log-sheet" aria-labelledby="tab-<?= e($tid) ?>">
                    <?php foreach ($sheet as [$lid, $lurl, $licon, $llabel, , $lfile, $lslice]): ?>
                        <?php if (nav_built($lfile)): ?>
                            <a href="<?= e($lurl) ?>" class="dropdown-item" id="tab-log-<?= e($lid) ?>" hx-get="<?= e($lurl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($lurl) ?>"><i class="<?= e($licon) ?>"></i><span><?= e($llabel) ?></span></a>
                        <?php else: ?>
                            <span class="dropdown-item disabled text-muted" id="tab-log-<?= e($lid) ?>" aria-disabled="true"><i class="<?= e($licon) ?>"></i><span><?= e($llabel) ?> <span class="fs-11">· <?= e($lslice) ?></span></span></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($tkind === 'menu'): ?>
            <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab" aria-controls="left-sidenav"
               onclick="var t = document.getElementById('mobile-collapse'); if (t) { event.preventDefault(); t.click(); }">
                <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
            </a>
        <?php else: ?>
            <a href="<?= e($turl) ?>" id="tab-<?= e($tid) ?>" class="app-tab<?= ($activeNav ?? '') === $tid ? ' active' : '' ?>"
               hx-get="<?= e($turl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($turl) ?>">
                <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
