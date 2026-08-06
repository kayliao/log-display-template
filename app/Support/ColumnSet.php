<?php

namespace App\Support;

/**
 * 報表欄位定義。
 *
 * 一份欄位設定同時決定三件事，所以只寫一次：
 *   1. 表頭 HTML（含「大標底下掛小標」的兩層表頭）
 *   2. 前端表格要顯示哪些欄位、怎麼排序、哪一欄有放大鏡
 *   3. CSV 匯出的欄位與標題
 *
 * 欄位寫法：
 *
 *   [
 *     // 一般欄位
 *     ['key' => 'machine_id', 'title' => '機台編號', 'width' => 120],
 *
 *     // 有說明泡泡的欄位（滑鼠移到標題會顯示）
 *     ['key' => 'oee', 'title' => '稼動率', 'tip' => '實際運轉時間 ÷ 應運轉時間'],
 *
 *     // 有放大鏡的欄位：點下去會用該列資料去打 api，結果顯示在彈窗
 *     ['key' => 'machine_id', 'title' => '機台', 'drill' => [
 *         'api'    => '/api/machine/detail.php',
 *         'params' => ['machine_id'],          // 要從該列帶哪些欄位當參數
 *         'title'  => '機台明細',
 *     ]],
 *
 *     // 大標底下掛小標
 *     ['title' => '產量', 'children' => [
 *         ['key' => 'qty_ok', 'title' => '良品'],
 *         ['key' => 'qty_ng', 'title' => '不良'],
 *     ]],
 *   ]
 *
 * 其他可用屬性：
 *   align     'left' | 'center' | 'right'（數字欄位建議 right）
 *   format    'number' | 'decimal' | 'percent' | 'datetime' | 'date' | 'status'
 *   sortable  false 可關閉該欄排序，預設開啟
 *   visible   false 表示預設隱藏（仍可由使用者切換顯示）
 *   className 額外的 CSS class
 */
class ColumnSet
{
    /** @var array 原始定義 */
    private $columns;

    public function __construct(array $columns)
    {
        $this->columns = $columns;
    }

    public static function make(array $columns): self
    {
        return new self($columns);
    }

    /**
     * 是否存在「大標 + 小標」的兩層表頭。
     */
    public function hasGroups(): bool
    {
        foreach ($this->columns as $col) {
            if (!empty($col['children'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 攤平後的實際資料欄位（依畫面由左至右的順序）。
     */
    public function leaves(): array
    {
        $leaves = [];

        foreach ($this->columns as $col) {
            if (!empty($col['children'])) {
                foreach ($col['children'] as $child) {
                    $child['group'] = $col['title'] ?? '';
                    $leaves[]       = $child;
                }
                continue;
            }

            $leaves[] = $col;
        }

        return $leaves;
    }

    /**
     * 表頭列。回傳兩列（有大標時）或一列。
     *
     * 有大標時：
     *   第一列：沒有子欄位的欄位 rowspan=2，有子欄位的大標 colspan=n
     *   第二列：所有小標
     */
    public function headerRows(): array
    {
        if (!$this->hasGroups()) {
            return [$this->columns];
        }

        $top    = [];
        $bottom = [];

        foreach ($this->columns as $col) {
            if (!empty($col['children'])) {
                $col['colspan'] = count($col['children']);
                $col['isGroup'] = true;
                $top[]          = $col;

                foreach ($col['children'] as $child) {
                    $bottom[] = $child;
                }
                continue;
            }

            $col['rowspan'] = 2;
            $top[]          = $col;
        }

        return [$top, $bottom];
    }

    /**
     * 給前端 JS 的欄位設定。
     */
    public function toJs(): array
    {
        $out = [];

        foreach ($this->leaves() as $col) {
            $out[] = [
                'key'       => $col['key'] ?? null,
                'title'     => $col['title'] ?? '',
                'align'     => $col['align'] ?? null,
                'format'    => $col['format'] ?? null,
                'sortable'  => $col['sortable'] ?? true,
                'visible'   => $col['visible'] ?? true,
                'width'     => $col['width'] ?? null,
                'className' => $col['className'] ?? null,
                'drill'     => $col['drill'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * 可排序欄位白名單，直接餵給 TableQuery / Paginator。
     * 這樣「畫面上能點的欄位」和「後端允許排序的欄位」永遠一致，
     * 不會出現前端能點但後端擋掉的狀況。
     */
    public function sortableKeys(): array
    {
        $keys = [];

        foreach ($this->leaves() as $col) {
            if (!empty($col['key']) && ($col['sortable'] ?? true)) {
                $keys[] = $col['key'];
            }
        }

        return $keys;
    }

    /**
     * CSV 匯出用的 [欄位鍵 => 標題]。
     * 有大標時標題會寫成「產量-良品」，匯出後才看得懂。
     */
    public function exportColumns(): array
    {
        $out = [];

        foreach ($this->leaves() as $col) {
            if (empty($col['key'])) {
                continue;
            }

            $title = $col['title'] ?? $col['key'];
            if (!empty($col['group'])) {
                $title = $col['group'] . '-' . $title;
            }

            $out[$col['key']] = $title;
        }

        return $out;
    }
}
