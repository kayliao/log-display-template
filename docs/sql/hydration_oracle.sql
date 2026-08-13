-- =============================================================================
--  水化排程 —— 資料表設計的理由（說明版）
-- =============================================================================
--
--  要直接執行的建表 SQL 請用同一個目錄的 **aqua_schedule_create.sql**。
--  這一份寫的是「為什麼這樣設計」：每個鍵、每個索引、取號怎麼處理併發，
--  以及上線前的檢查清單。兩份檔案的欄位要一起改。
--
--  對應頁面：/pages/hydration/schedule.php
--  對應程式：app/Domain/Hydration/
--  機台 API：/service/v1/packet-lot.php
--
--  ── 環境 ────────────────────────────────────────────────────
--    Oracle Database 19c Enterprise Edition
--    可以用 12c 以後的寫法（線上建索引…），要另外買授權的選項會標出來。
--
--  ── 資料量假設（現場估計）────────────────────────────────────
--    一天最多 1000 列上下 => 一年約 25～36 萬列
--    這在 Oracle 是小表：不需要分割區、索引多一兩個的寫入成本可以忽略、
--    也不需要 bitmap（這張表一直在寫，bitmap 在頻繁 DML 下會鎖到整段）。
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. 欄位
-- -----------------------------------------------------------------------------
--   AQUA_SCHEDULE_DATE       DATE               水化日期（只到日）
--   PPCUP_LOT                VARCHAR2(100)      乾片批號
--   QTY                      NUMBER(38,0)       數量
--   AQUA_SCHEDULE_DATE_CODE  VARCHAR2(100)      水化日編號（封包批號的中段）
--   AQUA_CYCLE_NUM           NUMBER(38,0)       第幾次水化
--   PACKET_LOT_TEMP_AUTO     VARCHAR2(100)      封包批號（機台來要號時系統寫回）
--   NOTE                     VARCHAR2(500 CHAR) 備註，選填
--   UPDATE_USER              VARCHAR2(100)      最後異動者，NOT NULL
--   UPDATE_TIME              DATE               最後異動時間，NOT NULL
--
--  【NOTE 為什麼是 NULL 不是空字串】
--    Oracle 把空字串就當 NULL 存（'' IS NULL 成立），所以「預設空字串」
--    在 Oracle 根本做不到。一律 NULL 最單純，程式端寫入時也把空字串轉成 null，
--    這樣換到 PostgreSQL 行為才一致（PG 的 '' 跟 NULL 是兩回事）。
--    長度用 500 CHAR 不是 BYTE：備註會有中文，AL32UTF8 下一個中文字佔 3 bytes。
--
--  【UPDATE_USER 的值從哪裡來】
--    一律由外面傳進來，Domain 這一層不去讀 Session：
--      頁面匯入   登入者姓名（取不到就退回工號）—— public/api/hydration/import.php
--      機台取號   機台名稱，機台在 JSON 裡帶 update_user；
--                 沒帶就用 API 金鑰對應的呼叫端代號 —— 所以這一欄永遠有值
--
--  【UPDATE_TIME 不用 trigger】
--    每一句 INSERT/UPDATE 自己寫 SYSDATE。trigger 在大量 MERGE 時是額外成本，
--    而且出事的時候「為什麼時間變了」很難查。
--
--  【型別可以再收緊的地方】
--    NUMBER(38,0) 是不限精度的整數，一個值最多吃 21 bytes。
--    數量寫 NUMBER(7)、第幾次水化寫 NUMBER(2) 會更省，而且等於多一層防呆。
--    現在改用 CHECK 補上防呆的效果，型別照現場給的原樣。


