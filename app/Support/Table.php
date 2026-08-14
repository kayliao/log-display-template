<?php

namespace App\Support;

use App\Core\AppException;

/**
 * 把「一列一列的儲存格」組成匯入用的資料結構。
 *
 * 存在的理由：CSV 與 XLSX 讀出來的東西到這一步已經一模一樣了——都是
 * array<int, string[]>。表頭檢查、補欄、標行號、錯誤訊息如果各寫一份，
 * 兩種格式遲早會給出不一樣的說法（同一個檔案存成 CSV 說「缺少必要欄位」、
 * 存成 XLSX 卻說別的），現場會更難查。所以只留一份。
 */
class Table
{
    /**
     * @param array<int, string[]> $lines    第一列是表頭
     * @param string[]             $required 必要欄位（表頭缺了就直接擋掉，
     *                                       不要讓使用者匯到一半才發現）
     * @param int                  $maxRows  最多幾列
     * @return array{header:string[], rows:array<int,array>, count:int}
     */
    public static function fromLines(array $lines, array $required, int $maxRows): array
    {
        if ($lines === []) {
            throw new AppException('檔案裡沒有任何資料列。');
        }

        $required = array_map([self::class, 'text'], $required);

        $first = array_shift($lines);

        if (!is_array($first)) {
            throw new AppException('檔案的表頭讀不出來，請確認第一列是欄位名稱。');
        }

        // array_values 是必要的：不同解析器回傳的鍵不一定一樣（有的用 0、1、2，
        // 有的用儲存格的欄名 A、B、C）。不統一的話，下面用欄索引去取值就會落空，
        // 變成「明明有這一欄卻說缺少」。
        $header = array_map(function ($cell) {
            return trim(self::text($cell), " \t\"'");
        }, array_values($first));

        if ($header === [] || $header === ['']) {
            throw new AppException('讀不到表頭，請確認第一列是欄位名稱。');
        }

        $missing = array_diff($required, $header);

        if ($missing !== []) {
            throw new AppException(sprintf(
                '檔案缺少必要欄位：%s。目前讀到的欄位是：%s。%s',
                implode('、', $missing),
                implode('、', $header),
                self::headerHint($header)
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
            $cells = array_slice(array_pad(array_values((array) $cells), $width, ''), 0, $width);

            $row = [];
            foreach ($header as $col => $name) {
                $row[$name] = trim(self::text($cells[$col]));
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
     * 把任何東西安全地變成字串。
     *
     * 為什麼不直接 (string)：碰到陣列時 PHP 會丟
     * 「Notice: Array to string conversion」。這些函式是在 API 回應裡跑的，
     * notice 會直接印進輸出把 JSON 弄壞，前端只看得到「回傳格式不對」，
     * 真正的原因反而被蓋掉。
     *
     * 會出現陣列通常是欄位定義寫錯（例如 required 裡不小心包了一層），
     * 轉成空字串之後會由「缺少必要欄位」把問題講出來，比丟 notice 清楚。
     *
     * @param mixed $value
     */
    private static function text($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return is_bool($value) ? ($value ? '1' : '') : '';
        }

        return (string) $value;
    }

    /**
     * 欄位名對不到時，補一句話說明「畫面上看到的」跟「實際讀到的」為什麼不一樣。
     *
     * 「目前讀到的欄位是：IGEF」這種訊息最難處理：使用者看到的是幾個正常的
     * 英文字母，會以為欄位名是對的、只是系統不認得，於是反覆換編碼另存，
     * 但問題其實是字母中間夾了看不見的東西。訊息裡直接把位元組秀出來，
     * 現場就不用來回猜。
     */
    private static function headerHint(array $header): string
    {
        $joined = implode('', $header);

        if (!preg_match('/[^\P{C}\t]/u', $joined)) {
            return '';
        }

        $first = $header[0] ?? '';

        return sprintf(
            '（注意：欄位名裡夾著看不見的控制字元，畫面上看不出來。第一個欄位的實際位元組是 %s，'
            . '這通常表示檔案不是純文字，或存檔時選錯了編碼。）',
            strtoupper(implode(' ', str_split(bin2hex(substr($first, 0, 12)), 2)))
        );
    }
}
