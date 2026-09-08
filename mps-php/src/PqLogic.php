<?php

declare(strict_types=1);

namespace Mps;

/**
 * PqLogic – PHP port of pq_logic.py.
 *
 * All functions work with PHP arrays-of-associative-arrays instead of pandas DataFrames.
 */
class PqLogic
{
    // ── Master-table lookups ─────────────────────────────────────────────────

    /**
     * Build {Material → HrsPerUnit} from the Hrs sheet.
     * Sums all routing operations per part.
     */
    public static function buildHrsLookup(
        array $hrsRows,
        string $materialCol = Helpers::DEFAULT_COL_MATERIAL,
        string $hrsCol = Helpers::DEFAULT_COL_HRS,
    ): array {
        if (empty($hrsRows)) {
            return [];
        }
        $cols  = Helpers::getColumns($hrsRows);
        $mCol  = Helpers::findFirstCol($cols, [$materialCol, 'Material']);
        $hCol  = Helpers::findFirstCol($cols, [$hrsCol, 'Std x Hr', 'Std_x_Hr', 'StdxHr']);
        if ($mCol === null || $hCol === null) {
            return [];
        }
        $lookup = [];
        foreach ($hrsRows as $row) {
            $mat = Helpers::safeStr($row[$mCol] ?? null);
            $hrs = Helpers::toNumber($row[$hCol] ?? null);
            if ($mat === '' || $hrs <= 0) {
                continue;
            }
            $lookup[$mat] = ($lookup[$mat] ?? 0.0) + $hrs;
        }
        return $lookup;
    }

    /**
     * Build {PartNumber → StdCost} using the most recent non-zero cost.
     */
    public static function buildCostLookup(
        array $costRows,
        string $pnCol = 'PN',
        string $costCol = Helpers::DEFAULT_COL_COST,
        string $dateCol = 'Last update',
    ): array {
        if (empty($costRows)) {
            return [];
        }
        $cols = Helpers::getColumns($costRows);
        $pCol = Helpers::findFirstCol($cols, [$pnCol, Helpers::DEFAULT_COL_MATERIAL, 'Material', 'Part']);
        $cCol = Helpers::findFirstCol($cols, [$costCol, 'Std Cost', 'StdCost', 'Cost']);
        $dCol = Helpers::findFirstCol($cols, [$dateCol, 'Last update', 'Update Date', 'Date']);
        if ($pCol === null || $cCol === null) {
            return [];
        }

        // Collect all valid rows
        $valid = [];
        foreach ($costRows as $row) {
            $pn   = Helpers::safeStr($row[$pCol] ?? null);
            $cost = Helpers::toNumber($row[$cCol] ?? null);
            if ($pn === '' || $cost <= 0) {
                continue;
            }
            $dt   = ($dCol !== null) ? Helpers::toDate($row[$dCol] ?? null) : null;
            $ts   = $dt ? $dt->getTimestamp() : 0;
            $valid[] = ['pn' => $pn, 'cost' => $cost, 'ts' => $ts];
        }

        // Sort descending by date so first-seen per PN = most recent
        usort($valid, static fn($a, $b) => $b['ts'] <=> $a['ts']);

        $lookup = [];
        foreach ($valid as $item) {
            if (!isset($lookup[$item['pn']])) {
                $lookup[$item['pn']] = $item['cost'];
            }
        }
        return $lookup;
    }

    /**
     * Build {Material → DeliveryUnit} from the Std Pack master sheet.
     */
    public static function buildStdPackLookup(
        array $spRows,
        string $materialCol = Helpers::DEFAULT_COL_MATERIAL,
        string $packCol = 'Delivery unit',
    ): array {
        if (empty($spRows)) {
            return [];
        }
        $cols = Helpers::getColumns($spRows);
        $mCol = Helpers::findFirstCol($cols, [$materialCol, 'Material']);
        $pCol = Helpers::findFirstCol($cols, [
            $packCol, 'Delivery unit', 'Min. MtO quantity', 'Minimum delivery qty',
        ]);
        if ($mCol === null || $pCol === null) {
            return [];
        }
        $lookup = [];
        foreach ($spRows as $row) {
            $mat  = Helpers::safeStr($row[$mCol] ?? null);
            $pack = Helpers::toNumber($row[$pCol] ?? null);
            if ($mat === '' || isset($lookup[$mat])) {
                continue;
            }
            $lookup[$mat] = $pack;
        }
        return $lookup;
    }

