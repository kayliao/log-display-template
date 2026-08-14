<?php

namespace App\Support;

use App\Core\AppException;

/**
 * XLSX 解析。
 *
 * 為什麼自己寫而不用 PhpSpreadsheet：
 *   專案鎖 PHP 7.2，PhpSpreadsheet 只能停在 1.17.x（1.18 之後要 7.3+），
 *   那是早就沒有安全更新的分支，而它處理的正好是「使用者上傳的檔案」。
 *   加上 vendor 會膨脹十幾 MB，對一個要複製到現場的模板來說不划算。
 *   這裡只需要「讀第一張工作表的儲存格」，用內建的 ZipArchive + XMLReader
 *   就夠了，零相依。
 *
 * 為什麼值得支援 XLSX：它把編碼問題整個消滅掉。xlsx 是 ZIP 包著 UTF-8 的 XML，
 * 沒有 Big5／UTF-16／BOM／分隔符號可以猜錯，使用者也不必「另存新檔」——
 * 而那一步正是檔案被改壞的地方。日期也是型別化的值，不再是「儲存格當下顯示
 * 的樣子」。
 *
 * 只做 .xlsx。舊的 .xls 是 BIFF 二進位格式，自己解析划不來，維持原本那句
 * 「請另存成 CSV」的錯誤訊息。
 */
class Xlsx
{
    /** 解壓後的單一檔案上限。xlsx 是壓縮檔，壓縮比可以很誇張，不設限等著被塞爆記憶體 */
    const MAX_ENTRY_BYTES = 41943040; // 40 MB

    /** 壓縮檔裡的項目數上限，正常的 xlsx 不會有幾千個 */
    const MAX_ENTRIES = 512;

    /**
     * 讀第一張工作表，轉成跟 Csv::read() 一樣的結構。
     *
     * @param string   $path     檔案路徑
     * @param string[] $required 必要欄位
     * @param int      $maxRows  最多幾列
     * @return array{header:string[], rows:array<int,array>, count:int}
     */
    public static function read(string $path, array $required = [], int $maxRows = 5000): array
    {
        $zip = self::open($path);

        try {
            $shared     = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            $date1904   = self::isDate1904($zip);
            $sheetXml   = self::entry($zip, self::firstSheetPath($zip));
        } finally {
            $zip->close();
        }

        // 多讀一列，這樣「剛好超過上限」也能算出正確的列數給使用者看
        $lines = self::sheetRows($sheetXml, $shared, $dateStyles, $date1904, $maxRows + 2);

        return Table::fromLines($lines, $required, $maxRows);
    }

    /**
     * 這個檔案是不是 xlsx。
     *
     * 看的是內容不是副檔名：現場很習慣直接改副檔名，靠 .xlsx 判斷會漏。
     * ZIP 的開頭是 PK\x03\x04（空的 ZIP 是 PK\x05\x06）。
     */
    public static function looksLikeXlsx(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 4);
        fclose($handle);

        if ($head !== "PK\x03\x04") {
            return false;
        }

