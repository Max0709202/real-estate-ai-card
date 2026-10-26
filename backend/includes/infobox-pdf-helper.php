<?php
/**
 * 取引台帳PDFの生成（外部ライブラリなし）。
 *
 * サーバーに PDF ライブラリが無いため、最小限の PDF 書き出しを自前で持つ。
 * 日本語は PDF 標準の日本語フォント（HeiseiKakuGo-W5 / Adobe-Japan1、埋め込みなし）で描く。
 * Acrobat・ブラウザ（Chrome / Edge / Safari）・プレビュー等は端末の日本語フォントで表示する。
 * 文字は UniJIS-UCS2-H で出し、英数字・半角カナは半角幅、その他は全角幅で固定する。
 *
 * レイアウトは「不動産取引台帳 売買（デザイン版）」に合わせている。
 */

class IboxPdf
{
    const PAGE_W = 595.28;
    const PAGE_H = 841.89;

    /** @var string[] */
    private $pages = [];
    private $current = '';

    public function addPage(): void
    {
        if ($this->current !== '') $this->pages[] = $this->current;
        $this->current = "0 0 0 RG 0 0 0 rg\n";
    }

    /** 上端基準の y を PDF 座標へ。 */
    private function py(float $y): float
    {
        return self::PAGE_H - $y;
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    public static function color(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = '#B8C4D0', float $lineWidth = 0.5): void
    {
        $ops = '';
        if ($fill !== null) {
            [$r, $g, $b] = self::color($fill);
            $ops .= self::num($r) . ' ' . self::num($g) . ' ' . self::num($b) . " rg\n";
        }
        if ($stroke !== null) {
            [$r, $g, $b] = self::color($stroke);
            $ops .= self::num($r) . ' ' . self::num($g) . ' ' . self::num($b) . ' RG ' . self::num($lineWidth) . " w\n";
        }
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $ops .= self::num($x) . ' ' . self::num($this->py($y + $h)) . ' ' . self::num($w) . ' ' . self::num($h) . ' re ' . $op . "\n";
        $this->current .= $ops . "0 0 0 rg 0 0 0 RG\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $stroke = '#B8C4D0', float $lineWidth = 0.5): void
    {
        [$r, $g, $b] = self::color($stroke);
        $this->current .= self::num($r) . ' ' . self::num($g) . ' ' . self::num($b) . ' RG ' . self::num($lineWidth) . " w\n"
            . self::num($x1) . ' ' . self::num($this->py($y1)) . ' m ' . self::num($x2) . ' ' . self::num($this->py($y2)) . " l S\n0 0 0 RG\n";
    }

    /** 1文字の幅（em）。英数字・半角カナは 0.5、その他は 1。 */
    private static function charEm(int $cp): float
    {
        if ($cp >= 0x20 && $cp <= 0x7E) return 0.5;
        if ($cp >= 0xFF61 && $cp <= 0xFF9F) return 0.5;
        return 1.0;
    }

    public static function textWidth(string $text, float $size): float
    {
        $w = 0.0;
        foreach (self::codepoints($text) as $cp) $w += self::charEm($cp);
        return $w * $size;
    }

    private static function codepoints(string $text): array
    {
        $cps = [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            if ($cp === false) continue;
            if ($cp > 0xFFFF) $cp = 0x3013; // BMP 外は「〓」で代用（UCS2 で表せないため）
            if ($cp === 0x09) $cp = 0x20;
            if ($cp < 0x20) continue;
            $cps[] = $cp;
        }
        return $cps;
    }

    /** y は文字のベースライン（上端基準）。align: left / right / center */
    public function text(float $x, float $y, string $text, float $size = 9, string $align = 'left', string $color = '#1F2A37'): void
    {
        if ($text === '') return;
        $w = self::textWidth($text, $size);
        if ($align === 'right') $x -= $w;
        elseif ($align === 'center') $x -= $w / 2;
        $hex = '';
        foreach (self::codepoints($text) as $cp) $hex .= sprintf('%04X', $cp);
        [$r, $g, $b] = self::color($color);
        $this->current .= self::num($r) . ' ' . self::num($g) . ' ' . self::num($b) . " rg\n"
            . 'BT /F1 ' . self::num($size) . ' Tf ' . self::num($x) . ' ' . self::num($this->py($y)) . " Td <$hex> Tj ET\n0 0 0 rg\n";
    }

    /** 幅に収まるように折り返した行を返す。 */
    public static function wrap(string $text, float $size, float $maxWidth): array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\r|\n/", $text) ?: [''] as $para) {
            $line = '';
            $width = 0.0;
            foreach (preg_split('//u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $cw = self::textWidth($ch, $size);
                if ($width + $cw > $maxWidth && $line !== '') {
                    $lines[] = $line;
                    $line = '';
                    $width = 0.0;
                }
                $line .= $ch;
                $width += $cw;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** 幅に収まらない1行は、文字サイズを下げて収める（最小 6pt）。 */
    public static function fitSize(string $text, float $size, float $maxWidth): float
    {
        while ($size > 6 && self::textWidth($text, $size) > $maxWidth) $size -= 0.5;
        return $size;
    }

    public function output(string $title = ''): string
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }
        if (!$this->pages) $this->pages[] = '';

        $objects = [];
        // 1: Catalog, 2: Pages, 3: Font(Type0), 4: CIDFont, 5: FontDescriptor, 6: Info, 7〜: page/content
        $pageCount = count($this->pages);
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) $kids[] = (7 + $i * 2) . ' 0 R';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type0 /BaseFont /HeiseiKakuGo-W5 /Encoding /UniJIS-UCS2-H /DescendantFonts [4 0 R] >>';
        $objects[4] = '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /HeiseiKakuGo-W5'
            . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 2 >>'
            . ' /FontDescriptor 5 0 R /DW 1000 /W [1 95 500 231 632 500] >>';
        $objects[5] = '<< /Type /FontDescriptor /FontName /HeiseiKakuGo-W5 /Flags 4 /FontBBox [-92 -250 1010 922]'
            . ' /ItalicAngle 0 /Ascent 752 /Descent -221 /CapHeight 737 /StemV 116 /XHeight 553 >>';
        $titleHex = '';
        foreach (self::codepoints($title) as $cp) $titleHex .= sprintf('%04X', $cp);
        $objects[6] = '<< /Producer (AI-Fcard InfoBox) /Title <FEFF' . $titleHex . '> /CreationDate (D:' . date('YmdHis') . '+09\'00\') >>';

        foreach ($this->pages as $i => $content) {
            $pageObj = 7 + $i * 2;
            $contentObj = $pageObj + 1;
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::num(self::PAGE_W) . ' ' . self::num(self::PAGE_H) . ']'
                . ' /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
            $stream = function_exists('gzcompress') ? gzcompress($content) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objects[$contentObj] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objects));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}

/**
 * 取引台帳（売買）のPDFを作る。
 * @param array $d  iboxLedgerFields() のキーを持つ入力値
 * @param array $meta ['version'=>int, 'transaction_code'=>string]
 */
function iboxRenderLedgerPdf(array $d, array $meta = []): string
{
    $v = function (string $key) use ($d): string {
        return trim((string)($d[$key] ?? ''));
    };
    $pdf = new IboxPdf();
    $pdf->addPage();

    $L = 40.0;
    $W = IboxPdf::PAGE_W - $L * 2;
    $rowH = 14.4;
    $labelFill = '#EDF1F5';
    $border = '#B8C4D0';

    // 1セル（ラベルなら薄い背景）。$suffix は右端の単位（円・㎡）。
    $cell = function (float $x, float $y, float $w, float $h, string $text, bool $label = false, string $align = 'left', string $suffix = '', float $size = 8.5) use ($pdf, $labelFill, $border) {
        $pdf->rect($x, $y, $w, $h, $label ? $labelFill : '#FFFFFF', $border);
        $pad = 4.0;
        $inner = $w - $pad * 2 - ($suffix !== '' ? IboxPdf::textWidth($suffix, $size) + 2 : 0);
        $lines = $label ? [$text] : IboxPdf::wrap($text, $size, max(10, $inner));
        if (count($lines) > 1 && count($lines) * ($size + 1.5) > $h - 2) {
            // 2行に収まらない長さは、文字を小さくして1行に寄せる
            $lines = [$text];
        }
        if (count($lines) === 1) {
            $s = IboxPdf::fitSize($lines[0], $label ? 7.5 : $size, max(10, $inner));
            $ty = $y + $h / 2 + $s * 0.36;
            $tx = $align === 'right' ? $x + $w - $pad - ($suffix !== '' ? IboxPdf::textWidth($suffix, $size) + 2 : 0) : ($align === 'center' ? $x + $w / 2 : $x + $pad);
            $pdf->text($tx, $ty, $lines[0], $s, $align, $label ? '#475569' : '#1F2A37');
        } else {
            $lh = $size + 1.5;
            $ty = $y + ($h - $lh * count($lines)) / 2 + $size;
            foreach ($lines as $i => $ln) $pdf->text($x + $pad, $ty + $i * $lh, $ln, $size);
        }
        if ($suffix !== '') $pdf->text($x + $w - $pad, $y + $h / 2 + $size * 0.36, $suffix, $size, 'right', '#475569');
    };

    // 行を横に並べる。$cols = [[幅の比率, 文字, ラベルか, 揃え, 単位], ...]
    $row = function (float $y, array $cols, float $h = 0) use ($cell, $L, $W, $rowH) {
        $h = $h > 0 ? $h : $rowH;
        $x = $L;
        foreach ($cols as $c) {
            $w = $W * $c[0];
            $cell($x, $y, $w, $h, (string)$c[1], !empty($c[2]), $c[3] ?? 'left', $c[4] ?? '');
            $x += $w;
        }
        return $y + $h;
    };

    $section = function (float $y, string $no, string $title) use ($pdf, $L) {
        $pdf->text($L, $y + 12, $no, 8, 'left', '#64748B');
        $pdf->text($L + 16, $y + 12.5, $title, 11, 'left', '#1F2A37');
        return $y + 17;
    };

    $date = function (string $value): string {
        $value = trim($value);
        if ($value === '') return '　　年　　月　　日';
        $ts = strtotime(str_replace(['年', '月', '/'], '-', rtrim($value, '日')));
        return $ts ? date('Y年n月j日', $ts) : $value;
    };
    $yen = function (string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        $digits = preg_replace('/[^\d]/', '', mb_convert_kana($value, 'n', 'UTF-8'));
        return ($digits !== '' && preg_match('/^[\d,\s円]+$/u', mb_convert_kana($value, 'n', 'UTF-8'))) ? number_format((int)$digits) : $value;
    };

    // タイトル
    $pdf->text($L, 62, '不動産取引台帳', 22, 'left', '#1F2A37');
    $pdf->text($L + IboxPdf::textWidth('不動産取引台帳', 22) + 10, 60, '売買', 11, 'left', '#475569');
    $pdf->text($L + $W * 0.62, 50, '発行日：' . $date($v('issue_date')), 8.5, 'left', '#334155');
    $pdf->text($L + $W * 0.62, 64, '担当：' . $v('staff'), 8.5, 'left', '#334155');
    $metaLine = '事務所：' . ($v('office_name') !== '' ? $v('office_name') : '―')
        . '　事業年度：' . ($v('fiscal_year') !== '' ? $v('fiscal_year') . '年度' : '―')
        . '　台帳番号：' . ($v('ledger_no') !== '' ? $v('ledger_no') : '―')
        . '　取引ID：' . ($meta['transaction_code'] ?? '―')
        . '　第' . (int)($meta['version'] ?? 1) . '版';
    $pdf->text($L, 80, $metaLine, 7.5, 'left', '#64748B');

    $y = 88.0;
    $y = $row($y, [[0.12, '契約NO', true], [0.44, $v('contract_no')], [0.12, '物件NO', true], [0.32, $v('property_no')]]);
    $y = $row($y, [[0.25, '契約成立日', true], [0.25, '決済・引渡日', true], [0.25, '契約内容', true], [0.25, '取引形態', true]]);
    $y = $row($y, [[0.25, $date($v('contract_date')), false, 'center'], [0.25, $date($v('settlement_date')), false, 'center'], [0.25, $v('contract_type') ?: '売買'], [0.25, $v('deal_form') ?: '媒介']]);

    // 01 売主・買主
    $y = $section($y + 6, '01', '売主・買主');
    foreach (['seller' => '売主', 'buyer' => '買主'] as $key => $label) {
        $groupTop = $y;
        foreach (['' => '本人', '_agent' => '代理人'] as $sfx => $sub) {
            $x0 = $L + $W * 0.06;
            $h = 12.5;
            $cell($x0, $y, $W * 0.08, $h * 2, $sub, true);
            $cell($x0 + $W * 0.08, $y, $W * 0.2, $h * 2, $v($key . $sfx . '_name'));
            $xr = $x0 + $W * 0.28;
            $wr = $W - ($xr - $L);
            $cell($xr, $y, $wr * 0.12, $h, '住所', true);
            $cell($xr + $wr * 0.12, $y, $wr * 0.88, $h, $v($key . $sfx . '_address'), false, 'left', '', 7.5);
            $cell($xr, $y + $h, $wr * 0.12, $h, '連絡先1', true);
            $cell($xr + $wr * 0.12, $y + $h, $wr * 0.38, $h, $v($key . $sfx . '_contact1'), false, 'left', '', 7.5);
            $cell($xr + $wr * 0.5, $y + $h, $wr * 0.12, $h, '連絡先2', true);
            $cell($xr + $wr * 0.62, $y + $h, $wr * 0.38, $h, $v($key . $sfx . '_contact2'), false, 'left', '', 7.5);
            $y += $h * 2;
        }
        $cell($L, $groupTop, $W * 0.06, $y - $groupTop, $label, true, 'center');
    }

    // 02 建物
    $y = $section($y + 6, '02', '建物');
    $y = $row($y, [[0.12, '名称', true], [0.5, $v('bld_name')], [0.12, '築年月', true], [0.26, $v('bld_built')]]);
    $y = $row($y, [[0.12, '所在地', true], [0.88, $v('bld_location')]]);
    $y = $row($y, [[0.12, '構造', true], [0.5, $v('bld_structure')], [0.12, '家屋番号', true], [0.26, $v('bld_house_no')]]);
    $y = $row($y, [[0.12, '種類', true], [0.5, $v('bld_kind')], [0.12, '床面積', true], [0.26, $v('bld_floor_area'), false, 'right', '㎡']]);
    $box = function (string $key, string $label) use ($v): string {
        $val = $v($key);
        return ($val !== '' && $val !== '0' && $val !== '無' ? '■' : '□') . $label;
    };
    $y = $row($y, [[0.12, '設備', true], [0.88, $box('util_electric', '電気') . '　　' . $box('util_gas', 'ガス') . '　　' . $box('util_water', '水道') . '　　' . $box('util_sewer', '下水')]]);
    $y = $row($y, [[0.12, '附属物', true], [0.88, $v('bld_annex')]]);

    // 03 土地
    $y = $section($y + 6, '03', '土地');
    $y = $row($y, [[0.12, '所在', true], [0.88, $v('land_location')]]);
    $y = $row($y, [[0.08, '地目', true], [0.24, $v('land_category')], [0.08, '形状', true], [0.24, $v('land_shape')], [0.08, '現況', true], [0.28, $v('land_current')]]);
    $y = $row($y, [[0.12, '権利', true], [0.3, $v('land_right')], [0.08, '位置', true], [0.5, $v('land_position')]]);
    $y = $row($y, [[0.12, '地積', true], [0.08, '公簿', true], [0.3, $v('land_area_registry'), false, 'right', '㎡'], [0.08, '実測', true], [0.42, $v('land_area_survey'), false, 'right', '㎡']]);
    $y = $row($y, [[0.12, '借地の場合', true], [0.08, '地主', true], [0.3, $v('lease_landlord')], [0.08, '地代', true], [0.42, $yen($v('lease_rent')), false, 'right', '円']]);
    $leasePeriod = ($v('lease_from') !== '' || $v('lease_to') !== '')
        ? $date($v('lease_from')) . ' ～ ' . $date($v('lease_to')) . ($v('lease_years') !== '' ? '（' . $v('lease_years') . '年間）' : '')
        : '　　年　　月　　日 ～ 　　年　　月　　日（　　年間）';
    $y = $row($y, [[0.12, '借地期間', true], [0.88, $leasePeriod]]);
    $y = $row($y, [[0.12, '区分', true], [0.12, '用途地域', true], [0.76, $v('land_zoning')]]);

    // 04 売買代金
    $y = $section($y + 6, '04', '売買代金');
    $pairs = [['総額', 'price_total', '手付金', 'deposit'], ['土地', 'price_land', '中間金', 'interim1'], ['建物', 'price_building', '中間金', 'interim2'], ['消費税', 'price_tax', '残金', 'balance']];
    foreach ($pairs as $pp) {
        $y = $row($y, [[0.12, $pp[0], true], [0.38, $yen($v($pp[1])), false, 'right', '円'], [0.12, $pp[2], true], [0.38, $yen($v($pp[3])), false, 'right', '円']]);
    }

    // 05 報酬受領
    $y = $section($y + 6, '05', '報酬受領');
    foreach (['seller' => '売主', 'buyer' => '買主'] as $key => $label) {
        $received = $v('fee_' . $key . '_date');
        $y = $row($y, [
            [0.08, $label, true], [0.17, $yen($v('fee_' . $key . '_base')), false, 'right', '円'],
            [0.08, '消費税', true], [0.15, $yen($v('fee_' . $key . '_tax')), false, 'right', '円'],
            [0.12, '仲介手数料', true], [0.18, $yen($v('fee_' . $key . '_total')), false, 'right', '円'],
            [0.22, '受領日 ' . ($received !== '' ? $date($received) : '　　年　　月　　日'), false, 'left'],
        ], 16);
    }
    $y = $row($y, [[0.63, '自社報酬額 合計（税込）', true, 'right'], [0.37, $yen($v('fee_total')), false, 'right', '円']], 16);

    // 06 仲介者
    $y = $section($y + 6, '06', '仲介者');
    foreach (['seller' => '売主', 'buyer' => '買主'] as $key => $label) {
        $h = 12.5;
        $cell($L, $y, $W * 0.08, $h * 2, $label, true, 'center');
        $cell($L + $W * 0.08, $y, $W * 0.12, $h * 2, '商号・名称', true);
        $cell($L + $W * 0.2, $y, $W * 0.35, $h * 2, $v('broker_' . $key . '_name'));
        $cell($L + $W * 0.55, $y, $W * 0.07, $h, '住所', true);
        $cell($L + $W * 0.62, $y, $W * 0.38, $h, $v('broker_' . $key . '_address'), false, 'left', '', 7.5);
        $cell($L + $W * 0.55, $y + $h, $W * 0.07, $h, '電話', true);
        $cell($L + $W * 0.62, $y + $h, $W * 0.38, $h, $v('broker_' . $key . '_phone'));
        $y += $h * 2;
    }

    // 07 特記事項（長い場合は次ページへ続ける）
    if ($y > IboxPdf::PAGE_H - 105) { $pdf->addPage(); $y = 40.0; }
    $y = $section($y + 6, '07', '特記事項');
    $lines = IboxPdf::wrap($v('remarks'), 8, $W - 10);
    $lineH = 11.0;
    $bottom = IboxPdf::PAGE_H - 50;
    $boxTop = $y;
    $cursor = $y + 12;
    foreach ($lines as $ln) {
        if ($cursor > $bottom) {
            $pdf->rect($L, $boxTop, $W, $cursor - $boxTop - 8, null, $border);
            $pdf->addPage();
            $boxTop = 50.0;
            $cursor = $boxTop + 12;
        }
        $pdf->text($L + 5, $cursor, $ln, 8);
        $cursor += $lineH;
    }
    $boxBottom = max($cursor - 5, $boxTop + 44);
    $pdf->rect($L, $boxTop, $W, $boxBottom - $boxTop, null, $border);

    $noteY = min($boxBottom + 14, IboxPdf::PAGE_H - 26);
    $pdf->text($L, $noteY, '取引の都度記載し、事務所ごとに管理します。各事業年度末に閉鎖し、閉鎖後5年間保存します。', 6.5, 'left', '#64748B');
    $pdf->text($L, $noteY + 9, '自ら売主となる新築住宅に係る台帳は、閉鎖後10年間保存します。', 6.5, 'left', '#64748B');

    return $pdf->output('不動産取引台帳');
}
