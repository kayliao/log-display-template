-- =============================================================================
--  水化排程 —— Oracle 資料表設計（完整範例）
-- =============================================================================
--
--  對應頁面：/pages/hydration/schedule.php
--  對應程式：app/Domain/Hydration/
--  對應對外 API：/service/v1/packet-lot.php（機台端取封包批號）
--
--  這一份是「可以直接照著建、也可以照著改」的參考。每一個索引、每一個
--  唯一鍵下面都寫了「為什麼要有它」——現場資料出問題的時候，多半是因為
--  某個唯一鍵當初沒建。
--
--  ── 環境 ────────────────────────────────────────────────────
--    Oracle Database 19c Enterprise Edition
--    所以底下可以用 12c 以後才有的寫法（IDENTITY、DEFAULT ON NULL、
--    線上建索引…），會特別標出來哪些是要另外買授權的選項。
--
--  ── 資料量假設（現場估計）────────────────────────────────────
--    一天最多 1000 列上下 => 一年約 25～36 萬列
--
--    這個量在 Oracle 是小表：
--      - 不需要分割區（Partitioning 在 EE 上還是要另外買的選項）
--      - 索引多建一兩個的寫入成本可以忽略
--      - 一般的 B-tree 索引就夠，不用 bitmap（這張表一直在寫，
--        bitmap 索引在頻繁 DML 下會鎖到整段，反而更糟）
--
--  ⚠ 資料表名稱請改成你們實際的那一個。程式裡只有
--    app/Domain/Hydration/*Repository.php 這一層會用到表名，改那裡就好。
--
--  實際上線請照第 8 節的「上線前檢查清單」跑，不要直接整份貼下去。
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. 水化排程（主表）
-- -----------------------------------------------------------------------------
--  一列 = 某一個乾片批號（PPCUP_LOT）的某一次水化。
--  同一個乾片批號會水化好幾次，所以 PPCUP_LOT 自己不是主鍵。
-- -----------------------------------------------------------------------------
CREATE TABLE AQUA_SCHEDULE (
    AQUA_SCHEDULE_DATE       DATE            NOT NULL,   -- 水化日期（只到日，不存時分秒）
    PPCUP_LOT                VARCHAR2(100)   NOT NULL,   -- 乾片批號
    QTY                      NUMBER(38,0)    NOT NULL,   -- 數量
    AQUA_SCHEDULE_DATE_CODE  VARCHAR2(100)   NOT NULL,   -- 水化日編號（封包批號的中段）
    AQUA_CYCLE_NUM           NUMBER(38,0)    NOT NULL,   -- 第幾次水化（1 開始）
    PACKET_LOT_TEMP_AUTO     VARCHAR2(100),              -- 封包批號（系統在機台 API 來要號時產生後寫回）

    -- 主鍵就是自然鍵：一個乾片批號的一次水化只會有一列。
    --
    -- 不另外開流水號（代理鍵）的理由：
    --   - 這張表沒有子表要參照它，代理鍵沒有人會用到
    --   - 匯入是以 (PPCUP_LOT, AQUA_CYCLE_NUM) 做 MERGE，主鍵剛好就是比對鍵
    --   - 少一個欄位、少一個序號、少一個索引
    --
    -- 欄位順序是 (乾片批號, 第幾次)，因為查詢一律是「這個乾片批號的水化歷程」；
    -- 反過來寫的話那種查詢就用不到索引。
    CONSTRAINT PK_AQUA_SCHEDULE PRIMARY KEY (PPCUP_LOT, AQUA_CYCLE_NUM),

    -- 封包批號不可重複。
    --
    -- 一個封包批只會對到一個乾片批的「某一次水化」；同一個乾片批之後再水化
    -- 一次會拿到另一個封包批號，所以封包批號跟資料列是一對一。
    --
    -- 這個唯一鍵有兩個作用：
    --   1. 取號併發的最後一道防線（程式的鎖若失效，資料庫還是擋得住）
    --   2. 「同一個封包批號被貼到兩列」進不去 —— 這種錯如果沒擋，
    --      出貨端回頭查「這箱是哪批」會查到兩批，而且通常是出貨後才發現
    --
    -- Oracle 的唯一索引不管「鍵全部是 NULL」的列，所以還沒取號的幾十萬列
    -- 根本不會進這個索引，也不會互相衝突。這個索引永遠只有已取號的那些列。
    CONSTRAINT UX_AQUA_SCHEDULE_PACKET UNIQUE (PACKET_LOT_TEMP_AUTO),

    -- 日期只到日，不准帶時分秒。
    -- 整頁的查詢、統計與「今日」的判斷都靠這個假設；
    -- 有人用 SYSDATE 塞進去（帶時分秒）就會變成「今天查不到今天的資料」，
    -- 而且這種錯很難從畫面上看出來。
    CONSTRAINT CK_AQUA_SCHEDULE_DATE CHECK (AQUA_SCHEDULE_DATE = TRUNC(AQUA_SCHEDULE_DATE)),

    CONSTRAINT CK_AQUA_SCHEDULE_QTY   CHECK (QTY > 0),
    CONSTRAINT CK_AQUA_SCHEDULE_CYCLE CHECK (AQUA_CYCLE_NUM BETWEEN 1 AND 99)
);

--  ⚠ 兩個可以再收緊的地方（不改也能跑，但值得考慮）：
--
--  1. NUMBER(38,0) 是「不限精度」的整數，一個值最多吃 21 bytes。
--     數量寫成 NUMBER(7)、第幾次水化寫成 NUMBER(2) 的話，
--     不只省空間，還等於多了一層防呆（打錯一個 0 直接被擋）。
--     現在的 CHECK 已經補上了後者的效果。
--
--  2. VARCHAR2(100) 沒寫單位就是 BYTE。批號都是英數字所以沒差，
--     但如果之後有欄位要放中文，請寫成 VARCHAR2(100 CHAR)——
--     AL32UTF8 下一個中文字佔 3 bytes，VARCHAR2(100) 只放得下 33 個中文字。

-- 頁面預設的查法是「日期區間 + 條件」，所以日期放索引第一欄。
-- 第二欄放乾片批號，是為了讓「某天某批」這種查詢在索引裡就找完，不用再回表。
--
-- COMPRESS 1：第一欄是日期，一天上千列共用同一個值，壓掉重複的前綴
-- 可以省下兩到三成的索引空間（也就少兩到三成的 I/O）。
-- 這是基本的前綴壓縮，不用另外買授權。
-- （19c EE 另有 COMPRESS ADVANCED LOW，效果更好，但那是
--   Advanced Compression 選項，要另外買。）
CREATE INDEX IX_AQUA_SCHEDULE_DATE
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1;

-- 依水化日編號查（現場拿著一張水化日報表來對數字時用這個）。
-- 水化日編號跟日期高度相關，多半走上面那個索引就夠了；
-- 這一個是給「只給編號、不給日期」的查法用的。
-- 一天一千列的量，多這一個索引的寫入成本可以忽略，所以直接建。
CREATE INDEX IX_AQUA_SCHEDULE_CODE
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE_CODE, AQUA_SCHEDULE_DATE) COMPRESS 1;

-- 依封包批號回查是哪一批乾片（出貨端問「這箱是哪批」時用這個）
-- => 不用另外建，上面的 UX_AQUA_SCHEDULE_PACKET 唯一鍵本身就是索引。

-- 19c EE 在正式環境加索引請加 ONLINE，不會擋住正在跑的 DML：
--   CREATE INDEX ... ONLINE;
--   ALTER  INDEX ... REBUILD ONLINE;


-- -----------------------------------------------------------------------------
-- 2. 封包批號的當日順序
-- -----------------------------------------------------------------------------
--  一天一列。取號的時候鎖這一列，所以同一天的取號會排隊、不同天不互相影響。
--
--  為什麼不用 SEQUENCE：
--    - SEQUENCE 是全域的，沒辦法「每天從 01 重新開始」
--    - NEXTVAL 不受交易保護，rollback 之後號碼就是跳掉了（現場會問為什麼少一號）
--    - 我們的順序不是 +1 而是 01 → 04 → 07 …（步進值 3），SEQUENCE 也表達不了
--
--  為什麼不用 SELECT MAX(順序) + 1：
--    兩支同時進來會算出同一個號碼。加上唯一索引雖然擋得住，但變成「撞了再重試」，
--    高併發時重試會越來越多。鎖一列的成本反而更低、行為也可預測。
-- -----------------------------------------------------------------------------
CREATE TABLE AQUA_PACKET_SEQ (
    AQUA_SCHEDULE_DATE_CODE  VARCHAR2(100) NOT NULL,             -- 水化日編號
    NEXT_VAL                 NUMBER(4) DEFAULT 1 NOT NULL,       -- 下一個要發出去的順序值（1、4、7 …）
    UPDATED_AT               DATE DEFAULT SYSDATE NOT NULL,

    CONSTRAINT PK_AQUA_PACKET_SEQ PRIMARY KEY (AQUA_SCHEDULE_DATE_CODE),
    CONSTRAINT CK_AQUA_PACKET_SEQ CHECK (NEXT_VAL >= 1)
);

--  ⚠ 這張表要把統計值收好並鎖起來。
--
--  它永遠只有幾十列，但每天都在更新。半夜的自動統計收集如果剛好在
--  「今天這一列還沒建」的時候跑過，最佳化器會以為這張表是空的；
--  之後偶爾會出現「三列的表居然掃很久」這種很難解釋的狀況。
/*
BEGIN
  DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');
  DBMS_STATS.LOCK_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');
END;
/
*/

