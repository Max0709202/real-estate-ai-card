<?php
/**
 * Serve vCard (VCF) for a business card by slug.
 * Used for "Save to address book" from QR/NFC card view.
 */
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/config/database.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header('HTTP/1.0 400 Bad Request');
    exit('Bad Request');
}

$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("
    SELECT bc.name, bc.name_romaji, bc.company_name, bc.mobile_phone, bc.company_phone,
           bc.company_address, bc.company_postal_code, bc.company_website, bc.position, bc.branch_department,
           bc.payment_status, bc.is_published, u.email
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.url_slug = ? AND u.status = 'active'
");
$stmt->execute([$slug]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}
if (!in_array($card['payment_status'], ['CR', 'BANK_PAID', 'ST']) || (int)$card['is_published'] !== 1) {
    header('HTTP/1.0 404 Not Found');
    exit('Not Found');
}

$cardUrl = rtrim(BASE_URL, '/') . '/card.php?slug=' . urlencode($slug);

/**
 * Escape a value for vCard 3.0 (escape \, ; and newlines)
 */
function vcardEscape($s) {
    if ($s === null || $s === '') return '';
    $s = str_replace(['\\', ';', "\r", "\n"], ['\\\\', '\\;', '', '\\n'], trim($s));
    return $s;
}

function vcardSplitName($name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return ['', ''];

    $parts = explode(' ', $name, 2);
    if (count($parts) === 2) {
        return [trim($parts[0]), trim($parts[1])];
    }

    return [$name, ''];
}

function vcardLegalFormKana($value) {
    $replacements = [
        '株式会社' => 'カブシキガイシャ',
        '有限会社' => 'ユウゲンガイシャ',
        '合同会社' => 'ゴウドウガイシャ',
        '合資会社' => 'ゴウシガイシャ',
        '合名会社' => 'ゴウメイガイシャ',
        '一般社団法人' => 'イッパンシャダンホウジン',
        '公益社団法人' => 'コウエキシャダンホウジン',
        '一般財団法人' => 'イッパンザイダンホウジン',
        '公益財団法人' => 'コウエキザイダンホウジン',
        '特定非営利活動法人' => 'トクテイヒエイリカツドウホウジン',
        '医療法人' => 'イリョウホウジン',
        '学校法人' => 'ガッコウホウジン',
        '社会福祉法人' => 'シャカイフクシホウジン',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $value);
}

function vcardToKatakana($value) {
    $value = trim((string)$value);
    if ($value === '') return '';

    $value = vcardLegalFormKana($value);
    if (class_exists('Transliterator')) {
        $latin = Transliterator::create('Latin-Katakana');
        if ($latin) {
            $value = $latin->transliterate($value);
        }

        $kana = Transliterator::create('Any-Katakana');
        if ($kana) {
            $value = $kana->transliterate($value);
        }
    }

    $value = mb_convert_kana($value, 'KVC', 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

[$familyName, $givenName] = vcardSplitName($card['name'] ?? '');
[$familyNameRomaji, $givenNameRomaji] = vcardSplitName($card['name_romaji'] ?? '');

$fn = vcardEscape(trim(($familyName . ' ' . $givenName)) ?: ($card['name'] ?? ''));
$familyNameEscaped = vcardEscape($familyName);
$givenNameEscaped = vcardEscape($givenName);
$org = vcardEscape($card['company_name'] ?? '');
$tel = vcardEscape($card['mobile_phone'] ?? $card['company_phone'] ?? '');
$email = vcardEscape($card['email'] ?? '');
$url = vcardEscape($cardUrl);
$phoneticFamily = vcardEscape(vcardToKatakana($familyNameRomaji ?: $familyName));
$phoneticGiven = vcardEscape(vcardToKatakana($givenNameRomaji ?: $givenName));
$phoneticOrg = vcardEscape(vcardToKatakana($card['company_name'] ?? ''));
// N: Family;Given;Middle;Prefix;Suffix
$n = $familyNameEscaped . ';' . $givenNameEscaped . ';;;';
$title = vcardEscape($card['position'] ?? '');
$role = vcardEscape($card['branch_department'] ?? '');
$adr = '';
if (!empty($card['company_postal_code']) || !empty($card['company_address'])) {
    // ADR: ;;;locality;region;postal;country
    $adr = ';;' . vcardEscape($card['company_address'] ?? '') . ';;' . vcardEscape($card['company_postal_code'] ?? '') . ';Japan';
}

$vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\n";
$vcard .= "FN:" . $fn . "\r\n";
$vcard .= "N:" . $n . "\r\n";
if ($phoneticFamily !== '') $vcard .= "X-PHONETIC-LAST-NAME:" . $phoneticFamily . "\r\n";
if ($phoneticGiven !== '') $vcard .= "X-PHONETIC-FIRST-NAME:" . $phoneticGiven . "\r\n";
if ($org !== '') $vcard .= "ORG:" . $org . "\r\n";
if ($phoneticOrg !== '') $vcard .= "X-PHONETIC-ORG:" . $phoneticOrg . "\r\n";
if ($title !== '') $vcard .= "TITLE:" . $title . "\r\n";
if ($role !== '') $vcard .= "ROLE:" . $role . "\r\n";
if ($tel !== '') $vcard .= "TEL;TYPE=CELL:" . $tel . "\r\n";
if ($email !== '') $vcard .= "EMAIL:" . $email . "\r\n";
if ($url !== '') $vcard .= "URL:" . $url . "\r\n";
if ($adr !== '') $vcard .= "ADR;TYPE=WORK:" . $adr . "\r\n";
$vcard .= "END:VCARD\r\n";

$filename = preg_replace('/[^a-zA-Z0-9_\-\p{L}]/u', '_', $card['name']) . '.vcf';
$filename = mb_substr($filename, 0, 60) . '.vcf';

header('Content-Type: text/vcard; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $vcard;
exit;
