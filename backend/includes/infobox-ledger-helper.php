<?php
/**
 * 取引台帳（売買）の項目定義・自動取得・検証。
 *
 * 入力内容は、情報BOXに登録された「売買契約書」（フォルダー07）と「重要事項説明書」（フォルダー09）から
 * 自動取得する（お客様のご指示）。どちらかが無い場合は台帳を作らず、エラーを表示する。
 *
 * 自動取得のルール
 *   ・所有者が閲覧できる書類だけを読む（共有されていない書類は読まない＝第2章の最優先ルール）。
 *   ・書類に書かれている値だけを写す。書かれていない項目は空欄のまま残し、推測で埋めない。
 *   ・取得後も全項目を編集できる。空欄は所有者があとから入力する。
 *
 * 読み取りは ①書式に依存しない AI 読取（記載値の転記のみ）→ ②全宅連書式の定型パターン の順で
 * 空欄を埋める。AI が使えない環境でも②で主要項目は埋まる。
 */

require_once __DIR__ . '/infobox-helper.php';

/**
 * 台帳の項目。[ラベル, 区分, 必須, 種類]
 * 種類: text / date / money / area / check / textarea
 */
function iboxLedgerFields(): array
{
    return [
        'issue_date' => ['発行日', '台帳管理', false, 'date'],
        'staff' => ['担当', '台帳管理', true, 'text'],
        'office_name' => ['事務所', '台帳管理', true, 'text'],
        'fiscal_year' => ['事業年度', '台帳管理', true, 'text'],
        'contract_no' => ['契約NO', '台帳管理', false, 'text'],
        'property_no' => ['物件NO', '台帳管理', false, 'text'],
        'contract_date' => ['契約成立日', '取引', true, 'date'],
        'settlement_date' => ['決済・引渡日', '取引', true, 'date'],
        'contract_type' => ['契約内容', '取引', true, 'text'],
        'deal_form' => ['取引形態（媒介／代理）', '取引', true, 'text'],

        'seller_name' => ['売主 氏名', '売主・買主', true, 'text'],
        'seller_address' => ['売主 住所', '売主・買主', true, 'text'],
        'seller_contact1' => ['売主 連絡先1', '売主・買主', false, 'text'],
        'seller_contact2' => ['売主 連絡先2', '売主・買主', false, 'text'],
        'seller_agent_name' => ['売主 代理人 氏名', '売主・買主', false, 'text'],
        'seller_agent_address' => ['売主 代理人 住所', '売主・買主', false, 'text'],
        'seller_agent_contact1' => ['売主 代理人 連絡先1', '売主・買主', false, 'text'],
        'seller_agent_contact2' => ['売主 代理人 連絡先2', '売主・買主', false, 'text'],
        'buyer_name' => ['買主 氏名', '売主・買主', true, 'text'],
        'buyer_address' => ['買主 住所', '売主・買主', true, 'text'],
        'buyer_contact1' => ['買主 連絡先1', '売主・買主', false, 'text'],
        'buyer_contact2' => ['買主 連絡先2', '売主・買主', false, 'text'],
        'buyer_agent_name' => ['買主 代理人 氏名', '売主・買主', false, 'text'],
        'buyer_agent_address' => ['買主 代理人 住所', '売主・買主', false, 'text'],
        'buyer_agent_contact1' => ['買主 代理人 連絡先1', '売主・買主', false, 'text'],
        'buyer_agent_contact2' => ['買主 代理人 連絡先2', '売主・買主', false, 'text'],

        'bld_name' => ['建物 名称', '建物', false, 'text'],
        'bld_built' => ['築年月', '建物', false, 'text'],
        'bld_location' => ['建物 所在地', '建物', true, 'text'],
        'bld_structure' => ['構造', '建物', true, 'text'],
        'bld_house_no' => ['家屋番号', '建物', false, 'text'],
        'bld_kind' => ['種類（用途）', '建物', true, 'text'],
        'bld_floor_area' => ['床面積（㎡）', '建物', true, 'area'],
        'util_electric' => ['設備：電気', '建物', false, 'check'],
        'util_gas' => ['設備：ガス', '建物', false, 'check'],
        'util_water' => ['設備：水道', '建物', false, 'check'],
        'util_sewer' => ['設備：下水', '建物', false, 'check'],
        'bld_annex' => ['附属物', '建物', false, 'text'],

        'land_location' => ['土地 所在', '土地', true, 'text'],
        'land_category' => ['地目', '土地', true, 'text'],
        'land_shape' => ['形状', '土地', false, 'text'],
        'land_current' => ['現況', '土地', false, 'text'],
        'land_right' => ['権利（敷地権の割合など）', '土地', false, 'text'],
        'land_position' => ['位置', '土地', false, 'text'],
        'land_area_registry' => ['地積 公簿（㎡）', '土地', true, 'area'],
        'land_area_survey' => ['地積 実測（㎡）', '土地', false, 'area'],
        'lease_landlord' => ['借地 地主', '土地', false, 'text'],
        'lease_rent' => ['借地 地代（円）', '土地', false, 'money'],
        'lease_from' => ['借地期間 開始', '土地', false, 'date'],
        'lease_to' => ['借地期間 終了', '土地', false, 'date'],
        'lease_years' => ['借地期間（年間）', '土地', false, 'text'],
        'land_zoning' => ['用途地域', '土地', false, 'text'],

        'price_total' => ['売買代金 総額（円）', '売買代金', true, 'money'],
        'price_land' => ['うち土地（円）', '売買代金', false, 'money'],
        'price_building' => ['うち建物（円）', '売買代金', false, 'money'],
        'price_tax' => ['消費税（円）', '売買代金', false, 'money'],
        'deposit' => ['手付金（円）', '売買代金', false, 'money'],
        'interim1' => ['中間金 第1回（円）', '売買代金', false, 'money'],
        'interim2' => ['中間金 第2回（円）', '売買代金', false, 'money'],
        'balance' => ['残金（円）', '売買代金', false, 'money'],

        'fee_seller_base' => ['売主から 報酬額（税抜・円）', '報酬受領', false, 'money'],
        'fee_seller_tax' => ['売主から 消費税（円）', '報酬受領', false, 'money'],
        'fee_seller_total' => ['売主から 仲介手数料（税込・円）', '報酬受領', false, 'money'],
        'fee_seller_date' => ['売主から 受領日', '報酬受領', false, 'date'],
        'fee_buyer_base' => ['買主から 報酬額（税抜・円）', '報酬受領', false, 'money'],
        'fee_buyer_tax' => ['買主から 消費税（円）', '報酬受領', false, 'money'],
        'fee_buyer_total' => ['買主から 仲介手数料（税込・円）', '報酬受領', false, 'money'],
        'fee_buyer_date' => ['買主から 受領日', '報酬受領', false, 'date'],

        'broker_seller_name' => ['売主側仲介 商号・名称', '仲介者', false, 'text'],
        'broker_seller_address' => ['売主側仲介 住所', '仲介者', false, 'text'],
        'broker_seller_phone' => ['売主側仲介 電話', '仲介者', false, 'text'],
        'broker_buyer_name' => ['買主側仲介 商号・名称', '仲介者', false, 'text'],
        'broker_buyer_address' => ['買主側仲介 住所', '仲介者', false, 'text'],
        'broker_buyer_phone' => ['買主側仲介 電話', '仲介者', false, 'text'],

        'remarks' => ['特記事項', '特記事項', false, 'textarea'],
    ];
}

