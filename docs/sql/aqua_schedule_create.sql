-- =============================================================================
--  水化排程 —— 建表 SQL（可直接執行）
-- =============================================================================
--  Oracle Database 19c Enterprise Edition
--
--  這一份是「照著跑就好」的版本。每個索引、每個唯一鍵「為什麼要有它」，
--  以及上線前的檢查清單，寫在同一個目錄的 hydration_oracle.sql。
--
--  執行前確認兩件事：
--    1. 資料表名稱 —— 如果你們的不叫 AQUA_SCHEDULE / AQUA_PACKET_SEQ，
--       先把這份檔案裡的表名全部取代掉，然後改
--       app/Domain/Hydration/ 底下三個 Repository 裡的表名。
--    2. 要不要指定 TABLESPACE —— 多數公司規範資料表與索引分開放，
--       需要的話在每個 CREATE 後面加 TABLESPACE xxx。
--
--  SQL*Plus / SQL Developer 直接整份執行即可。
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. 主表
-- -----------------------------------------------------------------------------
CREATE TABLE AQUA_SCHEDULE (
    AQUA_SCHEDULE_DATE       DATE          NOT NULL,
    PPCUP_LOT                VARCHAR2(100) NOT NULL,
    QTY                      NUMBER(38,0)  NOT NULL,
    AQUA_SCHEDULE_DATE_CODE  VARCHAR2(100) NOT NULL,
    AQUA_CYCLE_NUM           NUMBER(38,0)  NOT NULL,
    PACKET_LOT_TEMP_AUTO     VARCHAR2(100),

    -- 一個乾片批號的一次水化只有一列
    CONSTRAINT PK_AQUA_SCHEDULE PRIMARY KEY (PPCUP_LOT, AQUA_CYCLE_NUM),

    -- 封包批號不可重複（取號併發的最後一道防線；NULL 不進索引）
    CONSTRAINT UX_AQUA_SCHEDULE_PACKET UNIQUE (PACKET_LOT_TEMP_AUTO),

    -- 日期只到日，不准帶時分秒
    CONSTRAINT CK_AQUA_SCHEDULE_DATE  CHECK (AQUA_SCHEDULE_DATE = TRUNC(AQUA_SCHEDULE_DATE)),
    CONSTRAINT CK_AQUA_SCHEDULE_QTY   CHECK (QTY > 0),
    CONSTRAINT CK_AQUA_SCHEDULE_CYCLE CHECK (AQUA_CYCLE_NUM BETWEEN 1 AND 99)
);


-- -----------------------------------------------------------------------------
-- 2. 索引
-- -----------------------------------------------------------------------------
--  主鍵與唯一鍵已經各自帶一個索引，另外只需要這兩個。
--  COMPRESS 1 是免費的前綴壓縮（第一欄重複值很多，省兩三成空間）。
-- -----------------------------------------------------------------------------

-- 頁面預設查法：日期區間 + 乾片批號
CREATE INDEX IX_AQUA_SCHEDULE_DATE
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1;

-- 只給水化日編號、不給日期的查法
CREATE INDEX IX_AQUA_SCHEDULE_CODE
    ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE_CODE, AQUA_SCHEDULE_DATE) COMPRESS 1;


-- -----------------------------------------------------------------------------
-- 3. 封包批號的當日順序（取號 API 會鎖這張表的其中一列）
-- -----------------------------------------------------------------------------
CREATE TABLE AQUA_PACKET_SEQ (
    AQUA_SCHEDULE_DATE_CODE  VARCHAR2(100) NOT NULL,
    NEXT_VAL                 NUMBER(4) DEFAULT 1 NOT NULL,
    UPDATED_AT               DATE DEFAULT SYSDATE NOT NULL,

    CONSTRAINT PK_AQUA_PACKET_SEQ PRIMARY KEY (AQUA_SCHEDULE_DATE_CODE),
    CONSTRAINT CK_AQUA_PACKET_SEQ CHECK (NEXT_VAL >= 1)
);


-- -----------------------------------------------------------------------------
-- 4. 欄位註解（現場自己開 SQL 工具看資料時靠這些看懂）
-- -----------------------------------------------------------------------------
COMMENT ON TABLE  AQUA_SCHEDULE                         IS '水化排程：一列 = 某乾片批號的某一次水化';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_SCHEDULE_DATE      IS '水化日期（只到日）';
COMMENT ON COLUMN AQUA_SCHEDULE.PPCUP_LOT               IS '乾片批號';
COMMENT ON COLUMN AQUA_SCHEDULE.QTY                     IS '數量';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_SCHEDULE_DATE_CODE IS '水化日編號，封包批號的中段';
COMMENT ON COLUMN AQUA_SCHEDULE.AQUA_CYCLE_NUM          IS '第幾次水化，同一乾片批號從 1 開始且必須連號';
COMMENT ON COLUMN AQUA_SCHEDULE.PACKET_LOT_TEMP_AUTO    IS '封包批號：機台 API 來要號時由系統產生後寫回';

