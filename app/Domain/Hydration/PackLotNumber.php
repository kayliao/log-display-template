<?php

namespace App\Domain\Hydration;

use App\Core\AppException;
use App\Core\Config;

/**
 * 封包批號的組成規則。
 *
 * 這個類別只做「算」，完全不碰資料庫也不管併發 ——
 * 規則本身才是最容易吵起來、也最需要單獨看懂的部分，
 * 混在交易與鎖裡面的話沒有人敢改。
 *
 *   PACKET_LOT_TEMP_AUTO = PPCUP_LOT 去掉後 5 碼
 *                        + PACKET_SCHEDULE_DATE_CODE + 當日順序（2 碼）
 *
 *   PPCUP-A2408-10001  →  PPCUP-A2408-  +  H0812  +  01  =>  PPCUP-A2408-H081201
 *
 * 當日順序從 01 開始，每次往前 3。兩碼就是一個數字：
 *
 *   前一碼（十位）是 0-9 之後接 A-Z，後一碼（個位）是 0-9
 *   => A0 = 100、A9 = 109、B0 = 110、Z9 = 359
 *
 *   01 04 07 10 13 16 19 22 25 28 31 … 94 97 A0 A3 A6 A9 B2 B5 …
 *
 * 進位規則（config/app.php 的 hydration.pack_seq_mode）：
 *
 *   decimal  ← 現場確認的規則。兩碼當數字直接加 3，A9 的下一個是 B2
 *   block    每一段只用 0/3/6/9，A9 的下一個是 B0（另一種常見寫法，保留備用）
 *
 * ⚠ 當天發得出幾組是算得出來的，而且很可能不夠用：
 *
 *   後一碼   步進值   decimal   block
 *   0-9      3        120 組    143 組     ← 目前設定是 decimal + 3
 *   0-9      1        359 組    359 組
 *
 * 兩碼最多就是 Z9 = 359。現場估一天最多一千筆，
 * 如果每一筆都要一個號，兩碼一定不夠 —— 要改成三碼，
 * 那會動到號碼長度與格式，必須跟機台端、封包端一起確認。
 * capacity() 隨時算得出目前設定的上限。
 */
class PackLotNumber
{
    /** 高位字元：0-9 之後接 A-Z */
    const TENS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 低位字元的預設值。要提高當日容量就在 config 改成 self::TENS */
    const ONES_DEFAULT = '0123456789';

    /**
     * 低位可用的字元。
     * 這個值決定「一組進位要幾個號」，也就決定了當天的容量。
     */
    public static function ones(): string
    {
        $ones = (string) Config::get('app.hydration.pack_ones', self::ONES_DEFAULT);

        return $ones === '' ? self::ONES_DEFAULT : strtoupper($ones);
    }

    /** 兩碼能表示的最大值（預設 Z9 = 359） */
    public static function maxValue(): int
    {
        return strlen(self::TENS) * strlen(self::ones()) - 1;
    }

    /**
     * 順序值 → 兩碼代號。
     *   低位是 0-9 時：1 => '01'、10 => '10'、100 => 'A0'、110 => 'B0'、359 => 'Z9'
     */
    public static function encode(int $value): string
    {
        $ones = self::ones();
        $base = strlen($ones);

        if ($value < 0 || $value > self::maxValue()) {
            throw new AppException(
                '當日封包批號已經用完（上限 ' . self::capacity() . ' 組），請聯絡資訊人員。'
            );
        }

        return self::TENS[intdiv($value, $base)] . $ones[$value % $base];
    }

    /**
     * 兩碼代號 → 順序值。認不得就回 null（不要丟例外，呼叫端多半只是想驗一下）。
     */
    public static function decode(string $code): ?int
    {
        if (strlen($code) !== 2) {
            return null;
        }

        $ones = self::ones();
        $high = strpos(self::TENS, strtoupper($code[0]));
        $low  = strpos($ones, strtoupper($code[1]));

        if ($high === false || $low === false) {
            return null;
        }

        return $high * strlen($ones) + $low;
    }

    /**
     * 下一個順序值。
     *
     * decimal（現場的規則）：兩碼當一個數字直接加步進值。
     *   109（A9）+ 3 = 112（B2）。中間的 110（B0）、111（B1）
     *   是合法的號碼，只是這一串序列不會走到而已。
     *
     * block（備用）：低位加上步進值之後超出可用字元就進位，
     *   而且進位後低位歸零，所以 A9 的下一個是 B0。
     */
    public static function next(int $current, ?int $step = null, ?string $mode = null): int
    {
        $step = $step ?? (int) Config::get('app.hydration.pack_step', 3);
        $mode = $mode ?? (string) Config::get('app.hydration.pack_seq_mode', 'decimal');

        if ($mode === 'decimal') {
            return $current + $step;
        }

        $base = strlen(self::ones());
        $low  = $current % $base;

        return ($low + $step) > ($base - 1)
            ? (intdiv($current, $base) + 1) * $base
            : $current + $step;
    }

    /**
     * 第一個順序值。現場的第一號是 01，不是 00。
     */
    public static function first(): int
    {
        return 1;
    }

    /**
     * 「當天已發出去的最大號」→ 下一個要發的順序值。
     *
     * 一個都還沒發（null）就是今天的第一號。
     * 認不得的代號（有人手動塞了奇怪的東西）也當成還沒發，
     * 反正真的撞號的話唯一鍵會擋下來，不會發出重複的號。
     */
    public static function firstOrNext(?string $lastCode): int
    {
        if ($lastCode === null) {
            return self::first();
        }

        $value = self::decode($lastCode);

        return $value === null ? self::first() : self::next($value);
    }

    /**
     * 這個順序值還在兩碼放得下的範圍內嗎。
     */
    public static function fits(int $value): bool
    {
        return $value >= 0 && $value <= self::maxValue();
    }

    /**
     * 組出完整的封包批號。
     *
     * @param string $ppcupLot 乾片批號（PPCUP_LOT）
     * @param string $dateCode 封包日編碼（PACKET_SCHEDULE_DATE_CODE）
     * @param int    $value    當日順序值（1、4、7 …）
     */
    public static function compose(string $ppcupLot, string $dateCode, int $value, ?int $trim = null): string
    {
        $trim = $trim ?? (int) Config::get('app.hydration.pack_trim', 5);
        $lot  = trim($ppcupLot);

        if (mb_strlen($lot) <= $trim) {
            // 去掉尾碼之後什麼都不剩，組出來的號碼會是別人的前綴，很危險
            throw new AppException(
                '乾片批號「' . $ppcupLot . '」長度不足 ' . ($trim + 1) . ' 碼，無法產生封包批號。'
            );
        }

        return mb_substr($lot, 0, mb_strlen($lot) - $trim) . strtoupper(trim($dateCode)) . self::encode($value);
    }

    /**
     * 前 n 個順序代號。給說明頁與測試用，順便當成規則的活文件。
     *
     * @return string[] ['01', '04', '07', …]
     */
    public static function preview(int $count = 12, ?string $mode = null): array
    {
        $out   = [];
        $value = self::first();

        for ($i = 0; $i < $count && self::fits($value); $i++) {
            $out[] = self::encode($value);
            $value = self::next($value, null, $mode);
        }

        return $out;
    }

    /**
     * 一天總共發得出幾組。
     * 預設設定（低位 0-9、步進 3）是 block 143 組、decimal 120 組。
     */
    public static function capacity(?string $mode = null): int
    {
        $count = 0;
        $value = self::first();

        while (self::fits($value)) {
            $count++;
            $value = self::next($value, null, $mode);
        }

        return $count;
    }
}
