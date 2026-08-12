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
 * 兩碼的寫法：前一碼（高位）是 0-9 之後接 A-Z（所以 A0 = 100、B0 = 110），
 * 後一碼（低位）預設只有 0-9。走到最後一組就是當天的上限。
 *
 * 進位規則有兩種（config/app.php 的 hydration.pack_seq_mode）：
 *
 *   block    每一段只用 0/3/6/9，滿了換下一段從 0 開始 → A9 的下一個是 B0
 *   decimal  兩碼當數字直接加 3                        → A9 的下一個是 B2
 *
 * 預設 block。改設定就換規則，其他程式一行都不用動。
 *
 * ⚠ 當天發得出幾組是算得出來的，而且很可能不夠用：
 *
 *   低位字元   步進值   block    decimal
 *   0-9        3        143 組   120 組     ← 預設
 *   0-9        1        359 組   359 組
 *   0-9A-Z     3        432 組   432 組
 *   0-9A-Z     1       1295 組  1295 組
 *
 * 低位字元集與步進值都在 config 裡（pack_ones / pack_step），
 * 不夠用就改設定，程式不用動。capacity() 隨時算得出目前設定的上限。
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
     * block 模式的規則：低位加上步進值之後超出可用字元就進位，
     * 而且進位後低位歸零（不是把多出來的部分帶過去）——
     * 這就是「A9 的下一個是 B0 而不是 B2」的原因。
     */
    public static function next(int $current, ?int $step = null, ?string $mode = null): int
    {
        $step = $step ?? (int) Config::get('app.hydration.pack_step', 3);
        $mode = $mode ?? (string) Config::get('app.hydration.pack_seq_mode', 'block');

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
     * 這個順序值還在兩碼放得下的範圍內嗎。
     */
    public static function fits(int $value): bool
    {
        return $value >= 0 && $value <= self::maxValue();
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
