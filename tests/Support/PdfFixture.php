<?php

namespace Tests\Support;

/**
 * Builds small, valid PDF fixtures without a second PDF library.
 */
final class PdfFixture
{
    /**
     * @param  array<int, string>  $pages
     */
    public static function text(array $pages): string
    {
        $fontId = 2 + (count($pages) * 2);
        $kids = [];
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
        ];

        foreach (array_values($pages) as $index => $text) {
            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $stream = 'BT /F1 12 Tf 72 720 Td ('.self::literal($text).') Tj ET';
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[1] = '<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ', $kids).'] >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        return self::assemble($objects);
    }

    public static function arabic(string $text): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $codes = [];

        for ($offset = 0; $offset < strlen($utf16); $offset += 2) {
            $codes[] = unpack('n', substr($utf16, $offset, 2))[1];
        }

        $cid = '';
        $bfchar = '';

        foreach ($codes as $index => $code) {
            $slot = $index + 1;
            $cid .= sprintf('%04X', $slot);
            $bfchar .= sprintf("<%04X> <%04X>\n", $slot, $code);
        }

        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n".count($codes)." beginbfchar\n{$bfchar}endbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $stream = "BT /F1 12 Tf 72 720 Td <{$cid}> Tj ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type0 /BaseFont /Arial /Encoding /Identity-H /ToUnicode 6 0 R /DescendantFonts [7 0 R] >>',
            '<< /Length '.strlen($cmap)." >>\nstream\n{$cmap}\nendstream",
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Arial /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /DW 500 >>',
        ];

        return self::assemble($objects);
    }

    public static function emptyDocument(): string
    {
        return self::assemble([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 0 /Kids [] >>',
        ]);
    }

    public static function blankPage(): string
    {
        $stream = 'q 0 0 100 100 re f Q';

        return self::assemble([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ]);
    }

    public static function encrypted(): string
    {
        return self::assemble([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 0 /Kids [] >>',
            '<< /Filter /Standard /V 1 /R 2 /O (xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx) /U (yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy) /P -4 >>',
        ], '/Encrypt 3 0 R');
    }

    private static function literal(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private static function assemble(array $objects, string $trailer = ''): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $number = $index + 1;
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($number = 1; $number < $count; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $extra = $trailer === '' ? '' : ' '.$trailer;
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R{$extra} >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