-- -----------------------------------------------------------------------------
-- 2. 鍵與索引
-- -----------------------------------------------------------------------------
--  【主鍵 (PPCUP_LOT, AQUA_CYCLE_NUM)】
--    一個乾片批號的一次水化只有一列。不另外開流水號（代理鍵）：
--      - 沒有子表要參照它，代理鍵沒有人會用到
--      - 匯入是以這兩欄做 MERGE，主鍵剛好就是比對鍵
--      - 少一個欄位、少一個序號、少一個索引
--    欄位順序是 (乾片批號, 第幾次)，因為查詢一律是「這個乾片批號的水化歷程」。
--
--  【唯一鍵 (PACKET_LOT_TEMP_AUTO)】
--    封包批號跟資料列是一對一：一個封包批只對到一個乾片批的某一次水化，
--    同一個乾片批之後再水化一次會拿到另一個號。
--    這個鍵有兩個作用：
--      1. 取號併發的最後一道防線（程式的鎖若失效，資料庫還是擋得住）
--      2. 「同一個封包批號被貼到兩列」進不去 —— 這種錯如果沒擋，
--         出貨端回頭查「這箱是哪批」會查到兩批，而且通常是出貨後才發現
--    Oracle 不索引「鍵全部是 NULL」的列，所以還沒取號的幾十萬列不會進這個索引。
--
--  【IX_AQUA_SCHEDULE_DATE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1】
--    頁面預設就是查日期區間，日期放第一欄；第二欄放乾片批號讓「某天某批」
--    在索引裡就找完。COMPRESS 1 壓掉重複的日期前綴，省兩三成空間，
--    是基本前綴壓縮、不用另外買授權（19c EE 的 COMPRESS ADVANCED LOW
--    效果更好，但那是 Advanced Compression 選項）。
--
--  【IX_AQUA_SCHEDULE_SEQ (AQUA_SCHEDULE_DATE_CODE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2))】
--    一個索引服務兩件事：
--      1. 「只給水化日編號」的查詢（第一欄相等就用得到）
--      2. 取號時找「當天已發出去的最大號」，見第 3 節
--    第二欄是運算式，所以這是 function-based index，
--    ⚠ 程式裡那句 SQL 的運算式必須跟索引**一模一樣**，改一邊要改兩邊。


-- -----------------------------------------------------------------------------
-- 3. 當日順序：從資料算，不用計數表
-- -----------------------------------------------------------------------------
--  封包批號的**最後兩碼**就是當日順序：
--
--      PPCUP-A2408-H0813 01
--                        ^^ 這兩碼
--
--  所以「下一個號是幾號」不需要另外記帳，抓當天已發出去的最大值就好：
/*
SELECT MAX(SUBSTR(PACKET_LOT_TEMP_AUTO, -2)) AS LAST_SEQ
  FROM AQUA_SCHEDULE
 WHERE AQUA_SCHEDULE_DATE_CODE = :date_code
   AND PACKET_LOT_TEMP_AUTO IS NOT NULL;
*/
--
--  【為什麼可以直接用字串的 MAX】
--    編碼是「前一碼 0-9 之後接 A-Z、後一碼 0-9」，
--    ASCII 裡 '0'-'9' 剛好排在 'A'-'Z' 前面，
--    所以字串比大小的順序跟數值大小完全一致（'99' < 'A0' < 'B2'）。
--    ⚠ 哪天編碼改用別的字元集，這個前提就不成立。
--
--  【為什麼是最後兩碼而不是整串】
--    封包批號的前段是乾片批號，不同批號的前段不一樣，
--    整串比大小會變成「比乾片批號」，跟順序無關。
--
--  【為什麼不用計數表】
--    計數表（一天一列、記著下一個號）也是一種常見做法，但：
--      - 會跟真實資料對不起來：有人手動補號、修資料、清掉幾列之後，
--        計數表照樣發它記著的號，發到重複才被唯一鍵擋下
--      - 多一張表要備份、要收統計、要有人知道它存在
--    從資料算則永遠是單一真相。
--
--  【代價與對策】
--    兩支同時取號會算出同一個號 —— 這正是唯一鍵存在的意義：
--    其中一支會收到 ORA-00001，程式重新抓最大號再算一次（最多五次）。
--    一天最多 120 個號，撞在一起的機率極低。
--    對應程式：PackLotRepository::maxSeqCode() / PackLotService::allocate()


