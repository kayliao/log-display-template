<?php

namespace App\Domain\Announcement;

use App\Core\Config;
use App\Core\Db\Db;

/**
 * 從資料庫讀取公告。
 *
 * ⚠ 尚未接上實際資料表。要改用這一個來源時：
 *   1. 依實際結構調整 config/announcement.php 的 'db' 區塊（連線與資料表）
 *   2. 欄位名稱跟下面假設的不同，就改這支的 SQL
 *   3. 把 config/announcement.php 的 'source' 改成 'db'
 * 其他地方一行都不用動。
 *
 * 假設的資料表：
 *   sys_announcement(id, level, title, content, start_date, end_date, target_role, created_at)
 *
 * 這裡不寫日期與角色的 WHERE，整張表撈回來交給 AnnouncementService 過濾——
 * 公告表通常只有幾十筆，省下的那點查詢時間不值得讓同一份規則存在兩個版本。
 * 真的長到需要在 SQL 過濾時，記得 Service 那份判斷也要跟著看一次。
 */
class DbAnnouncementProvider implements AnnouncementProvider
{
    public function all(): array
    {
        $cfg = Config::get('announcement.db', []);

        return Db::conn($cfg['connection'] ?? null)->select(
            sprintf(
                "SELECT id, level, title, content,
                        TO_CHAR(created_at, 'YYYY-MM-DD') AS date,
                        TO_CHAR(start_date, 'YYYY-MM-DD') AS start_date,
                        TO_CHAR(end_date,   'YYYY-MM-DD') AS end_date,
                        target_role
                   FROM %s",
                $cfg['table'] ?? 'sys_announcement'
            )
        );
    }
}
