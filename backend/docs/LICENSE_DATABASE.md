# MLIT宅建業者ライセンスデータベース

## 概要

このシステムは、国土交通省（MLIT）の宅建業者情報をローカルデータベースに保存し、
登録時に高速な会社情報検索を提供します。

## ファイル構成

```
backend/
├── database/
│   └── migrations/
│       └── create_real_estate_licenses_table.sql  # DBスキーマ
├── scripts/
│   └── import_licenses.php                         # CSVインポートスクリプト
├── api/
│   └── utils/
│       └── license-lookup.php                      # 検索API
└── data/
    └── sample_licenses.csv                         # サンプルCSV
```

## セットアップ手順

### 1. データベーステーブル作成

```bash
mysql -u root -p real_estate_card < backend/database/migrations/create_real_estate_licenses_table.sql
```

または phpMyAdmin で SQL ファイルをインポート。

### 2. CSVデータの準備

#### CSV形式（ヘッダー必須）

```csv
prefecture,prefecture_code,renewal_number,registration_number,company_name,representative_name,office_name,address,phone_number
```

#### 各カラムの説明

| カラム名 | 説明 | 例 |
|---------|------|-----|
| prefecture | 都道府県名 | 東京都, 青森県 |
| prefecture_code | 都道府県コード（2桁） | 13, 02 |
| renewal_number | 更新回数（免許の括弧内の数字） | 4, 15 |
| registration_number | 登録番号（第○○号の数字部分） | 12345, 000586 |
| company_name | 商号又は名称 | 株式会社サンプル不動産 |
| representative_name | 代表者名 | 山田 太郎 |
| office_name | 事務所名 | 本店 |
| address | 所在地 | 東京都新宿区西新宿1-1-1 |
| phone_number | 電話番号 | 03-1234-5678 |

#### サンプルデータ

```csv
青森県,02,15,000586,三和興業株式会社,吉町 敦子,本店,青森県青森市橋本1－9－14,017-XXX-XXXX
東京都,13,4,12345,株式会社サンプル不動産,山田 太郎,本店,東京都新宿区西新宿1-1-1,03-XXXX-XXXX
```

### 3. データインポート

```bash
# 差分更新モード（デフォルト）
php backend/scripts/import_licenses.php /path/to/licenses.csv

# 全件置換モード（既存データを削除して再インポート）
php backend/scripts/import_licenses.php /path/to/licenses.csv --full

# ヘルプ表示
php backend/scripts/import_licenses.php --help
```

## API使用方法

### エンドポイント

```
POST /backend/api/utils/license-lookup.php
```

### リクエスト

```json
{
  "prefecture": "東京都",
  "renewal": "4",
  "registration": "12345"
}
```

### レスポンス（成功時）

```json
{
  "success": true,
  "message": "Success",
  "data": {
    "company_name": "株式会社サンプル不動産",
    "address": "東京都新宿区西新宿1-1-1",
    "representative_name": "山田 太郎",
    "office_name": "本店",
    "phone_number": "03-XXXX-XXXX",
    "full_license_text": "東京都知事(4)第12345号"
  }
}
```

### レスポンス（見つからない場合）

```json
{
  "success": false,
  "message": "Not found"
}
```

## データ取得元

### 公式データソース

1. **国土交通省オープンデータ**
   - https://www.mlit.go.jp/
   - 宅建業者名簿のCSVエクスポートが利用可能な場合

2. **各都道府県の宅建業者名簿**
   - 各都道府県庁のWebサイトで公開されている場合あり

3. **MLIT宅建業者検索システム**
   - https://etsuran2.mlit.go.jp/TAKKEN/takkenKensaku.do
   - 一度だけスクレイピングでデータを取得し、以降はローカルDBを使用

### 推奨更新頻度

- **通常**: 月1回
- **重要な精度が必要な場合**: 週1回

## 定期更新（Cron設定例）

```bash
# 毎週月曜日 午前3時に更新
0 3 * * 1 /usr/bin/php /path/to/backend/scripts/import_licenses.php /var/data/licenses_latest.csv >> /var/log/license_import.log 2>&1
```

## データベーステーブル構造

### real_estate_licenses

| カラム | 型 | 説明 |
|--------|-----|------|
| id | BIGINT | 主キー |
| prefecture | VARCHAR(50) | 都道府県名 |
| prefecture_code | VARCHAR(8) | 都道府県コード |
| issuing_authority | VARCHAR(64) | 免許権者（○○知事） |
| renewal_number | INT | 更新回数 |
| registration_number | VARCHAR(50) | 登録番号 |
| full_license_text | VARCHAR(255) | 完全な免許番号テキスト |
| company_name | VARCHAR(255) | 商号又は名称 |
| representative_name | VARCHAR(255) | 代表者名 |
| office_name | VARCHAR(255) | 事務所名 |
| address | VARCHAR(512) | 所在地 |
| phone_number | VARCHAR(50) | 電話番号 |
| is_active | TINYINT | アクティブフラグ |
| created_at | DATETIME | 作成日時 |
| updated_at | DATETIME | 更新日時 |

### インデックス

- `idx_license_unique`: (prefecture_code, renewal_number, registration_number) - ユニーク
- `idx_prefecture`: prefecture
- `idx_registration`: registration_number
- `ft_company`: company_name (FULLTEXT)

## トラブルシューティング

### インポートが失敗する場合

1. CSVファイルのエンコーディングを確認（UTF-8推奨）
2. BOMが含まれている場合は自動的にスキップされます
3. ヘッダー行が必須です

### 検索で見つからない場合

1. 登録番号の形式を確認（先頭のゼロの有無）
2. 更新回数が正しいか確認
3. データがインポートされているか確認:
   ```sql
   SELECT COUNT(*) FROM real_estate_licenses WHERE prefecture = '東京都';
   ```

## 法的注意事項

- MLITの公開データを使用する場合は、利用規約に従ってください
- データの再配布には注意が必要です
- 定期的にデータを更新して最新の状態を維持してください

