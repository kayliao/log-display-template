<?php

namespace App\Domain\Announcement;

use App\Core\Config;
use App\Core\Logger;

/**
 * 首頁公告。
 *
 * 公告從哪裡來由 config/announcement.php 的 'source' 決定：
 *   'config' = 直接寫在設定檔裡（預設，不需要資料表）
 *   'db'     = 讀 sys_announcement 資料表
 *
 * 「現在該顯示哪幾則」的規則只寫在這一支，不寫進 SQL——
 * 兩種來源共用同一份判斷，之後把 source 換成 'db' 時，
 * 不會出現「換個來源公告就突然多幾則」這種難查的差異。
 *
 * ⚠ 讀取失敗時回傳空陣列而不是丟例外。
 *   公告只是輔助資訊，不該因為它讓整個首頁打不開。
 */
class AnnouncementService
{
    /** 顏色等級的排序權重，數字大的排前面 */
    private static $levelWeight = [
        'danger'  => 3,
        'warning' => 2,
        'info'    => 1,
    ];

    /**
     * 目前有效、且該角色看得到的公告。
     *
     * 回傳欄位固定為 level / title / content / date，
     * 剛好是 announcement 元件吃的四個欄位。
     */
    public function active(string $role): array
    {
        try {
            $rows = $this->provider()->all();
        } catch (\Throwable $e) {
            Logger::warning('讀取公告失敗，首頁改為不顯示公告', ['error' => $e->getMessage()]);

            return [];
        }

        $today = date('Y-m-d');
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row) && $this->visible($row, $role, $today)) {
                $items[] = $this->normalize($row);
            }
        }

        usort($items, [$this, 'compare']);

        return $items;
    }

    private function provider(): AnnouncementProvider
    {
        return Config::get('announcement.source', 'config') === 'db'
            ? new DbAnnouncementProvider()
            : new ConfigAnnouncementProvider();
    }

    /**
     * 這一則今天要不要顯示、這個角色能不能看。
     */
    private function visible(array $row, string $role, string $today): bool
    {
        $start  = $this->day($row['start_date'] ?? '');
        $end    = $this->day($row['end_date'] ?? '');
        $target = trim((string) ($row['target_role'] ?? ''));

        // 日期留空代表不限，所以要有值才判斷得出「還沒開始」或「已經結束」
        if ($start !== '' && $today < $start) {
            return false;
        }

        if ($end !== '' && $today > $end) {
            return false;
        }

        return $target === '' || strcasecmp($target, $role) === 0;
    }

    /**
     * 補齊元件需要的欄位，並把不認得的 level 收斂成 info。
     */
    private function normalize(array $row): array
    {
        $level = strtolower(trim((string) ($row['level'] ?? '')));

        if (!isset(self::$levelWeight[$level])) {
            $level = 'info';
        }

        $date = $row['date'] ?? '';
        if (trim((string) $date) === '') {
            // 沒特別填就用開始日期，兩個都沒有就不顯示日期
            $date = $row['start_date'] ?? '';
        }

        return [
            'level'   => $level,
            'title'   => (string) ($row['title'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'date'    => $this->day($date),
        ];
    }

    /**
     * 統一成 YYYY-MM-DD，好直接用字串比大小。
     *
     * 認不出來的寫法（例如手打成 2026/8/32）當成「沒填」，
     * 也就是不限日期——公告多顯示幾天，比因為一個錯字整則消失、
     * 現場又完全看不出原因來得好處理。
     */
    private function day($value): string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        $ts = strtotime($value);

        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    /**
     * danger → warning → info，同一級再照日期由新到舊。
     */
    private function compare(array $a, array $b): int
    {
        $diff = self::$levelWeight[$b['level']] - self::$levelWeight[$a['level']];

        return $diff !== 0 ? $diff : strcmp($b['date'], $a['date']);
    }
}
