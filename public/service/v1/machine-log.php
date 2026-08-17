<?php
/**
 * 對外 API：其他系統寫入機台 Log。
 *
 * 這是「純後端」端點，給 MES / SCADA / PLC 收集器之類的系統呼叫，
 * 跟前端頁面完全無關：不吃 Session、不吃 Cookie，用 API 金鑰驗證。
 *
 * 呼叫方式：
 *   POST /service/v1/machine-log.php
 *   Content-Type: application/json
 *   X-Api-Key: <金鑰>
 *
 *   單筆：
 *     { "machine_id":"M-101", "log_time":"2026-08-06 13:45:00",
 *       "event_code":"AL-102", "event_type":"ALARM",
 *       "message":"主軸溫度過高", "operator":"E0012", "duration_sec":45 }
 *
 *   多筆（一次最多 500 筆）：
 *     { "items": [ {...}, {...} ] }
 *
 * 回傳：
 *   { "ok":true, "data":{ "inserted":2, "failed":[] }, "trace_id":"..." }
 *
 * 多筆寫入採「整批交易」：有任何一筆失敗就全部回滾，
 * 避免呼叫端重送時產生半套資料。
 */

define('APP_API_ENTRY', true);
define('APP_NO_SESSION', true);   // 對外 API 不需要 Session

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Db\Db;
use App\Core\Request;
use App\Core\ServiceApi;
use App\Domain\Log\MachineLogService;

ServiceApi::requireMethod('POST');

$client  = ServiceApi::authenticate();
$payload = Request::json();

// 單筆與多筆統一成陣列處理，空請求與超量在這裡就擋掉（還沒開交易）
$items = ServiceApi::items(
    $payload,
    500,
    '沒有可寫入的資料。',
    '單次最多寫入 500 筆，請分批送出。'
);

$service  = new MachineLogService();
$inserted = 0;

try {
    Db::oracle()->transaction(function () use ($items, $service, $client, &$inserted) {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                throw new \App\Core\AppException("第 " . ($index + 1) . " 筆資料格式錯誤。", 422);
            }

            $inserted += $service->record($item, $client);
        }
    });
} catch (\App\Core\AppException $e) {
    // 資料格式問題：回 422 並說明是哪一筆，呼叫端才修得動
    ServiceApi::reject($e->getMessage(), $e->status());
} catch (\Throwable $e) {
    // 系統問題：詳細內容只寫 log，不回給外部系統
    \App\Core\Logger::error('對外 API 寫入失敗', [
        'client' => $client,
        'error'  => $e->getMessage(),
    ]);

    ServiceApi::reject('資料寫入失敗，請稍後重試或聯絡資訊人員。', 500);
}

ServiceApi::success(['inserted' => $inserted], '寫入成功');
