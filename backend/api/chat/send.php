<?php
/**
 * Send a message and get bot response.
 * POST { "session_id": "...", "message": "..." } -> { "reply", "sources" }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat-helpers.php';
require_once __DIR__ . '/../../includes/openai-chat-helper.php';
require_once __DIR__ . '/../../includes/chat-intake-helper.php';
require_once __DIR__ . '/../../includes/chat-crm-helper.php';
require_once __DIR__ . '/../../includes/agent-messaging-helper.php';
require_once __DIR__ . '/../../includes/notification-helper.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/chat-phone-helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$sessionId = trim($input['session_id'] ?? '');
$message = trim($input['message'] ?? '');
$visitorId = trim($input['visitor_id'] ?? '');
$buttonSelection = isset($input['button_selection']) && is_array($input['button_selection']) ? $input['button_selection'] : null;
$attachmentIds = isset($input['attachment_ids']) && is_array($input['attachment_ids']) ? $input['attachment_ids'] : [];
$selectedMansionId = null;
// 現在地（GPS）からの土地情報照会。緯度経度が来た場合のみ有効。日本国内のおおよその
// 範囲（緯度20〜46／経度122〜154）に収まる値だけ採用し、範囲外は通常メッセージ扱い。
$geo = null;
if (isset($input['latitude'], $input['longitude']) && is_numeric($input['latitude']) && is_numeric($input['longitude'])) {
    $lat = (float)$input['latitude'];
    $lon = (float)$input['longitude'];
    if ($lat >= 20 && $lat <= 46 && $lon >= 122 && $lon <= 154) {
        $geo = ['lat' => $lat, 'lon' => $lon];
    }
}
// channel: 'ai'（AIチャット, 既定） | 'contact'（担当連絡＝人間担当へ）
$channel = (($input['channel'] ?? '') === 'contact') ? 'contact' : 'ai';
if ($visitorId !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId)) {
    $visitorId = '';
}

if ($sessionId === '' || ($message === '' && empty($attachmentIds))) {
    sendErrorResponse('session_id and message are required', 400);
}
if ($message === '' && !empty($attachmentIds)) {
    $message = '[ファイルを送信しました]';
}

// 「土地/ハザード情報を確認」ボタン：選択値(住所)で土地情報フローを実行する通常メッセージへ変換。
if ($buttonSelection !== null && ($buttonSelection['field'] ?? '') === 'land_hazard' && !empty($buttonSelection['value'])) {
    $message = trim((string)$buttonSelection['value']) . ' の土地情報・ハザード情報（用途地域・建ぺい率・容積率・都市計画・浸水／土砂／液状化など）を教えてください';
    $buttonSelection = null;
}
// マンション候補ボタン：表示ラベル（所在地入り）ではなく、検索用の名称＋所在エリアを
// 明示した通常質問へ変換する。候補をクリックすれば確実に同じDB先行フローを通る。
if ($buttonSelection !== null && ($buttonSelection['field'] ?? '') === 'mansion_lookup' && !empty($buttonSelection['value'])) {
    $selectedValue = trim((string)$buttonSelection['value']);
    if (preg_match('/^mansion_id:(\d+)$/', $selectedValue, $selectedMatch)) {
        $selectedMansionId = (int)$selectedMatch[1];
        // Resolved immediately after the DB connection is available below.
        $message = trim((string)($buttonSelection['label'] ?? '選択したマンション'));
    } else {
        $message = $selectedValue . ' の物件情報を教えてください';
    }
    $buttonSelection = null;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    ensureChatDemoColumns($db);
    $stmt = $db->prepare("SELECT id, business_card_id, visitor_identifier, handoff_mode, is_demo FROM chat_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    $stmt = $db->prepare("SELECT * FROM business_cards WHERE id = ?");
    $stmt->execute([$session['business_card_id']]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        sendErrorResponse('セッションが見つかりません', 404);
    }
    if (!canUseChatbot($card)) {
        sendErrorResponse('チャットボットはご利用いただけません。', 403);
    }
    $leadProfile = chatIntakeLoad($db, $sessionId, (int)$card['id']);
    // 体験版（デモ）名刺はSMS認証を行わないため chat_session_devices に端末行が無く、
    // chatSessionDeviceAuth() は必ず null になる。デモでは代わりに
    // 「このセッションの所有者本人か」だけを確認する（他人のデモセッションへの投稿を防ぐ）。
    // 名刺とセッションの両方がデモの場合に限る。デモ化前に作られた通常セッションは
    // 従来どおりSMS認証を要求する。
    $isDemoSession = isDemoCard($card) && !empty($session['is_demo']);
    if ($isDemoSession) {
        if ($visitorId === '' || !chatSessionVisitorAuthorized($db, $sessionId, $visitorId, $session['visitor_identifier'])) {
            sendErrorResponse('セッションを確認できません。ページを再読み込みしてください。', 403);
        }
    } else {
        $deviceAuth = $visitorId !== '' ? chatSessionDeviceAuth($db, $sessionId, $visitorId) : null;
        if ($visitorId === '' || !$deviceAuth) {
            sendErrorResponse('SMS認証の有効期限が切れています。もう一度SMS認証を行ってください。', 403);
        }
        $profileComplete = chatIntakeProfileComplete($leadProfile);
        if (!$profileComplete) {
            sendErrorResponse('ご本人情報の登録が完了していません。SMS認証後、お名前とメールアドレスを登録してください。', 403);
        }
    }
    // 送信元の端末を現所有者に更新（poll/upload の visitor 突合と整合させる）。
    $stmt = $db->prepare("UPDATE chat_sessions SET visitor_identifier = ?, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$visitorId, $sessionId]);

    // 担当連絡チャネル：人間の担当者宛のメッセージ。AIの自動応答は行わず、
    // 顧客発言を担当への未読メッセージ（channel='contact'）として保存するだけにする。
    // AI担当チャネルとは独立しているため、AIは別途このチャネルを文脈として捕捉する。
    if ($channel === 'contact') {
        $userMessageId = agentMsgInsertMessage($db, $sessionId, 'user', $message, null, 'contact');
        if (!empty($attachmentIds)) {
            agentMsgAttachMessageId($db, $attachmentIds, $userMessageId, $sessionId, 'customer');
        }
        // 担当営業へメール通知（60秒バッチ・未読中は抑制）。失敗は握りつぶす。
        notifyEnqueue($db, $sessionId, 'contact');
        sendSuccessResponse([
            'reply' => '',
            'agent_mode' => true,
            'message_id' => $userMessageId,
            'sources' => [],
            'quick_replies' => [],
        ], '担当者にお伝えしました');
    }

    if ($buttonSelection !== null) {
        chatIntakeArchiveButtonSelection($db, $sessionId, $card['id'], $buttonSelection, $message);
    }

    // Save user message (AIチャネル)
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, message) VALUES (?, 'user', 'ai', ?)");
    $stmt->execute([$sessionId, $message]);
    if (!empty($attachmentIds)) {
        agentMsgAttachMessageId($db, $attachmentIds, (int)$db->lastInsertId(), $sessionId, 'customer');
    }

    // Give the model the actual conversation so it analyzes the user's own sentences rather than a
    // mechanical keyword digest. For recap/summary requests, load the whole conversation so "まとめて"
    // produces a real summary; otherwise use a generous recent window for continuity.
    // 両チャネル（ai / contact）を時系列で取り込み、担当連絡（人間担当との会話）も
    // AIの文脈として捕捉する。発言主体は role/channel で区別してプロンプト化する。
    $isRecapRequest = function_exists('chatIsRecapRequest') && chatIsRecapRequest($message);
    $historyLimit = $isRecapRequest ? 200 : 20;
    $stmt = $db->prepare("
        SELECT role, channel, message FROM chat_messages
        WHERE session_id = ? AND deleted_at IS NULL AND id < (SELECT MAX(id) FROM chat_messages WHERE session_id = ?)
        ORDER BY id DESC
        LIMIT " . (int)$historyLimit . "
    ");
    $stmt->execute([$sessionId, $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $conversationHistory = array_reverse(array_map(function ($r) { return ['role' => $r['role'], 'channel' => $r['channel'], 'message' => $r['message']]; }, $rows));

    $quickReplies = [];
    $leadData = null;
    // 現在地(GPS)分岐は intake を通らないが、最終レスポンス組み立てで
    // $intake['sms_auth_required'] 等を参照するため、ここで必ず初期化しておく
    // （未初期化のままだと現在地照会のたびに Undefined variable 警告が出る）。
    $intake = [];

    if ($geo !== null) {
        // 現在地（GPS）照会：intake／マンション名検索は通さず、緯度経度を土地情報の
        // 公的データ取得にそのまま渡してAI回答を生成する。
        // 会話履歴は渡さない（空配列）。現在地レポートは「その座標のAPI取得結果」だけで
        // 完結する自己完結型のため、履歴を渡すと、毎回同じ定型文「現在地の土地情報を教えて
        // ください」に対して、履歴内の前回の現在地レポート（前回の住所・座標）をそのまま
        // 繰り返してしまう。その結果、移動後・別セッションでも前の住所が表示され、名刺（＝
        // セッション）ごとに挙動が変わる。毎回その時の緯度経度から生成させる。
        $agentName = $card['name'] ?? '担当者';
        $result = getBotReplyWithOpenAI($message, [], $agentName, $db, $sessionId, $geo);
        if ($result['error'] !== null || $result['reply'] === null || $result['reply'] === '') {
            error_log('Chat OpenAI error (geo): ' . ($result['error'] ?? 'empty reply'));
            $reply = getBotReplyPlaceholder($message);
            $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
        } else {
            $reply = $result['reply'];
            $sources = $result['sources'];
        }
    } else {
    // 物件名らしい問い合わせは、AIやヒアリングより先にDBで決定的に処理する。
    // DBで解決できない場合だけ従来のintake→AI解析へフォールバックする。
    $agentName = $card['name'] ?? '担当者';
    // 土地/ハザード照会の判定は chatMessageAsksLandInfo() に一本化する。ここだけ独自の
    // 狭い正規表現を持っていたため、「災害/防災/洪水/地盤/津波」等は土地照会と見なされず、
    // マンション先行応答が基礎情報だけを返して 下の 土地情報フロー（マンション名→住所→
    // ハザード）へ到達しなかった。両者の語彙を揃えて取りこぼしを無くす。
    $isMansionLandRequest = function_exists('chatMessageAsksLandInfo')
        ? chatMessageAsksLandInfo($message)
        : (bool)preg_match('/(土地情報|ハザード|用途地域|建ぺい率|容積率|都市計画|浸水|土砂|液状化)/u', $message);
    $mansionSearchTerms = chatExtractMansionSearchTerms($message);
    // 物件名を実際に含む質問だけを「マンション照会」とみなす。名称抽出は緩く、ほぼ全ての
    // 文から断片を返す（「夏季休業はいつからですか?」→「夏季休業はいつから」）ため、長さ判定
    // だけを条件にすると通常の会話まで「該当するマンションが見つかりませんでした」で
    // 上書きしてしまう。DB照会自体は下の preflight で別途実行されるので影響しない。
    // function_exists ガード：この関数は chat-public-data-helper.php にあり、send.php は
    // 同ファイルを直接 require していない（推移的読み込み）。片側だけ古いまま配信されると
    // 未定義関数は Error を投げ、下の catch (Exception) では捕捉できずチャット全体が500に
    // なる。未定義時は「物件名照会ではない」＝通常AI応答へ倒す（安全側）。
    $isMansionLookupIntent = !$isMansionLandRequest
        && !empty($mansionSearchTerms)
        && chatMansionTermLooksSpecific($mansionSearchTerms, $message)
        && function_exists('chatMansionMessageNamesBuilding')
        && chatMansionMessageNamesBuilding($message, $mansionSearchTerms);
    // 添付がある場合はテキストからのマンション先行応答を行わない。先行応答が成立すると
    // handled=true となり、下の「添付画像から物件を特定するフロー」（chatResolvePropertyFrom
    // Attachments）へ到達せず、送信された図面・資料が黙って無視されてしまうため。
    // ※候補ボタン（mansion_id 指定）は利用者の明示選択なので添付有無に関わらず優先する。
    $preflightMansionAnswer = $isMansionLandRequest
        ? null
        : ($selectedMansionId !== null
            ? chatMansionDbDirectAnswerById($db, $selectedMansionId, $message, $agentName)
            : (!empty($attachmentIds)
                ? null
                : chatMansionDbDirectAnswer($db, $message, $agentName)));
    if ($preflightMansionAnswer !== null) {
        $intake = [
            'handled' => true,
            'reply' => $preflightMansionAnswer['reply'],
            'quick_replies' => $preflightMansionAnswer['quick_replies'] ?? [],
            'data' => null,
            '_mansion_sources' => $preflightMansionAnswer['sources'] ?? [],
            '_mansion_meta' => $preflightMansionAnswer['meta'] ?? [],
        ];
    } elseif ($isMansionLookupIntent) {
        // DBで処理できなかった物件名質問はヒアリングに横取りさせず、下段のAIへ渡す。
        $intake = ['handled' => false, 'quick_replies' => [], 'data' => null];
    } else {
        $intake = processChatIntakeMessage($db, $sessionId, $card['id'], $message, [
        'from_button' => $buttonSelection !== null,
        'button_selection' => $buttonSelection,
        'agent_name' => $agentName,
        ]);
    }
    $quickReplies = $intake['quick_replies'] ?? [];
    $leadData = $intake['data'] ?? null;

    if (!empty($intake['handled'])) {
        $reply = $intake['reply'];
        $sources = $intake['_mansion_sources'] ?? [];
        if (!empty($intake['_mansion_meta'])) {
            chatLogPublicDataAccess($db, $sessionId, (int)$card['id'], $message, $intake['_mansion_meta']);
        }
    } else {
        $agentName = $card['name'] ?? '担当者';
        // 画像添付がある場合は、まず添付画像から物件（物件名・所在地・マンション名等）を特定し、
        // その物件を最優先の前提としてAI回答を生成する。過去の会話の別物件と混同させない。
        // 物件を特定できない場合は、推測で答えず「物件を特定できませんでした」と正直に返す。
        $imageProp = !empty($attachmentIds)
            ? chatResolvePropertyFromAttachments($db, $sessionId, $attachmentIds)
            : null;

        if ($imageProp !== null && $imageProp['has_image']) {
            if ($imageProp['identified']) {
                $propContext = chatBuildImagePropertyContext($imageProp['fields']);
                // 公的データ／マンションDB照会が画像の住所を対象にするよう、物件名・住所をクエリ先頭へ添える。
                $hintParts = [];
                $bName = trim((string)($imageProp['fields']['building_name'] ?? ''));
                $bAddr = trim((string)($imageProp['fields']['address'] ?? ''));
                if ($bName !== '') $hintParts[] = $bName;
                if ($bAddr !== '') $hintParts[] = $bAddr;
                $hint = implode(' ', $hintParts);
                $queryForAI = ($hint !== '' ? '対象物件：' . $hint . "\n" : '') . $message;

                $result = getBotReplyWithOpenAI($queryForAI, $conversationHistory, $agentName, $db, $sessionId, null, $propContext);
                if ($result['error'] !== null || $result['reply'] === null || $result['reply'] === '') {
                    error_log('Chat OpenAI error (image property): ' . ($result['error'] ?? 'empty reply'));
                    $reply = getBotReplyPlaceholder($message);
                    $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
                } else {
                    $reply = $result['reply'];
                    $sources = $result['sources'];
                    if ($bName !== '') {
                        $reply = $bName . 'について、お送りいただいた画像をもとに回答します。' . "\n\n" . $reply;
                    }
                }
            } else {
                // 画像はあるが物件を特定できなかった → 過去の会話から推測せず、正直に伝える。
                // 解析APIの一時失敗と、内容から物件を読み取れなかった場合とで文面を分ける。
                if (!empty($imageProp['error'])) {
                    error_log('Chat image property extraction failed: ' . $imageProp['error']);
                    $reply = "申し訳ありません。お送りいただいた画像の読み取りに一時的に失敗しました。\n\n"
                        . "お手数ですが、もう一度画像をお送りいただくか、物件名・ご住所をテキストでお知らせください。私の方でお調べしてご案内いたします。";
                } else {
                    $reply = "お送りいただいた画像から物件を特定できませんでした。\n\n"
                        . "恐れ入りますが、物件名（マンション名）や所在地がはっきり写った画像を改めてお送りいただくか、"
                        . "物件名・ご住所をテキストでお知らせいただけますでしょうか。私の方でお調べしてご案内いたします。";
                }
                $sources = [];
            }
        } else {
        // マンション名での土地/ハザード照会 → DBから住所を解決し、住所入りクエリで
        // 標準の土地情報フロー（用途地域・建ぺい率・容積率・ハザード等）を実行する。
        $mansionLand = chatMansionLandQueryAddress($db, $message);
        $directMansionAnswer = $mansionLand === null ? chatMansionDbDirectAnswer($db, $message, $agentName) : null;
        if ($directMansionAnswer !== null) {
            $reply = $directMansionAnswer['reply'];
            $sources = $directMansionAnswer['sources'];
            // マンション名・住所を表示したので「土地/ハザード情報を確認」ボタンを添える。
            if (!empty($directMansionAnswer['quick_replies'])) $quickReplies = $directMansionAnswer['quick_replies'];
            if (!empty($directMansionAnswer['meta'])) {
                chatLogPublicDataAccess($db, $sessionId, (int)$card['id'], $message, $directMansionAnswer['meta']);
            }
        } else {
            $landMessage = $mansionLand !== null ? $mansionLand['query'] : $message;
            $isUnresolvedMansionLookup = $mansionLand === null && $isMansionLookupIntent;
            if ($isUnresolvedMansionLookup) {
                // Accuracy-first policy: do not guess individual building facts
                // from model knowledge when neither exact nor similar DB rows exist.
                $reply = '該当するマンションが見つかりませんでした。正式名称や所在地などをご確認ください。';
                $sources = [];
                $result = null;
            } else {
                $result = getBotReplyWithOpenAI($landMessage, $conversationHistory, $agentName, $db, $sessionId);
            }

            if ($result !== null && ($result['error'] !== null || $result['reply'] === null || $result['reply'] === '')) {
                error_log('Chat OpenAI error: ' . ($result['error'] ?? 'empty reply'));
                $reply = getBotReplyPlaceholder($message);
                $sources = [['url' => CHAT_BLOG_BASE_URL, 'title' => '戸建てリノベINFO']];
            } elseif ($result !== null) {
                $reply = $result['reply'];
                $sources = $result['sources'];
                // マンション名から住所を解決して回答した場合、対象物件・住所を先頭に明示する。
                if ($mansionLand !== null) {
                    $reply = $mansionLand['building_name'] . '（' . $mansionLand['full_address'] . '）の土地情報です。' . "\n\n" . $reply;
                }
            }
        }
        } // 画像物件フロー else（マンション名テキストフロー）の終端
    }
    }

    // AIが本文内で「・物件名（所在地）」形式の候補を提示した場合も、DBの
    // 曖昧候補と同じ選択ボタンに変換する。既存の明示的なボタンは上書きしない。
    if (empty($quickReplies)) {
        $aiCandidateReplies = chatMansionQuickRepliesFromAiReply($reply);
        if (!empty($aiCandidateReplies)) $quickReplies = $aiCandidateReplies;
    }

    // Update lightweight conversation memory for future turns and reload continuity
    updateChatSessionMemoryHeuristic($db, $sessionId, $card['id'], $message, $reply);
    $crmCase = chatCrmSyncFromChatSession($db, $sessionId, (int)$card['id']);
    if ($crmCase && chatCrmConditionReminderDue($crmCase)) {
        $conditionReminder = chatCrmBuildConditionReminder($crmCase);
        if ($conditionReminder !== '') {
            $reply = rtrim($reply) . "\n\n" . $conditionReminder;
            chatCrmMarkConditionReminderShown($db, $sessionId, (int)$card['id']);
        }
    }

    // Save bot message after condition reminder has been appended. (AIチャネル)
    $stmt = $db->prepare("INSERT INTO chat_messages (session_id, role, channel, message) VALUES (?, 'bot', 'ai', ?)");
    $stmt->execute([$sessionId, $reply]);

    // Update session last_seen
    $stmt = $db->prepare("UPDATE chat_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);

    sendSuccessResponse([
        'reply' => $reply,
        'sources' => $sources,
        'quick_replies' => $quickReplies,
        'lead_data' => $leadData,
        'sms_auth_required' => !empty($intake['sms_auth_required']),
        'sms_auth_phone' => $intake['sms_auth_phone'] ?? '',
    ], 'OK');
} catch (Exception $e) {
    error_log('Chat send error: ' . $e->getMessage());
    sendErrorResponse('サーバーエラーが発生しました', 500);
}

/**
 * Placeholder bot response until RAG is connected.
 * Includes disclaimer and optional suggestion for loan sim / tools.
 */
function getBotReplyPlaceholder($userMessage) {
    $disclaimer = "※参考情報です。個別のご相談は担当者までお問い合わせください。";
    $intro = "ご質問ありがとうございます。現在、AI回答の生成に失敗しました。";
    $suggest = "不動産のご相談（購入・売却・リノベ・制度確認など）は、条件により答えが変わります。担当者への確認やローンシミュレーションもあわせてご利用ください。";
    return $intro . "\n\n" . $suggest . "\n\n" . $disclaimer;
}
