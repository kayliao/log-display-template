<?php

namespace App\Core;

/**
 * 檔案上傳。
 *
 * 存在的理由：驗證要在同一個地方做完。
 * 每支匯入 API 各自檢查副檔名與大小，遲早會有一支忘記檢查，
 * 那支就變成整個系統的破口。
 *
 * 驗證順序刻意是「先看 PHP 的錯誤碼，再看大小，最後看副檔名」——
 * 超過 php.ini 的 upload_max_filesize 時 $_FILES 的 tmp_name 是空的，
 * 先驗副檔名會得到一個看不懂的錯誤訊息。
 */
class Upload
{
    /** 允許的副檔名 => 這個副檔名合理的 MIME（瀏覽器給的 MIME 只當參考，不當唯一依據） */
    const TEXT_TYPES = [
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream', ''],
        'txt' => ['text/plain', 'application/octet-stream', ''],
    ];

    /**
     * 接收一個上傳檔並存進 storage/uploads。
     *
     * @param string   $field    表單欄位名
     * @param string[] $allowed  允許的副檔名，例如 ['csv', 'txt']
     * @param int      $maxBytes 大小上限
     * @return array{token:string, path:string, name:string, size:int, ext:string}
     * @throws AppException 驗證不過時（訊息會直接顯示給使用者）
     */
    public static function receive(string $field, array $allowed = ['csv', 'txt'], int $maxBytes = 5242880): array
    {
        $file = $_FILES[$field] ?? null;

        if ($file === null || !isset($file['error'])) {
            throw new AppException('沒有收到檔案，請重新選擇。');
        }

        self::assertNoPhpError((int) $file['error'], $maxBytes);

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            throw new AppException('檔案是空的，請確認內容後再上傳。');
        }

        if ($size > $maxBytes) {
            throw new AppException(sprintf(
                '檔案大小 %s 超過上限 %s。',
                self::formatSize($size),
                self::formatSize($maxBytes)
            ));
        }

        $name = (string) ($file['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            throw new AppException(sprintf(
                '只接受 %s 檔，這個檔案是 .%s。',
                implode(' / ', array_map('strtoupper', $allowed)),
                $ext === '' ? '（沒有副檔名）' : $ext
            ));
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // 不是真的透過 HTTP 上傳上來的，可能是被動過手腳
            Logger::warning('上傳檔案來源可疑', ['field' => $field, 'name' => $name]);
            throw new AppException('檔案上傳失敗，請重試。');
        }

        $dir = self::dir();

        // token 只用來在「預覽」與「確認匯入」兩個步驟之間指認同一個檔案，
        // 不放在網址上、也猜不到，所以用亂數而不是流水號
        $token = bin2hex(random_bytes(16));
        $path  = $dir . '/' . $token . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new AppException('檔案存檔失敗，請聯絡資訊人員。');
        }

        Logger::info('收到上傳檔案', [
            'name'  => $name,
            'size'  => $size,
            'token' => $token,
        ]);

        return [
            'token' => $token,
            'path'  => $path,
            'name'  => $name,
            'size'  => $size,
            'ext'   => $ext,
        ];
    }

    /**
     * 用 token 取回先前上傳的檔案路徑。
     *
     * token 只允許十六進位字元——它會被拼進檔案路徑，
     * 不檢查的話有人送 ../../config/database.php 就把設定檔讀走了。
     */
    public static function pathOf(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new AppException('檔案識別碼不正確，請重新上傳。');
        }

        foreach (['csv', 'txt'] as $ext) {
            $path = self::dir() . '/' . $token . '.' . $ext;

            if (is_file($path)) {
                return $path;
            }
        }

        throw new AppException('找不到上傳的檔案，可能已經逾時被清除，請重新上傳。');
    }

    public static function forget(string $token): void
    {
        try {
            $path = self::pathOf($token);
            @unlink($path);
        } catch (\Throwable $e) {
            // 刪不掉就交給定期清理，不需要讓使用者看到錯誤
        }
    }

    /**
     * 清掉放太久的暫存檔。
     *
     * 使用者上傳完預覽卻沒有按確認，檔案就會留在那裡，
     * 所以每次收檔時順手清一次，不另外開排程。
     */
    public static function cleanup(int $keepMinutes = 120): void
    {
        $deadline = time() - $keepMinutes * 60;

        foreach (glob(self::dir() . '/*') as $path) {
            if (is_file($path) && basename($path) !== '.gitkeep' && filemtime($path) < $deadline) {
                @unlink($path);
            }
        }
    }

    private static function dir(): string
    {
        $dir = BASE_PATH . '/storage/uploads';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * 把 PHP 的上傳錯誤碼翻成使用者看得懂的話。
     */
    private static function assertNoPhpError(int $code, int $maxBytes): void
    {
        if ($code === UPLOAD_ERR_OK) {
            return;
        }

        $messages = [
            UPLOAD_ERR_INI_SIZE   => '檔案超過伺服器允許的大小上限（' . ini_get('upload_max_filesize') . '），請分批上傳。',
            UPLOAD_ERR_FORM_SIZE  => '檔案超過表單允許的大小上限（' . self::formatSize($maxBytes) . '）。',
            UPLOAD_ERR_PARTIAL    => '檔案只上傳了一部分，請重試。',
            UPLOAD_ERR_NO_FILE    => '請先選擇要上傳的檔案。',
            UPLOAD_ERR_NO_TMP_DIR => '伺服器缺少暫存目錄，請聯絡資訊人員。',
            UPLOAD_ERR_CANT_WRITE => '伺服器無法寫入暫存檔，請聯絡資訊人員。',
            UPLOAD_ERR_EXTENSION  => '上傳被伺服器擴充套件擋下，請聯絡資訊人員。',
        ];

        throw new AppException($messages[$code] ?? '檔案上傳失敗（代碼 ' . $code . '）。');
    }

    private static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
