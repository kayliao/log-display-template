<?php

namespace App\Core\Db;

/**
 * 資料庫錯誤的分類。
 *
 * 連線層（BaseConnection::fail）把驅動的錯誤包成 RuntimeException，訊息裡帶著
 * 原始的錯誤字串。到了 Domain 這一層，有些錯誤其實是**預期中的**，
 * 要翻譯成使用者看得懂的話，而不是一律當成「系統壞掉」：
 *
 *   撞到唯一鍵   多半是兩個人同時做同一件事。是流水號撞號就重試，
 *                是業務鍵撞號就告訴使用者「這筆已經有人建了」
 *   等鎖逾時     不是壞掉，是同時進來的人太多，請他稍後重試
 *
 * 判斷靠字串比對是不得已的：這個專案同時支援 oci8、PDO_OCI 與 PDO_pgsql，
 * 三種驅動丟出來的例外型別與錯誤碼欄位都不一樣，只有訊息文字是共通的。
 * 所以比對規則集中在這裡一份，不要散到各個 Repository 去各寫各的。
 */
class DbError
{
    /**
     * 這個例外是不是「唯一鍵衝突」。
     *
     * Oracle 是 ORA-00001，PostgreSQL 是 SQLSTATE 23505。
     */
    public static function isDuplicate(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return strpos($message, 'ORA-00001') !== false
            || strpos($message, '23505') !== false;
    }

    /**
     * 撞到的是不是**指定的那一個**約束。
     *
     * 同一張表上通常有好幾個唯一鍵，而它們的意義完全不同 ——
     * 料號對應表就有兩個：主鍵撞號是「流水號剛好被別人用走了」（重試就好），
     * 料號的唯一索引撞號是「這個料號已經有人建了」（要告訴使用者，重試沒用）。
     * 只判斷 isDuplicate() 的話這兩件事會混在一起。
     *
     * Oracle 的訊息長這樣：
     *   ORA-00001: unique constraint (<schema>.<索引名>) violated
     * PostgreSQL：
     *   SQLSTATE[23505] … violates unique constraint "<索引名>"
     *
     * 兩邊都會把約束名稱寫在訊息裡，只是大小寫與結構不同，所以比對不分大小寫。
     *
     * @param string $constraint 約束或索引名稱，不含 schema
     */
    public static function violates(\Throwable $e, string $constraint): bool
    {
        if (!self::isDuplicate($e)) {
            return false;
        }

        return stripos($e->getMessage(), $constraint) !== false;
    }

    /**
     * 這個例外是不是「等鎖等到逾時」。
     *
     * ORA-00054  NOWAIT 拿不到鎖
     * ORA-30006  WAIT n 等到時間到
     *
     * 這不是壞掉，是同時進來的人太多。回 500 會讓對方以為資料寫壞了。
     */
    public static function isLockTimeout(\Throwable $e): bool
    {
        return (bool) preg_match('/ORA-0*(54|30006)/', $e->getMessage());
    }
}
