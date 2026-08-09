<?php

namespace App\Domain\Machine;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 機台資料存取。
 *
 * ⚠ 這裡的 SQL 是依「假設的資料表結構」寫的範例，
 *   實際欄位名稱請對照公司資料庫調整。重點是結構：
 *   所有 SQL 都集中在 Repository，頁面與 API 不會出現任何一句 SQL。
 *   之後要改資料表、換資料庫、加索引，只需要看這一層。
 *
 * 假設的資料表：
 *   mes_machine(machine_id, machine_name, model, area, pos_x, pos_y,
 *               width, height, status, last_report_time)
 */
class MachineRepository
{
    private function conn(): Connection
    {
        // 機台主檔在 PostgreSQL；歷史 Log 在 Oracle（見 MachineLogRepository）
        return Db::pg();
    }

    /**
     * 機台狀態總表的查詢 SQL。
     *
     * 只回傳 SQL 與繫結參數、不直接執行，
     * 是為了讓上層可以決定要分頁查詢還是整包匯出 CSV，同一份 SQL 兩用。
     *
     * @return array{0:string, 1:array}
     */
    public function statusQuery(array $filters): array
    {
        $sql = "SELECT m.machine_id,
                       m.machine_name,
                       m.model,
                       m.area,
                       m.status,
                       m.last_report_time,
                       s.run_minutes,
                       s.idle_minutes,
                       s.down_minutes,
                       s.qty_ok,
                       s.qty_ng,
                       CASE WHEN COALESCE(s.run_minutes, 0) + COALESCE(s.idle_minutes, 0) + COALESCE(s.down_minutes, 0) = 0
                            THEN 0
                            ELSE ROUND(s.run_minutes * 100.0
                                 / (s.run_minutes + s.idle_minutes + s.down_minutes), 1)
                       END AS oee
                  FROM mes_machine m
                  LEFT JOIN mes_machine_daily s
                         ON s.machine_id = m.machine_id
                        AND s.stat_date  = CURRENT_DATE
                 WHERE 1 = 1";

        $bind = [];

        // 條件用「有給才加」的寫法，不要用一長串 CASE WHEN 硬塞，
        // 那樣資料庫用不到索引。
        if (!empty($filters['area'])) {
            $sql .= " AND m.area = :area";
            $bind['area'] = $filters['area'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND m.status = :status";
            $bind['status'] = $filters['status'];
        }

        if (!empty($filters['keyword'])) {
            $sql .= " AND (UPPER(m.machine_id) LIKE :kw OR UPPER(m.machine_name) LIKE :kw OR UPPER(m.model) LIKE :kw)";
            $bind['kw'] = '%' . strtoupper($filters['keyword']) . '%';
        }

        return [$sql, $bind];
    }

    /**
     * 平面圖用的機台位置與狀態。
     * 資料量固定不大（一個廠區數百台），一次全撈沒問題。
     *
     * @param string|null $floor 只要某一層樓的機台（分頁版平面圖用）
     */
    public function forMap(?string $area = null, ?string $floor = null): array
    {
        $sql = "SELECT machine_id,
                       machine_name,
                       model,
                       area,
                       floor,
                       pos_x   AS x,
                       pos_y   AS y,
                       width   AS w,
                       height  AS h,
                       status,
                       last_report_time
                  FROM mes_machine
                 WHERE pos_x IS NOT NULL
                   AND pos_y IS NOT NULL";

        $bind = [];

        if ($area) {
            $sql .= " AND area = :area";
            $bind['area'] = $area;
        }

        if ($floor) {
            $sql .= " AND floor = :floor";
            $bind['floor'] = $floor;
        }

        return $this->conn()->select($sql . " ORDER BY pos_y, pos_x", $bind);
    }

    /**
     * 下拉／分頁籤用的樓層清單。
     */
    public function floors(): array
    {
        return array_column(
            $this->conn()->select("SELECT DISTINCT floor FROM mes_machine WHERE floor IS NOT NULL ORDER BY floor"),
            'floor'
        );
    }

    /**
     * 單一機台的基本資料（放大鏡彈窗第一段用）。
     */
    public function find(string $machineId): ?array
    {
        return $this->conn()->selectOne(
            "SELECT machine_id, machine_name, model, area, status,
                    maker, install_date, last_report_time, remark
               FROM mes_machine
              WHERE machine_id = :machine_id",
            ['machine_id' => $machineId]
        );
    }

    /**
     * 單一機台今日的分時稼動（放大鏡彈窗第二段用）。
     */
    public function todayHourly(string $machineId): array
    {
        return $this->conn()->select(
            "SELECT TO_CHAR(stat_hour, 'HH24:00') AS hour_label,
                    run_minutes,
                    idle_minutes,
                    down_minutes,
                    qty_ok,
                    qty_ng
               FROM mes_machine_hourly
              WHERE machine_id = :machine_id
                AND stat_hour >= CURRENT_DATE
              ORDER BY stat_hour",
            ['machine_id' => $machineId]
        );
    }

    /**
     * 下拉選單用的廠區清單。
     */
    public function areas(): array
    {
        return array_column(
            $this->conn()->select("SELECT DISTINCT area FROM mes_machine WHERE area IS NOT NULL ORDER BY area"),
            'area'
        );
    }
}
