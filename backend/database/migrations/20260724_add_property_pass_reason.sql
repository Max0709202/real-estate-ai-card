-- 物件選定: 顧客が「見送り(passed)」を選んだ際の理由を保存する。
-- pass_reason: 理由コード（price/location/layout/condition/renovation/other）
-- pass_reason_text: 「その他」等の自由入力（任意）
ALTER TABLE properties
  ADD COLUMN pass_reason VARCHAR(32) NULL DEFAULT NULL AFTER status,
  ADD COLUMN pass_reason_text VARCHAR(500) NULL DEFAULT NULL AFTER pass_reason;
