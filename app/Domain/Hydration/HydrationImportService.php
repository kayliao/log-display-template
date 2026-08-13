<?php

namespace App\Domain\Hydration;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Logger;
use App\Support\Csv;
use App\Support\DateInput;

/**
 * 水化排程匯入 —— 商業邏輯。
 *
 * 跟其他兩支匯入（機台清單、排程實績）最大的差別：
 * **有問題的那幾列不會擋住整批**。
 *
 * 現場一次貼上百來列，其中兩列順序填錯就整批退回的話，
 * 使用者只能一直重傳；所以這裡的做法是「能寫的先寫進去，
 * 寫不進去的最後用彈窗列出來」，修好那幾列再傳一次就好。
 *
 * ── 每一列的判斷規則 ──────────────────────────────────────────
 *
 *   這個乾片批號還沒有任何水化紀錄
 *       第幾次水化必須是 1              → 新增
 *
 *   已經有「同一次水化」的紀錄
 *       那一次還沒取號                   → 更新（upsert，數量／日期／封包日編碼覆蓋）
 *       那一次已經有封包批號             → 失敗：不可以覆蓋已經發出去的號
 *
 *   沒有「同一次水化」的紀錄
 *       不是接在最後一次後面             → 失敗：順序不對（要連號、不可以跳號）
 *       是接在後面，但前一次還沒取號     → 失敗：前一次還沒取號，不能開始下一次
 *       是接在後面且前一次已取號         → 新增
 *
 * 同一個檔案裡重複的 (乾片批號, 第幾次水化) 也會被抓出來 ——
 * 後面那筆會蓋掉前面那筆，使用者通常沒發現。
 *
 * ⚠ 「已經取號」= PACKET_LOT_TEMP_AUTO 有值。封包批號一旦發給機台就不能反悔，
 *   所以那一列的內容也不能再被匯入覆蓋掉。
 */
class HydrationImportService
{
    /** @var HydrationImportRepository */
    private $repo;

    /** @var HydrationRepository */
    private $query;

    public function __construct(?HydrationImportRepository $repo = null, ?HydrationRepository $query = null)
    {
        $this->repo  = $repo  ?: new HydrationImportRepository();
        $this->query = $query ?: new HydrationRepository();
    }

    /**
     * 檔案要有哪些欄位、怎麼驗。
     *
     * 封包批號不在檔案裡：它是機台來要號時由系統產生的，不是現場填的。
     *
     * 陣列的鍵就是資料表的欄位名，title 是現場在 Excel 上看到的標題。
     */
    public static function columns(): array
    {
        return [
            // normalize 讓 2026/08/13、20260813、Excel 日期序號都收得進來，
            // 寫進資料庫的一律是 YYYY-MM-DD。細節見 Support/DateInput。
            'aqua_schedule_date' => [
                'title'     => '水化日期',
                'required'  => true,
                'normalize' => 'date',
                'message'   => DateInput::MESSAGE,
                'sample'    => date('Y-m-d'),
            ],
            'qty' => [
                'title'    => '數量',
                'required' => true,
                'rule'     => '/^[1-9]\d{0,8}$/',
                'message'  => '只能填 1 以上的整數',
                'sample'   => '1200',
            ],
            'ppcup_lot' => [
                'title'    => '乾片批號',
                'required' => true,
                'rule'     => '/^[A-Za-z0-9\-_]{6,100}$/',
                'message'  => '只能是英數字、減號與底線，6 到 100 碼',
                'sample'   => 'PPCUP-A2408-10001',
            ],
            'packet_schedule_date_code' => [
                'title'    => '封包日編碼',
                'required' => true,
                'rule'     => '/^[A-Za-z0-9]{1,100}$/',
                'message'  => '只能是英數字',
                'sample'   => 'H' . date('md'),
            ],
            'aqua_cycle_num' => [
                'title'    => '第幾次水化',
                'required' => true,
                'rule'     => '/^\d{1,2}$/',
                'message'  => '只能填 1 到 99 的整數',
                'sample'   => '1',
            ],
            // 選填。現場常要註記「補跑」「重工」這種原因。
            // 沒填就是 NULL（Oracle 把空字串當 NULL 存，兩者沒有差別）。
            'note' => [
                'title'    => '備註',
                'required' => false,
                'max'      => 500,
                'sample'   => '',
            ],
        ];
    }