        // ZIP 不一定是 xlsx（.docx、.jar、隨便一個壓縮檔都是 ZIP），
        // 所以再確認裡面有沒有 xlsx 的骨架
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            return false;
        }

        $isXlsx = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $isXlsx;
    }

    // ---------------------------------------------------------------- 壓縮檔

    private static function open(string $path): \ZipArchive
    {
        $zip  = new \ZipArchive();
        $code = $zip->open($path, \ZipArchive::CHECKCONS);

        if ($code !== true) {
            throw new AppException('這個 Excel 檔打不開，可能在傳輸過程中損毀，請重新上傳。');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new AppException('這個 Excel 檔的內部結構不正常，請重新另存一份再上傳。');
        }

        if ($zip->locateName('xl/workbook.xml') === false) {
            $zip->close();
            throw new AppException('這個檔案是壓縮檔但不是 Excel 活頁簿，請確認上傳的檔案。');
        }

        return $zip;
    }

    /**
     * 取出壓縮檔裡的一個項目，順便擋掉解壓後大到不合理的東西。
     */
    private static function entry(\ZipArchive $zip, string $name, bool $required = true): string
    {
        $stat = $zip->statName($name);

        if ($stat === false) {
            if ($required) {
                throw new AppException('這個 Excel 檔缺少必要的內部檔案（' . $name . '），請重新另存一份再上傳。');
            }

            return '';
        }

        if (($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
            throw new AppException(sprintf(
                'Excel 檔裡的資料量太大（%s 解開後超過 %d MB），請減少列數或分批匯入。',
                $name,
                (int) (self::MAX_ENTRY_BYTES / 1048576)
            ));
        }

        $content = $zip->getFromName($name);

        return $content === false ? '' : $content;
    }

    /**
     * 第一張工作表的路徑。
     *
     * 不能直接寫死 xl/worksheets/sheet1.xml——工作表被刪過或重新排序時，
     * 檔名的編號跟畫面上的順序就對不起來了。要照 workbook.xml 的順序，
     * 再透過 rels 換成實際路徑。
     */
    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = self::xml(self::entry($zip, 'xl/workbook.xml'));
        $sheet    = $workbook->sheets->sheet[0] ?? null;

        if ($sheet === null) {
            throw new AppException('這個 Excel 檔裡沒有任何工作表。');
        }

        $rid  = (string) $sheet->attributes('r', true)['id'];
        $rels = self::xml(self::entry($zip, 'xl/_rels/workbook.xml.rels'));

        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] !== $rid) {
                continue;
            }

            $target = (string) $rel['Target'];

            // Target 可能寫成 worksheets/sheet1.xml 或 /xl/worksheets/sheet1.xml
            $target = ltrim($target, '/');

            return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }

        throw new AppException('這個 Excel 檔的工作表對應不完整，請重新另存一份再上傳。');
    }

    // ---------------------------------------------------------------- 內容

    /**
     * 共用字串表。
     *
     * xlsx 會把重複出現的字串抽出來放在 sharedStrings.xml，儲存格裡只留編號，
     * 所以不讀這張表就只會拿到一堆數字。
     *
     * @return string[]
     */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = self::entry($zip, 'xl/sharedStrings.xml', false);

        if ($xml === '') {
            return []; // 整份都是數字的表就不會有這個檔
        }

        $strings  = [];
        $reader   = self::reader($xml);
        $previous = self::quietXml();

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'si') {
                    continue;
                }

                $node = self::xml($reader->readOuterXml());

                // 一個 si 裡可能有多段 <r>（同一格裡有不同格式的文字），要接起來
                $text = '';
                foreach ($node->xpath('.//*[local-name()="t"]') as $t) {
                    $text .= (string) $t;
                }

                $strings[] = $text;
            }

            self::assertXmlParsed('共用字串表');
        } finally {
            $reader->close();
            self::restoreXml($previous);
        }

        return $strings;
    }

    /**
     * 哪些樣式編號代表「這格是日期」。
     *
     * xlsx 裡日期就是個數字，是不是日期只能看套用的格式。不判斷的話
     * 使用者填 2026/8/14，匯進來會變成 46248。
     *
     * @return array<int, bool> 樣式索引 => 是不是日期
     */
    private static function dateStyles(\ZipArchive $zip): array
    {
        $xml = self::entry($zip, 'xl/styles.xml', false);

        if ($xml === '') {
            return [];
        }

        $styles = self::xml($xml);

        // 自訂格式：formatCode 裡有 y/m/d/h/s 就當日期，
        // 但要先把引號括起來的字面文字拿掉（例如 0"月" 不是日期）
        $custom = [];
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            $code = preg_replace('/"[^"]*"/', '', (string) $fmt['formatCode']);

            if (preg_match('/[ymdhs]/i', $code)) {
                $custom[(int) $fmt['numFmtId']] = true;
            }
        }

        $result = [];
        $index  = 0;

        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];

            $result[$index] = isset($custom[$id]) || self::isBuiltInDateFormat($id);
            $index++;
        }

        return $result;
    }

    /**
     * Excel 內建的日期／時間格式編號。這些是規格寫死的，不會出現在 styles.xml。
     */
    private static function isBuiltInDateFormat(int $id): bool
    {
        return ($id >= 14 && $id <= 22)
            || ($id >= 27 && $id <= 36)
            || ($id >= 45 && $id <= 47)
            || ($id >= 50 && $id <= 58);
    }

    /**
     * 這份活頁簿用不用 1904 日期系統（Mac 版 Excel 的舊預設）。
     * 用錯的話所有日期會差四年又一天。
     */
    private static function isDate1904(\ZipArchive $zip): bool
    {
        $workbook = self::xml(self::entry($zip, 'xl/workbook.xml'));
        $pr       = $workbook->workbookPr ?? null;

        if ($pr === null) {
            return false;
        }

        $flag = (string) ($pr['date1904'] ?? '');

        return $flag === '1' || $flag === 'true';
    }

    /**
     * 掃工作表，回傳 array<int, string[]>。
     *
     * 用 XMLReader 一列一列讀而不是整份 load：工作表 XML 解開後可以很大，
     * 而且我們有列數上限，讀到就可以停，不需要把整份放進記憶體。
     */
    private static function sheetRows(string $xml, array $shared, array $dateStyles, bool $date1904, int $limit): array
    {
        $rows     = [];
        $reader   = self::reader($xml);
        $previous = self::quietXml();

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'row') {
                    continue;
                }

                $cells = self::rowCells(self::xml($reader->readOuterXml()), $shared, $dateStyles, $date1904);

                // 整列都是空的就跳過，跟 CSV 的行為一致（Excel 很容易留下一堆空列）
                foreach ($cells as $cell) {
                    if (trim($cell) !== '') {
                        $rows[] = $cells;
                        break;
                    }
                }

                if (count($rows) >= $limit) {
                    break;
                }
            }

            // 讀到一半壞掉的話 read() 只會回 false 就結束，不檢查的話會變成
            // 「檔案裡沒有任何資料列」這種看不出真正原因的訊息
            self::assertXmlParsed('工作表');
        } finally {
            $reader->close();
            self::restoreXml($previous);
        }

        return $rows;
    }

    /**
     * 一列裡的儲存格。
     *
     * 空白儲存格在 xlsx 裡是「不存在」而不是空字串，所以要照 r 屬性（A1、C1…）
     * 補回位置。不補的話 A、C 兩欄有值時會被擠成第 1、2 欄，整列錯位。
     *
     * @return string[]
     */
    private static function rowCells(\SimpleXMLElement $row, array $shared, array $dateStyles, bool $date1904): array
    {
        $cells = [];

        foreach ($row->c as $c) {
            $index = self::columnIndex((string) $c['r']);

            // 補上中間沒出現過的空格
            for ($i = count($cells); $i < $index; $i++) {
                $cells[$i] = '';
            }

            $cells[$index] = self::cellValue($c, $shared, $dateStyles, $date1904);
        }

        ksort($cells);

        return array_values($cells);
    }

    /**
     * 從儲存格參照（A1、AB12）取出從 0 開始的欄索引。
     */
    private static function columnIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return 0;
        }

        $letters = strtoupper($m[1]);
        $index   = 0;

        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /**
     * 一個儲存格的值，轉成字串。
     *
     * 一律回字串是刻意的：後面的驗證與 DateInput 都吃字串，跟 CSV 走同一條路，
     * 不要因為來源不同就出現「XLSX 過得了、CSV 過不了」這種差異。
     */
    private static function cellValue(\SimpleXMLElement $c, array $shared, array $dateStyles, bool $date1904): string
    {
        $type = (string) $c['t'];

        // 行內字串：沒有進共用字串表的文字
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($c->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string) $t;
            }

            return $text;
        }

        $raw = isset($c->v) ? (string) $c->v : '';

        if ($raw === '') {
            return '';
        }

        switch ($type) {
            case 's': // 共用字串，v 是編號
                return $shared[(int) $raw] ?? '';

            case 'str': // 公式算出來的字串
                return $raw;

            case 'b': // 布林。Excel 匯 CSV 時寫的是 TRUE/FALSE，這裡跟著一致
                return $raw === '1' ? 'TRUE' : 'FALSE';

            case 'e': // 公式錯誤，例如 #N/A。原樣帶出來讓使用者看得到哪一格有問題
                return $raw;
        }

        // 到這裡是數字。要看樣式判斷它其實是不是日期
        $style = (int) ($c['s'] ?? 0);

        if (!empty($dateStyles[$style]) && is_numeric($raw)) {
            return self::serialToDate((float) $raw, $date1904);
        }

        return self::number($raw);
    }

    /**
     * Excel 的日期序號轉 YYYY-MM-DD。
     */
    private static function serialToDate(float $serial, bool $date1904): string
    {
        $days = (int) floor($serial);
        $time = $serial - $days;

        if ($date1904) {
            $date = new \DateTime('1904-01-01');
            $date->modify('+' . $days . ' days');
        } else {
            // 1900 系統有個著名的 bug：Excel 認為 1900 年有 2 月 29 日（序號 60）。
            // 序號 61 之後全部往前挪一天才對得上真實日期。
            $date = new \DateTime('1899-12-31');
            $date->modify('+' . ($days > 59 ? $days - 1 : $days) . ' days');
        }

        if ($time <= 0) {
            return $date->format('Y-m-d');
        }

        $seconds = (int) round($time * 86400);
        $date->modify('+' . $seconds . ' seconds');

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * 數字轉字串，不要出現 1.0E-5 或多餘的小數點。
     */
    private static function number(string $raw): string
    {
        if (!is_numeric($raw)) {
            return $raw;
        }

        $value = (float) $raw;

        if ($value == (int) $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }

    // ---------------------------------------------------------------- XML

    /**
     * 建一個 XMLReader。
     *
     * LIBXML_NONET 是必要的：xlsx 是使用者上傳的檔案，裡面的 XML 不該有機會
     * 去連外部網址。
     */
    private static function reader(string $xml): \XMLReader
    {
        $reader = new \XMLReader();

        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            throw new AppException('這個 Excel 檔的內容讀不出來，請重新另存一份再上傳。');
        }

        return $reader;
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        if (trim($content) === '') {
            throw new AppException('這個 Excel 檔的內容是空的，請確認後重新上傳。');
        }

        $previous = self::quietXml();
        $node     = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        self::restoreXml($previous);

        if ($node === false) {
            throw new AppException('這個 Excel 檔的內容格式不正確，請重新另存一份再上傳。');
        }

        return $node;
    }

    /**
     * 把 libxml 的錯誤收進內部緩衝區，不要變成 PHP warning。
     *
     * 這件事非做不可：這些解析器是在 API 回應裡跑的，warning 會直接印進
     * 輸出，把 JSON 弄壞，前端只會看到「回傳格式不對」而不是真正的原因。
     */
    private static function quietXml(): bool
    {
        libxml_clear_errors();

        return libxml_use_internal_errors(true);
    }

    private static function restoreXml(bool $previous): void
    {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    /**
     * XMLReader 讀到壞掉的 XML 時只會讓 read() 回 false 就安靜結束，
     * 結果是「檔案裡沒有任何資料列」這種看不出原因的訊息。這裡主動檢查。
     */
    private static function assertXmlParsed(string $what): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                throw new AppException(sprintf(
                    '這個 Excel 檔的%s解析失敗（第 %d 行：%s），檔案可能損毀，請重新另存一份再上傳。',
                    $what,
                    $error->line,
                    trim($error->message)
                ));
            }
        }
    }
}
