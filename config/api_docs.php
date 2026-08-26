<?php
/**
 * 對外 API 說明書 —— 唯一來源。
 *
 * public/service/v1/ 底下每一支端點的用法都寫在這裡，畫面與匯出檔都讀這一份：
 *
 *   /pages/dev/api_docs.php              線上看（要登入、要 dev.api_docs 權限）
 *   /api/dev/api_doc_export.php          匯出單頁 HTML，拿去給沒有帳號的人
 *
 * 【改了端點就要回來改這裡。】原本用法散在三個地方——端點檔開頭的註解、
 * README、config/menu.php 的 note——三邊遲早會各說各話（寫這一份的時候就抓到
 * 一個：machine-log 的註解寫回傳有 failed 欄，實際上沒有）。現在改成一份設定
 * 餵所有出口，改一次到處都對。
 *
 * ── 每一支端點可以寫的欄位 ──────────────────────────────────
 *
 *   title      功能名稱（給人看的，不是路徑）
 *   method     HTTP 方法
 *   path       網址路徑，以 / 開頭
 *   caller     誰會呼叫這一支（廠商拿到說明書時第一個想確認的事）
 *   summary    一句話講清楚這支在做什麼
 *   batch      多筆送出的規則：max 上限、note 交易行為
 *   fields     請求欄位表：name / type / required / example / desc
 *   request        單筆請求範例（PHP 陣列，輸出時轉成 JSON）
 *   request_multi  多筆請求範例，沒有就不顯示
 *   response       回應範例（PHP 陣列）
 *   response_fields 回應欄位表，格式同 fields（不需要就省略）
 *   status     HTTP 狀態碼 => 意思
 *   notes      機台端／廠商端一定要知道的事，一則一句
 *
 * 範例寫成 PHP 陣列而不是手打的 JSON 字串：手打的 JSON 少一個逗號就是壞的，
 * 而且沒有人會發現——它只是一段字串，不會有任何東西去驗證它。
 * 寫成陣列的話由 json_encode 產生，永遠是合法 JSON，縮排也一致。
 */

