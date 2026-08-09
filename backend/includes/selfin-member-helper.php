<?php
/**
 * セルフィンPro 会員判定API連携
 *
 * チャットでお客様のメールアドレスを登録いただいた時点で、そのメールアドレスが
 * セルフィンProに登録済みかどうかをセルフィンPro側の確認APIへ問い合わせ、
 * 結果に応じてチャット内の案内文を出し分ける。
 *
 * 【呼び出し仕様（セルフィンPro側からの提示内容）】
 *   POST https://self-in.com/api/v1/member/check
 *   Content-Type: application/x-www-form-urlencoded
 *   Body: key=<APIキー>&mail=<メールアドレス>
 *   Response: {"exists": true} / {"exists": false}
 *   401       : IP制限またはAPIキーのチェックに失敗（先方へ状況確認を依頼する）
 *
 * 【重要】
 * - ブラウザから直接呼ばない。必ずサーバー（本番: Xserver / グローバルIP 85.131.209.117）
 *   経由で呼ぶ。先方はこのIPをホワイトリスト登録している。
 * - APIキーはリポジトリに置かない。backend/config/secrets.php もしくは環境変数
 *   SELFIN_MEMBER_CHECK_KEY で設定する。未設定時は本連携全体を無効として扱い、
 *   チャットの挙動は連携前と変わらない（案内文を出さない）。
 * - 個人情報保護の観点から、返却されるのは登録有無のみ。ログにメールアドレスは残さない。
 */

if (!function_exists('selfinMemberCheckEnabled')) {

/**
 * 連携が利用可能か（機能ON かつ APIキー設定済み かつ cURL利用可）。
 */
function selfinMemberCheckEnabled() {
    if (defined('SELFIN_MEMBER_CHECK_ENABLED') && !SELFIN_MEMBER_CHECK_ENABLED) return false;
    if (!function_exists('curl_init')) return false;
    $key = defined('SELFIN_MEMBER_CHECK_KEY') ? trim((string)SELFIN_MEMBER_CHECK_KEY) : '';
    $url = defined('SELFIN_MEMBER_CHECK_URL') ? trim((string)SELFIN_MEMBER_CHECK_URL) : '';
    return $key !== '' && $url !== '';
}

/**
 * セルフィンPro確認APIを1回だけ呼ぶ。
 *
 * @param string $email 判定するメールアドレス
 * @return array{exists: ?bool, http_code: int, error: ?string}
 *         exists が null の場合は「判定できなかった」（未設定・通信失敗・401など）。
 *         判定できない場合は案内文を出さず、チャットは従来どおり進める。
 */
function selfinMemberCheck($email) {
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['exists' => null, 'http_code' => 0, 'error' => 'invalid email'];
    }
    if (!selfinMemberCheckEnabled()) {
        return ['exists' => null, 'http_code' => 0, 'error' => 'not configured'];
    }

    $timeout = defined('SELFIN_MEMBER_CHECK_TIMEOUT') ? (int)SELFIN_MEMBER_CHECK_TIMEOUT : 8;
    if ($timeout <= 0) $timeout = 8;

    // APIキーはヘッダーではなくPOSTパラメータで送る（先方エンジニアの指定）。
    $body = http_build_query([
        'key'  => SELFIN_MEMBER_CHECK_KEY,
        'mail' => $email,
    ]);

    $ch = curl_init(SELFIN_MEMBER_CHECK_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $curlError = $response === false ? curl_error($ch) : '';
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        // メールアドレスはログに残さない（個人情報のため）。
        error_log('Selfin member check failed (transport): ' . $curlError);
        return ['exists' => null, 'http_code' => $httpCode, 'error' => $curlError !== '' ? $curlError : 'request failed'];
    }
    if ($httpCode === 401) {
        // 先方の案内どおり、401はIP制限またはAPIキーの問題。運用時に気づけるようログへ残す。
        error_log('Selfin member check returned 401 (IPホワイトリストまたはAPIキーをご確認ください)');
        return ['exists' => null, 'http_code' => 401, 'error' => 'unauthorized'];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('Selfin member check returned HTTP ' . $httpCode);
        return ['exists' => null, 'http_code' => $httpCode, 'error' => 'http ' . $httpCode];
    }

    $exists = selfinMemberParseExists($response);
    if ($exists === null) {
        error_log('Selfin member check returned an unexpected body.');
        return ['exists' => null, 'http_code' => $httpCode, 'error' => 'unexpected response'];
    }
    return ['exists' => $exists, 'http_code' => $httpCode, 'error' => null];
}

