<?php

namespace App\Domain\Machine;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Logger;
use App\Support\ImportFile;

/**
 * 機台清單匯入 —— 商業邏輯。
 *
 * 流程刻意分成兩步：
 *
 *   1. preview()  解析檔案、逐列驗證、回傳前幾筆與所有錯誤
 *   2. commit()   使用者確認後才真的寫進資料庫
 *
 * 為什麼不一步做完：現場的檔案十次有三次是錯的（欄位少一欄、
 * 廠區打錯、編號重複）。一步寫入的話錯誤發生時資料已經進去一半，
 * 要人工回頭清；先預覽的話錯了就重傳，什麼都沒動到。
 */
class MachineImportService
{
    /** @var MachineImportRepository */
    private $repo;

    public function __construct(?MachineImportRepository $repo = null)
    {
        $this->repo = $repo ?: new MachineImportRepository();
    }

    /**
     * 檔案要有哪些欄位、怎麼驗。
     *
     * 這一份同時餵給三個地方：必要欄位檢查、逐列驗證、下載範本的表頭，
     * 所以「範本長什麼樣」跟「實際會驗什麼」永遠一致。
     */
    public static function columns(): array
    {
        return [
            'machine_id' => [
                'title'    => '機台編號',
                'required' => true,
                'rule'     => '/^[A-Za-z0-9\-_]{1,20}$/',
                'message'  => '只能是英數字、減號與底線，最多 20 碼',
                'sample'   => 'M-101',
            ],
            'machine_name' => [
                'title'    => '機台名稱',
                'required' => true,
                'max'      => 60,
                'sample'   => 'A 線 1 號機',
            ],
            'model' => [
                'title'    => '機型',
                'required' => false,
                'max'      => 40,
                'sample'   => 'CNC-500',
            ],
            'area' => [
                'title'    => '廠區',
                'required' => true,
                'in'       => ['A', 'B', 'C'],
                'message'  => '只能填 A、B 或 C',
                'sample'   => 'A',
            ],
        ];
    }

    /**
     * 解析並驗證，不寫入。
     *
     * @return array{
     *     total:int, valid:int, insert:int, update:int,
     *     preview:array, errors:array
     * }
     */
    public function preview(string $path): array
    {
        $columns = self::columns();

        $titles = array_map(function ($c) {
            return $c['title'];
        }, $columns);

        $required = [];
        foreach ($columns as $key => $meta) {
            if (!empty($meta['required'])) {
                $required[] = $meta['title'];
            }
        }

        $file = ImportFile::read($path, $required, (int) Config::get('app.import.max_rows', 5000));

        $rows   = [];
        $errors = [];
        $seen   = [];

        foreach ($file['rows'] as $raw) {
            $line = $raw['_line'];
            $row  = ['_line' => $line];
            $bad  = false;

            foreach ($columns as $key => $meta) {
                $value     = $raw[$meta['title']] ?? '';
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

            // 檔案自己內部重複：後面那筆會蓋掉前面那筆，使用者通常沒發現
            $id = $row['machine_id'];
            if ($id !== '') {
                if (isset($seen[$id])) {
                    $errors[] = [
                        'line'    => $line,
                        'column'  => '機台編號',
                        'value'   => $id,
                        'message' => '跟第 ' . $seen[$id] . ' 列重複',
                    ];
                    $bad = true;
                } else {
                    $seen[$id] = $line;
                }
            }

            if (!$bad) {
                $rows[] = $row;
            }
        }

        // 標示每一筆是新增還是更新，使用者按下確認前就知道會影響什麼
        $existing = $this->existingIds(array_column($rows, 'machine_id'));

        $insert = 0;
        $update = 0;

        foreach ($rows as $i => $row) {
            $isUpdate         = in_array($row['machine_id'], $existing, true);
            $rows[$i]['_act'] = $isUpdate ? 'update' : 'insert';

            $isUpdate ? $update++ : $insert++;
        }

        return [
            'total'   => $file['count'],
            'valid'   => count($rows),
            'insert'  => $insert,
            'update'  => $update,
            // 用 array_values：前端要對它做 .map()，帶著鍵送出去會變成物件
            'columns' => array_values($titles),
            'preview' => array_slice($rows, 0, 20),
            'errors'  => array_slice($errors, 0, 100),
            'errorTotal' => count($errors),
        ];
    }

    /**
     * 確認後真的寫入。
     *
     * 會重新解析並驗證一次。看起來像是重工，但這一步是必要的——
     * 預覽的結果放在使用者的瀏覽器裡，不能信任；
     * 而且從預覽到按下確認之間，資料庫可能已經被別人改過了。
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

        // preview 只回傳前 20 筆，寫入要拿全部，所以重新取一次
        $rows = $this->validRows($path);

        $written = $this->repo->upsertMany($rows);

        Logger::info('機台清單匯入完成', [
            'total'  => count($rows),
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
     * 全部通過驗證的列（commit 用）。
     */
    private function validRows(string $path): array
    {
        $columns  = self::columns();
        $required = [];

        foreach ($columns as $meta) {
            if (!empty($meta['required'])) {
                $required[] = $meta['title'];
            }
        }

        $file = ImportFile::read($path, $required, (int) Config::get('app.import.max_rows', 5000));
        $rows = [];

        foreach ($file['rows'] as $raw) {
            $row = [];

            foreach ($columns as $key => $meta) {
                $row[$key] = $raw[$meta['title']] ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 查已存在的編號。
     * 資料庫還沒接好時不要讓整個預覽掛掉，全部當成新增就好。
     */
    private function existingIds(array $ids): array
    {
        try {
            return $this->repo->existingIds($ids);
        } catch (\Throwable $e) {
            Logger::warning('比對既有機台編號失敗，預覽一律顯示為新增', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 單一欄位驗證。回傳 null 表示沒問題。
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
