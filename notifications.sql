-- =====================================================================
-- TALA-AIS: notifications + system notices (admin broadcasts)
-- Run once in phpMyAdmin (Import / SQL tab). Safe to re-run.
-- =====================================================================

-- One row per broadcast the admin sends (history shown in admin/system_notices.php)
CREATE TABLE IF NOT EXISTS system_notices (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sender_id       INT NULL,
    title           VARCHAR(150) NOT NULL,
    message         TEXT NOT NULL,
    severity        VARCHAR(20) NOT NULL DEFAULT 'info',   -- info | maintenance | warning | success
    audience        VARCHAR(20) NOT NULL DEFAULT 'all',    -- all | Instructor | Student
    recipient_count INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per user per notification (this is what the bell reads).
-- Admin broadcasts fill notice_id; later features (tasks, deadlines, feedback)
-- can insert their own rows with notice_id = NULL.
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    notice_id   INT NULL,
    type        VARCHAR(30) NOT NULL DEFAULT 'system',     -- system | announcement | task | deadline | feedback
    severity    VARCHAR(20) NOT NULL DEFAULT 'info',
    title       VARCHAR(150) NOT NULL,
    message     TEXT NULL,
    link        VARCHAR(255) NULL,                         -- relative to BASE_URL, e.g. pages/dashboard.php
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_unread (user_id, is_read, created_at),
    INDEX idx_notice (notice_id),
    CONSTRAINT fk_notif_notice FOREIGN KEY (notice_id)
        REFERENCES system_notices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
