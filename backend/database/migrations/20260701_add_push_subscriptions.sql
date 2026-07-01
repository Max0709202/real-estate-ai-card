-- ホーム画面アイコンのアプリバッジ用 Web Push 購読テーブル。
-- 顧客が担当営業カード(card.php)のPWAで通知を許可した際の購読情報を保持する。
-- 担当→顧客の新着時に、対象セッションの購読へ空Push（tickle）を送り、
-- Service Worker が未読数を取得して setAppBadge する。
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(36) NOT NULL,
  business_card_id INT NULL,
  visitor_id VARCHAR(128) NULL,
  endpoint VARCHAR(700) NOT NULL,
  p256dh VARCHAR(255) NULL,
  auth VARCHAR(255) NULL,
  ua VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_notified_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_endpoint (endpoint(255)),
  INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
