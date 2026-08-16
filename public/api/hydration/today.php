<?php
/**
 * 水化排程 —— 今日統整。
 *
 * 回傳的就是頁面第一次載入時 PHP 用的那一包
 * （HydrationService::todaySummary()）：
 *
 *   { "date": "2026-08-14", "subtitle": "統計日期 2026-08-14",
 *     "tiles":  [ { "label": "今日筆數", "value": 128, ... }, ... ],
 *     "cycles": [ { "label": "第 1 次水化", "value": 64, ... }, ... ],
 *     "achv":   [ { "label": "第 1 次水化", "plan": 64, "actual": 41 }, ... ] }
 *
 * 一包餵三張卡，各取各的鍵（元件的 field 參數）：
 * stat_tile 吃 tiles、stat_card 吃 cycles、achievement 吃 achv。
 * 三張卡指到同一個網址，前端會合併成一次呼叫
 * （App.http 的 shared，見 public/assets/js/app.http.js）。
 *
 * 這一支刻意不吃任何查詢條件：「今日統整」看的永遠是伺服器當天，
 * 不跟著下面明細表的日期區間跑。條件改成上週還顯示上週的話，
 * 「今日統整」四個字就不成立了。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Response;
use App\Domain\Hydration\HydrationService;

Auth::requirePermission('hydration.view');

Response::ok((new HydrationService())->todaySummary());