-- -----------------------------------------------------------------------------
-- 4. 取號的完整流程（機台 API 進來時跑這一段）
-- -----------------------------------------------------------------------------
--  封包批號 = 乾片批號去掉後 5 碼 + 水化日編號 + 當日順序（2 碼）
--             PPCUP-A2408- + H0813 + 01  =>  PPCUP-A2408-H081301
--
--  順序：兩碼當成一個數字，每次加 3
--        01 → 04 → 07 → 10 … 94 → 97 → A0 → A3 → A6 → A9 → B2 …
--        （A0 = 100、A9 = 109、B0 = 110、Z9 = 359，所以一天上限 120 組）
-- -----------------------------------------------------------------------------

-- 4-1 找出並鎖住這個乾片批號「最新一次水化」那一列。
--     這個鎖擋的是「同一個批號、兩支同時來要號」；
--     不同批號之間不互相等，那一段交給唯一鍵與重試。
--
--     WAIT 3：等最多三秒，等不到就回「系統忙碌中請重試」給機台。
--     SELECT ... FOR UPDATE 的鎖持有到 COMMIT，
--     所以這個交易裡絕對不可以做檔案解析、呼叫別的系統這類慢動作。
/*
SELECT PPCUP_LOT, AQUA_CYCLE_NUM, AQUA_SCHEDULE_DATE_CODE, PACKET_LOT_TEMP_AUTO
  FROM AQUA_SCHEDULE
 WHERE PPCUP_LOT = :ppcup_lot
   AND AQUA_CYCLE_NUM = (SELECT MAX(AQUA_CYCLE_NUM) FROM AQUA_SCHEDULE WHERE PPCUP_LOT = :ppcup_lot)
   FOR UPDATE WAIT 3;

-- 4-2 已經有封包批號 => 原號回傳，不要再燒一個號。
--     機台重試、網路斷線重送都會走到這裡，所以這支 API 可以重複呼叫
--     （idempotent）。少了這一步，對方重試一次就多一個號、數量就對不起來。

-- 4-3 抓當天最大的順序（第 3 節那一句），程式算出下一個號。

-- 4-4 寫回，順便記下是誰、什麼時候。
--     WHERE 多一個 PACKET_LOT_TEMP_AUTO IS NULL：萬一鎖沒鎖到，
--     這一句也不會蓋掉別人已經寫進去的號碼；更新到 0 列就重新讀一次。
UPDATE AQUA_SCHEDULE
   SET PACKET_LOT_TEMP_AUTO = :packet_lot,
       UPDATE_USER          = :update_user,
       UPDATE_TIME          = SYSDATE
 WHERE PPCUP_LOT            = :ppcup_lot
   AND AQUA_CYCLE_NUM       = :cycle_num
   AND PACKET_LOT_TEMP_AUTO IS NULL;

COMMIT;

-- 4-5 如果 UPDATE 收到 ORA-00001（唯一鍵衝突），表示別人剛好也算出同一個號：
--     整個重來（回到 4-1），最多五次。
*/


-- -----------------------------------------------------------------------------
-- 5. 匯入時的寫入（MERGE：有就更新、沒有就新增）
-- -----------------------------------------------------------------------------
--  比對鍵是 (PPCUP_LOT, AQUA_CYCLE_NUM)，也就是主鍵。
--
--  「已經有封包批號的那一次水化不可以被覆蓋」這條規則在程式裡先檢查過了
--  （才能告訴使用者是第幾列、為什麼不行），這裡的
--  WHERE PACKET_LOT_TEMP_AUTO IS NULL 是第二道防線：
--  從檢查到寫入之間，機台可能剛好來要過號了。
--
--  ⚠ 同一個具名參數在 Oracle 只能出現一次，所以 INSERT 那段要另外取名（_ins）。
-- -----------------------------------------------------------------------------
/*
MERGE INTO AQUA_SCHEDULE T
USING (SELECT :ppcup_lot AS PPCUP_LOT, :cycle_num AS AQUA_CYCLE_NUM FROM DUAL) S
   ON (T.PPCUP_LOT = S.PPCUP_LOT AND T.AQUA_CYCLE_NUM = S.AQUA_CYCLE_NUM)
WHEN MATCHED THEN
    UPDATE SET T.AQUA_SCHEDULE_DATE      = TO_DATE(:schedule_date, 'YYYY-MM-DD'),
               T.QTY                     = :qty,
               T.AQUA_SCHEDULE_DATE_CODE = :date_code,
               T.NOTE                    = :note,
               T.UPDATE_USER             = :update_user,
               T.UPDATE_TIME             = SYSDATE
     WHERE T.PACKET_LOT_TEMP_AUTO IS NULL
WHEN NOT MATCHED THEN
    INSERT (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY, AQUA_SCHEDULE_DATE_CODE,
            AQUA_CYCLE_NUM, NOTE, UPDATE_USER, UPDATE_TIME)
    VALUES (TO_DATE(:schedule_date_ins, 'YYYY-MM-DD'), :ppcup_lot_ins, :qty_ins,
            :date_code_ins, :cycle_num_ins, :note_ins, :update_user_ins, SYSDATE);
*/


