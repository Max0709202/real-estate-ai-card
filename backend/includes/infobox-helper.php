<?php
/**
 * 情報BOX（取引ごとの書類共有・関係者連絡・原本受渡し・取引台帳）共通ヘルパー。
 * 仕様：「不動産AI名刺_情報BOX開発仕様書 2026.9.24」
 *
 * 用語
 *   所有者 … 不動産AI名刺の所有者（BOXを作った仲介担当者。ログイン中の users）
 *   関係者 … BOXに登録された人（所有者自身も1行の関係者として持つ）
 *   書類登録者 … その書類をアップロードした本人
 *   フォルダー責任者 … 追加フォルダーを作った仲介担当者
 *
 * 最優先ルール（第2章）
 *   書類の中身は「書類登録者」と「登録者が指定した相手」だけが閲覧・印刷できる。
 *   所有者・階層管理者・運営であっても、指定されていない書類は一覧・件数・通知にも出さない。
 *   → 書類の可視判定は必ず iboxDocVisibleTo() を通す。所有者であることを理由に飛ばさない。
 *
 * 利用可否
 *   会社（宅建業免許番号）ごとに運営の管理画面で ON / OFF する（階層機能と同じ単位）。
 *   OFF の会社の所有者にはメニューを出さず、APIも拒否する。データは消さない。
 */

require_once __DIR__ . '/org-hierarchy-helper.php';

if (!defined('IBOX_MAX_FILE_BYTES')) define('IBOX_MAX_FILE_BYTES', 20 * 1024 * 1024);
if (!defined('IBOX_INVITE_DAYS')) define('IBOX_INVITE_DAYS', 7);
if (!defined('IBOX_AUTH_MAX_FAILS')) define('IBOX_AUTH_MAX_FAILS', 5);
if (!defined('IBOX_AUTH_LOCK_MINUTES')) define('IBOX_AUTH_LOCK_MINUTES', 15);
if (!defined('IBOX_SESSION_IDLE_SECONDS')) define('IBOX_SESSION_IDLE_SECONDS', 30 * 60);
if (!defined('IBOX_SESSION_MAX_SECONDS')) define('IBOX_SESSION_MAX_SECONDS', 8 * 3600);

/* ──────────────────────────────────────────────────────────
 * テーブル（マイグレーション未実行でも動くよう冪等に作成）
 * 定義は backend/database/migrations/20260928_add_infobox.sql と同じ。
 * ────────────────────────────────────────────────────────── */
