<?php

namespace App\Domain\Machine;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 機台清單匯入 —— 資料存取。
 *
 * ⚠ 這裡的 SQL 是依「假設的資料表結構」寫的範例，
 *   實際欄位名稱請對照公司資料庫調整。
 *
 * 這一支同時示範 Oracle 與 PostgreSQL 的「有就更新、沒有就新增」寫法。
 * 兩家的語法差很多，寫錯的話最常見的後果是「重跑一次匯入就多出一整份重複資料」，
 * 所以兩種都列出來，換資料庫時照著改就好。
 */
class MachineImportRepository
{
    private function conn(): Connection
    {
        return Db::pg();
    }

    /**
     * 寫入一筆（有就更新、沒有就新增）。
     *
     * 依連線的資料庫種類選語法。
     * Repository 是唯一知道「現在跑在哪個資料庫」的地方，
     * Service 與頁面都不需要判斷。
     */
    public function upsert(array $machine): int
    {
        $conn = $this->conn();

        return $conn->driver() === 'oracle'
            ? $conn->execute($this->oracleMergeSql(), $machine)
            : $conn->execute($this->postgresUpsertSql(), $machine);
    }

    /**
     * Oracle：MERGE INTO … WHEN MATCHED THEN UPDATE / WHEN NOT MATCHED THEN INSERT
     *
     * 注意事項：
     *   - USING 那一段要有一個來源列，Oracle 慣用 `FROM dual`
     *   - 同一個具名參數在 Oracle 只能出現一次，重複出現要取不同的名字，
     *     所以 machine_id 在 USING 與 INSERT 各用一個（:machine_id 與 :machine_id_ins）
     *   - MERGE 是單句 SQL，不需要先 SELECT 再判斷，少一次來回
     *   - ON 的欄位一定要有唯一索引，否則兩筆同時進來會各自 INSERT
     */
    private function oracleMergeSql(): string
    {
        return "MERGE INTO mes_machine t
                USING (SELECT :machine_id AS machine_id FROM dual) s
                   ON (t.machine_id = s.machine_id)
                WHEN MATCHED THEN
                    UPDATE SET t.machine_name = :machine_name,
                               t.model        = :model,
                               t.area         = :area,
                               t.updated_at   = SYSDATE
                WHEN NOT MATCHED THEN
                    INSERT (machine_id, machine_name, model, area, created_at, updated_at)
                    VALUES (:machine_id_ins, :machine_name_ins, :model_ins, :area_ins, SYSDATE, SYSDATE)";
    }

    /**
     * PostgreSQL：INSERT … ON CONFLICT DO UPDATE
     *
     * 注意事項：
     *   - ON CONFLICT 的欄位要有唯一索引或 primary key
     *   - EXCLUDED 是「原本要插入的那一列」，所以更新值不用再寫一次參數
     *   - 同一個具名參數可以重複出現，不像 Oracle 要另外取名
     */
    private function postgresUpsertSql(): string
    {
        return "INSERT INTO mes_machine (machine_id, machine_name, model, area, created_at, updated_at)
                VALUES (:machine_id, :machine_name, :model, :area, NOW(), NOW())
                ON CONFLICT (machine_id) DO UPDATE
                   SET machine_name = EXCLUDED.machine_name,
                       model        = EXCLUDED.model,
                       area         = EXCLUDED.area,
                       updated_at   = NOW()";
    }

    /**
     * 一次寫入多筆，包在同一個交易裡。
     *
     * 整批成功或整批失敗，不會出現「匯入到第 37 筆時斷掉，
     * 前面 36 筆進去了、後面沒有」這種要人工善後的狀況。
     *
     * @param array[] $machines
     * @return int 實際寫入筆數
     */
    public function upsertMany(array $machines): int
    {
        $conn = $this->conn();

        return $conn->transaction(function () use ($machines) {
            $count = 0;

            foreach ($machines as $machine) {
                $bind = [
                    'machine_id'   => $machine['machine_id'],
                    'machine_name' => $machine['machine_name'],
                    'model'        => $machine['model'],
                    'area'         => $machine['area'],
                ];

                // Oracle 的 MERGE 需要另一組參數名（同名參數不能出現兩次）
                if ($this->conn()->driver() === 'oracle') {
                    $bind['machine_id_ins']   = $machine['machine_id'];
                    $bind['machine_name_ins'] = $machine['machine_name'];
                    $bind['model_ins']        = $machine['model'];
                    $bind['area_ins']         = $machine['area'];
                }

                $count += $this->upsert($bind) > 0 ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * 檔案裡的哪些機台編號已經存在（預覽時要標示「會更新」還是「新增」）。
     *
     * @param string[] $ids
     * @return string[] 已存在的編號
     */
    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$clause, $bind] = \App\Support\Sql::in('machine_id', $ids);

        return array_column(
            $this->conn()->select("SELECT machine_id FROM mes_machine WHERE " . $clause, $bind),
            'machine_id'
        );
    }
}