-- -----------------------------------------------------------------------------
-- 6. 頁面實際會送出的兩句查詢（附上預期用到的索引）
-- -----------------------------------------------------------------------------
--  查明細（走 IX_AQUA_SCHEDULE_DATE）：
/*
SELECT S.AQUA_SCHEDULE_DATE, S.QTY, S.PPCUP_LOT, S.AQUA_SCHEDULE_DATE_CODE,
       S.AQUA_CYCLE_NUM, S.PACKET_LOT_TEMP_AUTO, S.NOTE, S.UPDATE_USER, S.UPDATE_TIME
  FROM AQUA_SCHEDULE S
 WHERE S.AQUA_SCHEDULE_DATE >= TO_DATE(:start_date, 'YYYY-MM-DD')
   AND S.AQUA_SCHEDULE_DATE <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1
 ORDER BY S.AQUA_SCHEDULE_DATE DESC, S.PPCUP_LOT, S.AQUA_CYCLE_NUM;
*/
--  ⚠ 日期條件不要寫成 TRUNC(S.AQUA_SCHEDULE_DATE) = :d，那樣索引就用不到了。
--
--  今日統整（走 IX_AQUA_SCHEDULE_DATE，只掃當天那一段）。
--  COUNT(欄位) 只算「不是 NULL」的那些列，COUNT(*) 才是全部：
/*
SELECT COUNT(*)                        AS ROW_CNT,     -- 總筆數
       SUM(S.QTY)                      AS QTY_SUM,     -- 總數量
       COUNT(DISTINCT S.PPCUP_LOT)     AS LOT_CNT,     -- 幾個乾片批號
       COUNT(S.PACKET_LOT_TEMP_AUTO)   AS PACKET_CNT   -- 已取號（未取號 = ROW_CNT - PACKET_CNT）
  FROM AQUA_SCHEDULE S
 WHERE S.AQUA_SCHEDULE_DATE >= TRUNC(SYSDATE)
   AND S.AQUA_SCHEDULE_DATE <  TRUNC(SYSDATE) + 1;
*/


-- -----------------------------------------------------------------------------
-- 7. 上線前檢查清單
-- -----------------------------------------------------------------------------
--  有歷史資料要搬的時候，第 3 步幾乎一定會抓到東西 ——
--  舊資料通常是靠人工避免重複的。
-- -----------------------------------------------------------------------------

--  1. 先建表，不建主鍵、唯一鍵與索引（大量初始資料先灌後建比較快），
--     而且 UPDATE_USER 先允許 NULL

--  2. 灌歷史資料

