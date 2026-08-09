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
 *
 *     // 要幾層就寫幾層，children 一路往下掛即可
 *     ['title' => '今日產量', 'children' => [
 *         ['title' => '白班', 'children' => [
 *             ['key' => 'day_ok', 'title' => '良品'],
 *             ['key' => 'day_ng', 'title' => '不良'],
 *         ]],
 *         ['title' => '夜班', 'children' => [
 *             ['key' => 'night_ok', 'title' => '良品'],
 *             ['key' => 'night_ng', 'title' => '不良'],
 *         ]],
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
     * 是否存在「大標 + 小標」的多層表頭。
     */
    public function hasGroups(): bool
    {
        return $this->depth() > 1;
    }

    /**
     * 表頭有幾層。沒有大標時是 1。
     */
    public function depth(): int
    {
        return self::depthOf($this->columns);
    }

    /**
     * 攤平後的實際資料欄位（依畫面由左至右的順序）。
     *
     * 每個欄位會多兩個鍵：
     *   group   最貼近它的那一層大標（維持舊有用法）
     *   groups  由外而內的完整大標路徑，例如 ['今日產量', '良品']
     */
    public function leaves(): array
    {
        return self::collectLeaves($this->columns, []);
    }

    /**
     * 表頭列。層數由欄位定義自動決定，要幾層就幾層。
     *
     *   有子欄位的大標   colspan = 它底下的實際欄位數
     *   沒有子欄位的欄位 rowspan = 剩下的層數（一路併到最底）
     *
     * 所以「大項 → 小項 → 更小項」跟「只有一層」用的是同一份程式碼，
     * 頁面只要把 children 再往下寫一層就好。
     */
    public function headerRows(): array
    {
        $depth = $this->depth();
        $rows  = array_fill(0, $depth, []);

        self::fillHeader($this->columns, 0, $depth, $rows);

        return $rows;
    }

    /**
     * 遞迴算出最深有幾層。
     */
    private static function depthOf(array $columns): int
    {
        $max = 1;

        foreach ($columns as $col) {
            if (!empty($col['children'])) {
                $max = max($max, 1 + self::depthOf($col['children']));
            }
        }

        return $max;
    }

    /**
     * 一個欄位底下有幾個實際資料欄位（決定大標要 colspan 多少）。
     */
    private static function leafCountOf(array $col): int
    {
        if (empty($col['children'])) {
            return 1;
        }

        $count = 0;

        foreach ($col['children'] as $child) {
            $count += self::leafCountOf($child);
        }

        return $count;
    }

    private static function collectLeaves(array $columns, array $path): array
    {
        $out = [];

        foreach ($columns as $col) {
            if (!empty($col['children'])) {
                $children = $col['children'];

                $deeper   = $path;
                $deeper[] = (string) ($col['title'] ?? '');

                $out = array_merge($out, self::collectLeaves($children, $deeper));
                continue;
            }

            $col['groups'] = $path;
            $col['group']  = $path === [] ? '' : $path[count($path) - 1];

            $out[] = $col;
        }

        return $out;
    }

    /**
     * 把每一層的表頭格填進對應的列。
     *
     * @param array $rows 依參考傳入，直接把格子塞進 $rows[層數]
     */
    private static function fillHeader(array $columns, int $level, int $depth, array &$rows): void
    {
        foreach ($columns as $col) {
            if (!empty($col['children'])) {
                $children = $col['children'];
                unset($col['children']);      // 表頭格只需要標題與跨欄數

                $col['colspan'] = self::leafCountOf(['children' => $children]);
                $col['isGroup'] = true;

                $rows[$level][] = $col;

                self::fillHeader($children, $level + 1, $depth, $rows);
                continue;
            }

            // 只有一層時不寫 rowspan="1"，表頭 HTML 乾淨一點
            if ($depth - $level > 1) {
                $col['rowspan'] = $depth - $level;
            }

            $rows[$level][] = $col;
        }
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
     *
     * 有大標時把整條路徑串起來，例如「今日產量-良品-數量」，
     * 匯出的檔案脫離畫面之後也看得懂是哪一欄。
     */
    public function exportColumns(): array
    {
        $out = [];

        foreach ($this->leaves() as $col) {
            if (empty($col['key'])) {
                continue;
            }

            $path   = array_filter($col['groups'] ?? [], 'strlen');
            $path[] = $col['title'] ?? $col['key'];

            $out[$col['key']] = implode('-', $path);
        }

        return $out;
    }
}