    // ── Capacity parsing ──────────────────────────────────────────────────────

    /**
     * Build long-form capacity rows from a wide Line × Week table.
     *
     * Input columns: Line | 6/8/2026 | 6/15/2026 | ...
     * Returns sorted rows: [_Line, _LineName, _WeekDate, _WkNum, _Cap]
     */
    public static function buildCapFlat(
        array $capRows,
        ?string $preferredLineCol = null,
        ?array $lineColCandidates = null,
        ?array $validLines = null,
    ): array {
        if (empty($capRows)) {
            return [];
        }

        $cols = Helpers::getColumns($capRows);
        $baseCandidates = $lineColCandidates ?? [
            'Line Group', 'LineGroup', 'Line Grp', 'Line', 'Production Line',
        ];
        $lineCol = self::chooseCapacityLineColumn($cols, $preferredLineCol, $baseCandidates, $validLines);

        // Separate date columns from the line column
        $dateCols = [];
        foreach ($cols as $c) {
            if ($c === $lineCol) {
                continue;
            }
            $dt = Helpers::toDate($c);
            if ($dt !== null) {
                $dateCols[$c] = $dt;
            }
        }

        // Find a "Line Name" column
        $lineNameCol = Helpers::findFirstCol($cols, [
            'Line', 'Line Name', 'Line Description', 'Description',
        ]);

        $rows = [];
        foreach ($capRows as $row) {
            $lineKey = Helpers::normalizeLine($row[$lineCol] ?? null);
            if ($lineKey === null) {
                continue;
            }
            $lineName = trim((string) ($row[$lineNameCol] ?? $lineKey));
            if ($lineName === '') {
                $lineName = $lineKey;
            }

            foreach ($dateCols as $dc => $weekDate) {
                $cap = Helpers::toNumber($row[$dc] ?? null);
                if ($cap <= 0) {
                    continue;
                }
                $rows[] = [
                    '_Line'     => $lineKey,
                    '_LineName' => $lineName,
                    '_WeekDate' => $weekDate,
                    '_WkNum'    => (int) $weekDate->format('W'),
                    '_Cap'      => $cap,
                ];
            }
        }

        usort($rows, static fn($a, $b) => [
            $a['_Line'], $a['_WeekDate']->getTimestamp(),
        ] <=> [
            $b['_Line'], $b['_WeekDate']->getTimestamp(),
        ]);

        return $rows;
    }

    /**
     * Choose the best line column from available columns.
     */
    private static function chooseCapacityLineColumn(
        array $cols,
        ?string $preferred,
        array $baseCandidates,
        ?array $validLines,
    ): string {
        $lower = [];
        foreach ($cols as $c) {
            $lower[strtolower(trim($c))] = $c;
        }
        $candidates = [];
        if ($preferred !== null) {
            $candidates[] = $preferred;
        }
        foreach ($baseCandidates as $b) {
            $candidates[] = $b;
        }
        $existing = [];
        foreach ($candidates as $cand) {
            $key = strtolower(trim($cand));
            if (isset($lower[$key]) && !in_array($lower[$key], $existing, true)) {
                $existing[] = $lower[$key];
            }
        }
        if (empty($existing)) {
            return $cols[0];
        }
        if (empty($validLines)) {
            return $existing[0];
        }
        return $existing[0];
    }

