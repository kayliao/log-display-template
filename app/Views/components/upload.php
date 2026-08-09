<?php
/**
 * 檔案上傳匯入。
 *
 *   View::component('upload', [
 *       'id'       => 'machineImport',
 *       'api'      => url('/api/machine/import.php'),
 *       'accept'   => '.csv,.txt',
 *       'maxSize'  => 5,                                          // MB
 *       'template' => url('/api/machine/import.php?action=template'),
 *       'columns'  => MachineImportService::columns(),            // 欄位說明表
 *   ]);
 *
 * 流程固定是兩步：
 *
 *   選檔／拖曳 → 上傳並預覽（後端驗證，還沒寫入）
 *              → 有錯就列出第幾列錯在哪，改完重傳
 *              → 沒錯就按「確認匯入」才真的寫進資料庫
 *
 * 為什麼不一步做完：現場的檔案十次有三次是錯的。一步寫入的話
 * 錯誤發生時資料已經進去一半，要人工回頭清；先預覽的話錯了就重傳，
 * 什麼都沒動到。
 *
 * 後端 API 要支援三個 action：template / preview（預設）/ commit，
 * 回傳格式見 public/api/machine/import.php。
 */

$id       = $id      ?? 'upload';
$accept   = $accept  ?? '.csv,.txt';
$maxSize  = (float) ($maxSize ?? 5);
$columns  = $columns ?? [];
$template = $template ?? '';

$config = [
    'api'     => $api ?? '',
    'accept'  => $accept,
    'maxSize' => $maxSize,
];
?>
<div class="app-upload" id="<?= e($id) ?>"
     data-upload-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <!-- 步驟一：選檔 -->
    <div class="app-upload__drop" data-role="drop">
        <i class="bi bi-cloud-arrow-up app-upload__icon"></i>

        <div class="app-upload__title">把檔案拖到這裡，或</div>

        <label class="btn btn-primary btn-sm app-upload__pick">
            <i class="bi bi-folder2-open"></i> 選擇檔案
            <input type="file" accept="<?= e($accept) ?>" data-role="file" hidden>
        </label>

        <div class="app-upload__hint">
            接受 <?= e(strtoupper(str_replace(['.', ','], ['', '、'], $accept))) ?>，
            單檔最大 <?= e(rtrim(rtrim(number_format($maxSize, 1), '0'), '.')) ?> MB
            <?php if ($template !== ''): ?>
                ．<a href="<?= e($template) ?>"><i class="bi bi-download"></i> 下載範本</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 已選檔案 -->
    <div class="app-upload__file" data-role="file-info" hidden>
        <i class="bi bi-file-earmark-text"></i>
        <span data-role="file-name"></span>
        <span class="app-upload__size" data-role="file-size"></span>
        <button type="button" class="app-upload__remove" data-role="file-remove">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- 步驟二：預覽結果 -->
    <div class="app-upload__result" data-role="result" hidden></div>

    <div class="app-upload__actions" data-role="actions" hidden>
        <button type="button" class="btn btn-primary btn-sm" data-role="commit" disabled>
            <i class="bi bi-check-lg"></i> 確認匯入
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-role="reset">
            <i class="bi bi-arrow-counterclockwise"></i> 重新選擇
        </button>
    </div>

    <?php if ($columns !== []): ?>
        <!--
          欄位說明直接從後端的驗證定義產生。
          另外手寫一份的話，改了驗證規則卻忘記改說明，
          現場就會照著錯的說明填然後一直被擋。
        -->
        <div class="app-upload__spec">
            <h4 class="app-upload__spec-title">檔案格式</h4>
            <table class="app-subtable">
                <thead>
                    <tr>
                        <th style="width:130px">欄位</th>
                        <th style="width:70px">必填</th>
                        <th>規則</th>
                        <th style="width:140px">範例</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($columns as $meta): ?>
                        <tr>
                            <td><?= e($meta['title'] ?? '') ?></td>
                            <td><?= !empty($meta['required']) ? '是' : '' ?></td>
                            <td><?php
                                if (!empty($meta['message'])) {
                                    echo e($meta['message']);
                                } elseif (!empty($meta['in'])) {
                                    echo '只能填 ' . e(implode('、', $meta['in']));
                                } elseif (!empty($meta['max'])) {
                                    echo '最多 ' . (int) $meta['max'] . ' 個字';
                                }
                            ?></td>
                            <td class="app-td--mono"><?= e($meta['sample'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="app-upload__spec-note">
                第一列必須是欄位名稱。Excel 另存的 Big5 檔也讀得進來，不用先轉檔。
            </p>
        </div>
    <?php endif; ?>
</div>
