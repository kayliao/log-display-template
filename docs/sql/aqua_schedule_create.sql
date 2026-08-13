-- =============================================================================
--  水化排程 —— 建表 SQL（可直接執行）
-- =============================================================================
--  Oracle Database 19c Enterprise Edition
--
--  這一份是「照著跑就好」的版本。每個索引、每個唯一鍵「為什麼要有它」，
--  以及上線前的檢查清單，寫在同一個目錄的 hydration_oracle.sql。
--
--  執行前確認兩件事：
--    1. 資料表名稱 —— 如果你們的不叫 AQUA_SCHEDULE，先把這份檔案裡的表名
--       全部取代掉，然後改 app/Domain/Hydration/ 底下三個 Repository 裡的表名。
--       整套只有這一張表，沒有第二張（原因見第 3 節）。
--    2. 要不要指定 TABLESPACE —— 多數公司規範資料表與索引分開放，
--       需要的話在每個 CREATE 後面加 TABLESPACE xxx。
--
--  SQL*Plus / SQL Developer 直接整份執行即可。
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. 主表
-- -----------------------------------------------------------------------------
CREATE TABLE AQUA_SCHEDULE (
    AQUA_SCHEDULE_DATE       DATE              NOT NULL,
    PPCUP_LOT                VARCHAR2(100)     NOT NULL,
    QTY                      NUMBER(38,0)      NOT NULL,
    PACKET_SCHEDULE_DATE_CODE  VARCHAR2(100)     NOT NULL,
    AQUA_CYCLE_NUM           NUMBER(38,0)      NOT NULL,
    PACKET_LOT_TEMP_AUTO     VARCHAR2(100),
    NOTE                     VARCHAR2(500 CHAR),
    UPDATE_USER              VARCHAR2(100)     NOT NULL,
    UPDATE_TIME              DATE DEFAULT SYSDATE NOT NULL,

    -- 一個乾片批號的一次水化只有一列
    CONSTRAINT PK_AQUA_SCHEDULE PRIMARY KEY (PPCUP_LOT, AQUA_CYCLE_NUM),

    -- 封包批號不可重複（取號併發的最後一道防線；NULL 不進索引）
    CONSTRAINT UX_AQUA_SCHEDULE_PACKET UNIQUE (PACKET_LOT_TEMP_AUTO),

    -- 日期只到日，不准帶時分秒
    CONSTRAINT CK_AQUA_SCHEDULE_DATE  CHECK (AQUA_SCHEDULE_DATE = TRUNC(AQUA_SCHEDULE_DATE)),
    CONSTRAINT CK_AQUA_SCHEDULE_QTY   CHECK (QTY > 0),
    CONSTRAINT CK_AQUA_SCHEDULE_CYCLE CHECK (AQUA_CYCLE_NUM BETWEEN 1 AND 99)
);

--  NOTE 為什麼是 NULL 而不是預設空字串：
--    Oracle 把空字串就當成 NULL 存（'' IS NULL 成立），
--    所以「預設空字串」在 Oracle 根本做不到，一律用 NULL 最單純。
--    程式端寫入時也把空字串轉成 null，兩種資料庫行為才一致。
--    長度寫 500 CHAR（不是 BYTE）：備註會有中文，AL32UTF8 下一個中文字佔 3 bytes。
--
--  UPDATE_USER / UPDATE_TIME 都是 NOT NULL：
--    UPDATE_USER 的值一律由外面傳進來 —— 頁面匯入寫登入者姓名、
--    機台 API 取號寫機台名稱（沒帶就用 API 金鑰對應的呼叫端代號）。
--    UPDATE_TIME 由 SQL 直接寫 SYSDATE，不用 trigger 維護：
--    trigger 在大量 MERGE 時是額外成本，而且出事時很難查。


-- -----------------------------------------------------------------------------
-- 2. 索引
-- -----------------------------------------------------------------------------
--  主鍵與唯一鍵已經各自帶一個索引，另外只需要這兩個。
--  COMPRESS 1 是免費的前綴壓縮（第一欄重複值很多，省兩三成空間）。
-- -----------------------------------------------------------------------------

-- 頁面預設查法：日期區間 + 乾片批號
CREATE INDEX IX_AQUA_SCHEDULE_DATE
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1;

