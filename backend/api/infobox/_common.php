<?php
/**
 * 情報BOX API の共通処理。
 *
 * すべての取得・配信・変更で「有効な参加資格 → 対象ごとの権限」の順に確認する（仕様 6-4）。
 * 所有者・階層管理者であることを理由に、書類の権限判定を飛ばさない。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/upload_security.php';
require_once __DIR__ . '/../../includes/infobox-helper.php';

startSessionIfNotStarted();

/** JSON 本文（無ければ POST）。 */
function iboxApiInput(): array
{
    static $input = null;
    if ($input !== null) return $input;
    $raw = file_get_contents('php://input');
    $json = $raw !== '' ? json_decode($raw, true) : null;
    $input = is_array($json) ? $json : $_POST;
    return $input;
}

/** 別サイトからの送信（CSRF）を防ぐため、変更系は独自ヘッダー付きの同一オリジン通信だけ受け付ける。 */
function iboxApiRequirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendErrorResponse('Method not allowed', 405);
    if (($_SERVER['HTTP_X_IBOX'] ?? '') !== '1') sendErrorResponse('不正なリクエストです。画面を再読み込みしてください。', 400);
}

function iboxApiDb(): PDO
{
    static $db = null;
    if ($db === null) {
        $db = (new Database())->getConnection();
        iboxEnsureTables($db);
    }
    return $db;
}

/**
 * BOX の閲覧者を解決する。$write=true のときは、期限経過後の読み取り専用 BOX を拒否する。
 * @return array{box:array, p:array, is_owner:bool, readonly:bool, access:array}
 */
function iboxApiViewer(PDO $db, int $boxId, bool $write = false): array
{
    $viewer = iboxResolveViewer($db, $boxId);
    if (isset($viewer['error'])) {
        sendJsonResponse(['success' => false, 'message' => $viewer['error'], 'reason' => $viewer['reason']], $viewer['code']);
    }
    if ($write && $viewer['readonly']) {
        sendErrorResponse('利用期限を過ぎたため、この情報BOXは閲覧のみです。', 403);
    }
    if (!$viewer['is_owner']) {
        $db->prepare('UPDATE ibox_participants SET last_access_at = ? WHERE id = ?')->execute([iboxNow(), (int)$viewer['p']['id']]);
    }
    return $viewer;
}

function iboxApiRequireOwner(array $viewer): void
{
    if (!$viewer['is_owner']) sendErrorResponse('この操作は名刺所有者だけが行えます。', 403);
}

/** ログイン中の名刺所有者で、会社の情報BOXが ON であること（一覧・新規作成用）。 */
function iboxApiRequireEnabledUser(PDO $db): int
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) sendErrorResponse('再ログインをお願いします', 401);
    if (!iboxEnabledForUser($db, $userId)) sendErrorResponse('情報BOXは現在ご利用いただけません。', 403);
    return $userId;
}

function iboxApiNameMap(array $participants): array
{
    $map = [];
    foreach ($participants as $pp) $map[(int)$pp['id']] = iboxParticipantName($pp);
    return $map;
}

/** 日付入力（空なら null、形式不正ならエラー）。 */
function iboxApiDate($value, string $label): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) sendErrorResponse($label . 'の日付が正しくありません。', 400);
    return $value;
}

function iboxApiText($value, int $max = 255): string
{
    $value = trim(str_replace("\0", '', (string)$value));
    return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
}

/** 操作識別子（二重送信・再試行を同じ処理として扱う）。 */
function iboxApiOpKey($value): ?string
{
    $value = trim((string)$value);
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $value) ? $value : null;
}
