<?php
/**
 * Application Configuration
 */

// Load local secrets if exists (not committed to git)
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    require_once $secretsFile;
}

// 環境設定
define('ENVIRONMENT', 'development'); // development, production

// エラーレポート
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', 0);
}

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// セッション設定
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // HTTPS使用時は1に変更
ini_set('session.gc_maxlifetime', 3600); // セッション有効期限: 1時間 (3600秒)
ini_set('session.cookie_lifetime', 3600); // クッキー有効期限: 1時間 (3600秒)

// セッション保存パスをプロジェクト内に設定（権限エラーを回避）
$sessionPath = __DIR__ . '/../sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    ini_set('session.save_path', $sessionPath);
} else {
    // 書き込みできない場合は、システムの一時ディレクトリを使用
    $systemTemp = sys_get_temp_dir();
    if (is_writable($systemTemp)) {
        ini_set('session.save_path', $systemTemp);
    }
}

// ベースURL
define('BASE_URL', 'http://103.179.45.108/php');
define('API_BASE_URL', BASE_URL . '/backend/api');

// ファイルアップロード設定
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB (before resize)
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// 画像リサイズ設定
define('IMAGE_RESIZE_ENABLED', true);
define('IMAGE_QUALITY', 85); // JPEG/WebP quality (1-100)

// アップロードタイプ別の最大サイズ設定
define('IMAGE_SIZES', [
    'logo' => ['maxWidth' => 400, 'maxHeight' => 400],      // ロゴ: 400x400
    'photo' => ['maxWidth' => 800, 'maxHeight' => 800],     // プロフィール写真: 800x800
    'free' => ['maxWidth' => 1200, 'maxHeight' => 1200],    // フリー画像: 1200x1200
    'default' => ['maxWidth' => 1024, 'maxHeight' => 1024]  // デフォルト: 1024x1024
]);

// Stripe設定
define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: '');
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');

// 価格設定
define('PRICING_NEW_USER_INITIAL', 30000); // 税別
define('PRICING_NEW_USER_MONTHLY', 500); // 税別
define('PRICING_EXISTING_USER_INITIAL', 20000); // 税別
define('TAX_RATE', 0.1); // 10%

// QRコード設定
define('QR_CODE_BASE_URL', 'https://www.ai-fcard.com/');
define('QR_CODE_DIR', __DIR__ . '/../uploads/qr_codes/');

// テックツールベースURL
define('TECH_TOOL_MDB_BASE', 'https://self-in.com/');
define('TECH_TOOL_RLP_BASE', 'https://self-in.net/rlp/index.php?id=');
define('TECH_TOOL_LLP_BASE', 'https://self-in.net/llp/index.php?id=');
define('TECH_TOOL_AI_BASE', 'https://self-in.com/');
define('TECH_TOOL_SLP_BASE', 'https://self-in.net/slp/index.php?id=');
define('TECH_TOOL_OLP_BASE', 'https://self-in.net/olp/index.php?id=');
define('TECH_TOOL_ALP_BASE', 'https://self-in.net/alp/index.php?id=');

// 通知メール設定
define('NOTIFICATION_EMAIL', 'info@ai-fcard.com');

