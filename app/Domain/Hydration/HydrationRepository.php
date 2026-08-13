<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;
use App\Support\Sql;

/**
 * 水化排程 —— 資料存取。
 *
 * 資料表設計、索引與「為什麼是這樣建」全部寫在 docs/sql/hydration_oracle.sql，
 * 這裡的 SQL 是照那一份寫的。改欄位請兩邊一起改。
 *
 *   AQUA_SCHEDULE(AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY,
 *                 AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO)
 *
 *   主鍵 (PPCUP_LOT, AQUA_CYCLE_NUM)：一個乾片批號的一次水化只有一列
 *
 * ⚠ 資料表名稱如果跟你們實際的不一樣，只要改這一支與
 *   HydrationImportRepository / PackLotRepository 三個檔案裡的表名。
 *
 * 這一支示範的是 Oracle 端的寫法：日期比較用 TO_DATE 而不是字串比大小、
 * 不寫 LIMIT（分頁交給 Paginator）、日期條件不套函式以免用不到索引。
 */
class HydrationRepository
{
    private function conn(): Connection
    {
        return Db::oracle();
    }

    /**
     * 明細查詢。只回傳 SQL 與參數，分頁查詢與 CSV 匯出共用同一份。
     *
     * @return array{0:string, 1:array}
     */
    public function query(array $filters): array
    {
        $sql = "SELECT TO_CHAR(s.aqua_schedule_date, 'YYYY-MM-DD') AS aqua_schedule_date,
                       s.qty,
                       s.ppcup_lot,
                       s.aqua_schedule_date_code,
                       s.aqua_cycle_num,
                       s.packet_lot_temp_auto
                  FROM aqua_schedule s
                 WHERE s.aqua_schedule_date >= TO_DATE(:start_date, 'YYYY-MM-DD')
                   AND s.aqua_schedule_date <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1";

        $bind = [
            'start_date' => $filters['start_date'],
            'end_date'   => $filters['end_date'],
        ];

        /**
         * 乾片批號一次可以查很多筆（現場從水化排程表複製一整欄貼進來）。
         * 陣列不能直接塞進具名參數，交給 Sql::in() 一個值一個參數。
         */
        if (!empty($filters['ppcup_lots'])) {
            [$clause, $inBind] = Sql::in('s.ppcup_lot', $filters['ppcup_lots']);

            $sql  .= ' AND ' . $clause;
            $bind  = array_merge($bind, $inBind);
        }

        if (!empty($filters['date_code'])) {
            $sql .= ' AND s.aqua_schedule_date_code = :date_code';
            $bind['date_code'] = strtoupper($filters['date_code']);
        }

        if (!empty($filters['packet_lot'])) {
            $sql .= ' AND UPPER(s.packet_lot_temp_auto) LIKE :packet_kw';
            $bind['packet_kw'] = '%' . strtoupper($filters['packet_lot']) . '%';
        }

        if (!empty($filters['cycle_num'])) {
            $sql .= ' AND s.aqua_cycle_num = :cycle_num';
            $bind['cycle_num'] = (int) $filters['cycle_num'];
        }

        // 只看還沒取號的（現場最常按的一個條件：今天還有哪些沒收尾）
        if (!empty($filters['only_no_packet'])) {
            $sql .= ' AND s.packet_lot_temp_auto IS NULL';
        }

        return [$sql, $bind];
    }

    /**
     * 今日統整用的四個數字。
     *
     * 日期條件寫成「>= 今天 AND < 明天」而不是 TRUNC(aqua_schedule_date) = 今天，
     * 後者會讓 IX_AQUA_SCHEDULE_DATE 整個用不到。
     */
    public function todayTotals(string $date): array
    {
        /**
         * COUNT(欄位) 只算「不是 NULL」的那些列，COUNT(*) 才是全部。
         * 所以「已取號幾筆」直接用 COUNT(packet_lot_temp_auto) 就好，
         * 不需要寫成 SUM(CASE WHEN … THEN 1 ELSE 0 END)。
         * 「未取號」在 Service 用 總筆數 − 已取號 算出來。
         */
        $row = $this->conn()->selectOne(
            "SELECT COUNT(*)                      AS row_cnt,
                    SUM(s.qty)                    AS qty_sum,
                    COUNT(DISTINCT s.ppcup_lot)   AS lot_cnt,
                    COUNT(s.packet_lot_temp_auto) AS packet_cnt
               FROM aqua_schedule s
              WHERE s.aqua_schedule_date >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND s.aqua_schedule_date <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1",
            ['stat_date' => $date]
        );

        return $row ?: [];
    }

    /**
     * 今日各次水化的分佈（第 1 次幾筆、第 2 次幾筆…）。
     */
    public function todayByCycle(string $date): array
    {
        return $this->conn()->select(
            "SELECT s.aqua_cycle_num,
                    COUNT(*)   AS row_cnt,
                    SUM(s.qty) AS qty_sum
               FROM aqua_schedule s
              WHERE s.aqua_schedule_date >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND s.aqua_schedule_date <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1
              GROUP BY s.aqua_cycle_num
              ORDER BY s.aqua_cycle_num",
            ['stat_date' => $date]
        );
    }

    /**
     * 一個乾片批號的完整水化歷程（放大鏡彈窗用）。
     * 走主鍵 (PPCUP_LOT, AQUA_CYCLE_NUM)，不用另外建索引。
     */
    public function lotHistory(string $ppcupLot): array
    {
        return $this->conn()->select(
            "SELECT TO_CHAR(s.aqua_schedule_date, 'YYYY-MM-DD') AS aqua_schedule_date,
                    s.aqua_cycle_num,
                    s.qty,
                    s.aqua_schedule_date_code,
                    s.packet_lot_temp_auto
               FROM aqua_schedule s
              WHERE s.ppcup_lot = :ppcup_lot
              ORDER BY s.aqua_cycle_num",
            ['ppcup_lot' => $ppcupLot]
        );
    }

    /**
     * 匯入驗證用：這幾個乾片批號目前各有哪幾次水化、取號了沒。
     *
     * 一次把整個檔案用到的批號查回來，不要一列查一次 ——
     * 五百列的檔案就是五百次來回，現場會覺得「按了沒反應」。
     *
     * @param string[] $ppcupLots
     * @return array<string, array<int, array{aqua_cycle_num:int, packet_lot_temp_auto:?string}>>
     *         乾片批號 => [第幾次 => 狀態]
     */
    public function lotStates(array $ppcupLots): array
    {
        if ($ppcupLots === []) {
            return [];
        }

        [$clause, $bind] = Sql::in('ppcup_lot', $ppcupLots);

        $rows = $this->conn()->select(
            "SELECT ppcup_lot, aqua_cycle_num, packet_lot_temp_auto
               FROM aqua_schedule
              WHERE " . $clause . "
              ORDER BY ppcup_lot, aqua_cycle_num",
            $bind
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['ppcup_lot']][(int) $row['aqua_cycle_num']] = [
                'aqua_cycle_num'       => (int) $row['aqua_cycle_num'],
                'packet_lot_temp_auto' => $row['packet_lot_temp_auto'] ?? null,
            ];
        }

        return $out;
    }
}
