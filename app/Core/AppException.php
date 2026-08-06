<?php

namespace App\Core;

/**
 * 「可以直接顯示給使用者看」的例外。
 *
 * 和一般 Exception 的差別：
 *   - AppException  => 訊息會原樣顯示給現場人員（例如「查詢區間最多 7 天」）
 *   - 其他 Exception => 只寫進 log，畫面上只顯示通用訊息與 trace_id
 *
 * 所以凡是「使用者操作不當」造成的錯誤都丟這個，
 * 「程式或環境壞掉」的錯誤丟原生例外，不要混用。
 */
class AppException extends \RuntimeException
{
    /** @var int HTTP 狀態碼 */
    protected $status;

    /** @var mixed 附帶資料（例如欄位驗證明細） */
    protected $data;

    public function __construct(string $message, int $status = 400, $data = null, int $code = 1)
    {
        parent::__construct($message, $code);
        $this->status = $status;
        $this->data   = $data;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data()
    {
        return $this->data;
    }

    /** 未登入 */
    public static function unauthenticated(string $message = '登入狀態已失效，請重新登入。'): self
    {
        return new self($message, 401);
    }

    /** 已登入但沒有權限 */
    public static function forbidden(string $message = '您沒有使用這個功能的權限。'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = '找不到指定的資料。'): self
    {
        return new self($message, 404);
    }
}
