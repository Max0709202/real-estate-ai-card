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
// 無操作タイムアウト（最終アクセスからの経過時間）
define('SESSION_IDLE_LIFETIME', 6 * 3600);        // 一般ユーザー（エージェント）: 6時間
define('SESSION_IDLE_LIFETIME_ADMIN', 3600);      // 管理画面: 1時間（セキュリティのため据え置き）

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // HTTPS使用時は1に変更
// GC は最長のタイムアウトに合わせる（実際の期限判定は startSessionIfNotStarted() で行う）
ini_set('session.gc_maxlifetime', SESSION_IDLE_LIFETIME);
// クッキー有効期限もアクセスのたびに延長する（startSessionIfNotStarted() で再送出）
ini_set('session.cookie_lifetime', SESSION_IDLE_LIFETIME);

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
// 環境ごとに切り替え（DB設定と同じ判定: APP_ENV もしくはホスト名で staging を判定）。
// BASE_URL 環境変数があれば最優先で使用する。
$baseUrlEnv  = getenv('BASE_URL') ?: '';
$appEnv      = getenv('APP_ENV') ?: '';
$reqHost     = preg_replace('/:\d+$/', '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
$isStaging   = ($appEnv === 'staging' || $reqHost === 'staging.example.com' || $reqHost === 'staging.ai-fcard.com');
define('BASE_URL', $baseUrlEnv ?: ($isStaging
    ? 'https://staging.ai-fcard.com'
    : 'https://www.ai-fcard.com'));
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
    'flyer_band' => ['maxWidth' => 1654, 'maxHeight' => 800], // 自社帯: A4横相当（アスペクト比保持）
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
define('QR_CODE_BASE_URL', BASE_URL);
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

// お問い合わせフォームのスパム対策
/** Per-IP contact submissions per hour (0 = unlimited) */
define('CONTACT_RATE_LIMIT_PER_HOUR', 5);
/** Per-IP contact submissions per minute (0 = unlimited) */
define('CONTACT_RATE_LIMIT_PER_MINUTE', 2);
/** Submissions faster than this after form render are bots */
define('CONTACT_MIN_FILL_SECONDS', 3);
/** Signed form timestamps older than this are stale */
define('CONTACT_FORM_MAX_AGE_SECONDS', 86400);
/** Honeypot input name (rendered hidden; any value = bot) */
define('CONTACT_HONEYPOT_FIELD', 'website_url');

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
    // Customer-facing consultation and property explanations need the flagship
    // model. Keep OPENAI_MODEL_LIGHT on the inexpensive default for routing,
    // classification and fallback work.
    define('OPENAI_MODEL_SALES', getenv('OPENAI_MODEL_SALES') ?: 'gpt-5.6');
}
if (!defined('OPENAI_MODEL_SUMMARY')) {
    define('OPENAI_MODEL_SUMMARY', getenv('OPENAI_MODEL_SUMMARY') ?: OPENAI_MODEL_LIGHT);
}
// 質問意図の判定（マンション名検索／一般相談／対象外の振り分け）に使う軽量モデル。
// 1メッセージにつき1回・JSON数十トークンだけ返させる用途なので、最も安価なモデルを使う。
if (!defined('OPENAI_MODEL_INTENT')) {
    define('OPENAI_MODEL_INTENT', getenv('OPENAI_MODEL_INTENT') ?: OPENAI_MODEL_LIGHT);
}
// マンション紹介文の生成に使うモデル（未設定時は営業モデルを流用）。
if (!defined('OPENAI_MODEL_MANSION')) {
    define('OPENAI_MODEL_MANSION', getenv('OPENAI_MODEL_MANSION') ?: 'gpt-5.4-mini');
}
// 開発環境でマンション検索・紹介生成の詳細ログ（抽出名/検索方法/件数/採用ID/
// コンテキスト文字数・内容/GPT応答）をerror_logへ出すフラグ。
if (!defined('CHAT_MANSION_DEBUG')) {
    define('CHAT_MANSION_DEBUG', (getenv('CHAT_MANSION_DEBUG') === '1' || getenv('CHAT_MANSION_DEBUG') === 'true'));
}
if (!defined('CHAT_BLOG_BASE_URL')) {
    define('CHAT_BLOG_BASE_URL', 'https://smile.re-agent.info/blog/');
}

