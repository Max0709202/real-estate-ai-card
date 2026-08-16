-- 組織階層機能（統括 → 店長 → 営業）を会社ごとに ON / OFF するための設定表。
--
-- 背景:
--   階層分けは法人プランの機能として提供する。契約していない会社では
--   マイページに「組織・配下顧客」を出さない（APIも拒否する）。
--
-- 設計上の要点:
--   ・会社の識別子は宅建業免許番号の正規化キー（都道府県|登録番号）。
--     users / business_cards と同じ判定（orgLicenseParts）を使うため、
--     会社名の表記ゆれの影響を受けない。
--   ・既定は OFF。行が無い会社も OFF として扱う（＝法人プラン未契約）。
--   ・階層そのもの（org_role / parent_user_id）はこの表と独立して保持する。
--     OFF の間も設定は消えないので、ON に戻せばそのまま使える。

CREATE TABLE IF NOT EXISTS org_license_settings (
    license_key VARCHAR(191) NOT NULL PRIMARY KEY
        COMMENT '免許番号の正規化キー（都道府県|登録番号）',
    license_text VARCHAR(255) NULL
        COMMENT '画面表示用の免許番号（例：東京都知事（3）第12345号）',
    company_name VARCHAR(255) NULL
        COMMENT '確認用の会社名。判定には使わない',
    hierarchy_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=法人プラン（階層分けを使える） / 0=使えない',
    updated_by_admin_id INT NULL COMMENT '最後に切り替えた運営管理者のID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_org_license_enabled (hierarchy_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
