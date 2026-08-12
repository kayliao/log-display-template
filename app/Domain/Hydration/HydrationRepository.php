<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;
use App\Support\Sql;

/**
 * 水化紀錄 —— 資料存取。
 *
 * 資料表設計、索引與「為什麼是這樣建」全部寫在 docs/sql/hydration_oracle.sql，
 * 這裡的 SQL 是照那一份寫的。改欄位請兩邊一起改。
 *
 *   MES_HYD_WAFER(HYD_ID, HYD_DATE, QTY, DRY_LOT_NO, HYD_DAY_CODE,
 *                 PACK_LOT_NO, HYD_SEQ, PRE_PACK_LOT_NO, SOURCE,
 *                 CREATED_AT, UPDATED_AT)
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
        $sql = "SELECT w.hyd_id,
                       TO_CHAR(w.hyd_date, 'YYYY-MM-DD') AS hyd_date,
                       w.qty,
                       w.dry_lot_no,
                       w.hyd_day_code,
                       w.pack_lot_no,
                       w.hyd_seq,
                       w.pre_pack_lot_no,
                       TO_CHAR(w.updated_at, 'YYYY-MM-DD HH24:MI:SS') AS updated_at
                  FROM mes_hyd_wafer w
                 WHERE w.hyd_date >= TO_DATE(:start_date, 'YYYY-MM-DD')
                   AND w.hyd_date <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1";

        $bind = [
            'start_date' => $filters['start_date'],
            'end_date'   => $filters['end_date'],
        ];

        /**
         * 乾片批號一次可以查很多筆（現場從水化排程表複製一整欄貼進來）。
         * 陣列不能直接塞進具名參數，交給 Sql::in() 一個值一個參數。
         */
        if (!empty($filters['dry_lot_nos'])) {
            [$clause, $inBind] = Sql::in('w.dry_lot_no', $filters['dry_lot_nos']);

            $sql  .= ' AND ' . $clause;
            $bind  = array_merge($bind, $inBind);
        }

        if (!empty($filters['hyd_day_code'])) {
            $sql .= ' AND w.hyd_day_code = :hyd_day_code';
            $bind['hyd_day_code'] = strtoupper($filters['hyd_day_code']);
        }

        if (!empty($filters['pack_lot_no'])) {
            // 正式與預配兩個欄位一起找：現場拿到一個號碼時不會知道它是哪一種
            $sql .= ' AND (UPPER(w.pack_lot_no) LIKE :pack_kw OR UPPER(w.pre_pack_lot_no) LIKE :pack_kw)';
            $bind['pack_kw'] = '%' . strtoupper($filters['pack_lot_no']) . '%';
        }

        if (!empty($filters['hyd_seq'])) {
            $sql .= ' AND w.hyd_seq = :hyd_seq';
            $bind['hyd_seq'] = (int) $filters['hyd_seq'];
        }

        // 只看還沒封包的（現場最常按的一個條件：今天還有哪些沒收尾）
        if (!empty($filters['only_open'])) {
            $sql .= ' AND w.pack_lot_no IS NULL';
        }

        // 只看還沒取號的
        if (!empty($filters['only_no_pre'])) {
            $sql .= ' AND w.pre_pack_lot_no IS NULL';
        }

        return [$sql, $bind];
    }

    /**
     * 今日統整用的四個數字。
     *
     * 日期條件寫成「>= 今天 AND < 明天」而不是 TRUNC(hyd_date) = 今天，
     * 後者會讓 IX_HYD_WAFER_DATE 整個用不到。
     */
    public function todayTotals(string $date): array
    {
        /**
         * COUNT(欄位) 只算「不是 NULL」的那些列，COUNT(*) 才是全部。
         * 所以「已封包幾筆」直接用 COUNT(pack_lot_no) 就好，
         * 不需要寫成 SUM(CASE WHEN … THEN 1 ELSE 0 END)——
         * 後者每加一個條件就長一截，也比較容易寫錯。
         * 「未封包」在 Service 用 總筆數 − 已封包 算出來。
         */
        $row = $this->conn()->selectOne(
            "SELECT COUNT(*)                     AS row_cnt,
                    SUM(w.qty)                   AS qty_sum,
                    COUNT(DISTINCT w.dry_lot_no) AS lot_cnt,
                    COUNT(w.pack_lot_no)         AS packed_cnt,
                    COUNT(w.pre_pack_lot_no)     AS pre_cnt
               FROM mes_hyd_wafer w
              WHERE w.hyd_date >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND w.hyd_date <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1",
            ['stat_date' => $date]
        );

        return $row ?: [];
    }

    /**
     * 今日各次水化的分佈（第 1 次幾筆、第 2 次幾筆…）。
     */
    public function todayBySeq(string $date): array
    {
        return $this->conn()->select(
            "SELECT w.hyd_seq,
                    COUNT(*)   AS row_cnt,
                    SUM(w.qty) AS qty_sum
               FROM mes_hyd_wafer w
              WHERE w.hyd_date >= TO_DATE(:stat_date, 'YYYY-MM-DD')
                AND w.hyd_date <  TO_DATE(:stat_date, 'YYYY-MM-DD') + 1
              GROUP BY w.hyd_seq
              ORDER BY w.hyd_seq",
            ['stat_date' => $date]
        );
    }

    /**
     * 一個乾片批號的完整水化歷程（放大鏡彈窗用）。
     * 走 UX_HYD_WAFER_LOT_SEQ 這個唯一索引，不用另外建索引。
     */
    public function lotHistory(string $dryLotNo): array
    {
        return $this->conn()->select(
            "SELECT TO_CHAR(w.hyd_date, 'YYYY-MM-DD') AS hyd_date,
                    w.hyd_seq,
                    w.qty,
                    w.hyd_day_code,
                    w.pack_lot_no,
                    w.pre_pack_lot_no,
                    w.source,
                    TO_CHAR(w.updated_at, 'YYYY-MM-DD HH24:MI:SS') AS updated_at
               FROM mes_hyd_wafer w
              WHERE w.dry_lot_no = :dry_lot_no
              ORDER BY w.hyd_seq",
            ['dry_lot_no' => $dryLotNo]
        );
    }

    /**
     * 匯入驗證用：這幾個乾片批號目前各有哪幾次水化、封包了沒。
     *
     * 一次把整個檔案用到的批號查回來，不要一列查一次 ——
     * 五百列的檔案就是五百次來回，現場會覺得「按了沒反應」。
     *
     * @param string[] $dryLotNos
     * @return array<string, array<int, array{hyd_seq:int, pack_lot_no:?string, pre_pack_lot_no:?string}>>
     *         乾片批號 => [第幾次 => 狀態]
     */
    public function lotStates(array $dryLotNos): array
    {
        if ($dryLotNos === []) {
            return [];
        }

        [$clause, $bind] = Sql::in('dry_lot_no', $dryLotNos);

        $rows = $this->conn()->select(
            "SELECT dry_lot_no, hyd_seq, pack_lot_no, pre_pack_lot_no
               FROM mes_hyd_wafer
              WHERE " . $clause . "
              ORDER BY dry_lot_no, hyd_seq",
            $bind
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['dry_lot_no']][(int) $row['hyd_seq']] = [
                'hyd_seq'         => (int) $row['hyd_seq'],
                'pack_lot_no'     => $row['pack_lot_no'] ?? null,
                'pre_pack_lot_no' => $row['pre_pack_lot_no'] ?? null,
            ];
        }

        return $out;
    }
}
