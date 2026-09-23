<?php
/**
 * 内見日程調整の自動メール（M01〜M13／仕様 §9）。
 * -------------------------------------------------------------
 * 波括弧の項目はここで差し込む。URLは必ず該当物件詳細・対象者の画面へ遷移させる。
 * 共通署名は「担当会社名・担当者名・メールアドレス・電話番号・不動産AI名刺URL」。
 *
 * ★2026/9/20 追加ご依頼
 *   物件を特定するための「物件名」は、すべてのメールで金額を併記する。
 *   表記は viewingPropertyLabel()（viewing-helper.php）に一元化しているため、
 *   件名・本文・リマインドのいずれも「エルザタワー55　6500万円」の形になる。
 *
 * 送信結果は property_viewing_events に必ず残す（成功／失敗を担当者画面で確認できるようにする）。
 * 送信できなかったメールは「送信済み」にしない。
 */

require_once __DIR__ . '/functions.php';                    // sendEmail()
require_once __DIR__ . '/customer-notification-helper.php'; // customerNotifyResolveEmail()
require_once __DIR__ . '/property-email-helper.php';        // propertyEmailCustomerName()
require_once __DIR__ . '/property-view-helper.php';         // propertyViewTokenFor()（買主用URLの閲覧トークン）
require_once __DIR__ . '/viewing-helper.php';

if (!function_exists('viewingMailDefs')) {
    /**
     * メールの定義。to は宛先の役割（agent=担当エージェント / buyer=買主 / seller=売主仲介会社）。
     * track に true を付けたものだけ開封通知用の画像を埋め込む（売主仲介会社宛て）。
     */
    function viewingMailDefs(): array
    {
        return [
            'M01' => ['to' => 'agent',  'name' => '買主からエージェントへの内見依頼'],
            'M02' => ['to' => 'buyer',  'name' => '買主への再調整依頼'],
            'M03' => ['to' => 'seller', 'name' => '売主仲介会社への初回打診', 'track' => true],
            'M04' => ['to' => 'seller', 'name' => '売主仲介会社への確定通知', 'track' => true],
            'M05' => ['to' => 'agent',  'name' => 'エージェントへの確定通知'],
            'M06' => ['to' => 'buyer',  'name' => '買主への確定案内'],
            'M07' => ['to' => 'agent',  'name' => 'エージェントへの変更依頼'],
            'M08' => ['to' => 'seller', 'name' => '売主仲介会社への変更打診', 'track' => true],
            'M09' => ['to' => 'buyer',  'name' => '買主へのキャンセル受付'],
            'M10' => ['to' => 'agent',  'name' => 'エージェントへのキャンセル通知'],
            'M11' => ['to' => 'seller', 'name' => '売主仲介会社へのキャンセル通知', 'track' => true],
            'M12' => ['to' => 'buyer+agent', 'name' => '前日リマインド'],
            'M13' => ['to' => 'buyer+agent', 'name' => '当日リマインド'],
            // 仕様 §6「エージェントへ通知する」の実装。M01〜M13 とは別の内部通知。
            'N01' => ['to' => 'agent',  'name' => '売主側からの内見不可（成約・申込済み）通知'],
            'N02' => ['to' => 'agent',  'name' => '売主側からの内見不可（候補日時では調整不可）通知'],
            // 仕様 §6「本システムから買主にも案内できるようにしてください」の実装。
            // 自動送信はせず、担当者が内容を確認して送信する。
            'N03' => ['to' => 'buyer',  'name' => '買主への内見不可のご案内'],
        ];
    }
}

