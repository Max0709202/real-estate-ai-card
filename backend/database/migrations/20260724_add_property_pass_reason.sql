-- 物件選定: 顧客が「見送り(passed)」を選んだ際の理由を保存する。
-- pass_reason: 理由コード（複数選択可・カンマ区切り。例 "price_high,station_far"）
-- pass_reason_text: 「その他」等の自由入力（任意）
-- pass_reason_ai: 見送り理由をAIが分析した所見（管理画面に掲出）
ALTER TABLE properties
  ADD COLUMN pass_reason VARCHAR(255) NULL DEFAULT NULL AFTER status,
  ADD COLUMN pass_reason_text VARCHAR(500) NULL DEFAULT NULL AFTER pass_reason,
  ADD COLUMN pass_reason_ai TEXT NULL DEFAULT NULL AFTER pass_reason_text;
