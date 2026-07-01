<?php
/**
 * Web Push (VAPID) 送信ヘルパー — ホーム画面アイコンのアプリバッジ用。
 *
 * ペイロード暗号化(aes128gcm)は行わず、空ボディの「tickle」Pushのみ送る。
 * Service Worker(push-sw.js) が受信時に customer/poll.php から未読数を取得し
 * setAppBadge() する。ここで必要な暗号処理は VAPID の ES256 JWT 署名のみ。
 *
 * 鍵は secrets.php の環境変数:
 *   VAPID_PUBLIC_KEY      … 65byte 非圧縮公開点 base64url（applicationServerKey）
 *   VAPID_PRIVATE_KEY_B64 … EC秘密鍵PEMをbase64化したもの
 *   VAPID_SUBJECT         … mailto: か https:// のURL
 */

require_once __DIR__ . '/../config/config.php';

/** base64url エンコード */
function pushB64Url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * ECDSA署名(DER: SEQUENCE{INTEGER r, INTEGER s}) を JWS 用の raw(r||s, 各32byte) に変換。
 * @return string|false
 */
function pushDerToRaw(string $der)
{
    $len = strlen($der);
    $off = 0;
    if ($len < 8 || ord($der[$off++]) !== 0x30) return false;
    $seqLen = ord($der[$off++]);
    if ($seqLen & 0x80) {
        $n = $seqLen & 0x7f;
        for ($i = 0; $i < $n; $i++) { $off++; }
    }
    if (ord($der[$off++]) !== 0x02) return false;
    $rLen = ord($der[$off++]);
    $r = substr($der, $off, $rLen); $off += $rLen;
    if (ord($der[$off++]) !== 0x02) return false;
    $sLen = ord($der[$off++]);
    $s = substr($der, $off, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    if (strlen($r) > 32 || strlen($s) > 32) return false;
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

/**
 * 指定エンドポイント向けの VAPID リクエストヘッダを生成する。
 * @return array|null HTTPヘッダ配列（失敗時 null）
 */
function pushBuildVapidHeaders(string $endpoint): ?array
{
    $pub = getenv('VAPID_PUBLIC_KEY') ?: '';
    $privB64 = getenv('VAPID_PRIVATE_KEY_B64') ?: '';
    $sub = getenv('VAPID_SUBJECT') ?: 'mailto:info@ai-fcard.com';
    if ($pub === '' || $privB64 === '') {
        error_log('push: VAPID keys not configured');
        return null;
    }
    $pem = base64_decode($privB64, true);
    if ($pem === false) return null;
    $pkey = openssl_pkey_get_private($pem);
    if (!$pkey) { error_log('push: invalid VAPID private key'); return null; }

    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return null;
    $aud = $p['scheme'] . '://' . $p['host'];

    $header  = pushB64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = pushB64Url(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $sub]));
    $signingInput = $header . '.' . $payload;

    $der = '';
    if (!openssl_sign($signingInput, $der, $pkey, OPENSSL_ALGO_SHA256)) return null;
    $raw = pushDerToRaw($der);
    if ($raw === false) return null;

    $jwt = $signingInput . '.' . pushB64Url($raw);
    return [
        'Authorization: vapid t=' . $jwt . ', k=' . $pub,
        'TTL: 2419200',
        'Urgency: high',
    ];
}

/**
 * 指定セッションの全購読へ空Push（tickle）を送信する。
 * 404/410 は購読を削除（prune）。通知失敗は握りつぶしログのみ。
 * @return int 送信成功数
 */
function pushSendToSession(PDO $db, string $sessionId): int
{
    $sent = 0;
    try {
        if (!function_exists('curl_init')) return 0;
        $stmt = $db->prepare("SELECT id, endpoint FROM push_subscriptions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subs as $s) {
            $headers = pushBuildVapidHeaders((string)$s['endpoint']);
            if (!$headers) continue;
            $ch = curl_init((string)$s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 404 || $code === 410) {
                $db->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$s['id']]);
            } elseif ($code >= 200 && $code < 300) {
                $db->prepare("UPDATE push_subscriptions SET last_notified_at = NOW() WHERE id = ?")->execute([$s['id']]);
                $sent++;
            } else {
                error_log('push: send failed code=' . $code . ' endpoint=' . substr((string)$s['endpoint'], 0, 60));
            }
        }
    } catch (Throwable $e) {
        error_log('pushSendToSession error: ' . $e->getMessage());
    }
    return $sent;
}