    /**
     * 解析並驗證，不寫入。
     */
    public function preview(string $path): array
    {
        $checked = $this->check($path);

        $insert = 0;
        $update = 0;

        foreach ($checked['rows'] as $row) {
            $row['_act'] === 'update' ? $update++ : $insert++;
        }

        $titles = [];
        foreach (self::columns() as $meta) {
            $titles[] = $meta['title'];
        }

        return [
            'total'      => $checked['total'],
            'valid'      => count($checked['rows']),
            'insert'     => $insert,
            'update'     => $update,
            'columns'    => $titles,
            'preview'    => array_slice($checked['rows'], 0, 20),
            'errors'     => array_slice($checked['errors'], 0, 100),
            'errorTotal' => count($checked['errors']),
        ];
    }

    /**
     * 確認匯入：能寫的寫進去，寫不進去的回報。
     *
     * 會重新解析驗證一次 —— 預覽的結果放在使用者的瀏覽器裡，不能信任；
     * 而且從預覽到按下確認之間，機台可能已經來要過某一列的號了。
     *
     * @param string $updateUser 寫進 UPDATE_USER 的名字。
     *                           頁面上傳時是登入者姓名，由入口檔（API）傳進來 ——
     *                           Domain 這一層不去碰 Session。
     */
    public function commit(string $path, string $updateUser): array
    {
        $updateUser = mb_substr(trim($updateUser), 0, 100);

        if ($updateUser === '') {
            throw new AppException('取不到操作人員名稱，請重新登入後再試。', 422);
        }

        $checked = $this->check($path);

        if ($checked['total'] === 0) {
            throw new AppException('檔案裡沒有可以匯入的資料。');
        }

        $errors = $checked['errors'];
        $insert = 0;
        $update = 0;

        /**
         * 通過檢查的列包在同一個交易裡。
         *
         * Oracle 的單句失敗只會回滾那一句、交易本身還活著，
         * 所以這裡可以「一列一列 try」，最後一起 COMMIT：
         * 成功的那些是一次進去的，不會出現寫到一半的中間狀態。
         */
        $this->repo->conn()->transaction(function () use ($checked, $updateUser, &$errors, &$insert, &$update) {
            foreach ($checked['rows'] as $row) {
                try {
                    $affected = $this->repo->upsert([
                        'aqua_schedule_date'      => $row['aqua_schedule_date'],
                        'qty'                     => (int) $row['qty'],
                        'ppcup_lot'               => $row['ppcup_lot'],
                        'packet_schedule_date_code' => $row['packet_schedule_date_code'],
                        'aqua_cycle_num'          => (int) $row['aqua_cycle_num'],

                        // 空字串在 Oracle 就是 NULL，這裡明確轉成 null 讓兩種資料庫一致
                        'note'                    => $row['note'] === '' ? null : $row['note'],
                        'update_user'             => $updateUser,
                    ]);

                    // 0 列 = 這一列剛剛被機台取號了（MERGE 的 WHERE 擋下來）
                    if ($affected === 0 && $row['_act'] === 'update') {
                        $errors[] = self::error($row, '這一次水化剛剛被機台取號了，資料沒有更新');
                        continue;
                    }

                    $row['_act'] === 'update' ? $update++ : $insert++;
                } catch (\Throwable $e) {
                    Logger::warning('水化匯入單列失敗', [
                        'line'  => $row['_line'],
                        'lot'   => $row['ppcup_lot'],
                        'error' => $e->getMessage(),
                    ]);

                    // 幾乎都是唯一鍵衝突（有人同時在寫同一列）。
                    // 訊息不要把 ORA-00001 丟給現場看，那沒有意義。
                    $errors[] = self::error($row, '寫入時被資料庫擋下來，請重新查詢確認目前狀態');
                }
            }
        });

        Logger::info('水化匯入完成', [
            'total'  => $checked['total'],
            'insert' => $insert,
            'update' => $update,
            'failed' => count($errors),
            'user'   => $updateUser,
        ]);

        return [
            'written' => $insert + $update,
            'insert'  => $insert,
            'update'  => $update,
            'failed'  => count($errors),
            'total'   => $checked['total'],

            // 直接回一份 app.modal.js 吃得下的結構，前端不用組畫面
            'report'  => self::report($checked['total'], $insert, $update, $errors),
        ];
    }

