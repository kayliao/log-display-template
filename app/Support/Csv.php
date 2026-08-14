<?php

namespace App\Support;

use App\Core\AppException;

/**
 * CSV / TXT 解析。
 *
 * 現場的檔案幾乎都是「Excel 另存新檔成 CSV」，會踩到三個坑，
 * 這裡一次處理掉，各支匯入功能就不用各自對抗一次：
 *
 *   1. 編碼    中文版 Excel 存出來是 Big5（CP950），不是 UTF-8。
 *              直接讀會整片變亂碼，而且亂碼還會被當成合法的機台編號存進資料庫。
 *              記事本的「Unicode」與 Excel 的「Unicode 文字」則是 UTF-16，
 *              一樣要認出來，否則也是一整片亂碼。
 *   2. BOM     UTF-8 存檔會在開頭加三個看不見的位元組，
 *              不處理的話第一個欄位名會變成「\xEF\xBB\xBF機台編號」而對不到。
 *   3. 分隔符  有人存成 CSV（逗號）、有人直接從 Excel 複製貼成 TXT（Tab）。
 *              副檔名不可靠，改看內容決定。
 */
class Csv
{
    /**
     * 讀檔並轉成 [欄位名 => 值] 的列陣列。
     *
     * @param string   $path     檔案路徑
     * @param string[] $required 必要欄位（表頭缺了就直接擋掉，不要讓使用者匯到一半才發現）
     * @param int      $maxRows  最多幾列
     * @return array{header:string[], rows:array<int,array>, count:int}
     */
    public static function read(string $path, array $required = [], int $maxRows = 5000): array
    {
        $raw = file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            throw new AppException('檔案是空的或讀取失敗。');
        }

        $text      = self::toUtf8($raw);
        $delimiter = self::detectDelimiter($text);

        $lines = self::parse($text, $delimiter);

        if ($lines === []) {
            throw new AppException('檔案裡沒有任何資料列。');
        }

        $header = array_map(function ($cell) {
            return trim($cell, " \t\"'");
        }, array_shift($lines));

        if ($header === [] || $header === ['']) {
            throw new AppException('讀不到表頭，請確認第一列是欄位名稱。');
        }

        $missing = array_diff($required, $header);

        if ($missing !== []) {
            throw new AppException(sprintf(
                '檔案缺少必要欄位：%s。目前讀到的欄位是：%s。',
                implode('、', $missing),
                implode('、', $header)
            ));
        }

        if (count($lines) > $maxRows) {
            throw new AppException(sprintf(
                '一次最多匯入 %s 列，這個檔案有 %s 列，請分批處理。',
                number_format($maxRows),
                number_format(count($lines))
            ));
        }

        $rows  = [];
        $width = count($header);

        foreach ($lines as $i => $cells) {
            // 欄數不足補空、過多截掉，這樣後面取值就不用每次判斷有沒有這一欄
            $cells = array_slice(array_pad($cells, $width, ''), 0, $width);

            $row = [];
            foreach ($header as $col => $name) {
                $row[$name] = trim((string) $cells[$col]);
            }

            // 行號（+2 是因為陣列從 0 開始、而且第一列是表頭），
            // 錯誤訊息要能告訴使用者「檔案第幾列有問題」。
            // 數的是「有內容的列」，中間夾空白列時會跟編輯器顯示的行號差一點。
            $row['_line'] = $i + 2;
            $rows[]       = $row;
        }

