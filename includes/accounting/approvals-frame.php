<?php
// The approvals tab frame (docs/specs/accounting-spec.md, A4b), shared by Approval rules and Approvals so
// moving between Overview, Rules, Live map, Waiting and Handed over keeps the same bar.
// $apPage: 'rules' (approval-rules.php) or 'board' (approvals.php); $apActive: which tab is on.
$apBase = htmlspecialchars($accCtx['baseUrl']);
$apTabs = [
    'overview' => 'Overview', 'rules' => 'Rules', 'board' => 'Live map', 'waiting' => 'Waiting', 'delegations' => 'Handed over',
];
?>
<div class="ap-frame">
    <div class="ap-capsule ap-brand" id="apBrand"><span class="ap-brand-icon"><i class="ri-shield-check-line"></i></span><span id="apBrandName"><?= htmlspecialchars($accCtx['place']['name'] ?: 'Approvals') ?></span></div>
    <nav class="ap-capsule ap-nav" aria-label="Approvals">
        <?php foreach ($apTabs as $key => $label) :
            $on = $key === $apActive ? ' is-on' : '';
            $here = in_array($key, ['overview', 'rules'], true) ? $apPage === 'rules' : $apPage === 'board';
            if ($here) : ?>
            <button type="button" class="ap-tab<?= $on ?>" data-ap-tab="<?= $key ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= $label ?></button>
            <?php else :
                $href = $apPage === 'board'
                    ? $apBase . '/approval-rules.php' . ($key === 'rules' ? '?view=rules' : '')
                    : $apBase . '/approvals.php' . ($key === 'board' ? '' : '?tab=' . $key); ?>
            <a class="ap-tab" href="<?= $href ?>"><?= $label ?></a>
            <?php endif;
        endforeach; ?>
    </nav>
    <div class="ap-capsule ap-status">
        <span class="ap-live"><span class="ap-live-dot"></span>Live</span>
        <?php if ($apPage === 'rules') : ?>
        <label class="ap-window"><i class="ri-calendar-line"></i><select id="apDays" aria-label="How far back">
            <option value="1">24 hours</option>
            <option value="7">7 days</option>
            <option value="14" selected>14 days</option>
            <option value="30">30 days</option>
        </select><i class="ri-arrow-down-s-line"></i></label>
        <?php endif; ?>
    </div>
</div>
