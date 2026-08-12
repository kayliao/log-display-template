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
 *   封包批號 = 乾片批號去掉後 5 碼 + 水化日編號 + 當日順序（2 碼）
 *
 *   DRY-A2408-10001  →  DRY-A2408  +  H0812  +  01  =>  DRY-A2408-H081201
 *
 * 當日順序從 01 開始，每次往前 3：
 *
 *   01 04 07 10 13 16 19 20 23 26 29 30 … 96 99 A0 A3 A6 A9 B0 …
 *
 * 兩碼的寫法：十位數是 0-9 之後接 A-Z（所以 A0 = 100、B0 = 110），
 * 個位數只有 0-9。走到 Z9 就是當天的上限。
 *
 * 進位規則有兩種（config/app.php 的 hydration.pack_seq_mode）：
 *
 *   block    每一段只用 0/3/6/9，滿了換下一段從 0 開始 → A9 的下一個是 B0
 *   decimal  兩碼當數字直接加 3                        → A9 的下一個是 B2
 *
 * 預設 block。改設定就換規則，其他程式一行都不用動。
 */
class PackLotNumber
{
    /** 十位數用的字元：0-9 之後接 A-Z */
    const TENS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** 兩碼能表示的最大值：Z9 */
    const MAX_VALUE = 359;

    /**
     * 順序值 → 兩碼代號。
     *   1 => '01'、10 => '10'、100 => 'A0'、110 => 'B0'、359 => 'Z9'
     */
    public static function encode(int $value): string
    {
        if ($value < 0 || $value > self::MAX_VALUE) {
            throw new AppException('當日封包批號已經用完（上限 ' . self::MAX_VALUE . '），請聯絡資訊人員。');
        }

        return self::TENS[intdiv($value, 10)] . (string) ($value % 10);
    }

    /**
     * 兩碼代號 → 順序值。認不得就回 null（不要丟例外，呼叫端多半只是想驗一下）。
     */
    public static function decode(string $code): ?int
    {
        if (strlen($code) !== 2) {
            return null;
        }

        $tens = strpos(self::TENS, strtoupper($code[0]));
        $ones = $code[1];

        if ($tens === false || !ctype_digit($ones)) {
            return null;
        }

        return $tens * 10 + (int) $ones;
    }

    /**
     * 下一個順序值。
     *
     * block 模式的規則：個位數加上步進值之後超過 9 就進位，
     * 而且進位後個位數歸零（不是把多出來的部分帶過去）——
     * 這就是「A9 的下一個是 B0 而不是 B2」的原因。
     */
    public static function next(int $current, ?int $step = null, ?string $mode = null): int
    {
        $step = $step ?? (int) Config::get('app.hydration.pack_step', 3);
        $mode = $mode ?? (string) Config::get('app.hydration.pack_seq_mode', 'block');

        if ($mode === 'decimal') {
            return $current + $step;
        }

        $ones = $current % 10;

        return ($ones + $step) > 9
            ? (intdiv($current, 10) + 1) * 10
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
     * 這個順序值還在兩碼放得下的範圍內嗎。
     */
    public static function fits(int $value): bool
    {
        return $value >= 0 && $value <= self::MAX_VALUE;
    }

    /**
     * 組出完整的封包批號。
     *
     * @param string $dryLotNo 乾片批號
     * @param string $dayCode  水化日編號
     * @param int    $value    當日順序值（1、4、7 …）
     */
    public static function compose(string $dryLotNo, string $dayCode, int $value, ?int $trim = null): string
    {
        $trim   = $trim ?? (int) Config::get('app.hydration.pack_trim', 5);
        $dryLot = trim($dryLotNo);

        if (mb_strlen($dryLot) <= $trim) {
            // 去掉尾碼之後什麼都不剩，組出來的號碼會是別人的前綴，很危險
            throw new AppException(
                '乾片批號「' . $dryLotNo . '」長度不足 ' . ($trim + 1) . ' 碼，無法產生封包批號。'
            );
        }

        return mb_substr($dryLot, 0, mb_strlen($dryLot) - $trim) . strtoupper(trim($dayCode)) . self::encode($value);
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
     * 一天總共發得出幾組。block 143 組、decimal 120 組。
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
