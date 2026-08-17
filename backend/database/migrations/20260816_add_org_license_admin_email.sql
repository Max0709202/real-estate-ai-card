-- org_license_settings に「階層機能を使えるログインメール」を追加する。
--
-- 背景:
--   階層機能を使えるかどうかは免許番号キーだけで判定していたが、
--   免許番号が未登録だったり表記が揺れていたりすると一致せず、
--   運営が ON にしても「組織・配下顧客」が出ない、という状態が起きた。
--   そこで、使える方をメールで明示的に指定する運用にする。
--
-- 表示条件（AND）:
--   ① license_key が一致する会社が hierarchy_enabled = 1
--   ② admin_email の一覧に、ログイン中のユーザーのメールが含まれている
--   → ②が空の会社は、ON にしても誰にも表示されない。
--
-- 設計上の要点:
--   ・統括だけでなく、その会社の店長のメールもここに入れる（複数可）。
--     入れないと店長に「組織・配下顧客」が出なくなる。
--   ・区切りはカンマ・改行・空白のいずれでもよい（orgParseEmailList が吸収する）。
--   ・比較は小文字・前後空白除去で行う。
--
-- 20260810_add_org_license_settings.sql を新規に流す環境では、
-- そちらの CREATE TABLE にこの列が含まれているため、この移行は不要。

ALTER TABLE org_license_settings
    ADD COLUMN admin_email TEXT NULL
        COMMENT '階層機能を使えるログインメール。カンマ／改行区切りで複数可'
        AFTER company_name;

-- すでに統括・店長に指名されている方のメールを初期値として入れておく。
-- これを流さないと、ON 済みの会社が「誰も表示されない」状態になる。
-- 代表名刺（最も古い名刺）の免許番号でキーを作る点は、アプリ側の判定と同じ。
-- ※ 全角数字や「第◯号」表記の登録番号はここでは一致しないため、
--    その場合は管理画面「組織階層設定」の一覧から直接入力する。
SET SESSION group_concat_max_len = 8192;

UPDATE org_license_settings s
JOIN (
    SELECT CONCAT(bc.real_estate_license_prefecture, '|',
                  TRIM(LEADING '0' FROM bc.real_estate_license_registration_number)) AS license_key,
           GROUP_CONCAT(DISTINCT LOWER(u.email) SEPARATOR ', ') AS admin_email
    FROM users u
    JOIN business_cards bc
      ON bc.id = (SELECT MIN(id) FROM business_cards WHERE user_id = u.id)
    WHERE u.org_role IN ('admin', 'manager')
      AND bc.real_estate_license_registration_number IS NOT NULL
      AND bc.real_estate_license_registration_number <> ''
    GROUP BY license_key
) a ON a.license_key = s.license_key
SET s.admin_email = a.admin_email
WHERE s.admin_email IS NULL OR TRIM(s.admin_email) = '';
