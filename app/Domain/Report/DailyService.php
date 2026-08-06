<?php

namespace App\Domain\Report;

use App\Core\AppException;
use App\Core\Db\Db;
use App\Core\TableQuery;

/**
 * 每日生產日報 —— 商業邏輯。
 *
 * Repository 負責「怎麼從資料庫拿」，Service 負責「拿到之後要做什麼」：
 * 代碼轉中文、欄位計算、跨表組合。頁面與 API 只跟 Service 說話。
 */
class DailyService
{
    /** @var DailyRepository */
    private $repo;

    public function __construct(?DailyRepository $repo = null)
    {
        $this->repo = $repo ?: new DailyRepository();
    }

    /**
     * 分頁查詢。
     */
    public function table(array $filters, TableQuery $query): array
    {
        [$sql, $bind] = $this->repo->query($filters);

        $result = $query->paginate(Db::pg(), $sql, $bind);

        // 需要加工的欄位在這裡處理，例如把代碼轉成中文：
        // foreach ($result['rows'] as $i => $row) {
        //     $result['rows'][$i]['type_label'] = self::typeLabel($row['type']);
        // }

        return $result;
    }

    /**
     * 放大鏡點下去看到的內容。
     *
     * 回傳格式就是 App.modal.detail 認得的「多段區塊」結構。
     * 要讓彈窗多一段內容，改這裡就好，前端一行都不用動。
     */
    public function detail(string $id): array
    {
        $row = $this->repo->find($id);

        if ($row === null) {
            throw new AppException('查不到 ' . $id . ' 的資料。', 404);
        }

        return [
            'title'    => '每日生產日報 ' . $id,
            'sections' => [
                [
                    'type'   => 'fields',
                    'title'  => '基本資料',
                    'fields' => [
                        ['label' => '欄位一', 'value' => $row['col1']],
                        ['label' => '欄位二', 'value' => $row['col2']],
                    ],
                ],
                // 想再加一張表就多一段：
                // [
                //     'type'    => 'table',
                //     'title'   => '明細',
                //     'columns' => [ ['key' => 'a', 'title' => 'A'] ],
                //     'rows'    => $this->repo->details($id),
                // ],
            ],
        ];
    }
}
