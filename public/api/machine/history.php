<?php
/**
 * 單一機台的歷史 Log（詳細資料彈窗裡的「可查詢區塊」用）。
 *
 * 跟 /api/log/list.php 的差別：
 *   list.php     列表頁用，會分頁、可匯出、可跨多台機器
 *   history.php  彈窗用，固定一台機器、只回最近幾筆，不分頁
 *
 * 回傳 { rows: [...] } 就好——欄位定義已經寫在彈窗的 section 裡，
 * 兩邊不用各維護一份。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Log\MachineLogRepository;

// 看得到機台的人就看得到它的 Log
Auth::requirePermission('monitor.view');

$machineId = Request::str('machine_id');

if ($machineId === '') {
    throw new \App\Core\AppException('缺少機台編號。');
}

/**
 * 日期區間一樣要擋。
 * 彈窗的條件是使用者可以改的，改完就是一支普通的 API 請求，
 * 沒有比列表頁「更值得信任」。
 */
[$start, $end] = Request::dateRange('start_date', 'end_date', 'machine_log');

$filters = [
    'start_date'  => $start,
    'end_date'    => $end,
    'machine_ids' => [$machineId],
    'event_type'  => Request::str('event_type'),
];

[$sql, $bind] = (new MachineLogRepository())->query($filters);

/**
 * 彈窗裡不分頁，但一定要有上限——
 * 有人把區間拉到七天又剛好挑到一台狂噴警報的機器，
 * 沒有上限的話瀏覽器會直接卡死。
 */
$rows = Db::oracle()->select($sql . ' ORDER BY l.log_time DESC', $bind);
$rows = array_slice($rows, 0, 200);

Response::ok(['rows' => $rows, 'count' => count($rows)]);