/** 入力値を項目定義のキーだけに絞り、文字列へ整える。 */
function iboxLedgerSanitize(array $input): array
{
    $out = [];
    foreach (iboxLedgerFields() as $key => $def) {
        $value = $input[$key] ?? '';
        if (is_array($value)) $value = implode(' ', array_map('strval', $value));
        $value = trim(str_replace("\0", '', (string)$value));
        if ($def[3] === 'check') $value = ($value !== '' && $value !== '0' && $value !== 'false') ? '1' : '';
        $max = $def[3] === 'textarea' ? 4000 : 300;
        if (mb_strlen($value) > $max) $value = mb_substr($value, 0, $max);
        $out[$key] = $value;
    }
    return $out;
}

/**
 * 必須項目の不足。「該当なし」は入力済みとして扱う（未入力と区別する）。
 * 建物・土地の必須は物件種別に合わせ、報酬は自社が受領する側を必須にする。
 * @return array<string,string> key => ラベル
 */
function iboxLedgerMissing(array $data, array $box): array
{
    $fields = iboxLedgerFields();
    $missing = [];
    foreach ($fields as $key => $def) {
        if (!$def[2]) continue;
        if (trim((string)($data[$key] ?? '')) === '') $missing[$key] = $def[0];
    }
    $sides = (int)$box['dual_agency'] === 1 ? ['seller', 'buyer'] : [$box['owner_side'] === 'seller' ? 'seller' : 'buyer'];
    foreach ($sides as $side) {
        $key = 'fee_' . $side . '_total';
        if (trim((string)($data[$key] ?? '')) === '') $missing[$key] = $fields[$key][0];
        $brokerKey = 'broker_' . $side . '_name';
        if (trim((string)($data[$brokerKey] ?? '')) === '') $missing[$brokerKey] = $fields[$brokerKey][0];
    }
    return $missing;
}

/* ──────────────────────────────────────────────────────────
 * 書類の読み取り
 * ────────────────────────────────────────────────────────── */

/**
 * 台帳の取得元になる書類（所有者が閲覧できる、07 売買契約書・09 重要事項説明書）。
 * @return array{contract:array[], explanation:array[]}
 */
