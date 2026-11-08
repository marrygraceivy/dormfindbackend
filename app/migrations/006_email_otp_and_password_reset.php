<?php
class Email_otp_and_password_reset
{
    public function up()
    {
        $db=lava_instance()->db;
        $db->raw('CREATE TABLE IF NOT EXISTS registration_email_otps (otp_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,email VARCHAR(100) NOT NULL UNIQUE,code_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,used_at DATETIME NULL,created_at DATETIME NOT NULL,INDEX (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->raw('CREATE TABLE IF NOT EXISTS password_reset_tokens (token_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX (user_id,expires_at),FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function down() { /* Preserve verification and password reset history. */ }
}
