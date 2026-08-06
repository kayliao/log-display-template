<?php
/**
 * 首頁內容。
 */

use App\Core\View;
?>
<div class="app-container">

    <?php View::component('announcement', ['items' => $announcements]); ?>

    <div class="app-hero">
        <h1 class="app-hero__title">
            <?= e(user()['name'] ?? '') ?>，您好
        </h1>
        <p class="app-hero__sub">
            <?= e(date('Y 年 n 月 j 日')) ?>
            ·
            以下是您可以使用的功能
        </p>
    </div>

    <?php if ($cards === []): ?>
        <div class="app-empty">
            <i class="bi bi-inbox"></i>
            <p>目前沒有可使用的功能，請洽單位主管申請權限。</p>
        </div>
    <?php else: ?>
        <div class="app-cards">
            <?php foreach ($cards as $group): ?>
                <?php View::component('card', ['group' => $group]); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
