<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentException;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use ZipArchive;

/**
 * Reads text from .docx and .xlsx packages. Formulas are not calculated,
 * and macro projects are rejected.
 */
class OfficeTextExtractor
{
    private const WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const SHEET = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const OFFICE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /**
     * @return array{page_count: int, pages: array<int, string>, empty_pages: array<int, int>, unit: string, kind: string}
     */
    public function extract(string $path): array
    {
        if (! is_file($path) || ! class_exists(ZipArchive::class)) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        try {
            $this->assertSafePackage($zip);
            $types = $this->entry($zip, '[Content_Types].xml');
            $this->assertNoMacros($zip, $types);
            $kind = $this->kind($types);

            return $kind === 'xlsx' ? $this->workbook($zip) : $this->document($zip);
        } finally {
            $zip->close();
        }
    }

    private function assertSafePackage(ZipArchive $zip): void
    {
        $count = $zip->numFiles;

        if ($count < 1 || $count > 200) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $uncompressed = 0;
        $limit = (int) config('kimi.document_max_uncompressed_kilobytes') * 1024;

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? str_replace('\\', '/', (string) ($stat['name'] ?? '')) : '';

            if ($name === '' || str_contains($name, '../') || str_starts_with($name, '/')) {
                throw new DocumentException(__('chat.office_corrupted'));
            }

            if (in_array(strtolower(basename($name)), ['encryptioninfo', 'encryptedpackage'], true)) {
                throw new DocumentException(__('chat.office_encrypted'));
            }

            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
            $uncompressed += max(0, $size);

            if ($uncompressed > $limit) {
                throw new DocumentException(__('chat.office_too_large', [
                    'limit' => max(1, (int) round(((int) config('kimi.document_max_kilobytes')) / 1024)),
                ]));
            }
        }
    }

    private function assertNoMacros(ZipArchive $zip, string $types): void
    {
        if (stripos($types, 'vbaProject') !== false || stripos($types, 'macroEnabled') !== false) {
            throw new DocumentException(__('chat.office_macros'));
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = strtolower(str_replace('\\', '/', (string) (is_array($stat) ? ($stat['name'] ?? '') : '')));

            if (str_ends_with($name, 'vbaproject.bin')) {
                throw new DocumentException(__('chat.office_macros'));
            }
        }
    }

    private function kind(string $types): string
    {
        $word = str_contains($types, 'wordprocessingml.document.main+xml');
        $sheet = str_contains($types, 'spreadsheetml.sheet.main+xml');

        if ($word === $sheet) {
            throw new DocumentException(__('chat.office_invalid'));
        }

        return $sheet ? 'xlsx' : 'docx';
    }

    /**
     * @return array{page_count: int, pages: array<int, string>, empty_pages: array<int, int>, unit: string, kind: string}
     */
    private function document(ZipArchive $zip): array
    {
        $dom = $this->xml($this->entry($zip, 'word/document.xml'));
        $bodies = $dom->getElementsByTagNameNS(self::WORD, 'body');
        $body = $bodies->item(0);

        if (! $body instanceof DOMElement) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $sections = [];
        $title = '';
        $blocks = [];
        $flush = function () use (&$sections, &$title, &$blocks): void {
            $text = trim($title.($title !== '' && $blocks !== [] ? "\n\n" : '').implode("\n\n", $blocks));

            if ($this->hasLetter($text)) {
                $sections[] = $this->clean($text);
            }

            $title = '';
            $blocks = [];
        };

        foreach ($body->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'p' && $this->isHeading($child)) {
                if ($title !== '' || $blocks !== []) {
                    $flush();
                }

                $title = $this->paragraphText($child);

                continue;
            }

            $block = match ($child->localName) {
                'p' => $this->paragraphText($child),
                'tbl' => $this->tableText($child),
                default => '',
            };

            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        $flush();
        $keyed = [];

        foreach ($sections as $text) {
            $keyed[count($keyed) + 1] = $text;
        }

        $sections = $keyed;
        $limit = (int) config('kimi.document_max_pages');

        if (count($sections) > $limit) {
            throw new DocumentException(__('chat.office_too_many_sections', ['limit' => $limit]));
        }

        return $this->pages($sections, [], 'section', 'docx');
    }

    /**
     * @return array{page_count: int, pages: array<int, string>, empty_pages: array<int, int>, unit: string, kind: string}
     */
    private function workbook(ZipArchive $zip): array
    {
        $workbook = $this->xml($this->entry($zip, 'xl/workbook.xml'));
        $rels = $this->xml($this->entry($zip, 'xl/_rels/workbook.xml.rels'));
        $targets = $this->relationships($rels);
        $styles = $zip->locateName('xl/styles.xml') === false ? [] : $this->dateStyles($this->xml($this->entry($zip, 'xl/styles.xml')));
        $strings = $zip->locateName('xl/sharedStrings.xml') === false ? [] : $this->sharedStrings($this->xml($this->entry($zip, 'xl/sharedStrings.xml')));
        $sheets = [];

        foreach ($workbook->getElementsByTagNameNS(self::SHEET, 'sheet') as $sheet) {
            if (! $sheet instanceof DOMElement) {
                continue;
            }

            $sheets[] = $sheet;
        }

        $limit = (int) config('kimi.document_max_pages');

        if (count($sheets) > $limit) {
            throw new DocumentException(__('chat.office_too_many_sheets', ['limit' => $limit]));
        }

        $pages = [];
        $empty = [];
        $number = 0;

        foreach ($sheets as $sheet) {
            $number++;
            $name = $this->clean($sheet->getAttribute('name'));
            $id = $sheet->getAttributeNS(self::OFFICE_REL, 'id');

            if ($id === '') {
                $id = $sheet->getAttribute('r:id');
            }

            $target = $targets[$id] ?? null;

            if (! is_string($target)) {
                throw new DocumentException(__('chat.office_corrupted'));
            }

            $text = $this->sheetText($name !== '' ? $name : 'Sheet '.$number, $this->xml($this->entry($zip, 'xl/'.$target)), $strings, $styles);

            if (! $this->hasLetter($text)) {
                $empty[] = $number;

                continue;
            }

            $pages[$number] = $text;
        }

        return $this->pages($pages, $empty, 'sheet', 'xlsx');
    }

    /**
     * @param  array<int, string>  $strings
     * @param  array<int, array{date: bool, time: bool}>  $styles
     */
    private function sheetText(string $name, DOMDocument $sheet, array $strings, array $styles): string
    {
        $rowLimit = (int) config('kimi.document_max_rows');
        $columnLimit = (int) config('kimi.document_max_columns');
        $rows = [];
        $rowNodes = $sheet->getElementsByTagNameNS(self::SHEET, 'row');

        if ($rowNodes->length > $rowLimit) {
            throw new DocumentException(__('chat.office_too_many_rows', ['limit' => $rowLimit]));
        }

        foreach ($rowNodes as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cells = [];

            foreach ($row->getElementsByTagNameNS(self::SHEET, 'c') as $cell) {
                if (! $cell instanceof DOMElement || $this->hasAncestor($cell, 'c')) {
                    continue;
                }

                $column = $this->columnIndex($cell->getAttribute('r'));

                if ($column > $columnLimit) {
                    throw new DocumentException(__('chat.office_too_many_columns', ['limit' => $columnLimit]));
                }

                $value = $this->cellValue($cell, $strings, $styles);

                if ($value !== '') {
                    $cells[$column > 0 ? $column : count($cells) + 1] = $value;
                }
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $rows[] = implode(' | ', $cells);
        }

        if ($rows === []) {
            return '';
        }

        return $this->clean("Sheet: {$name}\n".implode("\n", $rows));
    }

    /**
     * @param  array<int, string>  $strings
     * @param  array<int, array{date: bool, time: bool}>  $styles
     */
    private function cellValue(DOMElement $cell, array $strings, array $styles): string
    {
        $type = $cell->getAttribute('t');
        $formula = '';
        $raw = '';

        foreach ($cell->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'f') {
                $formula = trim($child->textContent);
            }

            if ($child->localName === 'v') {
                $raw = trim($child->textContent);
            }

            if ($child->localName === 'is') {
                $raw = $this->descendantText($child, self::SHEET);
            }
        }

        if ($type === 's') {
            $index = is_numeric($raw) ? (int) $raw : -1;

            return $strings[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            return $this->clean($raw);
        }

        if ($type === 'b') {
            return $raw === '1' ? 'TRUE' : 'FALSE';
        }

        if ($raw === '') {
            return $formula === '' ? '' : 'formula: '.$this->clean($formula);
        }

        $style = $styles[(int) $cell->getAttribute('s')] ?? null;

        if (is_array($style) && $style['date'] && is_numeric($raw)) {
            return $this->excelDate((float) $raw, $style['time']);
        }

        return $this->clean($raw);
    }

    private function excelDate(float $serial, bool $timeOnly): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $date = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days));

        if (! $date instanceof DateTimeImmutable) {
            return (string) $serial;
        }

        if ($seconds !== 0) {
            $date = $date->modify(sprintf('%+d seconds', $seconds));
        }

        if (! $date instanceof DateTimeImmutable) {
            return (string) $serial;
        }

        if ($timeOnly || $days === 0) {
            return $date->format('H:i');
        }

        return $seconds === 0 ? $date->format('Y-m-d') : $date->format('Y-m-d H:i');
    }

    /**
     * @return array<int, array{date: bool, time: bool}>
     */
    private function dateStyles(DOMDocument $styles): array
    {
        $codes = [];

        foreach ($styles->getElementsByTagNameNS(self::SHEET, 'numFmt') as $format) {
            if ($format instanceof DOMElement && is_numeric($format->getAttribute('numFmtId'))) {
                $codes[(int) $format->getAttribute('numFmtId')] = strtolower($format->getAttribute('formatCode'));
            }
        }

        $result = [];

        foreach ($styles->getElementsByTagNameNS(self::SHEET, 'cellXfs') as $group) {
            if (! $group instanceof DOMElement) {
                continue;
            }

            foreach ($group->childNodes as $xf) {
                if (! $xf instanceof DOMElement || $xf->localName !== 'xf') {
                    continue;
                }

                $id = is_numeric($xf->getAttribute('numFmtId')) ? (int) $xf->getAttribute('numFmtId') : 0;
                $code = $codes[$id] ?? '';
                $date = in_array($id, self::DATE_FORMATS, true) || ($code !== '' && preg_match('/[dy]/', $code) === 1);
                $time = in_array($id, [18, 19, 20, 21, 45, 46, 47], true)
                    || ($code !== '' && $date && ! str_contains($code, 'd') && ! str_contains($code, 'y'));
                $result[] = ['date' => $date, 'time' => $time];
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(DOMDocument $strings): array
    {
        $values = [];

        foreach ($strings->getElementsByTagNameNS(self::SHEET, 'si') as $item) {
            if ($item instanceof DOMElement) {
                $values[] = $this->clean($this->descendantText($item, self::SHEET));
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function relationships(DOMDocument $rels): array
    {
        $targets = [];

        foreach ($rels->getElementsByTagNameNS(self::REL, 'Relationship') as $relationship) {
            if (! $relationship instanceof DOMElement) {
                continue;
            }

            $id = $relationship->getAttribute('Id');
            $target = ltrim(str_replace('\\', '/', $relationship->getAttribute('Target')), '/');

            if (str_starts_with($target, 'xl/')) {
                $target = substr($target, 3);
            }

            if ($id === '' || $target === '' || str_contains($target, '../') || preg_match('/^[a-z]+:/i', $target) === 1) {
                throw new DocumentException(__('chat.office_external'));
            }

            $targets[$id] = $target;
        }

        return $targets;
    }

    private function isHeading(DOMElement $paragraph): bool
    {
        foreach ($paragraph->getElementsByTagNameNS(self::WORD, 'pStyle') as $style) {
            if (! $style instanceof DOMElement) {
                continue;
            }

            $value = $style->getAttributeNS(self::WORD, 'val');
            $value = strtolower($value !== '' ? $value : $style->getAttribute('w:val'));

            return str_contains($value, 'heading') || $value === 'title';
        }

        return false;
    }

    private function paragraphText(DOMElement $paragraph): string
    {
        return $this->clean($this->descendantText($paragraph, self::WORD));
    }

    private function tableText(DOMElement $table): string
    {
        $lines = [];

        foreach ($table->childNodes as $row) {
            if (! $row instanceof DOMElement || $row->localName !== 'tr') {
                continue;
            }

            $cells = [];

            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                    continue;
                }

                $text = $this->clean($this->descendantText($cell, self::WORD));

                if ($text !== '') {
                    $cells[] = $text;
                }
            }

            if ($cells !== []) {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }

    private function descendantText(DOMElement $node, string $namespace): string
    {
        $text = '';

        foreach ($node->getElementsByTagNameNS($namespace, 't') as $part) {
            if ($part instanceof DOMNode && ! $this->hasAncestor($part, 'rPh')) {
                $text .= $part->textContent;
            }
        }

        return $text;
    }

    private function hasAncestor(DOMNode $node, string $name): bool
    {
        $parent = $node->parentNode;

        while ($parent instanceof DOMElement) {
            if ($parent->localName === $name) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    private function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/', strtoupper($reference), $match) !== 1) {
            return 0;
        }

        $index = 0;

        foreach (str_split($match[1]) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    /**
     * @param  array<int, string>  $parts
     * @param  array<int, int>  $empty
     * @return array{page_count: int, pages: array<int, string>, empty_pages: array<int, int>, unit: string, kind: string}
     */
    private function pages(array $parts, array $empty, string $unit, string $kind): array
    {
        $pages = [];
        $characters = 0;
        $characterLimit = (int) config('kimi.document_max_characters');

        foreach ($parts as $index => $text) {
            $number = is_int($index) && $index > 0 ? $index : count($pages) + count($empty) + 1;
            $characters += mb_strlen($text);

            if ($characters > $characterLimit) {
                throw new DocumentException(__('chat.office_too_much_text', ['limit' => $characterLimit]));
            }

            $pages[$number] = $text;
        }

        if ($pages === []) {
            throw new DocumentException(__('chat.office_empty'));
        }

        $count = count($pages) + count($empty);

        return [
            'page_count' => $count,
            'pages' => $pages,
            'empty_pages' => $empty,
            'unit' => $unit,
            'kind' => $kind,
        ];
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        if (! preg_match('#^[A-Za-z0-9_./\\[\\]-]+$#', $name) || str_contains($name, '..')) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $raw = $zip->getFromName($name);

        if (! is_string($raw) || $raw === '' || strlen($raw) > ((int) config('kimi.document_max_uncompressed_kilobytes') * 1024)) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        return $raw;
    }

    private function xml(string $raw): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        return $dom;
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f", "\0"], ["\n", "\n", "\n", ''], $text);
        $text = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u", '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function hasLetter(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1;
    }
}
