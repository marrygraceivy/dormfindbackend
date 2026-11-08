CREATE TABLE IF NOT EXISTS account_email_otps (
  otp_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  new_email VARCHAR(100) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX (new_email), INDEX (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX (attempt_key,attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
