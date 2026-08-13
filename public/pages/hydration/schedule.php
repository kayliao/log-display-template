<?php
/**
 * 水化排程管理。
 *
 * 版面是刻意不用分頁籤的那一種：上半左右各一半（上傳 / 今日統整），
 * 下半整片是資料查詢。三塊都在同一個畫面上，
 * 現場一邊傳檔一邊看數字變化，不用切來切去。
 *
 * 這一頁是整套模板裡最完整的一個範例，四件事都在裡面：
 *   - 上傳匯入，而且「有問題的列不擋整批」（partial 模式）
 *   - 今日統整（stat_tile + stat_card）
 *   - 條件查詢 + CSV 匯出 + 放大鏡
 *   - 機台 API 取封包批號（public/service/v1/packet-lot.php）
 *
 * 資料表 AQUA_SCHEDULE 的設計、索引、取號的併發處理全部寫在
 * docs/sql/hydration_oracle.sql。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Hydration\HydrationImportService;
use App\Domain\Hydration\HydrationService;

Auth::requirePermission('hydration.view');

$service = new HydrationService();

View::render('pages/hydration/schedule', [
    'title'         => '水化排程',
    'columns'       => HydrationService::columns(),
    'summary'       => $service->todaySummary(),
    'importColumns' => HydrationImportService::columns(),
    'canImport'     => can('hydration.import'),
]);
