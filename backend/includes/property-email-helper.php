<?php
/**
 * 顧客向け「物件提案」メールの本文パーツ（物件カード・署名・共通レイアウト）。
 * -------------------------------------------------------------
 * 物件提案のお知らせメール（customer-notification-helper.php）と、
 * 未閲覧リマインドメール（property-reminder-helper.php）の双方から使う共通部品。
 *
 * 本文の構成は以下で統一する（2026/9/1 修正依頼）:
 *   ① リード文（提案 or リマインドの回数ごとの文面）
 *   ② 提案物件のカード（サムネイル・提案元・ステータス・物件名・価格・住所・間取り等）
 *   ③ 「詳細は、以下のリンクよりご確認ください。」＋［内容を確認する］ボタン
 *   ④ 署名（担当者の会社名＋氏名）
 *
 * カードはメールクライアント互換のため table + インラインCSS で組み立てる
 * （flex/grid・外部CSS・<style> は Gmail/Outlook で落ちるため使わない）。
 * サムネイルは画像配信API（image.php）へ閲覧トークン付きの絶対URLで参照する。
 * 画像がブロックされても情報が欠けないよう、物件名・価格・住所などはすべてテキストで出す。
 */

require_once __DIR__ . '/property-helper.php';        // propertySerialize() / propertyEnsureTables()
require_once __DIR__ . '/property-view-helper.php';   // property_views（物件詳細の閲覧記録）
require_once __DIR__ . '/property-unread-helper.php'; // property_customer_reads（物件選定の既読位置）

if (!defined('PROPERTY_EMAIL_MAX_CARDS')) {
    // 1通に載せる物件カードの上限。超過分は「ほかN件」とだけ伝える（メールの肥大化防止）。
    define('PROPERTY_EMAIL_MAX_CARDS', (int)(getenv('PROPERTY_EMAIL_MAX_CARDS') ?: 5));
}

