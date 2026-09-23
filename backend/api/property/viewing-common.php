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

        // 選択不可（担当者の既存予定と前後1時間）。未連携なら空＝カレンダーは表示し候補は選べる。
        $cardId = (int)$property['business_card_id'];
        $out['calendar']['connected'] = viewingCalendarIsConnected($db, $cardId);
        if ($out['calendar']['connected']) {
            $from = viewingNow()->setTime(0, 0);
            $to = $from->modify('+' . VIEWING_DAYS_AHEAD . ' days');
            $out['blocked'] = viewingCalendarBlockedFor($db, $cardId, $from, $to);
        }

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
            $v['seller_cancel_notified_at'] = $case['seller_cancel_notified_at'];
            $v['events'] = viewingEventSummary($db, (int)$case['id']);
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