    /**
     * Apply capacity overrides (Line × Week → New Capacity).
     */
    public static function applyCapacityOverrides(array $capFlat, array $overrideRows): array
    {
        $base = $capFlat;
        if (empty($overrideRows)) {
            return $base;
        }
        $cols = Helpers::getColumns($overrideRows);
        $lineCol = Helpers::findFirstCol($cols, ['Line']);
        $weekCol = Helpers::findFirstCol($cols, ['Week', 'Date']);
        $capCol  = Helpers::findFirstCol($cols, ['New Capacity', 'Capacity', 'Override Capacity']);
        if ($lineCol === null || $weekCol === null || $capCol === null) {
            return $base;
        }

        // Build index: (line, weekDate_ts) → array index
        $index = [];
        foreach ($base as $i => $r) {
            $ts = $r['_WeekDate']->getTimestamp();
            $index[$r['_Line'] . '|' . $ts] = $i;
        }

        foreach ($overrideRows as $row) {
            $lineKey  = Helpers::normalizeLine($row[$lineCol] ?? null);
            $weekDate = Helpers::toDate($row[$weekCol] ?? null);
            $newCap   = Helpers::toNumber($row[$capCol] ?? null);
            if ($lineKey === null || $weekDate === null) {
                continue;
            }
            $key = $lineKey . '|' . $weekDate->getTimestamp();
            if (isset($index[$key])) {
                $base[$index[$key]]['_Cap'] = max(0.0, $newCap);
            } elseif ($newCap > 0) {
                $base[] = [
                    '_Line'     => $lineKey,
                    '_LineName' => $lineKey,
                    '_WeekDate' => $weekDate,
                    '_WkNum'    => (int) $weekDate->format('W'),
                    '_Cap'      => $newCap,
                ];
            }
        }

        $base = array_values(array_filter($base, static fn($r) => $r['_Cap'] > 0));
        usort($base, static fn($a, $b) => [
            $a['_Line'], $a['_WeekDate']->getTimestamp(),
        ] <=> [
            $b['_Line'], $b['_WeekDate']->getTimestamp(),
        ]);

        return $base;
    }

    // ── Scheduling core ───────────────────────────────────────────────────────

    /**
     * Get capacity buckets for a given line.
     */
    private static function getBuckets(string $lineKey, array $capFlat, int $baseWeek): array
    {
        $rows = array_values(array_filter($capFlat, static fn($r) => $r['_Line'] === $lineKey));
        if (!empty($rows)) {
            usort($rows, static fn($a, $b) => $a['_WeekDate']->getTimestamp() <=> $b['_WeekDate']->getTimestamp());
            return array_map(static fn($r) => [
                'Week'     => $r['_WkNum'],
                'WeekDate' => $r['_WeekDate'],
                'Cap'      => $r['_Cap'],
                'LineName' => $r['_LineName'] ?? $lineKey,
            ], $rows);
        }

        // Fallback capacity
        $fallback = Helpers::DEFAULT_CAPS[$lineKey] ?? null;
        if ($fallback === null && !empty($capFlat)) {
            $caps = array_column($capFlat, '_Cap');
            sort($caps);
            $mid = (int) floor(count($caps) / 2);
            $fallback = $caps[$mid];
        }
        $fallback = (float) max(1.0, (float) ($fallback ?? 1.0));

        return [['Week' => $baseWeek, 'WeekDate' => null, 'Cap' => $fallback, 'LineName' => $lineKey]];
    }

    /**
     * Find which bucket index a cumulative position falls into.
     */
    private static function findIdx(float $cumPos, array $cumCapEnds): int
    {
        foreach ($cumCapEnds as $i => $end) {
            if ($end > $cumPos) {
                return $i;
            }
        }
        return count($cumCapEnds) - 1;
    }

