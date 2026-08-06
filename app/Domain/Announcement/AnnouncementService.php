<?php

namespace App\Domain\Announcement;

use App\Core\Db\Db;
use App\Core\Logger;

/**
 * 首頁公告。
 *
 * ⚠ 公告資料表可能還不存在。
 *   查詢失敗時回傳空陣列而不是丟例外——
 *   公告只是輔助資訊，不該因為它讓整個首頁打不開。
 *
 * 假設的資料表：
 *   sys_announcement(id, level, title, content, start_date, end_date, target_role, created_at)
 */
class AnnouncementService
{
    /**
     * 目前有效、且該角色看得到的公告。
     */
    public function active(string $role): array
    {
        try {
            return Db::pg()->select(
                "SELECT id, level, title, content,
                        TO_CHAR(created_at, 'YYYY-MM-DD') AS date
                   FROM sys_announcement
                  WHERE CURRENT_DATE BETWEEN start_date AND end_date
                    AND (target_role IS NULL OR target_role = '' OR target_role = :role)
                  ORDER BY level DESC, created_at DESC",
                ['role' => $role]
            );
        } catch (\Throwable $e) {
            Logger::warning('讀取公告失敗，首頁改為不顯示公告', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
