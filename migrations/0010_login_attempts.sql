-- ============================================================
--  0010_login_attempts.sql — Create login_attempts table
--  IP-based brute-force rate limiting (added in TASK-06)
-- ============================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
