-- 全国マンションデータベース（db.self-in.com）の実ページを参照するためのリンク列。
--
-- mansion_buildings.id はXLSX取り込み時のAUTO_INCREMENT（ローカル採番）であり、
-- db.self-in.com の「マンションID」ではない。実ページ
--   https://db.self-in.com/mansion/{マンションID}.html?cid=rchukai&on=0
-- を取得するには本家のIDが必要なため、専用列で保持する。
--
-- mdb_id        : db.self-in.com のマンションID（マスタ配布 or 検索で解決したもの）
-- mdb_id_status : pending（未解決）/ confirmed（建物名・住所で照合済み）/ notfound（本家に該当なし）
-- mdb_id_checked_at : 最終解決試行日時。notfound の再試行間隔の判定に使う。
ALTER TABLE mansion_buildings
    ADD COLUMN mdb_id INT NULL,
    ADD COLUMN mdb_id_status VARCHAR(20) NULL,
    ADD COLUMN mdb_id_checked_at TIMESTAMP NULL,
    ADD INDEX idx_mansion_mdb_id (mdb_id);
