<?php

namespace App\Support;

/**
 * XLSX 產生。
 *
 * 為什麼自己寫而不用 PhpSpreadsheet——理由跟 Support\Xlsx（讀取端）一樣：
 * 專案鎖 PHP 7.2，那個套件能用的版本早就沒有安全更新，vendor 還會膨脹十幾 MB，
 * 而現場是沒有網路、要用複製資料夾的方式部署的。
 *
 * xlsx 說穿了就是一包 UTF-8 的 XML 壓成 zip。這裡只做「給人看的報表」需要的東西：
 *
 *   - 文字與數字儲存格
 *   - 合併儲存格
 *   - 底色、框線、對齊、自動換行、粗體
 *   - 數值格式（千分位）
 *   - 欄寬
 *
 * 不做公式、圖表、多工作表、凍結窗格。需要那些的時候再說，不要為了「將來可能會用到」
 * 先把這個檔案養成第二個 PhpSpreadsheet。
 *
 * 用法：
 *
 *   $xlsx  = new XlsxWriter('單日優先排程');
 *   $head  = $xlsx->style(['bold' => true, 'border' => true, 'align' => 'center']);
 *   $money = $xlsx->style(['border' => true, 'format' => '#,##0']);
 *
 *   $xlsx->width(2, 20);
 *   $xlsx->set(1, 2, '工站', $head);
 *   $xlsx->set(2, 2, 1234, $money);
 *   $xlsx->merge(2, 2, 5, 2);
 *   $xlsx->download('報表.xlsx');
 *
 * 列與欄都是 1 起算（跟 Excel 一樣，A 欄是 1）。
 */
class XlsxWriter
{
    /** 工作表名稱長度上限，Excel 的規定 */
    const MAX_SHEET_NAME = 31;

    /** 自訂數值格式的編號從這裡開始（0～163 是內建的） */
    const FIRST_NUMFMT_ID = 164;

    /** @var string */
    private $sheetName;

    /** @var array<int, array<int, array{value:mixed, style:int, text:bool}>> [列][欄] */
    private $cells = [];

    /** @var string[] 合併範圍，例如 B3:B10 */
    private $merges = [];

    /** @var array<int, float> 欄 => 寬度 */
    private $widths = [];

    /** @var string[] 共用字串表 */
    private $strings = [];

    /** @var array<string, int> 字串 => 在共用字串表裡的編號 */
    private $stringIndex = [];

    /** @var array<string, int> 樣式指紋 => 樣式編號 */
    private $styleIndex = [];

    /** @var array[] 樣式定義 */
    private $styles = [];

    /** @var string[] 用到的底色（ARGB） */
    private $fills = [];

    /** @var string[] 用到的數值格式 */
    private $formats = [];

    public function __construct(string $sheetName = 'Sheet1')
    {
        $this->sheetName = self::safeSheetName($sheetName);

        // 0 號樣式是「什麼都沒設定」，Excel 預期它一定存在
        $this->style([]);
    }

    /**
     * 註冊一組樣式，回傳樣式編號。
     *
     * 同樣的設定只會產生一個編號 —— 一份幾百列的報表如果每一格都存一份樣式，
     * styles.xml 會比資料本身還大，Excel 開起來也會變慢。
     *
     * 可用的鍵：
     *   fill    底色，ARGB 字串（例如 'FFFFE699'）
     *   border  true = 四邊細框線
     *   align   'left' | 'center' | 'right'
     *   valign  'top' | 'center' | 'bottom'
     *   wrap    true = 自動換行
     *   bold    true = 粗體
     *   format  數值格式（例如 '#,##0'）
     */
    public function style(array $spec): int
    {
        $spec = [
            'fill'   => isset($spec['fill']) ? strtoupper((string) $spec['fill']) : null,
            'border' => !empty($spec['border']),
            'align'  => isset($spec['align']) ? (string) $spec['align'] : null,
            'valign' => isset($spec['valign']) ? (string) $spec['valign'] : null,
            'wrap'   => !empty($spec['wrap']),
            'bold'   => !empty($spec['bold']),
            'format' => isset($spec['format']) ? (string) $spec['format'] : null,
        ];

        $key = json_encode($spec);

        if (isset($this->styleIndex[$key])) {
            return $this->styleIndex[$key];
        }

        if ($spec['fill'] !== null && !in_array($spec['fill'], $this->fills, true)) {
            $this->fills[] = $spec['fill'];
        }

        if ($spec['format'] !== null && !in_array($spec['format'], $this->formats, true)) {
            $this->formats[] = $spec['format'];
        }

        $this->styles[] = $spec;

        return $this->styleIndex[$key] = count($this->styles) - 1;
    }

