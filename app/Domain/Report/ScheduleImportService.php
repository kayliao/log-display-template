<?php

namespace App\Domain\Report;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Logger;
use App\Support\Csv;

/**
 * 排程與實績匯入 —— 商業邏輯。
 *
 * 流程跟機台清單匯入一樣是兩步（preview → commit），原因也一樣：
 * 現場的檔案十次有三次是錯的，先看過再寫入，錯了就重傳，什麼都沒動到。
 *
 * 這一支多一件事：檔案上填的是「白片 / 彩片」這種現場講法，
 * 寫進資料庫的是代號（WHITE / COLOR）。轉換只做在這一層，
 * 現場不用背代號，資料表也不用存中文。
 */
class ScheduleImportService
{
    /** @var ScheduleImportRepository */
    private $repo;

    public function __construct(?ScheduleImportRepository $repo = null)
    {
        $this->repo = $repo ?: new ScheduleImportRepository();
    }

    /**
     * 檔案要有哪些欄位、怎麼驗。
     *
     * 這一份同時餵給三個地方：畫面上的「檔案格式」說明表、逐列驗證、
     * 下載範本的表頭，所以不會出現「照範本填卻說欄位不對」。
     */
    public static function columns(): array
    {
        $schedules = ScheduleService::defaultSchedules();

        $codes = [];
        foreach ($schedules as $code => $name) {
            $codes[] = $code . '（' . $name . '）';
        }

        return [
            'plan_date' => [
                'title'    => '日期',
                'required' => true,
                'rule'     => '/^\d{4}-\d{2}-\d{2}$/',
                'message'  => '格式必須是 YYYY-MM-DD',
                'sample'   => date('Y-m-d'),
            ],
            'schedule_code' => [
                'title'    => '排程代號',
                'required' => true,
                'in'       => array_keys($schedules),
                'message'  => '只能填 ' . implode('、', $codes),
                'sample'   => 'HYD',
            ],
            'category' => [
                'title'    => '產品別',
                'required' => true,
                'in'       => array_values(ScheduleService::categoryOptions()),
                'message'  => '只能填 白片 或 彩片',
                'sample'   => '白片',
            ],
            'line_name' => [
                'title'    => '線別',
                'required' => true,
                'max'      => 20,
                'sample'   => '一線',
            ],
            'plan_qty' => [
                'title'    => '預計數量',
                'required' => true,
                'rule'     => '/^\d{1,9}$/',
                'message'  => '只能填 0 以上的整數',
                'sample'   => '4000',
            ],
            'actual_qty' => [
                'title'    => '實際數量',
                'required' => true,
                'rule'     => '/^\d{1,9}$/',
                'message'  => '只能填 0 以上的整數',
                'sample'   => '3820',
            ],
        ];
    }

    /**
     * 解析並驗證，不寫入。
     *
     * @return array{total:int, valid:int, insert:int, update:int, preview:array, errors:array}
     */
    public function preview(string $path): array
    {
        $columns = self::columns();
        $file    = Csv::read($path, self::requiredTitles(), (int) Config::get('app.import.max_rows', 5000));

        $rows   = [];
        $errors = [];
        $seen   = [];

        foreach ($file['rows'] as $raw) {
            $line = $raw['_line'];
            $row  = ['_line' => $line];
            $bad  = false;

            foreach ($columns as $key => $meta) {
                $value     = trim((string) ($raw[$meta['title']] ?? ''));
                $row[$key] = $value;

                $problem = self::validateCell($value, $meta);

                if ($problem !== null) {
                    $errors[] = [
                        'line'    => $line,
                        'column'  => $meta['title'],
                        'value'   => $value,
                        'message' => $problem,
                    ];
                    $bad = true;
                }
            }

            /**
             * 同一天、同一個排程、同一個產品別、同一條線只能有一列。
             * 重複的話後面那筆會蓋掉前面那筆，使用者通常沒發現——
             * 表現出來就是「我明明填了 4000，怎麼只算 2000」。
             */
            $key = implode('|', [$row['plan_date'], $row['schedule_code'], $row['category'], $row['line_name']]);

            if (trim($key, '|') !== '') {
                if (isset($seen[$key])) {
                    $errors[] = [
                        'line'    => $line,
                        'column'  => '線別',
                        'value'   => $row['line_name'],
                        'message' => '跟第 ' . $seen[$key] . ' 列重複（同一天、同排程、同產品別、同線別）',
                    ];
                    $bad = true;
                } else {
                    $seen[$key] = $line;
                }
            }

            // 實際大於預計不是錯誤（超前達標），但值得提醒一下
            if (!$bad && (int) $row['actual_qty'] > (int) $row['plan_qty'] * 2 && (int) $row['plan_qty'] > 0) {
                $errors[] = [
                    'line'    => $line,
                    'column'  => '實際數量',
                    'value'   => $row['actual_qty'],
                    'message' => '超過預計的兩倍，請確認是不是多打了一個 0',
                ];
                $bad = true;
            }

            if (!$bad) {
                $rows[] = $row;
            }
        }

        $existing = $this->existingKeys($rows);

        $insert = 0;
        $update = 0;

        foreach ($rows as $i => $row) {
            $isUpdate = in_array(self::rowKey($row), $existing, true);

            $rows[$i]['_act'] = $isUpdate ? 'update' : 'insert';

            $isUpdate ? $update++ : $insert++;
        }

        $titles = [];
        foreach ($columns as $meta) {
            $titles[] = $meta['title'];
        }

        return [
            'total'      => $file['count'],
            'valid'      => count($rows),
            'insert'     => $insert,
            'update'     => $update,
            'columns'    => $titles,
            'preview'    => array_slice($rows, 0, 20),
            'errors'     => array_slice($errors, 0, 100),
            'errorTotal' => count($errors),
        ];
    }

