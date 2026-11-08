<?php
class Rental_workflow
{
    public function up()
    {
        $db = lava_instance()->db;
        $has = fn($table,$column) => (bool)$db->raw('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column])->fetchColumn();
        if (!$has('users','is_banned')) $db->raw('ALTER TABLE users ADD is_banned TINYINT(1) NOT NULL DEFAULT 0');
        if (!$has('inquiries_applications','rent_start')) $db->raw('ALTER TABLE inquiries_applications ADD rent_start DATE NULL');
        if (!$has('inquiries_applications','rent_end')) $db->raw('ALTER TABLE inquiries_applications ADD rent_end DATE NULL');
        $db->raw('CREATE TABLE IF NOT EXISTS rental_contracts (contract_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, inquiry_id INT NOT NULL UNIQUE, monthly_rent DECIMAL(10,2) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, terms TEXT NOT NULL, landlord_signed_at DATETIME NULL, student_signed_at DATETIME NULL, landlord_signature VARCHAR(150) NULL, student_signature VARCHAR(150) NULL, landlord_ip VARCHAR(45) NULL, student_ip VARCHAR(45) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (inquiry_id) REFERENCES inquiries_applications(inquiry_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->raw('CREATE TABLE IF NOT EXISTS rental_notifications (notification_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, inquiry_id INT NOT NULL, recipient_id INT NOT NULL, kind VARCHAR(40) NOT NULL, message VARCHAR(500) NOT NULL, emailed TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY one_notice (inquiry_id,recipient_id,kind), FOREIGN KEY (inquiry_id) REFERENCES inquiries_applications(inquiry_id) ON DELETE CASCADE, FOREIGN KEY (recipient_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function down() { /* Additive data must not be dropped by rollback. */ }
}