/* ──────────────────────────────────────────────────────────
 * 差し込み項目の解決
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingAgentContact')) {
    /**
     * 担当エージェントの連絡先。共通署名と「担当者の連絡先」表示に使う。
     * @return array{company:string,name:string,email:string,phone:string,card_url:string}
     */
    function viewingAgentContact(PDO $db, int $businessCardId): array
    {
        $out = ['company' => '', 'name' => '', 'email' => '', 'phone' => '', 'card_url' => '', 'name_card_url' => ''];
        if ($businessCardId <= 0) return $out;
        try {
            // name_card_image は後から追加したカラムのため、無い環境でも動くようにして取得する。
            $hasNameCard = false;
            try {
                $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.columns
                                     WHERE table_schema = DATABASE() AND table_name = 'business_cards' AND column_name = 'name_card_image'");
                $chk->execute();
                $hasNameCard = ((int)$chk->fetchColumn() > 0);
            } catch (Throwable $e) {
                $hasNameCard = false;
            }
            $stmt = $db->prepare("SELECT bc.company_name, bc.name, bc.mobile_phone, bc.company_phone, bc.url_slug, u.email"
                                  . ($hasNameCard ? ', bc.name_card_image' : '') . "
                                  FROM business_cards bc JOIN users u ON u.id = bc.user_id
                                  WHERE bc.id = ? LIMIT 1");
            $stmt->execute([$businessCardId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $out['company'] = trim((string)($row['company_name'] ?? ''));
                $out['name']    = trim((string)($row['name'] ?? ''));
                $out['email']   = trim((string)($row['email'] ?? ''));
                $out['phone']   = trim((string)($row['mobile_phone'] ?? '')) ?: trim((string)($row['company_phone'] ?? ''));
                $slug = trim((string)($row['url_slug'] ?? ''));
                if ($slug !== '') $out['card_url'] = rtrim(BASE_URL, '/') . '/card.php?slug=' . rawurlencode($slug);
                $nameCard = trim((string)($row['name_card_image'] ?? ''));
                if ($nameCard !== '') {
                    $out['name_card_url'] = preg_match('#^https?://#', $nameCard)
                        ? $nameCard
                        : rtrim(BASE_URL, '/') . '/' . ltrim($nameCard, '/');
                }
            }
        } catch (Throwable $e) {
            error_log('viewingAgentContact error: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('viewingMailSignature')) {
    /** 共通署名（担当会社名・担当者名・メールアドレス・電話番号・不動産AI名刺URL）。 */
    function viewingMailSignature(array $agent): array
    {
        $lines = ['──────────────'];
        $head = trim($agent['company'] . '　' . $agent['name']);
        if ($head !== '') $lines[] = $head;
        if ($agent['phone'] !== '')    $lines[] = 'TEL：' . $agent['phone'];
        if ($agent['email'] !== '')    $lines[] = 'Mail：' . $agent['email'];
        if ($agent['card_url'] !== '') $lines[] = '不動産AI名刺：' . $agent['card_url'];
        return $lines;
    }
}

if (!function_exists('viewingSellerSalutation')) {
    /**
     * 売主仲介会社の宛名（仕様 §5）。
     *  ・担当者名が未入力：「会社名 ご担当者様」
     *  ・担当者名が入力済み：「会社名 担当者名様」
     */
    function viewingSellerSalutation(array $property): array
    {
        $company = trim((string)($property['seller_company'] ?? ''));
        $person  = trim((string)($property['seller_person'] ?? ''));
        $lines = [];
        if ($company !== '') $lines[] = $company;
        $lines[] = $person !== '' ? $person . '様' : 'ご担当者様';
        return $lines;
    }
}

if (!function_exists('viewingMailContext')) {
    /**
     * メール文面の差し込み項目をまとめて解決する。
     * 送信直前に呼び、常に最新の案件状態・確定日時・待ち合わせ案内を使う。
     */
    function viewingMailContext(PDO $db, array $case): array
    {
        $property = viewingLoadProperty($db, (int)$case['property_id']) ?: [];
        $agent = viewingAgentContact($db, (int)$case['business_card_id']);
        $buyerName = function_exists('propertyEmailCustomerName')
            ? propertyEmailCustomerName($db, (string)$case['session_id'], (int)$case['business_card_id'])
            : '';

        return [
            'case'        => $case,
            'property'    => $property,
            // ★物件名は必ず金額を併記する（例：エルザタワー55　6500万円）
            'label'       => viewingPropertyLabel($property),
            'agent'       => $agent,
            'buyer_name'  => $buyerName !== '' ? $buyerName : 'お客',
            'seller_to'   => viewingSellerSalutation($property),
            'confirmed'   => viewingFormatRange($case['confirmed_start_at'] ?? null, $case['confirmed_end_at'] ?? null),
            'prev'        => viewingFormatRange($case['prev_start_at'] ?? null, $case['prev_end_at'] ?? null),
            'subject_dt'  => viewingFormatDateStart($case['confirmed_start_at'] ?? null),
            'meeting'     => trim((string)($case['meeting_note'] ?? '')),
            'url_agent'   => viewingAgentUrl((string)$case['session_id'], (int)$case['property_id']),
            'url_seller'  => viewingSellerReplyUrl($db, (int)$case['id']),
            'url_buyer'   => viewingBuyerUrl($db, (string)$case['session_id'], (int)$case['property_id'], 'detail'),
            'url_buyer_input' => viewingBuyerUrl($db, (string)$case['session_id'], (int)$case['property_id'], 'input'),
            'url_buyer_list'  => viewingBuyerUrl($db, (string)$case['session_id'], 0, 'list'),
        ];
    }
}

/* ──────────────────────────────────────────────────────────
 * 文面（M01〜M13）
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingMailBuild')) {
    /**
     * メールの件名と本文（行の配列）を組み立てる。
     * $extra には文面ごとの追加情報（対象日時・変更前日時など）を渡す。
     *
     * @return array{subject:string, lines:string[], cta:array{label:string,url:string}|null}
     */
    function viewingMailBuild(string $code, array $ctx, array $extra = []): array
    {
        $label   = $ctx['label'];            // 物件名　金額
        $buyer   = $ctx['buyer_name'];
        $agent   = $ctx['agent'];
        $company = $agent['company'];
        $person  = $agent['name'];
        $nanori  = ($company !== '' ? $company . 'の' : '') . ($person !== '' ? $person : '担当') . 'です。';
        $sign    = viewingMailSignature($agent);
        $sellerTo = $ctx['seller_to'];
        $confirmed = $ctx['confirmed'];
        $target  = trim((string)($extra['target'] ?? '')) ?: $confirmed;
        $prev    = trim((string)($extra['prev'] ?? '')) ?: $ctx['prev'];
        $changed = !empty($extra['changed']);   // 日時変更の確定か

        switch ($code) {
            case 'M01':
                return [
                    'subject' => "【内見依頼】{$buyer}様／{$label}",
                    'lines' => [
                        "{$buyer}様より、{$label}の内見依頼が届きました。",
                        '',
                        '下記URLから希望日時をご確認のうえ、日程調整をお願いいたします。',
                        '',
                        '内見日時の確認・調整：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ],
                    'cta' => ['label' => '内見日時を確認・調整する', 'url' => $ctx['url_agent']],
                ];

            case 'M02':
                return [
                    'subject' => "【内見日時の再調整】{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        "ご希望いただいた日時では、あいにく内見の調整が難しい状況です。",
                        'お手数ですが、下記URLから別の候補日時を3つ以上お選びいただけますでしょうか。',
                        '',
                        '物件：' . $label,
                        '',
                        '希望日時の再入力：' . $ctx['url_buyer_input'],
                        '',
                        'どうぞよろしくお願いいたします。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '希望日時を選び直す', 'url' => $ctx['url_buyer_input']],
                ];

            case 'M03':
                return [
                    'subject' => "【内見調整のお願い】{$label}",
                    'lines' => array_merge($sellerTo, [
                        '',
                        '突然のご連絡失礼いたします。',
                        ($company !== '' ? $company . 'の' : '') . ($person !== '' ? $person : '担当') . 'と申します。',
                        '',
                        "当社のお客様が、貴社お取り扱いの{$label}の内見を希望されております。",
                        '購入予定者と私の日程調整は済ませております。',
                        '',
                        '下記URLから、候補日時のうち最も早く内見できる日時をお選びいただけませんでしょうか？',
                        'また、鍵の受け渡し方法についてもご入力いただけますと幸いです。',
                        '',
                        '内見日時のご回答：' . $ctx['url_seller'],
                        '',
                        'お申し込み済みやご成約により内見できない場合も、リンク先からご回答いただけますと幸いです。',
                        '私のプロフィールと名刺もご確認いただけます。',
                        '',
                        'お手数をおかけしますが、よろしくお願いいたします。',
                        '',
                    ], $sign, [
                        '',
                        'このメールは、不動産AI名刺（https://www.ai-fcard.com/）の内見調整機能から送信しています。',
                    ]),
                    'cta' => ['label' => '内見日時を回答する', 'url' => $ctx['url_seller']],
                ];

            case 'M04':
                return [
                    'subject' => ($changed ? '【内見日時変更確定】' : '【内見確定】') . $ctx['subject_dt'] . "／{$label}",
                    'lines' => array_merge($sellerTo, [
                        '',
                        '内見日時のご調整をいただき、ありがとうございます。',
                        '',
                        '物件：' . $label,
                    ], $changed ? [
                        '変更前：' . $prev,
                        '変更後：' . $confirmed,
                    ] : [
                        '内見日時：' . $confirmed,
                    ], [
                        '',
                        '確定内容の確認：' . $ctx['url_seller'],
                        '',
                        '変更・キャンセルのご連絡は、下記担当者へ直接お願いいたします。',
                        '当日はどうぞよろしくお願いいたします。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '確定内容を確認する', 'url' => $ctx['url_seller']],
                ];

            case 'M05':
                return [
                    'subject' => ($changed ? '【内見日時変更確定】' : '【内見確定】') . $ctx['subject_dt'] . "／{$label}",
                    'lines' => array_merge([
                        "{$label}の内見日時が確定しました。",
                        '',
                        '買主：' . $buyer . '様',
                    ], $changed ? [
                        '変更前：' . $prev,
                        '変更後：' . $confirmed,
                    ] : [
                        '内見日時：' . $confirmed,
                    ], [
                        '',
                        '下記URLから鍵の受け渡し情報をご確認ください。',
                        '買主との待ち合わせ場所・時間を入力し、確定案内を送信してください。',
                        '',
                        '確定内容の確認・買主への連絡：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ]),
                    'cta' => ['label' => '鍵情報を確認して買主へ連絡する', 'url' => $ctx['url_agent']],
                ];

            case 'M06':
                return [
                    'subject' => ($changed ? '【内見日時変更確定】' : '【内見確定】') . $ctx['subject_dt'] . "／{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        'ご希望いただいた物件の内見日時が確定しました。',
                        '',
                        '物件：' . $label,
                    ], $changed ? [
                        '変更前：' . $prev,
                        '変更後：' . $confirmed,
                    ] : [
                        '内見日時：' . $confirmed,
                    ], [
                        '',
                        '待ち合わせ場所・時間は、下記URLからご確認ください。',
                        '日時変更やキャンセルも同じ画面からご連絡いただけます。',
                        '',
                        '内見の詳細：' . $ctx['url_buyer'],
                        '',
                        '変更・キャンセルは、できるだけお早めにお知らせください。',
                        '当日のご連絡は、お電話でもお願いいたします。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '内見の詳細を確認する', 'url' => $ctx['url_buyer']],
                ];

            case 'M07':
                return [
                    'subject' => "【内見日時変更依頼】{$buyer}様／{$label}",
                    'lines' => [
                        "{$buyer}様より、{$label}の内見日時変更依頼が届きました。",
                        '',
                        '変更前の日時：' . $prev,
                        '',
                        '下記URLから新しい希望日時をご確認のうえ、再調整をお願いいたします。',
                        '',
                        '希望日時の確認・調整：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ],
                    'cta' => ['label' => '新しい希望日時を確認する', 'url' => $ctx['url_agent']],
                ];

            case 'M08':
                return [
                    'subject' => "【内見日時の再調整のお願い】{$label}",
                    'lines' => array_merge($sellerTo, [
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        '下記内見について、お客様より日時変更の希望がございました。',
                        '',
                        '物件：' . $label,
                        '変更前の日時：' . $prev,
                        '',
                        '変更前の予約は取り消し、新しい日時での調整をお願いいたします。',
                        '下記URLから新しい候補日時をご確認ください。',
                        '',
                        '変更候補のご回答：' . $ctx['url_seller'],
                        '',
                        'お申し込み・ご成約により内見できない場合も、リンク先からご回答ください。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '変更候補を回答する', 'url' => $ctx['url_seller']],
                ];

            case 'M09':
                return [
                    'subject' => "【内見キャンセル受付】{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        "{$label}の内見キャンセルを受け付けました。",
                        '',
                        'キャンセルした日時：' . $target,
                        '',
                        '売主側へのご連絡は担当者が行います。',
                        '他の物件で内見をご希望の際は、下記URLからご依頼ください。',
                        '',
                        '物件を探す：' . $ctx['url_buyer_list'],
                        '',
                    ], $sign),
                    'cta' => ['label' => '物件を探す', 'url' => $ctx['url_buyer_list']],
                ];

            case 'M10':
                return [
                    'subject' => "【内見キャンセル】{$buyer}様／{$label}",
                    'lines' => [
                        "{$buyer}様より、{$label}の内見キャンセルを受け付けました。",
                        '',
                        '対象日時：' . $target,
                        '',
                        '下記URLから理由をご確認のうえ、「売主（仲介）会社へキャンセルを通知」ボタンで先方へご連絡ください。',
                        '',
                        'キャンセル内容の確認：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ],
                    'cta' => ['label' => 'キャンセル内容を確認する', 'url' => $ctx['url_agent']],
                ];

            case 'M11':
                return [
                    'subject' => "【内見キャンセルのご連絡】{$label}",
                    'lines' => array_merge($sellerTo, [
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        '下記内見について、お客様よりキャンセルの連絡がございました。',
                        '',
                        '物件：' . $label,
                        'キャンセルする日時：' . $target,
                        '',
                        'ご調整いただいたところ、誠に申し訳ございません。',
                        '売主様にもお伝えいただけますと幸いです。',
                        'お手数をおかけしますが、よろしくお願いいたします。',
                        '',
                    ], $sign),
                    'cta' => null,
                ];

            case 'M12':
                return [
                    'subject' => '【明日の内見予定】' . $ctx['subject_dt'] . "／{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        '明日の内見について、あらためてご案内いたします。',
                        '',
                        '物件：' . $label,
                        '内見日時：' . $confirmed,
                        '待ち合わせ場所・時間：' . ($ctx['meeting'] !== '' ? $ctx['meeting'] : '（担当者よりご案内いたします）'),
                        '担当者：' . trim($company . '　' . $person) . ($agent['phone'] !== '' ? '（TEL：' . $agent['phone'] . '）' : ''),
                        '',
                        '内見の詳細・変更・キャンセル：' . $ctx['url_buyer'],
                        '',
                        '変更やキャンセルは、できるだけお早めにお知らせください。',
                        '明日はどうぞよろしくお願いいたします。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '内見の詳細を確認する', 'url' => $ctx['url_buyer']],
                ];

            case 'M13':
                return [
                    'subject' => '【本日の内見予定】' . $ctx['subject_dt'] . "〜／{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        'おはようございます。' . $nanori,
                        '',
                        '本日の内見予定をご案内いたします。',
                        '',
                        '物件：' . $label,
                        '内見日時：' . $confirmed,
                        '待ち合わせ場所・時間：' . ($ctx['meeting'] !== '' ? $ctx['meeting'] : '（担当者よりご案内いたします）'),
                        '担当者：' . trim($company . '　' . $person) . ($agent['phone'] !== '' ? '（TEL：' . $agent['phone'] . '）' : ''),
                        '',
                        '内見の詳細：' . $ctx['url_buyer'],
                        '',
                        '遅れる場合や、当日の変更・キャンセルは、下記担当者へお電話でもご連絡ください。',
                        'お気をつけてお越しください。',
                        '',
                    ], $sign),
                    'cta' => ['label' => '内見の詳細を確認する', 'url' => $ctx['url_buyer']],
                ];
            case 'N01':
                return [
                    'subject' => "【内見不可（成約・申込済み）】{$label}",
                    'lines' => [
                        "{$label}について、売主（仲介）会社より「成約・申込済み」のご回答がありました。",
                        '',
                        '買主：' . $buyer . '様',
                        '',
                        '内見はできない状態です。買主へのご案内は、内容をご確認のうえ下記URLから送信してください。',
                        'この案件の未送信のリマインドはすべて取り消しています。',
                        '',
                        '内容の確認：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ],
                    'cta' => ['label' => '内容を確認する', 'url' => $ctx['url_agent']],
                ];

            case 'N03':
                return [
                    'subject' => "【内見について】{$label}",
                    'lines' => array_merge([
                        "{$buyer}様",
                        '',
                        'お世話になっております。' . $nanori,
                        '',
                        'ご希望いただいた下記物件について、売主側より内見をお受けできない旨のご連絡がございました。',
                        '',
                        '物件：' . $label,
                    ], trim((string)($extra['message'] ?? '')) !== '' ? array_merge([''], preg_split('/\R/u', trim((string)$extra['message']))) : [], [
                        '',
                        'せっかくご検討いただいたところ、誠に申し訳ございません。',
                        '他の物件のご提案は、下記よりご確認いただけます。',
                        '',
                        '物件を見る：' . $ctx['url_buyer_list'],
                        '',
                    ], $sign),
                    'cta' => ['label' => '物件を見る', 'url' => $ctx['url_buyer_list']],
                ];

            case 'N02':
                return [
                    'subject' => "【候補日時では内見不可】{$label}",
                    'lines' => [
                        "{$label}について、売主（仲介）会社より「候補日時では内見不可」のご回答がありました。",
                        '',
                        '買主：' . $buyer . '様',
                        '',
                        '成約・申込済みとは別の回答です。買主へ別の候補日時を3つ以上お選びいただき、再調整をお願いいたします。',
                        '',
                        '再調整：' . $ctx['url_agent'],
                        '',
                        '不動産AI名刺',
                    ],
                    'cta' => ['label' => '再調整する', 'url' => $ctx['url_agent']],
                ];
        }

        return ['subject' => '', 'lines' => [], 'cta' => null];
    }
}

/* ──────────────────────────────────────────────────────────
 * 送信
 * ────────────────────────────────────────────────────────── */

if (!function_exists('viewingMailOpenPixelUrl')) {
    /**
     * 開封通知用の画像URL（売主仲介会社宛てのみ）。
     * 画像がブロックされることもあるため、開封が取れなくても「未開封」とは断定せず、
     * ページ閲覧（page_open）・回答完了（replied）と区別して記録する。
     */
    function viewingMailOpenPixelUrl(PDO $db, int $viewingId, string $code): string
    {
        $token = viewingTokenFor($db, $viewingId, 'seller');
        if ($token === '') return '';
        return rtrim(BASE_URL, '/') . '/backend/api/property/viewing-open.php?t=' . rawurlencode($token) . '&m=' . rawurlencode($code);
    }
}

if (!function_exists('viewingMailCompose')) {
    /**
     * 本文（HTML / テキスト）を組み立てる。
     * メールクライアント互換のためインラインCSSのみ。URLは本文にもそのまま載せ、
     * ボタンが表示されない環境でも内容が欠けないようにする。
     */
    function viewingMailCompose(array $built, string $pixelUrl = ''): array
    {
        $htmlLines = '';
        foreach ($built['lines'] as $line) {
            $line = (string)$line;
            if (trim($line) === '') { $htmlLines .= '<p style="margin:0 0 8px 0;">&nbsp;</p>'; continue; }
            $htmlLines .= '<p style="margin:0 0 8px 0;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $cta = '';
        if (!empty($built['cta']) && !empty($built['cta']['url'])) {
            $cta = '<p style="margin:20px 0;">'
                . '<a href="' . htmlspecialchars($built['cta']['url'], ENT_QUOTES, 'UTF-8') . '"'
                . ' style="display:inline-block;padding:12px 24px;background:#0066cc;color:#fff;text-decoration:none;border-radius:4px;">'
                . htmlspecialchars($built['cta']['label'], ENT_QUOTES, 'UTF-8') . '</a></p>';
        }

        $pixel = $pixelUrl !== ''
            ? '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">'
            : '';

        $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">'
            . $htmlLines . $cta . $pixel . '</div>';
        $text = implode("\n", array_map(fn($l) => (string)$l, $built['lines'])) . "\n";
        return [$html, $text];
    }
}

if (!function_exists('viewingMailRecipients')) {
    /**
     * 宛先を解決する。取得できない場合は空配列（送らない・送信済みにしない）。
     * @return array<int, array{email:string, role:string}>
     */
    function viewingMailRecipients(PDO $db, array $case, string $code, array $ctx): array
    {
        $defs = viewingMailDefs();
        $to = $defs[$code]['to'] ?? '';
        $out = [];

        $addAgent = function () use (&$out, $ctx) {
            $email = trim((string)$ctx['agent']['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => $email, 'role' => 'agent'];
        };
        $addBuyer = function () use (&$out, $db, $case) {
            if (!function_exists('customerNotifyResolveEmail')) return;
            $email = customerNotifyResolveEmail($db, (string)$case['session_id'], (int)$case['business_card_id']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => $email, 'role' => 'buyer'];
        };
        $addSeller = function () use (&$out, $ctx) {
            $email = trim((string)($ctx['property']['seller_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => $email, 'role' => 'seller'];
        };

        if ($to === 'agent')  $addAgent();
        if ($to === 'buyer')  $addBuyer();
        if ($to === 'seller') $addSeller();
        // リマインドは買主と担当エージェントの双方へ送る（仕様 §7 補足案）。
        if ($to === 'buyer+agent') { $addBuyer(); $addAgent(); }

        // 同じ宛先には1通だけ。
        $seen = [];
        return array_values(array_filter($out, function ($r) use (&$seen) {
            $k = mb_strtolower($r['email']);
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));
    }
}

if (!function_exists('viewingMailSend')) {
    /**
     * 内見関連メールを送信し、結果を履歴に残す。
     *
     * @param array $extra target / prev / changed など、文面ごとの追加差し込み。
     * @param array $onlyTo 宛先を絞る場合のメールアドレス配列（リマインドの再送で使う）。
     * @return array{sent:int, failed:int, recipients:array<int,string>}
     */
    function viewingMailSend(PDO $db, array $case, string $code, array $extra = [], array $onlyTo = []): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'recipients' => []];
        $defs = viewingMailDefs();
        if (!isset($defs[$code])) return $result;

        $ctx = viewingMailContext($db, $case);
        $built = viewingMailBuild($code, $ctx, $extra);
        if ($built['subject'] === '') return $result;

        $recipients = viewingMailRecipients($db, $case, $code, $ctx);
        if ($onlyTo) {
            $allow = array_map('mb_strtolower', $onlyTo);
            $recipients = array_values(array_filter($recipients, fn($r) => in_array(mb_strtolower($r['email']), $allow, true)));
        }
        if (!$recipients) {
            viewingLogEvent($db, (int)$case['id'], 'mail_sent', [
                'mail_code' => $code, 'result' => 'no_recipient',
                'detail' => '宛先が取得できなかったため送信していません。',
            ]);
            return $result;
        }

        $pixel = !empty($defs[$code]['track']) ? viewingMailOpenPixelUrl($db, (int)$case['id'], $code) : '';
        [$html, $text] = viewingMailCompose($built, $pixel);

        foreach ($recipients as $r) {
            $ok = false;
            $error = '';
            try {
                $ok = sendEmail($r['email'], $built['subject'], $html, $text, 'viewing_' . strtolower($code), null, (int)$case['id']);
            } catch (Throwable $e) {
                $error = $e->getMessage();
                error_log('viewingMailSend(' . $code . ') error: ' . $error);
            }
            if ($ok) {
                $result['sent']++;
                $result['recipients'][] = $r['email'];
            } else {
                $result['failed']++;
            }
            viewingLogEvent($db, (int)$case['id'], 'mail_sent', [
                'mail_code' => $code,
                'recipient' => $r['email'],
                'result'    => $ok ? 'sent' : 'failed',
                'detail'    => $ok ? null : ('送信に失敗しました。' . $error),
            ]);
        }
        return $result;
    }
}
