<?php

namespace App\Core;

/**
 * 樣板渲染。
 *
 * 刻意不引入 Blade / Twig：樣板就是純 PHP 檔，
 * 現場同事打開就看得懂，也不需要編譯快取目錄。
 *
 * 用法（頁面入口）：
 *   View::render('pages/machine/status', ['title' => '機台狀態', 'rows' => $rows]);
 *
 * 用法（樣板內嵌共用元件）：
 *   View::component('card', ['title' => '報表', 'items' => $items]);
 */
class View
{
    /** @var array 目前渲染中的資料，供 partial 取用 */
    private static $shared = [];

    /**
     * 渲染一個頁面，並套上外層版型（header + 內容 + footer）。
     *
     * @param string $view   相對於 app/Views 的路徑，不含 .php
     * @param array  $data   樣板變數
     * @param string $layout 版型名稱，傳 null 表示不套版型（例如彈窗內容片段）
     */
    public static function render(string $view, array $data = [], ?string $layout = 'app'): void
    {
        $content = self::capture($view, $data);

        if ($layout === null) {
            echo $content;

            return;
        }

        // 版型需要知道頁面標題、目前選單位置等資訊
        $data['content'] = $content;

        echo self::capture('layouts/' . $layout, $data);
    }

    /**
     * 渲染共用元件並直接輸出。
     *
     * @param string $name 相對於 app/Views/components 的檔名，不含 .php
     */
    public static function component(string $name, array $data = []): void
    {
        echo self::componentHtml($name, $data);
    }

    /**
     * 同 component()，但回傳字串而不是直接輸出。
     * 需要把元件塞進另一個元件時使用（例如把表格放進分頁籤）。
     */
    public static function componentHtml(string $name, array $data = []): string
    {
        // 元件一律不繼承外層變數。
        // 否則頁面的 $title 會意外變成表格的標題，這種問題很難查。
        return self::capture('components/' . $name, $data, false);
    }

    /**
     * 渲染 partial（header / footer 這類版型片段）。
     */
    public static function partial(string $name, array $data = []): void
    {
        echo self::capture('partials/' . $name, $data);
    }

    /**
     * 把樣板輸出抓成字串。
     *
     * @param bool $inherit 是否繼承外層樣板的變數。
     *                      頁面與 partial 需要（true），共用元件不需要（false）。
     */
    public static function capture(string $view, array $data = [], bool $inherit = true): string
    {
        $file = VIEW_PATH . '/' . ltrim($view, '/') . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("找不到樣板：{$view}（預期路徑 {$file}）");
        }

        $previous     = self::$shared;
        self::$shared = $inherit ? array_merge(self::$shared, $data) : $data;

        ob_start();

        try {
            // 用獨立函式範圍執行，樣板拿不到呼叫端的區域變數
            (static function ($__file, $__data) {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, self::$shared);
        } catch (\Throwable $e) {
            ob_end_clean();
            self::$shared = $previous;
            throw $e;
        }

        $output       = ob_get_clean();
        self::$shared = $previous;

        return $output;
    }

    /**
     * 取共用資料（樣板內部用，避免每個 partial 都要層層傳參數）。
     */
    public static function get(string $key, $default = null)
    {
        return self::$shared[$key] ?? $default;
    }
}