function iboxLedgerSourceDocs(PDO $db, array $box, array $owner): array
{
    $stmt = $db->prepare("SELECT id, template_id FROM ibox_folders WHERE box_id = ? AND deleted_at IS NULL AND template_id IN ('07', '09')");
    $stmt->execute([(int)$box['id']]);
    $folderKind = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $folderKind[(int)$row['id']] = $row['template_id'] === '07' ? 'contract' : 'explanation';
    }
    $out = ['contract' => [], 'explanation' => []];
    foreach (iboxVisibleDocsByFolder($db, (int)$box['id'], $owner) as $folderId => $docs) {
        if (!isset($folderKind[$folderId])) continue;
        foreach ($docs as $doc) $out[$folderKind[$folderId]][] = $doc;
    }
    return $out;
}

/** 書類ファイルの絶対パス（Word・Excel は PDF プレビュー）。 */
function iboxDocReadablePath(array $box, array $doc): ?string
{
    $name = !empty($doc['preview_name']) ? $doc['preview_name'] : ($doc['stored_name'] ?? '');
    if ($name === '' && !empty($doc['id'])) return null;
    $path = iboxStorageDir((int)$box['id']) . '/' . basename($name);
    return is_file($path) ? $path : null;
}

function iboxPdftotextBinary(): ?string
{
    foreach (['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $cand) {
        $out = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
        if ($out && trim($out) !== '') return trim($out);
    }
    return null;
}

/** PDF のテキスト（ページ区切りは \f）。pdftotext → Ghostscript(txtwrite) の順で試す。 */
function iboxPdfText(string $path): string
{
    $text = '';
    $bin = iboxPdftotextBinary();
    if ($bin) {
        $text = (string)@shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
    }
    if (trim($text) === '' && function_exists('propertyGsBinary') && ($gs = propertyGsBinary())) {
        $text = (string)@shell_exec(escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- ' . escapeshellarg($path) . ' 2>/dev/null');
    }
    return $text;
}

/**
 * 読み取り用にテキストを整える。
 * 条文だけのページ（第○条が並ぶページ）は台帳に必要な値を含まないため省き、表の列は「 | 」で区切る。
 */
function iboxCondenseDocText(string $text, int $maxChars = 18000): string
{
    $pages = explode("\f", $text);
    $kept = [];
    foreach ($pages as $page) {
        $lines = [];
        $clauseLines = 0;
        foreach (preg_split("/\r\n|\r|\n/", $page) ?: [] as $line) {
            if (preg_match('/^\s*VPB_\S+\s*$/', $line)) continue;
            $line = preg_replace('/\s*VPB_\S+/', '', $line) ?? $line;
            $line = trim(preg_replace('/[ \t　]{3,}/u', ' | ', $line) ?? $line);
            if ($line === '' || $line === '|') continue;
            if (preg_match('/^第\s*[0-9０-９]+\s*条/u', $line)) $clauseLines++;
            $lines[] = $line;
        }
        if (!$lines) continue;
        $body = implode("\n", $lines);
        $hasValues = (bool)preg_match('/[0-9０-９][0-9０-９,，]*\s*円|㎡|住所|所在|媒介業者|宅地建物取引業者|特約|用途地域|地目/u', $body);
        if ($clauseLines >= 4 && !preg_match('/特\s*約/u', $body)) continue;
        if (!$hasValues && $clauseLines > 0) continue;
        $kept[] = $body;
    }
    $out = implode("\n----\n", $kept);
    if (mb_strlen($out) > $maxChars) {
        // 表紙側（物件・代金）を多めに、末尾（署名欄・媒介業者）も残す。
        $head = (int)($maxChars * 0.7);
        $out = mb_substr($out, 0, $head) . "\n…（中略）…\n" . mb_substr($out, -($maxChars - $head));
    }
    return $out;
}

/** 画像しか無い（スキャン）PDF は先頭数ページを JPEG にして画像で読む。 */
function iboxPdfPageImages(string $path, int $maxPages = 4): array
{
    $gs = function_exists('propertyGsBinary') ? propertyGsBinary() : null;
    if (!$gs) return [];
    $base = tempnam(sys_get_temp_dir(), 'ibox_pg_');
    @unlink($base);
    $pattern = $base . '_%d.jpg';
    @shell_exec(escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=' . (int)$maxPages
        . ' -sDEVICE=jpeg -dJPEGQ=85 -r130 -sOutputFile=' . escapeshellarg($pattern) . ' ' . escapeshellarg($path) . ' 2>/dev/null');
    $files = [];
    for ($i = 1; $i <= $maxPages; $i++) {
        $f = sprintf($pattern, $i);
        if (is_file($f) && filesize($f) > 0) $files[] = $f;
    }
    return $files;
}

/** AI への指示文（記載値の転記のみ。推測禁止）。 */
function iboxLedgerExtractionPrompt(): string
{
    $keys = [];
    foreach (iboxLedgerFields() as $key => $def) {
        if (in_array($key, ['issue_date', 'staff', 'office_name', 'fiscal_year', 'contract_no', 'property_no'], true)) continue;
        if (strpos($key, 'fee_') === 0) continue;
        $keys[] = '"' . $key . '"(' . $def[0] . ')';
    }
    return "あなたは日本の不動産売買契約書と重要事項説明書から、宅建業法の取引台帳に転記する値を抜き出す担当です。\n"
        . "次の規則を必ず守り、JSONオブジェクトだけを出力してください（説明文・コードフェンス不要）。\n"
        . "・書類に書かれている値をそのまま写す。書かれていない項目は空文字 \"\"。推測・計算・補完はしない。\n"
        . "・チェック欄（☑/■）の付いた選択肢だけを採用する。印の無い選択肢は採用しない。\n"
        . "・日付は YYYY-MM-DD（和暦は西暦へ換算：令和N年=2018+N年、平成N年=1988+N年）。\n"
        . "・金額は数字のみ（カンマ・円は付けない）。面積は数字のみ（㎡は付けない）。\n"
        . "・contract_date は契約書末尾の契約締結日（署名欄の日付）。settlement_date は引渡し（残代金支払）日。\n"
        . "・売主・買主が複数いる場合は「・」でつなぐ。代理人が記載されていれば *_agent_* に入れる。\n"
        . "・住所は署名欄・売主の表示などの記載どおり。連絡先（電話）は記載がある場合のみ。\n"
        . "・broker_* は媒介業者（宅地建物取引業者）欄。売主側・買主側が判別できない場合は空欄。\n"
        . "・区分所有建物の land_right は「所有権（敷地権 214929分の7427）」のように敷地権の種類と割合を書く。\n"
        . "・bld_structure は構造と階数（例：鉄骨鉄筋コンクリート造 地上13階建）。bld_built は新築時期（例：2002年4月）。\n"
        . "・util_electric / util_gas / util_water / util_sewer は、重要事項説明書の供給施設で直ちに利用可能とされていれば \"1\"、無ければ \"\"。\n"
        . "・remarks は特約事項のうち、台帳の参考になる要点（融資特約・手付解除期限・代理人など）を200字以内で。書かれていなければ空。\n"
        . "・deal_form は「媒介」「代理」のいずれか。contract_type は「売買」。\n"
        . "キー: " . implode(', ', $keys);
}

/** AI の出力を項目キーだけに絞る。 */
function iboxLedgerParseAiJson(?string $reply): array
{
    if (!$reply) return [];
    $reply = trim(preg_replace('/^```[a-zA-Z]*\s*|```\s*$/', '', trim($reply)) ?? '');
    $s = strpos($reply, '{');
    $e = strrpos($reply, '}');
    if ($s === false || $e === false || $e < $s) return [];
    $data = json_decode(substr($reply, $s, $e - $s + 1), true);
    if (!is_array($data)) return [];
    $fields = iboxLedgerFields();
    $out = [];
    foreach ($data as $key => $value) {
        if (!isset($fields[$key]) || strpos($key, 'fee_') === 0) continue;
        if (is_array($value)) $value = implode('・', array_map('strval', $value));
        $value = trim((string)$value);
        if ($value === '' || $value === '不明' || $value === '記載なし') continue;
        $out[$key] = $value;
    }
    return $out;
}

/** AI 読取（テキスト優先、テキストが無ければ画像）。 */
function iboxLedgerAiExtract(string $contractText, string $explanationText, array $images, array $logCtx = []): array
{
    if (!function_exists('callOpenAIChat') || !function_exists('propertyFlyerModel')) return ['fields' => [], 'error' => 'AI読取を利用できません'];
    $content = [['type' => 'text', 'text' => iboxLedgerExtractionPrompt()]];
    if ($contractText !== '') $content[] = ['type' => 'text', 'text' => "【売買契約書】\n" . $contractText];
    if ($explanationText !== '') $content[] = ['type' => 'text', 'text' => "【重要事項説明書】\n" . $explanationText];
    $count = 0;
    foreach ($images as $img) {
        $data = @file_get_contents($img);
        if ($data === false) continue;
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($data)]];
        if (++$count >= 8) break;
    }
    if (count($content) === 1) return ['fields' => [], 'error' => '読み取れる内容がありません'];

    $model = propertyFlyerModel();
    $res = callOpenAIChat([['role' => 'user', 'content' => $content]], chatOpenAIApiKeyForModel($model), $model, [
        'purpose' => 'infobox_ledger',
        'max_tokens' => 3000,
        'temperature' => 0.0,
        'timeout' => 120,
    ] + $logCtx);
    if (!empty($res['error'])) return ['fields' => [], 'error' => (string)$res['error']];
    return ['fields' => iboxLedgerParseAiJson($res['reply']), 'error' => null];
}