return [
    // 說明書封面
    'title'   => '對外 API 介接說明',
    'contact' => '資訊課',

    /**
     * 給廠商看的系統網址（含 http://，結尾不要斜線）。
     *
     * 留空 = 用「目前正在瀏覽的網址」推算。自己在畫面上看沒問題，但**匯出給
     * 廠商之前請把現場真正的網址填在這裡**：你開發時開的多半是 localhost 或
     * 測試機，直接匯出去對方會拿到一份打不通的網址。
     */
    'server' => '',

    /**
     * 共通規則。每一支端點都適用的事寫在這裡，不要每支重複一遍。
     *
     *   title  區塊標題
     *   body   段落（一個元素一段）
     *   code   要一起顯示的程式碼區塊，沒有就省略
     */
    'common' => [
        [
            'title' => '驗證方式',
            'body'  => [
                '每一次呼叫都要帶 X-Api-Key 標頭，金鑰由本系統的資訊人員提供，'
                . '一個呼叫端一把。金鑰同時也是「這筆資料是誰塞的」的依據，'
                . '請不要兩個系統共用同一把。',
                '另外可能會有來源 IP 白名單。介接前請先告知你們的出口 IP，'
                . '沒有在名單上會直接收到 403。',
            ],
            'code'  => "X-Api-Key: <你的金鑰>\nContent-Type: application/json; charset=utf-8",
        ],
        [
            'title' => '回應格式',
            'body'  => [
                '不論成功或失敗，回應都是同一個信封格式，差別只在 ok 與 HTTP 狀態碼。'
                . '真正的內容在 data 裡面。',
                'message 是可以直接顯示在機台畫面上的中文。trace_id 對應本系統的'
                . '日誌記錄，回報問題時請一併提供這一串，資訊人員才查得到那一次呼叫。',
            ],
            'code'  => "{\n"
                     . "    \"ok\": true,\n"
                     . "    \"code\": 0,\n"
                     . "    \"message\": \"寫入成功\",\n"
                     . "    \"data\": { },\n"
                     . "    \"trace_id\": \"a1b2c3d4e5f6\"\n"
                     . "}",
        ],
        [
            'title' => '單筆與多筆',
            'body'  => [
                '每一支都同時吃「單筆」與「多筆」兩種寫法：單筆就是直接把欄位放在最外層，'
                . '多筆是包成 items 陣列。兩種寫法的欄位名稱完全一樣。',
                '筆數上限各支不同（見各支的說明），超過上限或什麼都沒帶都會回 422，'
                . '而且不會碰到資料庫——也就是說，被擋下來的請求不會寫進去一半。',
            ],
            'code'  => "單筆：{ \"machine_id\": \"M-101\", ... }\n"
                     . "多筆：{ \"items\": [ { ... }, { ... } ] }",
        ],
        [
            'title' => '重試與逾時',
            'body'  => [
                '收到 5xx 或連線逾時的時候可以重送，但**重送的行為每一支不一樣**，'
                . '請看該支說明裡的「注意事項」：取封包批號那支重送會拿到同一個號碼，'
                . '寫入 Log 那支重送則會多一筆記錄。',
                '收到 4xx 不要無限重試——那是請求本身有問題，重送幾次都一樣，'
                . '請照 message 修正後再送。',
            ],
        ],
    ],

    'endpoints' => [

        /**
         * ─────────────────────────────────────────────────────
         * 寫入機台 Log
         * 對應 public/service/v1/machine-log.php
         * ─────────────────────────────────────────────────────
         */
        'machine_log' => [
            'title'   => '寫入機台 Log',
            'method'  => 'POST',
            'path'    => '/service/v1/machine-log.php',
            'caller'  => 'MES / SCADA / PLC 收集器',
            'summary' => '把機台的事件（警報、狀態變更、操作記錄）寫進本系統，'
                       . '寫進來的資料會出現在「Log 查詢 → 機台 Log 查詢」頁面。',

            'batch' => [
                'max'  => 500,
                'note' => '多筆是**整批交易**：其中一筆失敗就全部回滾，不會留下半套資料，'
                        . '所以整批重送是安全的（但成功的那批不要重送，見注意事項）。',
            ],

            'fields' => [
                [
                    'name'     => 'machine_id',
                    'type'     => 'string',
                    'required' => true,
                    'example'  => 'M-101',
                    'desc'     => '機台編號。必須是本系統機台主檔裡有的編號。',
                ],
                [
                    'name'     => 'log_time',
                    'type'     => 'string',
                    'required' => false,
                    'example'  => '2026-08-17 13:45:00',
                    'desc'     => '事件發生時間，格式固定 YYYY-MM-DD HH:MM:SS。'
                                . '格式不對會整批退回 422。不給的話用本系統收到的時間，'
                                . '但補送舊資料時請務必自己帶，否則時間會全部變成補送當下。',
                ],
                [
                    'name'     => 'event_code',
                    'type'     => 'string(50)',
                    'required' => false,
                    'example'  => 'AL-102',
                    'desc'     => '事件代碼，你們自己的代碼即可，本系統不做對照。',
                ],
                [
                    'name'     => 'event_type',
                    'type'     => 'string(20)',
                    'required' => false,
                    'example'  => 'ALARM',
                    'desc'     => '事件類型。查詢頁的下拉選單認得這五種：'
                                . 'ALARM（警報）、ERROR（錯誤）、WARN（警告）、INFO（一般）、OP（操作）。'
                                . '不給就是 INFO；給了其他值也會寫進去，只是查詢時要用關鍵字找。',
                ],
                [
                    'name'     => 'message',
                    'type'     => 'string(1000)',
                    'required' => false,
                    'example'  => '主軸溫度過高',
                    'desc'     => '事件內容。超過 1000 字會被截掉，不會報錯。',
                ],
                [
                    'name'     => 'operator',
                    'type'     => 'string(50)',
                    'required' => false,
                    'example'  => 'E0012',
                    'desc'     => '操作人員工號或姓名。',
                ],
                [
                    'name'     => 'duration_sec',
                    'type'     => 'int',
                    'required' => false,
                    'example'  => 45,
                    'desc'     => '持續秒數（停機、警報持續時間這類）。',
                ],
            ],

            'request' => [
                'machine_id'   => 'M-101',
                'log_time'     => '2026-08-17 13:45:00',
                'event_code'   => 'AL-102',
                'event_type'   => 'ALARM',
                'message'      => '主軸溫度過高',
                'operator'     => 'E0012',
                'duration_sec' => 45,
            ],

            'request_multi' => [
                'items' => [
                    [
                        'machine_id' => 'M-101',
                        'log_time'   => '2026-08-17 13:45:00',
                        'event_code' => 'AL-102',
                        'event_type' => 'ALARM',
                        'message'    => '主軸溫度過高',
                    ],
                    [
                        'machine_id' => 'M-101',
                        'log_time'   => '2026-08-17 13:47:20',
                        'event_code' => 'AL-102',
                        'event_type' => 'INFO',
                        'message'    => '溫度回復正常',
                    ],
                ],
            ],

            'response' => [
                'ok'       => true,
                'code'     => 0,
                'message'  => '寫入成功',
                'data'     => ['inserted' => 2],
                'trace_id' => 'a1b2c3d4e5f6',
            ],

            'response_fields' => [
                [
                    'name'    => 'data.inserted',
                    'type'    => 'int',
                    'example' => 2,
                    'desc'    => '實際寫進去的筆數。因為是整批交易，這個數字要嘛等於你送的筆數，'
                               . '要嘛整批失敗（不會出現寫一半的中間值）。',
                ],
            ],

            'status' => [
                200 => '全部寫入成功。',
                401 => '沒帶 X-Api-Key，或金鑰不正確。',
                403 => '來源 IP 不在白名單內。',
                405 => '用了 POST 以外的方法。',
                422 => '請求內容有問題：什麼都沒帶、超過 500 筆、machine_id 是空的、'
                     . 'log_time 格式不對。message 會指出是第幾筆，照著修就好。',
                500 => '本系統寫入失敗，整批已回滾，可以稍後重送。'
                     . '詳細原因在本系統的日誌裡，請提供 trace_id。',
            ],

            'notes' => [
                '**沒有去重**。同一筆資料送兩次就會有兩筆記錄——重送前請先確認上一次'
                . '是真的失敗（收到 5xx 或連線逾時），收到 200 就不要再送。',
                '**一次一批、不要一筆一次**。500 筆分成 500 次呼叫，資料庫的負擔差很多。',
                '整批交易的意思是「有一筆爛的，好的那 499 筆也不會進去」。'
                . '這是刻意的：資料寧可全部沒進去也不要進去一半，重送才好處理。',
            ],
        ],

        /**
         * ─────────────────────────────────────────────────────
         * 取封包批號
         * 對應 public/service/v1/packet-lot.php
         * ─────────────────────────────────────────────────────
         */
        'packet_lot' => [
            'title'   => '取封包批號',
            'method'  => 'POST',
            'path'    => '/service/v1/packet-lot.php',
            'caller'  => '水化機台',
            'summary' => '機台拿著乾片批號（ppcup_lot）來要一個封包批號，'
                       . '本系統產生號碼、寫回那一批的水化排程列，再把號碼回給機台。',

            'batch' => [
                'max'  => 50,
                'note' => '多筆是**一筆一交易**：其中一筆失敗不影響其他筆，'
                        . '失敗的那幾筆會列在 data.failed 裡。'
                        . '上限壓得比機台 Log（500 筆）低很多，是因為取號會鎖住'
                        . '「當日順序」那一列，一次進來太多筆會把鎖持有太久，其他機台就得排隊。',
            ],

            'fields' => [
                [
                    'name'     => 'ppcup_lot',
                    'type'     => 'string',
                    'required' => true,
                    'example'  => 'PPCUP-A2408-10001',
                    'desc'     => '乾片批號。會自動轉大寫。這個批號必須已經匯入水化排程，'
                                . '否則回 404。',
                ],
                [
                    'name'     => 'update_user',
                    'type'     => 'string(100)',
                    'required' => false,
                    'example'  => 'AQUA-M03',
                    'desc'     => '**機台名稱**，會寫進那一列的 UPDATE_USER 欄，'
                                . '用來追這個號是哪一台要走的。'
                                . '放在最外層代表整批共用，也可以放在 items 裡面那一層各自指定。'
                                . '不給就用你的 API 金鑰對應的呼叫端代號。',
                ],
            ],

            'request' => [
                'ppcup_lot'   => 'PPCUP-A2408-10001',
                'update_user' => 'AQUA-M03',
            ],

            'request_multi' => [
                'update_user' => 'AQUA-M03',
                'items'       => [
                    ['ppcup_lot' => 'PPCUP-A2408-10001'],
                    ['ppcup_lot' => 'PPCUP-A2408-10002'],
                ],
            ],

            'response' => [
                'ok'      => true,
                'message' => '取號成功',
                'data'    => [
                    'results' => [
                        [
                            'ppcup_lot'                 => 'PPCUP-A2408-10001',
                            'aqua_cycle_num'            => 2,
                            'packet_schedule_date_code' => 'H0812',
                            'packet_lot_temp_auto'      => 'PPCUP-A2408-H081201',
                            'reused'                    => false,
                        ],
                    ],
                    'failed'  => [],
                ],
                'trace_id' => 'a1b2c3d4e5f6',
            ],

            'response_fields' => [
                [
                    'name'    => 'data.results[].packet_lot_temp_auto',
                    'type'    => 'string',
                    'example' => 'PPCUP-A2408-H081201',
                    'desc'    => '**這就是要的號碼**。組成是「乾片批號去掉後五碼 + 封包日編碼 + 當日順序兩碼」。',
                ],
                [
                    'name'    => 'data.results[].reused',
                    'type'    => 'bool',
                    'example' => 'false',
                    'desc'    => 'true = 這個號碼之前就取過了，本次沒有產生新號（機台重送時會看到）。'
                               . '機台端不用特別處理，照樣用就是了。',
                ],
                [
                    'name'    => 'data.results[].aqua_cycle_num',
                    'type'    => 'int',
                    'example' => 2,
                    'desc'    => '這是該乾片批號的第幾次水化。號碼是掛在「最新一次水化」那一列上的。',
                ],
                [
                    'name'    => 'data.failed[]',
                    'type'    => 'array',
                    'example' => '[]',
                    'desc'    => '失敗的那幾筆：index（第幾筆，從 0 起算）、ppcup_lot、message。'
                               . '只要有一筆成功，整次呼叫就是 200，失敗的請看這個陣列。',
                ],
            ],

            'status' => [
                200 => '至少有一筆取到號。全部成功時 message 是「取號成功」，'
                     . '有失敗的是「部分取號成功」，失敗清單在 data.failed。',
                401 => '沒帶 X-Api-Key，或金鑰不正確。',
                403 => '來源 IP 不在白名單內。',
                404 => '找不到這個乾片批號的水化紀錄——請先在水化排程頁匯入資料。'
                     . '（多筆時這種錯誤會出現在 failed 裡，整體仍是 200）',
                405 => '用了 POST 以外的方法。',
                409 => '當天的號碼用完了（一天最多 120 組）。請聯絡資訊人員，不要重試。',
                422 => '請求內容有問題：什麼都沒帶、超過 50 筆、ppcup_lot 是空的，'
                     . '或是全部的筆數都失敗了。',
                503 => '系統忙碌（等鎖逾時），**三秒後重試**即可，不是壞掉。',
            ],

            'notes' => [
                '**可以安全重送**。同一個乾片批號重複呼叫會拿到同一個號碼（reused = true），'
                . '不會每呼叫一次就燒掉一個號。逾時、斷線之後直接重送即可。',
                '**號碼一旦發出去就是發出去了**。多筆時同一批的其他筆失敗，不會把已經發出的'
                . '號碼收回——收回的話機台手上那個號碼就變成幽靈號碼。',
                '收到 503 請等三秒再重試，那是同時來取號的機台太多在排隊，不是資料寫壞了。',
                '一天最多 120 組號碼。快用完時請提早通知資訊人員，用完之後只會收到 409。',
            ],
        ],
    ],
];
