<?php

namespace App\Support;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Url;

/**
 * 對外 API 說明書的資料來源與格式化。
 *
 * 內容全部來自 config/api_docs.php，這裡只負責：
 *   - 挑出要顯示／匯出的那幾支（select）
 *   - 把範例陣列轉成好看的 JSON（json）
 *   - 產生可以直接貼進終端機的 curl（curl）
 *   - 把說明文字逸出後套上粗體（text）
 *
 * 畫面（app/Views/pages/dev/api_docs.php）與匯出檔（app/Views/exports/api_doc.php）
 * 都用這一支，兩邊的內容才不會有一天開始不一樣。
 */
class ApiDoc
{
    /**
     * 全部端點，key 會被塞進每一筆裡（畫面上的錨點與匯出參數都用它）。
     */
    public static function all(): array
    {
        $endpoints = Config::get('api_docs.endpoints', []);
        $result    = [];

        foreach ($endpoints as $key => $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }

            $endpoint['key'] = (string) $key;
            $result[$key]    = $endpoint;
        }

        return $result;
    }

    /**
     * 挑出指定的幾支，順序一律照設定檔（不是照使用者勾選的順序），
     * 這樣同一份說明書給不同人看，章節順序都一樣。
     *
     * @param string[] $keys 空陣列 = 全部
     * @throws AppException 有指定但一支都對不到時
     */
    public static function select(array $keys): array
    {
        $all = self::all();

        if ($keys === []) {
            return $all;
        }

        $selected = [];

        foreach ($all as $key => $endpoint) {
            if (in_array((string) $key, $keys, true)) {
                $selected[$key] = $endpoint;
            }
        }

        if ($selected === []) {
            throw new AppException('沒有這幾支 API：' . implode('、', $keys) . '。請回上一頁重新勾選。');
        }

        return $selected;
    }

    /**
     * 給廠商看的系統網址（結尾不含斜線）。
     *
     * 設定檔沒填就從目前這一次請求推算。推算出來的在自己機器上看是對的，
     * 但匯出給廠商之前務必把現場網址填進 config/api_docs.php，
     * 否則對方會拿到一份 localhost 的說明書。
     */
    public static function server(): string
    {
        $configured = trim((string) Config::get('api_docs.server', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https  = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . Url::base();
    }

    /**
     * 某一支端點的完整網址。
     */
    public static function fullUrl(array $endpoint): string
    {
        return self::server() . '/' . ltrim((string) ($endpoint['path'] ?? ''), '/');
    }

    /**
     * 範例陣列 => 排版過的 JSON。
     *
     * 中文不轉 \uXXXX、斜線不加反斜線，貼到 Postman 或機台程式裡才是原樣。
     */
    public static function json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 可以直接貼進終端機的 curl。
     *
     * 刻意寫成一行不換行：換行要用續行符號，而 Linux 是 \、Windows 命令提示字元
     * 是 ^，一份說明書沒辦法同時對兩邊。一行雖然長，但兩邊都貼得動。
     * （JSON 用單引號包起來，這是 bash / PowerShell 的寫法；
     * 舊版 cmd.exe 要把單引號改成跳脫過的雙引號。）
     */
    public static function curl(array $endpoint): string
    {
        $body = json_encode(
            $endpoint['request'] ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return 'curl -X ' . strtoupper((string) ($endpoint['method'] ?? 'POST'))
            . ' "' . self::fullUrl($endpoint) . '"'
            . ' -H "Content-Type: application/json"'
            . ' -H "X-Api-Key: <你的金鑰>"'
            . " -d '" . $body . "'";
    }

    /**
     * 說明文字 => 可以塞進 HTML 的字串。
     *
     * 先逸出再處理粗體，順序不能反——反過來的話設定檔裡的 <script> 會活著出去。
     * 只認 **粗體** 這一種記號：設定檔是給接手的人改的，不該要求他們寫 HTML，
     * 也不該讓他們有機會在說明文字裡寫壞版面。
     */
    public static function text(string $raw): string
    {
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($raw));
    }

    /**
     * 匯出檔名。
     *
     * 純英數字：檔名帶中文的話，Content-Disposition 要處理編碼，
     * 而現場的瀏覽器版本不一定吃得動，最後會下載到一堆亂碼檔名。
     * 只挑一支時把代號放進檔名，一次匯好幾份才分得出誰是誰。
     */
    public static function filename(array $selected): string
    {
        $keys = array_keys($selected);

        $suffix = count($keys) === 1
            ? str_replace('_', '-', (string) $keys[0])
            : 'all';

        return 'service-api-doc-' . $suffix . '-' . date('Ymd') . '.html';
    }
}