/* ──────────────────────────────────────────────────────────
 * 定型パターン（全宅連の売買契約書・重要事項説明書の書式）
 * AI が使えないときの取得と、AI が空欄にした項目の補完に使う。
 * ────────────────────────────────────────────────────────── */

/** 和暦・全角を含む日付を YYYY-MM-DD に。読めなければ空。 */
function iboxParseJpDate(string $text): string
{
    $t = mb_convert_kana($text, 'n', 'UTF-8');
    if (preg_match('/(\d{4})\s*[年\/\-.]\s*(\d{1,2})\s*[月\/\-.]\s*(\d{1,2})/u', $t, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/(令和|平成|昭和)\s*(\d{1,2}|元)\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $t, $m)) {
        $base = ['令和' => 2018, '平成' => 1988, '昭和' => 1925][$m[1]];
        $year = $base + ($m[2] === '元' ? 1 : (int)$m[2]);
        return sprintf('%04d-%02d-%02d', $year, $m[3], $m[4]);
    }
    return '';
}

function iboxParseYen(string $text): string
{
    $t = mb_convert_kana($text, 'n', 'UTF-8');
    if (preg_match('/(\d{1,3}(?:[,，]\d{3})+|\d+)\s*円/u', $t, $m)) {
        return preg_replace('/\D/', '', $m[1]) ?? '';
    }
    return '';
}

