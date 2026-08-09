<?php

namespace App\Domain\Log;

use App\Core\Db\Connection;
use App\Core\Db\Db;
use App\Support\Sql;

/**
 * 機台 Log 資料存取。
 *
 * ⚠ SQL 為範例，欄位請對照實際資料表調整。
 *
 * 這一支示範的重點是「Oracle 端」的寫法：
 *   - 日期比較用 TO_DATE 而不是字串比大小
 *   - 一定要走時間索引，所以 WHERE 條件的第一項永遠是時間區間
 *   - 不寫 LIMIT，分頁交給 Paginator 處理（它會依資料庫產生正確語法）
 *
 * 假設的資料表：
 *   MES_MACHINE_LOG(LOG_ID, MACHINE_ID, LOG_TIME, EVENT_CODE, EVENT_TYPE,
 *                   MESSAGE, OPERATOR, DURATION_SEC)
 */
class MachineLogRepository
{
    private function conn(): Connection
    {
        // 歷史 Log 留在 Oracle
        return Db::oracle();
    }

    /**
     * 查詢 SQL。同樣只回傳 SQL 不執行，讓上層決定分頁或匯出。
     *
     * @param array $filters start_date / end_date / machine_ids / event_type / keyword
     * @return array{0:string, 1:array}
     */
    public function query(array $filters): array
    {
        $sql = "SELECT l.log_id,
                       l.machine_id,
                       TO_CHAR(l.log_time, 'YYYY-MM-DD HH24:MI:SS') AS log_time,
                       l.event_code,
                       l.event_type,
                       l.message,
                       l.operator,
                       l.duration_sec
                  FROM mes_machine_log l
                 WHERE l.log_time >= TO_DATE(:start_date, 'YYYY-MM-DD')
                   AND l.log_time <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1";

        $bind = [
            'start_date' => $filters['start_date'],
            'end_date'   => $filters['end_date'],
        ];

        // 多選機台。陣列不能直接塞進具名參數，交給 Sql::in() 一個值一個參數。
        if (!empty($filters['machine_ids'])) {
            [$clause, $inBind] = Sql::in('l.machine_id', $filters['machine_ids']);

            $sql  .= ' AND ' . $clause;
            $bind  = array_merge($bind, $inBind);
        }

        if (!empty($filters['event_type'])) {
            $sql .= " AND l.event_type = :event_type";
            $bind['event_type'] = $filters['event_type'];
        }

        if (!empty($filters['keyword'])) {
            $sql .= " AND (UPPER(l.message) LIKE :kw OR UPPER(l.event_code) LIKE :kw)";
            $bind['kw'] = '%' . strtoupper($filters['keyword']) . '%';
        }

        return [$sql, $bind];
    }

    /**
     * 單一機台在指定時間點前後的 Log（放大鏡下鑽用）。
     */
    public function around(string $machineId, string $logTime, int $minutes = 30): array
    {
        return $this->conn()->select(
            "SELECT TO_CHAR(log_time, 'YYYY-MM-DD HH24:MI:SS') AS log_time,
                    event_code, event_type, message, operator
               FROM mes_machine_log
              WHERE machine_id = :machine_id
                AND log_time BETWEEN TO_DATE(:log_time, 'YYYY-MM-DD HH24:MI:SS') - :mins / 1440
                                 AND TO_DATE(:log_time, 'YYYY-MM-DD HH24:MI:SS') + :mins / 1440
              ORDER BY log_time",
            [
                'machine_id' => $machineId,
                'log_time'   => $logTime,
                'mins'       => $minutes,
            ]
        );
    }

    /**
     * 事件類型統計（同一次查詢條件下的分佈，顯示在表格上方）。
     */
    public function typeSummary(array $filters): array
    {
        [$sql, $bind] = $this->query($filters);

        return $this->conn()->select(
            "SELECT event_type, COUNT(*) AS cnt FROM ({$sql}) GROUP BY event_type ORDER BY cnt DESC",
            $bind
        );
    }

    /**
     * 新增一筆 Log。給對外 API（其他系統寫入）使用。
     */
    public function insert(array $data): int
    {
        return $this->conn()->execute(
            "INSERT INTO mes_machine_log
                 (log_id, machine_id, log_time, event_code, event_type, message, operator, duration_sec, source, created_at)
             VALUES
                 (mes_machine_log_seq.NEXTVAL,
                  :machine_id,
                  TO_DATE(:log_time, 'YYYY-MM-DD HH24:MI:SS'),
                  :event_code,
                  :event_type,
                  :message,
                  :operator,
                  :duration_sec,
                  :source,
                  SYSDATE)",
            $data
        );
    }
}
