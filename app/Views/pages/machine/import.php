<?php
/**
 * 機台清單匯入畫面。
 *
 * 左邊上傳、右邊是「這功能會做什麼」的說明——
 * 匯入是會改到資料的操作，現場需要先看懂再按。
 */

use App\Core\View;
?>
<div class="app-container">

    <?php
    View::component('split', [
        'ratio'  => '2-1',
        'sticky' => 1,

        'left' => View::componentHtml('panel', [
            'title'   => '上傳檔案',
            'icon'    => 'cloud-arrow-up',
            'content' => View::componentHtml('upload', [
                'id'       => 'machineImport',
                'api'      => url('/api/machine/import.php'),
                'accept'   => '.csv,.txt',
                'maxSize'  => 5,
                'template' => url('/api/machine/import.php?action=template'),
                'columns'  => $columns,
            ]),
        ]),

        'right' => View::componentHtml('panel', [
            'title'   => '說明',
            'icon'    => 'info-circle',
            'content' => View::capture('pages/machine/_import_note'),
        ]),
    ]);
    ?>

</div>