/** 1行の中で、ラベルの後ろにある最初の値を拾う。 */
function iboxPatternAfter(string $text, string $labelRegex, string $valueRegex): string
{
    if (preg_match('/' . $labelRegex . '[^\n]*?' . $valueRegex . '/u', $text, $m)) return trim($m[1]);
    return '';
}

/**
 * 契約書末尾の署名欄を読む。
 * 「＜売　主＞ （住所） 東京都…」の次行が住所の続き、その後の「（氏名） ○○」が氏名、という並び。
 * @return array{seller:array, seller_agent:array, buyer:array, buyer_agent:array} 各要素は [name, address] の配列
 */
function iboxLedgerSignatureBlocks(string $text): array
{
    $out = ['seller' => [], 'seller_agent' => [], 'buyer' => [], 'buyer_agent' => []];
    $lines = preg_split("/\n/", $text) ?: [];
    $current = null;
    $entry = null;
    $flush = function () use (&$out, &$current, &$entry) {
        if ($current !== null && $entry !== null && ($entry['name'] !== '' || $entry['address'] !== '')) $out[$current][] = $entry;
        $entry = null;
    };
    $lastPartyBase = 'seller';
    foreach ($lines as $line) {
        $plain = preg_replace('/[\s　]+/u', '', $line) ?? '';
        if (preg_match('/＜(売主|買主|代理人として|売主代理人|買主代理人)＞/u', $plain, $m)) {
            $flush();
            if ($m[1] === '売主') { $current = 'seller'; $lastPartyBase = 'seller'; }
            elseif ($m[1] === '買主') { $current = 'buyer'; $lastPartyBase = 'buyer'; }
            elseif ($m[1] === '売主代理人') $current = 'seller_agent';
            elseif ($m[1] === '買主代理人') $current = 'buyer_agent';
            else $current = $lastPartyBase . '_agent';
            $entry = ['name' => '', 'address' => ''];
            if (preg_match('/（住所）[\s　]*(.+)$/u', $line, $a)) $entry['address'] = trim(preg_replace('/[\s　]{2,}.*$/u', '', trim($a[1])) ?? '');
            continue;
        }
        if ($entry === null) continue;
        if (preg_match('/＜媒介業者＞|宅地建物取引業法|宅地建物取引士/u', $plain)) { $flush(); $current = null; continue; }
        if (preg_match('/（氏名）[\s　]*(.+)$/u', $line, $n)) {
            $name = trim(str_replace('㊞', '', $n[1]));
            $entry['name'] = preg_replace('/[\s　]{2,}/u', ' ', $name) ?? $name;
            continue;
        }
        if (preg_match('/（住所）[\s　]*(.+)$/u', $line, $a)) {
            $entry['address'] = trim($a[1]);
            continue;
        }
        // 住所の続き（建物名・部屋番号）
        if ($entry['address'] !== '' && $entry['name'] === '' && trim($line) !== '' && mb_strpos($line, '（') === false) {
            $entry['address'] .= ' ' . trim(preg_replace('/[\s　]{2,}.*$/u', '', trim($line)) ?? '');
        }
    }
    $flush();
    return $out;
}

