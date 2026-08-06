<?php

namespace App\Core;

use App\Core\Db\Connection;
use App\Core\Db\Paginator;

/**
 * 報表查詢參數解析。
 *
 * 前端 App.table 送出的分頁/排序/搜尋參數格式是固定的，
 * 這個類別負責把它們解析成 Paginator 需要的選項，
 * 讓每支報表 API 只需要專心寫自己的 SQL。
 *
 * 前端送出的參數：
 *   page      頁碼，從 1 開始
 *   size      每頁筆數
 *   sort      排序欄位
 *   dir       asc / desc
 *   keyword   全域關鍵字（要不要用由 Repository 決定）
 */
class TableQuery
{
    /** @var int */
    public $page;

    /** @var int */
    public $size;

    /** @var string */
    public $sort;

    /** @var string */
    public $dir;

    /** @var string */
    public $keyword;

    /** @var string[] 允許排序的欄位白名單 */
    private $sortable = [];

    /**
     * @param string[] $sortable 允許排序的欄位。不在名單內的排序請求會被忽略。
     * @param string   $defaultSort 預設排序欄位
     */
    public static function fromRequest(array $sortable = [], string $defaultSort = '', string $defaultDir = 'asc'): self
    {
        $q = new self();

        $q->page     = max(1, Request::int('page', 1));
        $q->size     = Request::int('size', 50);
        $q->sort     = Request::str('sort', $defaultSort);
        $q->dir      = strtolower(Request::str('dir', $defaultDir)) === 'desc' ? 'desc' : 'asc';
        $q->keyword  = Request::str('keyword');
        $q->sortable = $sortable;

        return $q;
    }

    /**
     * 直接跑分頁查詢並回傳結果。
     *
     *   $result = TableQuery::fromRequest(['log_time'], 'log_time', 'desc')
     *                       ->paginate($conn, $sql, $bind);
     */
    public function paginate(Connection $conn, string $sql, array $bind = []): array
    {
        return Paginator::run($conn, $sql, $bind, [
            'page'     => $this->page,
            'size'     => $this->size,
            'sort'     => $this->sort,
            'dir'      => $this->dir,
            'sortable' => $this->sortable,
        ]);
    }

    /**
     * 把分頁結果直接輸出成標準 JSON 回應。
     */
    public function respond(array $result, array $extra = []): void
    {
        Response::page(
            $result['rows'],
            $result['total'],
            $result['page'],
            $result['size'],
            $extra
        );
    }
}