    /**
     * 解析 + 逐列判斷。preview 與 commit 共用，兩邊的規則不會分岔。
     *
     * @return array{total:int, rows:array, errors:array}
     */
    private function check(string $path): array
    {
        $columns  = self::columns();
        $required = [];

        foreach ($columns as $meta) {
            if (!empty($meta['required'])) {
                $required[] = $meta['title'];
            }
        }

        $file = Csv::read($path, $required, (int) Config::get('app.import.max_rows', 5000));

        // --- 先把檔案讀成乾淨的列，順便做欄位層級的檢查 ---
        $parsed = [];
        $errors = [];

        foreach ($file['rows'] as $raw) {
            $row = ['_line' => $raw['_line']];

            // 先把整列讀進來，再驗 —— 這樣錯誤訊息才帶得出「是哪個乾片批號」，
            // 結果視窗上只有列號的話，使用者還要自己回去對檔案
            foreach ($columns as $key => $meta) {
                $row[$key] = trim((string) ($raw[$meta['title']] ?? ''));
            }

            // 日期欄先轉成 YYYY-MM-DD 再驗，後面用到的就都是同一種寫法；
            // 轉不出來的原樣留著，由 validateCell 擋（訊息才看得到使用者填了什麼）
            $row = DateInput::applyTo($row, $columns);

            $bad = false;

            foreach ($columns as $key => $meta) {
                $problem = self::validateCell($row[$key], $meta);

                if ($problem !== null) {
                    $errors[] = array_merge(
                        self::error($row, $problem),
                        ['column' => $meta['title'], 'value' => $row[$key]]
                    );
                    $bad = true;
                }
            }

            if (!$bad) {
                $row['ppcup_lot']               = strtoupper($row['ppcup_lot']);
                $row['packet_schedule_date_code'] = strtoupper($row['packet_schedule_date_code']);
                $parsed[] = $row;
            }
        }

        /**
         * 一次把檔案用到的乾片批號全部查回來。
         * 一列查一次的話，五百列就是五百次來回，現場會覺得「按了沒反應」。
         */
        $states = $this->query->lotStates(array_values(array_unique(array_column($parsed, 'ppcup_lot'))));

        // --- 逐列套業務規則 ---
        $rows = [];
        $seen = [];

        foreach ($parsed as $row) {
            $lot = $row['ppcup_lot'];
            $num = (int) $row['aqua_cycle_num'];
            $key = $lot . '#' . $num;

            // 同一個檔案裡重複
            if (isset($seen[$key])) {
                $errors[] = self::error($row, '跟第 ' . $seen[$key] . ' 列重複（同一個乾片批號的同一次水化）');
                continue;
            }

            $state   = $states[$lot] ?? [];
            $problem = self::judge($state, $num);

            if ($problem !== null) {
                $errors[] = self::error($row, $problem);
                continue;
            }

            $row['_act'] = isset($state[$num]) ? 'update' : 'insert';
            $seen[$key]  = $row['_line'];

            // 讓同一個檔案後面的列看得到前面幾列造成的狀態改變
            $states[$lot][$num] = [
                'aqua_cycle_num'       => $num,
                'packet_lot_temp_auto' => $state[$num]['packet_lot_temp_auto'] ?? null,
            ];

            $rows[] = $row;
        }

        return [
            'total'  => $file['count'],
            'rows'   => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * 業務規則的判斷。回傳 null 表示這一列可以寫。
     *
     * 規則的完整表格寫在類別開頭的註解。訊息一律要講「所以我該填什麼」，
     * 只說「順序錯誤」的話現場還是不知道要改成幾。
     *
     * @param array $state 這個乾片批號目前的狀態：第幾次 => ['packet_lot_temp_auto' => ...]
     */
    private static function judge(array $state, int $num): ?string
    {
        if ($state === []) {
            return $num === 1
                ? null
                : '這個乾片批號還沒有水化紀錄，第一次必須填 1（目前填的是 ' . $num . '）';
        }

        // 已經有這一次的紀錄
        if (isset($state[$num])) {
            $packet = $state[$num]['packet_lot_temp_auto'] ?? null;

            if ($packet !== null && $packet !== '') {
                $next = max(array_keys($state)) + 1;

                return '第 ' . $num . ' 次水化已經取號（' . $packet . '），不可以覆蓋；'
                     . '如果這是新的一次水化，請改填 ' . $next;
            }

            return null;   // 還沒取號 => 直接覆蓋
        }

        $maxNum = max(array_keys($state));

        if ($num !== $maxNum + 1) {
            return '順序不對：這個乾片批號目前已經到第 ' . $maxNum . ' 次，'
                 . '這一筆必須填 ' . ($maxNum + 1) . '（目前填的是 ' . $num . '）';
        }

        $lastPacket = $state[$maxNum]['packet_lot_temp_auto'] ?? null;

        if ($lastPacket === null || $lastPacket === '') {
            return '第 ' . $maxNum . ' 次水化還沒有封包批號，不能開始第 ' . $num . ' 次；'
                 . '要更正第 ' . $maxNum . ' 次的話請把「第幾次水化」填 ' . $maxNum;
        }

        return null;
    }

    /**
     * 匯入結果彈窗。
     *
     * 部分成功用一行 toast 是講不清楚的：使用者要知道「哪幾列沒進去、為什麼」，
     * 而且要能直接照著這張表回去改檔案。
     */
    private static function report(int $total, int $insert, int $update, array $errors): array
    {
        $failed   = count($errors);
        $sections = [];

        $sections[] = [
            'type'    => 'fields',
            'title'   => '匯入結果',
            'columns' => 2,
            'fields'  => [
                ['label' => '檔案列數', 'value' => $total . ' 列'],
                ['label' => '寫入成功', 'value' => ($insert + $update) . ' 筆',
                 'badge' => $failed === 0 ? ['label' => '全部成功', 'tone' => 'success'] : null],
                ['label' => '新增',     'value' => $insert . ' 筆'],
                ['label' => '更新',     'value' => $update . ' 筆'],
                ['label' => '略過',     'value' => $failed . ' 筆',
                 'badge' => $failed > 0 ? ['label' => '需要修正', 'tone' => 'danger'] : null],
            ],
        ];

        if ($failed > 0) {
            $sections[] = [
                'type'    => 'table',
                'title'   => '沒有寫入的資料（修正後只要重傳這幾列就好）',
                'columns' => [
                    ['key' => 'line',           'title' => '第幾列', 'align' => 'center'],
                    ['key' => 'ppcup_lot',      'title' => '乾片批號'],
                    ['key' => 'aqua_cycle_num', 'title' => '第幾次水化', 'align' => 'center'],
                    ['key' => 'message',        'title' => '原因'],
                ],
                'rows'    => array_slice($errors, 0, 200),
            ];

            $sections[] = [
                'type' => 'html',
                'html' => '<p class="app-panel__hint">這幾列完全沒有寫入，'
                        . '其餘資料已經進去了。修好之後不用整份重傳，只留下這幾列即可。</p>',
            ];
        }

        return [
            'title'    => $failed === 0 ? '匯入完成' : '匯入完成（有 ' . $failed . ' 筆沒進去）',
            'sections' => $sections,
        ];
    }

    /**
     * 業務規則的錯誤。欄位固定指到「第幾次水化」，因為那是使用者要改的地方。
     */
    private static function error(array $row, string $message): array
    {
        return [
            'line'           => $row['_line'],
            'column'         => '第幾次水化',
            'value'          => $row['aqua_cycle_num'] ?? '',
            'ppcup_lot'      => $row['ppcup_lot'] ?? '',
            'aqua_cycle_num' => $row['aqua_cycle_num'] ?? '',
            'message'        => $message,
        ];
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

        // 日期不是用正規表示式驗的：^\d{4}-\d{2}-\d{2}$ 會放行 2026-02-30，
        // 那種列要到 Oracle 的 TO_DATE 才炸，現場只會看到「被資料庫擋下來」。
        // 訊息用 problem() 回的那一句（會指出是哪裡不對），不是欄位定義裡的通則說明
        if (($meta['normalize'] ?? '') === 'date') {
            $problem = DateInput::problem($value);

            if ($problem !== null) {
                return $problem;
            }
        }

        if (!empty($meta['rule']) && !preg_match($meta['rule'], $value)) {
            return $meta['message'] ?? '格式不正確';
        }

        return null;
    }
}