    /**
     * Schedule a group of orders (same line) against capacity buckets.
     */
    private static function processGroup(array $group, string $lineKey, array $capFlat, int $baseWeek): array
    {
        $qtyList = array_map(static fn($r) => Helpers::toNumber($r['QtyPlannedInput'] ?? null), $group);

        $rawBuckets = self::getBuckets($lineKey, $capFlat, $baseWeek);
        $totalQty   = array_sum($qtyList);
        $lastBucket = end($rawBuckets);
        $lastCap    = $lastBucket['Cap'];
        $lastWeek   = $lastBucket['Week'];
        $lastWkDate = $lastBucket['WeekDate'];
        $rawSum     = array_sum(array_column($rawBuckets, 'Cap'));

        $deficit = max(0, $totalQty - $rawSum);
        $extraN  = ($lastCap > 0) ? (int) ceil($deficit / $lastCap) : 0;

        $extBuckets = $rawBuckets;
        for ($n = 1; $n <= $extraN; $n++) {
            $extWkDate = null;
            if ($lastWkDate !== null) {
                $extWkDate = (clone $lastWkDate)->modify('+' . ($n * 7) . ' days');
            }
            $extBuckets[] = [
                'Week'     => $lastWeek + $n,
                'WeekDate' => $extWkDate,
                'Cap'      => $lastCap,
                'LineName' => $lastBucket['LineName'] ?? $lineKey,
            ];
        }

        // Cumulative capacity ends
        $cumCapEnds = [];
        $running    = 0.0;
        foreach ($extBuckets as $b) {
            $running    += $b['Cap'];
            $cumCapEnds[] = $running;
        }

        $outputRows = [];
        foreach ($group as $idx => $row) {
            $qty  = $qtyList[$idx];
            $cumS = (float) array_sum(array_slice($qtyList, 0, $idx));
            $cumE = $cumS + $qty;

            $iS = self::findIdx($cumS, $cumCapEnds);
            $iE = self::findIdx(max(0, $cumE - 1), $cumCapEnds);

            for ($i = $iS; $i <= $iE; $i++) {
                $bucketStart    = ($i === 0) ? 0.0 : $cumCapEnds[$i - 1];
                $bucketEnd      = $cumCapEnds[$i];
                $scheduledQty   = max(0.0, min($bucketEnd, $cumE) - max($bucketStart, $cumS));

                $newRow = $row;
                $newRow['Line']             = $lineKey;
                $newRow['Line Name']        = $extBuckets[$i]['LineName'] ?? $lineKey;
                $newRow['ProductionWeek']   = $extBuckets[$i]['Week'];
                $newRow['ProductionWeekDate'] = $extBuckets[$i]['WeekDate'];
                $newRow['ScheduledQtyBase'] = $scheduledQty;
                $newRow['SplitFlag']        = ($iS !== $iE) ? 'SPLIT' : '';
                $newRow['Excess Std Pack']  = ($i === $iS) ? ($row['Excess Std Pack'] ?? 0.0) : 0.0;
                $outputRows[] = $newRow;
            }
        }

        return $outputRows;
    }

    // ── Full run_query pipeline ───────────────────────────────────────────────

