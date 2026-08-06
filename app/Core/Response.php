<?php

namespace App\Core;

/**
 * 統一的回應輸出。
 *
 * 全系統所有 API（前端 ajax 與對外 API）都用同一個信封格式：
 *
 *   {
 *     "ok": true,
 *     "code": 0,
 *     "message": "",
 *     "data": {...},
 *     "trace_id": "a1b2c3d4e5f6"
 *   }
 *
 * 前端 App.http 只認得這個格式，所以後端不要自己 echo json_encode。
 * trace_id 會跟 storage/logs 裡的記錄對得起來，現場回報問題時報這串就能查。
 */
class Response
{
    /** 成功。分頁資料請用 page() */
    public static function ok($data = null, string $message = ''): void
    {
        self::json([
            'ok'       => true,
            'code'     => 0,
            'message'  => $message,
            'data'     => $data,
            'trace_id' => Logger::traceId(),
        ]);
    }

    /**
     * 分頁成功回應。格式固定，前端表格元件直接吃。
     *
     * @param array $rows  本頁資料
     * @param int   $total 全部筆數（不是本頁筆數）
     */
    public static function page(array $rows, int $total, int $page, int $size, array $extra = []): void
    {
        self::ok(array_merge([
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'pages' => $size > 0 ? (int) ceil($total / $size) : 1,
        ], $extra));
    }

    /**
     * 失敗。message 是要給現場人員看的，請寫人話。
     *
     * @param int $code 業務錯誤碼，0 以外自訂；HTTP 狀態另外給
     */
    public static function fail(string $message, int $status = 400, int $code = 1, $data = null): void
    {
        self::json([
            'ok'       => false,
            'code'     => $code,
            'message'  => $message,
            'data'     => $data,
            'trace_id' => Logger::traceId(),
        ], $status);
    }

    public static function json($payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Trace-Id: ' . Logger::traceId());
            // 查詢結果一律不快取，現場看到舊資料比看不到資料更糟
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $path): void
    {
        if (!headers_sent()) {
            header('Location: ' . (str_starts($path, 'http') ? $path : Url::to($path)));
        }
        exit;
    }

    /**
     * 輸出 CSV（報表匯出用）。加 BOM 讓 Excel 開中文不會亂碼。
     *
     * @param array $columns ['欄位鍵' => '顯示標題']
     */
    public static function csv(string $filename, array $columns, iterable $rows): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            header('Cache-Control: no-store');
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($columns));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }
}