COMMENT ON TABLE  AQUA_PACKET_SEQ                          IS '封包批號當日順序：一天一列，取號時鎖這一列';
COMMENT ON COLUMN AQUA_PACKET_SEQ.AQUA_SCHEDULE_DATE_CODE  IS '水化日編號';
COMMENT ON COLUMN AQUA_PACKET_SEQ.NEXT_VAL                 IS '下一個要發出去的順序值（1、4、7 …）';


-- -----------------------------------------------------------------------------
-- 5. 統計值
-- -----------------------------------------------------------------------------
--  AQUA_PACKET_SEQ 永遠只有幾十列但每天都在更新，統計值鎖起來，
--  免得半夜的自動收集把它當成空表。
-- -----------------------------------------------------------------------------
BEGIN
    DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_SCHEDULE', CASCADE => TRUE);
    DBMS_STATS.GATHER_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');
    DBMS_STATS.LOCK_TABLE_STATS(USER, 'AQUA_PACKET_SEQ');
END;
/

COMMIT;


-- -----------------------------------------------------------------------------
-- 6. 建完檢查
-- -----------------------------------------------------------------------------
/*
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, DATA_LENGTH, NULLABLE
  FROM USER_TAB_COLUMNS
 WHERE TABLE_NAME IN ('AQUA_SCHEDULE', 'AQUA_PACKET_SEQ')
 ORDER BY TABLE_NAME, COLUMN_ID;

SELECT INDEX_NAME, UNIQUENESS, COLUMN_NAME, COLUMN_POSITION
  FROM USER_IND_COLUMNS JOIN USER_INDEXES USING (INDEX_NAME, TABLE_NAME)
 WHERE TABLE_NAME IN ('AQUA_SCHEDULE', 'AQUA_PACKET_SEQ')
 ORDER BY INDEX_NAME, COLUMN_POSITION;

SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, SEARCH_CONDITION, STATUS
  FROM USER_CONSTRAINTS
 WHERE TABLE_NAME IN ('AQUA_SCHEDULE', 'AQUA_PACKET_SEQ');
*/


-- -----------------------------------------------------------------------------
-- 7. 兩筆測試資料（確認流程用，之後記得刪）
-- -----------------------------------------------------------------------------
/*
INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY,
                           AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10001', 1200, 'H0813', 1, NULL);

INSERT INTO AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY,
                           AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM, PACKET_LOT_TEMP_AUTO)
VALUES (TRUNC(SYSDATE), 'PPCUP-A2408-10002', 980, 'H0813', 1, NULL);

COMMIT;

-- 清掉
-- DELETE FROM AQUA_SCHEDULE WHERE PPCUP_LOT LIKE 'PPCUP-A2408-1000%';
-- DELETE FROM AQUA_PACKET_SEQ WHERE AQUA_SCHEDULE_DATE_CODE = 'H0813';
-- COMMIT;
*/


-- -----------------------------------------------------------------------------
-- 8. 如果要先把歷史資料灌進來
-- -----------------------------------------------------------------------------
--  順序要反過來：先建表（不建鍵與索引）→ 灌資料 → 查重複 → 再補鍵與索引。
--  舊資料通常是靠人工避免重複的，直接建唯一鍵多半會失敗。
--  檢查用的 SQL 在 hydration_oracle.sql 第 8 節。
--
--  作法：把上面第 1 節裡的五個 CONSTRAINT 先拿掉，灌完資料查過重複之後再補：
/*
ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT PK_AQUA_SCHEDULE
    PRIMARY KEY (PPCUP_LOT, AQUA_CYCLE_NUM);

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT UX_AQUA_SCHEDULE_PACKET
    UNIQUE (PACKET_LOT_TEMP_AUTO);

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_DATE
    CHECK (AQUA_SCHEDULE_DATE = TRUNC(AQUA_SCHEDULE_DATE));

ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_QTY   CHECK (QTY > 0);
ALTER TABLE AQUA_SCHEDULE ADD CONSTRAINT CK_AQUA_SCHEDULE_CYCLE CHECK (AQUA_CYCLE_NUM BETWEEN 1 AND 99);

-- 正式環境加索引請加 ONLINE，不會擋住正在跑的 DML（19c EE）
CREATE INDEX IX_AQUA_SCHEDULE_DATE ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE, PPCUP_LOT) COMPRESS 1 ONLINE;
CREATE INDEX IX_AQUA_SCHEDULE_CODE ON AQUA_SCHEDULE (AQUA_SCHEDULE_DATE_CODE, AQUA_SCHEDULE_DATE) COMPRESS 1 ONLINE;
*/


-- -----------------------------------------------------------------------------
-- 9. 砍掉重來（測試環境用）
-- -----------------------------------------------------------------------------
/*
DROP TABLE AQUA_SCHEDULE   CASCADE CONSTRAINTS PURGE;
DROP TABLE AQUA_PACKET_SEQ CASCADE CONSTRAINTS PURGE;
*/
