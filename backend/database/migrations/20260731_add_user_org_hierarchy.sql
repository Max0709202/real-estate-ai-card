-- 組織階層（統括 → 店長 → 営業）と権限区分を users に追加する。
--
-- 背景:
--   これまで users は「名刺を持つ営業担当者」のフラットな集合で、上下関係が無かった。
--   3階層（統括 → 店長 → 営業）で運用している会社では、上長が配下の担当者と
--   その顧客を見られないため導入できない、という指摘があった。
--
-- 設計上の要点:
--   ・階層は parent_user_id の連鎖だけで表現する（部署テーブルは作らない）。
--     統括 → 店長 → 営業 と親を辿れるので、統括は「店長 + その配下の営業」を辿れる。
--   ・org_role は「その人に何ができるか」だけを表す。
--       staff   = 担当者（自分の顧客のみ。従来どおり）
--       manager = マネージャー（店長）。配下の担当者と顧客を閲覧できる
--       admin   = 管理者（統括）。配下の店長・担当者と顧客を閲覧できる
--   ・今回は「閲覧のみ」。上長が配下の顧客を編集・削除する導線は作らない。
--   ・既存ユーザーは全員 staff 扱いになるため、この移行だけでは挙動は変わらない。

ALTER TABLE users
    ADD COLUMN org_role ENUM('staff','manager','admin') NOT NULL DEFAULT 'staff'
        COMMENT '組織権限 staff=担当者 / manager=マネージャー(店長) / admin=管理者(統括)',
    ADD COLUMN parent_user_id INT NULL
        COMMENT '直属の上長のユーザーID（営業→店長→統括）';

CREATE INDEX idx_users_parent_user ON users (parent_user_id);
CREATE INDEX idx_users_org_role ON users (org_role);

-- 上長が削除されても配下ユーザーは残す（親だけ空にする）。
ALTER TABLE users
    ADD CONSTRAINT fk_users_parent_user
        FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE SET NULL;
