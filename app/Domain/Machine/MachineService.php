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
    public function mapData(?string $area = null): array
    {
        $machines = $this->repo->forMap($area);
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

        return [
            'title'    => $machineId . ' ' . ($machine['machine_name'] ?? ''),
            'sections' => [
                [
                    'type'   => 'fields',
                    'title'  => '基本資料',
                    'fields' => [
                        ['label' => '機台編號', 'value' => $machine['machine_id']],
                        ['label' => '機台名稱', 'value' => $machine['machine_name']],
                        ['label' => '機型',     'value' => $machine['model']],
                        ['label' => '廠區',     'value' => $machine['area']],
                        ['label' => '製造商',   'value' => $machine['maker'] ?? ''],
                        ['label' => '安裝日期', 'value' => $machine['install_date'] ?? ''],
                        ['label' => '目前狀態', 'value' => self::statusLabel($machine['status'] ?? ''),
                         'badge' => strtolower((string) ($machine['status'] ?? ''))],
                        ['label' => '最後回報', 'value' => $machine['last_report_time'] ?? ''],
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
            ],
        ];
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
