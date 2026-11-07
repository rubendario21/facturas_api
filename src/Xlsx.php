<?php

declare(strict_types=1);

namespace App;

/**
 * Genera un libro de Excel (.xlsx) sin dependencias: un .xlsx es un ZIP con
 * archivos XML. Solo lo necesario para exportar tablas.
 *
 * Hoja: ['name' => ..., 'columns' => [[título, tipo, ancho], ...], 'rows' => [[...], ...]]
 * Tipos: text, money, date (AAAA-MM-DD), number. Una celda puede indicar su propio
 * tipo con ['v' => valor, 't' => tipo]; el tipo 'heading' es un título en negrita.
 * Sin 'columns' la hoja no lleva cabecera con filtros.
 */
final class Xlsx
{
    private const STYLES = ['text' => 0, 'header' => 1, 'money' => 2, 'date' => 3, 'number' => 4, 'heading' => 5, 'title' => 6];

    public static function build(array $sheets, string $currency): string
    {
        $files = [
            '[Content_Types].xml' => self::contentTypes(count($sheets)),
            '_rels/.rels' => self::xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>'),
            'xl/workbook.xml' => self::workbook($sheets),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(count($sheets)),
            'xl/styles.xml' => self::styles($currency),
        ];
        foreach (array_values($sheets) as $n => $sheet) {
            $files['xl/worksheets/sheet' . ($n + 1) . '.xml'] = self::sheet($sheet);
        }
        return self::zip($files);
    }

    private static function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $body;
    }

    private static function contentTypes(int $count): string
    {
        $overrides = '';
        for ($n = 1; $n <= $count; $n++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return self::xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides . '</Types>');
    }

    private static function workbook(array $sheets): string
    {
        $items = '';
        foreach (array_values($sheets) as $n => $sheet) {
            $id = $n + 1;
            $items .= '<sheet name="' . self::escape(mb_substr($sheet['name'], 0, 31)) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }
        return self::xml('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $items . '</sheets></workbook>');
    }

    private static function workbookRels(int $count): string
    {
        $items = '';
        for ($n = 1; $n <= $count; $n++) {
            $items .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $items .= '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return self::xml('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $items . '</Relationships>');
    }

    private static function styles(string $currency): string
    {
        $symbol = str_replace('"', '', $currency);
        $money = self::escape(($symbol === '' ? '' : '"' . $symbol . '" ') . '#,##0.00');
        return self::xml('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="' . $money . '"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="#,##0.##"/></numFmts>'
            . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="15"/><color rgb="FF1C2463"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2E3A8C"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    }

    private static function sheet(array $sheet): string
    {
        $columns = $sheet['columns'] ?? [];
        $widths = $sheet['widths'] ?? array_column($columns, 2);
        $data = '';
        $rowNumber = 0;
        $lastColumn = 1;
        if ($columns !== []) {
            $rowNumber = 1;
            $cells = '';
            foreach ($columns as $n => $c) {
                $cells .= self::cell(self::column($n) . '1', $c[0], 'header');
            }
            $data .= '<row r="1">' . $cells . '</row>';
            $lastColumn = count($columns);
        }
        foreach ($sheet['rows'] as $row) {
            $rowNumber++;
            $cells = '';
            foreach (array_values($row) as $n => $value) {
                $type = $columns[$n][1] ?? 'text';
                if (is_array($value)) {
                    [$value, $type] = [$value['v'], $value['t']];
                }
                $cells .= self::cell(self::column($n) . $rowNumber, $value, $type);
            }
            $data .= '<row r="' . $rowNumber . '">' . $cells . '</row>';
            $lastColumn = max($lastColumn, count($row));
        }
        $cols = '';
        foreach ($widths as $n => $width) {
            $cols .= '<col min="' . ($n + 1) . '" max="' . ($n + 1) . '" width="' . (float) $width . '" customWidth="1"/>';
        }
        $view = $columns === [] ? '<sheetView workbookViewId="0"/>'
            : '<sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView>';
        $filter = $columns !== [] && $rowNumber > 1
            ? '<autoFilter ref="A1:' . self::column($lastColumn - 1) . $rowNumber . '"/>' : '';
        return self::xml('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews>' . $view . '</sheetViews>'
            . ($cols === '' ? '' : '<cols>' . $cols . '</cols>')
            . '<sheetData>' . $data . '</sheetData>' . $filter . '</worksheet>');
    }

    private static function cell(string $ref, mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $style = self::STYLES[$type] ?? 0;
        if ($type === 'date') {
            $days = (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable(substr((string) $value, 0, 10)))->days;
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $days . '</v></c>';
        }
        if (($type === 'money' || $type === 'number') && is_numeric($value)) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . (float) $value . '</v></c>';
        }
        // El texto va como tal: Excel nunca lo interpreta como una fórmula.
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape((string) $value) . '</t></is></c>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function column(int $n): string
    {
        $name = '';
        for ($n++; $n > 0; $n = (int) (($n - 1) / 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    /** Escapa para XML y quita los caracteres de control que XML no admite. */
    private static function escape(string $text): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Archivo ZIP con los nombres y contenidos indicados. */
    private static function zip(array $files): string
    {
        $body = '';
        $directory = '';
        $time = (int) ((date('G') << 11) | ((int) date('i') << 5) | ((int) date('s') >> 1));
        $date = (int) (((date('Y') - 1980) << 9) | ((int) date('n') << 5) | (int) date('j'));
        foreach ($files as $name => $content) {
            $packed = gzdeflate($content);
            $common = pack('vvvvvVVVvv', 20, 0x0800, 8, $time, $date, crc32($content), strlen($packed), strlen($content), strlen($name), 0);
            $offset = strlen($body);
            $body .= "PK\x03\x04" . $common . $name . $packed;
            $directory .= "PK\x01\x02" . pack('v', 20) . $common . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        }
        return $body . $directory . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }
}