--  ⚠ 當天發得出幾組號碼是算得出來的，而且目前很可能不夠用。
--
--    順序是兩碼、當成一個數字每次加 3：
--      前一碼 0-9 之後接 A-Z、後一碼 0-9 => A0 = 100、A9 = 109、B0 = 110、Z9 = 359
--      01 04 07 10 … 94 97 A0 A3 A6 A9 B2 B5 …
--    => 一天最多 120 組（規則見 config/app.php 的 hydration）
--
--    現場估「一天最多 1000 筆左右」。如果那 1000 筆都要各自取一個封包批號，
--    120 組撐不到中午。步進值改成 1 也只有 359 組（兩碼的極限就是 Z9）。
--
--    要一天上千個號就得改成三碼，那會動到號碼長度與格式，
--    必須跟機台端、封包端一起確認。
--
--    先確認的事：那一千筆裡面實際會來要號的有幾筆？
--    （同一個乾片批號的多次水化才各自一個號，不是每一列都會取號。）
--
--    號碼用完時 API 會回 409 並把上限寫在訊息裡，不會默默發出重複的號碼。


-- -----------------------------------------------------------------------------
-- 3. 取號的完整流程（機台 API 進來時跑這一段）
-- -----------------------------------------------------------------------------
--  程式碼在 app/Domain/Hydration/PackLotService.php，這裡列出它實際送出的 SQL。
--
--  封包批號 = 乾片批號去掉後 5 碼 + 水化日編號 + 當日順序（2 碼）
--             PPCUP-A2408- + H0812 + 01
--          => PPCUP-A2408-H081201
--
--  順序：兩碼當成一個數字，每次加 3
--        01 → 04 → 07 → 10 → 13 → 16 → 19 → 22 → 25 …
--        十位數用完 9 之後換英文字母：… 94 → 97 → A0 → A3 → A6 → A9 → B2 …
--        （A0 = 100、A9 = 109、B0 = 110，所以 A9 的下一個是 B2）
--
--  鎖的順序固定「先鎖水化排程那一列、再鎖當日順序那一列」。
--  兩支程式用相反順序鎖同樣兩列就會 deadlock，所以這件事要寫下來。
-- -----------------------------------------------------------------------------

