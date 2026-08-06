<?php

namespace App\Core;

/**
 * 檔案日誌。
 *
 * 依日期切檔寫到 storage/logs/app-YYYY-MM-DD.log，
 * 並在寫入時順手清掉超過保留天數的舊檔（不需要另外排程）。
 *
 * 故意不使用 Monolog：現場無網路、少一個相依就少一個風險，
 * 而這裡需要的功能三十行就寫完了。
 */
class Logger
{
    private const LEVELS = [
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
    ];

    /** 同一個 request 共用的追蹤碼，方便把前端錯誤對回後端 log */
    private static $traceId;

    public static function traceId(): string
    {
        if (self::$traceId === null) {
            self::$traceId = substr(bin2hex(random_bytes(6)), 0, 12);
        }

        return self::$traceId;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * 對外 API 的請求記錄另外寫一個檔，跟系統 log 分開比較好查。
     */
    public static function apiRequest(array $data): void
    {
        self::writeTo('api', 'info', 'service-api', $data);
    }

    private static function write(string $level, string $message, array $context): void
    {
        self::writeTo('app', $level, $message, $context);
    }

    private static function writeTo(string $channel, string $level, string $message, array $context): void
    {
        $minLevel = self::LEVELS[Config::get('app.log.level', 'info')] ?? 20;
        if ((self::LEVELS[$level] ?? 20) < $minLevel) {
            return;
        }

        $dir = Config::get('app.log.path', STORAGE_PATH . '/logs');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // 寫不了 log 也不能讓系統掛掉
        }

        $line = sprintf(
            "[%s] [%s] [%s] [%s] %s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            self::traceId(),
            self::actor(),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        $file = $dir . '/' . $channel . '-' . date('Y-m-d') . '.log';

        // 新檔第一次寫入時加上 UTF-8 BOM。
        // 少了它，現場用記事本或 Excel 開 log 會看到一堆亂碼中文。
        if (!is_file($file)) {
            $line = "\xEF\xBB\xBF" . $line;
        }

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        self::cleanup($dir);
    }

    /** 記錄是誰觸發的，未登入就記 IP */
    private static function actor(): string
    {
        $u = $_SESSION['user'] ?? null;
        if (is_array($u) && isset($u['emp_no'])) {
            return $u['emp_no'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'cli';
    }

    /** 一天最多清一次，避免每次寫 log 都掃目錄 */
    private static function cleanup(string $dir): void
    {
        $keepDays = (int) Config::get('app.log.keep_days', 30);
        if ($keepDays <= 0) {
            return;
        }

        $stamp = $dir . '/.last-cleanup';
        if (is_file($stamp) && date('Y-m-d', (int) @filemtime($stamp)) === date('Y-m-d')) {
            return;
        }
        @touch($stamp);

        $deadline = time() - ($keepDays * 86400);
        foreach (glob($dir . '/*.log') as $file) {
            if (@filemtime($file) < $deadline) {
                @unlink($file);
            }
        }
    }
}
