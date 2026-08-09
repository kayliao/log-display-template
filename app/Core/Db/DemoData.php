<?php

namespace App\Core\Db;

/**
 * 示範資料。
 *
 * 讓這套模板在「還沒接上資料庫」的情況下也能完整展示——
 * 剛下載下來就能看到報表、平面圖、放大鏡彈窗長什麼樣子，
 * 也方便拿去給主管或同事看效果。
 *
 * ⚠ 接上真實資料庫後，請把 config/app.php 的 demo_mode 改成 false。
 *   開著的時候畫面上會一直有一條黃色提示列，不會不小心忘記關。
 *
 * 資料是用固定亂數種子產生的，每次重新整理內容都一樣，
 * 這樣截圖比對或做教育訓練時畫面才不會一直跳動。
 */
class DemoData
{
    /** @var array|null */
    private static $cache;

    /**
     * 依 SQL 裡出現的資料表名稱決定要回傳哪一組資料。
     */
    public static function forSql(string $sql): array
    {
        $sql = strtolower($sql);
        $all = self::all();

        foreach ($all as $table => $rows) {
            if (strpos($sql, $table) !== false) {
                return $rows;
            }
        }

        return [];
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = [
            'mes_machine_hourly' => self::hourly(),
            'mes_machine_shift'  => self::shifts(),
            'mes_machine_log'    => self::logs(),
            'mes_machine_daily'  => self::machines(),
            'mes_machine'        => self::machines(),
            'sys_announcement'   => self::announcements(),
        ];
    }

    /**
     * 機台主檔（同時供狀態總表與平面圖使用）。
     */
    public static function machines(): array
    {
        mt_srand(20260806);   // 固定種子，畫面每次都一樣

        $models   = ['CNC-500', 'CNC-800', 'LATHE-200', 'MILL-350', 'EDM-120', 'GRIND-90'];
        $areas    = ['A', 'B', 'C'];
        $statuses = ['RUN', 'RUN', 'RUN', 'RUN', 'IDLE', 'IDLE', 'DOWN', 'ALARM', 'OFF'];

        /**
         * 廠區在哪一層樓。
         * 分頁版平面圖（/pages/machine/map_floors.php）用這個欄位切換 2F / 4F。
         * 每一層佔用不同的橫軸範圍，所以兩層的座標不會重疊，
         * 「全部樓層畫在同一張圖」的頁面也還是正確的。
         */
        $floors = ['A' => '2F', 'B' => '2F', 'C' => '4F'];

        $rows = [];
        $n    = 0;

        /**
         * 依廠區配置在平面圖上：
         *   橫軸 A~L，每個廠區佔 4 欄（A 區 A~D、B 區 E~H、C 區 I~L）
         *   縱軸只放在 1、3、5、7，中間留空當走道
         *
         * 欄位游標會依機台寬度前進，所以寬 2 格的機台不會跟隔壁重疊——
         * 真實資料若有重疊代表現場座標登錄錯了，畫面上會直接看得出來。
         */
        foreach ($areas as $ai => $area) {
            for ($row = 1; $row <= 4; $row++) {
                $col = 0;

                while ($col < 4) {
                    $n++;

                    // 每個廠區的第二排放一台雙倍寬的機台，用來展示長寬效果
                    $w = ($row === 2 && $col === 1) ? 2 : 1;
                    if ($col + $w > 4) {
                        $w = 1;
                    }

                    // 最後一排放一台雙倍高的機台
                    $h = ($row === 4 && $col === 0) ? 2 : 1;

                    $status = $statuses[mt_rand(0, count($statuses) - 1)];
                    $run    = $status === 'RUN' ? mt_rand(600, 840) : mt_rand(120, 500);
                    $idle   = mt_rand(60, 300);
                    $down   = $status === 'DOWN' || $status === 'ALARM' ? mt_rand(120, 400) : mt_rand(0, 90);
                    $total  = $run + $idle + $down;

                    $qtyOk = $status === 'OFF' ? 0 : mt_rand(180, 1450);
                    $qtyNg = $qtyOk > 0 ? mt_rand(0, (int) round($qtyOk * 0.05)) : 0;

                    $rows[] = [
                        'machine_id'       => sprintf('M-%03d', $n),
                        'machine_name'     => $area . ' 線 ' . $row . '-' . ($col + 1) . ' 號機',
                        'model'            => $models[($n + $ai) % count($models)],
                        'area'             => $area,
                        'floor'            => $floors[$area],
                        'status'           => $status,
                        'maker'            => ['台中精機', 'MAZAK', 'FANUC', '友嘉'][$n % 4],
                        'install_date'     => date('Y-m-d', strtotime('-' . mt_rand(400, 2600) . ' days')),
                        'remark'           => '',
                        'last_report_time' => date('Y-m-d H:i:s', time() - mt_rand(10, 900)),

                        // 平面圖用：x 是橫軸代號、y 是縱軸號碼、w/h 是佔幾格
                        'x' => chr(ord('A') + $ai * 4 + $col),
                        'y' => $row * 2 - 1,
                        'w' => $w,
                        'h' => $h,

                        // 今日統計
                        'run_minutes'  => $run,
                        'idle_minutes' => $idle,
                        'down_minutes' => $down,
                        'qty_ok'       => $qtyOk,
                        'qty_ng'       => $qtyNg,
                        'oee'          => $total > 0 ? round($run * 100 / $total, 1) : 0,
                    ];

                    $col += $w;
                }
            }
        }

        return $rows;
    }

