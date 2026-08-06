<?php

namespace App\Core;

/**
 * 全域錯誤處理。
 *
 * 目標：現場永遠不會看到 PHP 的白畫面或一整串路徑外洩的錯誤訊息。
 * 不論錯誤發生在哪一層，最後都會變成：
 *   - 頁面請求 => 一頁乾淨的錯誤畫面，附 trace_id
 *   - API 請求 => 標準 JSON 錯誤信封，附 trace_id
 * 而完整的錯誤內容一律寫進 storage/logs。
 */
class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** 把 PHP warning/notice 轉成例外，避免被無聲忽略 */
    public static function handleError($severity, $message, $file = '', $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(\Throwable $e): void
    {
        // 使用者操作造成的錯誤：只記 info，訊息原樣顯示
        if ($e instanceof AppException) {
            Logger::info('使用者操作錯誤：' . $e->getMessage(), [
                'status' => $e->status(),
                'path'   => $_SERVER['REQUEST_URI'] ?? '',
            ]);

            self::render($e->getMessage(), $e->status(), $e->data());

            return;
        }

        // 程式/環境錯誤：完整寫進 log，畫面只給通用訊息
        Logger::error($e->getMessage(), [
            'type'  => get_class($e),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'path'  => $_SERVER['REQUEST_URI'] ?? '',
            'trace' => explode("\n", $e->getTraceAsString()),
        ]);

        $message = Config::get('app.debug')
            ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            : '系統發生錯誤，請聯絡資訊人員並提供代碼 ' . Logger::traceId() . '。';

        self::render($message, 500);
    }

    /** 抓致命錯誤（例如記憶體不足、parse error） */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        Logger::error('致命錯誤：' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        if (!headers_sent()) {
            self::render('系統發生嚴重錯誤，請聯絡資訊人員並提供代碼 ' . Logger::traceId() . '。', 500);
        }
    }

    /**
     * 依請求型態決定輸出 JSON 還是 HTML 錯誤頁。
     */
    private static function render(string $message, int $status, $data = null): void
    {
        if (self::wantsJson()) {
            Response::fail($message, $status, 1, $data);

            return;
        }

        // 401 且是頁面請求 => 直接導回登入頁比顯示錯誤有用
        if ($status === 401) {
            Response::redirect('/login.php?expired=1');

            return;
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }

        $view = VIEW_PATH . '/errors/error.php';
        if (is_file($view)) {
            $errorStatus  = $status;
            $errorMessage = $message;
            $traceId      = Logger::traceId();
            require $view;
        } else {
            echo '<meta charset="utf-8"><h1>' . htmlspecialchars($message) . '</h1>';
        }

        exit;
    }

    private static function wantsJson(): bool
    {
        // API 入口會宣告這個常數，最準確
        if (defined('APP_API_ENTRY')) {
            return true;
        }

        $path = $_SERVER['REQUEST_URI'] ?? '';

        return Request::isAjax()
            || strpos($path, '/api/') !== false
            || strpos($path, '/service/') !== false;
    }
}