function iboxEnsureTables(PDO $db): void
{
    static $done = false;
    if ($done) return;

    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_boxes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_user_id INT NOT NULL,
        business_card_id INT NULL DEFAULT NULL,
        property_id INT NULL DEFAULT NULL,
        transaction_code VARCHAR(40) NOT NULL,
        property_name VARCHAR(255) NULL DEFAULT NULL,
        address VARCHAR(255) NULL DEFAULT NULL,
        price BIGINT NULL DEFAULT NULL,
        property_type ENUM('mansion','house') NOT NULL DEFAULT 'mansion',
        contract_planned_date DATE NULL DEFAULT NULL,
        owner_side ENUM('buyer','seller') NOT NULL DEFAULT 'buyer',
        dual_agency TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('active','ended') NOT NULL DEFAULT 'active',
        end_date DATE NULL DEFAULT NULL,
        access_until DATETIME NULL DEFAULT NULL,
        ended_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_boxes_code (transaction_code),
        INDEX idx_ibox_boxes_owner (owner_user_id, status),
        INDEX idx_ibox_boxes_property (property_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        role VARCHAR(24) NOT NULL,
        is_owner TINYINT(1) NOT NULL DEFAULT 0,
        user_id INT NULL DEFAULT NULL,
        company_name VARCHAR(255) NULL DEFAULT NULL,
        name VARCHAR(128) NULL DEFAULT NULL,
        address VARCHAR(255) NULL DEFAULT NULL,
        email VARCHAR(255) NULL DEFAULT NULL,
        phone VARCHAR(64) NULL DEFAULT NULL,
        phone_norm VARCHAR(32) NULL DEFAULT NULL,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        continue_access TINYINT(1) NOT NULL DEFAULT 0,
        registered_by INT NULL DEFAULT NULL,
        updated_by INT NULL DEFAULT NULL,
        notify_status ENUM('unsent','sending','sent','failed') NOT NULL DEFAULT 'unsent',
        notified_at DATETIME NULL DEFAULT NULL,
        notify_error VARCHAR(255) NULL DEFAULT NULL,
        auth_version INT NOT NULL DEFAULT 1,
        fail_count INT NOT NULL DEFAULT 0,
        locked_until DATETIME NULL DEFAULT NULL,
        first_access_at DATETIME NULL DEFAULT NULL,
        last_access_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_ibox_participants_box (box_id, status),
        INDEX idx_ibox_participants_user (user_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        participant_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        auth_version INT NOT NULL DEFAULT 1,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_invites_token (token_hash),
        INDEX idx_ibox_invites_participant (participant_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        template_id VARCHAR(8) NULL DEFAULT NULL,
        name VARCHAR(255) NOT NULL,
        target_name VARCHAR(128) NULL DEFAULT NULL,
        is_initial TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL DEFAULT NULL,
        list_principals TEXT NULL DEFAULT NULL,
        upload_principals TEXT NULL DEFAULT NULL,
        unneeded_state ENUM('none','requested','confirmed') NOT NULL DEFAULT 'none',
        unneeded_reason VARCHAR(500) NULL DEFAULT NULL,
        unneeded_requested_by INT NULL DEFAULT NULL,
        unneeded_confirmed_by INT NULL DEFAULT NULL,
        unneeded_at DATETIME NULL DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_ibox_folders_box (box_id, deleted_at, sort_order)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        folder_id INT NOT NULL,
        uploader_id INT NOT NULL,
        display_name VARCHAR(255) NOT NULL,
        current_version INT NOT NULL DEFAULT 1,
        status ENUM('active','deleted') NOT NULL DEFAULT 'active',
        op_key VARCHAR(64) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uk_ibox_documents_op (uploader_id, op_key),
        INDEX idx_ibox_documents_folder (folder_id, status),
        INDEX idx_ibox_documents_box (box_id, status)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_document_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        version INT NOT NULL,
        stored_name VARCHAR(128) NOT NULL,
        preview_name VARCHAR(128) NULL DEFAULT NULL,
        original_name VARCHAR(255) NOT NULL,
        ext VARCHAR(8) NOT NULL,
        mime_type VARCHAR(127) NOT NULL,
        byte_size INT NOT NULL DEFAULT 0,
        sha256 CHAR(64) NULL DEFAULT NULL,
        op_key VARCHAR(64) NULL DEFAULT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_document_versions (document_id, version),
        UNIQUE KEY uk_ibox_document_versions_op (document_id, op_key)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_document_shares (
        document_id INT NOT NULL,
        participant_id INT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (document_id, participant_id),
        INDEX idx_ibox_document_shares_p (participant_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_originals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        folder_id INT NOT NULL,
        created_by INT NOT NULL,
        document_id INT NULL DEFAULT NULL,
        target_name VARCHAR(128) NULL DEFAULT NULL,
        doc_name VARCHAR(255) NOT NULL,
        required_state ENUM('unknown','required','not_required') NOT NULL DEFAULT 'unknown',
        copies INT NULL DEFAULT NULL,
        submit_to VARCHAR(255) NULL DEFAULT NULL,
        deadline DATE NULL DEFAULT NULL,
        issued_date DATE NULL DEFAULT NULL,
        deadline_condition VARCHAR(500) NULL DEFAULT NULL,
        keeper VARCHAR(128) NULL DEFAULT NULL,
        remarks TEXT NULL DEFAULT NULL,
        status ENUM('unconfirmed','requesting','obtained','submitted','returned','not_needed') NOT NULL DEFAULT 'unconfirmed',
        viewers TEXT NULL DEFAULT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_ibox_originals_folder (folder_id, deleted_at)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_original_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_id INT NOT NULL,
        status VARCHAR(16) NOT NULL,
        event_date DATE NULL DEFAULT NULL,
        copies INT NULL DEFAULT NULL,
        counterparty VARCHAR(255) NULL DEFAULT NULL,
        confirmed_by_name VARCHAR(128) NULL DEFAULT NULL,
        note VARCHAR(500) NULL DEFAULT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ibox_original_events (original_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        kind ENUM('all','direct') NOT NULL DEFAULT 'all',
        p_low INT NOT NULL DEFAULT 0,
        p_high INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_conversations (box_id, kind, p_low, p_high)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        box_id INT NOT NULL,
        sender_id INT NOT NULL,
        body TEXT NULL DEFAULT NULL,
        attach_stored VARCHAR(128) NULL DEFAULT NULL,
        attach_name VARCHAR(255) NULL DEFAULT NULL,
        attach_mime VARCHAR(127) NULL DEFAULT NULL,
        attach_size INT NULL DEFAULT NULL,
        client_uid VARCHAR(64) NULL DEFAULT NULL,
        edited_at DATETIME NULL DEFAULT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_messages_uid (conversation_id, sender_id, client_uid),
        INDEX idx_ibox_messages_conv (conversation_id, id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_message_reads (
        conversation_id INT NOT NULL,
        participant_id INT NOT NULL,
        last_read_id INT NOT NULL DEFAULT 0,
        PRIMARY KEY (conversation_id, participant_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_ledger_drafts (
        box_id INT NOT NULL PRIMARY KEY,
        data_json LONGTEXT NULL DEFAULT NULL,
        updated_at DATETIME NOT NULL
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_ledgers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        owner_user_id INT NOT NULL,
        version INT NOT NULL,
        ledger_no VARCHAR(64) NULL DEFAULT NULL,
        office_name VARCHAR(255) NULL DEFAULT NULL,
        fiscal_year INT NULL DEFAULT NULL,
        data_json LONGTEXT NOT NULL,
        pdf_name VARCHAR(128) NOT NULL,
        reason VARCHAR(500) NULL DEFAULT NULL,
        op_key VARCHAR(64) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uk_ibox_ledgers_version (box_id, version),
        UNIQUE KEY uk_ibox_ledgers_op (box_id, op_key),
        INDEX idx_ibox_ledgers_owner (owner_user_id, fiscal_year)
    ) $opts");

    // 事業年度末の台帳閉鎖（office_name が空なら全事務所）。閉鎖後も削除せず retain_until まで以上保持する。
    $db->exec("CREATE TABLE IF NOT EXISTS ibox_ledger_closures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_user_id INT NOT NULL,
        fiscal_year INT NOT NULL,
        office_name VARCHAR(255) NOT NULL DEFAULT '',
        closed_at DATETIME NOT NULL,
        retain_until DATE NOT NULL,
        UNIQUE KEY uk_ibox_ledger_closures (owner_user_id, fiscal_year, office_name)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS ibox_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        box_id INT NOT NULL,
        participant_id INT NULL DEFAULT NULL,
        action VARCHAR(48) NOT NULL,
        target_type VARCHAR(24) NULL DEFAULT NULL,
        target_id INT NULL DEFAULT NULL,
        detail TEXT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ibox_audit_box (box_id, id)
    ) $opts");

    iboxEnsureLicenseColumn($db);
    $done = true;
}

/* ──────────────────────────────────────────────────────────
 * 運営による会社ごとの ON / OFF（階層機能と同じ org_license_settings を使う）
 * ────────────────────────────────────────────────────────── */
function iboxEnsureLicenseColumn(PDO $db): void
{
    static $done = false;
    if ($done) return;
    orgEnsureLicenseSettingsTable($db);
    try {
        $columns = [];
        foreach ($db->query('SHOW COLUMNS FROM org_license_settings')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[strtolower($column['Field'])] = true;
        }
        if (!isset($columns['infobox_enabled'])) {
            $db->exec("ALTER TABLE org_license_settings
                ADD COLUMN infobox_enabled TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1=情報BOXを使える / 0=使えない'");
        }
        $done = true;
    } catch (Exception $e) {
        // 追加できなくても既存機能は壊さない（情報BOXが OFF 扱いになるだけ）。
        error_log('iboxEnsureLicenseColumn error: ' . $e->getMessage());
    }
}

/** その免許番号（会社）で情報BOXを使えるか。行が無い会社は OFF。 */
function iboxEnabledForKey(PDO $db, string $licenseKey): bool
{
    if ($licenseKey === '') return false;
    iboxEnsureLicenseColumn($db);
    static $cache = [];
    if (array_key_exists($licenseKey, $cache)) return $cache[$licenseKey];
    try {
        $stmt = $db->prepare('SELECT infobox_enabled FROM org_license_settings WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $value = $stmt->fetchColumn();
        $cache[$licenseKey] = ($value !== false && (int)$value === 1);
    } catch (Exception $e) {
        error_log('iboxEnabledForKey error: ' . $e->getMessage());
        $cache[$licenseKey] = false;
    }
    return $cache[$licenseKey];
}

/** ログイン中の名刺所有者が情報BOXを使えるか（所属会社が ON のとき）。 */
function iboxEnabledForUser(PDO $db, int $userId): bool
{
    if ($userId <= 0) return false;
    return iboxEnabledForKey($db, orgLicenseForUser($db, $userId)['key']);
}

/**
 * 会社ごとの情報BOXを ON / OFF する（運営の管理画面からのみ呼ぶ）。
 * 階層機能の設定（hierarchy_enabled / admin_email）には触れない。
 */
function iboxSetEnabled(PDO $db, string $licenseKey, string $licenseText, string $companyName, bool $enabled, ?int $adminId = null): bool
{
    if ($licenseKey === '') return false;
    iboxEnsureLicenseColumn($db);
    try {
        $stmt = $db->prepare('
            INSERT INTO org_license_settings (license_key, license_text, company_name, infobox_enabled, updated_by_admin_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                license_text = IF(VALUES(license_text) = \'\', license_text, VALUES(license_text)),
                company_name = IF(VALUES(company_name) = \'\', company_name, VALUES(company_name)),
                infobox_enabled = VALUES(infobox_enabled),
                updated_by_admin_id = VALUES(updated_by_admin_id)
        ');
        $stmt->execute([$licenseKey, $licenseText, $companyName, $enabled ? 1 : 0, $adminId]);
        return true;
    } catch (Exception $e) {
        error_log('iboxSetEnabled error: ' . $e->getMessage());
        return false;
    }
}

/** 会社ごとの ON / OFF 一覧（運営画面用）。 */
function iboxFetchLicenseSettings(PDO $db): array
{
    iboxEnsureLicenseColumn($db);
    $out = [];
    try {
        foreach ($db->query('SELECT license_key, infobox_enabled, updated_at FROM org_license_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['license_key']] = [
                'enabled' => ((int)$row['infobox_enabled'] === 1),
                'updated_at' => $row['updated_at'],
            ];
        }
    } catch (Exception $e) {
        error_log('iboxFetchLicenseSettings error: ' . $e->getMessage());
    }
    return $out;
}

/* ──────────────────────────────────────────────────────────
 * 役割と書類カタログ（第5章 統合一覧）
 * ────────────────────────────────────────────────────────── */

/** 役割の定義。kind=person は個人（氏名のみ一覧表示）、company は会社・事務所。 */
function iboxRoles(): array
{
    return [
        'buyer1'       => ['label' => '買主1',         'side' => 'buyer',  'kind' => 'person',  'party' => true],
        'buyer2'       => ['label' => '買主2',         'side' => 'buyer',  'kind' => 'person',  'party' => true],
        'buyer_agent'  => ['label' => '買主仲介会社',  'side' => 'buyer',  'kind' => 'company', 'agent' => true],
        'seller1'      => ['label' => '売主1',         'side' => 'seller', 'kind' => 'person',  'party' => true],
        'seller2'      => ['label' => '売主2',         'side' => 'seller', 'kind' => 'person',  'party' => true],
        'seller_agent' => ['label' => '売主仲介会社',  'side' => 'seller', 'kind' => 'company', 'agent' => true],
        'scrivener'    => ['label' => '司法書士',      'side' => '',       'kind' => 'company'],
        'surveyor'     => ['label' => '土地家屋調査士', 'side' => '',      'kind' => 'company'],
        'other'        => ['label' => 'その他の関係者', 'side' => '',      'kind' => 'company', 'multi' => true],
    ];
}

function iboxRoleLabel(string $role): string
{
    $roles = iboxRoles();
    return $roles[$role]['label'] ?? $role;
}

/** 仕様書の略号（買・売・買仲・売仲・司・調 …）を役割コードに展開する。 */
function iboxExpandRoleCodes(string $codes): array
{
    $map = [
        '買'         => ['buyer1', 'buyer2'],
        '売'         => ['seller1', 'seller2'],
        '買仲'       => ['buyer_agent'],
        '売仲'       => ['seller_agent'],
        '司'         => ['scrivener'],
        '調'         => ['surveyor'],
        '対象者'     => ['buyer1', 'buyer2', 'seller1', 'seller2'],
        '該当当事者' => ['buyer1', 'buyer2', 'seller1', 'seller2'],
        '該当側仲介' => ['buyer_agent', 'seller_agent'],
    ];
    $roles = [];
    foreach (preg_split('/[・,\s]+/u', $codes) ?: [] as $code) {
        foreach ($map[$code] ?? [] as $role) $roles[$role] = true;
    }
    return array_keys($roles);
}

/**
 * 書類フォルダーの統合カタログ。
 * [ID, 書類名, 適用(common/mansion/house), 基本項目か, 担当候補, 閲覧候補, 用途]
 * 基本項目は BOX 作成時に初期フォルダーとして作る。A項目は仲介担当者が必要時に追加する。
 */
function iboxCatalog(): array
{
    $all4 = '買・買仲・売仲・売';
    $rows = [
        ['01', '媒介契約書 買主側', 'common', true, '買仲', '買・買仲', ''],
        ['02', '媒介契約書 買主側 電子署名証明書', 'common', true, '買仲', '買・買仲', ''],
        ['03', '媒介契約書 売主側', 'common', true, '売仲', '売・売仲', ''],
        ['04', '媒介契約書 売主側 電子署名証明書', 'common', true, '売仲', '売・売仲', ''],
        ['05', '委任状・本人確認資料', 'common', true, '買・売', '対象者・該当側仲介', '対象者（買主1・売主1など）ごとに分けてご利用ください。'],
        ['06', '買付証明書', 'common', true, '買仲', $all4, ''],
        ['07', '売買契約書', 'common', true, '買仲・売仲', $all4 . '・司', ''],
        ['08', '売買契約書 電子署名証明書', 'common', true, '買仲', $all4, ''],
        ['09', '重要事項説明書', 'common', true, '売仲', $all4 . '・司', ''],
        ['10', '重要事項説明書 電子署名証明書', 'common', true, '買仲', '買・買仲', ''],
        ['11', '重説別添資料', 'common', true, '売仲', '買・買仲・売仲', ''],
        ['12', 'アスベスト調査報告書', 'common', true, '売仲', $all4, ''],
        ['13', 'ハザード情報関連', 'common', true, '売仲', $all4, ''],
        ['14', '付帯設備表', 'common', true, '売仲', $all4, ''],
        ['15', '状況確認書', 'common', true, '売仲', $all4, ''],
        ['16', '登記事項証明書・登記情報', 'common', true, '売仲', $all4 . '・司', ''],
        ['17', '建築確認済証・検査済証・台帳記載事項証明書', 'common', true, '売仲', $all4, ''],
        ['18', '固定資産税・都市計画税精算書等', 'common', true, '売仲', $all4 . '・司', ''],
        ['19', '管理規約・使用細則等', 'mansion', true, '売仲', $all4, ''],
        ['20', '長期修繕計画', 'mansion', true, '売仲', $all4, ''],
        ['21', '管理組合総会資料等', 'mansion', true, '売仲', $all4, ''],
        ['22', '重要事項に係る調査報告書', 'mansion', true, '売仲', $all4, ''],
        ['23', 'パンフレットなどその他資料', 'mansion', true, '売仲', $all4, ''],
        ['24', '管理費など口座振替書類', 'mansion', true, '売仲', '買・買仲・売仲', ''],
        ['25', 'その他関連資料（マンション）', 'mansion', true, '売仲', $all4, ''],
        ['26', 'インフラ関連 電気・ガス・水道', 'house', true, '売仲', $all4, ''],
        ['27', 'その他関連資料（一戸建て）', 'house', true, '売仲', $all4, ''],
        ['28', '公図', 'house', true, '売仲', $all4, ''],
        ['29', '設計図書', 'house', true, '売仲', $all4, ''],
        ['30', '瑕疵保険関連資料', 'common', true, '買仲', '買・買仲', ''],
        ['31', '精算書 買主用', 'common', true, '買仲', '買・買仲', ''],
        ['32', '精算書 売主用', 'common', true, '売仲', '売・売仲', ''],
        ['33', '火災保険見積もり', 'common', true, '買仲', '買・買仲', ''],
        ['34', '仲介手数料支払い承諾書 買主側', 'common', true, '買仲', '買・買仲', ''],
        ['35', '仲介手数料支払い承諾書 売主側', 'common', true, '売仲', '売・売仲', ''],
        ['A02', '耐震診断・耐震改修資料', 'common', false, '売仲', $all4, '耐震診断・改修記録がある場合。'],
        ['A03', '住宅性能評価書', 'common', false, '売仲', $all4, '住宅性能評価がある場合。設計評価と建設評価は区別してください。'],
        ['A04', '長期優良住宅等の認定資料', 'common', false, '売仲', $all4, '長期優良住宅等の認定がある場合。'],
        ['A05', '増改築・修繕履歴', 'common', false, '売・売仲', $all4, '増改築・修繕の履歴がある場合。'],
        ['A06', '設備保証書・取扱説明書', 'common', false, '売・売仲', $all4, '設備の保証書・取扱説明書がある場合。'],
        ['A07', '固定資産評価証明書・課税明細書', 'common', false, '売仲', '売・売仲・司', '登記や精算の評価・課税資料。精算書とは区別してください。'],
        ['A08', '契約変更覚書・合意書', 'common', false, '買仲・売仲', $all4, '契約内容を変更・合意した場合。'],
        ['A09', '手付金・残代金領収書', 'common', false, '売・売仲', $all4, '手付金・残代金の受領を確認する場合。'],
        ['A10', '仲介手数料請求書・領収書', 'common', false, '該当側仲介', '該当当事者・該当側仲介', '買主用と売主用を分けて追加してください。'],
        ['A11', '引渡確認書・鍵受領書', 'common', false, '買仲・売仲', $all4, '引渡し・鍵の受領を確認する場合。'],
        ['A12', '登記費用見積・明細・領収書', 'common', false, '司', '該当当事者・該当側仲介・司', '買主用と売主用を分けて追加してください。'],
        ['A13', '登記完了証・完了後確認資料', 'common', false, '司', '該当当事者・該当側仲介・司', '登記識別情報の秘密部分は含めないでください。'],
        ['A15', '理事会議事録', 'mansion', false, '売仲', $all4, '共有可能な理事会議事録がある場合。'],
        ['A16', '管理費等の滞納・精算資料', 'mansion', false, '売仲', $all4, '対象住戸の滞納・精算を確認する場合。'],
        ['A19', '駐車場・駐輪場等の利用関係資料', 'mansion', false, '売仲', $all4, '駐車場等の利用・承継条件を確認する場合。'],
        ['A20', '専有部図面・専用使用部分資料', 'mansion', false, '売仲', $all4, '専有部・専用使用部分を確認する場合。'],
        ['A21', '地積・確定・現況測量図', 'house', false, '売仲・調', $all4 . '・調', '地積・確定・現況の区別、作成日・作成者を確認してください。'],
        ['A22', '境界確認書・境界資料', 'house', false, '売仲・調', $all4 . '・調', '境界確認の資料がある場合。'],
        ['A23', '越境覚書・合意書', 'house', false, '売仲', $all4 . '・調', '越境の覚書・合意がある場合。'],
        ['A24', '道路・私道・通行掘削承諾', 'house', false, '売仲', $all4, '道路・私道の通行掘削承諾がある場合。'],
        ['A25', '確認申請副本・建物図面', 'house', false, '売仲', $all4, '既存の設計図書に不足する場合。'],
        ['A26', '造成・擁壁関係資料', 'house', false, '売仲', $all4, '造成・擁壁の資料がある場合。'],
        ['A27', '配管図・引込図', 'house', false, '売仲', $all4, '配管・引込の資料がある場合。'],
        ['A28', '防蟻保証・施工記録', 'house', false, '売・売仲', $all4, '防蟻の保証・施工記録がある場合。'],
        ['A29', '太陽光設備関係資料', 'house', false, '売・売仲', $all4, '太陽光設備がある場合。'],
        ['A30', '借地契約・地主承諾関係', 'common', false, '売仲', $all4 . '・司', '借地の場合に追加します。'],
        ['A31', '賃貸借契約・敷金承継資料', 'common', false, '売仲', $all4, '賃貸借を承継する場合に追加します。'],
        ['A32', '相続・共有・代理関係資料', 'common', false, '該当側仲介・司', '該当当事者・該当側仲介・司', '対象者・書類ごとに分けて追加してください。'],
        ['A33', '農地転用等の関係資料', 'common', false, '売仲', $all4 . '・司', '農地等の場合に追加します。'],
        ['A34', '法人資格・代表権確認資料', 'common', false, '該当側仲介・司', '該当当事者・該当側仲介・司', '法人ごとに分けて追加してください。'],
        ['A35', '住民票・印鑑証明書・戸籍等', 'common', false, '該当当事者・該当側仲介', '該当当事者・該当側仲介・司', '提出先から請求された場合に、対象者ごとに追加してください。'],
    ];

    $catalog = [];
    foreach ($rows as $row) {
        $catalog[$row[0]] = [
            'id' => $row[0],
            'name' => $row[1],
            'applies' => $row[2],
            'basic' => $row[3],
            'uploader_codes' => $row[4],
            'viewer_codes' => $row[5],
            'uploader_roles' => iboxExpandRoleCodes($row[4]),
            'viewer_roles' => iboxExpandRoleCodes($row[5]),
            'purpose' => $row[6],
        ];
    }
    return $catalog;
}

/** その物件種別で使えるカタログ項目か。 */
function iboxCatalogApplies(array $item, string $propertyType): bool
{
    return $item['applies'] === 'common' || $item['applies'] === $propertyType;
}

/* ──────────────────────────────────────────────────────────
 * 小さな共通処理
 * ────────────────────────────────────────────────────────── */
function iboxNow(): string
{
    return date('Y-m-d H:i:s');
}

/** 電話番号の照合用正規化（全角→半角、空白・ハイフン等の除去、+81→0）。 */
function iboxNormalizePhone(?string $phone): string
{
    $phone = mb_convert_kana((string)$phone, 'as', 'UTF-8');
    $phone = preg_replace('/^\s*\+\s*81/u', '0', $phone) ?? '';
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function iboxNormalizeEmail(?string $email): string
{
    return strtolower(trim(mb_convert_kana((string)$email, 'a', 'UTF-8')));
}

/** 取引終了日から、その他の関係者の利用期限（翌月同日 23:59:59、同日が無ければ翌月末）を返す。 */
function iboxAccessUntil(string $endDate): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $endDate, new DateTimeZone('Asia/Tokyo'));
    if (!$d) return '';
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n') + 1;
    if ($m > 12) { $m = 1; $y++; }
    $day = (int)$d->format('j');
    $last = iboxDaysInMonth($m, $y);
    return sprintf('%04d-%02d-%02d 23:59:59', $y, $m, min($day, $last));
}

/** calendar 拡張が無い環境でも動く月末日。 */
function iboxDaysInMonth(int $month, int $year): int
{
    return (int)date('t', mktime(0, 0, 0, $month, 1, $year));
}

function iboxDecodeList(?string $json): array
{
    if ($json === null || $json === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? array_values(array_filter(array_map('strval', $data), 'strlen')) : [];
}

function iboxAudit(PDO $db, int $boxId, ?int $participantId, string $action, ?string $targetType = null, ?int $targetId = null, array $detail = []): void
{
    try {
        $stmt = $db->prepare('INSERT INTO ibox_audit_logs (box_id, participant_id, action, target_type, target_id, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$boxId, $participantId, $action, $targetType, $targetId, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, iboxNow()]);
    } catch (Exception $e) {
        error_log('iboxAudit error: ' . $e->getMessage());
    }
}

/** 書類・添付・台帳PDFの保存先（直接アクセスは .htaccess で禁止し、認可付きの file.php からのみ配信）。 */
function iboxStorageDir(int $boxId): string
{
    $base = __DIR__ . '/../uploads/infobox';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    $deny = $base . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $dir = $base . '/' . $boxId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function iboxRandomName(string $ext): string
{
    return bin2hex(random_bytes(16)) . '.' . strtolower($ext);
}

/* ──────────────────────────────────────────────────────────
 * BOX・関係者の読み込み
 * ────────────────────────────────────────────────────────── */
function iboxLoadBox(PDO $db, int $boxId): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibox_boxes WHERE id = ? LIMIT 1');
    $stmt->execute([$boxId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function iboxLoadParticipant(PDO $db, int $participantId): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibox_participants WHERE id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** BOXの関係者（既定は参加停止を除く）。 */
function iboxParticipants(PDO $db, int $boxId, bool $includeSuspended = false): array
{
    $sql = 'SELECT * FROM ibox_participants WHERE box_id = ?' . ($includeSuspended ? '' : " AND status = 'active'") . ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([$boxId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 表示名（会社なら「会社名 氏名」、個人なら氏名）。 */
function iboxParticipantName(array $p): string
{
    $name = trim((string)($p['name'] ?? ''));
    $company = trim((string)($p['company_name'] ?? ''));
    if ($name !== '') return $name;
    return $company !== '' ? $company : '（未入力）';
}

/** 役割の表示（所有者は「名刺所有者・買主仲介」など）。 */
function iboxParticipantRoleLabel(array $box, array $p): string
{
    if ((int)$p['is_owner'] === 1) {
        $label = (int)$box['dual_agency'] === 1 ? '買主仲介・売主仲介（両手）' : ($box['owner_side'] === 'seller' ? '売主仲介' : '買主仲介');
        return '名刺所有者・' . $label;
    }
    return iboxRoleLabel((string)$p['role']);
}

/** その関係者が持つ役割（所有者が両手仲介なら買主仲介・売主仲介の両方）。 */
function iboxViewerRoles(array $box, array $p): array
{
    $roles = [(string)$p['role']];
    if ((int)$p['is_owner'] === 1 && (int)$box['dual_agency'] === 1) {
        $roles = ['buyer_agent', 'seller_agent'];
    }
    return $roles;
}

function iboxIsAgent(array $box, array $p): bool
{
    return (bool)array_intersect(iboxViewerRoles($box, $p), ['buyer_agent', 'seller_agent']);
}

/** 「role:xxx」「p:123」の一覧に、その関係者が当てはまるか。 */
function iboxPrincipalMatch(array $principals, array $box, array $p): bool
{
    if (in_array('p:' . (int)$p['id'], $principals, true)) return true;
    foreach (iboxViewerRoles($box, $p) as $role) {
        if (in_array('role:' . $role, $principals, true)) return true;
    }
    return false;
}

/* ──────────────────────────────────────────────────────────
 * 取引終了後のアクセス期限（第4章）
 * ────────────────────────────────────────────────────────── */

/**
 * その関係者が今 BOX を使えるか。
 * @return array{ok:bool, reason:string, until:?string, continuing:bool}
 */
function iboxAccessState(array $box, array $p, ?string $now = null): array
{
    $now = $now ?? iboxNow();
    if ($p['status'] !== 'active') {
        return ['ok' => false, 'reason' => 'suspended', 'until' => null, 'continuing' => false];
    }
    $continuing = (int)$p['is_owner'] === 1 || (int)$p['continue_access'] === 1;
    if ($box['status'] !== 'ended' || empty($box['access_until'])) {
        return ['ok' => true, 'reason' => '', 'until' => null, 'continuing' => $continuing];
    }
    if ($continuing) {
        return ['ok' => true, 'reason' => '', 'until' => null, 'continuing' => true];
    }
    if ($now > $box['access_until']) {
        return ['ok' => false, 'reason' => 'expired', 'until' => $box['access_until'], 'continuing' => false];
    }
    return ['ok' => true, 'reason' => '', 'until' => $box['access_until'], 'continuing' => false];
}

/** 期限経過後の BOX は通常の書込みを停止する（所有者の台帳追記・再生成のみ可）。 */
function iboxIsReadOnly(array $box, ?string $now = null): bool
{
    $now = $now ?? iboxNow();
    return $box['status'] === 'ended' && !empty($box['access_until']) && $now > $box['access_until'];
}

/* ──────────────────────────────────────────────────────────
 * 閲覧者の特定（すべてのAPIの入口）
 *   ① 名刺所有者：ログイン中の users.id が BOX の所有者（会社の ON も必要）
 *   ② 招待された関係者：受信者専用URL＋メール・電話の照合で作ったセッション
 * ────────────────────────────────────────────────────────── */

/** 招待関係者のセッションを確立する。 */
function iboxSessionLogin(array $p): void
{
    startSessionIfNotStarted();
    if (!headers_sent()) session_regenerate_id(true);
    if (!isset($_SESSION['ibox_auth']) || !is_array($_SESSION['ibox_auth'])) $_SESSION['ibox_auth'] = [];
    $_SESSION['ibox_auth'][(int)$p['box_id']] = [
        'pid' => (int)$p['id'],
        'v' => (int)$p['auth_version'],
        'at' => time(),
        'last' => time(),
    ];
}

function iboxSessionLogout(int $boxId): void
{
    startSessionIfNotStarted();
    unset($_SESSION['ibox_auth'][$boxId]);
}

/** セッション中の、この BOX の関係者ID（無操作30分・最長8時間で失効）。 */
function iboxSessionParticipantId(int $boxId): int
{
    startSessionIfNotStarted();
    $entry = $_SESSION['ibox_auth'][$boxId] ?? null;
    if (!is_array($entry)) return 0;
    $now = time();
    if ($now - (int)$entry['last'] > IBOX_SESSION_IDLE_SECONDS || $now - (int)$entry['at'] > IBOX_SESSION_MAX_SECONDS) {
        unset($_SESSION['ibox_auth'][$boxId]);
        return 0;
    }
    $_SESSION['ibox_auth'][$boxId]['last'] = $now;
    return (int)$entry['pid'];
}

/**
 * 今のリクエストの閲覧者を返す。使えない場合は ['error'=>..., 'code'=>...]。
 * @return array{box:array, p:array, is_owner:bool, readonly:bool, access:array}|array{error:string, code:int, reason:string}
 */
function iboxResolveViewer(PDO $db, int $boxId): array
{
    $box = $boxId > 0 ? iboxLoadBox($db, $boxId) : null;
    if (!$box) return ['error' => '情報BOXが見つかりません。', 'code' => 404, 'reason' => 'not_found'];

    // 会社が OFF の所有者の BOX は、機能ごと表示しない（データは保持）。
    if (!iboxEnabledForUser($db, (int)$box['owner_user_id'])) {
        return ['error' => '情報BOXは現在ご利用いただけません。', 'code' => 403, 'reason' => 'disabled'];
    }

    startSessionIfNotStarted();
    $p = null;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && $userId === (int)$box['owner_user_id']) {
        $stmt = $db->prepare('SELECT * FROM ibox_participants WHERE box_id = ? AND is_owner = 1 LIMIT 1');
        $stmt->execute([$boxId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$p) {
        $pid = iboxSessionParticipantId($boxId);
        if ($pid > 0) {
            $cand = iboxLoadParticipant($db, $pid);
            $entry = $_SESSION['ibox_auth'][$boxId] ?? [];
            // 連絡先の変更・参加停止で auth_version が上がると、既存セッションは失効する。
            if ($cand && (int)$cand['box_id'] === $boxId && (int)$cand['is_owner'] === 0
                && (int)$cand['auth_version'] === (int)($entry['v'] ?? -1)) {
                $p = $cand;
            } else {
                iboxSessionLogout($boxId);
            }
        }
    }
    if (!$p && $userId > 0) {
        // 招待URLで本人確認したときにログイン中だった既存アカウントは、同じ関係者IDに紐づけてある。
        // 継続閲覧者などは、以後この既存アカウントから再ログインして開ける（連絡先変更・参加停止で紐づけは外れる）。
        $stmt = $db->prepare('SELECT * FROM ibox_participants WHERE box_id = ? AND user_id = ? AND is_owner = 0 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$boxId, $userId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$p) return ['error' => 'この情報BOXを開く権限がありません。ご案内メールのURLからアクセスしてください。', 'code' => 401, 'reason' => 'unauthenticated'];

    $access = iboxAccessState($box, $p);
    if (!$access['ok']) {
        $message = $access['reason'] === 'expired'
            ? 'この情報BOXの利用期限（' . date('Y年n月j日 H:i', strtotime($access['until'])) . '）を過ぎました。'
            : 'この情報BOXへの参加は停止されています。';
        return ['error' => $message . 'ご不明点はご案内担当者へお問い合わせください。', 'code' => 403, 'reason' => $access['reason']];
    }

    return [
        'box' => $box,
        'p' => $p,
        'is_owner' => (int)$p['is_owner'] === 1,
        'readonly' => iboxIsReadOnly($box),
        'access' => $access,
    ];
}

/* ──────────────────────────────────────────────────────────
 * 関係者の登録・修正の権限（第1章）
 * ────────────────────────────────────────────────────────── */

/** その閲覧者が、この役割の関係者を新規登録できるか。 */
function iboxCanRegisterRole(array $box, array $viewer, string $role): bool
{
    $isOwner = (int)$viewer['is_owner'] === 1;
    $roles = iboxViewerRoles($box, $viewer);
    switch ($role) {
        case 'buyer_agent':
        case 'seller_agent':
            // 相手仲介の登録は所有者だけ。所有者自身の側（両手なら両方）は所有者の行が兼ねる。
            if (!$isOwner) return false;
            if ((int)$box['dual_agency'] === 1) return false;
            return $role !== ($box['owner_side'] === 'seller' ? 'seller_agent' : 'buyer_agent');
        case 'buyer1':
        case 'buyer2':
            return in_array('buyer_agent', $roles, true);
        case 'seller1':
        case 'seller2':
            return in_array('seller_agent', $roles, true);
        case 'scrivener':
        case 'surveyor':
            return iboxIsAgent($box, $viewer);
        case 'other':
            return $isOwner;
    }
    return false;
}

/** 既存の関係者を修正・参加停止できるのは、所有者とその情報の入力者だけ。 */
function iboxCanEditParticipant(array $viewer, array $target): bool
{
    if ((int)$target['is_owner'] === 1) return (int)$viewer['is_owner'] === 1;
    return (int)$viewer['is_owner'] === 1 || (int)$target['registered_by'] === (int)$viewer['id'];
}

/** 通知できるのは、所有者は全員、仲介担当者は自分が登録した関係者だけ。 */
function iboxCanNotifyParticipant(array $viewer, array $target): bool
{
    if ((int)$target['is_owner'] === 1) return false;
    return (int)$viewer['is_owner'] === 1 || (int)$target['registered_by'] === (int)$viewer['id'];
}

/**
 * 画面・APIに出す関係者情報。
 * 買主・売主の住所・メール・電話は、所有者と入力者の管理画面（$forManage=true）だけで返す。
 */
function iboxSerializeParticipant(array $box, array $p, array $viewer, array $nameById, bool $forManage = false): array
{
    $roles = iboxRoles();
    $isPerson = (int)$p['is_owner'] !== 1 && (($roles[$p['role']]['kind'] ?? 'company') === 'person');
    $canEdit = iboxCanEditParticipant($viewer, $p);
    $showContact = !$isPerson || ($forManage && $canEdit);

    $out = [
        'id' => (int)$p['id'],
        'role' => (string)$p['role'],
        'role_label' => iboxParticipantRoleLabel($box, $p),
        'is_owner' => (int)$p['is_owner'] === 1,
        'is_self' => (int)$p['id'] === (int)$viewer['id'],
        'is_person' => $isPerson,
        'name' => (string)($p['name'] ?? ''),
        'company_name' => $isPerson ? '' : (string)($p['company_name'] ?? ''),
        'display_name' => iboxParticipantName($p),
        'status' => (string)$p['status'],
        'registered_by_name' => $nameById[(int)$p['registered_by']] ?? ((int)$p['is_owner'] === 1 ? iboxParticipantName($p) : ''),
        'notify_status' => (int)$p['is_owner'] === 1 ? 'owner' : (string)$p['notify_status'],
        'notified_at' => $p['notified_at'],
        'can_edit' => $canEdit,
        'can_notify' => iboxCanNotifyParticipant($viewer, $p),
        'continue_access' => (int)$p['continue_access'] === 1,
    ];
    if ($showContact) {
        $out['address'] = (string)($p['address'] ?? '');
        $out['email'] = (string)($p['email'] ?? '');
        $out['phone'] = (string)($p['phone'] ?? '');
    }
    return $out;
}

/* ──────────────────────────────────────────────────────────
 * BOX 作成
 * ────────────────────────────────────────────────────────── */

/** 取引ID（TX-YYYYMMDD-XXXX）を発行する。 */
function iboxGenerateTransactionCode(PDO $db): string
{
    for ($i = 0; $i < 20; $i++) {
        $code = 'TX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $db->prepare('SELECT 1 FROM ibox_boxes WHERE transaction_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) return $code;
    }
    return 'TX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** 所有者の代表名刺（会社名・氏名・住所・電話）。 */
function iboxOwnerCard(PDO $db, int $userId): array
{
    $stmt = $db->prepare('
        SELECT bc.id, bc.company_name, bc.name, bc.company_address, bc.company_phone, bc.mobile_phone, bc.branch_department, u.email
        FROM business_cards bc
        JOIN users u ON u.id = bc.user_id
        WHERE bc.user_id = ?
        ORDER BY bc.id ASC
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * BOX を作る。所有者の関係者行・初期フォルダー・全員チャットも同時に作る。
 * @return int 作成した BOX の ID
 */
function iboxCreateBox(PDO $db, int $userId, array $data): int
{
    $card = iboxOwnerCard($db, $userId);
    $now = iboxNow();
    $type = ($data['property_type'] ?? '') === 'house' ? 'house' : 'mansion';
    $side = ($data['owner_side'] ?? '') === 'seller' ? 'seller' : 'buyer';

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO ibox_boxes (owner_user_id, business_card_id, property_id, transaction_code, property_name, address, price,
                property_type, contract_planned_date, owner_side, dual_agency, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)
        ');
        $stmt->execute([
            $userId,
            $card['id'] ?? null,
            $data['property_id'] ?? null,
            iboxGenerateTransactionCode($db),
            $data['property_name'] ?? null,
            $data['address'] ?? null,
            $data['price'] ?? null,
            $type,
            $data['contract_planned_date'] ?? null,
            $side,
            !empty($data['dual_agency']) ? 1 : 0,
            $now, $now,
        ]);
        $boxId = (int)$db->lastInsertId();

        $stmt = $db->prepare('
            INSERT INTO ibox_participants (box_id, role, is_owner, user_id, company_name, name, address, email, phone, phone_norm,
                status, notify_status, created_at, updated_at)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, \'active\', \'sent\', ?, ?)
        ');
        $phone = (string)($card['mobile_phone'] ?? ($card['company_phone'] ?? ''));
        $stmt->execute([
            $boxId,
            $side === 'seller' ? 'seller_agent' : 'buyer_agent',
            $userId,
            $card['company_name'] ?? null,
            $card['name'] ?? null,
            $card['company_address'] ?? null,
            $card['email'] ?? null,
            $phone,
            iboxNormalizePhone($phone),
            $now, $now,
        ]);
        $ownerPid = (int)$db->lastInsertId();

        $box = iboxLoadBox($db, $boxId);
        iboxCreateInitialFolders($db, $box);

        $db->prepare('INSERT INTO ibox_conversations (box_id, kind, p_low, p_high, created_at) VALUES (?, \'all\', 0, 0, ?)')
            ->execute([$boxId, $now]);

        $db->commit();
        iboxAudit($db, $boxId, $ownerPid, 'box_create', 'box', $boxId, ['property_name' => $data['property_name'] ?? '']);
        return $boxId;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 物件種別に応じた基本項目の初期フォルダーを作る（既にある項目は作らない）。
 * 担当・一覧公開は「候補」を初期値として入れ、所有者が画面14で変更できる。
 * 書類の中身の閲覧権限はここでは一切付与しない（書類ごとに登録者が指定する）。
 */
function iboxCreateInitialFolders(PDO $db, array $box): int
{
    $stmt = $db->prepare('SELECT template_id FROM ibox_folders WHERE box_id = ? AND is_initial = 1 AND deleted_at IS NULL');
    $stmt->execute([(int)$box['id']]);
    $existing = array_flip(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));

    $insert = $db->prepare('
        INSERT INTO ibox_folders (box_id, template_id, name, is_initial, created_by, list_principals, upload_principals, sort_order, created_at, updated_at)
        VALUES (?, ?, ?, 1, NULL, ?, ?, ?, ?, ?)
    ');
    $now = iboxNow();
    $count = 0;
    $order = 0;
    foreach (iboxCatalog() as $item) {
        $order++;
        if (!$item['basic'] || !iboxCatalogApplies($item, (string)$box['property_type'])) continue;
        if (isset($existing[$item['id']])) continue;
        $list = array_map(fn($r) => 'role:' . $r, array_unique(array_merge($item['viewer_roles'], $item['uploader_roles'])));
        $upload = array_map(fn($r) => 'role:' . $r, $item['uploader_roles']);
        $insert->execute([(int)$box['id'], $item['id'], $item['name'], json_encode(array_values($list)), json_encode(array_values($upload)), $order, $now, $now]);
        $count++;
    }
    return $count;
}

/* ──────────────────────────────────────────────────────────
 * フォルダー・書類の権限と状態（第2・3章）
 * ────────────────────────────────────────────────────────── */

/** フォルダーの設定（担当・一覧公開・不要確定）を変更できる人：初期は所有者、追加は作成者。 */
function iboxCanManageFolder(array $folder, array $viewer): bool
{
    if ((int)$folder['is_initial'] === 1) return (int)$viewer['is_owner'] === 1;
    return (int)$folder['created_by'] === (int)$viewer['id'];
}

/** 新規アップロードできる人：初期フォルダーの指定担当者／追加フォルダーの責任者と、責任者が追加した担当。 */
function iboxCanUploadToFolder(array $box, array $folder, array $viewer): bool
{
    if ((int)$folder['is_initial'] === 0 && (int)$folder['created_by'] === (int)$viewer['id']) return true;
    return iboxPrincipalMatch(iboxDecodeList($folder['upload_principals']), $box, $viewer);
}

/** 書類の中身を見られるか：登録者本人か、登録者が指定した相手だけ。所有者も例外ではない。 */
function iboxDocVisibleTo(array $doc, array $shareIds, array $viewer): bool
{
    if ($doc['status'] !== 'active') return false;
    if ((int)$doc['uploader_id'] === (int)$viewer['id']) return true;
    return in_array((int)$viewer['id'], array_map('intval', $shareIds), true);
}

/**
 * BOX 内の有効な書類と共有先を、閲覧者が見られるものだけ返す。
 * @return array<int, array> folder_id => [doc, ...]（doc に shares を付ける）
 */
function iboxVisibleDocsByFolder(PDO $db, int $boxId, array $viewer): array
{
    $stmt = $db->prepare("
        SELECT d.*, v.original_name, v.ext, v.mime_type, v.byte_size, v.preview_name
        FROM ibox_documents d
        JOIN ibox_document_versions v ON v.document_id = d.id AND v.version = d.current_version
        WHERE d.box_id = ? AND d.status = 'active'
        ORDER BY d.id ASC
    ");
    $stmt->execute([$boxId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$docs) return [];

    $ids = array_map(fn($d) => (int)$d['id'], $docs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT document_id, participant_id FROM ibox_document_shares WHERE document_id IN ($in)");
    $stmt->execute($ids);
    $shares = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shares[(int)$row['document_id']][] = (int)$row['participant_id'];
    }

    $out = [];
    foreach ($docs as $doc) {
        $docShares = $shares[(int)$doc['id']] ?? [];
        if (!iboxDocVisibleTo($doc, $docShares, $viewer)) continue;
        $doc['shares'] = $docShares;
        $out[(int)$doc['folder_id']][] = $doc;
    }
    return $out;
}

/** 有効な書類・原本票の件数（削除可否の判定用。閲覧者によらない内部判定で、画面には出さない）。 */
function iboxFolderHasContent(PDO $db, int $folderId): bool
{
    $stmt = $db->prepare("SELECT (SELECT COUNT(*) FROM ibox_documents WHERE folder_id = ? AND status = 'active') + (SELECT COUNT(*) FROM ibox_originals WHERE folder_id = ? AND deleted_at IS NULL)");
    $stmt->execute([$folderId, $folderId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * 閲覧者から見た色と状態。非公開書類によって色・件数を変えない。
 *   青 uploaded … 見られる書類が1件以上
 *   黒 unneeded … 見られる書類が0件で、不要・書類なしが確定
 *   赤 requested / missing … 見られる書類が0件（申告中も赤）
 */
function iboxFolderStatus(array $folder, int $visibleCount): array
{
    if ($visibleCount > 0) return ['color' => 'blue', 'status' => 'uploaded', 'label' => 'アップロード済み'];
    if ($folder['unneeded_state'] === 'confirmed') return ['color' => 'black', 'status' => 'unneeded', 'label' => '不要・書類なし'];
    if ($folder['unneeded_state'] === 'requested') return ['color' => 'red', 'status' => 'requested', 'label' => '申告の確認待ち'];
    return ['color' => 'red', 'status' => 'missing', 'label' => '未アップロード'];
}

/**
 * 閲覧者に見せるフォルダー一覧（一覧公開・担当・責任者・共有された書類があるものだけ）。
 * 件数・状態は閲覧できる範囲だけで集計する。
 */
function iboxFolderList(PDO $db, array $box, array $viewer): array
{
    $stmt = $db->prepare('SELECT * FROM ibox_folders WHERE box_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC, id ASC');
    $stmt->execute([(int)$box['id']]);
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $docsByFolder = iboxVisibleDocsByFolder($db, (int)$box['id'], $viewer);

    $participants = iboxParticipants($db, (int)$box['id']);
    $nameById = [];
    foreach ($participants as $pp) $nameById[(int)$pp['id']] = iboxParticipantName($pp);

    $out = [];
    foreach ($folders as $folder) {
        $docs = $docsByFolder[(int)$folder['id']] ?? [];
        $visible = !empty($docs)
            || iboxCanManageFolder($folder, $viewer)
            || iboxCanUploadToFolder($box, $folder, $viewer)
            || iboxPrincipalMatch(iboxDecodeList($folder['list_principals']), $box, $viewer);
        if (!$visible) continue;
        $out[] = iboxSerializeFolder($box, $folder, $viewer, $docs, $participants, $nameById);
    }
    return $out;
}

/** フォルダーの担当者名（役割・個人の指定を、登録済みの関係者名に展開）。 */
function iboxFolderUploaderNames(array $box, array $folder, array $participants): array
{
    $names = [];
    $principals = iboxDecodeList($folder['upload_principals']);
    foreach ($participants as $pp) {
        $isCreator = (int)$folder['is_initial'] === 0 && (int)$folder['created_by'] === (int)$pp['id'];
        if ($isCreator || iboxPrincipalMatch($principals, $box, $pp)) $names[] = iboxParticipantName($pp);
    }
    return array_values(array_unique($names));
}

function iboxSerializeFolder(array $box, array $folder, array $viewer, array $visibleDocs, array $participants, array $nameById): array
{
    $status = iboxFolderStatus($folder, count($visibleDocs));
    $catalog = iboxCatalog();
    $item = $folder['template_id'] !== null ? ($catalog[$folder['template_id']] ?? null) : null;
    $lastUpdated = null;
    foreach ($visibleDocs as $doc) {
        if ($lastUpdated === null || $doc['updated_at'] > $lastUpdated) $lastUpdated = $doc['updated_at'];
    }
    $canManage = iboxCanManageFolder($folder, $viewer);
    return [
        'id' => (int)$folder['id'],
        'template_id' => $folder['template_id'],
        'name' => (string)$folder['name'],
        'target_name' => (string)($folder['target_name'] ?? ''),
        'is_initial' => (int)$folder['is_initial'] === 1,
        'creator_name' => (int)$folder['is_initial'] === 1 ? '' : ($nameById[(int)$folder['created_by']] ?? ''),
        'file_count' => count($visibleDocs),
        'color' => $status['color'],
        'status' => $status['status'],
        'status_label' => $status['label'],
        'uploader_names' => iboxFolderUploaderNames($box, $folder, $participants),
        'can_upload' => iboxCanUploadToFolder($box, $folder, $viewer),
        'can_manage' => $canManage,
        'can_delete' => (int)$folder['is_initial'] === 0 && (int)$folder['created_by'] === (int)$viewer['id'],
        'can_request_unneeded' => (int)$folder['is_initial'] === 1 && iboxCanUploadToFolder($box, $folder, $viewer) && !$canManage,
        'unneeded_state' => (string)$folder['unneeded_state'],
        'unneeded_reason' => $folder['unneeded_state'] !== 'none' ? (string)($folder['unneeded_reason'] ?? '') : '',
        'updated_at' => $lastUpdated,
        'purpose' => $item['purpose'] ?? '',
    ];
}

function iboxLoadFolder(PDO $db, int $boxId, int $folderId): ?array
{
    $stmt = $db->prepare('SELECT * FROM ibox_folders WHERE id = ? AND box_id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$folderId, $boxId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 書類1件と共有先（閲覧可否の判定は呼び出し側で iboxDocVisibleTo()）。 */
function iboxLoadDocument(PDO $db, int $boxId, int $documentId): ?array
{
    $stmt = $db->prepare("
        SELECT d.*, v.stored_name, v.preview_name, v.original_name, v.ext, v.mime_type, v.byte_size
        FROM ibox_documents d
        JOIN ibox_document_versions v ON v.document_id = d.id AND v.version = d.current_version
        WHERE d.id = ? AND d.box_id = ? LIMIT 1
    ");
    $stmt->execute([$documentId, $boxId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) return null;
    $stmt = $db->prepare('SELECT participant_id FROM ibox_document_shares WHERE document_id = ?');
    $stmt->execute([$documentId]);
    $doc['shares'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $doc;
}

/* ──────────────────────────────────────────────────────────
 * アップロード検証（第3章：PDF・JPEG・PNG・DOCX・XLSX、20MB）
 * ────────────────────────────────────────────────────────── */
function iboxAllowedExtensions(): array
{
    return [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
}

/**
 * アップロードされたファイルを検証し、保存用の情報を返す。
 * @return array{ok:bool, message?:string, ext?:string, mime?:string, name?:string, size?:int}
 */
function iboxValidateUpload(?array $file): array
{
    $retry = '内容をご確認のうえ、PDF・JPEG・PNG・Word・Excel（1ファイル20MBまで）で再度登録してください。';
    if (!$file || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => 'ファイルが選択されていません。'];
    }
    if ((int)$file['error'] === UPLOAD_ERR_INI_SIZE || (int)$file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'ファイルサイズが大きすぎます。1ファイル20MBまでです。'];
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'ファイルを受け取れませんでした。通信状態をご確認のうえ、もう一度お試しください。'];
    }
    $size = (int)$file['size'];
    if ($size <= 0) return ['ok' => false, 'message' => '空のファイルは登録できません。' . $retry];
    if ($size > IBOX_MAX_FILE_BYTES) return ['ok' => false, 'message' => 'ファイルサイズが大きすぎます。1ファイル20MBまでです。'];

    $name = trim(str_replace(["\0", '/', '\\'], '', (string)$file['name']));
    if ($name === '') $name = 'file';
    if (mb_strlen($name) > 200) $name = mb_substr($name, -200);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = iboxAllowedExtensions();
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'message' => '未対応のファイル形式です。' . $retry];
    }

    $path = $file['tmp_name'];
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    if ($ext === 'pdf') {
        if (strpos($head, '%PDF-') !== 0) return ['ok' => false, 'message' => 'PDFとして読み取れませんでした。' . $retry];
        if (iboxPdfIsEncrypted($path)) {
            return ['ok' => false, 'message' => 'パスワード付き（暗号化）のPDFは登録できません。パスワードを解除したPDFで再度登録してください。'];
        }
    } elseif ($ext === 'jpg' || $ext === 'jpeg' || $ext === 'png') {
        $info = @getimagesize($path);
        $want = $ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if (!$info || (int)$info[2] !== $want) return ['ok' => false, 'message' => '画像として読み取れませんでした。' . $retry];
    } else {
        // Office Open XML は ZIP。パスワード付きの Word/Excel は ZIP ではなく OLE 形式になる。
        if (strpos($head, "\xD0\xCF\x11\xE0") === 0) {
            return ['ok' => false, 'message' => 'パスワード付きの Word・Excel は登録できません。パスワードを解除するか、PDFに変換して再度登録してください。'];
        }
        if (strpos($head, "PK\x03\x04") !== 0 || !iboxOfficeZipLooksValid($path, $ext)) {
            return ['ok' => false, 'message' => 'Word・Excel ファイルとして読み取れませんでした。' . $retry];
        }
    }

    if (function_exists('upload_security_clamav_scan')) {
        $scan = upload_security_clamav_scan($path);
        if (!$scan['ok']) {
            return ['ok' => false, 'message' => '安全確認（ウイルス検査）を通過できなかったため登録できません。別のファイルでお試しください。'];
        }
    }

    return ['ok' => true, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'mime' => $allowed[$ext], 'name' => $name, 'size' => $size];
}

function iboxPdfIsEncrypted(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $size = filesize($path);
    // トレーラーは末尾付近にある。先頭と末尾を見て /Encrypt 参照の有無を確認する。
    $chunk = (string)fread($fh, 65536);
    if ($size > 65536) {
        fseek($fh, max(0, $size - 65536));
        $chunk .= (string)fread($fh, 65536);
    }
    fclose($fh);
    return (bool)preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $chunk);
}

function iboxOfficeZipLooksValid(string $path, string $ext): bool
{
    if (!class_exists('ZipArchive')) return true;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;
    $ok = $zip->locateName('[Content_Types].xml') !== false
        && $zip->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false;
    $zip->close();
    return $ok;
}

/** LibreOffice の実行ファイル（Word・Excel の PDF プレビュー変換用）。 */
function iboxSofficeBinary(): ?string
{
    $env = getenv('SOFFICE_BIN');
    if ($env && is_file($env)) return $env;
    foreach (['soffice', 'libreoffice'] as $cand) {
        $out = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
        if ($out && trim($out) !== '') return trim($out);
    }
    foreach (['/usr/bin/soffice', '/usr/bin/libreoffice', '/opt/libreoffice/program/soffice'] as $cand) {
        if (is_file($cand)) return $cand;
    }
    return null;
}

/** Word・Excel を PDF に変換する。成功時は PDF のパス、失敗時は null。 */
function iboxConvertOfficeToPdf(string $srcPath, string $outDir): ?string
{
    $bin = iboxSofficeBinary();
    if (!$bin) return null;
    $work = sys_get_temp_dir() . '/ibox_conv_' . bin2hex(random_bytes(6));
    @mkdir($work, 0700, true);
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $tmpSrc = $work . '/src.' . $ext;
    if (!@copy($srcPath, $tmpSrc)) return null;
    $cmd = 'HOME=' . escapeshellarg($work) . ' timeout 90 ' . escapeshellarg($bin)
        . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($tmpSrc) . ' 2>&1';
    @shell_exec($cmd);
    $pdf = $work . '/src.pdf';
    $result = null;
    if (is_file($pdf) && filesize($pdf) > 0) {
        $dest = $outDir . '/' . iboxRandomName('pdf');
        if (@rename($pdf, $dest) || @copy($pdf, $dest)) $result = $dest;
    }
    foreach (glob($work . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) @unlink($f);
    }
    @exec('rm -rf ' . escapeshellarg($work));
    return $result;
}

/**
 * 検証済みのアップロードを BOX の保存先へ移し、プレビュー（Word・Excel は PDF）を作る。
 * 失敗時は保存したファイルを消して ['ok'=>false] を返す（公開状態のレコードを残さない）。
 */
function iboxStoreValidatedUpload(array $file, array $valid, int $boxId): array
{
    $dir = iboxStorageDir($boxId);
    $stored = iboxRandomName($valid['ext']);
    $dest = $dir . '/' . $stored;
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'message' => 'ファイルの保存に失敗しました。時間をおいて再度お試しください。'];
    }
    @chmod($dest, 0640);
    $preview = null;
    if ($valid['ext'] === 'docx' || $valid['ext'] === 'xlsx') {
        $pdf = iboxConvertOfficeToPdf($dest, $dir);
        if (!$pdf) {
            @unlink($dest);
            return ['ok' => false, 'message' => 'Word・Excel のプレビュー変換ができませんでした。お手数ですが、PDFに変換してから再度登録してください。'];
        }
        $preview = basename($pdf);
    }
    return ['ok' => true, 'stored_name' => $stored, 'preview_name' => $preview, 'sha256' => hash_file('sha256', $dest)];
}

/* ──────────────────────────────────────────────────────────
 * 招待URL（受信者専用・7日）と本人照合
 * ────────────────────────────────────────────────────────── */

/** 招待URL用のトークンを発行する（同じ関係者の旧トークンは失効）。生のトークンを返す。 */
function iboxIssueInvite(PDO $db, array $p): string
{
    $raw = bin2hex(random_bytes(32));
    $now = iboxNow();
    $db->prepare('UPDATE ibox_invites SET revoked_at = ? WHERE participant_id = ? AND revoked_at IS NULL')->execute([$now, (int)$p['id']]);
    $db->prepare('INSERT INTO ibox_invites (participant_id, token_hash, auth_version, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([(int)$p['id'], hash('sha256', $raw), (int)$p['auth_version'], date('Y-m-d H:i:s', time() + IBOX_INVITE_DAYS * 86400), $now]);
    return $raw;
}

function iboxInviteUrl(string $rawToken): string
{
    return rtrim(BASE_URL, '/') . '/infobox-access.php?t=' . $rawToken;
}

/**
 * トークンから招待を探す（期限・失効は呼び出し側で判定できるよう状態を返す）。
 * @return array{invite:array, p:array, box:array, state:string}|null state: ok / expired / revoked
 */
function iboxLookupInvite(PDO $db, string $rawToken): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) return null;
    $stmt = $db->prepare('SELECT * FROM ibox_invites WHERE token_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $rawToken)]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invite) return null;
    $p = iboxLoadParticipant($db, (int)$invite['participant_id']);
    if (!$p) return null;
    $box = iboxLoadBox($db, (int)$p['box_id']);
    if (!$box) return null;
    $state = 'ok';
    if ($invite['revoked_at'] !== null || (int)$invite['auth_version'] !== (int)$p['auth_version']) $state = 'revoked';
    elseif ($invite['expires_at'] < iboxNow()) $state = 'expired';
    return ['invite' => $invite, 'p' => $p, 'box' => $box, 'state' => $state];
}

/**
 * 本人確認に成功した関係者を、ログイン中の既存アカウント（不動産AI名刺の users）に紐づける。
 * 既に別アカウントに紐づいている場合や、この BOX の所有者自身の場合は何もしない。
 */
function iboxLinkParticipantAccount(PDO $db, array $p, array $box, int $userId): bool
{
    if ($userId <= 0 || $userId === (int)$box['owner_user_id'] || (int)$p['is_owner'] === 1) return false;
    if (!empty($p['user_id']) && (int)$p['user_id'] !== $userId) return false;
    $stmt = $db->prepare('UPDATE ibox_participants SET user_id = ? WHERE id = ? AND (user_id IS NULL OR user_id = ?)');
    $stmt->execute([$userId, (int)$p['id'], $userId]);
    return true;
}

/** アカウントに紐づいた、参加中（利用期限内）の他社の情報BOX。 */
function iboxJoinedBoxes(PDO $db, int $userId): array
{
    if ($userId <= 0) return [];
    $stmt = $db->prepare("SELECT p.*, b.id AS b_id FROM ibox_participants p JOIN ibox_boxes b ON b.id = p.box_id WHERE p.user_id = ? AND p.is_owner = 0 AND p.status = 'active' ORDER BY b.updated_at DESC");
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $box = iboxLoadBox($db, (int)$p['box_id']);
        if (!$box || !iboxEnabledForUser($db, (int)$box['owner_user_id']) || !iboxAccessState($box, $p)['ok']) continue;
        $out[] = ['box' => $box, 'p' => $p];
    }
    return $out;
}

/** 画面8で表示する問い合わせ先（その方を登録・案内した担当者。無ければ名刺所有者）。 */
function iboxInviterContact(PDO $db, array $p): string
{
    $inviter = !empty($p['registered_by']) ? iboxLoadParticipant($db, (int)$p['registered_by']) : null;
    if (!$inviter) {
        $stmt = $db->prepare('SELECT * FROM ibox_participants WHERE box_id = ? AND is_owner = 1 LIMIT 1');
        $stmt->execute([(int)$p['box_id']]);
        $inviter = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$inviter) return '';
    return trim(trim((string)$inviter['company_name']) . ' ' . iboxParticipantName($inviter)
        . (trim((string)$inviter['phone']) !== '' ? '（電話：' . trim((string)$inviter['phone']) . '）' : ''));
}

/** 連絡先の変更・参加停止のたびに呼ぶ。旧URL・既存セッションをまとめて失効させる。 */
function iboxBumpAuthVersion(PDO $db, int $participantId): void
{
    // 既存アカウントとの紐づけも外す（新しい連絡先で本人確認し直すまで入れない）
    $db->prepare('UPDATE ibox_participants SET auth_version = auth_version + 1, user_id = NULL, updated_at = ? WHERE id = ? AND is_owner = 0')->execute([iboxNow(), $participantId]);
    $db->prepare('UPDATE ibox_invites SET revoked_at = ? WHERE participant_id = ? AND revoked_at IS NULL')->execute([iboxNow(), $participantId]);
}

/* ──────────────────────────────────────────────────────────
 * 必ず表示する案内文（第2章・第6章）
 * ────────────────────────────────────────────────────────── */
function iboxDocumentNotice(): string
{
    return 'この書類は、あなたと、あなたが指定した相手だけが閲覧・印刷できます。名刺所有者や管理者も、指定がなければ閲覧できません。書類を変更・削除できるのは、アップロードしたあなたご本人だけです。';
}

function iboxColorNotice(): string
{
    return 'フォルダーの赤は、ご本人に表示される書類が未登録の状態、青は表示可能な書類がある状態、黒はご本人への共有が不要・書類なしとして確認された状態です。青は必要書類がすべて揃ったことを保証する表示ではありません。';
}

function iboxChatNotice(): string
{
    return '個別チャットは相手とご本人だけが閲覧できます。全員チャットは関係者全員に公開され、あとから参加した方も過去の全員チャットを閲覧できます。個人間のご相談は個別チャットをご利用ください。ローン審査結果は投稿しないでください。';
}

/** 期限の案内（終了前は一般的な案内、終了確定後は受信者ごとの具体的な期限）。 */
function iboxAccessNotice(array $box, array $p): string
{
    if ((int)$p['is_owner'] === 1 || ($box['status'] === 'ended' && (int)$p['continue_access'] === 1)) {
        return '取引終了後も、この情報BOXを継続してご覧いただけます（閲覧できる範囲はこれまでと同じです）。';
    }
    if ($box['status'] === 'ended' && !empty($box['access_until'])) {
        return 'この情報BOXのご利用期限は ' . date('Y年n月j日 H:i', strtotime($box['access_until'])) . ' までです。';
    }
    return '取引終了後、名刺所有者とそのお客様は継続閲覧でき、その他の関係者は1か月後に利用終了となります。確定した期限はBOX内に表示します。';
}