    /**
     * 確認後真的寫入。
     *
     * 會重新解析驗證一次：預覽的結果放在使用者的瀏覽器裡，不能信任。
     */
    public function commit(string $path): array
    {
        $result = $this->preview($path);

        if ($result['errors'] !== []) {
            throw new AppException(sprintf(
                '檔案還有 %d 個問題沒有處理，請修正後重新上傳。',
                $result['errorTotal']
            ));
        }

        if ($result['valid'] === 0) {
            throw new AppException('檔案裡沒有可以匯入的資料。');
        }

        $written = $this->repo->upsertMany($this->validRows($path));

        Logger::info('排程實績匯入完成', [
            'insert' => $result['insert'],
            'update' => $result['update'],
        ]);

        return [
            'written' => $written,
            'insert'  => $result['insert'],
            'update'  => $result['update'],
        ];
    }

    /**
     * 全部通過驗證的列，並且轉成資料表要的樣子（產品別中文 → 代號）。
     */
    private function validRows(string $path): array
    {
        $columns   = self::columns();
        $file      = Csv::read($path, self::requiredTitles(), (int) Config::get('app.import.max_rows', 5000));
        $schedules = ScheduleService::defaultSchedules();
        $codes     = array_flip(ScheduleService::categoryOptions());   // 白片 => WHITE
        $sortNo    = array_flip(array_keys(ScheduleService::categoryOptions()));

        $rows = [];

        foreach ($file['rows'] as $raw) {
            $row = [];

            foreach ($columns as $key => $meta) {
                $row[$key] = trim((string) ($raw[$meta['title']] ?? ''));
            }

            $category = $codes[$row['category']] ?? $row['category'];

            $rows[] = [
                'plan_date'     => $row['plan_date'],
                'schedule_code' => $row['schedule_code'],
                'schedule_name' => $schedules[$row['schedule_code']] ?? $row['schedule_code'],
                'category'      => $category,
                'category_name' => $row['category'],
                // 統整卡的排列順序（白片在前、彩片在後）跟著資料一起存
                'sort_no'       => ($sortNo[$category] ?? 9) + 1,
                'line_name'     => $row['line_name'],
                'plan_qty'      => (int) $row['plan_qty'],
                'actual_qty'    => (int) $row['actual_qty'],
            ];
        }

        return $rows;
    }

    /**
     * 已經存在的鍵（預覽時標示「新增」還是「更新」）。
     * 資料庫還沒接好時不要讓預覽掛掉，全部當成新增。
     */
    private function existingKeys(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        try {
            return $this->repo->existingKeys($rows);
        } catch (\Throwable $e) {
            Logger::warning('比對既有排程失敗，預覽一律顯示為新增', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 一列的唯一鍵。Repository 比對用的也是這一份，兩邊不會兜不起來。
     */
    public static function rowKey(array $row): string
    {
        $category = ScheduleService::categoryOptions();
        $codes    = array_flip($category);
        $value    = $row['category'] ?? '';

        return implode('|', [
            $row['plan_date'] ?? '',
            $row['schedule_code'] ?? '',
            $codes[$value] ?? $value,
            $row['line_name'] ?? '',
        ]);
    }

    private static function requiredTitles(): array
    {
        $titles = [];

        foreach (self::columns() as $meta) {
            if (!empty($meta['required'])) {
                $titles[] = $meta['title'];
            }
        }

        return $titles;
    }

    /**
     * 單一欄位驗證。回傳 null 表示沒問題。
     * 規則跟 MachineImportService 是同一套寫法，換一份欄位定義就換一種檔案。
     */
    private static function validateCell(string $value, array $meta): ?string
    {
        if ($value === '') {
            return !empty($meta['required']) ? '必填' : null;
        }

        if (!empty($meta['max']) && mb_strlen($value) > $meta['max']) {
            return '超過 ' . $meta['max'] . ' 個字';
        }

        if (!empty($meta['in']) && !in_array($value, $meta['in'], true)) {
            return $meta['message'] ?? ('只能填 ' . implode('、', $meta['in']));
        }

        if (!empty($meta['rule']) && !preg_match($meta['rule'], $value)) {
            return $meta['message'] ?? '格式不正確';
        }

        return null;
    }
}
