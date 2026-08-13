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
 *
 * ⚠ 大小寫的規則（整個專案一致）：
 *
 *   SQL 裡的資料表與欄位一律**大寫**，跟 DDL 完全一樣。
 *   Oracle 對沒加引號的識別字本來就不分大小寫，寫小寫也能跑，
 *   但照著 DDL 寫的好處是「拿欄位名去 grep 就找得到所有用到的地方」。
 *
 *   回傳的陣列鍵則一律**小寫**（$row['ppcup_lot']）。
 *   那是 BaseConnection::normalizeRows() 統一轉的 ——
 *   Oracle 回大寫、PostgreSQL 回小寫，不統一的話同一份樣板換個資料庫就全壞。
 *   所以欄位定義（HydrationService::columns() 的 key）也是小寫。
 *
 *   具名參數（:start_date）是 PHP 這邊自己取的名字，跟欄位無關，維持小寫。
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
        $sql = "SELECT TO_CHAR(S.AQUA_SCHEDULE_DATE, 'YYYY-MM-DD') AS AQUA_SCHEDULE_DATE,
                       S.QTY,
                       S.PPCUP_LOT,
                       S.AQUA_SCHEDULE_DATE_CODE,
                       S.AQUA_CYCLE_NUM,
                       S.PACKET_LOT_TEMP_AUTO,
                       S.NOTE,
                       S.UPDATE_USER,
                       TO_CHAR(S.UPDATE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS UPDATE_TIME
                  FROM AQUA_SCHEDULE S
                 WHERE S.AQUA_SCHEDULE_DATE >= TO_DATE(:start_date, 'YYYY-MM-DD')
                   AND S.AQUA_SCHEDULE_DATE <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1";

        $bind = [
            'start_date' => $filters['start_date'],
            'end_date'   => $filters['end_date'],
        ];

        /**
         * 乾片批號一次可以查很多筆（現場從水化排程表複製一整欄貼進來）。
         * 陣列不能直接塞進具名參數，交給 Sql::in() 一個值一個參數。
         */
        if (!empty($filters['ppcup_lots'])) {
            [$clause, $inBind] = Sql::in('S.PPCUP_LOT', $filters['ppcup_lots']);

            $sql  .= ' AND ' . $clause;
            $bind  = array_merge($bind, $inBind);
        }

        if (!empty($filters['date_code'])) {
            $sql .= ' AND S.AQUA_SCHEDULE_DATE_CODE = :date_code';
            $bind['date_code'] = strtoupper($filters['date_code']);
        }

        if (!empty($filters['packet_lot'])) {
            $sql .= ' AND UPPER(S.PACKET_LOT_TEMP_AUTO) LIKE :packet_kw';
            $bind['packet_kw'] = '%' . strtoupper($filters['packet_lot']) . '%';
        }

        if (!empty($filters['cycle_num'])) {
            $sql .= ' AND S.AQUA_CYCLE_NUM = :cycle_num';
            $bind['cycle_num'] = (int) $filters['cycle_num'];
        }

        // 只看還沒取號的（現場最常按的一個條件：今天還有哪些沒收尾）
        if (!empty($filters['only_no_packet'])) {
            $sql .= ' AND S.PACKET_LOT_TEMP_AUTO IS NULL';
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
            "SELECT COUNT(*)                      AS ROW_CNT,
                    SUM(S.QTY)                    AS QTY_SUM,
                    COUNT(DISTINCT S.PPCUP_LOT)   AS LOT_CNT,
                    COUNT(S.PACKET_LOT_TEMP_AUTO) AS PACKET_CNT
               FROM AQUA_SCHEDULE S
              WHERE S.AQUA_SCHEDULE_DATE >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND S.AQUA_SCHEDULE_DATE <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1",
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
            "SELECT S.AQUA_CYCLE_NUM,
                    COUNT(*)   AS ROW_CNT,
                    SUM(S.QTY) AS QTY_SUM
               FROM AQUA_SCHEDULE S
              WHERE S.AQUA_SCHEDULE_DATE >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND S.AQUA_SCHEDULE_DATE <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1
              GROUP BY S.AQUA_CYCLE_NUM
              ORDER BY S.AQUA_CYCLE_NUM",
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
            "SELECT TO_CHAR(S.AQUA_SCHEDULE_DATE, 'YYYY-MM-DD') AS AQUA_SCHEDULE_DATE,
                    S.AQUA_CYCLE_NUM,
                    S.QTY,
                    S.AQUA_SCHEDULE_DATE_CODE,
                    S.PACKET_LOT_TEMP_AUTO,
                    S.NOTE,
                    S.UPDATE_USER,
                    TO_CHAR(S.UPDATE_TIME, 'YYYY-MM-DD HH24:MI:SS') AS UPDATE_TIME
               FROM AQUA_SCHEDULE S
              WHERE S.PPCUP_LOT = :ppcup_lot
              ORDER BY S.AQUA_CYCLE_NUM",
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

        [$clause, $bind] = Sql::in('PPCUP_LOT', $ppcupLots);

        $rows = $this->conn()->select(
            "SELECT PPCUP_LOT, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO
               FROM AQUA_SCHEDULE
              WHERE " . $clause . "
              ORDER BY PPCUP_LOT, AQUA_CYCLE_NUM",
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