    /**
     * 填一格。
     *
     * 數字會存成數字、其他存成文字。要強制當文字（度數 -2.00、前面有 0 的批號
     * 這種被 Excel 自作主張轉成數字就毀了的值）請用 text()。
     */
    public function set(int $row, int $col, $value, int $style = 0): void
    {
        if ($value === null || $value === '') {
            return; // 空格在 xlsx 裡就是「不存在這一格」
        }

        $this->cells[$row][$col] = [
            'value' => $value,
            'style' => $style,
            'text'  => !is_int($value) && !is_float($value),
        ];
    }

    /**
     * 填一格，強制當文字。
     */
    public function text(int $row, int $col, $value, int $style = 0): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->cells[$row][$col] = [
            'value' => (string) $value,
            'style' => $style,
            'text'  => true,
        ];
    }

    /**
     * 合併儲存格。值放在左上角那一格。
     */
    public function merge(int $row1, int $col1, int $row2, int $col2): void
    {
        if ($row1 === $row2 && $col1 === $col2) {
            return; // 一格不用合併
        }

        $this->merges[] = self::ref($row1, $col1) . ':' . self::ref($row2, $col2);
    }

    /**
     * 欄寬（Excel 的字元寬度單位，不是像素）。
     */
    public function width(int $col, float $width): void
    {
        $this->widths[$col] = $width;
    }

    /**
     * 產生檔案內容。
     */
    public function toString(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new \RuntimeException('無法建立暫存檔來組 xlsx');
        }

        try {
            $this->write($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * 存成檔案。
     */
    public function save(string $path): void
    {
        $this->write($path);
    }

    /**
     * 直接送給瀏覽器下載。
     */
    public function download(string $filename): void
    {
        $content = $this->toString();

        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-store');
        }

        echo $content;
        exit;
    }

    // ------------------------------------------------------------------
    // 以下是組 zip 與各個 XML 的部分
    // ------------------------------------------------------------------

    private function write(string $path): void
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('這台主機沒有啟用 zip 擴充，無法產生 xlsx');
        }

        $sheet = $this->sheetXml();   // 這一步會把字串填進共用字串表，要先跑

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('無法建立 xlsx 檔：' . $path);
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->close();
    }

    private function contentTypesXml(): string
    {
        $main = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        return self::declaration()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="' . $main . '.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="' . $main . '.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="' . $main . '.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="' . $main . '.sharedStrings+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return self::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return self::declaration()
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $base = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

        return self::declaration()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $base . 'worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $base . 'styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="' . $base . 'sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    /**
     * 工作表。
     *
     * 元素順序是有規定的：cols 一定要在 sheetData 之前、mergeCells 一定要在之後，
     * 順序錯了 Excel 會說檔案損毀（而且不會告訴你錯在哪）。
     */
    private function sheetXml(): string
    {
        $xml = self::declaration()
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->widths !== []) {
            $xml .= '<cols>';

            foreach ($this->widths as $col => $width) {
                $xml .= '<col min="' . $col . '" max="' . $col . '"'
                      . ' width="' . rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.')
                      . '" customWidth="1"/>';
            }

            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        ksort($this->cells);

        foreach ($this->cells as $row => $cells) {
            ksort($cells);

            $xml .= '<row r="' . $row . '">';

            foreach ($cells as $col => $cell) {
                $xml .= $this->cellXml($row, $col, $cell);
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ($this->merges !== []) {
            $xml .= '<mergeCells count="' . count($this->merges) . '">';

            foreach ($this->merges as $ref) {
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }

            $xml .= '</mergeCells>';
        }

        return $xml . '</worksheet>';
    }

    private function cellXml(int $row, int $col, array $cell): string
    {
        $ref   = self::ref($row, $col);
        $style = $cell['style'] > 0 ? ' s="' . $cell['style'] . '"' : '';

        if (!$cell['text']) {
            return '<c r="' . $ref . '"' . $style . '><v>' . $cell['value'] . '</v></c>';
        }

        /**
         * 文字走共用字串表。
         *
         * 這份報表裡「-」、工站名稱、日期這些值會重複幾百次，
         * 存成共用字串一次、其他格只放編號，檔案會小很多。
         */
        return '<c r="' . $ref . '" t="s"' . $style . '>'
             . '<v>' . $this->stringId((string) $cell['value']) . '</v></c>';
    }

    private function stringId(string $value): int
    {
        if (isset($this->stringIndex[$value])) {
            return $this->stringIndex[$value];
        }

        $this->strings[] = $value;

        return $this->stringIndex[$value] = count($this->strings) - 1;
    }

    private function sharedStringsXml(): string
    {
        $xml = self::declaration()
             . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
             . ' count="' . count($this->strings) . '" uniqueCount="' . count($this->strings) . '">';

        foreach ($this->strings as $string) {
            // xml:space="preserve" 是必要的，不然前後空白會被 Excel 吃掉
            $xml .= '<si><t xml:space="preserve">' . self::esc($string) . '</t></si>';
        }

        return $xml . '</sst>';
    }

    /**
     * 樣式表。
     *
     * fills 的 0 號與 1 號是保留的（none 與 gray125），少了它們 Excel 會拒絕開檔，
     * 所以自訂底色一律從 2 號開始算。
     */
    private function stylesXml(): string
    {
        $xml = self::declaration()
             . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // 數值格式
        if ($this->formats !== []) {
            $xml .= '<numFmts count="' . count($this->formats) . '">';

            foreach ($this->formats as $i => $format) {
                $xml .= '<numFmt numFmtId="' . (self::FIRST_NUMFMT_ID + $i) . '"'
                      . ' formatCode="' . self::esc($format) . '"/>';
            }

            $xml .= '</numFmts>';
        }

        // 字型：0 號一般、1 號粗體
        $xml .= '<fonts count="2">'
              . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
              . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
              . '</fonts>';

        // 底色
        $xml .= '<fills count="' . (2 + count($this->fills)) . '">'
              . '<fill><patternFill patternType="none"/></fill>'
              . '<fill><patternFill patternType="gray125"/></fill>';

        foreach ($this->fills as $fill) {
            $xml .= '<fill><patternFill patternType="solid">'
                  . '<fgColor rgb="' . self::esc($fill) . '"/><bgColor indexed="64"/>'
                  . '</patternFill></fill>';
        }

        $xml .= '</fills>';

        // 框線：0 號無框、1 號四邊細框
        $xml .= '<borders count="2">'
              . '<border><left/><right/><top/><bottom/><diagonal/></border>'
              . '<border>'
              . '<left style="thin"><color rgb="FF000000"/></left>'
              . '<right style="thin"><color rgb="FF000000"/></right>'
              . '<top style="thin"><color rgb="FF000000"/></top>'
              . '<bottom style="thin"><color rgb="FF000000"/></bottom>'
              . '<diagonal/></border>'
              . '</borders>';

        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

        $xml .= '<cellXfs count="' . count($this->styles) . '">';

        foreach ($this->styles as $spec) {
            $xml .= $this->cellXf($spec);
        }

        $xml .= '</cellXfs>';

        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';

        return $xml . '</styleSheet>';
    }

    private function cellXf(array $spec): string
    {
        $fontId   = $spec['bold'] ? 1 : 0;
        $borderId = $spec['border'] ? 1 : 0;

        $fillId = 0;
        if ($spec['fill'] !== null) {
            $fillId = 2 + array_search($spec['fill'], $this->fills, true);
        }

        $numFmtId = 0;
        if ($spec['format'] !== null) {
            $numFmtId = self::FIRST_NUMFMT_ID + array_search($spec['format'], $this->formats, true);
        }

        $align = '';
        if ($spec['align'] !== null || $spec['valign'] !== null || $spec['wrap']) {
            $align = '<alignment'
                   . ($spec['align'] !== null ? ' horizontal="' . self::esc($spec['align']) . '"' : '')
                   . ($spec['valign'] !== null ? ' vertical="' . self::esc($spec['valign']) . '"' : '')
                   . ($spec['wrap'] ? ' wrapText="1"' : '')
                   . '/>';
        }

        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '"'
            . ' fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ($numFmtId ? ' applyNumberFormat="1"' : '')
            . ($fontId ? ' applyFont="1"' : '')
            . ($fillId ? ' applyFill="1"' : '')
            . ($borderId ? ' applyBorder="1"' : '')
            . ($align !== '' ? ' applyAlignment="1"' : '');

        return $align !== '' ? $xf . '>' . $align . '</xf>' : $xf . '/>';
    }

    // ------------------------------------------------------------------
    // 小工具
    // ------------------------------------------------------------------

    private static function declaration(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    /**
     * 儲存格參照：(1, 1) => A1、(3, 28) => AB3
     */
    public static function ref(int $row, int $col): string
    {
        return self::columnName($col) . $row;
    }

    /**
     * 欄名：1 => A、27 => AA
     */
    public static function columnName(int $col): string
    {
        $name = '';

        while ($col > 0) {
            $col--;
            $name = chr(ord('A') + ($col % 26)) . $name;
            $col  = intdiv($col, 26);
        }

        return $name === '' ? 'A' : $name;
    }

    /**
     * XML 逸出。
     *
     * 順便把 XML 1.0 不接受的控制字元拿掉：這些字元從資料庫撈出來時偶爾會夾帶，
     * 直接寫進去的話 Excel 會說檔案損毀，而且完全看不出是哪一格的問題。
     */
    private static function esc(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeSheetName(string $name): string
    {
        // Excel 不接受這些字元當工作表名稱
        $name = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', trim($name));

        if ($name === '') {
            $name = 'Sheet1';
        }

        return mb_substr($name, 0, self::MAX_SHEET_NAME, 'UTF-8');
    }

    private static function safeFilename(string $name): string
    {
        $name = str_replace(['"', "\r", "\n"], '', $name);

        return substr($name, -5) === '.xlsx' ? $name : $name . '.xlsx';
    }
}
