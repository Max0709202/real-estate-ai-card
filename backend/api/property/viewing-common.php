<?php
/**
 * 内見日程調整API の共通処理（認可とレスポンス整形）。
 * 各 viewing-*.php から require する。
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property-helper.php';
require_once __DIR__ . '/../../includes/property-view-helper.php';
require_once __DIR__ . '/../../includes/customer-notification-helper.php';
require_once __DIR__ . '/../../includes/property-email-helper.php';
require_once __DIR__ . '/../../includes/viewing-helper.php';
require_once __DIR__ . '/../../includes/viewing-email-helper.php';
require_once __DIR__ . '/../../includes/viewing-calendar-helper.php';
require_once __DIR__ . '/../../includes/viewing-reminder-helper.php';
require_once __DIR__ . '/../middleware/auth.php';

if (!function_exists('viewingApiInput')) {
    /** POST本文（JSON優先）。 */
    function viewingApiInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
        return $input;
    }
}

if (!function_exists('viewingApiAuthorize')) {
    /**
     * 物件に対する操作者を判定する。
     *  - 買主（session_id + visitor_id）: 'buyer'
     *  - 買主（view_token）: 'buyer_readonly'（閲覧のみ。依頼・変更・キャンセルは不可）
     *  - 担当エージェント（ログイン）: 'agent'
     *
     * @return array{role:string, property:array}
     */
    function viewingApiAuthorize(PDO $db, int $propertyId, array $src): array
    {
        propertyEnsureTables($db);
        viewingEnsureTables($db);

        $property = viewingLoadProperty($db, $propertyId);
        if (!$property) sendErrorResponse('物件が見つかりません', 404);

        $visitorId = trim((string)($src['visitor_id'] ?? ''));
        $viewToken = trim((string)($src['view_token'] ?? ''));

        if ($viewToken !== '') {
            if (propertyViewTokenSession($db, $viewToken) !== (string)$property['session_id']) {
                sendErrorResponse('アクセス権がありません', 403);
            }
            return ['role' => 'buyer_readonly', 'property' => $property];
        }
        if ($visitorId !== '') {
            propertyVerifyCustomerSession($db, (string)$property['session_id'], $visitorId);
            return ['role' => 'buyer', 'property' => $property];
        }
        startSessionIfNotStarted();
        $userId = requireAuth();
        propertyVerifyAgentProperty($db, $propertyId, $userId);
        return ['role' => 'agent', 'property' => $property];
    }
}

if (!function_exists('viewingApiRules')) {
    /** 画面側でカレンダーを組み立てるための日程ルール。 */
    function viewingApiRules(): array
    {
        return [
            'hour_start'  => VIEWING_HOUR_START,
            'hour_end'    => VIEWING_HOUR_END,
            'step'        => VIEWING_STEP_MINUTES,
            'slot'        => VIEWING_SLOT_MINUTES,
            'days_ahead'  => VIEWING_DAYS_AHEAD,
            'min_slots'   => VIEWING_MIN_SLOTS,
            'starts'      => viewingSlotStartCandidates(),
            'today'       => viewingNow()->format('Y-m-d'),
            'limit_date'  => viewingNow()->modify('+' . VIEWING_DAYS_AHEAD . ' days')->format('Y-m-d'),
        ];
    }
}

if (!function_exists('viewingApiBlocked')) {
    /**
     * 選択不可の開始時刻（当日〜予約可能期間）。
     *  ・Googleカレンダーの既存予定と前後1時間（連携している場合のみ）
     *  ・同じ担当者の確定済みの内見と重なる枠（$excludeViewingId の案件自身は除く）
     *  ・定休日
     */
    function viewingApiBlocked(PDO $db, int $cardId, int $excludeViewingId = 0): array
    {
        $from = viewingNow()->setTime(0, 0);
        $to = $from->modify('+' . VIEWING_DAYS_AHEAD . ' days');
        $blocked = viewingCalendarIsConnected($db, $cardId)
            ? viewingCalendarBlockedFor($db, $cardId, $from, $to)
            : [];
        $blocked = array_merge(
            $blocked,
            viewingConfirmedBlockedStarts($db, $cardId, $excludeViewingId, $from, $to),
            viewingClosedDayBlockedStarts(viewingAgentClosedWeekdays($db, $cardId), $from, $to)
        );
        $blocked = array_values(array_unique($blocked));
        sort($blocked);
        return $blocked;
    }
}