/**
 * レスポンス本文から登録有無を取り出す。
 * 正となる形式は {"exists": true} / {"exists": false}。
 * 先方実装のゆらぎ（"true"/1、true 単体）にも耐えられるようにしておく。
 */
function selfinMemberParseExists($response) {
    $raw = trim((string)$response);
    if ($raw === '') return null;

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        if (!array_key_exists('exists', $decoded)) return null;
        return selfinMemberNormalizeBool($decoded['exists']);
    }
    if (is_bool($decoded)) return $decoded;
    return selfinMemberNormalizeBool($raw);
}

function selfinMemberNormalizeBool($value) {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 1 ? true : ($value === 0 ? false : null);
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === 'true' || $normalized === '1') return true;
        if ($normalized === 'false' || $normalized === '0') return false;
    }
    return null;
}

/**
 * 判定結果に応じたチャット案内文。
 * 文面はお客様（セルフィンPro側）からの提示どおり。
 */
function selfinMemberGuidanceMessage($exists) {
    if ($exists === true) {
        return "セルフィンProをご利用いただきありがとうございます。\n"
            . "不動産AI名刺内の「不動産テックツール」からセルフィンProをはじめ各種ツールをご利用いただけます。\n"
            . "また、いずれか1つのツールで利用登録いただくと、他の対象ツールも共通アカウントでご利用いただけます。";
    }
    if ($exists === false) {
        return "不動産AI名刺内の「不動産テックツール」からセルフィンProをご利用いただけます。\n"
            . "また、いずれか1つのツールで利用登録いただくと、他の対象ツールも共通アカウントでご利用いただけます。\n"
            . "まずは不動産テックツールよりご登録ください。";
    }
    return '';
}

/**
 * チャットのリード情報（chat_leads.structured_data）に判定結果をキャッシュしつつ、
 * まだ案内していない場合に限り案内文を返す。
 *
 * - 同じメールアドレスに対して外部APIを何度も叩かない。
 * - 同じ案内をチャット内で繰り返さない（案内済みフラグを持つ）。
 * - メールアドレスそのものは重複保存せず、ハッシュだけを突合用に保持する。
 * - 判定できなかった場合はキャッシュも案内も行わず、次回の登録時に再試行する。
 *
 * @param string $email 登録されたメールアドレス
 * @param array  $data  chatIntakeLoad() で読み込んだリードデータ（参照渡し・呼び出し側で保存すること）
 * @return string 案内文（案内しない場合は空文字）
 */
function selfinMemberChatGuidance($email, array &$data) {
    $email = trim((string)$email);
    if ($email === '') return '';
    if (!selfinMemberCheckEnabled()) return '';

    $emailHash = hash('sha256', strtolower($email));
    $sameEmail = (($data['_selfin_pro_email_hash'] ?? '') === $emailHash);

    if ($sameEmail && array_key_exists('_selfin_pro_exists', $data) && is_bool($data['_selfin_pro_exists'])) {
        // 判定済み。案内済みなら繰り返さない。
        if (!empty($data['_selfin_pro_notified_at'])) return '';
        $exists = $data['_selfin_pro_exists'];
    } else {
        $result = selfinMemberCheck($email);
        if (!is_bool($result['exists'])) return '';
        $exists = $result['exists'];
        $data['_selfin_pro_exists'] = $exists;
        $data['_selfin_pro_email_hash'] = $emailHash;
        $data['_selfin_pro_checked_at'] = date('c');
        unset($data['_selfin_pro_notified_at']);
    }

    $message = selfinMemberGuidanceMessage($exists);
    if ($message === '') return '';
    $data['_selfin_pro_notified_at'] = date('c');
    return $message;
}

}
