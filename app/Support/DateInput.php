<?php

namespace App\Support;

/**
 * 匯入檔案裡的「日期欄」解析。
 *
 * 為什麼需要這一支：
 *
 *   現場的檔案幾乎都是 Excel 另存的 CSV，而 Excel 存出去的日期
 *   **是那個儲存格當下顯示的樣子**，不是一個固定格式。顯示的樣子跟著
 *   那台電腦的 Windows 地區設定跑 —— 同一份 xlsx 在 A 電腦另存出來是
 *   2026/8/13，在 B 電腦可能是 8/13/2026，儲存格改成「通用格式」的話
 *   直接變成 46247。三種都是同一天。
 *
 *   所以匯入端只認一種寫法的話，現場就得先手動改檔；這一支的作用是
 *   把「看得懂而且不會猜錯」的那些寫法，統一轉成 YYYY-MM-DD。
 *
 * ── 會接受的寫法 ──────────────────────────────────────────────
 *
 *   2026-08-13   2026-8-13     一般寫法（月日不補零也可以）
 *   2026/08/13   2026.08.13    分隔符號用 / 或 . 都行
 *   20260813                   完全不加分隔
 *   2026年08月13日              中文寫法
 *   46247                      Excel 日期序號（儲存格被設成通用格式時會存成這樣）
 *   2026/8/13 上午 12:00:00     後面接時間的（儲存格是「日期時間」格式），時間會被忽略
 *
 *   全形數字（２０２６）會先轉成半形，中文輸入法直接打的也讀得進來。
 *
 * ── 會被擋下來的寫法（重要）─────────────────────────────────────
 *
 *   08/13/2026   13/08/2026    月日順序無法判斷
 *   26-08-13                   兩位數年份，世紀無法判斷
 *
 *   這兩種**故意不猜**。猜錯不會有任何錯誤訊息，只會安靜地存錯一天：
 *   日 ≤ 12 的時候 8/13 跟 13/8 都是合法日期，程式沒有辦法知道哪個才對。
 *   水化日期又是機台取號時圈當日範圍用的欄位，存錯一天會牽動封包批號，
 *   這種錯誤比「當場擋下來叫使用者改成年份在前」貴太多。
 *
 * 不存在的日期（2026-02-30、2026-13-01）也一律擋掉 —— 這些寫法本身合乎
 * 格式，只靠正規表示式是攔不住的，會一路帶到 Oracle 的 TO_DATE 才炸，
 * 那時候現場看到的訊息已經跟「哪一欄填錯」沒有關係了。
 */
class DateInput
{
    /**
     * 欄位定義裡的說明文字。
     *
     * 直接餵給 upload 元件的「檔案格式」表格與驗證失敗的訊息，
     * 所以說明跟實際會驗的規則不會分岔。
     */
    const MESSAGE = '年份要在最前面，例如 2026-08-13（2026/08/13、20260813、2026年08月13日 也可以）';

    /**
     * Excel 日期序號的合理範圍。
     *
     * 序號 1 是 1900-01-01，所以理論上 1 也是合法的日期；但匯入檔裡的
     * 「1」幾乎都是使用者填錯欄位，把它讀成 1900 年只會讓錯誤更難查。
     * 這裡只認 1990-01-01（32874）到 2099-12-31（73050）之間 ——
     * 工廠排程不會出現這個範圍以外的日期。
     */
    const SERIAL_MIN = 32874;
    const SERIAL_MAX = 73050;

    /**
     * 看得懂的兩種形狀。解析與「為什麼不行」的判斷共用同一份，
     * 兩邊才不會分岔（訊息說可以、實際卻擋下來是最難查的那種問題）。
     */
    const PATTERN_YMD     = '/^(\d{4})[-\/.年](\d{1,2})[-\/.月](\d{1,2})日?$/u';
    const PATTERN_COMPACT = '/^(\d{4})(\d{2})(\d{2})$/';

