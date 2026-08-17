<?php

namespace App\Domain\Announcement;

/**
 * 公告來源介面。
 *
 * 現在用設定檔（ConfigAnnouncementProvider），
 * 之後公告改放資料表時換成 DbAnnouncementProvider，
 * AnnouncementService 與所有頁面完全不用動。
 */
interface AnnouncementProvider
{
    /**
     * 取回全部公告，不做日期與角色過濾。
     *
     * 「現在該顯示哪幾則」統一由 AnnouncementService 判斷，
     * 不要在各自的來源裡再過濾一次——規則寫在兩個地方遲早會不一致。
     *
     * @return array[] 每筆可含 level / title / content / date /
     *                 start_date / end_date / target_role，缺欄位由 Service 補預設值
     */
    public function all(): array;
}