--  3. 檢查重複與髒資料。要先決定「留哪一筆」，這個決定比索引本身重要。
/*
-- 同一個乾片批號的同一次水化出現兩次（主鍵會擋）
SELECT PPCUP_LOT, AQUA_CYCLE_NUM, COUNT(*) AS CNT
  FROM AQUA_SCHEDULE
 GROUP BY PPCUP_LOT, AQUA_CYCLE_NUM
HAVING COUNT(*) > 1
 ORDER BY CNT DESC;

-- 同一個封包批號被貼到兩列（唯一鍵會擋）
SELECT PACKET_LOT_TEMP_AUTO, COUNT(*) AS CNT
  FROM AQUA_SCHEDULE
 WHERE PACKET_LOT_TEMP_AUTO IS NOT NULL
 GROUP BY PACKET_LOT_TEMP_AUTO
HAVING COUNT(*) > 1;

-- 同一天的順序（最後兩碼）重複 —— 上面那句抓不到「不同批號但同順序」的情況
SELECT AQUA_SCHEDULE_DATE_CODE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2) AS SEQ, COUNT(*) AS CNT
  FROM AQUA_SCHEDULE
 WHERE PACKET_LOT_TEMP_AUTO IS NOT NULL
 GROUP BY AQUA_SCHEDULE_DATE_CODE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2)
HAVING COUNT(*) > 1;

-- 第幾次水化沒有從 1 開始、或中間跳號（規則上不該出現）
SELECT PPCUP_LOT, MIN(AQUA_CYCLE_NUM) AS MIN_NUM,
       MAX(AQUA_CYCLE_NUM) AS MAX_NUM, COUNT(*) AS CNT
  FROM AQUA_SCHEDULE
 GROUP BY PPCUP_LOT
HAVING MIN(AQUA_CYCLE_NUM) <> 1 OR MAX(AQUA_CYCLE_NUM) <> COUNT(*);

-- 日期被塞進時分秒（CHECK 會擋，但舊資料要先清）
SELECT COUNT(*) FROM AQUA_SCHEDULE
 WHERE AQUA_SCHEDULE_DATE <> TRUNC(AQUA_SCHEDULE_DATE);

-- 沒有異動者的列（UPDATE_USER 要改成 NOT NULL 之前要補完）
SELECT COUNT(*) FROM AQUA_SCHEDULE WHERE UPDATE_USER IS NULL;
*/

--  4. 都乾淨了才補鍵、索引與 NOT NULL（19c 可以加 ONLINE），
--     實際語法見 aqua_schedule_create.sql 第 8 節

--  5. 收統計值。沒收的話第一天的執行計畫是瞎猜的。
/*
BEGIN
  DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_SCHEDULE', CASCADE => TRUE);
END;
/
*/

--  6. 用真實比例的資料驗證執行計畫。看到 TABLE ACCESS FULL 就要回頭看索引。
--     取號那一句要看到 INDEX RANGE SCAN (MIN/MAX)。
/*
EXPLAIN PLAN FOR
SELECT MAX(SUBSTR(PACKET_LOT_TEMP_AUTO, -2))
  FROM AQUA_SCHEDULE
 WHERE AQUA_SCHEDULE_DATE_CODE = 'H0813'
   AND PACKET_LOT_TEMP_AUTO IS NOT NULL;

SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY);
*/

--  7. 要試「多加一個索引會不會比較好」時先建成 INVISIBLE，
--     只有自己這條連線看得到，不影響現場正在跑的查詢：
/*
CREATE INDEX IX_AQUA_SCHEDULE_TRY ON AQUA_SCHEDULE (...) INVISIBLE ONLINE;
ALTER SESSION SET OPTIMIZER_USE_INVISIBLE_INDEXES = TRUE;
*/

--  8. 跟 DBA 確認：資料表與索引要不要放不同 tablespace、備份與保留策略。


-- -----------------------------------------------------------------------------
-- 8. 什麼時候才需要分割區（Partitioning）
-- -----------------------------------------------------------------------------
--  以目前估的一天一千列（一年三十幾萬列）來說：**不需要**。
--  它在 19c EE 上仍然是要另外買的選項，而且代價要先知道：
--    - 按 AQUA_SCHEDULE_DATE 做月分割之後，主鍵與 UX_AQUA_SCHEDULE_PACKET
--      因為鍵裡沒有分割欄位，只能建成 GLOBAL 索引
--    - 之後 DROP 舊分割區必須加 UPDATE GLOBAL INDEXES，否則索引失效，
--      而且那個動作在大表上不便宜
--  「提早分割」通常是賠錢的，等量到了再說。
