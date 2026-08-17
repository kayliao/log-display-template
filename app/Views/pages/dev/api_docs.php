<?php
/**
 * 對外 API 說明書（畫面）。
 *
 * 內容全部來自 config/api_docs.php，這一頁只負責排版：
 * 左邊是端點索引與匯出面板，右邊是共通規則與每一支端點的細節。
 *
 * 刻意不做 Swagger 的「Try it out」：這裡的端點都會寫進資料庫，
 * 頁面上按一下就真的塞資料進去太危險，改成「複製 curl / 複製範例」，
 * 要試打請自己貼到 Postman 或終端機，看得到自己送了什麼。
 *
 * 匯出檔的版面在 app/Views/exports/api_doc.php，兩邊都讀同一份設定。
 */

use App\Support\ApiDoc;

$endpoints = ApiDoc::all();
$common    = config('api_docs.common', []);
$exportUrl = url('/api/dev/api_doc_export.php');

/**
 * 程式碼區塊 + 右上角的複製按鈕。
 * 複製的是 <pre> 裡的原始文字，所以按鈕不必知道內容是 JSON 還是 curl。
 */
$codeBlock = function (string $label, string $content) {
    echo '<div class="api-code">';
    echo '<div class="api-code__head">';
    echo '<span class="api-code__label">' . e($label) . '</span>';
    echo '<button type="button" class="api-code__copy" data-copy>'
       . '<i class="bi bi-clipboard"></i><span>複製</span></button>';
    echo '</div>';
    echo '<pre class="api-code__body"><code>' . e($content) . '</code></pre>';
    echo '</div>';
};

/**
 * 欄位表（請求欄位與回應欄位共用）。
 */
