-- 1つの顧客案件（chat_sessions）に最大2名の顧客を参加させるための拡張。
-- ------------------------------------------------------------------
-- 背景:
--   従来は1案件＝1名（1電話番号）で、夫婦など2名で検討する場合は片方の
--   ログイン情報を共有してもらうしかなく、もう一方に通知が届かない・誰の
--   発言か分からない等の不都合があった。
--
-- 方針（負担最小）:
--   2人目を「同じ session_id」に合流させ、案件・履歴・資料・提案・進捗を
--   自動共有する。chat_sessions 本体には手を入れず、参加者情報だけを本表で持つ。
--   chat_lead_contacts は session_id が UNIQUE で1名分しか保持できないため、
--   2名分の氏名・メール・電話・認証状態は本表に分けて保存する。
--
--   role='primary' … 最初に登録した本人。role='partner' … 招待された2人目。
--   UNIQUE(session_id, role) により、1案件につき各ロール1行＝合計最大2名を保証する。
--   status: invited（招待メール送信済み・SMS認証待ち） / registered（本人のSMS認証完了）
--          / removed（参加解除。行は残し、過去の発言の投稿者表示に使えるようにする）

CREATE TABLE IF NOT EXISTS chat_session_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    business_card_id INT NOT NULL,
    role ENUM('primary','partner') NOT NULL,
    invite_token CHAR(64) NULL DEFAULT NULL,
    phone_normalized VARCHAR(32) NULL DEFAULT NULL,
    email VARCHAR(255) NULL DEFAULT NULL,
    display_name VARCHAR(255) NULL DEFAULT NULL,
    firebase_uid VARCHAR(128) NULL DEFAULT NULL,
    status ENUM('invited','registered','removed') NOT NULL DEFAULT 'invited',
    invited_at TIMESTAMP NULL DEFAULT NULL,
    registered_at TIMESTAMP NULL DEFAULT NULL,
    removed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- 1案件につき primary/partner 各1行のみ（＝最大2名）。
    UNIQUE KEY uniq_session_role (session_id, role),
    -- 招待トークンは推測不能な一意値（NULL は複数許容される）。
    UNIQUE KEY uniq_participant_invite_token (invite_token),
    INDEX idx_participant_session (session_id, status),
    -- メッセージ投稿者の突合（端末の電話番号 → 参加者）に使う。
    INDEX idx_participant_card_phone (business_card_id, phone_normalized),
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- どの参加者が入力したメッセージかを記録する（「誰が投稿したか」）。
-- NULL = 従来どおり（単独顧客の案件 / AI / 担当の発言）で、既存挙動は一切変わらない。
ALTER TABLE chat_messages
  ADD COLUMN author_participant_id INT NULL DEFAULT NULL AFTER sender_user_id;
