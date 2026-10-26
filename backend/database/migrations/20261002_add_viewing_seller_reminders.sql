-- 売主仲介会社への内見調整リマインド（★2026/10/2 追加ご依頼）
-- viewing-reminder-helper.php の viewingSellerReminderEnsureTable() と同じ定義。
-- 調整依頼（M03／M08）の送信から48・72・96時間後に、売主仲介会社へのリマインドと所有者への通知を送る。
-- 調整依頼1回（request_at）× 送信回（seq）で1回だけ送る。
CREATE TABLE IF NOT EXISTS property_viewing_seller_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  viewing_id INT NOT NULL,
  request_at DATETIME NOT NULL,
  seq TINYINT NOT NULL,
  send_at DATETIME NOT NULL,
  status ENUM('scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'scheduled',
  attempts INT NOT NULL DEFAULT 0,
  sent_at DATETIME NULL DEFAULT NULL,
  error_message VARCHAR(500) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_property_viewing_seller_reminders (viewing_id, request_at, seq),
  INDEX idx_property_viewing_seller_reminders_due (status, send_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
