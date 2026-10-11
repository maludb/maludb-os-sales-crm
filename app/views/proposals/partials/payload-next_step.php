<?php /** A next step's payload: its kind, moment and subject, and what to say. Data: r */ ?>
<?php foreach ($r['lines'] as $i => $line): ?><div class="<?= $i === 0 ? 'fw-semibold' : 'text-muted' ?>" id="proposal-<?= $r['id'] ?>-line-<?= $i ?>"><?= e($line) ?></div><?php endforeach; ?>
