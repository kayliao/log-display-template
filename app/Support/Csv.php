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

        $text = self::toUtf8($raw);

        // 傳原始位元組進去，訊息裡才是檔案真正的開頭（轉碼後 0x80 會變成 C2 80，
        // 拿去對照檔案格式會對不上）
        self::assertIsText($text, $raw);

        $delimiter = self::detectDelimiter($text);

        // 表頭檢查、補欄、標行號跟 XLSX 完全一樣，所以交給 Table 處理，
        // 兩種格式的錯誤訊息才不會各說各話
        return Table::fromLines(self::parse($text, $delimiter), $required, $maxRows);
    }

    /**
     * 確認轉出來的東西真的是文字。
     *
     * 空位元組那關只擋得住「整片 00」型的二進位檔。有些檔案的控制位元組是
     * 散落的（例如某些自訂容器格式，開頭長成 49 47 45 46 02 05 80 01），
     * 空位元組佔比很低，會一路走到 CP950 被硬轉成亂碼，然後在「缺少必要欄位」
     * 那裡冒出一串鬼畫符——使用者只會覺得是編碼問題，然後開始反覆換編碼另存，
     * 但檔案根本不是文字。
     *
     * 判斷方式跟 detectUtf16() 的評分一樣：看控制字元與未指定碼位的比例。
     * 正常的文字檔這個比例是 0，抓 2% 已經很寬鬆。
     */
    private static function assertIsText(string $text, string $raw): void
    {
        $sample = mb_substr($text, 0, 2000, 'UTF-8');
        $total  = mb_strlen($sample, 'UTF-8');

        if ($total === 0) {
            return;
        }

        // Tab 與換行雖然是控制字元，但文字檔本來就有
        $bad = preg_match_all('/[^\P{C}\t\r\n]/u', $sample);

        if ($bad === false || ($bad / $total) <= 0.02) {
            return;
        }

        throw new AppException(sprintf(
            '這個檔案不是文字檔，也不是可以匯入的表格（開頭的位元組是 %s）。'
            . '常見原因是把其他格式的檔案改成 .csv／.xlsx 副檔名，或是檔案本身有加密保護。'
            . '請確認來源，或用 Excel 開啟後另存成「CSV UTF-8」或 .xlsx 再上傳。',
            strtoupper(implode(' ', str_split(bin2hex(substr($raw, 0, 8)), 2)))
        ));
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
        $nulls = substr_count($raw, "\0");

        if ($nulls > 0) {
            // 沒有 BOM 的 UTF-16（有些工具匯出時不寫 BOM）
            $order = self::detectUtf16($raw);

            if ($order !== null) {
                return self::fromUtf16($raw, $order);
            }

            // 空位元組又不成 UTF-16 的規律。這時候要分兩種情況，不能一律當成
            // 二進位檔擋掉——舊系統（AS/400、固定寬度報表那類）匯出的文字檔
            // 常常夾著幾個當填充用的 \0，那種檔案是好的，只是髒。
            //
            // 用佔比來分：二進位檔的 \0 是整片整片的，文字檔的是零星幾個。
            if ($nulls / strlen($raw) > 0.05) {
                throw new AppException('這個檔案讀起來不是文字，最常見的原因是把 Excel 活頁簿（.xls / .xlsx）直接改副檔名成 .csv。請在 Excel 用「另存新檔」選「CSV UTF-8（逗號分隔）」重新存一份再上傳。如果確定這是文字檔，請用 tools/check-encoding.php 檢查後回報。');
            }

            // 零星幾個就清掉繼續當文字處理。留著的話會混進欄位名裡，
            // 變成畫面上看不出來、卻永遠對不到的欄位。
            $raw = str_replace("\0", '', $raw);
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
     * 不要用「數空位元組落在奇數位還是偶數位」來判斷。UTF-16 存 ASCII 字元時
     * \0 的位置確實是固定的（LE 落奇數位、BE 落偶數位），但碼位結尾是 00 的
     * 中文字剛好相反——「一」是 U+4E00，在 LE 就是 00 4E，\0 落在偶數位。
     * 整份檔案只要出現一個「一」，「另一側必須是 0」這種條件就會失效，
     * 把好好的中文 CSV 誤判成二進位檔。這種欄位在現場一點都不罕見。
     *
     * 改成兩種都實際轉轉看，再看轉出來的東西像不像文字，就不必猜位元組分布。
     *
     * @return string|null
     */
    private static function detectUtf16(string $raw)
    {
        // 取樣就好，整份檔案跑正規表示式沒必要
        $sample = substr($raw, 0, 4096);
        $sample = substr($sample, 0, strlen($sample) & ~1); // UTF-16 兩個位元組一個字

        if (strlen($sample) < 4) {
            return null;
        }

        $best      = null;
        $bestScore = 0.0;

        foreach (['UTF-16LE', 'UTF-16BE'] as $order) {
            $score = self::textScore($sample, $order);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $order;
            }
        }

        // 抓 0.95 而不是 1.0：取樣有可能切在代理對中間，尾巴會壞掉一個字
        return $bestScore >= 0.95 ? $best : null;
    }

    /**
     * 把樣本當成某種 UTF-16 轉轉看，回傳 0～1 的「像不像文字」分數。
     */
    private static function textScore(string $sample, string $order): float
    {
        $text = @mb_convert_encoding($sample, 'UTF-8', $order);

        if ($text === false || $text === '' || !mb_check_encoding($text, 'UTF-8')) {
            return 0.0;
        }

        // 轉完還有 \0 表示原本就不是 UTF-16：
        // 二進位檔那一整片 00 00 會變成一堆 U+0000
        if (strpos($text, "\0") !== false) {
            return 0.0;
        }

        $total = mb_strlen($text, 'UTF-8');

        if ($total === 0) {
            return 0.0;
        }

        // \p{C} 涵蓋控制字元與未指定碼位，也就是「文字檔不該出現的東西」。
        // Tab 與換行雖然歸在控制字元，但 CSV 本來就有，要排除在外。
        $bad = preg_match_all('/[^\P{C}\t\r\n]/u', $text);

        if ($bad === false) {
            return 0.0;
        }

        return 1 - ($bad / $total);
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
