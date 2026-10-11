<?php /** One activity on the calendar (#cal-item-{id}): the kind's icon, the time (none when all day), the subject, the record's name; the state's class
 * (done muted and struck, overdue red, today amber, scheduled the kind's colour); on the team view the owner's initials in their colour. A click opens it.
 * Data: r, cal, colours, may, block (the week and day views' blocks: a height by the minutes) */
$id = (int) $r['activity_id'];
$block = $block ?? false;
$c = $colours[(int) ($r['owner_member_id'] ?? 0)] ?? null;
$drag = !$r['done'] && ($may['drag'] ?? false);
$style = ($c !== null ? '--owner:' . $c['colour'] . ';' : '') . ($block ? '--mins:' . max(30, min(600, (int) ($r['minutes'] ?? 30))) . ';' : '');
$back = calendar_url($cal); ?>
<a href="<?= e(with_back('/activities/' . $id, $back)) ?>" class="cal-chip cal-kind-<?= e($r['kind']) ?> cal-state-<?= e($r['state']) ?><?= $block ? ' cal-block' : '' ?><?= $c !== null ? ' cal-team' : '' ?>" id="cal-item-<?= $id ?>"
   data-activity-id="<?= $id ?>" data-time="<?= e($r['local_time'] ?? format_ts($r['starts_at'], $cal['tz'], 'H:i')) ?>"<?= $r['all_day'] ? ' data-all-day="1"' : '' ?><?= $drag ? ' data-draggable="1"' : '' ?><?= $style !== '' ? ' style="' . e($style) . '"' : '' ?>
   title="<?= e(ACTIVITY_KINDS[$r['kind']] . ': ' . $r['subject'] . ($r['record_name'] ? ' — ' . $r['record_name'] : '') . ($c !== null ? ' (' . $c['name'] . ')' : '')) ?>">
    <?php if ($c !== null): ?><span class="cal-initials" aria-hidden="true"><?= e(calendar_initials($c['name'])) ?></span><?php endif; ?>
    <i class="<?= e(activity_kind_icon($r['kind'])) ?>" aria-hidden="true"></i>
    <?php if (!$r['all_day']): ?><span class="cal-time"><?= e(format_ts($r['starts_at'], $cal['tz'], 'g:i')) ?><?= e(strtolower(format_ts($r['starts_at'], $cal['tz'], 'a'))[0] ?? '') ?></span><?php endif; ?>
    <span class="cal-subject"><?= e($r['subject']) ?></span><?php if ($r['record_name']): ?><span class="cal-record"> · <?= e($r['record_name']) ?></span><?php endif; ?>
</a>
