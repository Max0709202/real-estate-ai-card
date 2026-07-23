-- 自社帯（販売図面）機能。
-- 担当者が販売図面のマスク編集で、白マスクの代わりに「自社の帯」（会社名・連絡先・QR等の
-- A4横サイズ帯画像）を焼き込んで顧客に表示できるようにするための列。
--
-- 帯画像は1アカウント1枚。company_logo と同様、business_cards に相対パス文字列で保持する。
-- 実際の合成はマスク領域の種別 t='band'（mask_regions 内）で判定し、flyer-mask.php / property-helper.php で行う。

ALTER TABLE business_cards
    ADD COLUMN flyer_band VARCHAR(500) NULL AFTER company_logo;
