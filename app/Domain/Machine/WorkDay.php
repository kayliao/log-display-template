<?php

namespace App\Domain\Machine;

/**
 * 工作日 → 時間區間。
 *
 * ★ 現場的一天不是 00:00～24:00，是從早班交接的 08:00 到隔天 08:00。
 *
 *   所以「查 9/22」指的是 **9/22 08:00 ～ 9/23 08:00**。夜班做到凌晨三點的那些
 *   東西算在 9/22 頭上，不是 9/23 —— 現場交班、盤點、算稼動都是這樣算的，
 *   而機台狀態區段表自己累積的時數通常也是從這個時間起算。畫面上用日曆日切，
 *   夜班的工作就會被切成兩半掛在兩天上，跟現場報表對不起來。
 *
 * ⚠ 因此「結束日」那一天是**整天都算**的：右界是結束日 **+ 1 天** 的 08:00。
 *   查一天真的是 24 小時，不是 16 小時。
 *
 * ⚠ 右界是**不含**的（半開區間 `[start, end)`）。查詢條件要寫
 *   `< :end_at` 而不是 `<= :end_at` —— 寫成 `<=` 的話，剛好落在 08:00:00
 *   那一筆會同時算進前後兩個工作日，兩天的總時數加起來會超過實際時間。
 *
 * 交接時間在 `config/app.php` 的 `machine.day_start`。
 *
 * > 班別報表（`Report\ShiftService`）目前把白班 08:00–20:00 寫死在程式裡，
 * > 跟這裡是同一件事但還沒收在一起（`config/app.php` 那邊也記了這件事）。
 * > 哪天現場改交接時間，兩個地方都要動。
 */
class WorkDay
{
    /**
     * 某一天的工作日起點。
     *
     * @param string $date YYYY-MM-DD
     */
    public static function startAt(string $date): string
    {
        return $date . ' ' . config('app.machine.day_start', '08:00:00');
    }

    /**
     * 某一天的工作日結束（＝隔天的 08:00，**不含**）。
     */
    public static function endAt(string $date): string
    {
        return self::startAt(date('Y-m-d', strtotime($date . ' +1 day')));
    }

    /**
     * 日期區間 → 時間區間 `[start, end)`。
     *
     * 查詢條件列送來的是兩個日期（開始日、結束日），資料表裡是時間戳，
     * 這一支負責兩者之間的換算。單日查詢就是 $start === $end。
     *
     * @return array{0:string, 1:string} [起點（含）, 終點（不含）]
     */
    public static function range(string $start, string $end): array
    {
        return [self::startAt($start), self::endAt($end)];
    }

    /**
     * 「現在」算在哪一個工作日。
     *
     * ★ 凌晨 00:00～08:00 之間，這個答案是**前一天**。
     *
     *   需要這一支的原因是「預設查今天」：那段時間如果用日曆的今天當預設，
     *   算出來的區間（今天 08:00 ～ 隔天 08:00）整段都還沒發生，
     *   圖會是一片空白 —— 而那正是夜班在做事的時候，最需要看的時候。
     *
     * @param int|null $now 只有測試會傳；不傳就是現在
     */
    public static function today(?int $now = null): string
    {
        $now = $now ?? time();

        $start = config('app.machine.day_start', '08:00:00');

        // 今天的交接時間還沒到 → 還在前一個工作日
        if (date('H:i:s', $now) < $start) {
            return date('Y-m-d', strtotime('-1 day', $now));
        }

        return date('Y-m-d', $now);
    }
}