-- 3-1 找出並鎖住這個乾片批號「最新一次水化」那一列。
--     WAIT 3：等最多三秒。等不到就回「系統忙碌中請重試」給機台，
--     不要讓對方的連線一直掛在那裡（NOWAIT 太急、無限等最糟）。
--
--     SELECT ... FOR UPDATE 的鎖會一直持有到 COMMIT，
--     所以這個交易裡面絕對不可以做檔案解析、呼叫別的系統這類慢動作。
/*
SELECT PPCUP_LOT, AQUA_CYCLE_NUM, AQUA_SCHEDULE_DATE_CODE, PACKET_LOT_TEMP_AUTO
  FROM AQUA_SCHEDULE
 WHERE PPCUP_LOT = :ppcup_lot
   AND AQUA_CYCLE_NUM = (SELECT MAX(AQUA_CYCLE_NUM) FROM AQUA_SCHEDULE WHERE PPCUP_LOT = :ppcup_lot)
   FOR UPDATE WAIT 3;

-- 3-2 已經有封包批號 => 原號回傳，不要再燒一個號。
--     機台重試、網路斷線重送都會走到這裡，所以這支 API 是可以重複呼叫的
--     （idempotent）。少了這一步，對方重試一次就多一個號、數量就對不起來。

-- 3-3 鎖住當日順序那一列，沒有就先建一列。
--     ORA-00001（唯一鍵衝突）表示別人剛好也在建，重新 SELECT 一次就好。
SELECT NEXT_VAL FROM AQUA_PACKET_SEQ
 WHERE AQUA_SCHEDULE_DATE_CODE = :date_code FOR UPDATE WAIT 3;

INSERT INTO AQUA_PACKET_SEQ (AQUA_SCHEDULE_DATE_CODE, NEXT_VAL) VALUES (:date_code, 1);

-- 3-4 把順序往前推。
--     下一個值是程式算好再帶進來的（PackLotNumber::next()），不是在 SQL 裡 +3 ——
--     進位規則（A9 的下一個是 B0 還是 B2）只能有一個地方說了算。
UPDATE AQUA_PACKET_SEQ
   SET NEXT_VAL = :next_val, UPDATED_AT = SYSDATE
 WHERE AQUA_SCHEDULE_DATE_CODE = :date_code;

-- 3-5 寫回那一列的封包批號。
--     WHERE 多一個 PACKET_LOT_TEMP_AUTO IS NULL：萬一鎖沒鎖到（有人繞過流程、
--     或程式改壞了），這一句也不會蓋掉別人已經寫進去的號碼。
--     更新到 0 列就表示發生了這件事 —— 重新讀一次、把既有的號碼回傳。
UPDATE AQUA_SCHEDULE
   SET PACKET_LOT_TEMP_AUTO = :packet_lot
 WHERE PPCUP_LOT            = :ppcup_lot
   AND AQUA_CYCLE_NUM       = :cycle_num
   AND PACKET_LOT_TEMP_AUTO IS NULL;

COMMIT;
*/


