<?php
/**
 * セルフィンPro 会員判定API連携
 *
 * チャットでお客様のメールアドレスを登録いただいた時点で、そのメールアドレスが
 * セルフィンProに登録済みかどうかをセルフィンPro側の確認APIへ問い合わせ、
 * 結果に応じてチャット内の案内文を出し分ける。
 *
 * 【案内を表示するタイミング】
 *   1. メールアドレスのご登録直後（selfinMemberChatGuidance / profile/save.php）
 *   2. 「不動産テックツール」「セルフィンPro」に関するご質問をいただいたとき
 *   3. 前回の案内から一定期間（既定7日）が経過したとき
 *      （2・3は selfinMemberChatMessageGuidance / chat/send.php）
 * 2・3で案内する際は判定結果を再確認するため、未登録だった方がその後セルフィンProへ
 * ご登録された場合は、次回から「ご利用ありがとうございます」の案内へ切り替わる。
 * 間隔は SELFIN_MEMBER_CHECK_REPEAT_DAYS / SELFIN_MEMBER_CHECK_MIN_GAP_HOURS で調整できる。
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
 * 案内を出し直す間隔（秒）。0以下の場合は出し直さない（登録直後の1回のみ）。
 *
 * 判定結果のキャッシュ期間（＝再確認の間隔）にも同じ値を用いる。案内を出し直す際は
 * その時点の登録状況で判定し、以前は未登録だった方がセルフィンProへご登録された場合に、
 * 次回から「ご利用ありがとうございます」の案内へ自動的に切り替わるようにするため。
 */
function selfinMemberRepeatIntervalSeconds() {
    $days = defined('SELFIN_MEMBER_CHECK_REPEAT_DAYS') ? (int)SELFIN_MEMBER_CHECK_REPEAT_DAYS : 7;
    return $days > 0 ? $days * 86400 : 0;
}

/**
 * 直前の案内からこの時間が経過していない場合は、テックツールに関するご質問があっても
 * 案内を繰り返さない。登録直後の案内と続けて表示されるのを防ぐための下限間隔。
 */
function selfinMemberMinIntervalSeconds() {
    $hours = defined('SELFIN_MEMBER_CHECK_MIN_GAP_HOURS') ? (int)SELFIN_MEMBER_CHECK_MIN_GAP_HOURS : 24;
    return $hours > 0 ? $hours * 3600 : 0;
}

/**
 * 保存済みのISO8601日時をUNIX時刻に変換する。未設定・不正な値はnull。
 */
function selfinMemberParseTime($value) {
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '') return null;
    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

/**
 * 「不動産テックツール」「セルフィンPro」に関するご質問かどうか。
 * 該当する場合は、判定結果に応じた案内をAIの回答に添える。
 */
function selfinMemberMessageAsksTechTools($message) {
    $text = trim((string)$message);
    if ($text === '') return false;
    // 「self-in」「selfin」は直後がアルファベットの場合（selfindex等）を除外して拾う。
    return (bool)preg_match('/(セルフィン|ｾﾙﾌｨﾝ|\bself\s?-?\s?in(?![A-Za-z])|不動産テック|テックツール)/iu', $text);
}

/**
 * 判定結果を取得する。
 * キャッシュが有効な間はAPIを呼ばず、期限（selfinMemberRepeatIntervalSeconds）を過ぎている
 * 場合と、メールアドレスが変わった場合のみ確認APIへ問い合わせる。
 *
 * @return bool|null 判定できなかった場合はnull（案内は行わない）。
 */
function selfinMemberResolveExists($email, array &$data, $now) {
    $emailHash = hash('sha256', strtolower($email));
    $sameEmail = (($data['_selfin_pro_email_hash'] ?? '') === $emailHash);

    $cached = ($sameEmail && array_key_exists('_selfin_pro_exists', $data) && is_bool($data['_selfin_pro_exists']))
        ? $data['_selfin_pro_exists']
        : null;
    $checkedAt = $sameEmail ? selfinMemberParseTime($data['_selfin_pro_checked_at'] ?? null) : null;
    $ttl = selfinMemberRepeatIntervalSeconds();
    if ($cached !== null && ($ttl <= 0 || ($checkedAt !== null && ($now - $checkedAt) < $ttl))) {
        return $cached;
    }

    $result = selfinMemberCheck($email);
    if (!is_bool($result['exists'])) {
        // 判定できなかった場合は、以前の判定結果もそのまま残し、次の機会に再試行する。
        return null;
    }
    if (!$sameEmail) {
        // メールアドレスが変わった場合は、案内履歴もリセットして改めてご案内する。
        unset($data['_selfin_pro_notified_at']);
    }
    $data['_selfin_pro_exists'] = $result['exists'];
    $data['_selfin_pro_email_hash'] = $emailHash;
    $data['_selfin_pro_checked_at'] = date('c', $now);
    return $result['exists'];
}