    /**
     * Main scheduling pipeline – port of run_query() in pq_logic.py.
     *
     * @param array $dataRows        Orders (Entry Open Orders sheet).
     * @param array $capRows         Capacity table rows.
     * @param array $overrideRows    Capacity override rows (may be empty).
     * @param int   $baseWeek        ISO base week for fallback capacity.
     * @param array $hrsRows         Hrs master rows.
     * @param array $costRows        Cost master rows.
     * @param array $spRows          Std Pack master rows.
     * @param string $colQty         Column name for order qty.
     * @param string $colLine        Column name for MRP/Line code.
     * @param string $colReqDate     Column name for requested date.
     * @param string $colCommitDate  Column name for commit/plan date.
     * @param string $colStdPack     Column name for std pack in orders.
     * @param string $capacityLineCol Column in capacity table that holds line keys.
     * @param string $colPart        Column name for part number.
     * @return array  Result rows with scheduling output.
     */
    public static function runQuery(
        array  $dataRows,
        array  $capRows        = [],
        array  $overrideRows   = [],
        int    $baseWeek       = 24,
        array  $hrsRows        = [],
        array  $costRows       = [],
        array  $spRows         = [],
        string $colQty         = Helpers::DEFAULT_COL_QTY,
        string $colLine        = Helpers::DEFAULT_COL_LINE,
        string $colReqDate     = Helpers::DEFAULT_COL_REQ_DATE,
        string $colCommitDate  = Helpers::DEFAULT_COL_COMMIT_DATE,
        string $colStdPack     = Helpers::DEFAULT_COL_STD_PACK,
        string $colPart        = Helpers::DEFAULT_COL_PART,
        ?string $capacityLineCol = null,
    ): array {
        if (empty($dataRows)) {
            return [];
        }

        // ── Build master lookups ─────────────────────────────────────────────
        $hrsLookup  = self::buildHrsLookup($hrsRows);
        $costLookup = self::buildCostLookup($costRows);
        $spLookup   = self::buildStdPackLookup($spRows);

        // ── Enrich data rows ─────────────────────────────────────────────────
        $df = [];
        foreach ($dataRows as $row) {
            $qtyNum = Helpers::toNumber($row[$colQty] ?? null);
            $line   = Helpers::normalizeLine($row[$colLine] ?? null);
            $pn     = Helpers::safeStr($row[$colPart] ?? null);

            $hrsPerUnit = $hrsLookup[$pn] ?? 0.0;
            $stdCost    = $costLookup[$pn] ?? 0.0;

            // Std Pack: prefer master, fall back to order column
            $spMaster = $spLookup[$pn] ?? null;
            $spOrder  = Helpers::toNumber($row[$colStdPack] ?? null);
            $stdPackNum = ($spMaster !== null && $spMaster > 0) ? $spMaster : $spOrder;

            [$qtyPlanned, $excess] = Helpers::roundUpToStdPack($qtyNum, $stdPackNum);

            // Planning date: commit date first, then req date
            $commitVal = $row[$colCommitDate] ?? null;
            $reqVal    = $row[$colReqDate]    ?? null;
            $planDate  = Helpers::toDate(($commitVal !== null && $commitVal !== '') ? $commitVal : $reqVal);

            $newRow = $row;
            $newRow['QtyNum']           = $qtyNum;
            $newRow['Line']             = $line;
            $newRow['HrsPerUnit']       = $hrsPerUnit;
            $newRow['Std Cost']         = $stdCost;
            $newRow['Std Pack (Master)'] = $stdPackNum;
            $newRow['QtyPlannedInput']  = $qtyPlanned;
            $newRow['Excess Std Pack']  = $excess;
            $newRow['PlanningDate']     = $planDate;
            $df[] = $newRow;
        }

        // ── Sort by Line, PlanningDate ───────────────────────────────────────
        usort($df, static function ($a, $b) {
            $lc = strcmp((string) ($a['Line'] ?? ''), (string) ($b['Line'] ?? ''));
            if ($lc !== 0) {
                return $lc;
            }
            $da = $a['PlanningDate'];
            $db = $b['PlanningDate'];
            if ($da === null && $db === null) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }
            return $da->getTimestamp() <=> $db->getTimestamp();
        });

        // ── Build capacity ───────────────────────────────────────────────────
        $validLines = array_unique(array_filter(
            array_column($df, 'Line'),
            static fn($v) => $v !== null,
        ));

        $baseCapFlat = self::buildCapFlat($capRows, $capacityLineCol, null, $validLines);
        $capFlat     = self::applyCapacityOverrides($baseCapFlat, $overrideRows);

        // ── Schedule by line ─────────────────────────────────────────────────
        $grouped = [];
        foreach ($df as $row) {
            $key = $row['Line'] ?? '__UNKNOWN__';
            $grouped[$key][] = $row;
        }

