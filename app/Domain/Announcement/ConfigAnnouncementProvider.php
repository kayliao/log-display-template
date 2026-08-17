<?php

namespace App\Domain\Announcement;

use App\Core\Config;

/**
 * 從 config/announcement.php 讀取公告。
 *
 * 適合公告不多、不常改的情況——不需要資料表，也不需要後台維護頁，
 * 代價是改公告要改檔案並重新佈署。
 */
class ConfigAnnouncementProvider implements AnnouncementProvider
{
    public function all(): array
    {
        $items = Config::get('announcement.items', []);

        return is_array($items) ? $items : [];
    }
}
