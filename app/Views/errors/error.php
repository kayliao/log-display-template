<?php
/**
 * 錯誤頁。
 *
 * 刻意做成不依賴任何外部檔案的單頁——
 * 因為錯誤有可能就是「靜態檔載不到」造成的，
 * 這一頁必須在什麼都壞掉的情況下也顯示得出來。
 *
 * 由 ErrorHandler 傳入：$errorStatus、$errorMessage、$traceId
 */

$statusText = [
    403 => '沒有權限',
    404 => '找不到頁面',
    500 => '系統錯誤',
];

$title = $statusText[$errorStatus] ?? '發生問題';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: "Segoe UI", "Microsoft JhengHei", system-ui, sans-serif;
            background: #f6f7f9; color: #1f2933;
        }
        .box {
            max-width: 460px; padding: 40px; text-align: center;
            background: #fff; border-radius: 16px;
            box-shadow: 0 1px 3px rgba(16,24,40,.08), 0 12px 32px rgba(16,24,40,.06);
        }
        .code { font-size: 56px; font-weight: 700; color: #cbd2d9; line-height: 1; }
        h1 { margin: 12px 0 8px; font-size: 20px; }
        p { margin: 0 0 24px; color: #52606d; line-height: 1.7; font-size: 15px; }
        .trace {
            display: inline-block; margin-bottom: 24px; padding: 6px 12px;
            background: #f0f2f5; border-radius: 6px;
            font-family: Consolas, monospace; font-size: 13px; color: #52606d;
        }
        a {
            display: inline-block; padding: 10px 24px; border-radius: 8px;
            background: #2563eb; color: #fff; text-decoration: none; font-size: 15px;
        }
        a:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="box">
        <div class="code"><?= (int) $errorStatus ?></div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <p><?= htmlspecialchars($errorMessage) ?></p>

        <?php if (!empty($traceId) && $errorStatus >= 500): ?>
            <div class="trace">代碼 <?= htmlspecialchars($traceId) ?></div><br>
        <?php endif; ?>

        <a href="<?= htmlspecialchars(\App\Core\Url::to('/')) ?>">回到首頁</a>
    </div>
</body>
</html>
