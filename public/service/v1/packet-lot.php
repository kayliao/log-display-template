<?php
/**
 * 機台 API：取封包批號（PACKET_LOT_TEMP_AUTO）。
 *
 * 機台拿著乾片批號（PPCUP_LOT）來要一個封包批號，
 * 本系統產生號碼、寫回對應的水化排程列，再把號碼回給機台。
 *
 * ── 怎麼呼叫 ────────────────────────────────────────────────
 *
 *   POST /service/v1/packet-lot.php
 *   Content-Type: application/json
 *   X-Api-Key: <金鑰>
 *
 *   單筆：{ "ppcup_lot": "PPCUP-A2408-10001", "update_user": "AQUA-M03" }
 *   多筆：{ "update_user": "AQUA-M03",
 *           "items": [ { "ppcup_lot": "..." }, ... ] }   一次最多 50 筆
 *
 *   update_user 是**機台名稱**，會寫進那一列的 UPDATE_USER 欄
 *   （頁面上傳時寫的則是登入者姓名）。
 *   沒帶的話就用 API 金鑰對應的呼叫端代號當預設值，所以這一欄永遠有值。
 *   每一筆要用不同名字時，也可以寫在 items 裡面那一層。
 *
 * ── 回傳 ────────────────────────────────────────────────────
 *
 *   { "ok": true,
 *     "message": "取號成功",
 *     "data": {
 *       "results": [
 *         { "ppcup_lot": "PPCUP-A2408-10001",
 *           "packet_lot_temp_auto": "PPCUP-A2408-H081201",
 *           "aqua_cycle_num": 2,
 *           "packet_schedule_date_code": "H0812",
 *           "reused": false }
 *       ],
 *       "failed": [ { "index": 1, "ppcup_lot": "...", "message": "..." } ]
 *     },
 *     "trace_id": "..." }
 *
 *   失敗時 ok = false，message 是可以直接顯示在機台畫面上的中文。
 *   HTTP 狀態碼：401 金鑰不對 / 404 查無此乾片批號 / 409 當天號碼用完
 *              / 422 參數有問題 / 503 系統忙碌請重試
 *
 * ── 兩件機台端要知道的事 ─────────────────────────────────────
 *
 * 【可以安全重送】同一個乾片批號重複呼叫會拿到**同一個號碼**（reused = true），
 *   不會每呼叫一次就燒掉一個號。逾時、斷線之後直接重送即可。
 *   號碼是寫在「該批號最新一次水化」那一列上的。
 *
 * 【一筆一交易】多筆是一筆一筆各自取號、各自 commit：
 *   其中一筆失敗（例如那個乾片批號還沒匯入水化排程）不會影響其他筆。
 *   號碼一旦發出去就是發出去了，不會因為同一批的別人失敗而收回 ——
 *   收回的話機台手上那個號碼就變成幽靈號碼。
 *
 * 【給機台廠商看的完整說明在 config/api_docs.php】，
 * 畫面是 /pages/dev/api_docs.php，可以匯出成單頁 HTML 寄給對方。
 * 這支的參數或行為有變動時，那一份要一起改。
 */

define('APP_API_ENTRY', true);
define('APP_NO_SESSION', true);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\AppException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\ServiceApi;
use App\Domain\Hydration\PackLotService;

ServiceApi::requireMethod('POST');

$client  = ServiceApi::authenticate();
$payload = Request::json();

/**
 * 單筆與多筆統一成陣列，空請求與超量在這裡就擋掉。
 *
 * 一次最多 50 筆：取號會鎖住「當日順序」那一列，一次進來太多筆會把鎖
 * 持有太久，其他機台就得排隊。這是刻意壓得比機台 Log（500 筆）低很多的。
 */
$items = ServiceApi::items(
    $payload,
    50,
    '沒有要取號的資料。',
    '單次最多取 50 個號，請分批送出。'
);

$service = new PackLotService();
$results = [];
$failed  = [];

/**
 * 機台名稱。優先順序：那一筆自己帶的 > 整包帶的 > API 金鑰對應的呼叫端代號。
 * UPDATE_USER 是 NOT NULL，所以最後一層一定要有值。
 */
$defaultUser = trim((string) ($payload['update_user'] ?? '')) ?: $client;

foreach ($items as $index => $item) {
    $ppcupLot   = is_array($item) ? (string) ($item['ppcup_lot'] ?? '') : (string) $item;
    $updateUser = is_array($item) && !empty($item['update_user'])
        ? (string) $item['update_user']
        : $defaultUser;

    try {
        $results[] = $service->allocate($ppcupLot, $updateUser);
    } catch (AppException $e) {
        // 機台端修得動的問題（批號不存在、當天號碼用完、系統忙碌）
        $failed[] = [
            'index'     => $index,
            'ppcup_lot' => $ppcupLot,
            'message'   => $e->getMessage(),
        ];
    } catch (\Throwable $e) {
        // 系統問題：詳細內容只寫 log，不回給外部系統
        Logger::error('取封包批號失敗', [
            'client'    => $client,
            'ppcup_lot' => $ppcupLot,
            'error'     => $e->getMessage(),
        ]);

        $failed[] = [
            'index'     => $index,
            'ppcup_lot' => $ppcupLot,
            'message'   => '系統錯誤，請稍後重試或聯絡資訊人員。',
        ];
    }
}

// 全部都失敗才算這次呼叫失敗；有一筆成功就回 200，讓機台照 failed 清單處理
if ($results === []) {
    ServiceApi::reject(
        $failed[0]['message'] ?? '取號失敗。',
        422,
        ['failed' => $failed]
    );
}

ServiceApi::success(
    ['results' => $results, 'failed' => $failed],
    $failed === [] ? '取號成功' : '部分取號成功'
);
