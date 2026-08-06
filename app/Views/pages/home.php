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

    <?php
    // 跟 header「主選單」彈窗是同一個元件，兩邊看到的功能不會不一樣
    View::component('menu_grid', ['groups' => $cards]);
    ?>

</div>
