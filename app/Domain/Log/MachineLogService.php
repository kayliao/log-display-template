<?php

namespace App\Domain\Log;

use App\Core\AppException;
use App\Core\Db\Db;
use App\Core\Logger;
use App\Core\TableQuery;

/**
 * 機台 Log 的商業邏輯。
 */
class MachineLogService
{
    /** @var MachineLogRepository */
    private $repo;

    public function __construct(?MachineLogRepository $repo = null)
    {
        $this->repo = $repo ?: new MachineLogRepository();
    }

    /**
     * Log 查詢（分頁）。
     */
    public function search(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->query($filters);

        return $query->paginate(Db::oracle(), $sql, $bind);
    }

    /**
     * 匯出用：不分頁的完整結果。
     * 用 generator 一列一列吐出去，避免十萬筆一次進記憶體。
     */
    public function exportRows(array $filters): iterable
    {
        [$sql, $bind] = $this->repo->query($filters);

        // Oracle 的 fetch_all 沒有串流模式，這裡用分批撈的方式控制記憶體
        $page = 1;
        $size = 2000;

        do {
            $chunk = \App\Core\Db\Paginator::run(Db::oracle(), $sql, $bind, [
                'page'     => $page,
                'size'     => $size,
                'sort'     => 'log_time',
                'dir'      => 'asc',
                'sortable' => ['log_time'],
            ]);

            foreach ($chunk['rows'] as $row) {
                yield $row;
            }

            $page++;
        } while ($page <= $chunk['pages']);
    }

    /**
     * 放大鏡下鑽：看某筆 Log 前後 30 分鐘發生了什麼。
     */
    public function context(string $machineId, string $logTime): array
    {
        return [
            'title'    => $machineId . ' 前後 30 分鐘記錄',
            'sections' => [
                [
                    'type'    => 'table',
                    'title'   => $logTime . ' 前後 30 分鐘',
                    'columns' => [
                        ['key' => 'log_time',   'title' => '時間',   'width' => 160],
                        ['key' => 'event_code', 'title' => '代碼',   'width' => 100],
                        ['key' => 'event_type', 'title' => '類型',   'width' => 100],
                        ['key' => 'message',    'title' => '訊息'],
                        ['key' => 'operator',   'title' => '操作人', 'width' => 100],
                    ],
                    'rows'    => $this->repo->around($machineId, $logTime),
                ],
            ],
        ];
    }

    public function typeSummary(array $filters): array
    {
        return $this->repo->typeSummary($filters);
    }

    /**
     * 其他系統寫入 Log。
     *
     * 這是對外 API 唯一會呼叫的入口，
     * 所有驗證都集中在這裡，不管是誰呼叫都跑同一套規則。
     */
    public function record(array $payload, string $source): int
    {
        $machineId = trim((string) ($payload['machine_id'] ?? ''));
        if ($machineId === '') {
            throw new AppException('machine_id 不可為空。', 422);
        }

        $logTime = trim((string) ($payload['log_time'] ?? ''));
        if ($logTime === '') {
            $logTime = date('Y-m-d H:i:s');
        } elseif (!$this->isValidDateTime($logTime)) {
            throw new AppException('log_time 格式須為 YYYY-MM-DD HH:MM:SS。', 422);
        }

        $data = [
            'machine_id'   => $machineId,
            'log_time'     => $logTime,
            'event_code'   => mb_substr((string) ($payload['event_code'] ?? ''), 0, 50),
            'event_type'   => mb_substr((string) ($payload['event_type'] ?? 'INFO'), 0, 20),
            'message'      => mb_substr((string) ($payload['message'] ?? ''), 0, 1000),
            'operator'     => mb_substr((string) ($payload['operator'] ?? ''), 0, 50),
            'duration_sec' => isset($payload['duration_sec']) ? (int) $payload['duration_sec'] : null,
            'source'       => $source,
        ];

        $affected = $this->repo->insert($data);

        Logger::info('對外 API 寫入機台 Log', [
            'source'     => $source,
            'machine_id' => $machineId,
            'event_code' => $data['event_code'],
        ]);

        return $affected;
    }

    private function isValidDateTime(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        return $d && $d->format('Y-m-d H:i:s') === $value;
    }
}
