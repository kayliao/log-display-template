<?php

namespace App\Domain\Machine;

use App\Core\Db\Db;
use App\Core\Logger;
use App\Core\TableQuery;

/**
 * 機台相關的商業邏輯。
 *
 * Repository 負責「怎麼從資料庫拿」，Service 負責「拿到之後要做什麼」——
 * 狀態換算、資料整形、跨資料表組合。頁面與 API 只跟 Service 說話。
 */
class MachineService
{
    /** @var MachineRepository */
    private $repo;

    public function __construct(?MachineRepository $repo = null)
    {
        $this->repo = $repo ?: new MachineRepository();
    }

    /**
     * 機台狀態總表（分頁）。
     */
    public function statusTable(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->statusQuery($filters);

        $result = $query->paginate(Db::pg(), $sql, $bind);

        foreach ($result['rows'] as $i => $row) {
            $result['rows'][$i]['status_label'] = self::statusLabel($row['status'] ?? '');
        }

        return $result;
    }

    /**
     * 平面圖資料。
     * 順便附上狀態統計，讓頁面上方可以顯示「運轉 12 / 停機 3」這種摘要。
     */
    public function mapData(?string $area = null, ?string $floor = null): array
    {
        $machines = $this->repo->forMap($area, $floor);
        $summary  = [];

        foreach ($machines as $i => $m) {
            $status = strtoupper((string) ($m['status'] ?? 'OFF'));

            $machines[$i]['status']       = $status;
            $machines[$i]['status_label'] = self::statusLabel($status);
            // 座標與尺寸統一轉成前端需要的型別，前端就不用再做防呆
            $machines[$i]['y'] = (int) $m['y'];
            $machines[$i]['w'] = max(1, (int) ($m['w'] ?: 1));
            $machines[$i]['h'] = max(1, (int) ($m['h'] ?: 1));

            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        return [
            'machines' => $machines,
            'summary'  => $summary,
            'areas'    => $this->repo->areas(),
        ];
    }

    /**
     * 分頁籤用的樓層清單。
     * 跟 areas() 一樣，查不到就回空陣列，不要讓整頁打不開。
     */
    public function floors(): array
    {
        try {
            return $this->repo->floors();
        } catch (\Throwable $e) {
            Logger::warning('讀取樓層清單失敗', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 放大鏡點下去看到的內容。
     *
     * 回傳格式就是 App.modal.detail 認得的「多段區塊」結構：
     * 每一段可以是欄位清單（fields）或表格（table），
     * 段數與內容都由後端決定，前端不需要為每個彈窗各寫一套。
     */
    public function detail(string $machineId): array
    {
        $machine = $this->repo->find($machineId);

        if ($machine === null) {
            throw new \App\Core\AppException('查不到機台 ' . $machineId . ' 的資料。', 404);
        }

        $today = $this->todaySummary($machineId);

        return [
            'title'    => $machineId . ' ' . ($machine['machine_name'] ?? ''),
            'sections' => [
                [
                    /**
                     * type = fields 就是「把一筆資料立起來顯示」。
                     *
                     * 前端會排成兩欄等寬、由左至右填，跟 PHP 的 record 元件
                     * 是同一份長相。要幾欄就給 columns，要分段就用 children，
                     * 前端一行都不用改。
                     */
                    'type'    => 'fields',
                    'title'   => '基本資料',
                    'columns' => 2,
                    'fields'  => [
                        ['label' => '機台編號', 'value' => $machine['machine_id'], 'mono' => true],
                        ['label' => '機台名稱', 'value' => $machine['machine_name']],
                        ['label' => '機型',     'value' => $machine['model']],
                        ['label' => '廠區',     'value' => $machine['area']],
                        ['label' => '製造商',   'value' => $machine['maker'] ?? ''],
                        ['label' => '安裝日期', 'value' => $machine['install_date'] ?? '', 'format' => 'date'],
                        ['label' => '目前狀態', 'value' => self::statusLabel($machine['status'] ?? ''),
                         'badge' => strtolower((string) ($machine['status'] ?? ''))],
                        ['label' => '最後回報', 'value' => $machine['last_report_time'] ?? '',
                         'format' => 'datetime', 'mono' => true],

                        // 大項底下掛小項，跟表格的兩層表頭是同一個概念
                        ['title' => '今日累計', 'children' => [
                            ['title' => '時間分佈（分鐘）', 'children' => [
                                ['label' => '運轉', 'value' => $today['run_minutes'],  'format' => 'number'],
                                ['label' => '待機', 'value' => $today['idle_minutes'], 'format' => 'number'],
                                ['label' => '停機', 'value' => $today['down_minutes'], 'format' => 'number'],
                                ['label' => '稼動率', 'value' => $today['oee'], 'format' => 'percent'],
                            ]],
                            ['title' => '產量', 'children' => [
                                ['label' => '良品', 'value' => $today['qty_ok'], 'format' => 'number'],
                                ['label' => '不良', 'value' => $today['qty_ng'], 'format' => 'number'],
                            ]],
                        ]],

                        ['label' => '備註', 'value' => $machine['remark'] ?? '', 'span' => 'full'],
                    ],
                ],
                [
                    'type'    => 'table',
                    'title'   => '今日分時稼動',
                    'columns' => [
                        ['key' => 'hour_label',   'title' => '時段'],
                        ['key' => 'run_minutes',  'title' => '運轉(分)',  'align' => 'right'],
                        ['key' => 'idle_minutes', 'title' => '待機(分)',  'align' => 'right'],
                        ['key' => 'down_minutes', 'title' => '停機(分)',  'align' => 'right'],
                        ['key' => 'qty_ok',       'title' => '良品',      'align' => 'right'],
                        ['key' => 'qty_ng',       'title' => '不良',      'align' => 'right'],
                    ],
                    'rows'    => $this->repo->todayHourly($machineId),
                ],
                [
                    /**
                     * 可查詢區塊。
                     *
                     * 跟上面的 table 區塊差別：table 是後端一次算好送過來，
                     * 看完就沒了；query 有自己的查詢條件，使用者可以在彈窗裡
                     * 改條件重查，不用關掉彈窗回到列表頁再點一次。
                     *
                     * 前端只負責畫，條件與欄位都是這裡決定的。
                     */
                    'type'    => 'query',
                    'title'   => '歷史 Log 查詢',
                    'api'     => url('/api/machine/history.php'),
                    'params'  => ['machine_id' => $machineId],
                    'auto'    => true,
                    'empty'   => '這段期間沒有 Log 記錄。',
                    'fields'  => [
                        ['type' => 'date', 'name' => 'start_date', 'label' => '起',
                         'value' => date('Y-m-d', strtotime('-6 days'))],
                        ['type' => 'date', 'name' => 'end_date', 'label' => '迄',
                         'value' => date('Y-m-d')],
                        ['type' => 'select', 'name' => 'event_type', 'label' => '類型',
                         'empty' => '全部', 'options' => [
                             ['value' => 'ALARM', 'text' => '警報'],
                             ['value' => 'ERROR', 'text' => '錯誤'],
                             ['value' => 'WARN',  'text' => '警告'],
                             ['value' => 'INFO',  'text' => '一般'],
                             ['value' => 'OP',    'text' => '操作'],
                         ]],
                    ],
                    'columns' => [
                        ['key' => 'log_time',   'title' => '時間', 'width' => 150, 'format' => 'datetime'],
                        ['key' => 'event_type', 'title' => '類型', 'width' => 70],
                        ['key' => 'event_code', 'title' => '代碼', 'width' => 80],
                        ['key' => 'message',    'title' => '訊息'],
                        ['key' => 'operator',   'title' => '操作員', 'width' => 120],
                    ],
                ],
            ],
        ];
    }

    /**
     * 單一機台今日累計（詳細資料彈窗的「今日累計」那一段）。
     *
     * 把分時資料加總起來，而不是再查一次資料庫——
     * 同一個數字只有一個來源，畫面上不會出現分時表跟總計對不起來。
     */
    private function todaySummary(string $machineId): array
    {
        $sum = [
            'run_minutes'  => 0,
            'idle_minutes' => 0,
            'down_minutes' => 0,
            'qty_ok'       => 0,
            'qty_ng'       => 0,
        ];

        foreach ($this->repo->todayHourly($machineId) as $hour) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (int) ($hour[$key] ?? 0);
            }
        }

        $total = $sum['run_minutes'] + $sum['idle_minutes'] + $sum['down_minutes'];

        $sum['oee'] = $total > 0 ? round($sum['run_minutes'] * 100 / $total, 1) : 0;

        return $sum;
    }

    /**
     * 下拉選單用的廠區清單。
     *
     * 查不到就回空陣列，不要往上丟例外——
     * 這只是個下拉選單的選項，不該因為它讓整頁打不開。
     * （資料庫還沒接好時，頁面照樣要能顯示出來讓人看得到版面。）
     */
    public function areas(): array
    {
        try {
            return $this->repo->areas();
        } catch (\Throwable $e) {
            Logger::warning('讀取廠區清單失敗，下拉選單將只有「全部」', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 狀態代碼轉中文。
     * 集中在這裡，畫面、匯出、API 用的是同一份對照，不會各翻各的。
     */
    public static function statusLabel(string $status): string
    {
        $map = [
            'RUN'   => '運轉中',
            'IDLE'  => '待機',
            'DOWN'  => '停機',
            'ALARM' => '異常',
            'OFF'   => '關機',
        ];

        return $map[strtoupper($status)] ?? $status;
    }
}
