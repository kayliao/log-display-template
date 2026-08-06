<?php
/**
 * 每日生產日報
 *
 * 頁面入口。職責只有三件事：
 *   1. 檢查權限
 *   2. 定義報表欄位
 *   3. 交給 View 渲染
 *
 * 這裡不寫 SQL、不寫 HTML。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;

Auth::requirePermission('report.daily');

/**
 * 報表欄位定義。
 *
 * 這一份設定同時決定：表頭 HTML、前端顯示格式、後端允許排序的欄位、CSV 匯出欄位。
 *
 * 常用寫法：
 *   ['key' => 'xxx', 'title' => '欄位名']                          一般欄位
 *   ['key' => 'xxx', 'title' => '欄位名', 'tip' => '說明']          標題出現問號說明
 *   ['key' => 'xxx', 'title' => '欄位名', 'align' => 'right',
 *    'format' => 'number']                                        數字靠右並加千分位
 *   ['key' => 'xxx', 'title' => '欄位名', 'drill' => [             出現放大鏡可下鑽
 *        'api'    => url('/api/report/daily_detail.php'),
 *        'params' => ['xxx'],
 *   ]]
 *   ['title' => '大標', 'children' => [ ...小標... ]]               兩層表頭
 *
 * format 可用：number / decimal / percent / datetime / date / status
 */
$columns = [
    ['key' => 'col1', 'title' => '欄位一', 'width' => 120],
    ['key' => 'col2', 'title' => '欄位二'],
    ['key' => 'col3', 'title' => '數量', 'align' => 'right', 'format' => 'number'],
];

View::render('pages/report/daily', [
    'title'   => '每日生產日報',
    'note'    => '（請填寫這一頁的程式說明，會顯示在 header 的「程式說明」裡）',
    'columns' => $columns,
]);
