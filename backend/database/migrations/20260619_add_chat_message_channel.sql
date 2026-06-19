-- AI担当 / 担当連絡 を独立した会話チャネルに分離する。
-- chat_messages に channel を追加し、AI（bot↔顧客）と担当連絡（人間担当↔顧客）を区別する。
--   channel='ai'      … AIチャット（role=bot/system、AIへの user 質問）
--   channel='contact' … 担当連絡（role=agent、担当宛の user 発言）

-- 1) チャネル列を追加（既定は ai）
ALTER TABLE chat_messages
  ADD COLUMN channel ENUM('ai','contact') NOT NULL DEFAULT 'ai' AFTER role;

-- 2) 既存の担当発言（人間）は担当連絡チャネルへ
UPDATE chat_messages SET channel='contact' WHERE role='agent';

-- 注: 旧 handoff_mode='agent' 中に顧客が担当へ送った user 発言は、メッセージ単位で
--     チャネルを記録していなかったため厳密には再現できない（best-effort で 'ai' のまま）。
--     確実に担当連絡へ振り分けられるのは agent 発言のみ。実害は軽微。

-- 3) チャネル別の取得・未読集計用インデックス
ALTER TABLE chat_messages
  ADD INDEX idx_chat_messages_channel (session_id, channel, id);
