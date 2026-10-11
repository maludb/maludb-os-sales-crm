<?php /** A qualification's payload: the score and the reasons. Data: r */ ?>
<?php foreach ($r['lines'] as $i => $line): ?><div class="<?= $i === 0 ? 'fw-semibold' : 'text-muted' ?>" id="proposal-<?= $r['id'] ?>-line-<?= $i ?>"><?= e($line) ?></div><?php endforeach; ?>