-- -----------------------------------------------------------------------------
-- 4. 匯入時的寫入（MERGE：有就更新、沒有就新增）
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
MERGE INTO AQUA_SCHEDULE t
USING (SELECT :ppcup_lot AS PPCUP_LOT, :cycle_num AS AQUA_CYCLE_NUM FROM DUAL) s
   ON (t.PPCUP_LOT = s.PPCUP_LOT AND t.AQUA_CYCLE_NUM = s.AQUA_CYCLE_NUM)
WHEN MATCHED THEN
    UPDATE SET t.AQUA_SCHEDULE_DATE      = TO_DATE(:schedule_date, 'YYYY-MM-DD'),
               t.QTY                     = :qty,
               t.AQUA_SCHEDULE_DATE_CODE = :date_code
     WHERE t.PACKET_LOT_TEMP_AUTO IS NULL
WHEN NOT MATCHED THEN
    INSERT (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY, AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM)
    VALUES (TO_DATE(:schedule_date_ins, 'YYYY-MM-DD'), :ppcup_lot_ins, :qty_ins,
            :date_code_ins, :cycle_num_ins);
*/


-- -----------------------------------------------------------------------------
-- 5. 註解（現場自己開 SQL 工具看資料時，靠這些看懂欄位）
-- -----------------------------------------------------------------------------
COMMENT ON TABLE  AQUA_SCHEDULE IS '水化排程：一列 = 某乾片批號的某一次水化';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_SCHEDULE_DATE      IS '水化日期（只到日）';
COMMENT ON COLUMN AQUA_SCHEDULE.PPCUP_LOT               IS '乾片批號';
COMMENT ON COLUMN AQUA_SCHEDULE.QTY                     IS '數量';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_SCHEDULE_DATE_CODE IS '水化日編號，封包批號的中段';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_CYCLE_NUM          IS '第幾次水化，同一乾片批號從 1 開始且必須連號';
COMMENT ON COLUMN AQUA_SCHEDULE.PACKET_LOT_TEMP_AUTO    IS '封包批號：機台 API 來要號時由系統產生後寫回';

