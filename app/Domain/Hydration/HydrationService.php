<?php

namespace App\Domain\Hydration;

use App\Core\Db\Db;
use App\Core\TableQuery;

/**
 * 水化排程 —— 商業邏輯。
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
     * 同一包資料有兩個出口：頁面第一次載入時由 PHP 直接畫（見
     * pages/hydration/_today.php），匯入成功之後由前端重新跟
     * /api/hydration/today.php 要一次。兩邊吃的是這一個方法，
     * 不會出現「重新整理才對得起來」的數字。
     *
     * subtitle 也一起回傳，是因為它寫的是統計日期：跨過午夜之後重抓，
     * 數字換了日期卻沒換的話，畫面等於在說謊。
     *
     * 一包餵三張卡，各取各的鍵（元件的 field 參數）：
     *   tiles   stat_tile     今日筆數、數量、乾片批號、未取號
     *   cycles  stat_card     各次水化的分佈
     *   achv    achievement   各次水化的取號進度（預計／實際／達成率）
     * 三張卡指到同一支 API，前端會合併成一次呼叫（App.http 的 shared）。
     *
     * @return array{date:string, subtitle:string, tiles:array, cycles:array, achv:array}
     */
    public function todaySummary(?string $date = null): array
    {
        $date   = $date ?: date('Y-m-d');
        $totals = $this->repo->todayTotals($date);

        $rows = (int) ($totals['row_cnt'] ?? 0);
        $qty  = (int) ($totals['qty_sum'] ?? 0);
        $lots = (int) ($totals['lot_cnt'] ?? 0);

        // COUNT(欄位) 算的是「有值」的筆數，所以未取號的要用總筆數減掉
        $noPacket = $rows - (int) ($totals['packet_cnt'] ?? 0);

        // 上面那排數字：一眼看今天的量，以及還有多少沒收尾
        $tiles = [
            ['label' => '今日筆數', 'value' => $rows, 'format' => 'number', 'unit' => '筆', 'icon' => 'list-ol'],
            ['label' => '今日數量', 'value' => $qty,  'format' => 'number', 'icon' => 'layers'],
            ['label' => '乾片批號', 'value' => $lots, 'format' => 'number', 'unit' => '批', 'icon' => 'upc-scan'],
            [
                'label'  => '未取號',
                'value'  => $noPacket,
                'format' => 'number',
                'unit'   => '筆',
                'icon'   => 'hourglass-split',
                'tone'   => $noPacket > 0 ? 'warning' : 'success',
                'hint'   => $noPacket > 0 ? '等機台來要封包批號' : '今天的都取完了',
            ],
        ];

        /**
         * 各次水化的分佈（stat_card 用）與取號進度（achievement 用）。
         *
         * 兩張卡吃同一支查詢的同一批列，只是看的欄位不同：
         *   分佈卡    ROW_CNT  佔今日筆數的比例（bar，不是達成率）
         *   達成率卡  ROW_CNT 當預計、TAKEN_CNT 當實際
         *
         * ⚠ 這裡的「實際」是拿「機台已經來取過號」當完成，
         *    只是為了讓範例有真的會動的數字。實務上請換成你的實績來源
         *    （像 report/schedule 那頁就是 mes_schedule_plan.actual_qty），
         *    元件只認 plan / actual 兩個鍵，換來源不用改前端。
         */
        $cycles = [];
        $achv   = [];

        foreach ($this->repo->todayByCycle($date) as $row) {
            $count = (int) ($row['row_cnt'] ?? 0);
            $label = '第 ' . (int) $row['aqua_cycle_num'] . ' 次水化';

            $cycles[] = [
                'label'  => $label,
                'value'  => $count,
                'unit'   => '筆',
                'format' => 'number',
                'bar'    => $rows > 0 ? round($count * 100 / $rows, 1) : 0,
                'hint'   => number_format((int) ($row['qty_sum'] ?? 0)) . ' 片',
            ];

            $achv[] = [
                'label'  => $label,
                'plan'   => $count,
                'actual' => (int) ($row['taken_cnt'] ?? 0),
                // 達成率、合計、佔比一律由元件算，這裡只給 plan / actual 兩個數字
            ];
        }

        if ($cycles === []) {
            $cycles[] = ['label' => '今天還沒有資料', 'value' => null, 'tone' => 'muted'];
        }

        return [
            'date'     => $date,
            'subtitle' => '統計日期 ' . $date,
            'tiles'    => $tiles,
            'cycles'   => $cycles,
            'achv'     => $achv,
        ];
    }

    /**
     * 放大鏡彈窗：一個乾片批號的水化歷程。
     *
     * 回傳的是 app.modal.js 吃的格式，前端一行都不用改。
     * 現場點進來多半是為了搞懂「為什麼我匯入被擋」，
     * 所以下一次應該填第幾次水化要直接寫出來。
     */
    public function lotDetail(string $ppcupLot): array
    {
        $rows = $this->repo->lotHistory($ppcupLot);

        if ($rows === []) {
            return [
                'title'    => $ppcupLot,
                'sections' => [
                    ['type' => 'html', 'html' => '<div class="app-empty"><i class="bi bi-inbox"></i>'
                                               . '<p>這個乾片批號目前沒有水化紀錄</p></div>'],
                ],
            ];
        }

        $last     = $rows[count($rows) - 1];
        $lastNum  = (int) $last['aqua_cycle_num'];
        $hasPack  = !empty($last['packet_lot_temp_auto']);

        return [
            'title'    => '乾片批號 ' . $ppcupLot,
            'sections' => [
                [
                    'type'    => 'fields',
                    'title'   => '目前狀態',
                    'columns' => 2,
                    'fields'  => [
                        ['label' => '已水化次數', 'value' => count($rows) . ' 次'],
                        ['label' => '最後一次',   'value' => '第 ' . $lastNum . ' 次（' . $last['aqua_schedule_date'] . '）'],
                        ['label' => '封包批號',   'value' => $last['packet_lot_temp_auto'] ?: '（尚未取號）', 'span' => 'full'],
                        [
                            'label' => '下一次匯入',
                            'span'  => 'full',
                            'value' => $hasPack
                                ? '可以匯入第 ' . ($lastNum + 1) . ' 次水化'
                                : '第 ' . $lastNum . ' 次還沒取號，重傳會直接覆蓋這一筆（upsert）',
                        ],
                    ],
                ],
                [
                    'type'    => 'table',
                    'title'   => '水化歷程',
                    'columns' => [
                        ['key' => 'aqua_cycle_num',          'title' => '第幾次', 'align' => 'center'],
                        ['key' => 'aqua_schedule_date',      'title' => '水化日期', 'format' => 'date'],
                        ['key' => 'qty',                     'title' => '數量',   'align' => 'right', 'format' => 'number'],
                        ['key' => 'packet_schedule_date_code', 'title' => '封包日編碼'],
                        ['key' => 'packet_lot_temp_auto',    'title' => '封包批號'],
                        ['key' => 'note',                    'title' => '備註'],
                        ['key' => 'update_user',             'title' => '更新人員'],
                        ['key' => 'update_time',             'title' => '更新時間', 'format' => 'datetime'],
                    ],
                    'rows'    => $rows,
                ],
            ],
        ];
    }

    /**
     * 明細表格的欄位定義。畫面、排序白名單與 CSV 匯出共用同一份。
     *
     * key 就是資料表的欄位名，這樣「畫面上這一欄」對應到「資料表哪一欄」
     * 不用再查對照表。
     */
    public static function columns(): array
    {
        return [
            ['key' => 'aqua_schedule_date', 'title' => '水化日期', 'width' => 110, 'format' => 'date'],

            ['key' => 'qty', 'title' => '數量', 'width' => 90, 'align' => 'right', 'format' => 'number'],

            ['key' => 'ppcup_lot', 'title' => '乾片批號', 'width' => 190,
             // 點放大鏡看這個批號的完整水化歷程，順便告訴使用者下一次該填第幾次
             'drill' => [
                 'api'    => url('/api/hydration/lot.php'),
                 'params' => ['ppcup_lot'],
             ]],

            ['key' => 'packet_schedule_date_code', 'title' => '封包日編碼', 'width' => 120, 'align' => 'center'],

            ['key' => 'aqua_cycle_num', 'title' => '第幾次水化', 'width' => 100, 'align' => 'center', 'format' => 'number',
             'tip' => '同一個乾片批號從 1 開始，必須連號。'],

            /**
             * 最後一欄用不同底色標出來：它跟前面那些「現場填的」不一樣，
             * 是機台來要號時系統自己寫進去的。
             */
            ['key' => 'packet_lot_temp_auto', 'title' => '封包批號', 'width' => 200,
             'className' => 'app-col--accent',
             'tip' => '機台呼叫取號 API 時由系統產生後寫回（PACKET_LOT_TEMP_AUTO）。空白表示還沒取號。'],

            ['key' => 'note', 'title' => '備註', 'width' => 180],

            /**
             * 誰、什麼時候動過這一列。
             * 匯入寫的是登入者姓名，機台取號寫的是機台名稱 ——
             * 現場問「這個號是誰要走的」時看這兩欄。
             */
            ['key' => 'update_user', 'title' => '更新人員', 'width' => 110, 'align' => 'center',
             'tip' => '匯入是登入者姓名，取號是機台名稱。'],

            ['key' => 'update_time', 'title' => '更新時間', 'width' => 150, 'format' => 'datetime'],
        ];
    }
}
