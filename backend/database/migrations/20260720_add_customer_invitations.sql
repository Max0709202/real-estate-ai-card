-- エージェントが顧客ページ（AIエージェントページ）を事前に作成するための招待テーブル。
--
-- 従来の導線は「顧客が名刺へアクセス → SMS認証 → 顧客ページ作成」だったため、
-- 既存顧客へ提案を始めるにも顧客側の操作を待つ必要があった。
-- そこでエージェント側から chat_sessions を先に作り、専用URL（invite_token）を
-- メールで送る導線を追加する。従来の導線はそのまま残す。
--
-- 設計上の要点:
--   ここで入力した氏名・メールアドレスは「エージェントの申告値」であり、
--   顧客本人の確認を経ていない（エージェントが間違えている可能性がある）。
--   そのため chat_lead_contacts へは書き込まず、この表だけに保持する。
--   顧客はURLを開いた後、いつもどおり SMS認証 → 氏名 → メールアドレスを登録し、
--   その結果が正となる。

CREATE TABLE IF NOT EXISTS chat_customer_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    business_card_id INT NOT NULL,
    invite_token CHAR(64) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    -- sent: メール送信済み / opened: 顧客が専用URLを開いた / registered: 顧客の本人登録が完了
    status ENUM('sent','opened','registered') NOT NULL DEFAULT 'sent',
    sent_at TIMESTAMP NULL DEFAULT NULL,
    opened_at TIMESTAMP NULL DEFAULT NULL,
    registered_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_customer_invitation_session (session_id),
    UNIQUE KEY uniq_chat_customer_invitation_token (invite_token),
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
    INDEX idx_chat_customer_invitation_card (business_card_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