/**
 * メールアドレスをご登録いただいた直後の案内。
 *
 * - 同じメールアドレスに対して外部APIを何度も叩かない。
 * - 登録直後の案内はチャット内で1回だけ（案内済みフラグを持つ）。
 * - メールアドレスそのものは重複保存せず、ハッシュだけを突合用に保持する。
 * - 判定できなかった場合はキャッシュも案内も行わず、次の機会に再試行する。
 *
 * @param string $email 登録されたメールアドレス
 * @param array  $data  chatIntakeLoad() で読み込んだリードデータ（参照渡し・呼び出し側で保存すること）
 * @return string 案内文（案内しない場合は空文字）
 */
function selfinMemberChatGuidance($email, array &$data) {
    $email = trim((string)$email);
    if ($email === '') return '';
    if (!selfinMemberCheckEnabled()) return '';

    // 同じメールアドレスで既に案内済みなら、確認APIも呼ばずに終了する。
    $emailHash = hash('sha256', strtolower($email));
    if (($data['_selfin_pro_email_hash'] ?? '') === $emailHash && !empty($data['_selfin_pro_notified_at'])) {
        return '';
    }

    $now = time();
    $exists = selfinMemberResolveExists($email, $data, $now);
    if (!is_bool($exists)) return '';

    $message = selfinMemberGuidanceMessage($exists);
    if ($message === '') return '';
    $data['_selfin_pro_notified_at'] = date('c', $now);
    return $message;
}

/**
 * 会話中の案内（登録直後の1回に加えて表示するもの）。
 *
 * 次のいずれかに当てはまる場合に、判定結果に応じた案内文を返す。
 *   1. 「不動産テックツール」「セルフィンPro」に関するご質問をいただいたとき
 *      （直前の案内から selfinMemberMinIntervalSeconds() 未満の場合は繰り返さない）
 *   2. 前回の案内から一定期間（既定7日）が経過したとき
 *
 * メールアドレスが未登録の間は判定できないため案内しない（登録時に selfinMemberChatGuidance
 * が案内する）。判定できなかった場合も案内せず、チャットはそのまま継続する。
 *
 * @param string $message お客様の発言
 * @param array  $data    chatIntakeLoad() で読み込んだリードデータ（参照渡し・呼び出し側で保存すること）
 * @return string 案内文（案内しない場合は空文字）
 */
function selfinMemberChatMessageGuidance($message, array &$data) {
    if (!selfinMemberCheckEnabled()) return '';
    $email = trim((string)($data['customer_email'] ?? ''));
    if ($email === '') return '';

    $now = time();
    $notifiedAt = selfinMemberParseTime($data['_selfin_pro_notified_at'] ?? null);
    $elapsed = $notifiedAt === null ? null : ($now - $notifiedAt);

    $minInterval = selfinMemberMinIntervalSeconds();
    $repeatInterval = selfinMemberRepeatIntervalSeconds();
    $dueByQuestion = selfinMemberMessageAsksTechTools($message)
        && ($elapsed === null || $elapsed >= $minInterval);
    $dueByInterval = $repeatInterval > 0
        && ($elapsed === null || $elapsed >= $repeatInterval);
    if (!$dueByQuestion && !$dueByInterval) return '';

    $exists = selfinMemberResolveExists($email, $data, $now);
    if (!is_bool($exists)) return '';

    $guidance = selfinMemberGuidanceMessage($exists);
    if ($guidance === '') return '';
    $data['_selfin_pro_notified_at'] = date('c', $now);
    return $guidance;
}

}
