-- KitKat invite database. ENGINE is pinned to InnoDB on purpose: the
-- server's default storage engine is MyISAM.
CREATE TABLE IF NOT EXISTS activity (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME NOT NULL,
  event       VARCHAR(40)  NOT NULL,
  guest       VARCHAR(200) NOT NULL DEFAULT '',
  visit       VARCHAR(20)  NOT NULL DEFAULT '',
  device      VARCHAR(20)  NOT NULL DEFAULT '',
  link        VARCHAR(20)  NOT NULL DEFAULT '',
  response    VARCHAR(20)  NOT NULL DEFAULT '',
  name        VARCHAR(200) NOT NULL DEFAULT '',
  mobile      VARCHAR(30)  NOT NULL DEFAULT '',
  email       VARCHAR(200) NOT NULL DEFAULT '',
  message     TEXT,
  invited_as  VARCHAR(200) NOT NULL DEFAULT '',
  page        VARCHAR(300) NOT NULL DEFAULT '',
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  KEY ix_ip_time (ip, created_at),
  KEY ix_event_guest (event, guest(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  last_login    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token_hash CHAR(64) PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY ix_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip    VARCHAR(45)  NOT NULL,
  email VARCHAR(200) NOT NULL,
  at    DATETIME NOT NULL,
  KEY ix_ip_at (ip, at),
  KEY ix_email_at (email(100), at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
