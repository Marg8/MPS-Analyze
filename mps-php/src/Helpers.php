<?php

declare(strict_types=1);

namespace Mps;

/**
 * Helpers – utility functions ported from pq_logic.py and bom_analysis.py.
 */
class Helpers
{
    /** Default fallback capacities per line key. */
    public const DEFAULT_CAPS = [
        'HC8_H3H'  => 35000,
        'H4Z'      => 8250,
        'HB5'      => 32000,
        'H5C_HC9'  => 6100,
        'HC7'      => 19000,
        'H2J'      => 9500,
        'HC4_A9R'  => 35000,
        'HC0'      => 16000,
    ];

    public const DEFAULT_COL_QTY         = 'Qty';
    public const DEFAULT_COL_LINE        = 'MRP';
    public const DEFAULT_COL_REQ_DATE    = 'Requested date';
    public const DEFAULT_COL_COMMIT_DATE = 'Plan Request Date';
    public const DEFAULT_COL_STD_PACK    = 'Std Pack';
    public const DEFAULT_COL_PART        = 'Product code';
    public const DEFAULT_COL_HRS         = 'Std x Hr';
    public const DEFAULT_COL_COST        = 'Std Cost';
    public const DEFAULT_COL_MATERIAL    = 'Material';

    /**
     * Convert any value to float (0.0 on failure/null/empty).
     */
    public static function toNumber(mixed $x): float
    {
        if ($x === null) {
            return 0.0;
        }
        if (is_float($x)) {
            return is_nan($x) ? 0.0 : $x;
        }
        if (is_int($x)) {
            return (float) $x;
        }
        $s = trim(str_replace(',', '', (string) $x));
        if (in_array($s, ['', '-', 'None', 'nan', 'NaN', 'N/A'], true)) {
            return 0.0;
        }
        $f = filter_var($s, FILTER_VALIDATE_FLOAT);
        return $f !== false ? (float) $f : 0.0;
    }

    /**
     * Parse a date-like value to a DateTime (midnight UTC) or null.
     */
    public static function toDate(mixed $x): ?\DateTime
    {
        if ($x === null || $x === '') {
            return null;
        }
        if ($x instanceof \DateTime) {
            return (clone $x)->setTime(0, 0, 0);
        }
        if ($x instanceof \DateTimeImmutable) {
            return \DateTime::createFromImmutable($x)->setTime(0, 0, 0);
        }
        // PhpSpreadsheet stores dates as Excel serial floats — handled in ExcelReader;
        // if we still receive a raw serial here, convert it
        if (is_float($x) || is_int($x)) {
            $serial = (float) $x;
            if ($serial > 1 && $serial < 2958466) { // plausible date range
                $ts = ($serial - 25569) * 86400;
                if ($serial < 60) {
                    $ts += 86400;
                }
                $dt = new \DateTime('@' . (int) round($ts));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                return $dt->setTime(0, 0, 0);
            }
            return null;
        }
        $s = trim((string) $x);
        if ($s === '' || in_array(strtolower($s), ['none', 'nan', 'nat'], true)) {
            return null;
        }
        // Try a handful of common date formats
        foreach (['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'd-m-Y', 'm-d-Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $s);
            if ($dt !== false) {
                return $dt->setTime(0, 0, 0);
            }
        }
        // strtotime fallback
        $ts = strtotime($s);
        if ($ts !== false) {
            $dt = new \DateTime();
            $dt->setTimestamp($ts)->setTime(0, 0, 0);
            return $dt;
        }
        return null;
    }

    /**
     * Normalise MRP/Line codes to their canonical group keys.
     */
    public static function normalizeLine(mixed $x): ?string
    {
        if ($x === null) {
            return null;
        }
        $t = strtoupper(trim((string) $x));
        if ($t === '') {
            return null;
        }
        if (in_array($t, ['HC8', 'H3H'], true)) {
            return 'HC8_H3H';
        }
        if (in_array($t, ['H5C', 'HC9'], true)) {
            return 'H5C_HC9';
        }
        if (in_array($t, ['HC4', 'A9R'], true)) {
            return 'HC4_A9R';
        }
        return $t;
    }

    /**
     * Find the first matching column name from a list of candidates (case-insensitive).
     * $columns is an array of column-name strings.
     * Returns the original (not lowercased) column name, or null.
     */
    public static function findFirstCol(array $columns, array $candidates): ?string
    {
        $lower = [];
        foreach ($columns as $c) {
            $lower[strtolower(trim($c))] = $c;
        }
        foreach ($candidates as $cand) {
            $key = strtolower(trim($cand));
            if (isset($lower[$key])) {
                return $lower[$key];
            }
        }
        return null;
    }

    /**
     * Get the column names from an array-of-rows dataset.
     */
    public static function getColumns(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        return array_keys($rows[0]);
    }

    /**
     * Round order qty up to the nearest std-pack multiple.
     * Returns [planned_qty, excess_qty].
     *
     * @return array{float, float}
     */
    public static function roundUpToStdPack(float $orderQty, float $stdPack): array
    {
        if ($orderQty <= 0) {
            return [0.0, 0.0];
        }
        if ($stdPack <= 0) {
            return [$orderQty, 0.0];
        }
        $planned = (float) (ceil($orderQty / $stdPack) * $stdPack);
        $excess  = max(0.0, $planned - $orderQty);
        return [$planned, $excess];
    }

    /**
     * Add Year/Quarter/Month/MonthName/ISOWeek to each row based on a date column.
     */
    public static function addDateDimensions(array &$rows, string $dateCol): void
    {
        $monthNames = [
            1  => 'Jan', 2  => 'Feb', 3  => 'Mar', 4  => 'Apr',
            5  => 'May', 6  => 'Jun', 7  => 'Jul', 8  => 'Aug',
            9  => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];
        foreach ($rows as &$row) {
            $dt = $row[$dateCol] ?? null;
            if (!($dt instanceof \DateTime)) {
                $dt = self::toDate($dt);
            }
            if ($dt !== null) {
                $m  = (int) $dt->format('n');
                $q  = (int) ceil($m / 3);
                $row['Year']       = (int) $dt->format('Y');
                $row['Quarter']    = 'Q' . $q;
                $row['Month']      = $dt->format('Y-m');
                $row['Month Name'] = $monthNames[$m];
                $row['ISOWeek']    = (int) $dt->format('W');
            } else {
                $row['Year']       = null;
                $row['Quarter']    = null;
                $row['Month']      = null;
                $row['Month Name'] = null;
                $row['ISOWeek']    = null;
            }
        }
        unset($row);
    }

    /**
     * Safely convert any cell value (including DateTime) to a trimmed string.
     */
    public static function safeStr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTime || $v instanceof \DateTimeImmutable) {
            return $v->format('Y-m-d');
        }
        return trim((string) $v);
    }

    /**
     * Format a float for human display (comma thousands separator).
     */
    public static function fmt(float $v, int $decimals = 0): string
    {
        return number_format($v, $decimals);
    }

    /**
     * Escape a value for HTML table output.
     */
    public static function esc(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
