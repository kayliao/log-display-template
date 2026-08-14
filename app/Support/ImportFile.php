<?php

namespace App\Support;

use App\Core\AppException;

/**
 * 匯入檔的入口：看內容決定要用哪個解析器。
 *
 * 各支匯入服務只要呼叫這裡，不用管使用者傳的是 CSV、TXT 還是 XLSX。
 *
 * 判斷依據是**檔案內容**而不是副檔名。現場很習慣直接改副檔名，
 * 靠副檔名判斷的話，把 .xlsx 改成 .csv 傳上來就會走進 CSV 那條路，
 * 讀出一堆看不懂的東西——這正是這個模板先前踩過的坑。
 */
class ImportFile
{
    /**
     * @param string   $path     檔案路徑
     * @param string[] $required 必要欄位
     * @param int      $maxRows  最多幾列
     * @return array{header:string[], rows:array<int,array>, count:int}
     */
    public static function read(string $path, array $required = [], int $maxRows = 5000): array
    {
        if (Xlsx::looksLikeXlsx($path)) {
            return Xlsx::read($path, $required, $maxRows);
        }

        // 是壓縮檔但不是 xlsx。丟給 CSV 解析只會得到「這個檔案讀起來不是文字」，
        // 使用者會以為是編碼問題然後開始換編碼另存，所以這裡直接講清楚。
        if (self::isZip($path)) {
            throw new AppException('這個檔案是壓縮檔，不是可以匯入的表格。如果是 .zip 請先解開，如果是 .xls 舊格式請用 Excel 另存成 .xlsx 或 CSV 再上傳。');
        }

        return Csv::read($path, $required, $maxRows);
    }

    private static function isZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 2);
        fclose($handle);

        return $head === 'PK';
    }
}