-- 取號時要找「那一天已經發出去的最大號」：
--
--   SELECT MAX(SUBSTR(PACKET_LOT_TEMP_AUTO, -2))
--     FROM AQUA_SCHEDULE
--    WHERE AQUA_SCHEDULE_DATE = TO_DATE(:d, 'YYYY-MM-DD')
--      AND PACKET_LOT_TEMP_AUTO IS NOT NULL
--
-- 因為索引就是照這個運算式建的，Oracle 可以走 INDEX RANGE SCAN (MIN/MAX)，
-- 只讀一個葉節點就得到答案，不用掃當天上千列。
--
-- ⚠ 圈範圍用的是「日期」不是 PACKET_SCHEDULE_DATE_CODE ——
--   後者是編碼（封包批號中間那一段字串），當日順序要靠真正的日期才可靠。
-- ⚠ 程式裡那句 SQL 的運算式必須跟這裡**一模一樣**，改一邊要改兩邊。
--   （對應程式：PackLotRepository::maxSeqCode()）
CREATE INDEX IX_AQUA_SCHEDULE_SEQ
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2));

-- PACKET_SCHEDULE_DATE_CODE 不用另外建索引：頁面查詢一定會帶日期區間，
-- 走 IX_AQUA_SCHEDULE_DATE 之後在剩下的幾百列裡比對編碼就夠了。


-- -----------------------------------------------------------------------------
-- 3. 沒有第二張表
-- -----------------------------------------------------------------------------
--  當日順序不另外用計數表記帳，下一個號是「從資料算出來的」：
--  抓當天已發出去的號碼裡最大的那兩碼，往前推一步。
--
--  單一真相：號碼就在 PACKET_LOT_TEMP_AUTO 裡。有人手動補號、修資料、
--  清掉幾列，下一號永遠算得對；計數表會跟真實資料對不起來，
--  而且對不起來的時候它照樣發號，發到重複才被唯一鍵擋下。
--
--  代價是兩支同時取號會算出同一個號 —— 那正好被
--  UX_AQUA_SCHEDULE_PACKET 擋下，程式收到唯一鍵衝突就重算重試（最多五次）。
--  一天最多 120 個號，撞在一起的機率極低。


-- -----------------------------------------------------------------------------
-- 4. 欄位註解（現場自己開 SQL 工具看資料時靠這些看懂）
-- -----------------------------------------------------------------------------
COMMENT ON TABLE  AQUA_SCHEDULE                         IS '水化排程：一列 = 某乾片批號的某一次水化';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_SCHEDULE_DATE      IS '水化日期（只到日）';
COMMENT ON COLUMN AQUA_SCHEDULE.PPCUP_LOT               IS '乾片批號';
COMMENT ON COLUMN AQUA_SCHEDULE.QTY                     IS '數量';
COMMENT ON COLUMN AQUA_SCHEDULE.PACKET_SCHEDULE_DATE_CODE IS '封包日編碼，封包批號的中段';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_CYCLE_NUM          IS '第幾次水化，同一乾片批號從 1 開始且必須連號';
COMMENT ON COLUMN AQUA_SCHEDULE.PACKET_LOT_TEMP_AUTO    IS '封包批號：機台 API 來要號時由系統產生後寫回；最後兩碼是當日順序';
COMMENT ON COLUMN AQUA_SCHEDULE.NOTE                    IS '備註，選填';
COMMENT ON COLUMN AQUA_SCHEDULE.UPDATE_USER             IS '最後異動者：匯入是登入者姓名、取號是機台名稱';
COMMENT ON COLUMN AQUA_SCHEDULE.UPDATE_TIME             IS '最後異動時間';


-- -----------------------------------------------------------------------------
-- 5. 統計值
-- -----------------------------------------------------------------------------
BEGIN
    DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_SCHEDULE', CASCADE => TRUE);
END;
/

COMMIT;


