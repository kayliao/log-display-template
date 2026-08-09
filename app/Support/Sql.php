<?php

namespace App\Support;

/**
 * SQL 片段組裝。
 *
 * 只放「每個 Repository 都會重寫一次、寫錯就會開後門」的那幾種片段。
 * 完整的查詢語句仍然寫在各自的 Repository，這裡不做查詢建構器。
 */
class Sql
{
    /**
     * 產生 IN (...) 條件與繫結參數。
     *
     * 陣列不能直接塞進具名參數（PDO 與 oci8 都不支援），
     * 手動拼字串又很容易漏掉逸出而開出注入漏洞，
     * 所以統一由這裡產生「一個值一個具名參數」的形式。
     *
     *   [$sql, $bind] = Sql::in('m.machine_id', ['M-101', 'M-102']);
     *   // $sql  = "m.machine_id IN (:machine_id_0, :machine_id_1)"
     *   // $bind = ['machine_id_0' => 'M-101', 'machine_id_1' => 'M-102']
     *
     * 用法：
     *
     *   if ($ids !== []) {
     *       [$clause, $inBind] = Sql::in('m.machine_id', $ids);
     *       $sql .= ' AND ' . $clause;
     *       $bind = array_merge($bind, $inBind);
     *   }
     *
     * 空陣列會回傳一個恆假條件（1 = 0）。
     * 這是刻意的：呼叫端如果不小心把空清單傳進來，
     * 應該查不到東西，而不是變成「沒有條件」把整張表撈回來。
     *
     * @param string $column  欄位名。這是程式寫死的，不會是使用者輸入
     * @param array  $values  值清單
     * @param string $prefix  參數名前綴，同一句 SQL 有兩組 IN 時要給不同的
     * @return array{0:string, 1:array}
     */
    public static function in(string $column, array $values, ?string $prefix = null): array
    {
        $values = array_values($values);

        if ($values === []) {
            return ['1 = 0', []];
        }

        // 沒給前綴就從欄位名產生，m.machine_id => machine_id
        if ($prefix === null) {
            $dot    = strrchr($column, '.');
            $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $dot ? substr($dot, 1) : $column);
        }

        $names = [];
        $bind  = [];

        foreach ($values as $i => $value) {
            $name         = $prefix . '_' . $i;
            $names[]      = ':' . $name;
            $bind[$name]  = $value;
        }

        return [$column . ' IN (' . implode(', ', $names) . ')', $bind];
    }
}