$fieldTable = function (array $fields, bool $withRequired) {
    echo '<table class="api-table">';
    echo '<thead><tr>';
    echo '<th style="width:22%">欄位</th><th style="width:12%">型別</th>';

    if ($withRequired) {
        echo '<th style="width:8%">必填</th>';
    }

    echo '<th style="width:16%">範例</th><th>說明</th>';
    echo '</tr></thead><tbody>';

    foreach ($fields as $field) {
        echo '<tr>';
        echo '<td><code>' . e($field['name'] ?? '') . '</code></td>';
        echo '<td class="api-table__type">' . e($field['type'] ?? '') . '</td>';

        if ($withRequired) {
            echo !empty($field['required'])
                ? '<td><span class="api-req">必填</span></td>'
                : '<td class="api-table__opt">選填</td>';
        }

        echo '<td><code>' . e((string) ($field['example'] ?? '')) . '</code></td>';
        echo '<td>' . ApiDoc::text((string) ($field['desc'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
};
?>
<div class="app-container app-container--wide">
    <div class="api-doc">

        <!-- ============ 左欄：索引與匯出 ============ -->
        <aside class="api-doc__side">

            <div class="app-panel api-side-block">
                <div class="app-panel__head">
                    <h3 class="app-panel__title"><i class="bi bi-list-ul"></i><span>端點</span></h3>
                </div>
                <div class="app-panel__body">
                    <nav class="api-nav">
                        <a class="api-nav__link" href="#api-common">
                            <span class="api-nav__title">共通規則</span>
                        </a>
                        <?php foreach ($endpoints as $key => $endpoint): ?>
                            <a class="api-nav__link" href="#ep-<?= e($key) ?>">
                                <span class="api-method api-method--<?= e(strtolower($endpoint['method'] ?? 'post')) ?>">
                                    <?= e($endpoint['method'] ?? 'POST') ?>
                                </span>
                                <span class="api-nav__title"><?= e($endpoint['title'] ?? $key) ?></span>
                                <span class="api-nav__path"><?= e($endpoint['path'] ?? '') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>

            <?php
            /**
             * 匯出面板。
             *
             * 用最普通的 GET 表單：勾選的 keys[] 直接變成網址參數，
             * 按下去就是一次瀏覽器下載，不需要 ajax，JS 壞了也還是能用。
             */
            ?>
            <div class="app-panel api-side-block">
                <div class="app-panel__head">
                    <h3 class="app-panel__title"><i class="bi bi-download"></i><span>匯出說明書</span></h3>
                </div>
                <div class="app-panel__body">
                    <p class="api-export__note">
                        產生一個單頁 HTML 檔，樣式都在檔案裡，
                        <strong>不需要網路也不需要本系統帳號</strong>。
                        寄給廠商雙擊就能看，要 PDF 就用瀏覽器列印。
                    </p>

                    <form class="api-export" method="get" action="<?= e($exportUrl) ?>" data-api-export>
                        <label class="api-export__all">
                            <input type="checkbox" data-export-toggle checked>
                            <span>全選</span>
                        </label>

                        <?php foreach ($endpoints as $key => $endpoint): ?>
                            <label class="api-export__item">
                                <input type="checkbox" name="keys[]" value="<?= e($key) ?>" checked>
                                <span><?= e($endpoint['title'] ?? $key) ?></span>
                            </label>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary api-export__btn">
                            <i class="bi bi-file-earmark-arrow-down"></i> 匯出勾選的
                        </button>
                    </form>

                    <p class="api-export__hint">
                        匯出前請確認 <code>config/api_docs.php</code> 的 <code>server</code>
                        填的是現場網址 —— 沒填會用你現在瀏覽的網址，
                        對方拿到的可能是打不通的 localhost。
                    </p>
                </div>
            </div>
        </aside>

        <!-- ============ 右欄：說明內容 ============ -->
        <div class="api-doc__main">

            <div class="app-panel api-intro" id="api-common">
                <div class="app-panel__body">
                    <h2 class="api-intro__title"><?= e(config('api_docs.title', '對外 API 介接說明')) ?></h2>
                    <p class="api-intro__text">
                        以下端點是給<strong>別的系統</strong>呼叫的，跟本系統的頁面完全分開：
                        不吃 Session、不吃 Cookie，用 API 金鑰驗證，每一次呼叫都會留下記錄。
                        目前共 <?= count($endpoints) ?> 支。
                    </p>
                    <div class="api-intro__server">
                        <span>系統網址</span>
                        <code><?= e(ApiDoc::server()) ?></code>
                    </div>
                </div>
            </div>

            <?php foreach ($common as $section): ?>
                <div class="app-panel api-common">
                    <div class="app-panel__head">
                        <h3 class="app-panel__title">
                            <i class="bi bi-info-circle"></i><span><?= e($section['title'] ?? '') ?></span>
                        </h3>
                    </div>
                    <div class="app-panel__body">
                        <?php foreach ($section['body'] ?? [] as $paragraph): ?>
                            <p class="api-p"><?= ApiDoc::text((string) $paragraph) ?></p>
                        <?php endforeach; ?>

                        <?php if (!empty($section['code'])): ?>
                            <pre class="api-code__body api-code__body--plain"><code><?= e($section['code']) ?></code></pre>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($endpoints as $key => $endpoint): ?>
                <section class="app-panel api-ep" id="ep-<?= e($key) ?>">

                    <div class="api-ep__head">
                        <span class="api-method api-method--<?= e(strtolower($endpoint['method'] ?? 'post')) ?>">
                            <?= e($endpoint['method'] ?? 'POST') ?>
                        </span>
                        <code class="api-ep__path"><?= e($endpoint['path'] ?? '') ?></code>
                        <h2 class="api-ep__title"><?= e($endpoint['title'] ?? $key) ?></h2>
                    </div>

                    <div class="app-panel__body">
                        <p class="api-p"><?= ApiDoc::text((string) ($endpoint['summary'] ?? '')) ?></p>

                        <div class="api-meta">
                            <div class="api-meta__item">
                                <span class="api-meta__label">呼叫端</span>
                                <span><?= e($endpoint['caller'] ?? '—') ?></span>
                            </div>
                            <div class="api-meta__item">
                                <span class="api-meta__label">驗證</span>
                                <span><code>X-Api-Key</code> 標頭</span>
                            </div>
                            <?php if (!empty($endpoint['batch']['max'])): ?>
                                <div class="api-meta__item">
                                    <span class="api-meta__label">單次上限</span>
                                    <span><?= (int) $endpoint['batch']['max'] ?> 筆</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($endpoint['batch']['note'])): ?>
                            <p class="api-p api-p--soft"><?= ApiDoc::text((string) $endpoint['batch']['note']) ?></p>
                        <?php endif; ?>

                        <h3 class="api-h3">請求欄位</h3>
                        <?php $fieldTable($endpoint['fields'] ?? [], true); ?>

                        <h3 class="api-h3">請求範例</h3>
                        <?php
                        $codeBlock('單筆', ApiDoc::json($endpoint['request'] ?? []));

                        if (!empty($endpoint['request_multi'])) {
                            $codeBlock('多筆', ApiDoc::json($endpoint['request_multi']));
                        }

                        $codeBlock('curl（貼到終端機就能試）', ApiDoc::curl($endpoint));
                        ?>

                        <h3 class="api-h3">回應範例</h3>
                        <?php $codeBlock('成功時', ApiDoc::json($endpoint['response'] ?? [])); ?>

                        <?php if (!empty($endpoint['response_fields'])): ?>
                            <?php $fieldTable($endpoint['response_fields'], false); ?>
                        <?php endif; ?>

                        <h3 class="api-h3">HTTP 狀態碼</h3>
                        <table class="api-table">
                            <thead>
                                <tr><th style="width:12%">狀態</th><th>意思</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($endpoint['status'] ?? [] as $code => $meaning): ?>
                                    <tr>
                                        <td>
                                            <span class="api-status api-status--<?= (int) $code < 300 ? 'ok' : ((int) $code < 500 ? 'warn' : 'err') ?>">
                                                <?= e($code) ?>
                                            </span>
                                        </td>
                                        <td><?= ApiDoc::text((string) $meaning) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (!empty($endpoint['notes'])): ?>
                            <h3 class="api-h3">注意事項</h3>
                            <ul class="api-notes">
                                <?php foreach ($endpoint['notes'] as $note): ?>
                                    <li><?= ApiDoc::text((string) $note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>

        </div>
    </div>
</div>