// ---------------------------------------------------------------------------
// 全国マンションデータベース（db.self-in.com）実ページ参照
//
// 取り込み済みのXLSX基礎情報（mansion_buildings）だけでは、販売履歴・価格推移・
// 賃料履歴・口コミ・管理費/修繕積立金などに答えられない。これらは実際の
// マンションページにしか存在しないため、建物を特定できた場合に限り実ページを
// 取得して回答根拠にする。
//
// URL形式： {BASE}{マンションID}.html?cid={CID}&on={ON}
// 【重要】on は必ず 0（リニュアル仲介専用のマスク解除パラメータ）。1は使用しない。
// ---------------------------------------------------------------------------
if (!defined('MANSION_DB_WEB_ENABLED')) {
    // 既定は有効。ただし後述の「マンションIDの解決手段」が無い環境では
    // 1度も外部リクエストを行わず、従来どおりDB基礎情報だけで回答する。
    define('MANSION_DB_WEB_ENABLED', getenv('MANSION_DB_WEB_ENABLED') !== '0');
}
if (!defined('MANSION_DB_WEB_BASE_URL')) {
    define('MANSION_DB_WEB_BASE_URL', getenv('MANSION_DB_WEB_BASE_URL') ?: 'https://db.self-in.com/mansion/');
}
if (!defined('MANSION_DB_WEB_CID')) {
    define('MANSION_DB_WEB_CID', getenv('MANSION_DB_WEB_CID') ?: 'rchukai');
}
// マスク解除パラメータ。仕様上 0 固定（1 は使用しない）。定数にしているのは
// 将来ベンダー側で名称が変わった場合に一箇所で追随するためだけの目的。
if (!defined('MANSION_DB_WEB_ON')) {
    define('MANSION_DB_WEB_ON', '0');
}
// マンション名からマンションIDを引くための検索URL。
// db.self-in.com トップの「マンション名で検索」窓が実際に投げているリクエスト
// （GET /search/?mname=マンション名）をそのまま利用する。
// {name} {address} {pref} {city} をURLエンコードして差し込む。
// 未設定にすると MANSION_DB_WEB_SEARCH_PAGE_URL の検索窓から自動生成を試みる。
if (!defined('MANSION_DB_WEB_SEARCH_URL')) {
    define('MANSION_DB_WEB_SEARCH_URL', getenv('MANSION_DB_WEB_SEARCH_URL') ?: 'https://db.self-in.com/search/?mname={name}');
}
// マンション名の「検索窓」があるページ（db.self-in.com トップの
// 「マンション名で検索」の入力欄）。ここから <form> の action と入力欄の name を
// 読み取り、検索URLを自動生成する（＝ベンダーにID一覧を請求せず自社で解決するため）。
// 生成した検索URLテンプレートはキャッシュされ、毎回の解析は発生しない。
if (!defined('MANSION_DB_WEB_SEARCH_PAGE_URL')) {
    define('MANSION_DB_WEB_SEARCH_PAGE_URL', getenv('MANSION_DB_WEB_SEARCH_PAGE_URL') ?: 'https://db.self-in.com/');
}
// ID一括解決（backfill_mansion_mdb_id.php）でのリクエスト間隔（ミリ秒）。
// 先方に頻度制限は無いとのことだが、相手サーバーへの配慮として既定200ms。
if (!defined('MANSION_DB_WEB_SEARCH_DELAY_MS')) {
    define('MANSION_DB_WEB_SEARCH_DELAY_MS', (int)(getenv('MANSION_DB_WEB_SEARCH_DELAY_MS') ?: 200));
}
// 実ページのキャッシュTTL（秒）。販売履歴・口コミは頻繁には変わらないため既定6時間。
if (!defined('MANSION_DB_WEB_CACHE_TTL')) {
    define('MANSION_DB_WEB_CACHE_TTL', (int)(getenv('MANSION_DB_WEB_CACHE_TTL') ?: 21600));
}
if (!defined('MANSION_DB_WEB_TIMEOUT')) {
    define('MANSION_DB_WEB_TIMEOUT', (int)(getenv('MANSION_DB_WEB_TIMEOUT') ?: 12));
}
// db.self-in.com へ名乗るUser-Agent。先方が誰からのアクセスかを識別できるよう、
// 素性の分かる名前にしている（IP許可の相談時にログで突合しやすくするため）。
// ※万一 User-Agent で弾かれる場合は、環境変数 MANSION_DB_WEB_USER_AGENT で
//   ブラウザ相当の文字列に差し替えられる。
if (!defined('MANSION_DB_WEB_USER_AGENT')) {
    define('MANSION_DB_WEB_USER_AGENT', getenv('MANSION_DB_WEB_USER_AGENT')
        ?: 'AI-Fcard-MansionDB/1.0 (+https://www.ai-fcard.com/)');
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