/** 全宅連書式の定型パターンで主要項目を拾う。 */
function iboxLedgerPatternExtract(string $contract, string $explanation): array
{
    $out = [];
    // 書式の埋め込み記号（VPB_○○）は値の間に入り込むため先に除く
    $c = preg_replace('/VPB_\S+/u', '', mb_convert_kana($contract, 'n', 'UTF-8')) ?? '';
    $x = preg_replace('/VPB_\S+/u', '', mb_convert_kana($explanation, 'n', 'UTF-8')) ?? '';
    $yen = '([0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)\s*円';
    $date = '((?:令和|平成)\s*[0-9元]{1,2}\s*年\s*[0-9]{1,2}\s*月\s*[0-9]{1,2}\s*日|[0-9]{4}\s*年\s*[0-9]{1,2}\s*月\s*[0-9]{1,2}\s*日)';

    if (($v = iboxPatternAfter($c, '売買代金総額', $yen)) !== '') $out['price_total'] = str_replace(',', '', $v);
    if (($v = iboxPatternAfter($c, '（Ｂ２）手付金|\(B2\)手付金|手付金\s*（第', $yen)) !== '') $out['deposit'] = str_replace(',', '', $v);
    if (($v = iboxPatternAfter($c, '残代金\s*（第', $yen)) !== '') $out['balance'] = str_replace(',', '', $v);
    if (($v = iboxPatternAfter($c, '所有権移転・引渡し', $date)) !== '') $out['settlement_date'] = iboxParseJpDate($v);
    if (!isset($out['settlement_date']) && preg_match('/所有権移転・引渡し[^\n]*\n[^\n]*?' . $date . '/u', $c, $m)) $out['settlement_date'] = iboxParseJpDate($m[1]);

    // 冒頭「売主 ○○ 様 と買主 ○○ 様 は」
    if (preg_match('/売主\s+(.+?)\s*様\s*と\s*買主\s+(.+?)\s*様/u', $c, $m)) {
        $out['seller_name'] = preg_replace('/\s{2,}/u', ' ', trim($m[1]));
        $out['buyer_name'] = preg_replace('/\s{2,}/u', ' ', trim($m[2]));
    }
    // 署名欄の契約日（「令和8年3月9日」だけの行）
    $lines = preg_split("/\n/", $c) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/^\s*' . $date . '\s*$/u', $lines[$i], $m)) { $out['contract_date'] = iboxParseJpDate($m[1]); break; }
    }

    // 建物（一棟の建物の表示・専有部分）
    if (preg_match('/名\s*称\s+(\S[^\n]*?)\s*$/mu', $c, $m)) $out['bld_name'] = trim($m[1]);
    if (preg_match('/専有部分の表示（\s*([^）]+)）/u', $c, $m) && !empty($out['bld_name'])) {
        if (preg_match('/([0-9]+号室)/u', $m[1], $room)) $out['bld_name'] .= ' ' . $room[1];
    }
    if (preg_match('/所\s*在\s+(東京都|北海道|(?:京都|大阪)府|\S{2,3}県)(\S[^\n]*?)\s*$/mu', $c, $m)) {
        $out['bld_location'] = preg_replace('/\s+/u', '', $m[1] . $m[2]);
    }
    if (preg_match('/床\s*面\s*積[^\n]*?([0-9]+(?:\.[0-9]+)?)\s*㎡/u', $c, $m)) {
        // 「延床面積」ではなく専有部分の床面積（後に出る方）を採る
        if (preg_match_all('/(?<!延)床\s*面\s*積[^\n]*?([0-9]+(?:\.[0-9]+)?)\s*㎡/u', $c, $all) && $all[1]) {
            $out['bld_floor_area'] = end($all[1]);
        }
    }
    if (preg_match('/種\s*類\s+(居宅|共同住宅|店舗|事務所|居宅・店舗)/u', $c, $m)) $out['bld_kind'] = $m[1];

    // 土地（敷地権の目的である土地）
    if (preg_match('/(宅地|田|畑|山林|雑種地|原野)[\s|]*([0-9]+(?:\.[0-9]+)?)\s*㎡/u', $c, $m)) {
        $out['land_category'] = $m[1];
        $out['land_area_registry'] = $m[2];
    }
    if (preg_match('/([0-9]+)\s*分の\s*([0-9]+)/u', $c, $m)) {
        $right = preg_match('/敷地権の種類[\s\S]{0,300}?(所有権|地上権|賃借権)/u', $c, $r) ? $r[1] : '所有権';
        $out['land_right'] = $right . '（敷地権 ' . $m[1] . '分の' . $m[2] . '）';
    }

    // 構造（一棟の建物の表示）：「鉄骨鉄筋コンクリート造／…」と「地上13階」「2階建」が別行になる書式
    if (preg_match('/一棟の建物の表示[\s\S]{0,600}?([^\s|／\/]+造)/u', $c, $m)) {
        $structure = $m[1];
        if (preg_match('/一棟の建物の表示[\s\S]{0,800}?(地上\s*[0-9]+\s*階(?:建)?|[0-9]+\s*階建)/u', $c, $fl)) {
            $structure .= ' ' . preg_replace('/\s+/u', '', $fl[1]) . (mb_substr($fl[1], -1) === '建' ? '' : '建');
        }
        $out['bld_structure'] = $structure;
    }

    // 署名欄（＜売 主＞（住所）…（氏名）…）から住所・氏名・代理人を拾う
    $sign = iboxLedgerSignatureBlocks($c);
    foreach (['seller' => $sign['seller'], 'seller_agent' => $sign['seller_agent'], 'buyer' => $sign['buyer'], 'buyer_agent' => $sign['buyer_agent']] as $key => $people) {
        if (!$people) continue;
        $names = array_values(array_unique(array_filter(array_column($people, 'name'))));
        $addresses = array_values(array_unique(array_filter(array_column($people, 'address'))));
        if ($names && ($key === 'seller_agent' || $key === 'buyer_agent' || empty($out[$key . '_name']))) $out[$key . '_name'] = implode('・', $names);
        if ($addresses) $out[$key . '_address'] = implode(' ／ ', $addresses);
    }

    // 媒介業者（署名欄は「商号」の行で左右に並ぶ）
    if (preg_match_all('/(株式会社[^\s|]+|[^\s|]+株式会社|有限会社[^\s|]+|[^\s|]+有限会社)/u', mb_substr($c, (int)mb_strrpos($c, '媒介業者')), $m) && $m[1]) {
        $companies = array_values(array_unique($m[1]));
        if (count($companies) === 1) $out['_brokers'] = $companies;
        elseif (count($companies) >= 2) $out['_brokers'] = array_slice($companies, 0, 2);
    }

    // 重要事項説明書：新築時期・用途地域・供給施設
    if (preg_match('/新築時期\s*(?:\|\s*)?((?:令和|平成|昭和)\s*[0-9元]{1,2}\s*年\s*[0-9]{1,2}\s*月|[0-9]{4}\s*年\s*[0-9]{1,2}\s*月)/u', $x, $m)) {
        $d = iboxParseJpDate($m[1] . '1日');
        $out['bld_built'] = $d !== '' ? (int)substr($d, 0, 4) . '年' . (int)substr($d, 5, 2) . '月' : $m[1];
    }
    $zones = [];
    foreach (['第1種低層住居専用地域', '第2種低層住居専用地域', '第1種中高層住居専用地域', '第2種中高層住居専用地域', '第1種住居地域', '第2種住居地域', '準住居地域', '田園住居地域', '近隣商業地域', '商業地域', '準工業地域', '工業地域', '工業専用地域'] as $zone) {
        // 選択された用途地域だけ、定義文（「…地域」の直後に説明文）が付く書式
        $z = preg_quote(str_replace(['第1種', '第2種'], ['第1種', '第2種'], $zone), '/');
        if (preg_match('/^\s*' . $z . '\s*$\n\s*\S*(?:ため定める地域|とする地域|を図る地域|定める地域)/mu', mb_convert_kana($x, 'n', 'UTF-8'))) $zones[] = $zone;
    }
    if ($zones) $out['land_zoning'] = implode('・', $zones);
    if (preg_match('/飲用水[^\n]*水道/u', $x)) $out['util_water'] = '1';
    if (preg_match('/ガ\s*ス[^\n]*(都市ガス|プロパン)/u', $x) || preg_match('/^\s*都市ガス/mu', $x)) $out['util_gas'] = '1';
    if (preg_match('/排水[^\n]*公共下水/u', $x)) $out['util_sewer'] = '1';

    return array_filter($out, fn($v) => $v !== '' && $v !== []);
}

