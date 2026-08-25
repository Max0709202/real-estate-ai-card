-- 物件選定: 物件PRコメント（物件提案時に担当者がお客様へ届ける250〜350字程度の紹介文）。
-- pr_comment            : 本文。NULL/空 = 未登録（削除された状態）
-- pr_comment_source     : 最後に保存した方法 manual=手入力 / ai=AI生成をそのまま保存 / ai_edited=AI生成を編集して保存
-- pr_comment_updated_at : 最終更新日時（担当画面に表示）
ALTER TABLE properties
  ADD COLUMN pr_comment TEXT NULL DEFAULT NULL AFTER remarks,
  ADD COLUMN pr_comment_source VARCHAR(16) NULL DEFAULT NULL AFTER pr_comment,
  ADD COLUMN pr_comment_updated_at TIMESTAMP NULL DEFAULT NULL AFTER pr_comment_source;
