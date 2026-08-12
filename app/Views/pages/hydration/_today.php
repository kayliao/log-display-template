<?php
/**
 * 今日統整（水化管理頁的右上角那一塊）。
 *
 * 兩層資訊：
 *   上面 stat_tile  今天做了多少、還有多少沒收尾（一眼掃過去）
 *   下面 stat_card  各次水化的分佈（要看細一點的時候）
 *
 * 數字是後端在頁面載入時算好的（HydrationService::todaySummary()），
 * 這一塊看的永遠是「今天」，不跟著下面的查詢條件跑 ——
 * 條件改成上週的話，這裡還是要顯示今天，否則「今日統整」四個字就不成立了。
 */

use App\Core\View;
?>

<?php View::component('stat_tile', ['items' => $summary['tiles'], 'min' => 130]); ?>

<?php View::component('stat_card', [
    'title'   => '各次水化',
    'variant' => 'plain',
    'items'   => $summary['seq'],
]); ?>

<p class="app-panel__hint">
    統計日期 <?= e($summary['date']) ?>。
    「未封包」是還沒拿到正式封包批號的筆數 ——
    封包端呼叫取號 API 之後，預配封包批號會先寫回來，
    等實際封包完成才會有正式的封包批號。
</p>
