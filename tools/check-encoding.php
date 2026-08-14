<?php

/**
 * 檢查一個匯入檔到底是什麼編碼，以及 Csv::read() 會怎麼看它。
 *
 * 匯入失敗時「檔案看起來是好的」是最難處理的情況：記事本開起來正常、
 * Excel 開起來也正常，但系統就是讀不到欄位。這支工具把中間發生的事攤開，
 * 不必再靠猜。
 *
 * 用法（PHP 不在 PATH 的話要寫完整路徑）：
 *   php tools/check-encoding.php "C:\path\to\匯入檔.csv"
 */

require __DIR__ . '/../app/autoload.php';

use App\Support\Csv;

$path = $argv[1] ?? '';

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "用法：php tools/check-encoding.php <檔案路徑>\n");
    exit(1);
}

$raw  = file_get_contents($path);
$size = strlen($raw);

echo "檔案　　：{$path}\n";
echo '大小　　：' . number_format($size) . " 位元組\n";

// ---- 開頭位元組與 BOM ---------------------------------------------------
$head = substr($raw, 0, 16);
echo '開頭位元組：' . strtoupper(implode(' ', str_split(bin2hex($head), 2))) . "\n";

$boms = [
    "\xEF\xBB\xBF" => 'UTF-8 BOM',
    "\xFF\xFE"     => 'UTF-16 LE BOM',
    "\xFE\xFF"     => 'UTF-16 BE BOM',
];

$bom = '（沒有 BOM）';
foreach ($boms as $bytes => $label) {
    if (strncmp($raw, $bytes, strlen($bytes)) === 0) {
        $bom = $label;
        break;
    }
}
echo "BOM　　 ：{$bom}\n";

// ---- 空位元組 -----------------------------------------------------------
$nulls = substr_count($raw, "\0");
echo '空位元組：' . ($nulls === 0
    ? '沒有（是純文字檔）'
    : number_format($nulls) . ' 個 → 不是 UTF-8 也不是 Big5，只可能是 UTF-16 或二進位檔') . "\n";

// ---- 各種編碼假設下的樣子 -----------------------------------------------
echo "\n--- 用不同編碼去讀第一行，哪個看起來對就是哪個 ---\n";

$firstLineOf = function ($text) {
    $line = strtok(str_replace("\0", '', $text), "\r\n");
    return $line === false ? '' : mb_substr($line, 0, 60, 'UTF-8');
};

$guesses = [
    'UTF-8'      => $raw,
    'Big5/CP950' => @mb_convert_encoding($raw, 'UTF-8', 'CP950'),
    'UTF-16 LE'  => @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'),
    'UTF-16 BE'  => @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE'),
];

foreach ($guesses as $label => $text) {
    if ($text === false || $text === '') {
        printf("  %-11s （轉換失敗）\n", $label);
        continue;
    }

    $line  = $firstLineOf($text);
    $valid = mb_check_encoding($text, 'UTF-8') ? '' : '  ← 轉出來不是合法 UTF-8';
    printf("  %-11s %s%s\n", $label, $line, $valid);
}

// ---- Csv::read() 實際的結果 ---------------------------------------------
echo "\n--- Csv::read() 實際會怎麼處理 ---\n";

try {
    $file = Csv::read($path);
    echo '  讀到的欄位：' . implode(' | ', $file['header']) . "\n";
    echo '  資料列數　：' . number_format($file['count']) . "\n";

    // 欄位名裡混進看不見的東西時，光看畫面是看不出來的
    foreach ($file['header'] as $name) {
        if (preg_match('/[^\P{C}\t]/u', $name)) {
            echo "  ⚠ 欄位「{$name}」裡面有看不見的控制字元："
                . strtoupper(implode(' ', str_split(bin2hex($name), 2))) . "\n";
        }
    }
} catch (Throwable $e) {
    echo '  被擋下：' . $e->getMessage() . "\n";
}
