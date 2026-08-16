<?php
/**
 * 今日統整（水化排程頁的右上角那一塊）。
 *
 * 三層資訊：
 *   上面   stat_tile    今天做了多少、還有多少沒取號（一眼掃過去）
 *   中間   stat_card    各次水化的分佈（要看細一點的時候）
 *   下面   achievement  各次水化的取號進度（預計／實際／達成率）
 *
 * 數字是後端在頁面載入時算好的（HydrationService::todaySummary()），
 * 這一塊看的永遠是「今天」，不跟著下面的查詢條件跑 ——
 * 條件改成上週的話，這裡還是要顯示今天，否則「今日統整」四個字就不成立了。
 *
 * 三張卡都給了 api（同一支 /api/hydration/today.php），所以左邊匯入成功之後
 * 會自己重抓一次（上傳元件的 reload 指到這三個 id）。剛傳上去的那幾筆
 * 本來就算今天的，數字不跟著動的話，現場只會以為檔案沒進去。
 *
 * auto = false：初始數字已經由 PHP 畫在畫面上了，載入頁面時不用再打一次 API。
 * 三張卡指到同一個網址、各取各的 field，重抓時只會發出一次呼叫
 * （合併的規則在 app.http.js 的 shared，stat 與 achievement 走同一套）。
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

<?php
/**
 * 取號進度（達成率統整卡）。
 *
 * 跟上面兩張卡是同一支 API、同一包回應，只是取 achv 這個鍵 ——
 * 三張卡各給各的 field，前端仍然只打一次 API。
 *
 * 這張卡只吃 plan / actual 兩個數字，達成率、合計、佔比都是元件自己算的。
 * 範例把「機台已經來取過號」當成實際完成；要換成別的實績來源，
 * 改 HydrationService::todaySummary() 裡的 achv 就好，這裡一個字都不用動。
 *
 * target / warn 是顏色門檻，這裡刻意壓低（預設是 100 / 90）：
 * 今天還沒過完，取號率本來就不會是滿的，用預設值會整片紅色，
 * 看不出「元件會依門檻換顏色」這件事。
 */
View::component('achievement', [
    'id'         => 'aquaAchv',
    'title'      => '取號進度',
    'subtitle'   => $summary['subtitle'],
    'icon'       => 'upc-scan',
    'unit'       => '筆',
    'variant'    => 'plain',              // 已經在 panel 裡面了，不要再包一層外框
    'items'      => $summary['achv'],
    'totalLabel' => '今日取號率',
    'empty'      => '今天還沒有排程',

    'target'     => 60,
    'warn'       => 40,

    'api'        => url('/api/hydration/today.php'),
    'field'      => 'achv',
    'auto'       => false,
]); ?>

<p class="app-panel__hint">
    「未取號」是 <code>PACKET_LOT_TEMP_AUTO</code> 還是空的筆數 ——
    機台呼叫取號 API 之後，系統才會把封包批號產生出來寫回那一列。
</p>
