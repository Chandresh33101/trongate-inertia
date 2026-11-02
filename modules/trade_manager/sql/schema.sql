-- ============================================================================
-- Trade Manager schema
-- Run once against your Trongate database.
-- Every row is owned by a Trongate user (trongate_users.id) so clients can only
-- ever see and modify their own journal.
-- ============================================================================

-- ── 1. Client accounts (login target for user level 2) ──────────────────────
-- Skip this block if you already have a clients/members table; just point
-- config/login.php (level 2) at it.
CREATE TABLE IF NOT EXISTS clients (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trongate_user_id INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,
    email            VARCHAR(255) NOT NULL,
    password         VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_clients_email (email),
    UNIQUE KEY uq_clients_user  (trongate_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The 'client' user level (id 2 must match config/login.php).
INSERT INTO trongate_user_levels (id, level_title)
VALUES (2, 'client')
ON DUPLICATE KEY UPDATE level_title = level_title;

-- ── 2. Trades ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trades (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trongate_user_id INT UNSIGNED NOT NULL,
    symbol           VARCHAR(20)  NOT NULL,
    direction        ENUM('long','short') NOT NULL DEFAULT 'long',
    status           ENUM('open','closed') NOT NULL DEFAULT 'open',
    entry_price      DECIMAL(18,6) NOT NULL,
    exit_price       DECIMAL(18,6) NULL,
    quantity         DECIMAL(18,6) NOT NULL,
    stop_loss        DECIMAL(18,6) NULL,
    take_profit      DECIMAL(18,6) NULL,
    fees             DECIMAL(18,6) NOT NULL DEFAULT 0,
    pnl              DECIMAL(18,6) NULL,            -- net P&L, computed on save
    entry_date       DATETIME NOT NULL,
    exit_date        DATETIME NULL,
    notes            TEXT NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    KEY idx_trades_user_entry  (trongate_user_id, entry_date),
    KEY idx_trades_user_status (trongate_user_id, status),
    KEY idx_trades_user_symbol (trongate_user_id, symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Tags (per client, names unique case-insensitively) ───────────────────
CREATE TABLE IF NOT EXISTS trade_tags (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trongate_user_id INT UNSIGNED NOT NULL,
    name             VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_tag_user_name (trongate_user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trade_tag_map (
    trade_id INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (trade_id, tag_id),
    KEY idx_map_tag (tag_id),
    CONSTRAINT fk_map_trade FOREIGN KEY (trade_id) REFERENCES trades (id)     ON DELETE CASCADE,
    CONSTRAINT fk_map_tag   FOREIGN KEY (tag_id)   REFERENCES trade_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Screenshot attachments ───────────────────────────────────────────────
-- Files live in modules/trade_manager/uploads/{trongate_user_id}/ (outside the
-- web root) and are streamed through trade_manager/screenshot/{id} after an
-- ownership check. Only generated file names are stored here.
CREATE TABLE IF NOT EXISTS trade_attachments (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trade_id         INT UNSIGNED NOT NULL,
    trongate_user_id INT UNSIGNED NOT NULL,
    file_name        VARCHAR(64)  NOT NULL,
    thumb_name       VARCHAR(64)  NULL,
    original_name    VARCHAR(255) NOT NULL,
    mime_type        VARCHAR(50)  NOT NULL,
    file_size        INT UNSIGNED NOT NULL,
    caption          VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL,
    KEY idx_att_trade (trade_id),
    KEY idx_att_user  (trongate_user_id),
    CONSTRAINT fk_att_trade FOREIGN KEY (trade_id) REFERENCES trades (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
