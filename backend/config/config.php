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

// ベースURL（画像・リンクはこのドメインで統一。wwwありで表示）
define('BASE_URL', 'https://www.ai-fcard.com');
define('API_BASE_URL', BASE_URL . '/backend/api');

// ファイルアップロード設定
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
/** Pre-validation staging (not web-accessible; ClamAV + MIME checks run here first) */
define('UPLOAD_QUARANTINE_DIR', __DIR__ . '/../uploads_quarantine/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB (before resize)
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
/** Max width/height in pixels (decompression bomb mitigation) */
define('UPLOAD_MAX_DIMENSION', 8000);
/** Max total pixels (width * height) */
define('UPLOAD_MAX_PIXELS', 25000000);
/** Per-IP upload attempts per minute (0 = unlimited) */
define('UPLOAD_RATE_LIMIT_PER_MINUTE', 30);

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
// Customer Portal（カード変更等）。ダッシュボードでポータルを有効化。独自構成を使う場合のみ ID を指定
define('STRIPE_BILLING_PORTAL_CONFIGURATION_ID', getenv('STRIPE_BILLING_PORTAL_CONFIGURATION_ID') ?: '');

// 価格設定
define('PRICING_NEW_USER_INITIAL', 30000); // 税別
define('PRICING_NEW_USER_MONTHLY', 500); // 税別
define('PRICING_EXISTING_USER_INITIAL', 20000); // 税別
define('PRICING_RENEWAL_BANK_ANNUAL', 5000); // 税別（銀行振込・年間更新）
define('TAX_RATE', 0.1); // 10%

// QRコード設定
define('QR_CODE_BASE_URL', 'https://www.ai-fcard.com');
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

// チャットボット: OpenAI
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
}
if (!defined('OPENAI_API_KEY_LIGHT')) {
    define('OPENAI_API_KEY_LIGHT', getenv('OPENAI_API_KEY_LIGHT') ?: OPENAI_API_KEY);
}
if (!defined('OPENAI_API_KEY_SALES')) {
    define('OPENAI_API_KEY_SALES', getenv('OPENAI_API_KEY_SALES') ?: OPENAI_API_KEY);
}
if (!defined('OPENAI_API_KEY_SUMMARY')) {
    define('OPENAI_API_KEY_SUMMARY', getenv('OPENAI_API_KEY_SUMMARY') ?: OPENAI_API_KEY_LIGHT);
}
if (!defined('OPENAI_CHAT_MODEL')) {
    define('OPENAI_CHAT_MODEL', getenv('OPENAI_CHAT_MODEL') ?: 'gpt-4o-mini');
}
if (!defined('OPENAI_MODEL_LIGHT')) {
    define('OPENAI_MODEL_LIGHT', getenv('OPENAI_MODEL_LIGHT') ?: OPENAI_CHAT_MODEL);
}
if (!defined('OPENAI_MODEL_SALES')) {
    define('OPENAI_MODEL_SALES', getenv('OPENAI_MODEL_SALES') ?: OPENAI_CHAT_MODEL);
}
if (!defined('OPENAI_MODEL_SUMMARY')) {
    define('OPENAI_MODEL_SUMMARY', getenv('OPENAI_MODEL_SUMMARY') ?: OPENAI_MODEL_LIGHT);
}
// マンション紹介文の生成に使うモデル（GPT-4.1 など。未設定時は営業モデルを流用）。
if (!defined('OPENAI_MODEL_MANSION')) {
    define('OPENAI_MODEL_MANSION', getenv('OPENAI_MODEL_MANSION') ?: OPENAI_MODEL_SALES);
}
// 開発環境でマンション検索・紹介生成の詳細ログ（抽出名/検索方法/件数/採用ID/
// コンテキスト文字数・内容/GPT応答）をerror_logへ出すフラグ。
if (!defined('CHAT_MANSION_DEBUG')) {
    define('CHAT_MANSION_DEBUG', (getenv('CHAT_MANSION_DEBUG') === '1' || getenv('CHAT_MANSION_DEBUG') === 'true'));
}
if (!defined('CHAT_BLOG_BASE_URL')) {
    define('CHAT_BLOG_BASE_URL', 'https://smile.re-agent.info/blog/');
}

// チャットボット: Firebase SMS認証（Phone Authentication）
if (!defined('FIREBASE_API_KEY')) {
    define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: '');
}
if (!defined('FIREBASE_AUTH_DOMAIN')) {
    define('FIREBASE_AUTH_DOMAIN', getenv('FIREBASE_AUTH_DOMAIN') ?: '');
}
if (!defined('FIREBASE_PROJECT_ID')) {
    define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: '');
}
if (!defined('FIREBASE_APP_ID')) {
    define('FIREBASE_APP_ID', getenv('FIREBASE_APP_ID') ?: '');
}

// チャットボット: 公的データAPI（キーはsecrets.phpまたは環境変数で管理）
if (!defined('REINFOLIB_API_KEY')) {
    define('REINFOLIB_API_KEY', getenv('REINFOLIB_API_KEY') ?: '');
}
if (!defined('MLIT_DPF_API_KEY')) {
    define('MLIT_DPF_API_KEY', getenv('MLIT_DPF_API_KEY') ?: '');
}
if (!defined('MLIT_DPF_BASE_URL')) {
    define('MLIT_DPF_BASE_URL', getenv('MLIT_DPF_BASE_URL') ?: 'https://data-platform.mlit.go.jp/api/v1');
}
if (!defined('ESTAT_APP_ID')) {
    define('ESTAT_APP_ID', getenv('ESTAT_APP_ID') ?: '');
}
// Google Maps Platform Geocoding API（現在地の逆ジオコーディング）。サーバー側からのみ
// 呼び出し、ブラウザには絶対に露出させない。未設定時は従来のGSI逆ジオコーダにフォールバック。
if (!defined('GOOGLE_GEOCODING_API_KEY')) {
    define('GOOGLE_GEOCODING_API_KEY', getenv('GOOGLE_GEOCODING_API_KEY') ?: '');
}