if (!function_exists('viewingApiBuyerConfirmed')) {
    /**
     * 買主本人の確定済みの内見（買主へ連絡済みのもの）。
     * 担当者の予定として「選択不可」になる枠のうち、本人の内見は「内見確定」と表示するために使う。
     * 買主画面に返すため、鍵情報・売主側の連絡先は含めない。
     */
    function viewingApiBuyerConfirmed(PDO $db, string $sessionId, int $cardId): array
    {
        if ($sessionId === '' || $cardId <= 0) return [];
        try {
            $stmt = $db->prepare("SELECT property_id, confirmed_start_at, confirmed_end_at, meeting_note FROM property_viewings
                                  WHERE session_id = ? AND business_card_id = ? AND status = 'buyer_notified'
                                    AND confirmed_start_at IS NOT NULL AND confirmed_end_at IS NOT NULL
                                    AND confirmed_end_at > ?
                                  ORDER BY confirmed_start_at");
            $stmt->execute([$sessionId, $cardId, viewingNow()->format('Y-m-d H:i:s')]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('viewingApiBuyerConfirmed error: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $prop = viewingLoadProperty($db, (int)$r['property_id']) ?: [];
            $out[] = [
                'property_id'    => (int)$r['property_id'],
                'property_label' => $prop ? viewingPropertyLabel($prop) : '',
                'start_at'       => $r['confirmed_start_at'],
                'end_at'         => $r['confirmed_end_at'],
                'confirmed_text' => viewingFormatRange($r['confirmed_start_at'], $r['confirmed_end_at']),
                'meeting_note'   => (string)($r['meeting_note'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('viewingApiCasePayload')) {
    /**
     * 画面へ返す案件の内容。role により出し分ける。
     * 買主には売主仲介会社の鍵情報・連絡先・購入検討者属性を返さない（仕様 §10 確認項目10）。
     */
    function viewingApiCasePayload(PDO $db, ?array $case, array $property, string $role): array
    {
        $statusDefs = viewingStatusDefs();
        $keyDefs = viewingKeyMethodDefs();
        $isAgent = ($role === 'agent');

        $out = [
            'property' => [
                'id'    => (int)$property['id'],
                // ★物件名は金額を併記する（追加ご依頼 2026/9/20）
                'label' => viewingPropertyLabel($property),
                'price' => viewingPropertyPriceText($property),
            ],
            'rules'    => viewingApiRules(),
            'viewing'  => null,
            'slots'    => [],
            'blocked'  => [],
            'calendar' => ['connected' => false],
        ];

        // 選択不可（担当者の既存予定と前後1時間・確定済みの内見・定休日）。
        $cardId = (int)$property['business_card_id'];
        $out['calendar']['connected'] = viewingCalendarIsConnected($db, $cardId);
        $out['blocked'] = viewingApiBlocked($db, $cardId, $case ? (int)$case['id'] : 0);
        // 定休日の設定はエージェントの画面でのみ扱う。
        if ($isAgent) $out['settings'] = ['closed_weekdays' => viewingAgentClosedWeekdays($db, $cardId)];
        // 買主本人の確定済みの内見。カレンダーに「内見確定」と表示し、押すと内容を確認できるようにする。
        if ($role === 'buyer') $out['confirmed'] = viewingApiBuyerConfirmed($db, (string)($property['session_id'] ?? ''), $cardId);

        if (!$case) return $out;

        $status = (string)$case['status'];
        $v = [
            'id'                 => (int)$case['id'],
            'status'             => $status,
            'status_label'       => $statusDefs[$status]['label'] ?? $status,
            'round'              => (int)$case['round'],
            'is_rescheduling'    => (int)$case['is_rescheduling'] === 1,
            'confirmed_start_at' => $case['confirmed_start_at'],
            'confirmed_end_at'   => $case['confirmed_end_at'],
            'confirmed_text'     => viewingFormatRange($case['confirmed_start_at'], $case['confirmed_end_at']),
            'prev_text'          => viewingFormatRange($case['prev_start_at'], $case['prev_end_at']),
            'meeting_note'       => (string)($case['meeting_note'] ?? ''),
            'buyer_notified_at'  => $case['buyer_notified_at'],
            'cancel_reason'      => $case['cancel_reason'],
            'cancel_reason_text' => $case['cancel_reason_text'],
            'unavailable_reason' => $case['unavailable_reason'],
        ];

        if ($isAgent) {
            // 鍵情報・購入検討者属性はエージェントのみ。買主画面には出さない。
            $v['key_method']       = $case['key_method'];
            $v['key_method_label'] = $case['key_method'] ? ($keyDefs[$case['key_method']]['label'] ?? $case['key_method']) : '';
            $v['key_data']         = $case['key_data'] ?? [];
            $v['buyer_attributes'] = (string)($case['buyer_attributes'] ?? '');
            // 売主仲介会社からのメッセージ（回答画面「3. メッセージ」）。
            $v['seller_message']    = (string)($case['seller_message'] ?? '');
            $v['seller_message_at'] = $case['seller_message_at'] ?? null;
            $v['seller_cancel_notified_at'] = $case['seller_cancel_notified_at'];
            $v['events'] = viewingEventSummary($db, (int)$case['id']);
            // 現在の確定日時に対するリマインドの予約状況（画面に実際の予約内容を表示するため）。
            $v['reminders'] = array_values(array_filter(
                viewingReminderList($db, (int)$case['id']),
                fn($r) => (string)$r['target_start_at'] === (string)$case['confirmed_start_at']
            ));
            $v['seller'] = [
                'company'          => (string)($property['seller_company'] ?? ''),
                'person'           => (string)($property['seller_person'] ?? ''),
                'email'            => (string)($property['seller_email'] ?? ''),
                'phone'            => (string)($property['seller_phone'] ?? ''),
                'transaction_type' => (string)($property['transaction_type'] ?? ''),
                'remarks'          => (string)($property['seller_remarks'] ?? ''),
            ];
        }

        $out['viewing'] = $v;
        $out['slots'] = array_map(function ($s) {
            return [
                'id'       => (int)$s['id'],
                'start_at' => $s['start_at'],
                'end_at'   => $s['end_at'],
                'state'    => $s['state'],
                'text'     => viewingFormatRange($s['start_at'], $s['end_at']),
            ];
        }, viewingSlots($db, (int)$case['id'], (int)$case['round']));

        return $out;
    }
}

if (!function_exists('viewingApiAfterResponse')) {
    /** レスポンスを返してからメール送信などを行う（画面を待たせない）。 */
    function viewingApiAfterResponse(callable $fn): void
    {
        register_shutdown_function(function () use ($fn) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { $fn(); } catch (Throwable $e) { error_log('viewing after-response error: ' . $e->getMessage()); }
        });
    }
}
