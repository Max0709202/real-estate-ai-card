<?php
/**
 * 依存ライブラリなしの最小xlsxリーダー（ZipArchive + SimpleXML）。
 * 数式・書式は解釈せず、セルの表示元になる値だけを文字列として取り出す。
 *
 * 実装上の注意:
 * xlsxのXMLは spreadsheetml/2006/main を「既定の名前空間」として使う。SimpleXMLは
 * 既定名前空間の要素をそのままプロパティ名で辿れるため、children($ns) は使わない。
 * children($ns) を階層ごとに重ねると、2段目以降が空集合になり要素を取りこぼす
 * （「シートが見つからない」の原因になる）。r:id のような接頭辞付き属性だけは
 * attributes($ns) で取得する。scripts/import_mansion_buildings.php と同じ方針。
 */

if (!defined('XLSX_NS_DOC_REL')) {
    define('XLSX_NS_DOC_REL', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
}

function xlsxLoadXml($xml, $what) {
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException("{$what} を読み込めませんでした。");
    }
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_PARSEHUGE | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        throw new RuntimeException("{$what} のXML解析に失敗しました。");
    }
    return $doc;
}

/**
 * <si> / <is> のテキストを連結する。
 * 直下の <t> と、リッチテキスト <r><t> だけを見る。ふりがな <rPh> は入れ子の別要素
 * なので、この辿り方なら自動的に除外される。
 */
function xlsxElementText(SimpleXMLElement $node) {
    $text = '';
    foreach ($node->t as $t) $text .= (string)$t;
    foreach ($node->r as $r) {
        foreach ($r->t as $t) $text .= (string)$t;
    }
    return $text;
}

function xlsxLoadSharedStrings(ZipArchive $zip) {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if (!is_string($xml) || $xml === '') return [];
    $doc = xlsxLoadXml($xml, 'sharedStrings.xml');
    $strings = [];
    foreach ($doc->si as $si) {
        $strings[] = xlsxElementText($si);
    }
    return $strings;
}

/** ブック内のシート名一覧（診断・エラーメッセージ用）。 */
function xlsxSheetNames(ZipArchive $zip) {
    try {
        $doc = xlsxLoadXml($zip->getFromName('xl/workbook.xml'), 'workbook.xml');
    } catch (Throwable $e) {
        return [];
    }
    $names = [];
    foreach ($doc->sheets->sheet as $sheet) {
        $names[] = (string)$sheet['name'];
    }
    return $names;
}

function xlsxFindSheetPath(ZipArchive $zip, $sheetName) {
    $relsDoc = xlsxLoadXml($zip->getFromName('xl/_rels/workbook.xml.rels'), 'workbook.xml.rels');
    $wbDoc = xlsxLoadXml($zip->getFromName('xl/workbook.xml'), 'workbook.xml');

    $rels = [];
    foreach ($relsDoc->Relationship as $rel) {
        $rels[(string)$rel['Id']] = (string)$rel['Target'];
    }

    $sheets = [];
    if (isset($wbDoc->sheets)) {
        foreach ($wbDoc->sheets->sheet as $sheet) {
            $sheets[] = $sheet;
        }
    }
    if (empty($sheets)) {
        throw new RuntimeException('workbook.xml からシート一覧を取得できませんでした（XMLの構造が想定外です）。');
    }

    // 完全一致 → 前後空白を無視した一致 → 大文字小文字を無視した一致 の順で探す。
    $wanted = (string)$sheetName;
    $matchers = [
        function ($name) use ($wanted) { return $name === $wanted; },
        function ($name) use ($wanted) { return trim($name) === trim($wanted); },
        function ($name) use ($wanted) { return strcasecmp(trim($name), trim($wanted)) === 0; },
    ];

    foreach ($matchers as $matches) {
        foreach ($sheets as $sheet) {
            if (!$matches((string)$sheet['name'])) continue;
            $attrs = $sheet->attributes(XLSX_NS_DOC_REL);
            $relId = ($attrs !== null && isset($attrs->id)) ? (string)$attrs->id : '';
            if ($relId === '') {
                throw new RuntimeException("シート「{$sheet['name']}」に r:id がありません。");
            }
            if (!isset($rels[$relId])) {
                throw new RuntimeException("シート「{$sheet['name']}」のリレーション {$relId} が workbook.xml.rels にありません。");
            }
            $target = ltrim($rels[$relId], '/');
            return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }
    }

    $available = [];
    foreach ($sheets as $sheet) $available[] = (string)$sheet['name'];
    throw new RuntimeException("シート「{$sheetName}」が見つかりませんでした。存在するシート: " . implode(' / ', $available));
}

function xlsxColumnLetter($cellRef) {
    return preg_replace('/[^A-Z]/', '', strtoupper((string)$cellRef));
}

/** シートを「列文字 => 値」の配列の配列として読み込む。空セルはキーごと存在しない。 */
function xlsxReadSheetRows(ZipArchive $zip, $sheetPath, array $strings) {
    $doc = xlsxLoadXml($zip->getFromName($sheetPath), $sheetPath);
    if (!isset($doc->sheetData)) {
        throw new RuntimeException("{$sheetPath} に sheetData がありません（XMLの構造が想定外です）。");
    }
    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $type = (string)$c['t'];
            $value = '';
            if ($type === 's') {
                $idx = (int)(string)$c->v;
                $value = $strings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($c->is) ? xlsxElementText($c->is) : '';
            } else {
                $value = isset($c->v) ? (string)$c->v : '';
            }
            $letter = xlsxColumnLetter((string)$c['r']);
            if ($letter !== '') $cells[$letter] = $value;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/**
 * xlsxの1シートを読み込むショートカット。
 *
 * @return array [rows, sheetNames]
 */
function xlsxReadSheet($path, $sheetName) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHPのZipArchive拡張が必要です。');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('xlsxを開けませんでした: ' . $path);
    }
    try {
        $strings = xlsxLoadSharedStrings($zip);
        $names = xlsxSheetNames($zip);
        $sheetPath = xlsxFindSheetPath($zip, $sheetName);
        $rows = xlsxReadSheetRows($zip, $sheetPath, $strings);
    } finally {
        $zip->close();
    }
    return [$rows, $names];
}
