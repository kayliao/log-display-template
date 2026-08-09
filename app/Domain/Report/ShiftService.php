<?php

namespace App\Domain\Report;

use App\Core\Db\Db;
use App\Core\TableQuery;

/**
 * 班別產量報表 —— 商業邏輯。
 */
class ShiftService
{
    /** @var ShiftRepository */
    private $repo;

    public function __construct(?ShiftRepository $repo = null)
    {
        $this->repo = $repo ?: new ShiftRepository();
    }

    /**
     * 分頁查詢。
     */
    public function table(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->query($filters);

        return $query->paginate(Db::pg(), $sql, $bind);
    }

    /**
     * 表格欄位定義。
     *
     * 放在 Service 而不是頁面，是因為畫面與 CSV 匯出都要用同一份——
     * 兩邊各寫一份的話，遲早會出現「畫面上有這一欄、匯出檔沒有」。
     *
     * 這份定義示範三層表頭：
     *
     *   ┌──────┬────────────────────────────────────┬────────┐
     *   │ 機台 │              今日產量               │  合計  │
     *   │      ├─────────────────┬──────────────────┤        │
     *   │      │      白班       │       夜班       │        │
     *   │      ├─────┬────┬──────┼─────┬────┬───────┤        │
     *   │      │良品 │不良│稼動率│良品 │不良│稼動率 │良品│不良│
     *
     * children 想掛幾層就掛幾層，ColumnSet 會自己算出 colspan 與 rowspan。
     */
    public static function columns(): array
    {
        return [
            ['key' => 'machine_id', 'title' => '機台編號', 'width' => 110,
             'drill' => [
                 'api'    => url('/api/machine/detail.php'),
                 'params' => ['machine_id'],
             ]],

            ['key' => 'machine_name', 'title' => '機台名稱', 'width' => 150],
            ['key' => 'area',         'title' => '廠區',     'width' => 70, 'align' => 'center'],

            ['title' => '今日產量', 'children' => [

                ['title' => '白班（08:00–20:00）', 'children' => [
                    ['key' => 'd_ok',  'title' => '良品',   'align' => 'right', 'format' => 'number'],
                    ['key' => 'd_ng',  'title' => '不良',   'align' => 'right', 'format' => 'number'],
                    ['key' => 'd_oee', 'title' => '稼動率', 'align' => 'right', 'format' => 'percent',
                     'tip' => '白班運轉時間 ÷ 720 分鐘'],
                ]],

                ['title' => '夜班（20:00–08:00）', 'children' => [
                    ['key' => 'n_ok',  'title' => '良品',   'align' => 'right', 'format' => 'number'],
                    ['key' => 'n_ng',  'title' => '不良',   'align' => 'right', 'format' => 'number'],
                    ['key' => 'n_oee', 'title' => '稼動率', 'align' => 'right', 'format' => 'percent',
                     'tip' => '夜班運轉時間 ÷ 720 分鐘'],
                ]],
            ]],

            ['title' => '全日合計', 'children' => [
                ['key' => 'total_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
            ]],
        ];
    }
}