COMMENT ON TABLE  AQUA_PACKET_SEQ IS '封包批號當日順序：一天一列，取號時鎖這一列';
COMMENT ON COLUMN AQUA_PACKET_SEQ.AQUA_SCHEDULE_DATE_CODE IS '水化日編號';
COMMENT ON COLUMN AQUA_PACKET_SEQ.NEXT_VAL                IS '下一個要發出去的順序值（1、4、7 …）';


-- -----------------------------------------------------------------------------
-- 6. 頁面實際會送出的兩句查詢（附上預期用到的索引）
-- -----------------------------------------------------------------------------
--  查明細（走 IX_AQUA_SCHEDULE_DATE）：
/*
SELECT s.AQUA_SCHEDULE_DATE, s.QTY, s.PPCUP_LOT, s.AQUA_SCHEDULE_DATE_CODE,
       s.AQUA_CYCLE_NUM, s.PACKET_LOT_TEMP_AUTO
  FROM AQUA_SCHEDULE s
 WHERE s.AQUA_SCHEDULE_DATE >= TO_DATE(:start_date, 'YYYY-MM-DD')
   AND s.AQUA_SCHEDULE_DATE <  TO_DATE(:end_date, 'YYYY-MM-DD') + 1
 ORDER BY s.AQUA_SCHEDULE_DATE DESC, s.PPCUP_LOT, s.AQUA_CYCLE_NUM;
*/
--  ⚠ 日期條件不要寫成 TRUNC(s.AQUA_SCHEDULE_DATE) = :d，那樣索引就用不到了。
--    要嘛照上面寫成區間，要嘛替 TRUNC(...) 另外建 function-based index。
--
--  今日統整（走 IX_AQUA_SCHEDULE_DATE，只掃當天那一段）。
--  COUNT(欄位) 只算「不是 NULL」的那些列，COUNT(*) 才是全部，
--  所以「已取號幾筆」不需要寫成 SUM(CASE WHEN … THEN 1 ELSE 0 END)：
/*
SELECT COUNT(*)                        AS row_cnt,     -- 總筆數
       SUM(s.QTY)                      AS qty_sum,     -- 總數量
       COUNT(DISTINCT s.PPCUP_LOT)     AS lot_cnt,     -- 幾個乾片批號
       COUNT(s.PACKET_LOT_TEMP_AUTO)   AS packet_cnt   -- 已取號（未取號 = row_cnt - packet_cnt）
  FROM AQUA_SCHEDULE s
 WHERE s.AQUA_SCHEDULE_DATE >= TRUNC(SYSDATE)
   AND s.AQUA_SCHEDULE_DATE <  TRUNC(SYSDATE) + 1;
*/


-- -----------------------------------------------------------------------------
-- 7. 幾筆測試資料（照著這個長相就對了）
-- -----------------------------------------------------------------------------
/*
INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY,
                           AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10001', 1200, 'H0812', 1, 'PPCUP-A2408-H081201');

-- 第 2 次水化還沒取號 => 重傳同一列會直接覆蓋（upsert）
INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY,
                           AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10001', 980, 'H0812', 2, NULL);

INSERT INTO AQUA_PACKET_SEQ (AQUA_SCHEDULE_DATE_CODE, NEXT_VAL) VALUES ('H0812', 4);

COMMIT;
*/


-- -----------------------------------------------------------------------------
-- 8. 上線前檢查清單
-- -----------------------------------------------------------------------------
--  不要把這份檔案整份貼下去就上線。有歷史資料要搬的時候，
--  第 3 步幾乎一定會抓到東西 —— 舊資料通常是靠人工避免重複的。
-- -----------------------------------------------------------------------------

