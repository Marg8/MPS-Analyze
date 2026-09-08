<?php

declare(strict_types=1);

namespace Mps;

/**
 * ExcelExporter – write formatted .xlsx files using only PHP built-ins.
 *
 * Uses ZipArchive + XML string templates; no Composer packages required.
 */
class ExcelExporter
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Export the scheduled result and summary to a multi-sheet xlsx.
     * Returns raw bytes of the xlsx file.
     *
     * @param array $resultRows   Full schedule rows.
     * @param array $summaryRows  Week-pivot summary rows.
     * @param array $weekList     Ordered list of production week labels.
     */
    public static function scheduledResultToXlsx(
        array $resultRows,
        array $summaryRows,
        array $weekList,
    ): string {
        $sheets = [
            'Scheduled' => self::rowsToSheet($resultRows),
            'Pivot'     => self::rowsToSheet($summaryRows),
        ];
        return self::buildXlsx($sheets);
    }

    /**
     * Export RM Coverage report to formatted xlsx.
     */
    public static function rmCoverageToXlsx(array $coverageRows, array $kpiRows, array $weeks): string
    {
        $infoColLabels = ['Component' => 'Part Number', 'Component_Desc' => 'Description',
                          'UOM' => 'UOM', 'Initial_Inventory' => 'Initial Inventory', 'Metric' => 'Metric'];
        $allHeaders = array_values($infoColLabels);
        foreach ($weeks as $wk) {
            $allHeaders[] = is_int($wk) ? "Wk {$wk}" : (string) $wk;
        }

        // Build rows for RM Coverage sheet with conditional styling hints
        $dataRows = [];
        foreach ($coverageRows as $row) {
            $outRow = [];
            foreach (array_keys($infoColLabels) as $col) {
                $outRow[] = $row[$col] ?? '';
            }
            foreach ($weeks as $wk) {
                $outRow[] = $row[$wk] ?? '';
            }
            $metric = $row['Metric'] ?? '';
            $dataRows[] = ['values' => $outRow, 'metric' => $metric];
        }

        $kpiHeaders = !empty($kpiRows) ? array_keys($kpiRows[0]) : [];
        $kpiData    = [];
        foreach ($kpiRows as $row) {
            $kpiData[] = array_values($row);
        }

        $sheets = [
            'RM Coverage' => self::buildStyledCoverageSheet($allHeaders, $dataRows),
            'KPI Summary' => self::buildSimpleSheet($kpiHeaders, $kpiData),
        ];
        return self::buildXlsx($sheets);
    }

    /**
     * Export Line Output Plan to formatted xlsx.
     */
    public static function lineOutputToXlsx(array $planRows, array $kpiRows, array $weeks): string
    {
        $infoColLabels = ['Line' => 'Line', 'Line_Name' => 'Line Name', 'Metric' => 'Metric'];
        $allHeaders = array_values($infoColLabels);
        foreach ($weeks as $wk) {
            $allHeaders[] = (string) $wk;
        }

        $dataRows = [];
        foreach ($planRows as $row) {
            $outRow = [];
            foreach (array_keys($infoColLabels) as $col) {
                $outRow[] = $row[$col] ?? '';
            }
            foreach ($weeks as $wk) {
                $outRow[] = $row[$wk] ?? 0;
            }
            $metric = $row['Metric'] ?? '';
            $dataRows[] = ['values' => $outRow, 'metric' => $metric];
        }

        $kpiHeaders = !empty($kpiRows) ? array_keys($kpiRows[0]) : [];
        $kpiData    = [];
        foreach ($kpiRows as $row) {
            $kpiData[] = array_values($row);
        }

        $sheets = [
            'Line Output Plan' => self::buildStyledOutputSheet($allHeaders, $dataRows),
            'Line KPI'         => self::buildSimpleSheet($kpiHeaders, $kpiData),
        ];
        return self::buildXlsx($sheets);
    }

    /**
     * Export the MA3 summary to xlsx.
     */
    public static function ma3ToXlsx(array $ma3): string
    {
        $sheets = [];
        foreach (['Pieces' => 'ScheduledQty', 'Hours' => 'Total Hours', 'Value' => 'Total Value'] as $label => $key) {
            $rows = $ma3[$label] ?? [];
            if (!empty($rows)) {
                $sheets["MA3_{$label}"] = self::rowsToSheet($rows);
            }
        }
        if (empty($sheets)) {
            $sheets['MA3'] = self::rowsToSheet([]);
        }
        return self::buildXlsx($sheets);
    }

    // ── Sheet builders ────────────────────────────────────────────────────────

    /** Convert array-of-assoc-arrays to a sheet-data structure. */
    private static function rowsToSheet(array $rows): array
    {
        if (empty($rows)) {
            return ['headers' => [], 'data' => [], 'type' => 'plain'];
        }
        $headers = array_keys($rows[0]);
        $data    = [];
        foreach ($rows as $row) {
            $data[] = array_values($row);
        }
        return ['headers' => $headers, 'data' => $data, 'type' => 'plain'];
    }

    private static function buildSimpleSheet(array $headers, array $data): array
    {
        return ['headers' => $headers, 'data' => $data, 'type' => 'plain'];
    }

    private static function buildStyledCoverageSheet(array $headers, array $dataRows): array
    {
        return ['headers' => $headers, 'data' => $dataRows, 'type' => 'coverage'];
    }

    private static function buildStyledOutputSheet(array $headers, array $dataRows): array
    {
        return ['headers' => $headers, 'data' => $dataRows, 'type' => 'output'];
    }

    // ── XLSX builder ──────────────────────────────────────────────────────────

    /**
     * Build an xlsx file from multiple sheet definitions.
     * Returns the raw bytes.
     */
    private static function buildXlsx(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mps_export_') . '.xlsx';

        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        // ── [Content_Types].xml ───────────────────────────────────────────────
        $sheetCount = count($sheets);
        $contentOverrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $contentOverrides .= <<<XML
  <Override PartName="/xl/worksheets/sheet{$i}.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>

XML;
        }
        $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
{$contentOverrides}</Types>
XML);

        // ── _rels/.rels ──────────────────────────────────────────────────────
        $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);

        // ── xl/styles.xml ─────────────────────────────────────────────────────
        $zip->addFromString('xl/styles.xml', self::buildStyles());

        // ── xl/workbook.xml & rels ────────────────────────────────────────────
        $sheetEls  = '';
        $relEls    = '';
        $i         = 1;
        foreach (array_keys($sheets) as $name) {
            $safeName = htmlspecialchars($name, ENT_XML1);
            $sheetEls .= "  <sheet name=\"{$safeName}\" sheetId=\"{$i}\" r:id=\"rId{$i}\"/>\n";
            $relEls   .= "  <Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>\n";
            $i++;
        }

        $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
{$sheetEls}  </sheets>
</workbook>
XML);

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
{$relEls}</Relationships>
XML);

        // ── xl/worksheets/sheet{i}.xml ────────────────────────────────────────
        $i = 1;
        foreach ($sheets as $sheetDef) {
            $wsXml = self::buildWorksheetXml($sheetDef);
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $wsXml);
            $i++;
        }

        $zip->close();
        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    // ── Styles ────────────────────────────────────────────────────────────────

    private static function buildStyles(): string
    {
        // Fonts: 0=normal, 1=bold-white (header), 2=bold-green, 3=bold-red
        // Fills: 0=none, 1=gray(default), 2=dark-green(hdr), 3=light-green, 4=light-red,
        //        5=light-blue(grp), 6=receipts-green, 7=zero-gray
        // Borders: 0=none, 1=bottom-medium
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="4">
    <font><sz val="10"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FF1B5E20"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><color rgb="FFB71C1C"/><name val="Calibri"/></font>
  </fonts>
  <fills count="8">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1B5E20"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFC8E6C9"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFCDD2"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDDEEFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF5F5F5"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left/><right/><top/><bottom style="medium"><color rgb="FF9E9E9E"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="9">
    <xf numFmtId="0"    fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0"    fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="3"    fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
    <xf numFmtId="3"    fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="3"    fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0"    fontId="0" fillId="5" borderId="0" xfId="0" applyFill="1"/>
    <xf numFmtId="3"    fontId="0" fillId="6" borderId="0" xfId="0" applyFill="1"/>
    <xf numFmtId="3"    fontId="0" fillId="7" borderId="0" xfId="0" applyFill="1"/>
    <xf numFmtId="3"    fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
  </cellXfs>
</styleSheet>
XML;
    }

    // ── Worksheet XML ─────────────────────────────────────────────────────────

    private static function buildWorksheetXml(array $sheetDef): string
    {
        $headers = $sheetDef['headers'] ?? [];
        $data    = $sheetDef['data']    ?? [];
        $type    = $sheetDef['type']    ?? 'plain';

        $rows = '';
        $ri   = 1;

        if (!empty($headers)) {
            $rows .= self::buildHeaderRow($ri, $headers);
            $ri++;
        }

        foreach ($data as $rowData) {
            $values = is_array($rowData) && isset($rowData['values']) ? $rowData['values'] : $rowData;
            $metric = is_array($rowData) && isset($rowData['metric']) ? $rowData['metric'] : '';

            $styleId = 0;
            if ($type === 'coverage') {
                $styleId = match ($metric) {
                    'Receipts'       => 6,
                    'Ending Balance' => 0, // handled per cell
                    default          => 0,
                };
            } elseif ($type === 'output') {
                $styleId = match ($metric) {
                    'FG Output' => 8,
                    'Shortage'  => 3,
                    'Planned'   => 4,
                    default     => 0,
                };
            }

            $rows .= self::buildDataRow($ri, $values, $styleId, $type, $metric);
            $ri++;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
{$rows}  </sheetData>
</worksheet>
XML;
    }

    private static function buildHeaderRow(int $ri, array $headers): string
    {
        $cells = '';
        foreach ($headers as $ci => $header) {
            $col    = self::colIndex($ci);
            $ref    = $col . $ri;
            $v      = self::xmlVal((string) $header);
            $cells .= "      <c r=\"{$ref}\" t=\"inlineStr\" s=\"1\"><is><t>{$v}</t></is></c>\n";
        }
        return "    <row r=\"{$ri}\">\n{$cells}    </row>\n";
    }

    private static function buildDataRow(int $ri, array $values, int $styleId, string $type, string $metric): string
    {
        $cells = '';
        foreach ($values as $ci => $val) {
            $col = self::colIndex($ci);
            $ref = $col . $ri;

            // For coverage Ending Balance, colour per value
            $s = $styleId;
            if ($type === 'coverage' && $metric === 'Ending Balance' && is_numeric($val) && $ci > 4) {
                $s = match (true) {
                    (float) $val < 0 => 3,   // red
                    (float) $val > 0 => 2,   // green
                    default          => 7,   // gray
                };
            }

            $cells .= self::buildCell($ref, $val, $s);
        }
        return "    <row r=\"{$ri}\">\n{$cells}    </row>\n";
    }

    private static function buildCell(string $ref, mixed $val, int $styleId): string
    {
        $s = $styleId > 0 ? " s=\"{$styleId}\"" : '';
        if ($val === null || $val === '') {
            return "      <c r=\"{$ref}\"{$s}/>\n";
        }
        if (is_bool($val)) {
            $v = $val ? '1' : '0';
            return "      <c r=\"{$ref}\" t=\"b\"{$s}><v>{$v}</v></c>\n";
        }
        if (is_int($val) || is_float($val)) {
            $v = is_float($val) ? rtrim(rtrim(sprintf('%.10f', $val), '0'), '.') : (string) $val;
            return "      <c r=\"{$ref}\"{$s}><v>{$v}</v></c>\n";
        }
        if ($val instanceof \DateTime) {
            // Format as text
            $v = self::xmlVal($val->format('Y-m-d'));
            return "      <c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t>{$v}</t></is></c>\n";
        }
        // String
        $v = self::xmlVal((string) $val);
        return "      <c r=\"{$ref}\" t=\"inlineStr\"{$s}><is><t>{$v}</t></is></c>\n";
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private static function colIndex(int $idx): string
    {
        $idx++; // 1-based
        $col = '';
        while ($idx > 0) {
            $rem = ($idx - 1) % 26;
            $col = chr(65 + $rem) . $col;
            $idx = (int) (($idx - 1) / 26);
        }
        return $col;
    }

    private static function xmlVal(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1, 'UTF-8');
    }
}
