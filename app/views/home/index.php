<?php /** Today (screen `home`; design §9; sso-shell.md). Data: s (home_summary), tz. A new person sees first-run.php; everyone else the regions below.
 * Phone first: Today's chips, then closing and the flags, the replies, the note; a manager's forecast tile and leaders follow. */ ?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Today', 'crumbs' => [['Today', null]]]) ?>
<div class="main-content" id="home-content">
<?php if ($s['first_run']): ?>
    <?= view('home/first-run.php', ['s' => $s]) ?>
<?php else: ?>
    <?php if ($s['note'] !== null): ?><?= view('home/partials/note.php', ['note' => $s['note']]) ?><?php endif; ?>
    <div class="row g-3">
        <div class="col-xl-7"><?= view('home/partials/today.php', ['s' => $s, 'tz' => $tz]) ?></div>
        <div class="col-xl-5">
            <?= view('home/partials/closing.php', ['s' => $s]) ?>
            <?= view('home/partials/flags.php', ['s' => $s]) ?>
            <?php if ($s['manager'] !== null): ?>
                <?= view('home/partials/manager-forecast.php', ['s' => $s]) ?>
                <?= view('home/partials/leaders.php', ['s' => $s]) ?>
            <?php endif; ?>
        </div>
    </div>
    <?= view('home/partials/replies.php', ['s' => $s, 'tz' => $tz]) ?>
<?php endif; ?>
</div>
