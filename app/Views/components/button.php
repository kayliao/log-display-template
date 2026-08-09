<?php
/**
 * 按鈕。
 *
 *   View::component('button', ['label' => '查詢', 'icon' => 'search', 'variant' => 'primary']);
 *   View::component('button', ['label' => '匯出', 'icon' => 'download', 'url' => '/api/x.php?export=csv']);
 *
 * 有給 url 就渲染成 <a>，沒給就是 <button>——
 * 這樣「看起來一樣的按鈕」不會因為一個是連結一個是動作而長得不一樣。
 *
 * 參數：
 *   variant  primary | secondary | outline | danger | success | link（預設 secondary）
 *   size     sm | md | lg（預設 sm，現場的查詢列都用 sm）
 *   icon     Bootstrap Icon 名稱
 *   iconOnly true 表示只顯示圖示（label 會變成 title 與無障礙名稱）
 *   block    true 表示佔滿整行
 *   type     button | submit | reset（預設 button）
 *   attrs    其他屬性，例如 ['data-role' => 'export']
 */

$label    = $label   ?? '';
$icon     = $icon    ?? '';
$variant  = $variant ?? 'secondary';
$size     = $size    ?? 'sm';
$url      = $url     ?? '';
$type     = $type    ?? 'button';
$iconOnly = !empty($iconOnly);
$attrs    = $attrs   ?? [];

$variants = [
    'primary'   => 'btn-primary',
    'secondary' => 'btn-outline-secondary',
    'outline'   => 'btn-outline-primary',
    'danger'    => 'btn-danger',
    'success'   => 'btn-success',
    'link'      => 'btn-link',
];

$classes = ['btn', $variants[$variant] ?? $variants['secondary']];

if ($size === 'sm') { $classes[] = 'btn-sm'; }
if ($size === 'lg') { $classes[] = 'btn-lg'; }
if (!empty($block)) { $classes[] = 'w-100'; }
if ($iconOnly)      { $classes[] = 'app-btn--icon'; }
if (!empty($class)) { $classes[] = $class; }

$html = '';
foreach ($attrs as $key => $value) {
    $html .= ' ' . e($key) . '="' . e($value) . '"';
}
if (!empty($disabled)) {
    $html .= $url !== '' ? ' aria-disabled="true"' : ' disabled';
}
if ($iconOnly && $label !== '') {
    $html .= ' title="' . e($label) . '" aria-label="' . e($label) . '"';
}

$inner = ($icon !== '' ? '<i class="bi bi-' . e($icon) . '"></i>' : '')
       . ($iconOnly || $label === '' ? '' : ' ' . e($label));
?>
<?php if ($url !== ''): ?>
    <a class="<?= e(implode(' ', $classes)) ?>" href="<?= e($url) ?>"<?= $html ?>><?= $inner ?></a>
<?php else: ?>
    <button type="<?= e($type) ?>" class="<?= e(implode(' ', $classes)) ?>"<?= $html ?>><?= $inner ?></button>
<?php endif; ?>
