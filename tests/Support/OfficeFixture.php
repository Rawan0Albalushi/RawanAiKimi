<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds small Office Open XML fixtures without a spreadsheet library.
 */
final class OfficeFixture
{
    /**
     * @param  array<int, string|array{text: string, heading?: bool}>  $paragraphs
     * @param  array<int, array<int, string>>  $tables
     */
    public static function word(array $paragraphs, array $tables = []): string
    {
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $heading = is_array($paragraph) && ($paragraph['heading'] ?? false);
            $text = is_array($paragraph) ? $paragraph['text'] : $paragraph;
            $style = $heading ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : '';
            $body .= '<w:p>'.$style.'<w:r><w:t>'.self::escape($text).'</w:t></w:r></w:p>';
        }

        foreach ($tables as $row) {
            $cells = '';

            foreach ($row as $cell) {
                $cells .= '<w:tc><w:p><w:r><w:t>'.self::escape($cell).'</w:t></w:r></w:p></w:tc>';
            }

            $body .= '<w:tbl><w:tr>'.$cells.'</w:tr></w:tbl>';
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$body
            .'</w:body></w:document>';

        return self::package([
            '[Content_Types].xml' => self::types('word'),
            'word/document.xml' => $document,
        ]);
    }

    /**
     * @param  array<string, array<int, array<int, string|int|float|array<string, string>>>>  $sheets
     */
    public static function workbook(array $sheets, bool $external = false): string
    {
        $strings = [];
        $sheetFiles = [];
        $index = 1;
        $sheetTags = '';
        $relationships = '';

        foreach ($sheets as $name => $rows) {
            $sheetFiles['xl/worksheets/sheet'.$index.'.xml'] = self::sheet($rows, $strings);
            $sheetTags .= '<sheet name="'.self::escape((string) $name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
            $index++;
        }

        if ($external) {
            $relationships .= '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="http://example.test/outside.xlsx"/>';
        }

        $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';

        foreach ($strings as $string) {
            $shared .= '<si><t>'.self::escape($string).'</t></si>';
        }

        $shared .= '</sst>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2">'
            .'<xf numFmtId="0"/><xf numFmtId="14"/>'
            .'</cellXfs></styleSheet>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .$sheetTags
            .'</sheets></workbook>';

        return self::package([
            '[Content_Types].xml' => self::types('sheet'),
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>',
            'xl/sharedStrings.xml' => $shared,
            'xl/styles.xml' => $styles,
            ...$sheetFiles,
        ]);
    }

    public static function macroWord(): string
    {
        return self::package([
            '[Content_Types].xml' => str_replace('</Types>', '<Override PartName="/word/vbaProject.bin" ContentType="application/vnd.ms-office.vbaProject"/></Types>', self::types('word')),
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Macro</w:t></w:r></w:p></w:body></w:document>',
            'word/vbaProject.bin' => 'macro',
        ]);
    }

    public static function encryptedWord(): string
    {
        return self::package([
            '[Content_Types].xml' => self::types('word'),
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hidden</w:t></w:r></w:p></w:body></w:document>',
            'EncryptionInfo' => 'encrypted',
        ]);
    }

    public static function emptyWord(): string
    {
        return self::word(['']);
    }

    /**
     * @param  array<int, array<int, string|int|float|array<string, string>>>  $rows
     * @param  array<int, string>  $strings
     */
    private static function sheet(array $rows, array &$strings): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $rowNumber = 1;

        foreach ($rows as $row) {
            $xml .= '<row r="'.$rowNumber.'">';
            $column = 1;

            foreach ($row as $cell) {
                $reference = self::column($column).$rowNumber;
                $xml .= self::cell($reference, $cell, $strings);
                $column++;
            }

            $xml .= '</row>';
            $rowNumber++;
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  array<int, string>  $strings
     */
    private static function cell(string $reference, string|int|float|array $cell, array &$strings): string
    {
        if (is_array($cell) && isset($cell['formula'])) {
            $cached = $cell['value'] ?? '';

            return '<c r="'.$reference.'"><f>'.self::escape($cell['formula']).'</f>'.($cached === '' ? '' : '<v>'.self::escape($cached).'</v>').'</c>';
        }

        if (is_array($cell) && ($cell['date'] ?? false)) {
            return '<c r="'.$reference.'" s="1"><v>'.self::escape((string) $cell['value']).'</v></c>';
        }

        if (is_int($cell) || is_float($cell) || (is_string($cell) && is_numeric($cell))) {
            return '<c r="'.$reference.'"><v>'.self::escape((string) $cell).'</v></c>';
        }

        $text = is_array($cell) ? (string) ($cell['text'] ?? '') : $cell;
        $index = array_search($text, $strings, true);

        if ($index === false) {
            $strings[] = $text;
            $index = array_key_last($strings);
        }

        return '<c r="'.$reference.'" t="s"><v>'.$index.'</v></c>';
    }

    private static function column(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function types(string $kind): string
    {
        $main = $kind === 'sheet'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
        $part = $kind === 'sheet' ? '/xl/workbook.xml' : '/word/document.xml';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="'.$part.'" ContentType="'.$main.'"/></Types>';
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function package(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'office');

        if ($path === false) {
            throw new \RuntimeException('Could not create an Office fixture.');
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $files['[Content_Types].xml']);
        unset($files['[Content_Types].xml']);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
