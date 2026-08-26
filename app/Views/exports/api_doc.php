<?php
/**
 * 對外 API 說明書（匯出用的單頁 HTML）。
 *
 * 這一份是要寄給**沒有本系統帳號的人**看的（機台廠商、MES 廠商的工程師），
 * 所以有三個限制，改的時候請一起顧到：
 *
 *   1. 不可以連到本系統的任何檔案。CSS 直接寫在 <style> 裡，
 *      不用 app.css、不用 Bootstrap Icons、不用任何圖片——
 *      對方那台電腦連不到我們的內網，外連的東西一律會變成破圖或沒有樣式。
 *   2. 不要有 JavaScript。有些公司的信件閘道會擋帶 script 的 HTML 附件。
 *   3. 要能列印。最下面那段 @media print 就是為了讓對方按 Ctrl+P 存成 PDF
 *      時不會被切得亂七八糟。
 *
 * 內容跟畫面版（app/Views/pages/dev/api_docs.php）讀的是同一份
 * config/api_docs.php，只有版面不一樣。
 *
 * 變數：
 *   $meta       ['title', 'system', 'server', 'contact', 'exported_at']
 *   $common     共通規則區塊
 *   $endpoints  這次要匯出的端點
 */

use App\Support\ApiDoc;

/** 匯出檔專用的欄位表 */
$fieldTable = function (array $fields, bool $withRequired) {
    echo '<table><thead><tr><th class="w-name">欄位</th><th class="w-type">型別</th>';

    if ($withRequired) {
        echo '<th class="w-req">必填</th>';
    }

    echo '<th class="w-ex">範例</th><th>說明</th></tr></thead><tbody>';

    foreach ($fields as $field) {
        echo '<tr>';
        echo '<td><code>' . e($field['name'] ?? '') . '</code></td>';
        echo '<td class="soft">' . e($field['type'] ?? '') . '</td>';

        if ($withRequired) {
            echo !empty($field['required'])
                ? '<td><b class="req">必填</b></td>'
                : '<td class="soft">選填</td>';
        }

        echo '<td><code>' . e((string) ($field['example'] ?? '')) . '</code></td>';
        echo '<td>' . ApiDoc::text((string) ($field['desc'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
};
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($meta['title']) ?> - <?= e($meta['system']) ?></title>
<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 32px 20px 64px;
    background: #f4f6f8;
    color: #1f2937;
    font-family: "Noto Sans TC", "Microsoft JhengHei", "PingFang TC", -apple-system,
                 "Segoe UI", system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.75;
}

.wrap { max-width: 960px; margin: 0 auto; }

.card {
    background: #fff;
    border: 1px solid #e3e8ee;
    border-radius: 10px;
    padding: 22px 26px;
    margin-bottom: 18px;
}

h1 { margin: 0 0 6px; font-size: 22px; }
h2 { margin: 0; font-size: 17px; }
h3 { margin: 26px 0 10px; font-size: 14.5px; color: #334155;
     border-left: 3px solid #2563eb; padding-left: 8px; }
p  { margin: 0 0 10px; }
p:last-child { margin-bottom: 0; }

.cover-meta { margin-top: 14px; border-top: 1px solid #eef2f6; padding-top: 12px;
              font-size: 13px; color: #64748b; }
.cover-meta div { margin-bottom: 3px; }
.cover-meta b { color: #1f2937; font-weight: 600; }

.toc { margin: 0; padding-left: 20px; font-size: 13.5px; }
.toc li { margin-bottom: 4px; }

.ep-head {
    display: flex; align-items: center; flex-wrap: wrap; gap: 10px;
    border-bottom: 1px solid #e3e8ee;
    margin: -22px -26px 18px; padding: 16px 26px;
    background: #fbfcfd; border-radius: 10px 10px 0 0;
}

.method {
    display: inline-block; padding: 2px 9px; border-radius: 5px;
    background: #2563eb; color: #fff;
    font-size: 11.5px; font-weight: 700; letter-spacing: .5px;
}

.path { font-family: Consolas, "Courier New", monospace; font-size: 13px; color: #334155; }

.meta { display: flex; flex-wrap: wrap; gap: 8px 26px; margin: 12px 0 14px;
        font-size: 13px; color: #475569; }
.meta span.k { color: #94a3b8; margin-right: 6px; }

pre {
    margin: 0 0 14px;
    padding: 13px 16px;
    background: #1e293b;
    color: #e2e8f0;
    border-radius: 8px;
    font-family: Consolas, "Courier New", monospace;
    font-size: 12px;
    line-height: 1.65;
    overflow-x: auto;
    white-space: pre-wrap;      /* 列印時長行要能折，不然右邊會被切掉 */
    word-break: break-all;
}

.code-label { font-size: 12px; color: #64748b; margin: 0 0 5px; }

table { width: 100%; border-collapse: collapse; margin: 0 0 16px; font-size: 13px; }
th, td { border: 1px solid #e3e8ee; padding: 7px 10px; text-align: left; vertical-align: top; }
th { background: #f8fafc; font-weight: 600; color: #334155; white-space: nowrap; }
/* 範例欄留寬一點：時間格式（2026-08-17 13:45:00）擠不下就會斷在秒的中間，很難看 */
.w-name { width: 21%; } .w-type { width: 11%; } .w-req { width: 7%; } .w-ex { width: 21%; }
.soft { color: #64748b; }
.req  { color: #dc2626; }

code { font-family: Consolas, "Courier New", monospace; font-size: 12.5px;
       background: #f1f5f9; padding: 1px 5px; border-radius: 4px; word-break: break-all; }
td code { background: none; padding: 0; }

.status { display: inline-block; min-width: 34px; text-align: center;
          padding: 1px 6px; border-radius: 4px; font-weight: 700; font-size: 12px; }
.status-ok   { background: #dcfce7; color: #15803d; }
.status-warn { background: #fef3c7; color: #b45309; }
.status-err  { background: #fee2e2; color: #b91c1c; }

.notes { margin: 0; padding-left: 20px; }
.notes li { margin-bottom: 6px; }

.foot { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 26px; }

@media print {
    body { background: #fff; padding: 0; font-size: 12px; }
    .card { border: 0; border-bottom: 1px solid #ddd; border-radius: 0;
            padding: 0 0 14px; margin-bottom: 18px; }
    .ep-head { margin: 0 0 12px; padding: 0 0 8px; background: none; }
    .ep { page-break-inside: avoid; }
    pre { background: #f6f8fa; color: #24292f; border: 1px solid #ddd; }
}
</style>
</head>
<body>
<div class="wrap">

    <div class="card">
        <h1><?= e($meta['title']) ?></h1>
        <p class="soft"><?= e($meta['system']) ?></p>

        <div class="cover-meta">
            <div><b>系統網址</b>　<code><?= e($meta['server']) ?></code></div>
            <div><b>本份包含</b>　<?= count($endpoints) ?> 支 API</div>
            <div><b>匯出時間</b>　<?= e($meta['exported_at']) ?></div>
            <?php if ($meta['contact'] !== ''): ?>
                <div><b>聯絡窗口</b>　<?= e($meta['contact']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>本份內容</h2>
        <ol class="toc">
            <?php foreach ($endpoints as $endpoint): ?>
                <li>
                    <b><?= e($endpoint['title'] ?? '') ?></b>
                    <code><?= e($endpoint['method'] ?? '') ?> <?= e($endpoint['path'] ?? '') ?></code>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <?php // ── 共通規則 ── ?>
    <?php foreach ($common as $section): ?>
        <div class="card">
            <h2><?= e($section['title'] ?? '') ?></h2>
            <div style="margin-top:10px">
                <?php foreach ($section['body'] ?? [] as $paragraph): ?>
                    <p><?= ApiDoc::text((string) $paragraph) ?></p>
                <?php endforeach; ?>
                <?php if (!empty($section['code'])): ?>
                    <pre style="margin-top:12px"><?= e($section['code']) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php // ── 各支端點 ── ?>
    <?php // 陣列的 key 是端點代號（字串），章節編號要另外數 ?>
    <?php $no = 0; ?>
    <?php foreach ($endpoints as $endpoint): $no++; ?>
        <div class="card ep">
            <div class="ep-head">
                <span class="method"><?= e($endpoint['method'] ?? 'POST') ?></span>
                <span class="path"><?= e($endpoint['path'] ?? '') ?></span>
                <h2><?= $no ?>. <?= e($endpoint['title'] ?? '') ?></h2>
            </div>

            <p><?= ApiDoc::text((string) ($endpoint['summary'] ?? '')) ?></p>

            <div class="meta">
                <div><span class="k">呼叫端</span><?= e($endpoint['caller'] ?? '—') ?></div>
                <div><span class="k">驗證</span><code>X-Api-Key</code> 標頭</div>
                <?php if (!empty($endpoint['batch']['max'])): ?>
                    <div><span class="k">單次上限</span><?= (int) $endpoint['batch']['max'] ?> 筆</div>
                <?php endif; ?>
            </div>

            <?php if (!empty($endpoint['batch']['note'])): ?>
                <p class="soft"><?= ApiDoc::text((string) $endpoint['batch']['note']) ?></p>
            <?php endif; ?>

            <h3>請求欄位</h3>
            <?php $fieldTable($endpoint['fields'] ?? [], true); ?>

            <h3>請求範例</h3>
            <p class="code-label">單筆</p>
            <pre><?= e(ApiDoc::json($endpoint['request'] ?? [])) ?></pre>

            <?php if (!empty($endpoint['request_multi'])): ?>
                <p class="code-label">多筆</p>
                <pre><?= e(ApiDoc::json($endpoint['request_multi'])) ?></pre>
            <?php endif; ?>

            <p class="code-label">curl（金鑰換成我們給你的那一把就能試打）</p>
            <pre><?= e(ApiDoc::curl($endpoint)) ?></pre>

            <h3>回應範例</h3>
            <pre><?= e(ApiDoc::json($endpoint['response'] ?? [])) ?></pre>

            <?php if (!empty($endpoint['response_fields'])): ?>
                <?php $fieldTable($endpoint['response_fields'], false); ?>
            <?php endif; ?>

            <h3>HTTP 狀態碼</h3>
            <table>
                <thead><tr><th class="w-req">狀態</th><th>意思</th></tr></thead>
                <tbody>
                    <?php foreach ($endpoint['status'] ?? [] as $code => $meaning): ?>
                        <tr>
                            <td>
                                <span class="status status-<?= (int) $code < 300 ? 'ok' : ((int) $code < 500 ? 'warn' : 'err') ?>">
                                    <?= e($code) ?>
                                </span>
                            </td>
                            <td><?= ApiDoc::text((string) $meaning) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!empty($endpoint['notes'])): ?>
                <h3>注意事項</h3>
                <ul class="notes">
                    <?php foreach ($endpoint['notes'] as $note): ?>
                        <li><?= ApiDoc::text((string) $note) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <p class="foot">
        <?= e($meta['system']) ?>　·　由系統於 <?= e($meta['exported_at']) ?> 產生<br>
        內容如與實際行為不符，請聯絡本系統資訊人員，不要自行推測。
    </p>

</div>
</body>
</html>
