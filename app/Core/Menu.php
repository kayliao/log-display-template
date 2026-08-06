<?php

namespace App\Core;

/**
 * 選單處理。
 *
 * config/menu.php 是全系統唯一的功能清單，這個類別負責：
 *   - 依目前使用者的權限過濾（沒權限的項目連看都看不到）
 *   - 找出目前所在的頁面，供 header 標示 active 與顯示頁面標題
 *   - 取出該頁的「程式說明」給 header 的 note 用
 *
 * header 主選單與首頁功能小卡都吃 tree()，所以兩邊永遠一致。
 */
class Menu
{
    /** @var array|null 過濾後的選單快取 */
    private static $tree;

    /** @var array|null 目前所在的選單項目 */
    private static $current;

    /** @var array|null 目前所在的第一層（大項目） */
    private static $currentGroup;

    /**
     * 依權限過濾後的選單樹。
     */
    public static function tree(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }

        return self::$tree = self::filter(Config::get('menu', []));
    }

    /**
     * 首頁小卡用的資料：只保留「至少有一個可用子項目」的第一層。
     */
    public static function cards(): array
    {
        return array_values(array_filter(self::tree(), static function ($group) {
            return ($group['card'] ?? true) !== false && !empty($group['children']);
        }));
    }

    /**
     * 目前所在的選單項目（第二層），找不到回傳 null。
     */
    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current ?: null;
        }

        self::$current = false;
        $path          = Request::path();

        foreach (self::tree() as $group) {
            foreach ($group['children'] ?? [] as $item) {
                if (empty($item['url'])) {
                    continue;
                }

                if (rtrim($item['url'], '/') === rtrim($path, '/')) {
                    $item['group'] = $group['title'];
                    self::$current = $item;

                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * 目前所在的第一層（大項目），找不到回傳 null。
     *
     * header 的子選單靠這個決定「要列出哪些子功能」——
     * 使用者現在在「報表」底下，子選單就只列報表的東西。
     */
    public static function currentGroup(): ?array
    {
        if (self::$currentGroup !== null) {
            return self::$currentGroup ?: null;
        }

        self::$currentGroup = false;

        foreach (self::tree() as $group) {
            foreach ($group['children'] ?? [] as $item) {
                if (Url::isCurrent($item['url'] ?? null)) {
                    self::$currentGroup = $group;

                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * header 顯示的頁面標題。
     * 優先用頁面自己傳的 title，其次用選單設定的名稱。
     */
    public static function pageTitle(?string $explicit = null): string
    {
        if ($explicit) {
            return $explicit;
        }

        $current = self::current();

        return $current['title'] ?? '';
    }

    /**
     * 目前頁面的程式說明，給 header 的「程式說明」按鈕用。
     */
    public static function pageNote(?string $explicit = null): string
    {
        if ($explicit) {
            return $explicit;
        }

        $current = self::current();

        return $current['note'] ?? '';
    }

    /**
     * 遞迴過濾沒有權限的項目。
     * 第一層若所有子項目都被濾掉，整個第一層也不顯示。
     */
    private static function filter(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (!Auth::can($item['perm'] ?? null)) {
                continue;
            }

            if (!empty($item['children'])) {
                $item['children'] = self::filter($item['children']);

                if ($item['children'] === []) {
                    continue;
                }
            }

            $result[] = $item;
        }

        return $result;
    }
}
