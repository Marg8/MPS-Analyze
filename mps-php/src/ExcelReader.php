<?php

declare(strict_types=1);

namespace Mps;

/**
 * ExcelReader – self-contained XLSX reader using only PHP built-ins.
 *
 * Requires ext-zip and ext-simplexml (both enabled by default in XAMPP / PHP 8+).
 * No Composer packages needed.
 *
 * Handles:
 *  - Shared strings table (xl/sharedStrings.xml)
 *  - Date serial detection via numFmt codes (xl/styles.xml)
 *  - All worksheets (xl/workbook.xml for sheet name→rId mapping)
 */
class ExcelReader
{
    private array $sharedStrings = [];
    private array $dateFormatIds = [];   // numFmtId values that are date/time formats
    private array $cellXfNumFmtIds = []; // cellXf index → numFmtId
    private array $sheetMeta = [];       // [name => rId, ...]
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->parse();
    }

    public static function fromBytes(string $bytes): self
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mps_xlsx_');
        file_put_contents($tmp, $bytes);
        return new self($tmp);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** Return all sheet names in workbook order. */
    public function sheetNames(): array
    {
        return array_keys($this->sheetMeta);
    }

    /**
     * Read a sheet and return an array of associative arrays (rows).
     *
     * @param int $headerRow 0-based row index that is the header (default 0).
     * @return array<int, array<string, mixed>>
     */
    public function readSheet(string $sheetName, int $headerRow = 0): array
    {
        $rId = $this->sheetMeta[$sheetName] ?? null;
        if ($rId === null) {
            return [];
        }
        $path = $this->resolveSheetPath($rId);
        return $this->parseWorksheet($path, $headerRow);
    }

    // ── Initialisation ────────────────────────────────────────────────────────

    private function parse(): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->filePath) !== true) {
            throw new \RuntimeException("Cannot open xlsx file: {$this->filePath}");
        }

        $this->parseSharedStrings($zip);
        $this->parseStyles($zip);
        $this->parseWorkbook($zip);

        $zip->close();
    }

    private function parseSharedStrings(\ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;
        }
        $dom = $this->loadXml($xml);
        foreach ($dom->si as $si) {
            // A <si> can be a plain <t> or rich-text <r><t>... elements
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string) $r->t;
            }
            if ($text === '' && isset($si->t)) {
                $text = (string) $si->t;
            }
            $this->sharedStrings[] = $text;
        }
    }

    private function parseStyles(\ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return;
        }
        $dom = $this->loadXml($xml);

        // Built-in date numFmtIds (14–22, 45–47, 164+ if custom date)
        $builtInDateIds = range(14, 22);
        $builtInDateIds = array_merge($builtInDateIds, range(45, 47));
        $dateIdSet = array_flip($builtInDateIds);

        // Custom numFmts
        if (isset($dom->numFmts)) {
            foreach ($dom->numFmts->numFmt as $nf) {
                $id   = (int) $nf['numFmtId'];
                $code = strtolower((string) $nf['formatCode']);
                if ($this->isDateFormatCode($code)) {
                    $dateIdSet[$id] = true;
                }
            }
        }
        $this->dateFormatIds = $dateIdSet;

        // cellXf list → numFmtId
        if (isset($dom->cellXfs)) {
            foreach ($dom->cellXfs->xf as $xf) {
                $this->cellXfNumFmtIds[] = (int) $xf['numFmtId'];
            }
        }
    }

    private function parseWorkbook(\ZipArchive $zip): void
    {
        $xml = $zip->getFromName('xl/workbook.xml');
        if ($xml === false) {
            return;
        }
        $dom = $this->loadXml($xml);

        // Build rId → target map from workbook.xml.rels
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $ridMap  = [];
        if ($relsXml !== false) {
            $relsDom = $this->loadXml($relsXml);
            foreach ($relsDom->Relationship as $rel) {
                $ridMap[(string) $rel['Id']] = (string) $rel['Target'];
            }
        }

        foreach ($dom->sheets->sheet as $sheet) {
            $name = (string) $sheet['name'];
            $rId  = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $this->sheetMeta[$name] = $ridMap[$rId] ?? "worksheets/sheet{$rId}.xml";
        }
    }

    private function resolveSheetPath(string $rIdTarget): string
    {
        // Target is relative to xl/, e.g. "worksheets/sheet1.xml"
        if (strpos($rIdTarget, '/') === 0) {
            return ltrim($rIdTarget, '/');
        }
        return 'xl/' . $rIdTarget;
    }

    // ── Worksheet parsing ──────────────────────────────────────────────────────

    private function parseWorksheet(string $path, int $headerRow): array
    {
        $zip = new \ZipArchive();
        $zip->open($this->filePath);
        $xml = $zip->getFromName($path);
        $zip->close();

        if ($xml === false) {
            return [];
        }

        $dom = $this->loadXml($xml);

        // Collect all rows as raw data
        $rawRows = [];
        $maxCols = 0;
        foreach ($dom->sheetData->row as $rowEl) {
            $rowIdx = (int) $rowEl['r'] - 1; // 0-based
            $cells  = [];
            foreach ($rowEl->c as $cell) {
                $ref    = (string) $cell['r'];
                $colIdx = $this->colLetterToIndex($ref);
                $type   = (string) $cell['t'];
                $style  = isset($cell['s']) ? (int) $cell['s'] : -1;
                $rawVal = isset($cell->v) ? (string) $cell->v : null;

                $value = $this->coerceCell($rawVal, $type, $style);
                $cells[$colIdx] = $value;
                $maxCols = max($maxCols, $colIdx + 1);
            }
            $rawRows[$rowIdx] = $cells;
        }

        if (empty($rawRows)) {
            return [];
        }

        // Build header row
        $headerCells = $rawRows[$headerRow] ?? [];
        $colNames = [];
        for ($ci = 0; $ci < $maxCols; $ci++) {
            $h = $headerCells[$ci] ?? null;
            $name = ($h !== null && trim((string) $h) !== '') ? trim((string) $h) : ('Col_' . $ci);
            // Deduplicate
            $base = $name;
            $cnt  = 2;
            while (in_array($name, $colNames, true)) {
                $name = $base . '_' . $cnt++;
            }
            $colNames[$ci] = $name;
        }

        // Build associative rows
        $result = [];
        $sortedIdxs = array_keys($rawRows);
        sort($sortedIdxs);

        foreach ($sortedIdxs as $ri) {
            if ($ri <= $headerRow) {
                continue;
            }
            $cells = $rawRows[$ri];

            // Skip completely empty rows
            $nonEmpty = array_filter($cells, static fn($v) => $v !== null && (
                $v instanceof \DateTime || trim((string) $v) !== ''
            ));
            if (empty($nonEmpty)) {
                continue;
            }

            $row = [];
            for ($ci = 0; $ci < $maxCols; $ci++) {
                $row[$colNames[$ci]] = $cells[$ci] ?? null;
            }
            $result[] = $row;
        }

        return $result;
    }

    // ── Cell value coercion ────────────────────────────────────────────────────

    private function coerceCell(?string $rawVal, string $type, int $styleIdx): mixed
    {
        if ($rawVal === null) {
            return null;
        }
        // Shared string
        if ($type === 's') {
            $idx = (int) $rawVal;
            return $this->sharedStrings[$idx] ?? $rawVal;
        }
        // Boolean
        if ($type === 'b') {
            return $rawVal === '1';
        }
        // Inline string
        if ($type === 'inlineStr') {
            return $rawVal;
        }
        // Formula – return cached value as string
        if ($type === 'str') {
            return $rawVal;
        }

        // Numeric – check if it's a date
        if (is_numeric($rawVal)) {
            $numVal = (float) $rawVal;
            if ($styleIdx >= 0 && isset($this->cellXfNumFmtIds[$styleIdx])) {
                $fmtId = $this->cellXfNumFmtIds[$styleIdx];
                if (isset($this->dateFormatIds[$fmtId])) {
                    return $this->excelSerialToDateTime($numVal);
                }
            }
            // Return as float or int
            return (floor($numVal) == $numVal && abs($numVal) < 2_000_000_000)
                ? (int) $numVal
                : $numVal;
        }

        return $rawVal;
    }

    // ── Excel date serial → DateTime ──────────────────────────────────────────

    private function excelSerialToDateTime(float $serial): \DateTime
    {
        // Excel epoch: 1900-01-00 (but treats 1900 as leap year, so adjust)
        $ts = ($serial - 25569) * 86400; // days since 1970-01-01
        // Fix Excel's false leap year 1900 bug
        if ($serial < 60) {
            $ts += 86400;
        }
        $dt = new \DateTime('@' . (int) round($ts));
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function colLetterToIndex(string $cellRef): int
    {
        // Extract letter part from cell reference like "AB12"
        preg_match('/^([A-Z]+)/', strtoupper($cellRef), $m);
        $letters = $m[1] ?? 'A';
        $idx = 0;
        foreach (str_split($letters) as $ch) {
            $idx = $idx * 26 + (ord($ch) - 64);
        }
        return $idx - 1; // 0-based
    }

    private function isDateFormatCode(string $code): bool
    {
        // Remove quoted strings and color/locale tokens
        $code = preg_replace('/"[^"]*"/', '', $code) ?? $code;
        $code = preg_replace('/\[[^\]]*\]/', '', $code) ?? $code;
        // Check for date/time pattern characters
        return (bool) preg_match('/[ymdh]/', $code);
    }

    private function loadXml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $dom  = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($dom === false) {
            throw new \RuntimeException('Failed to parse XML');
        }
        return $dom;
    }
}

