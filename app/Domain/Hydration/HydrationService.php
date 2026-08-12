<?php

namespace App\Domain\Hydration;

use App\Core\Db\Db;
use App\Core\TableQuery;

/**
 * 水化管理 —— 商業邏輯。
 */
class HydrationService
{
    /** @var HydrationRepository */
    private $repo;

    public function __construct(?HydrationRepository $repo = null)
    {
        $this->repo = $repo ?: new HydrationRepository();
    }

    /**
     * 明細分頁查詢。
     */
    public function table(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->query($filters);

        return $query->paginate(Db::oracle(), $sql, $bind);
    }

    /**
     * 今日統整。
     *
     * @return array{tiles:array, seq:array, date:string}
     */
    public function todaySummary(?string $date = null): array
    {
        $date   = $date ?: date('Y-m-d');
        $totals = $this->repo->todayTotals($date);

        $rows  = (int) ($totals['row_cnt'] ?? 0);
        $qty   = (int) ($totals['qty_sum'] ?? 0);
        $lots  = (int) ($totals['lot_cnt'] ?? 0);

        // COUNT(欄位) 算的是「有值」的筆數，所以未完成的要用總筆數減掉
        $open  = $rows - (int) ($totals['packed_cnt'] ?? 0);
        $noPre = $rows - (int) ($totals['pre_cnt'] ?? 0);

        // 上面那排數字：一眼看今天的量，以及還有多少沒收尾
        $tiles = [
            ['label' => '今日筆數', 'value' => $rows, 'format' => 'number', 'unit' => '筆', 'icon' => 'list-ol'],
            ['label' => '今日數量', 'value' => $qty,  'format' => 'number', 'unit' => '片', 'icon' => 'layers'],
            ['label' => '乾片批號', 'value' => $lots, 'format' => 'number', 'unit' => '批', 'icon' => 'upc-scan'],
            [
                'label'  => '未封包',
                'value'  => $open,
                'format' => 'number',
                'unit'   => '筆',
                'icon'   => 'hourglass-split',
                'tone'   => $open > 0 ? 'warning' : 'success',
                'hint'   => $noPre > 0 ? ('其中 ' . $noPre . ' 筆還沒取號') : '都已經取號',
            ],
        ];

        // 各次水化的分佈。bar 是「佔今日筆數的比例」，不是達成率。
        $seq = [];

        foreach ($this->repo->todayBySeq($date) as $row) {
            $count = (int) ($row['row_cnt'] ?? 0);

            $seq[] = [
                'label'  => '第 ' . (int) $row['hyd_seq'] . ' 次水化',
                'value'  => $count,
                'unit'   => '筆',
                'format' => 'number',
                'bar'    => $rows > 0 ? round($count * 100 / $rows, 1) : 0,
                'hint'   => number_format((int) ($row['qty_sum'] ?? 0)) . ' 片',
            ];
        }

        if ($seq === []) {
            $seq[] = ['label' => '今天還沒有資料', 'value' => null, 'tone' => 'muted'];
        }

        return [
            'date'  => $date,
            'tiles' => $tiles,
            'seq'   => $seq,
        ];
    }

    /**
     * 放大鏡彈窗：一個乾片批號的水化歷程。
     *
     * 回傳的是 app.modal.js 吃的格式，前端一行都不用改。
     * 現場點進來多半是為了搞懂「為什麼我匯入被擋」，
     * 所以下一次應該填第幾次水化要直接寫出來。
     */
    public function lotDetail(string $dryLotNo): array
    {
        $rows = $this->repo->lotHistory($dryLotNo);

        if ($rows === []) {
            return [
                'title'    => $dryLotNo,
                'sections' => [
                    ['type' => 'html', 'html' => '<div class="app-empty"><i class="bi bi-inbox"></i>'
                                               . '<p>這個乾片批號目前沒有水化紀錄</p></div>'],
                ],
            ];
        }

        $last    = $rows[count($rows) - 1];
        $lastSeq = (int) $last['hyd_seq'];
        $packed  = !empty($last['pack_lot_no']);

        return [
            'title'    => '乾片批號 ' . $dryLotNo,
            'sections' => [
                [
                    'type'    => 'fields',
                    'title'   => '目前狀態',
                    'columns' => 2,
                    'fields'  => [
                        ['label' => '已水化次數', 'value' => count($rows) . ' 次'],
                        ['label' => '最後一次',   'value' => '第 ' . $lastSeq . ' 次（' . $last['hyd_date'] . '）'],
                        ['label' => '封包批號',   'value' => $last['pack_lot_no'] ?: '（尚未封包）'],
                        ['label' => '預配封包批號', 'value' => $last['pre_pack_lot_no'] ?: '（尚未取號）'],
                        [
                            'label' => '下一次匯入',
                            'span'  => 'full',
                            'value' => $packed
                                ? '可以匯入第 ' . ($lastSeq + 1) . ' 次水化'
                                : '第 ' . $lastSeq . ' 次還沒封包，重傳會直接覆蓋這一筆（upsert）',
                        ],
                    ],
                ],
                [
                    'type'    => 'table',
                    'title'   => '水化歷程',
                    'columns' => [
                        ['key' => 'hyd_seq',         'title' => '第幾次', 'align' => 'center'],
                        ['key' => 'hyd_date',        'title' => '日期',   'format' => 'date'],
                        ['key' => 'qty',             'title' => '數量',   'align' => 'right', 'format' => 'number'],
                        ['key' => 'hyd_day_code',    'title' => '水化日編號'],
                        ['key' => 'pack_lot_no',     'title' => '封包批號'],
                        ['key' => 'pre_pack_lot_no', 'title' => '預配封包批號'],
                        ['key' => 'source',          'title' => '來源'],
                        ['key' => 'updated_at',      'title' => '更新時間', 'format' => 'datetime'],
                    ],
                    'rows'    => $rows,
                ],
            ],
        ];
    }

    /**
     * 明細表格的欄位定義。畫面、排序白名單與 CSV 匯出共用同一份。
     */
    public static function columns(): array
    {
        return [
            ['key' => 'hyd_date', 'title' => '日期', 'width' => 100, 'format' => 'date'],

            ['key' => 'qty', 'title' => '數量', 'width' => 80, 'align' => 'right', 'format' => 'number'],

            ['key' => 'dry_lot_no', 'title' => '乾片批號', 'width' => 160,
             // 點放大鏡看這個批號的完整水化歷程，順便告訴使用者下一次該填第幾次
             'drill' => [
                 'api'    => url('/api/hydration/lot.php'),
                 'params' => ['dry_lot_no'],
             ]],

            ['key' => 'hyd_day_code', 'title' => '水化日編號', 'width' => 110, 'align' => 'center'],

            ['key' => 'pack_lot_no', 'title' => '封包批號', 'width' => 170,
             'tip' => '封包完成後才會有。空白表示這一次水化還沒收尾。'],

            ['key' => 'hyd_seq', 'title' => '第幾次水化', 'width' => 100, 'align' => 'center', 'format' => 'number',
             'tip' => '同一個乾片批號從 1 開始，必須連號。'],

            /**
             * 最後一欄用不同底色標出來：它跟前面那些「現場填的」不一樣，
             * 是系統在對外 API 取號時自己寫進去的。
             */
            ['key' => 'pre_pack_lot_no', 'title' => '預配封包批號', 'width' => 180,
             'className' => 'app-col--accent',
             'tip' => '對外 API 取號後由系統寫回，代表號碼已經配出去、但封包還沒完成。'],
        ];
    }
}