--  1. 先建表，「不要」建主鍵、唯一鍵與索引
--     （大量初始資料時，先灌後建比邊灌邊維護快很多）

--  2. 灌歷史資料

--  3. 檢查重複與髒資料。有重複的話鍵根本建不起來，
--     而且要先決定「留哪一筆」，這個決定比索引本身重要。
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

-- 第幾次水化沒有從 1 開始、或中間跳號（規則上不該出現）
SELECT PPCUP_LOT, MIN(AQUA_CYCLE_NUM) AS MIN_NUM,
       MAX(AQUA_CYCLE_NUM) AS MAX_NUM, COUNT(*) AS CNT
  FROM AQUA_SCHEDULE
 GROUP BY PPCUP_LOT
HAVING MIN(AQUA_CYCLE_NUM) <> 1 OR MAX(AQUA_CYCLE_NUM) <> COUNT(*);

-- 日期被塞進時分秒（CK_AQUA_SCHEDULE_DATE 會擋，但舊資料要先清）
SELECT COUNT(*) FROM AQUA_SCHEDULE
 WHERE AQUA_SCHEDULE_DATE <> TRUNC(AQUA_SCHEDULE_DATE);
*/

--  4. 都乾淨了才建主鍵、唯一鍵與索引（照第 1 節的順序，19c 可以加 ONLINE）

--  5. 收統計值。沒收的話第一天的執行計畫是瞎猜的。
/*
BEGIN
  DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_SCHEDULE', CASCADE => TRUE);
  DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');
  DBMS_STATS.LOCK_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');   -- 見第 2 節
END;
/
*/

--  6. 用真實比例的資料驗證執行計畫。看到 TABLE ACCESS FULL 就要回頭看索引。
/*
EXPLAIN PLAN FOR
SELECT s.AQUA_SCHEDULE_DATE, s.QTY, s.PPCUP_LOT
  FROM AQUA_SCHEDULE s
 WHERE s.AQUA_SCHEDULE_DATE >= TO_DATE('2026-08-01', 'YYYY-MM-DD')
   AND s.AQUA_SCHEDULE_DATE <  TO_DATE('2026-08-08', 'YYYY-MM-DD') + 1;

SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY);
*/

--  7. 要試「多加一個索引會不會比較好」時，先建成 INVISIBLE，
--     只有自己這條連線看得到，不會影響現場正在跑的查詢。
/*
CREATE INDEX IX_AQUA_SCHEDULE_TRY ON AQUA_SCHEDULE (...) INVISIBLE ONLINE;
ALTER SESSION SET OPTIMIZER_USE_INVISIBLE_INDEXES = TRUE;
-- 確認有效再 ALTER INDEX IX_AQUA_SCHEDULE_TRY VISIBLE; 沒效就 DROP
*/

--  8. 跟 DBA 確認這兩件事：
--     - 資料表與索引要不要放不同的 tablespace（多數公司有這個規範）
--     - 備份與保留策略。一天一千列的話一年三十幾萬列，
--       跑個五年也才一百多萬列，先不用想清檔；真的要清再按日期刪。


-- -----------------------------------------------------------------------------
-- 9. 什麼時候才需要分割區（Partitioning）
-- -----------------------------------------------------------------------------
--  以目前估的一天一千列（一年三十幾萬列）來說：**不需要**。
--  分割區是給「一年上千萬列、而且要整批刪舊資料」的表用的，
--  而且在 19c EE 上它仍然是要另外買的選項。
--
--  真的長到那個量再做，並且要先知道代價：
--    - 按 AQUA_SCHEDULE_DATE 做月分割之後，主鍵 (PPCUP_LOT, AQUA_CYCLE_NUM)
--      與 UX_AQUA_SCHEDULE_PACKET 因為鍵裡沒有分割欄位，只能建成 GLOBAL 索引
--    - 之後 DROP 舊分割區時必須加 UPDATE GLOBAL INDEXES，否則索引會失效，
--      而且那個動作在大表上不便宜
--  所以「提早分割」通常是賠錢的，等量到了再說。