    /**
     * 班別產量（三層表頭的範例報表用）。
     *
     * 一台機器一列，白班與夜班各自有良品／不良／稼動率，
     * 對應到表頭的「今日產量 → 白班 → 良品」這種三層結構。
     */
    public static function shifts(): array
    {
        mt_srand(20260809);

        $rows = [];

        foreach (self::machines() as $machine) {
            $shift = [];

            foreach (['d' => '白班', 'n' => '夜班'] as $prefix => $ignored) {
                $ok  = $machine['status'] === 'OFF' ? 0 : mt_rand(120, 780);
                $ng  = $ok > 0 ? mt_rand(0, (int) round($ok * 0.06)) : 0;
                $run = mt_rand(300, 690);

                $shift[$prefix . '_ok']  = $ok;
                $shift[$prefix . '_ng']  = $ng;
                $shift[$prefix . '_oee'] = round($run * 100 / 720, 1);
            }

            $rows[] = array_merge([
                'machine_id'   => $machine['machine_id'],
                'machine_name' => $machine['machine_name'],
                'area'         => $machine['area'],
                'model'        => $machine['model'],
            ], $shift, [
                'total_ok' => $shift['d_ok'] + $shift['n_ok'],
                'total_ng' => $shift['d_ng'] + $shift['n_ng'],
            ]);
        }

        return $rows;
    }

    /**
     * 機台 Log。
     */
    public static function logs(): array
    {
        mt_srand(20260807);

        $events = [
            ['ALARM', 'AL-102', '主軸溫度過高'],
            ['ALARM', 'AL-217', '刀具磨耗超出容許值'],
            ['ERROR', 'ER-004', '伺服軸定位逾時'],
            ['ERROR', 'ER-011', '氣壓不足'],
            ['WARN',  'WN-330', '冷卻液液位偏低'],
            ['WARN',  'WN-118', '加工時間高於標準工時'],
            ['INFO',  'IN-001', '程式載入完成'],
            ['INFO',  'IN-007', '批次生產開始'],
            ['OP',    'OP-020', '操作員手動暫停'],
            ['OP',    'OP-021', '操作員解除暫停'],
        ];

        $operators = ['E0012 林志明', 'E0087 陳美玲', 'E0154 張家豪', 'E0203 王淑芬', ''];

        $rows = [];
        $time = time();

        for ($i = 0; $i < 260; $i++) {
            $e = $events[mt_rand(0, count($events) - 1)];
            $time -= mt_rand(60, 900);

            $rows[] = [
                'log_id'       => 100000 + $i,
                'machine_id'   => sprintf('M-%03d', mt_rand(1, 48)),
                'log_time'     => date('Y-m-d H:i:s', $time),
                'event_type'   => $e[0],
                'event_code'   => $e[1],
                'message'      => $e[2],
                'operator'     => $operators[mt_rand(0, count($operators) - 1)],
                'duration_sec' => in_array($e[0], ['ALARM', 'ERROR'], true) ? mt_rand(15, 1800) : null,
                'cnt'          => 0,
            ];
        }

        return $rows;
    }

    /**
     * 單一機台的分時稼動（放大鏡彈窗第二段）。
     */
    public static function hourly(): array
    {
        mt_srand(20260808);

        $rows = [];

        for ($h = 8; $h <= 20; $h++) {
            $run  = mt_rand(30, 60);
            $idle = mt_rand(0, 60 - $run);

            $rows[] = [
                'hour_label'   => sprintf('%02d:00', $h),
                'run_minutes'  => $run,
                'idle_minutes' => $idle,
                'down_minutes' => 60 - $run - $idle,
                'qty_ok'       => mt_rand(20, 120),
                'qty_ng'       => mt_rand(0, 6),
            ];
        }

        return $rows;
    }

    /**
     * 首頁公告。
     */
    public static function announcements(): array
    {
        return [
            [
                'id'      => 1,
                'level'   => 'warning',
                'title'   => '系統維護通知',
                'content' => '本週六 22:00 起進行資料庫例行維護，預計停機兩小時，屆時查詢功能將暫停使用。',
                'date'    => date('Y-m-d'),
            ],
            [
                'id'      => 2,
                'level'   => 'info',
                'title'   => '新功能上線',
                'content' => '機台 Log 查詢新增「類型統計」頁籤，可快速查看各類事件的發生次數分佈。',
                'date'    => date('Y-m-d', strtotime('-2 days')),
            ],
            [
                'id'      => 3,
                'level'   => 'danger',
                'title'   => 'B 線異常提醒',
                'content' => 'B 線 M-021 今日警報次數異常偏高，請設備課派員檢查主軸冷卻系統。',
                'date'    => date('Y-m-d', strtotime('-1 day')),
            ],
        ];
    }
}
