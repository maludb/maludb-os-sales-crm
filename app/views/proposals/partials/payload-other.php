<?php /** The payload of a kind with no layout of its own (a draft's subject, a stale deal, a close date). Data: r */ ?>
<?php foreach ($r['lines'] as $line): ?><div class="text-muted"><?= e($line) ?></div><?php endforeach; ?>
