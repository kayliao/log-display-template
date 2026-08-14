<?php
/**
 * 今日統整（水化排程頁的右上角那一塊）。
 *
 * 兩層資訊：
 *   上面 stat_tile  今天做了多少、還有多少沒取號（一眼掃過去）
 *   下面 stat_card  各次水化的分佈（要看細一點的時候）
 *
 * 數字是後端在頁面載入時算好的（HydrationService::todaySummary()），
 * 這一塊看的永遠是「今天」，不跟著下面的查詢條件跑 ——
 * 條件改成上週的話，這裡還是要顯示今天，否則「今日統整」四個字就不成立了。
 *
 * 兩張卡都給了 api（同一支 /api/hydration/today.php），所以左邊匯入成功之後
 * 會自己重抓一次（上傳元件的 reload 指到這兩個 id）。剛傳上去的那幾筆
 * 本來就算今天的，數字不跟著動的話，現場只會以為檔案沒進去。
 *
 * auto = false：初始數字已經由 PHP 畫在畫面上了，載入頁面時不用再打一次 API。
 * 兩張卡指到同一個網址，重抓時 app.stat.js 會合併成一次呼叫。
 *
 * 統計日期寫在 stat_card 的副標，不寫在下面那行說明裡：它是會跟著資料變的字，
 * 放在卡片裡才會跟數字一起換掉（跨過午夜之後重抓就看得出差別）。
 */

use App\Core\View;
?>

<?php View::component('stat_tile', [
    'id'    => 'aquaToday',
    'items' => $summary['tiles'],
    'min'   => 130,

    'api'   => url('/api/hydration/today.php'),
    'field' => 'tiles',
    'auto'  => false,
]); ?>

<?php View::component('stat_card', [
    'id'       => 'aquaCycles',
    'title'    => '各次水化',
    'subtitle' => $summary['subtitle'],
    'variant'  => 'plain',
    'items'    => $summary['cycles'],

    'api'      => url('/api/hydration/today.php'),
    'field'    => 'cycles',
    'auto'     => false,
]); ?>

<p class="app-panel__hint">
    「未取號」是 <code>PACKET_LOT_TEMP_AUTO</code> 還是空的筆數 ——
    機台呼叫取號 API 之後，系統才會把封包批號產生出來寫回那一列。
</p>