        $result = [];
        foreach ($grouped as $lineKey => $group) {
            $scheduled = self::processGroup($group, (string) $lineKey, $capFlat, $baseWeek);
            foreach ($scheduled as $r) {
                $result[] = $r;
            }
        }

        if (empty($result)) {
            return [];
        }

        // ── Finalise metrics ─────────────────────────────────────────────────
        foreach ($result as &$row) {
            $base         = Helpers::toNumber($row['ScheduledQtyBase'] ?? null);
            $scheduledQty = (int) floor($base);
            $hpu  = (float) ($row['HrsPerUnit'] ?? 0.0);
            $cost = (float) ($row['Std Cost']   ?? 0.0);

            $row['ScheduledQty'] = $scheduledQty;
            $row['Total Hours']  = $scheduledQty * $hpu;
            $row['Total Value']  = $scheduledQty * $cost;
        }
        unset($row);

        // ── Date dimensions ──────────────────────────────────────────────────
        Helpers::addDateDimensions($result, 'ProductionWeekDate');

        return $result;
    }

    // ── MA3 Summary ───────────────────────────────────────────────────────────

    /**
     * Build MA3 summary: pivot by Line × Month for Pieces, Hours, Value.
     *
     * Returns ['Pieces' => rows, 'Hours' => rows, 'Value' => rows]
     * where each rows array is a pivot with Line/Line Name + month columns + Total.
     */
    public static function buildMa3Summary(array $result): array
    {
        if (empty($result)) {
            return ['Pieces' => [], 'Hours' => [], 'Value' => []];
        }

        $monthOrder = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                       'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $makeMonthPivot = static function (string $metric) use ($result, $monthOrder): array {
            $agg = []; // [line => [month => value]]
            $lineNames = [];
            foreach ($result as $row) {
                $line = (string) ($row['Line'] ?? '');
                if ($line === '') {
                    continue;
                }
                $month = (string) ($row['Month Name'] ?? '');
                $val   = Helpers::toNumber($row[$metric] ?? null);
                $lineNames[$line] = (string) ($row['Line Name'] ?? $line);
                $agg[$line][$month] = ($agg[$line][$month] ?? 0.0) + $val;
            }

            // Get present months in calendar order
            $presentMonths = [];
            foreach ($monthOrder as $m) {
                foreach ($agg as $months) {
                    if (isset($months[$m])) {
                        $presentMonths[] = $m;
                        break;
                    }
                }
            }
            $presentMonths = array_unique($presentMonths);

            $rows = [];
            foreach ($agg as $line => $months) {
                $row = ['Line' => $line, 'Line Name' => $lineNames[$line] ?? $line];
                $total = 0.0;
                foreach ($presentMonths as $m) {
                    $v = $months[$m] ?? 0.0;
                    $row[$m] = $v;
                    $total  += $v;
                }
                $row['Total'] = $total;
                $rows[] = $row;
            }
            return $rows;
        };

        return [
            'Pieces' => $makeMonthPivot('ScheduledQty'),
            'Hours'  => $makeMonthPivot('Total Hours'),
            'Value'  => $makeMonthPivot('Total Value'),
            'months' => (static function () use ($result, $monthOrder): array {
                $present = [];
                foreach ($result as $row) {
                    $m = $row['Month Name'] ?? '';
                    if ($m !== '') {
                        $present[$m] = true;
                    }
                }
                $out = [];
                foreach ($monthOrder as $m) {
                    if (isset($present[$m])) {
                        $out[] = $m;
                    }
                }
                return $out;
            })(),
        ];
    }

    /**
     * Build summary pivot: Line × ProductionWeek → ScheduledQty.
     */
    public static function buildWeekPivot(array $result): array
    {
        $agg   = [];
        $weeks = [];
        $lineNames = [];

        foreach ($result as $row) {
            $line = (string) ($row['Line'] ?? '');
            $wk   = (string) ($row['ProductionWeek'] ?? '');
            $qty  = Helpers::toNumber($row['ScheduledQty'] ?? null);
            if ($line === '' || $wk === '') {
                continue;
            }
            $lineNames[$line] = (string) ($row['Line Name'] ?? $line);
            $agg[$line][$wk]  = ($agg[$line][$wk] ?? 0.0) + $qty;
            $weeks[$wk]        = true;
        }

        ksort($weeks);
        $weekList = array_keys($weeks);

        $rows = [];
        foreach ($agg as $line => $wkData) {
            $row = ['Line' => $line, 'Line Name' => $lineNames[$line] ?? $line];
            $total = 0.0;
            foreach ($weekList as $wk) {
                $v = $wkData[$wk] ?? 0.0;
                $row[$wk] = $v;
                $total   += $v;
            }
            $row['Total'] = $total;
            $rows[] = $row;
        }
        return ['rows' => $rows, 'weeks' => $weekList];
    }

    /**
     * Data quality report – port of data_quality_report() in pq_logic.py.
     */
    public static function dataQualityReport(
        array  $dataRows,
        array  $capRows         = [],
        array  $spRows          = [],
        string $colQty          = Helpers::DEFAULT_COL_QTY,
        string $colLine         = Helpers::DEFAULT_COL_LINE,
        string $colStdPack      = Helpers::DEFAULT_COL_STD_PACK,
        string $colPart         = Helpers::DEFAULT_COL_PART,
        ?string $capacityLineCol = null,
    ): array {
        $issues = [];
        $result = [
            'bad_cap_date_headers'  => [],
            'repaired_cap_dates'    => [],
            'null_std_pack_rows'    => 0,
            'null_std_pack_pns'     => [],
            'unmatched_mrp_codes'   => [],
            'zero_qty_rows'         => 0,
            'issues'                => &$issues,
        ];

        // Capacity date headers
        if (!empty($capRows)) {
            $capCols = Helpers::getColumns($capRows);
            $skip = ['line', 'line group', 'line grp', 'linegroup'];
            foreach ($capCols as $c) {
                if (in_array(strtolower(trim($c)), $skip, true)) {
                    continue;
                }
                if (Helpers::toDate($c) === null) {
                    $result['bad_cap_date_headers'][] = $c;
                }
            }
            if (!empty($result['bad_cap_date_headers'])) {
                $list = implode(', ', array_slice($result['bad_cap_date_headers'], 0, 10));
                $issues[] = '⚠️ Capacity contains invalid week header(s): ' . $list;
            }
        }

        // Std Pack completeness
        $spLookup = self::buildStdPackLookup($spRows);
        if (!empty($dataRows)) {
            $missingRows = 0;
            $missingPns  = [];
            foreach ($dataRows as $row) {
                $orderSp  = Helpers::toNumber($row[$colStdPack] ?? null);
                $pn       = Helpers::safeStr($row[$colPart] ?? null);
                $masterSp = Helpers::toNumber($spLookup[$pn] ?? null);
                if ($orderSp <= 0 && $masterSp <= 0) {
                    $missingRows++;
                    if ($pn !== '') {
                        $missingPns[$pn] = true;
                    }
                }
            }
            $result['null_std_pack_rows'] = $missingRows;
            $result['null_std_pack_pns']  = array_keys($missingPns);
            if ($missingRows > 0) {
                $issues[] = "⚠️ {$missingRows} row(s) have no Std Pack in orders/master; they will use Std Pack = 1.";
            }
        }

        // Zero qty rows
        if (!empty($dataRows)) {
            $zeroQty = 0;
            foreach ($dataRows as $row) {
                if (Helpers::toNumber($row[$colQty] ?? null) === 0.0) {
                    $zeroQty++;
                }
            }
            $result['zero_qty_rows'] = $zeroQty;
            if ($zeroQty > 0) {
                $issues[] = "ℹ️ {$zeroQty} row(s) have Qty = 0.";
            }
        }

        return $result;
    }
}
