-- 名刺登録（内見の打診メールM03のリンク先で、売主（仲介）会社に提示する名刺画像）。
-- edit.php「自社帯登録」の下の「名刺登録」からアップロードする。
-- upload.php 側でも冪等に追加されるため、本番へ先に流しておくと ALTER を省ける。
ALTER TABLE business_cards
  ADD COLUMN name_card_image VARCHAR(500) NULL DEFAULT NULL AFTER flyer_band;
