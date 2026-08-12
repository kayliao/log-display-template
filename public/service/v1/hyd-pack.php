<?php
/**
 * 對外 API：取封包批號。
 *
 * 別的系統（封包端）拿著乾片批號來要一個封包批號，
 * 本系統產生號碼、寫回對應的水化紀錄，再把號碼回給對方。
 *
 * 呼叫方式：
 *   POST /service/v1/hyd-pack.php
 *   Content-Type: application/json
 *   X-Api-Key: <金鑰>
 *
 *   單筆：{ "dry_lot_no": "DRY-A2408-10001" }
 *   多筆：{ "items": [ { "dry_lot_no": "..." }, ... ] }   一次最多 50 筆
 *
 * 回傳：
 *   { "ok": true,
 *     "data": {
 *       "results": [
 *         { "dry_lot_no": "DRY-A2408-10001", "pre_pack_lot_no": "DRY-A2408H081201",
 *           "hyd_seq": 2, "reused": false }
 *       ],
 *       "failed": [ { "dry_lot_no": "...", "message": "..." } ]
 *     } }
 *
 * ── 兩件呼叫端要知道的事 ─────────────────────────────────────
 *
 * 【可以安全重送】同一個乾片批號重複呼叫會拿到**同一個號碼**（reused = true），
 *   不會每呼叫一次就燒掉一個號。逾時、斷線之後直接重送即可。
 *
 * 【一筆一交易】多筆是一筆一筆各自取號、各自 commit：
 *   其中一筆失敗（例如那個乾片批號還沒匯入水化資料）不會影響其他筆。
 *   號碼一旦發出去就是發出去了，不會因為同一批的別人失敗而收回 ——
 *   收回的話對方手上那個號碼就變成幽靈號碼。
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

$items = isset($payload['items']) && is_array($payload['items'])
    ? $payload['items']
    : [$payload];

if ($items === []) {
    ServiceApi::reject('沒有要取號的資料。', 422);
}

/**
 * 一次最多 50 筆。
 * 取號會鎖住「當日順序」那一列，一次進來太多筆會把鎖持有太久，
 * 其他呼叫端就得排隊。這是刻意壓得比機台 Log（500 筆）低很多的。
 */
if (count($items) > 50) {
    ServiceApi::reject('單次最多取 50 個號，請分批送出。', 422);
}

$service = new PackLotService();
$results = [];
$failed  = [];

foreach ($items as $index => $item) {
    $dryLotNo = is_array($item) ? (string) ($item['dry_lot_no'] ?? '') : (string) $item;

    try {
        $results[] = $service->allocate($dryLotNo);
    } catch (AppException $e) {
        // 呼叫端修得動的問題（批號不存在、當天號碼用完、系統忙碌）
        $failed[] = [
            'index'      => $index,
            'dry_lot_no' => $dryLotNo,
            'message'    => $e->getMessage(),
        ];
    } catch (\Throwable $e) {
        // 系統問題：詳細內容只寫 log，不回給外部系統
        Logger::error('取封包批號失敗', [
            'client'     => $client,
            'dry_lot_no' => $dryLotNo,
            'error'      => $e->getMessage(),
        ]);

        $failed[] = [
            'index'      => $index,
            'dry_lot_no' => $dryLotNo,
            'message'    => '系統錯誤，請稍後重試或聯絡資訊人員。',
        ];
    }
}

// 全部都失敗才算這次呼叫失敗；有一筆成功就回 200，讓對方照 failed 清單處理
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
