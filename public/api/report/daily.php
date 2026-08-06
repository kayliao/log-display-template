<?php
/**
 * 每日生產日報 —— 資料 API。
 *
 * 一支端點同時支援兩種輸出：
 *   一般查詢    => JSON 分頁結果
 *   ?export=csv => CSV 全量下載
 * 用同一份 SQL、同一套條件，畫面看到的和匯出的保證一致。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableQuery;
use App\Domain\Report\DailyRepository;
use App\Domain\Report\DailyService;

Auth::requirePermission('report.daily');

/**
 * 日期區間驗證。
 * 前端日曆雖然已經擋過，但使用者可以直接打 API，後端一定要再擋一次，
 * 否則一句 SQL 就能把資料庫拖垮。
 * 若這一頁不需要日期條件，把這兩行刪掉即可。
 */
[$start, $end] = Request::dateRange('daily_date_start', 'daily_date_end', 'report');

$filters = [
    'start_date' => $start,
    'end_date'   => $end,
    'type'       => Request::str('type'),
    'keyword'    => Request::str('keyword'),
];

/**
 * 允許排序的欄位白名單。
 * 不在名單內的排序請求會被忽略——排序欄位是從前端傳來的，
 * 不檢查就拼進 SQL 等於開後門。
 */
$sortable = ['col1', 'col2', 'col3'];

$service = new DailyService();

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new DailyRepository())->query($filters);

    Response::csv(
        '每日生產日報_' . date('Ymd_His'),
        [
            'col1' => '欄位一',
            'col2' => '欄位二',
            'col3' => '數量',
        ],
        Db::pg()->select($sql . ' ORDER BY col1', $bind)
    );
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($sortable, 'col1', 'asc');
$result = $service->table($filters, $query);

$query->respond($result);