    /**
     * 把使用者填的日期轉成 YYYY-MM-DD。
     *
     * @return string|null 看不懂、或看得懂但那天不存在時回 null
     */
    public static function normalize(string $value): ?string
    {
        $text = self::clean($value);

        if ($text === '') {
            return null;
        }

        // Excel 日期序號。先判斷，才不會被下面的「純數字」規則當成別的東西
        if (preg_match('/^\d{5}$/', $text)) {
            return self::fromSerial((int) $text);
        }

        // 20260813
        if (preg_match(self::PATTERN_COMPACT, $text, $m)) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 2026-08-13 / 2026/8/13 / 2026.08.13 / 2026年08月13日
        if (preg_match(self::PATTERN_YMD, $text, $m)) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    /**
     * 這一格為什麼不行。沒問題（含留空）回 null。
     *
     * 訊息要講到「所以我該改什麼」：2026-02-30 回「年份要在最前面」是答非所問，
     * 使用者會盯著那一格看半天 —— 年份明明就在最前面。
     */
    public static function problem(string $value): ?string
    {
        if ($value === '' || self::normalize($value) !== null) {
            return null;
        }

        $text = self::clean($value);

        // 形狀看得懂，是內容本身有問題
        if (preg_match(self::PATTERN_YMD, $text, $m) || preg_match(self::PATTERN_COMPACT, $text, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];

            if ($year < 1990 || $year > 2099) {
                return '年份讀到的是 ' . $year . '，只收得下 1990 到 2099 年，請確認有沒有打錯';
            }

            return '沒有 ' . $year . ' 年 ' . $month . ' 月 ' . $day . ' 日這一天，請確認月份與日期';
        }

        // 08/13/2026、13/08/2026：日 <= 12 時兩種讀法都合法，不能猜
        if (preg_match('/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4}$/', $text)) {
            return '月份和日期分不出哪個是哪個，請改成年份在前的寫法，例如 2026-08-13';
        }

        // 26-08-13（世紀無法判斷）、226-08-13、20226-08-13（年份打錯碼數）
        if (preg_match('/^(\d{1,3}|\d{5,})[-\/.年]\d{1,2}[-\/.月]\d{1,2}日?$/u', $text)) {
            return '年份請寫四碼，例如 2026-08-13';
        }

        return self::MESSAGE;
    }

    /**
     * 把一列裡所有標了 'normalize' => 'date' 的欄位就地轉換。
     *
     * 轉不出來的**原樣留著**：錯誤訊息要顯示使用者實際填的內容，
     * 顯示成轉換到一半的樣子只會讓人更困惑。那一列會在驗證時被擋下來。
     *
     * @param array $row     [欄位鍵 => 值]
     * @param array $columns 欄位定義（Service::columns()）
     */
    public static function applyTo(array $row, array $columns): array
    {
        foreach ($columns as $key => $meta) {
            if (($meta['normalize'] ?? '') !== 'date') {
                continue;
            }

            if (!isset($row[$key]) || $row[$key] === '') {
                continue;
            }

            $fixed = self::normalize((string) $row[$key]);

            if ($fixed !== null) {
                $row[$key] = $fixed;
            }
        }

        return $row;
    }

    /**
     * 去掉會影響解析、但不影響語意的東西。
     *
     * 順序有意義：先轉全形再拆時間，不然全形的冒號會拆不掉。
     */
    private static function clean(string $value): string
    {
        $text = trim($value);

        // 全形數字與全形分隔符號 → 半形（中文輸入法直接打出來的）
        if (function_exists('mb_convert_kana')) {
            $text = mb_convert_kana($text, 'as', 'UTF-8');
        }

        // 儲存格是「日期時間」格式時，後面會跟著時間。日期欄不需要時間
        $text = preg_replace(
            '/[\s]+(上午|下午|AM|PM)?\s*\d{1,2}:\d{2}(:\d{2})?\s*(上午|下午|AM|PM)?$/iu',
            '',
            $text
        );

        // 2026 / 08 / 13 這種手動排版過的
        return preg_replace('/\s+/u', '', (string) $text);
    }

    /**
     * Excel 日期序號 → 日期。
     *
     * 基準是 1899-12-30 而不是 1900-01-01：Excel 為了相容 Lotus 1-2-3，
     * 把 1900 年當成閏年（有 2 月 29 日，實際上沒有），所以 1900-03-01
     * 之後的序號全部多算一天。用 1899-12-30 當基準剛好把這一天扣回來，
     * 對 SERIAL_MIN 以後的日期都是對的。
     */
    private static function fromSerial(int $serial): ?string
    {
        if ($serial < self::SERIAL_MIN || $serial > self::SERIAL_MAX) {
            return null;
        }

        // 驚嘆號讓沒填到的部分歸零（不加的話時分秒會是「現在」，跨日加減會不準）
        $date = \DateTime::createFromFormat('!Y-m-d', '1899-12-30');

        if ($date === false) {
            return null;
        }

        $date->modify('+' . $serial . ' days');

        return $date->format('Y-m-d');
    }

    /**
     * 組出 YYYY-MM-DD，順便確認那一天真的存在。
     *
     * 年份限制在 1990–2099：現場填錯一碼（把 2026 打成 226 或 20226）時
     * 要當場擋下來，不要讓一筆西元 226 年的排程進到資料庫。
     */
    private static function build(int $year, int $month, int $day): ?string
    {
        if ($year < 1990 || $year > 2099) {
            return null;
        }

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
