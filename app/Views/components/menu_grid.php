<?php
/**
 * 功能小卡牆。
 *
 * 一張卡 = 一個第一層選單。首頁跟 header 的「主選單」彈窗共用這一份，
 * 所以兩邊看到的功能永遠一致，也不會有一邊忘了改。
 *
 *   View::component('menu_grid', ['groups' => Menu::cards()]);
 *
 * 參數：
 *   groups   第一層選單陣列（Menu::cards() 已經依權限過濾過）
 *   compact  true = 卡片小一點，塞在彈窗裡用
 *   empty    沒有任何功能時要顯示的字
 */

use App\Core\View;

$groups = $groups ?? [];
?>
<?php if ($groups === []): ?>
    <div class="app-empty">
        <i class="bi bi-inbox"></i>
        <p><?= e($empty ?? '目前沒有可使用的功能，請洽單位主管申請權限。') ?></p>
    </div>
<?php else: ?>
    <div class="app-cards <?= !empty($compact) ? 'app-cards--compact' : '' ?>">
        <?php foreach ($groups as $group): ?>
            <?php View::component('card', ['group' => $group]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