        return [
            'header' => $header,
            'rows'   => $rows,
            'count'  => count($rows),
        ];
    }

    /**
     * 把整份文字拆成「列 => 欄」的二維陣列。
     *
     * 為什麼不用 PHP 內建的 str_getcsv：
     *   這台機器的 PHP 7.2.24 上，str_getcsv 遇到中文欄位會把後面的分隔符號
     *   一起吃掉，例如 "M-900,新機台,MILL-350,B" 會解析成三欄，
     *   中間變成「新機台,MILL-350」。單一個中文字就會觸發。
     *   這種行為隨 PHP 版本與平台而異，模板不能靠它——現場只要換一台機器
     *   就可能出現「同一個檔案在 A 電腦匯得進去、B 電腦欄位全錯」。
     *
     * 這個實作是逐位元組掃描，行為固定，並且順便處理內建函式本來就要處理的：
     *   - 用引號包起來的欄位，裡面可以有分隔符號與換行
     *   - 欄位裡的引號用兩個連續引號表示（RFC 4180）
     *   - \r\n、\n、\r 三種換行都吃
     *
     * @return array<int, string[]> 每一列是一個字串陣列；整列空白的會被略過
     */
    public static function parse(string $text, string $delimiter): array
    {
        $rows     = [];
        $row      = [];
        $field    = '';
        $inQuotes = false;
        $length   = strlen($text);
        $i        = 0;

        // 把目前這一列收起來；整列都是空字串的就丟掉（檔案結尾的空行）
        $endRow = function () use (&$rows, &$row, &$field) {
            $row[]  = $field;
            $field  = '';

            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    $rows[] = $row;
                    break;
                }
            }

            $row = [];
        };

        while ($i < $length) {
            $char = $text[$i];

            if ($inQuotes) {
                if ($char === '"') {
                    // 兩個連續引號 = 一個真的引號
                    if ($i + 1 < $length && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i     += 2;
                        continue;
                    }

                    $inQuotes = false;
                    $i++;
                    continue;
                }

                $field .= $char;
                $i++;
                continue;
            }

            // 只有在欄位開頭出現的引號才算「包住欄位」
            if ($char === '"' && $field === '') {
                $inQuotes = true;
                $i++;
                continue;
            }

            if ($char === $delimiter) {
                $row[] = $field;
                $field = '';
                $i++;
                continue;
            }

            if ($char === "\r" || $char === "\n") {
                $endRow();

                // \r\n 算一個換行，不要多切一列出來
                $i += ($char === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") ? 2 : 1;
                continue;
            }

            $field .= $char;
            $i++;
        }

        // 最後一列沒有換行結尾
        if ($field !== '' || $row !== []) {
            $endRow();
        }

        return $rows;
    }

    /**
     * 轉成 UTF-8 並去掉 BOM。
     *
     * 判斷順序有意義：先認 BOM（最可靠），再問 mb_check_encoding，
     * 都不是才假設是 CP950。反過來做的話，純 ASCII 的檔案會被誤判。
     *
     * UTF-16 有兩條路要擋，成因不同：
     *   有 BOM  ：位元組不是合法 UTF-8，會掉到最後的 CP950 被硬轉成一整片亂碼。
     *   沒有 BOM：\0 是合法的 UTF-8 位元組，純英文內容的 UTF-16 會整份通過
     *             mb_check_encoding 而被原封不動放行，欄位名變成看起來像
     *             「IGEF」、其實是 I\0G\0E\0F\0 的東西。
     * 兩條都不會拋錯，使用者只看得到「目前讀到的欄位是：…」而看不出是編碼問題，
     * 所以空位元組的判斷要排在 mb_check_encoding 之前。
     */
    public static function toUtf8(string $raw): string
    {
        // UTF-8 BOM
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            return substr($raw, 3);
        }

        // UTF-16 BOM。記事本存檔選單裡的「Unicode」、Excel 另存新檔的
        // 「Unicode 文字 (*.txt)」都是這個，現場很容易誤選。
        if (strncmp($raw, "\xFF\xFE", 2) === 0) {
            return self::fromUtf16(substr($raw, 2), 'UTF-16LE');
        }

        if (strncmp($raw, "\xFE\xFF", 2) === 0) {
            return self::fromUtf16(substr($raw, 2), 'UTF-16BE');
        }

        // 空位元組要在 mb_check_encoding 之前處理掉。\0 本身是合法的 UTF-8
        // 位元組，所以「沒有 BOM 的 UTF-16 + 純英文內容」（每個字母後面跟一個
        // \0）會整份通過 UTF-8 檢查而被原封不動放行，欄位名就變成看起來像
        // 「IGEF」、其實是 I\0G\0E\0F\0 的東西。二進位檔同理。
        if (strpos($raw, "\0") !== false) {
            // 沒有 BOM 的 UTF-16（有些工具匯出時不寫 BOM）
            $order = self::detectUtf16($raw);

            if ($order !== null) {
                return self::fromUtf16($raw, $order);
            }

            // 空位元組又不成 UTF-16 的規律，那就根本不是文字檔——最常見的是把
            // .xls / .xlsx 直接改副檔名成 .csv。硬轉下去會得到一串看不出所以然
            // 的東西（例如「俵遄」後面接著看不見的 Workbook），使用者只會覺得
            // 是亂碼，不會想到是檔案格式不對，所以這裡直接講白。
            throw new AppException('這個檔案不是文字檔，看起來是 Excel 活頁簿（.xls / .xlsx）直接改了副檔名。請在 Excel 用「另存新檔」選「CSV UTF-8（逗號分隔）」重新存一份再上傳。');
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // 中文版 Excel 另存 CSV 的預設編碼
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'CP950');

        if ($converted !== false && $converted !== '') {
            return $converted;
        }

        throw new AppException('檔案編碼無法辨識，請用 Excel 另存成「CSV UTF-8」再上傳。');
    }

    /**
     * UTF-16 轉 UTF-8。呼叫前要自己把 BOM 去掉。
     */
    private static function fromUtf16(string $raw, string $from): string
    {
        // UTF-16 一個字至少兩個位元組，長度是奇數表示檔案被截斷或根本不是
        // UTF-16。硬轉的話最後一個字會變成問號，不如直接講清楚。
        if (strlen($raw) % 2 !== 0) {
            throw new AppException('檔案看起來是 UTF-16 編碼但長度不完整，可能在複製過程中損毀，請重新另存成「CSV UTF-8」再上傳。');
        }

        $text = @mb_convert_encoding($raw, 'UTF-8', $from);

        if ($text === false || $text === '' || !mb_check_encoding($text, 'UTF-8')) {
            throw new AppException('檔案是 UTF-16 編碼，轉換失敗。請用 Excel 另存成「CSV UTF-8」再上傳。');
        }

        return $text;
    }

    /**
     * 認出沒有 BOM 的 UTF-16，回傳 'UTF-16LE' / 'UTF-16BE'，都不像就回傳 null。
     *
     * 依據是空位元組的位置：UTF-16 存 ASCII 字元（逗號、數字、換行，CSV 一定有）
     * 時會固定配一個 \0，LE 落在奇數位、BE 落在偶數位。
     * UTF-8 與 CP950 的文字檔則不會出現 \0，所以這個判斷不會誤傷。
     *
     * @return string|null
     */
    private static function detectUtf16(string $raw)
    {
        $sample = substr($raw, 0, 512);
        $length = strlen($sample) & ~1; // 取偶數長度，才好一對一對地數

        if ($length < 4) {
            return null;
        }

        $evenNulls = 0;
        $oddNulls  = 0;

        for ($i = 0; $i < $length; $i += 2) {
            if ($sample[$i] === "\0") {
                $evenNulls++;
            }

            if ($sample[$i + 1] === "\0") {
                $oddNulls++;
            }
        }

        // 門檻抓三成：中文佔多數的檔案空位元組會變少，但 CSV 的分隔符號與
        // 換行都是 ASCII，不至於一個都沒有。
        $threshold = max(2, (int) (($length / 2) * 0.3));

        if ($oddNulls >= $threshold && $evenNulls === 0) {
            return 'UTF-16LE';
        }

        if ($evenNulls >= $threshold && $oddNulls === 0) {
            return 'UTF-16BE';
        }

        return null;
    }

    /**
     * 從第一列猜分隔符號。
     * 逗號與 Tab 哪個多就用哪個，都沒有就當成逗號（單欄檔案）。
     */
    public static function detectDelimiter(string $text): string
    {
        $firstLine = strtok($text, "\r\n") ?: '';

        $counts = [
            ','  => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
            ';'  => substr_count($firstLine, ';'),
        ];

        arsort($counts);
        $best = key($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
