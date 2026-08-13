-- チャット履歴の誤削除を復元できるようにする（ゴミ箱方式のソフト削除）。
-- 担当者が一覧から削除しても実体は消さず deleted_at を立てて隠すだけにし、
-- 保持期間（既定30日 / CHAT_SESSION_TRASH_RETENTION_DAYS）を過ぎたものだけ
-- backend/cron/cleanup-deleted-chat-sessions.php が実体を削除する。
-- お客様側の表示・送信には影響しない（行はそのまま残る）。

ALTER TABLE chat_sessions
  ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL;

ALTER TABLE chat_sessions
  ADD COLUMN deleted_by_user_id INT NULL DEFAULT NULL;

ALTER TABLE chat_sessions
  ADD INDEX idx_chat_sessions_deleted_at (deleted_at);
