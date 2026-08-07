<?php
/**
 * セルフィンPro会員判定APIの疎通確認スクリプト。
 * チャット本体と同じ関数（selfinMemberCheck）を呼ぶため、本番で401が返る場合の
 * 切り分け（IPホワイトリスト／APIキー）にそのまま使える。
 *
 * 使い方（本番サーバー上で実行すること。IP制限のため手元のPCからは401になる）:
 *   php backend/scripts/test_selfin_member_check.php user@example.com
 *
 * 判定結果のみを表示し、APIキーは伏せ字にする。DBへの書き込みは行わない。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/selfin-member-helper.php';

$email = trim((string)($argv[1] ?? ''));
if ($email === '') {
    echo "使い方: php backend/scripts/test_selfin_member_check.php <メールアドレス>\n";
    exit(1);
}

function selfinTestLine($s = '') { echo $s . "\n"; }

$key = defined('SELFIN_MEMBER_CHECK_KEY') ? (string)SELFIN_MEMBER_CHECK_KEY : '';
$maskedKey = $key === ''
    ? '(未設定)'
    : substr($key, 0, 6) . str_repeat('*', 8) . substr($key, -4) . ' (' . strlen($key) . '文字)';

selfinTestLine('==================================================================');
selfinTestLine('SELFIN PRO MEMBER CHECK');
selfinTestLine('==================================================================');
selfinTestLine('URL      : ' . (defined('SELFIN_MEMBER_CHECK_URL') ? SELFIN_MEMBER_CHECK_URL : '(未定義)'));
selfinTestLine('APIキー  : ' . $maskedKey);
selfinTestLine('有効化   : ' . ((defined('SELFIN_MEMBER_CHECK_ENABLED') && SELFIN_MEMBER_CHECK_ENABLED) ? 'true' : 'false'));
selfinTestLine('タイムアウト : ' . (defined('SELFIN_MEMBER_CHECK_TIMEOUT') ? SELFIN_MEMBER_CHECK_TIMEOUT : '-') . '秒');
selfinTestLine('curl     : ' . (function_exists('curl_init') ? 'あり' : 'なし'));
selfinTestLine('連携可否 : ' . (selfinMemberCheckEnabled() ? '利用可能' : '利用不可（APIキー未設定・無効化・curl無しのいずれか）'));
selfinTestLine('');

if (!selfinMemberCheckEnabled()) {
    selfinTestLine('>>> 連携が有効ではないため問い合わせを行いませんでした。');
    selfinTestLine('    backend/config/secrets.php もしくは環境変数 SELFIN_MEMBER_CHECK_KEY にAPIキーを設定してください。');
    exit(1);
}

$result = selfinMemberCheck($email);
selfinTestLine('HTTPステータス : ' . $result['http_code']);
if ($result['exists'] === true) {
    selfinTestLine('判定           : exists = true（セルフィンPro登録あり）');
} elseif ($result['exists'] === false) {
    selfinTestLine('判定           : exists = false（セルフィンPro登録なし）');
} else {
    selfinTestLine('判定           : 判定できませんでした（エラー: ' . ($result['error'] ?? '不明') . '）');
    if ($result['http_code'] === 401) {
        selfinTestLine('');
        selfinTestLine('401はIPアドレス制限またはAPIキーのチェックに問題がある可能性があります。');
        selfinTestLine('本番サーバー（グローバルIP 85.131.209.117）から実行しているかを確認し、');
        selfinTestLine('解消しない場合はセルフィン側へ状況確認を依頼してください。');
    }
    exit(1);
}

selfinTestLine('');
selfinTestLine('--- チャットに表示される案内文 ---');
selfinTestLine(selfinMemberGuidanceMessage($result['exists']));
