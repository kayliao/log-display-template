<?php

namespace App\Domain\Report;

use App\Core\TableQuery;
use App\Core\Db\Db;

/**
 * 排程達成率 —— 商業邏輯。
 *
 * 一天一個排程（水化、研磨、鍍膜…），底下分產品別（白片、彩片）與線別。
 * 現場最常問的三句話就是這一頁要回答的：
 *   今天排多少？做了多少？還差多少？
 */
class ScheduleService
{
    /**
     * 產品別的固定顏色。
     *
     * 統整卡不指定顏色的話會依順序取色，換一個排程、少一個產品別，
     * 白片就可能從藍色變成紫色。現場靠顏色認東西，所以釘死。
     */
    const CATEGORY_COLORS = [
        'WHITE' => '#0891b2',
        'COLOR' => '#7c3aed',
    ];

    /** @var ScheduleRepository */
    private $repo;

    public function __construct(?ScheduleRepository $repo = null)
    {
        $this->repo = $repo ?: new ScheduleRepository();
    }

    /**
     * 明細分頁查詢。
     */
    public function table(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->query($filters);

        return $query->paginate(Db::pg(), $sql, $bind);
    }

    /**
     * 統整卡要的資料。
     *
     * 回傳的 items 只有 label / plan / actual —— 達成率、合計、佔比
     * 全部交給 achievement 元件算，後端與前端不會各算一套。
     */
    public function summary(array $filters): array
    {
        $rows  = $this->repo->summary($filters);
        $items = [];
        $last  = '';

        foreach ($rows as $row) {
            $code = strtoupper((string) ($row['category'] ?? ''));

            $items[] = [
                'label'  => $row['category_name'] ?? $code,
                'plan'   => $row['plan_qty'] ?? 0,
                'actual' => $row['actual_qty'] ?? 0,
                'color'  => self::CATEGORY_COLORS[$code] ?? null,
            ];

            $last = max($last, (string) ($row['updated_at'] ?? ''));
        }

        $date     = $filters['plan_date'] ?? date('Y-m-d');
        $schedule = $this->scheduleName($filters['schedule_code'] ?? '');

        return [
            'title'    => $schedule . '排程達成',
            'subtitle' => $date . ($date === date('Y-m-d') ? '（今日）' : ''),
            'items'    => $items,

            // 現場最常問「這個數字是幾點的」，所以資料時間與查詢時間都寫出來
            'footer'   => ($last !== '' ? '資料更新至 ' . substr($last, 0, 16) . '　／　' : '')
                        . '統計時間 ' . date('H:i'),
        ];
    }

    /**
     * 排程下拉選單。
     *
     * 資料庫還沒接上時（或這個資料表還沒建）不要讓整頁掛掉，
     * 退回內建清單，畫面照樣開得起來。
     */
    public function scheduleOptions(): array
    {
        $options = [];

        try {
            foreach ($this->repo->schedules() as $row) {
                $options[$row['schedule_code']] = $row['schedule_name'];
            }
        } catch (\Throwable $e) {
            $options = [];
        }

        return $options !== [] ? $options : self::defaultSchedules();
    }

    /**
     * 產品別下拉選單。
     */
    public static function categoryOptions(): array
    {
        return [
            'WHITE' => '白片',
            'COLOR' => '彩片',
        ];
    }

    /**
     * 內建排程清單。匯入驗證與下拉選單共用同一份。
     */
    public static function defaultSchedules(): array
    {
        return [
            'HYD' => '水化',
            'GRD' => '研磨',
            'CTG' => '鍍膜',
        ];
    }

    private function scheduleName(string $code): string
    {
        $all = $this->scheduleOptions();

        return $all[$code] ?? '全部';
    }

    /**
     * 明細表格的欄位定義。
     *
     * 放在 Service 而不是頁面：畫面、排序白名單與 CSV 匯出都用這一份，
     * 兩邊各寫一份的話遲早會出現「畫面上有這一欄、匯出檔沒有」。
     */
    public static function columns(): array
    {
        return [
            ['key' => 'plan_date',     'title' => '日期',   'width' => 100, 'format' => 'date'],
            ['key' => 'schedule_name', 'title' => '排程',   'width' => 90],
            ['key' => 'category_name', 'title' => '產品別', 'width' => 80, 'align' => 'center'],
            ['key' => 'line_name',     'title' => '線別',   'width' => 80, 'align' => 'center'],

            ['title' => '數量', 'children' => [
                ['key' => 'plan_qty',   'title' => '預計', 'align' => 'right', 'format' => 'number'],
                ['key' => 'actual_qty', 'title' => '實際', 'align' => 'right', 'format' => 'number'],
                ['key' => 'diff_qty',   'title' => '差異', 'align' => 'right', 'format' => 'number',
                 'tip' => '實際 − 預計。負數表示還沒做完。'],
            ]],

            ['key' => 'achieve_rate', 'title' => '達成率', 'align' => 'right', 'format' => 'percent',
             'tip' => '實際 ÷ 預計。預計為 0 的線別不計算。'],

            ['key' => 'updated_at', 'title' => '更新時間', 'width' => 150, 'format' => 'datetime'],
        ];
    }
}