if (!function_exists('propertyEmailProposedItems')) {
    /**
     * メールに載せる提案物件を取得する。
     *
     * 優先: 顧客がまだ確認していない提案物件（未閲覧）を新しい順に。
     *       ＝ property-unread-helper.php の未読カウントと同じ条件
     *          （担当が提案した物件／下書きを除く／既読位置より後／詳細も未閲覧）。
     * 代替: 未閲覧が1件も無ければ、直近に提案した物件を返す
     *       （提案直後の集計ズレでカードが1枚も出ない状態を避けるため）。
     *
     * @return array{items: array<int,array>, total: int} items=表示用に整形済み（最大 $limit 件）, total=対象の総件数
     */
    function propertyEmailProposedItems(PDO $db, string $sessionId, int $limit = PROPERTY_EMAIL_MAX_CARDS): array
    {
        $empty = ['items' => [], 'total' => 0];
        $sessionId = trim($sessionId);
        if ($sessionId === '' || $limit < 1) return $empty;

        try {
            // 未閲覧判定で参照する3テーブルを冪等に用意する（マイグレーション未実行でも動かす）。
            propertyEnsureTables($db);
            propertyViewEnsureTables($db);
            propertyUnreadEnsureTable($db);

            // 未閲覧の提案物件（新しい順）。
            $unviewedSql =
                "SELECT p.* FROM properties p
                 WHERE p.session_id = :sid
                   AND p.created_by = 'agent'
                   AND p.ocr_status <> 'draft'
                   AND p.id > COALESCE((SELECT r.last_seen_property_id FROM property_customer_reads r
                                        WHERE r.session_id = p.session_id), 0)
                   AND NOT EXISTS (SELECT 1 FROM property_views pv
                                   WHERE pv.property_id = p.id AND pv.session_id = p.session_id)
                 ORDER BY p.id DESC";
            $rows = propertyEmailFetchRows($db, $unviewedSql, $sessionId);

            if (empty($rows)) {
                // 代替: 直近に提案した物件。
                $latestSql =
                    "SELECT p.* FROM properties p
                     WHERE p.session_id = :sid
                       AND p.created_by = 'agent'
                       AND p.ocr_status <> 'draft'
                     ORDER BY p.id DESC";
                $rows = propertyEmailFetchRows($db, $latestSql, $sessionId);
                // 代替経路では総件数を出さない（「ほかN件」を誤って出さないため）。
                $rows = array_slice($rows, 0, $limit);
                $total = count($rows);
            } else {
                $total = count($rows);
                $rows = array_slice($rows, 0, $limit);
            }

            $items = [];
            foreach ($rows as $row) {
                // 顧客向け（$forAgent=false）で整形する。売主情報・閲覧回数は含まれない。
                $items[] = propertySerialize($db, $row, false, false);
            }
            return ['items' => $items, 'total' => $total];
        } catch (Throwable $e) {
            error_log('propertyEmailProposedItems error: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('propertyEmailFetchRows')) {
    /** 物件行を取得する内部ヘルパー（テーブル未作成等は空配列で返す）。 */
    function propertyEmailFetchRows(PDO $db, string $sql, string $sessionId): array
    {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([':sid' => $sessionId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('propertyEmailFetchRows error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('propertyEmailAbsoluteImageUrl')) {
    /**
     * カードのサムネイルURL（絶対URL）を組み立てる。
     * propertySerialize() の main_image_url はサイト内の相対パスのため、メール用に BASE_URL を付ける。
     * SMS認証前でも表示できるよう、リンクと同じ閲覧トークン（view_token）を付与する。
     */
    function propertyEmailAbsoluteImageUrl(?string $mainImageUrl, string $viewToken): string
    {
        $url = trim((string)$mainImageUrl);
        if ($url === '') return '';
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
        }
        if ($viewToken !== '') {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'view_token=' . rawurlencode($viewToken);
        }
        return $url;
    }
}

if (!function_exists('propertyEmailDisplayName')) {
    /** カードの見出しに出す物件名（物件名 → 建物名 の順で採用）。 */
    function propertyEmailDisplayName(array $p): string
    {
        $name = trim((string)($p['property_name'] ?? ''));
        if ($name === '') $name = trim((string)($p['building_name'] ?? ''));
        return $name !== '' ? $name : '（物件名未設定）';
    }
}

if (!function_exists('propertyEmailAreaText')) {
    /** 面積の表示（マンションは専有面積、無ければ土地面積）。 */
    function propertyEmailAreaText(array $p): string
    {
        $area = trim((string)($p['exclusive_area'] ?? ''));
        if ($area !== '') return $area;
        $land = trim((string)($p['land_area'] ?? ''));
        return $land !== '' ? '土地' . $land : '';
    }
}

if (!function_exists('propertyEmailBadgeHtml')) {
    /** カード上部のラベル（提案元・検討ステータス）を角丸のバッジとして描画する。 */
    function propertyEmailBadgeHtml(string $label, string $color): string
    {
        $label = trim($label);
        if ($label === '') return '';
        // 提案元の色は名前（blue/orange）で返るため、メール用に16進へ寄せる。
        $map = ['blue' => '#2d6cdf', 'orange' => '#f08a24'];
        $hex = $map[$color] ?? $color;
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', (string)$hex)) $hex = '#5b6470';
        return '<span style="display:inline-block;margin:0 6px 4px 0;padding:3px 10px;border:1px solid '
            . htmlspecialchars($hex, ENT_QUOTES, 'UTF-8') . ';border-radius:999px;'
            . 'font-size:12px;line-height:1.4;color:' . htmlspecialchars($hex, ENT_QUOTES, 'UTF-8') . ';white-space:nowrap;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('propertyEmailCardHtml')) {
    /** 物件カード1枚分のHTML（画面の物件カード §1/§4 と同じ情報構成）。 */
    function propertyEmailCardHtml(array $p, string $viewToken): string
    {
        $name  = htmlspecialchars(propertyEmailDisplayName($p), ENT_QUOTES, 'UTF-8');
        $price = trim((string)($p['price_text'] ?? ''));
        $addr  = trim((string)($p['address'] ?? ''));

        // 2行目: 間取り｜面積｜築年月｜現況（画面のカードと同じ並び）。
        $line2 = [];
        if (trim((string)($p['layout'] ?? '')) !== '') $line2[] = trim((string)$p['layout']);
        $area = propertyEmailAreaText($p);
        if ($area !== '') $line2[] = $area;
        if (trim((string)($p['built_year_month'] ?? '')) !== '') $line2[] = trim((string)$p['built_year_month']);
        if (trim((string)($p['current_status'] ?? '')) !== '') $line2[] = trim((string)$p['current_status']);

        $meta = [];
        if ($addr !== '') $meta[] = htmlspecialchars($addr, ENT_QUOTES, 'UTF-8');
        if ($line2) $meta[] = htmlspecialchars(implode('｜', $line2), ENT_QUOTES, 'UTF-8');

        $badges = propertyEmailBadgeHtml((string)($p['source_label'] ?? ''), (string)($p['source_color'] ?? 'blue'))
            . propertyEmailBadgeHtml((string)($p['status_label'] ?? ''), (string)($p['status_color'] ?? '#5b6470'));

        $imgUrl = propertyEmailAbsoluteImageUrl($p['main_image_url'] ?? null, $viewToken);
        $thumbCell = '';
        if ($imgUrl !== '') {
            $thumbCell = '<td width="132" valign="top" style="padding:14px 0 14px 14px;">'
                . '<img src="' . htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') . '" width="120" alt="' . $name . '"'
                . ' style="display:block;width:120px;max-width:120px;height:auto;border:0;border-radius:6px;" />'
                . '</td>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:100%;border-collapse:separate;border:1px solid #e3e6eb;border-radius:10px;'
            . 'background:#ffffff;margin:0 0 12px 0;">'
            . '<tr>'
            . $thumbCell
            . '<td valign="top" style="padding:14px;font-family:sans-serif;">'
            . ($badges !== '' ? '<div style="margin:0 0 6px 0;">' . $badges . '</div>' : '')
            . '<div style="font-size:16px;font-weight:bold;color:#1a1a1a;line-height:1.5;">' . $name . '</div>'
            . ($price !== ''
                ? '<div style="font-size:16px;font-weight:bold;color:#e8384f;line-height:1.5;margin:4px 0 6px 0;">'
                    . htmlspecialchars($price, ENT_QUOTES, 'UTF-8') . '</div>'
                : '')
            . ($meta
                ? '<div style="font-size:13px;color:#5b6470;line-height:1.8;">' . implode('<br>', $meta) . '</div>'
                : '')
            . '</td>'
            . '</tr>'
            . '</table>';
    }
}

if (!function_exists('propertyEmailCardsHtml')) {
    /** 物件カードをまとめて描画する。上限を超えた分は件数だけ添える。 */
    function propertyEmailCardsHtml(array $items, int $total, string $viewToken): string
    {
        if (empty($items)) return '';
        $html = '';
        foreach ($items as $p) {
            $html .= propertyEmailCardHtml($p, $viewToken);
        }
        $rest = $total - count($items);
        if ($rest > 0) {
            $html .= '<p style="margin:0 0 12px 0;font-size:13px;color:#5b6470;">ほか' . (int)$rest . '件の物件をご提案しています。</p>';
        }
        return $html;
    }
}

if (!function_exists('propertyEmailCardsText')) {
    /**
     * テキスト版の物件情報。
     * 依頼書の指定（カード表記が難しい場合は「物件名・価格・住所・専有面積・築年数」を配信）に合わせる。
     */
    function propertyEmailCardsText(array $items, int $total): string
    {
        if (empty($items)) return '';
        $blocks = [];
        foreach ($items as $p) {
            $lines = ['■ ' . propertyEmailDisplayName($p)];
            $price = trim((string)($p['price_text'] ?? ''));
            if ($price !== '') $lines[] = '　価格：' . $price;
            $addr = trim((string)($p['address'] ?? ''));
            if ($addr !== '') $lines[] = '　住所：' . $addr;
            $area = propertyEmailAreaText($p);
            if ($area !== '') $lines[] = '　専有面積：' . $area;
            $built = trim((string)($p['built_year_month'] ?? ''));
            if ($built !== '') $lines[] = '　築年月：' . $built;
            $blocks[] = implode("\n", $lines);
        }
        $text = implode("\n\n", $blocks);
        $rest = $total - count($items);
        if ($rest > 0) $text .= "\n\nほか" . (int)$rest . "件の物件をご提案しています。";
        return $text;
    }
}

if (!function_exists('propertyEmailAgentIdentity')) {
    /**
     * 差出人（担当者）の会社名・氏名を名刺から取得する。
     * 件名・名乗り・署名で同じ表記を使うため、ここで一元的に解決する。
     *
     * @return array{company: string, name: string, display: string}
     *         display は「会社名　氏名」（例:「リニュアル仲介株式会社　山田太郎」）。
     */
    function propertyEmailAgentIdentity(PDO $db, int $businessCardId, string $fallbackAgentName = ''): array
    {
        $company = '';
        $name = '';
        if ($businessCardId > 0) {
            try {
                $stmt = $db->prepare("SELECT company_name, name FROM business_cards WHERE id = ? LIMIT 1");
                $stmt->execute([$businessCardId]);
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $company = trim((string)($row['company_name'] ?? ''));
                    $name    = trim((string)($row['name'] ?? ''));
                }
            } catch (Throwable $e) {
                error_log('propertyEmailAgentIdentity error: ' . $e->getMessage());
            }
        }
        // 担当者の表示名は customerNotifyAgentDisplay() で「様」を付ける運用のため、ここでは素の氏名を使う。
        if ($name === '') $name = trim($fallbackAgentName);
        $parts = array_values(array_filter([$company, $name], fn($v) => $v !== ''));
        return ['company' => $company, 'name' => $name, 'display' => implode('　', $parts)];
    }
}

if (!function_exists('propertyEmailSignature')) {
    /**
     * 署名（例:「リニュアル仲介株式会社　山田太郎」）。
     * 名刺の会社名＋担当者名から組み立てる。会社名が未登録なら担当者名のみ。
     */
    function propertyEmailSignature(PDO $db, int $businessCardId, string $fallbackAgentName = ''): string
    {
        return propertyEmailAgentIdentity($db, $businessCardId, $fallbackAgentName)['display'];
    }
}

if (!function_exists('propertyEmailCustomerName')) {
    /**
     * 宛名に使うお客様のお名前。取得できない場合は空文字。
     * 参照順は宛先メールの解決（customerNotifyResolveEmail）と揃える。
     *   1) chat_lead_contacts.customer_name（お客様がご登録された連絡先）
     *   2) chat_leads.structured_data.customer_name（ヒアリングで伺ったお名前）
     *   3) chat_customer_invitations（担当が事前作成した顧客ページの入力値）
     */
    function propertyEmailCustomerName(PDO $db, string $sessionId, int $businessCardId = 0): string
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return '';

        try {
            $stmt = $db->prepare(
                "SELECT customer_name FROM chat_lead_contacts
                 WHERE session_id = ? AND customer_name IS NOT NULL AND TRIM(customer_name) <> ''
                 ORDER BY updated_at DESC LIMIT 1"
            );
            $stmt->execute([$sessionId]);
            $name = trim((string)($stmt->fetchColumn() ?: ''));
            if ($name !== '') return $name;
        } catch (Throwable $e) {
            // テーブル未作成等は無視して次の手段へ。
        }

        try {
            $stmt = $db->prepare("SELECT structured_data FROM chat_leads WHERE session_id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $sd = $stmt->fetchColumn();
            if ($sd) {
                $data = json_decode((string)$sd, true);
                if (is_array($data) && !empty($data['customer_name'])) {
                    $name = trim((string)$data['customer_name']);
                    if ($name !== '') return $name;
                }
            }
        } catch (Throwable $e) {
            // 無視。
        }

        try {
            $stmt = $db->prepare(
                "SELECT IF(COALESCE(first_name, '') = '', last_name, CONCAT(last_name, '　', first_name))
                 FROM chat_customer_invitations
                 WHERE session_id = ? AND (? = 0 OR business_card_id = ?)
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$sessionId, $businessCardId, $businessCardId]);
            $name = trim((string)($stmt->fetchColumn() ?: ''));
            if ($name !== '') return $name;
        } catch (Throwable $e) {
            // 無視。
        }

        return '';
    }
}

if (!function_exists('propertyEmailCompose')) {
    /**
     * 物件提案系メールの本文（HTML / テキスト）を組み立てる。
     * リード文だけを差し替えれば、提案のお知らせにも未閲覧リマインドにも使える。
     *
     * @param string $leadText 冒頭の文面（改行区切り。段落として描画する）
     * @param string $url      ［内容を確認する］のリンク先
     * @return array [html, text]
     */
    function propertyEmailCompose(
        PDO $db,
        string $sessionId,
        int $businessCardId,
        string $leadText,
        string $url,
        string $viewToken,
        string $fallbackAgentName = ''
    ): array {
        $proposed = propertyEmailProposedItems($db, $sessionId, PROPERTY_EMAIL_MAX_CARDS);
        $cardsHtml = propertyEmailCardsHtml($proposed['items'], $proposed['total'], $viewToken);
        $cardsText = propertyEmailCardsText($proposed['items'], $proposed['total']);
        $agent = propertyEmailAgentIdentity($db, $businessCardId, $fallbackAgentName);
        $signature = $agent['display'];

        // 冒頭の宛名・挨拶・名乗り（例:「山田 太郎様 / お世話になっております。 /
        // リニュアル仲介株式会社の山田太郎です。」）。お名前が分からない場合は「お客様」とする。
        $customerName = propertyEmailCustomerName($db, $sessionId, $businessCardId);
        $greetingLines = [$customerName !== '' ? $customerName . '様' : 'お客様'];
        $greetingLines[] = 'お世話になっております。';
        if ($agent['name'] !== '') {
            $greetingLines[] = ($agent['company'] !== '' ? $agent['company'] . 'の' : '') . $agent['name'] . 'です。';
        }

        // 冒頭の挨拶に続けて、用件（提案のお知らせ／未閲覧リマインドの各回文面）を置く。
        $bodyLines = array_merge($greetingLines, preg_split('/\R/u', trim($leadText)));

        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        // 本文は複数行あり得るため、行ごとに段落化する（空行は段落の区切りとして詰める）。
        $leadHtml = '';
        foreach ($bodyLines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $leadHtml .= '<p style="margin:0 0 8px 0;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $signatureHtml = $signature !== ''
            ? '<p style="margin:24px 0 0 0;color:#5b6470;">' . htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.8;color:#333;">'
            . $leadHtml
            . ($cardsHtml !== '' ? '<div style="margin:16px 0;">' . $cardsHtml . '</div>' : '')
            . '<p style="margin:0 0 8px 0;">詳細は、以下のリンクよりご確認ください。</p>'
            . '<p style="margin:16px 0 0 0;">'
            . '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 24px;background:#0066cc;color:#fff;text-decoration:none;border-radius:4px;">内容を確認する</a>'
            . '</p>'
            . $signatureHtml
            . '<p style="margin:24px 0 0 0;font-size:12px;color:#888;">※このメールに心当たりがない場合は破棄してください。</p>'
            . '</div>';

        $text = implode("\n\n", array_values(array_filter(array_map('trim', $bodyLines), fn($v) => $v !== ''))) . "\n\n"
            . ($cardsText !== '' ? $cardsText . "\n\n" : '')
            . "詳細は、以下のリンクよりご確認ください。\n\n"
            . "内容を確認する: {$url}\n"
            . ($signature !== '' ? "\n{$signature}\n" : '')
            . "\n※このメールに心当たりがない場合は破棄してください。\n";

        return [$html, $text];
    }
}