-- -----------------------------------------------------------------------------
-- 6. 建完檢查
-- -----------------------------------------------------------------------------
/*
SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH, CHAR_USED, NULLABLE, DATA_DEFAULT
  FROM USER_TAB_COLUMNS
 WHERE TABLE_NAME = 'AQUA_SCHEDULE'
 ORDER BY COLUMN_ID;

SELECT I.INDEX_NAME, I.UNIQUENESS, C.COLUMN_POSITION,
       NVL(E.COLUMN_EXPRESSION, C.COLUMN_NAME) AS COL
  FROM USER_INDEXES I
  JOIN USER_IND_COLUMNS C ON C.INDEX_NAME = I.INDEX_NAME
  LEFT JOIN USER_IND_EXPRESSIONS E
         ON E.INDEX_NAME = C.INDEX_NAME AND E.COLUMN_POSITION = C.COLUMN_POSITION
 WHERE I.TABLE_NAME = 'AQUA_SCHEDULE'
 ORDER BY I.INDEX_NAME, C.COLUMN_POSITION;

SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, SEARCH_CONDITION, STATUS
  FROM USER_CONSTRAINTS
 WHERE TABLE_NAME = 'AQUA_SCHEDULE';
*/


-- -----------------------------------------------------------------------------
-- 7. 兩筆測試資料（確認流程用，之後記得刪）
-- -----------------------------------------------------------------------------
/*
INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY, PACKET_SCHEDULE_DATE_CODE,
                           AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO, NOTE, UPDATE_USER, UPDATE_TIME)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10001', 1200, 'H0813', 1, NULL, NULL, '王工程師', SYSDATE);

INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY, PACKET_SCHEDULE_DATE_CODE,
                           AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO, NOTE, UPDATE_USER, UPDATE_TIME)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10002', 980, 'H0813', 1, NULL, '補跑', '王工程師', SYSDATE);

COMMIT;

-- 機台呼叫 /service/v1/packet-lot.php 之後，那一列會變成：
--   PACKET_LOT_TEMP_AUTO = 'PPCUP-A2408-H081301'   ← 最後兩碼 01 就是當日順序
--   UPDATE_USER          = 'AQUA-M03'（機台名稱）
--   UPDATE_TIME          = SYSDATE

-- 清掉
-- DELETE FROM AQUA_SCHEDULE WHERE PPCUP_LOT LIKE 'PPCUP-A2408-1000%';
-- COMMIT;
*/


-- -----------------------------------------------------------------------------
-- 8. 如果要先把歷史資料灌進來
-- -----------------------------------------------------------------------------
--  順序要反過來：先建表（不建鍵與索引）→ 灌資料 → 查重複 → 再補鍵與索引。
--  舊資料通常是靠人工避免重複的，直接建唯一鍵多半會失敗。
--  檢查用的 SQL 在 hydration_oracle.sql 第 8 節。
--
--  ⚠ UPDATE_USER 是 NOT NULL，舊資料多半沒有這一欄。
--    先建成可為 NULL、灌完之後補值再改成 NOT NULL：
/*
UPDATE AQUA_SCHEDULE SET UPDATE_USER = 'MIGRATION', UPDATE_TIME = SYSDATE
 WHERE UPDATE_USER IS NULL;
COMMIT;

ALTER TABLE AQUA_SCHEDULE MODIFY (UPDATE_USER VARCHAR2(100) NOT NULL);
ALTER TABLE AQUA_SCHEDULE MODIFY (UPDATE_TIME DATE DEFAULT SYSDATE NOT NULL);
*/
--
--  鍵與索引也是灌完再補（19c EE 加 ONLINE 不會擋住線上 DML）：
/*
ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT PK_AQUA_SCHEDULE
    PRIMARY KEY (PPCUP_LOT, AQUA_CYCLE_NUM);

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT UX_AQUA_SCHEDULE_PACKET
    UNIQUE (PACKET_LOT_TEMP_AUTO);

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_DATE
    CHECK (AQUA_SCHEDULE_DATE = TRUNC(AQUA_SCHEDULE_DATE));

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_QTY   CHECK (QTY > 0);
ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_CYCLE CHECK (AQUA_CYCLE_NUM BETWEEN 1 AND 99);

CREATE INDEX IX_AQUA_SCHEDULE_DATE ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1 ONLINE;
CREATE INDEX IX_AQUA_SCHEDULE_SEQ  ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2)) ONLINE;
*/


-- -----------------------------------------------------------------------------
-- 9. 砍掉重來（測試環境用）
-- -----------------------------------------------------------------------------
/*
DROP TABLE AQUA_SCHEDULE CASCADE CONSTRAINTS PURGE;
*/