/**
 * 台帳の初期値を組み立てる。
 *   書類（AI → 定型パターン）→ BOX に登録済みの情報（仲介会社・担当者など）の順で空欄を埋める。
 * @return array{ok:bool, message?:string, data?:array, sources?:array, notes?:array}
 */
function iboxLedgerAutoFill(PDO $db, array $box, array $owner): array
{
    $sources = iboxLedgerSourceDocs($db, $box, $owner);
    $missingDocs = [];
    if (!$sources['contract']) $missingDocs[] = '売買契約書';
    if (!$sources['explanation']) $missingDocs[] = '重要事項説明書';
    if ($missingDocs) {
        return [
            'ok' => false,
            'message' => implode('と', $missingDocs) . 'が情報BOXに登録されていないため、取引台帳は作れません。'
                . '書類フォルダーの「07 売買契約書」「09 重要事項説明書」に登録（または名刺所有者への共有）をしてから、もう一度お試しください。',
        ];
    }

    $texts = ['contract' => '', 'explanation' => ''];
    $images = [];
    $notes = [];
    $sourceNames = [];
    foreach ($sources as $kind => $docs) {
        foreach ($docs as $doc) {
            $sourceNames[] = $doc['display_name'];
            $path = iboxDocReadablePath($box, iboxLoadDocument($db, (int)$box['id'], (int)$doc['id']) ?: $doc);
            if (!$path) continue;
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                $text = iboxPdfText($path);
                if (mb_strlen(trim($text)) >= 200) {
                    $texts[$kind] .= "\n" . $text;
                } else {
                    $images = array_merge($images, iboxPdfPageImages($path, 3));
                }
            } else {
                $images[] = $path;
            }
        }
    }

    $data = [];
    $ai = iboxLedgerAiExtract(
        iboxCondenseDocText($texts['contract']),
        iboxCondenseDocText($texts['explanation']),
        $images,
        ['business_card_id' => (int)$box['business_card_id']]
    );
    foreach ($images as $img) {
        if (strpos($img, sys_get_temp_dir()) === 0) @unlink($img);
    }
    if ($ai['error']) $notes[] = 'AIによる読み取りができなかったため、書類の定型欄から取得できた項目のみ入力しています。';
    foreach ($ai['fields'] as $key => $value) $data[$key] = $value;

    $pattern = iboxLedgerPatternExtract($texts['contract'], $texts['explanation']);
    $brokers = $pattern['_brokers'] ?? [];
    unset($pattern['_brokers']);
    foreach ($pattern as $key => $value) {
        if (trim((string)($data[$key] ?? '')) === '') $data[$key] = $value;
    }

    // 日付・金額の表記をそろえる
    foreach (iboxLedgerFields() as $key => $def) {
        if (!isset($data[$key])) continue;
        if ($def[3] === 'date' && ($d = iboxParseJpDate((string)$data[$key])) !== '') $data[$key] = $d;
        if ($def[3] === 'money') $data[$key] = preg_replace('/[^\d]/', '', mb_convert_kana((string)$data[$key], 'n', 'UTF-8')) ?: '';
    }

    // BOX に登録済みの情報（仲介会社・担当者・物件）で空欄を補う
    $participants = iboxParticipants($db, (int)$box['id']);
    $card = iboxOwnerCard($db, (int)$box['owner_user_id']);
    $ownerRow = null;
    foreach ($participants as $pp) if ((int)$pp['is_owner'] === 1) $ownerRow = $pp;
    $brokerRows = ['seller' => null, 'buyer' => null];
    foreach ($participants as $pp) {
        if ($pp['role'] === 'seller_agent') $brokerRows['seller'] = $pp;
        if ($pp['role'] === 'buyer_agent') $brokerRows['buyer'] = $pp;
    }
    if ($ownerRow && (int)$box['dual_agency'] === 1) { $brokerRows['seller'] = $ownerRow; $brokerRows['buyer'] = $ownerRow; }
    foreach ($brokerRows as $side => $pp) {
        if (!$pp) continue;
        $fill = [
            'broker_' . $side . '_name' => (string)$pp['company_name'],
            'broker_' . $side . '_address' => (string)$pp['address'],
            'broker_' . $side . '_phone' => (string)$pp['phone'],
        ];
        foreach ($fill as $key => $value) {
            if (trim((string)($data[$key] ?? '')) === '' && trim($value) !== '') $data[$key] = $value;
        }
    }
    if (count($brokers) >= 1 && empty($data['broker_seller_name']) && empty($data['broker_buyer_name']) && count($brokers) === 1) {
        $data['broker_' . ($box['owner_side'] === 'seller' ? 'seller' : 'buyer') . '_name'] = $brokers[0];
    }

    $defaults = [
        'issue_date' => date('Y-m-d'),
        'staff' => (string)($card['name'] ?? ''),
        'office_name' => trim((string)($card['company_name'] ?? '') . ' ' . (string)($card['branch_department'] ?? '')),
        'contract_type' => '売買',
        'deal_form' => '媒介',
        'property_no' => (string)($box['property_id'] ?? ''),
        'contract_no' => (string)$box['transaction_code'],
        'bld_name' => (string)$box['property_name'],
    ];
    foreach ($defaults as $key => $value) {
        if (trim((string)($data[$key] ?? '')) === '' && trim($value) !== '') $data[$key] = $value;
    }
    if (empty($data['fiscal_year']) && !empty($data['contract_date'])) $data['fiscal_year'] = substr($data['contract_date'], 0, 4);
    // 区分所有建物は、土地の所在を建物の所在地から補う（敷地の所在は建物と同一のため）
    if (empty($data['land_location']) && !empty($data['bld_location']) && $box['property_type'] === 'mansion') {
        $data['land_location'] = $data['bld_location'];
    }

    return [
        'ok' => true,
        'data' => iboxLedgerSanitize($data),
        'sources' => array_values(array_unique($sourceNames)),
        'notes' => $notes,
    ];
}

/** 下書きの読み込み・保存。 */
function iboxLedgerLoadDraft(PDO $db, int $boxId): ?array
{
    $stmt = $db->prepare('SELECT data_json, updated_at FROM ibox_ledger_drafts WHERE box_id = ? LIMIT 1');
    $stmt->execute([$boxId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $data = json_decode((string)$row['data_json'], true);
    return is_array($data) ? ['data' => iboxLedgerSanitize($data), 'updated_at' => $row['updated_at']] : null;
}

function iboxLedgerSaveDraft(PDO $db, int $boxId, array $data): void
{
    $stmt = $db->prepare('INSERT INTO ibox_ledger_drafts (box_id, data_json, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = VALUES(updated_at)');
    $stmt->execute([$boxId, json_encode(iboxLedgerSanitize($data), JSON_UNESCAPED_UNICODE), iboxNow()]);
}

/** 保存済みの台帳（版の一覧）。 */
function iboxLedgerVersions(PDO $db, int $boxId): array
{
    $stmt = $db->prepare('SELECT id, version, ledger_no, office_name, fiscal_year, reason, created_at FROM ibox_ledgers WHERE box_id = ? ORDER BY version DESC');
    $stmt->execute([$boxId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
