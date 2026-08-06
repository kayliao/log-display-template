<?php
/**
 * 通用下拉選單 —— 按一下按鈕，底下展開一張清單。
 *
 * header 的子選單是它做的，但它不綁選單：
 * 表格上的「更多動作」、「匯出格式」這種也可以直接拿來用。
 *
 *   View::component('dropdown', [
 *       'icon'  => 'list-ul',
 *       'label' => '子選單',
 *       'items' => [
 *           ['title' => '機台狀態總表', 'icon' => 'list-check',
 *            'url' => '/pages/machine/status.php', 'active' => true],
 *
 *           ['divider' => true],
 *
 *           // 沒給 url 就變成按鈕，行為自己用 data-role 綁
 *           ['title' => '匯出 CSV', 'icon' => 'download',
 *            'attrs' => ['data-role' => 'export-csv']],
 *       ],
 *   ]);
 *
 * 參數：
 *   items    選項陣列，欄位見下方
 *   html     自訂選單內容；有給就不看 items（要塞表單、色票這類東西時用）
 *   icon     按鈕圖示（Bootstrap Icons，不含 bi- 前綴）
 *   label    按鈕文字
 *   variant  nav（預設，選單列樣式）| icon（header 右側圖示鈕樣式）
 *   active   true = 按鈕標示成「目前所在」
 *   caret    是否顯示小箭頭，預設 true
 *   align    end = 選單靠按鈕右緣對齊（按鈕在畫面右側時用）
 *   width    選單最小寬度（px），預設 220
 *   title    滑鼠移上去的提示文字
 *
 * items 每一項可用的欄位：
 *   divider     true = 這一項是分隔線
 *   header      有值 = 這一項是小標題（分組用）
 *   title       顯示文字
 *   icon        圖示
 *   url         連結；不給就渲染成按鈕
 *   active      true = 標成目前所在
 *   badge       右側小標籤（例如舊頁面標「舊」）
 *   badge_title 小標籤的提示文字
 *   attrs       額外屬性，例如 ['data-role' => 'export-csv']
 *
 * 權限過濾請在傳進來之前做完（例如 Menu::tree() 已經濾過了），
 * 這個元件不認得權限，給它什麼就畫什麼。
 */

use App\Core\View;

$items   = $items ?? [];
$width   = (int) ($width ?? 220);
$menuCls = 'dropdown-menu app-dropdown' . (($align ?? '') === 'end' ? ' dropdown-menu-end' : '');
?>
<div class="dropdown app-nav__item">

    <?= View::componentHtml('_trigger', [
        'variant' => $variant ?? 'nav',
        'icon'    => $icon ?? null,
        'label'   => $label ?? '',
        'title'   => $title ?? null,
        'caret'   => $caret ?? true,
        'active'  => $active ?? false,
        'id'      => $id ?? null,
        'attrs'   => [
            'data-bs-toggle'  => 'dropdown',
            // static：不讓 Popper 把選單翻到別的方向，header 空間小容易亂跑
            'data-bs-display' => 'static',
            'aria-expanded'   => 'false',
        ],
    ]) ?>

    <ul class="<?= e($menuCls) ?>" style="min-width: <?= $width ?>px">
        <?php if (isset($html)): ?>
            <li class="app-dropdown__custom"><?= $html ?></li>
        <?php else: ?>
            <?php foreach ($items as $item): ?>

                <?php if (!empty($item['divider'])): ?>
                    <li><hr class="dropdown-divider"></li>
                    <?php continue; ?>
                <?php endif; ?>

                <?php if (!empty($item['header'])): ?>
                    <li><h6 class="dropdown-header"><?= e($item['header']) ?></h6></li>
                    <?php continue; ?>
                <?php endif; ?>

                <?php
                $tag      = !empty($item['url']) ? 'a' : 'button';
                $itemCls  = 'dropdown-item' . (!empty($item['active']) ? ' active' : '');
                $extra    = $item['attrs'] ?? [];
                ?>
                <li>
                    <<?= $tag ?> class="<?= e($itemCls) ?>"
                        <?php if ($tag === 'a'): ?>
                            href="<?= e(url($item['url'])) ?>"
                        <?php else: ?>
                            type="button"
                        <?php endif; ?>
                        <?php foreach ($extra as $name => $value): ?> <?= e($name) ?>="<?= e($value) ?>"<?php endforeach; ?>>
                        <i class="bi bi-<?= e($item['icon'] ?? 'dot') ?>"></i>
                        <span><?= e($item['title'] ?? '') ?></span>
                        <?php if (!empty($item['badge'])): ?>
                            <span class="badge-legacy" title="<?= e($item['badge_title'] ?? '') ?>"><?= e($item['badge']) ?></span>
                        <?php endif; ?>
                    </<?= $tag ?>>
                </li>

            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>
