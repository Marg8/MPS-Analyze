<?php

declare(strict_types=1);

namespace Mps;

/**
 * BomAnalysis – PHP port of bom_analysis.py.
 */
class BomAnalysis
{
    // ── 1. Std Cost with progressive date fallback ───────────────────────────

    /**
     * Build {PN => ["cost" => float, "date" => string]} using the most recent
     * non-zero cost per part number.
     */
    public static function buildCostLookupDated(array $costRows): array
    {
        if (empty($costRows)) {
            return [];
        }
        $cols    = Helpers::getColumns($costRows);
        $pnCol   = Helpers::findFirstCol($cols, ['PN', 'Material', 'Part Number', 'Part']);
        $costCol = Helpers::findFirstCol($cols, ['Std Cost', 'StdCost', 'Cost', 'Price']);
        $dateCol = Helpers::findFirstCol($cols, ['Last update', 'LastUpdate', 'Update Date', 'Date']);
        if ($pnCol === null || $costCol === null) {
            return [];
        }

        $valid = [];
        foreach ($costRows as $row) {
            $pn   = Helpers::safeStr($row[$pnCol] ?? null);
            $cost = Helpers::toNumber($row[$costCol] ?? null);
            if ($pn === '' || $cost <= 0) {
                continue;
            }
            $dt = ($dateCol !== null) ? Helpers::toDate($row[$dateCol] ?? null) : null;
            $ts = $dt ? $dt->getTimestamp() : 0;
            $valid[] = [
                'pn'   => $pn,
                'cost' => $cost,
                'ts'   => $ts,
                'date' => $dt ? $dt->format('Y-m-d') : '',
            ];
        }

        // Sort descending so first-seen per PN = most recent
        usort($valid, static fn($a, $b) => $b['ts'] <=> $a['ts']);

        $result = [];
        foreach ($valid as $item) {
            if (!isset($result[$item['pn']])) {
                $result[$item['pn']] = ['cost' => $item['cost'], 'date' => $item['date']];
            }
        }
        return $result;
    }

    /**
     * Return traceability rows: [Part Number, Std Cost Used, Cost Effective Date].
     */
    public static function costDateSummary(array $costDatedLookup): array
    {
        $rows = [];
        foreach ($costDatedLookup as $pn => $info) {
            $rows[] = [
                'Part Number'          => $pn,
                'Std Cost Used'        => $info['cost'],
                'Cost Effective Date'  => $info['date'],
            ];
        }
        usort($rows, static fn($a, $b) => strcmp($a['Part Number'], $b['Part Number']));
        return $rows;
    }

    // ── 2. BOM parsing ────────────────────────────────────────────────────────

    /**
     * Parse and clean BOM rows.
     * Returns rows with columns: Material, Component, Component_Desc, Material_Desc,
     *   BOM_Usage, BOM_Level, Alt_BOM, MRP
     */
    public static function parseBom(array $bomRows, int $altBom = 1, int $bomLevel = 1): array
    {
        if (empty($bomRows)) {
            return [];
        }
        $cols = Helpers::getColumns($bomRows);

        $matCol     = Helpers::findFirstCol($cols, ['Material']);
        $compCol    = Helpers::findFirstCol($cols, ['Component']);
        $descCol    = Helpers::findFirstCol($cols, ['Component Description', 'Component Desc']);
        $qtyCol     = Helpers::findFirstCol($cols, ['Quantity', 'Qty']);
        $baseCol    = Helpers::findFirstCol($cols, ['Base quantity', 'Base Quantity', 'BaseQty']);
        $usageCol   = Helpers::findFirstCol($cols, ['Usage']);
        $levelCol   = Helpers::findFirstCol($cols, ['BOM Level', 'Level']);
        $altBomCol  = Helpers::findFirstCol($cols, ['Alt BOM', 'AltBOM', 'Alt_BOM']);
        $mrpCol     = Helpers::findFirstCol($cols, ['MRP']);
        $matDescCol = Helpers::findFirstCol($cols, ['Material Description']);

        if ($matCol === null || $compCol === null) {
            return [];
        }

        $out = [];
        foreach ($bomRows as $row) {
            $mat  = Helpers::safeStr($row[$matCol]  ?? null);
            $comp = Helpers::safeStr($row[$compCol] ?? null);
            if ($mat === '' || $mat === 'nan' || $comp === '' || $comp === 'nan') {
                continue;
            }

            // Compute BOM_Usage
            if ($usageCol !== null) {
                $bomUsage = Helpers::toNumber($row[$usageCol] ?? null);
            } elseif ($qtyCol !== null && $baseCol !== null) {
                $qty  = Helpers::toNumber($row[$qtyCol]  ?? null);
                $base = Helpers::toNumber($row[$baseCol] ?? null);
                $base = ($base <= 0) ? 1000.0 : $base;
                $bomUsage = $qty / $base;
            } elseif ($qtyCol !== null) {
                $bomUsage = Helpers::toNumber($row[$qtyCol] ?? null);
            } else {
                $bomUsage = 1.0;
            }

            if ($bomUsage <= 0) {
                continue;
            }

            $level = ($levelCol !== null) ? (int) Helpers::toNumber($row[$levelCol] ?? null) : 1;
            if ($level !== $bomLevel) {
                continue;
            }

            $altBomVal = ($altBomCol !== null) ? (int) Helpers::toNumber($row[$altBomCol] ?? null) : 1;
            if ($altBomVal <= 0) {
                $altBomVal = 1;
            }

            $out[] = [
                'Material'       => $mat,
                'Component'      => $comp,
                'Component_Desc' => ($descCol !== null)    ? Helpers::safeStr($row[$descCol]    ?? null) : $comp,
                'Material_Desc'  => ($matDescCol !== null) ? Helpers::safeStr($row[$matDescCol] ?? $mat)  : $mat,
                'BOM_Level'      => $level,
                'Alt_BOM'        => $altBomVal,
                'BOM_Usage'      => $bomUsage,
                'MRP'            => ($mrpCol !== null) ? Helpers::safeStr($row[$mrpCol] ?? null) : '',
            ];
        }

        if (empty($out)) {
            return [];
        }

        // Choose one Alt BOM per Material (prefer requested, else smallest)
        $altByMat = [];
        foreach ($out as $r) {
            $altByMat[$r['Material']][$r['Alt_BOM']] = true;
        }
        $chosenAlt = [];
        foreach ($altByMat as $mat => $alts) {
            if (isset($alts[$altBom])) {
                $chosenAlt[$mat] = $altBom;
            } else {
                $keys = array_keys($alts);
                sort($keys);
                $chosenAlt[$mat] = $keys[0];
            }
        }

        // Filter to chosen Alt BOM
        $out = array_values(array_filter($out, static fn($r) => $r['Alt_BOM'] === $chosenAlt[$r['Material']]));

        // Collapse duplicate Material × Component (sum BOM_Usage)
        $merged = [];
        foreach ($out as $r) {
            $key = $r['Material'] . '|||' . $r['Component'];
            if (!isset($merged[$key])) {
                $merged[$key] = $r;
            } else {
                $merged[$key]['BOM_Usage'] += $r['BOM_Usage'];
            }
        }

        return array_values($merged);
    }

    // ── 3. BOM explosion ──────────────────────────────────────────────────────

    /**
     * Explode scheduled FG demand through the BOM to produce component demand per week.
     *
     * Returns rows: FG, FG_Desc, Component, Component_Desc, BOM_Usage,
     *   FG_Qty, Comp_Demand, ProductionWeek, ProductionWeekDate, Line, Line Name, ...
     */
    public static function explodeBomDemand(
        array  $scheduledRows,
        array  $bomClean,
        string $fgCol       = 'Product code',
        string $qtyCol      = 'ScheduledQty',
        string $weekCol     = 'ProductionWeek',
        string $weekDateCol = 'ProductionWeekDate',
    ): array {
        if (empty($scheduledRows) || empty($bomClean)) {
            return [];
        }
        $bomCols = Helpers::getColumns($scheduledRows);
        if (!in_array($fgCol, $bomCols, true)) {
            return [];
        }

        // Build BOM index: Material → [component rows]
        $bomIndex = [];
        foreach ($bomClean as $bomRow) {
            $mat = (string) ($bomRow['Material'] ?? '');
            if ($mat !== '') {
                $bomIndex[$mat][] = $bomRow;
            }
        }

        $output = [];
        foreach ($scheduledRows as $schRow) {
            $fg    = Helpers::safeStr($schRow[$fgCol] ?? null);
            $fgQty = Helpers::toNumber($schRow[$qtyCol] ?? null);
            if (!isset($bomIndex[$fg])) {
                continue;
            }
            foreach ($bomIndex[$fg] as $bRow) {
                $usage      = (float) $bRow['BOM_Usage'];
                $compDemand = $fgQty * $usage;

                $newRow = [
                    'FG'             => $fg,
                    'FG_Desc'        => (string) ($bRow['Material_Desc'] ?? $fg),
                    'Component'      => (string) ($bRow['Component']      ?? ''),
                    'Component_Desc' => (string) ($bRow['Component_Desc'] ?? ''),
                    'BOM_Usage'      => $usage,
                    'FG_Qty'         => $fgQty,
                    'Comp_Demand'    => $compDemand,
                ];
                // Pass through dimension columns
                foreach ([$weekCol, $weekDateCol, 'Line', 'Line Name', 'Quarter', 'Month Name'] as $dc) {
                    if (isset($schRow[$dc])) {
                        $newRow[$dc] = $schRow[$dc];
                    }
                }
                $output[] = $newRow;
            }
        }
        return $output;
    }

    // ── 4. Component demand pivot ─────────────────────────────────────────────

    /**
     * Returns pivot: Component × Week → total demand qty.
     * Rows: Component, Component_Desc, <week1>, <week2>, ..., Total
     */
    public static function componentDemandPivot(array $explodedRows, string $weekCol = 'ProductionWeek'): array
    {
        if (empty($explodedRows) || !isset($explodedRows[0][$weekCol])) {
            return [];
        }

        $agg   = []; // [comp => [week => demand]]
        $descs = [];
        $weeks = [];
        foreach ($explodedRows as $row) {
            $comp = (string) ($row['Component']      ?? '');
            $desc = (string) ($row['Component_Desc'] ?? '');
            $wk   = (string) ($row[$weekCol]         ?? '');
            $dem  = Helpers::toNumber($row['Comp_Demand'] ?? null);
            if ($comp === '' || $wk === '') {
                continue;
            }
            $descs[$comp]     = $desc;
            $weeks[$wk]       = true;
            $agg[$comp][$wk]  = ($agg[$comp][$wk] ?? 0.0) + $dem;
        }

        ksort($weeks);
        $weekList = array_keys($weeks);

        $rows = [];
        foreach ($agg as $comp => $wkData) {
            $row   = ['Component' => $comp, 'Component_Desc' => $descs[$comp] ?? ''];
            $total = 0.0;
            foreach ($weekList as $wk) {
                $v = $wkData[$wk] ?? 0.0;
                $row[$wk] = $v;
                $total   += $v;
            }
            $row['Total'] = $total;
            $rows[] = $row;
        }

        // Sort by total desc
        usort($rows, static fn($a, $b) => $b['Total'] <=> $a['Total']);
        return ['rows' => $rows, 'weeks' => $weekList];
    }

    // ── 5. Build capability computation ──────────────────────────────────────

    /**
     * Build {Component → total_stock_qty} from the Stock sheet.
     */
    private static function buildStockLookup(array $stockRows): array
    {
        if (empty($stockRows)) {
            return [];
        }
        $cols    = Helpers::getColumns($stockRows);
        $compCol = Helpers::findFirstCol($cols, ['Component', 'Material', 'PN', 'Part Number', 'Part']);
        $qtyCol  = Helpers::findFirstCol($cols, ['Stock', 'Stock Qty', 'Stock_Qty', 'Qty', 'Quantity', 'On Hand']);
        if ($compCol === null || $qtyCol === null) {
            return [];
        }
        $lookup = [];
        foreach ($stockRows as $row) {
            $comp = Helpers::safeStr($row[$compCol] ?? null);
            $qty  = Helpers::toNumber($row[$qtyCol] ?? null);
            if ($comp === '') {
                continue;
            }
            $lookup[$comp] = ($lookup[$comp] ?? 0.0) + $qty;
        }
        return $lookup;
    }

    /**
     * Build weekly PO table: [{Component, PO_WeekDate, PO_Qty}, ...].
     */
    private static function buildWeeklyPoTable(array $rmPoRows): array
    {
        if (empty($rmPoRows)) {
            return [];
        }
        $cols    = Helpers::getColumns($rmPoRows);
        $compCol = Helpers::findFirstCol($cols, ['Component', 'Material', 'PN', 'Part Number', 'Part']);
        $qtyCol  = Helpers::findFirstCol($cols, [
            'Rec./reqd.qty', 'Rec./reqd.qty2', 'PO Qty', 'Qty', 'Quantity', 'Open Qty', 'Order Qty',
        ]);
        $dateCol = Helpers::findFirstCol($cols, [
            'Del/finish', 'Del/Finish', 'Delivery Date', 'Due Date',
            'PO Date', 'Week Date', 'Date', 'Requested date',
        ]);
        if ($compCol === null || $qtyCol === null || $dateCol === null) {
            return [];
        }

        $raw = [];
        foreach ($rmPoRows as $row) {
            $comp = Helpers::safeStr($row[$compCol] ?? null);
            $qty  = Helpers::toNumber($row[$qtyCol] ?? null);
            $dt   = Helpers::toDate($row[$dateCol] ?? null);
            if ($comp === '' || $qty <= 0 || $dt === null) {
                continue;
            }
            $key = $comp . '|||' . $dt->format('Y-m-d');
            $raw[$key] = [
                'Component'   => $comp,
                'PO_WeekDate' => $dt,
                'PO_Qty'      => ($raw[$key]['PO_Qty'] ?? 0.0) + $qty,
            ];
        }
        return array_values($raw);
    }

    /**
     * For each (component, weekDate) row, compute cumulative PO qty up to that week.
     *
     * @param array  $poWeekly  From buildWeeklyPoTable()
     * @param array  $rows      Exploded rows with 'Component' and weekDateCol
     * @param string $weekDateCol
     */
    private static function addCumPoQty(array &$rows, array $poWeekly, string $weekDateCol): void
    {
        if (empty($poWeekly)) {
            foreach ($rows as &$r) {
                $r['Cum_PO_Qty'] = 0.0;
            }
            unset($r);
            return;
        }

        // Build per-component sorted list of (timestamp, qty)
        $poMap = [];
        foreach ($poWeekly as $po) {
            $comp = Helpers::safeStr($po['Component'] ?? null);
            $dt   = $po['PO_WeekDate'];
            $qty  = (float) ($po['PO_Qty'] ?? 0.0);
            if ($comp === '' || !($dt instanceof \DateTime) || $qty <= 0) {
                continue;
            }
            $poMap[$comp][] = [$dt->getTimestamp(), $qty];
        }
        foreach ($poMap as &$entries) {
            usort($entries, static fn($a, $b) => $a[0] <=> $b[0]);
        }
        unset($entries);

        foreach ($rows as &$row) {
            $comp  = Helpers::safeStr($row['Component'] ?? null);
            $wkDt  = $row[$weekDateCol] ?? null;
            if (!($wkDt instanceof \DateTime)) {
                $wkDt = Helpers::toDate($wkDt);
            }
            if ($wkDt === null || !isset($poMap[$comp])) {
                $row['Cum_PO_Qty'] = 0.0;
                continue;
            }
            $ts  = $wkDt->getTimestamp();
            $cum = 0.0;
            foreach ($poMap[$comp] as [$poTs, $poQty]) {
                if ($poTs <= $ts) {
                    $cum += $poQty;
                } else {
                    break;
                }
            }
            $row['Cum_PO_Qty'] = $cum;
        }
        unset($row);
    }

    /**
     * Enrich exploded demand rows with CTB / supply metrics.
     */
    public static function computeBuildCapability(
        array   $explodedRows,
        array   $stockRows    = [],
        array   $rmPoRows     = [],
        string  $weekCol      = 'ProductionWeek',
        string  $weekDateCol  = 'ProductionWeekDate',
    ): array {
        if (empty($explodedRows)) {
            return [];
        }

        $stockLookup = self::buildStockLookup($stockRows);
        $poWeekly    = self::buildWeeklyPoTable($rmPoRows);

        $rows = $explodedRows;

        // Add Stock_Qty
        foreach ($rows as &$row) {
            $comp = Helpers::safeStr($row['Component'] ?? null);
            $row['Stock_Qty'] = $stockLookup[$comp] ?? 0.0;
        }
        unset($row);

        // Add Cum_PO_Qty
        if (!empty($poWeekly) && isset($rows[0][$weekDateCol])) {
            self::addCumPoQty($rows, $poWeekly, $weekDateCol);
        } else {
            foreach ($rows as &$r) {
                $r['Cum_PO_Qty'] = 0.0;
            }
            unset($r);
        }

        // Compute metrics
        foreach ($rows as &$row) {
            $avail  = (float) $row['Stock_Qty'] + (float) $row['Cum_PO_Qty'];
            $demand = Helpers::toNumber($row['Comp_Demand'] ?? null);
            $usage  = (float) ($row['BOM_Usage'] ?? 1.0);
            $fgQty  = (float) ($row['FG_Qty']    ?? 0.0);

            $row['Available_Supply'] = $avail;
            $row['Available_Qty']    = $avail;
            $row['Coverage_Pct']     = ($demand <= 0) ? 100.0 : min(100.0, $avail / $demand * 100.0);
            $row['Buildable_From_Comp'] = ($usage <= 0) ? $fgQty : $avail / $usage;
            $row['Shortage']         = max(0.0, $demand - $avail);
            $row['CTB_Flag']         = $avail > 0;
        }
        unset($row);

        return $rows;
    }

    // ── 6. Build capability summary ───────────────────────────────────────────

    /**
     * Summarise build capability per FG × Week.
     *
     * Returns rows ready for display:
     *   Part_Number, FG_Description, Line, Line Name, ProductionWeek, ProductionWeekDate,
     *   Quarter, Month Name, FG_Scheduled_Qty, Buildable_Qty, Material_Coverage_Pct,
     *   Constraint_Component, Constraint_Desc, Num_BOM_Components, Total_Shortage,
     *   Std_Cost, Cost_Effective_Date, Total_Cost
     */
    public static function buildCapabilitySummary(
        array  $enrichedRows,
        array  $costDatedLookup,
        bool   $hasStock    = false,
        string $weekCol     = 'ProductionWeek',
        string $weekDateCol = 'ProductionWeekDate',
    ): array {
        if (empty($enrichedRows)) {
            return [];
        }

        $grpKeys = ['FG', 'FG_Desc', $weekCol, $weekDateCol, 'Line', 'Line Name', 'Quarter', 'Month Name'];
        // Only keep keys present in the rows
        $sample = $enrichedRows[0];
        $grpKeys = array_values(array_filter($grpKeys, static fn($k) => isset($sample[$k])));

        // Group rows
        $groups = [];
        foreach ($enrichedRows as $row) {
            $key = implode('|||', array_map(static fn($k) => Helpers::safeStr($row[$k] ?? null), $grpKeys));
            $groups[$key][] = $row;
        }

        $summary = [];
        foreach ($groups as $groupRows) {
            $first  = $groupRows[0];
            $fgQty  = Helpers::toNumber($first['FG_Qty'] ?? null);

            if ($hasStock && isset($first['Coverage_Pct'])) {
                // Find minimum coverage
                $minCov     = PHP_FLOAT_MAX;
                $minIdx     = 0;
                $minBuild   = PHP_FLOAT_MAX;
                $totalShort = 0.0;
                foreach ($groupRows as $i => $r) {
                    $cov = (float) ($r['Coverage_Pct'] ?? 100.0);
                    if ($cov < $minCov) {
                        $minCov   = $cov;
                        $minIdx   = $i;
                    }
                    $build = (float) ($r['Buildable_From_Comp'] ?? $fgQty);
                    if ($build < $minBuild) {
                        $minBuild = $build;
                    }
                    $totalShort += (float) ($r['Shortage'] ?? 0.0);
                }
                $constraintComp = (string) ($groupRows[$minIdx]['Component']      ?? '');
                $constraintDesc = (string) ($groupRows[$minIdx]['Component_Desc'] ?? '');
                $coverage       = round($minCov, 1);
                $buildable      = max(0.0, $minBuild);
                $shortage       = $totalShort;
            } else {
                $constraintComp = null;
                $constraintDesc = null;
                $coverage       = 100.0;
                $buildable      = $fgQty;
                $shortage       = 0.0;
            }

            $baseRow = [];
            foreach ($grpKeys as $k) {
                $baseRow[$k] = $first[$k] ?? null;
            }

            $baseRow['FG_Scheduled_Qty']     = $fgQty;
            $baseRow['Buildable_Qty']        = $buildable;
            $baseRow['Material_Coverage_Pct'] = $coverage;
            $baseRow['Constraint_Component'] = $constraintComp;
            $baseRow['Constraint_Desc']      = $constraintDesc;
            $baseRow['Num_BOM_Components']   = count($groupRows);
            $baseRow['Total_Shortage']       = $shortage;

            // Rename FG → Part_Number
            if (isset($baseRow['FG'])) {
                $baseRow['Part_Number']    = $baseRow['FG'];
                $baseRow['FG_Description'] = $baseRow['FG_Desc'] ?? $baseRow['FG'];
                unset($baseRow['FG'], $baseRow['FG_Desc']);
            }

            // Add cost columns
            $pn = (string) ($baseRow['Part_Number'] ?? '');
            $costInfo = $costDatedLookup[$pn] ?? null;
            $baseRow['Std_Cost']           = $costInfo ? $costInfo['cost'] : 0.0;
            $baseRow['Cost_Effective_Date'] = $costInfo ? $costInfo['date'] : '';
            $baseRow['Total_Cost']         = $buildable * ($baseRow['Std_Cost'] ?? 0.0);

            $summary[] = $baseRow;
        }

        return $summary;
    }

    // ── 7. Shortage report ────────────────────────────────────────────────────

    /**
     * Returns shortage pivot: Component × Week → Shortage qty.
     */
    public static function shortageReport(array $enrichedRows, string $weekCol = 'ProductionWeek'): array
    {
        $agg   = [];
        $descs = [];
        $weeks = [];

        foreach ($enrichedRows as $row) {
            $shortage = Helpers::toNumber($row['Shortage'] ?? null);
            if ($shortage <= 0) {
                continue;
            }
            $comp = (string) ($row['Component']      ?? '');
            $desc = (string) ($row['Component_Desc'] ?? '');
            $wk   = (string) ($row[$weekCol]         ?? '');
            if ($comp === '' || $wk === '') {
                continue;
            }
            $descs[$comp]    = $desc;
            $weeks[$wk]      = true;
            $agg[$comp][$wk] = ($agg[$comp][$wk] ?? 0.0) + $shortage;
        }

        ksort($weeks);
        $weekList = array_keys($weeks);

        $rows = [];
        foreach ($agg as $comp => $wkData) {
            $row   = ['Component' => $comp, 'Component_Desc' => $descs[$comp] ?? ''];
            $total = 0.0;
            foreach ($weekList as $wk) {
                $v = $wkData[$wk] ?? 0.0;
                $row[$wk] = $v;
                $total   += $v;
            }
            $row['Total Shortage'] = $total;
            $rows[] = $row;
        }
        usort($rows, static fn($a, $b) => $b['Total Shortage'] <=> $a['Total Shortage']);
        return ['rows' => $rows, 'weeks' => $weekList];
    }

    // ── 8. RM Coverage report ─────────────────────────────────────────────────

    /**
     * Build the Raw Material Coverage report.
     *
     * Returns ['coverage' => rows, 'kpi' => rows, 'weeks' => list]
     *
     * coverage rows: Component, Component_Desc, UOM, Initial_Inventory, Metric,
     *   <week1>, <week2>, ...
     * kpi rows: Component, Description, Initial_Inventory, First_Shortage_Week,
     *   Pct_Weeks_Covered, Peak_Shortage, Net_Deficit_Weeks
     */
    public static function buildRmCoverageTable(
        array  $explodedRows,
        array  $stockRows     = [],
        array  $rmPoRows      = [],
        string $weekCol       = 'ProductionWeek',
        string $weekDateCol   = 'ProductionWeekDate',
    ): array {
        if (empty($explodedRows)) {
            return ['coverage' => [], 'kpi' => [], 'weeks' => []];
        }

        // ── 1. Ordered week list ──────────────────────────────────────────────
        $weekDateMap = [];
        foreach ($explodedRows as $row) {
            $wk  = (string) ($row[$weekCol]     ?? '');
            $wdt = $row[$weekDateCol] ?? null;
            if ($wk !== '' && $wdt !== null) {
                $weekDateMap[$wk] = ($wdt instanceof \DateTime) ? $wdt : Helpers::toDate($wdt);
            }
        }
        uasort($weekDateMap, static fn($a, $b) => (
            ($a instanceof \DateTime ? $a->getTimestamp() : 0) <=>
            ($b instanceof \DateTime ? $b->getTimestamp() : 0)
        ));
        $weeks = array_keys($weekDateMap);
        if (empty($weeks)) {
            // fallback: unique sorted week labels
            $wSet = [];
            foreach ($explodedRows as $row) {
                $wk = (string) ($row[$weekCol] ?? '');
                if ($wk !== '') {
                    $wSet[$wk] = true;
                }
            }
            ksort($wSet);
            $weeks = array_keys($wSet);
        }

        // ── 2. Demand pivot: Component × Week ────────────────────────────────
        $demandPvt = [];
        $descMap   = [];
        foreach ($explodedRows as $row) {
            $comp = (string) ($row['Component']      ?? '');
            $desc = (string) ($row['Component_Desc'] ?? '');
            $wk   = (string) ($row[$weekCol]         ?? '');
            $dem  = Helpers::toNumber($row['Comp_Demand'] ?? null);
            if ($comp === '' || $wk === '') {
                continue;
            }
            $descMap[$comp]         = $desc;
            $demandPvt[$comp][$wk]  = ($demandPvt[$comp][$wk] ?? 0.0) + $dem;
        }

        // ── 3. Initial inventory ──────────────────────────────────────────────
        $stockLookup = self::buildStockLookup($stockRows);

        // UOM from stock sheet
        $uomLookup = [];
        if (!empty($stockRows)) {
            $sCols    = Helpers::getColumns($stockRows);
            $compColS = Helpers::findFirstCol($sCols, ['Component', 'Material', 'PN', 'Part Number', 'Part']);
            $uomColS  = Helpers::findFirstCol($sCols, ['UOM', 'Unit of Measure', 'Unit', 'Base UOM', 'Base Unit']);
            if ($compColS !== null && $uomColS !== null) {
                foreach ($stockRows as $sRow) {
                    $c = Helpers::safeStr($sRow[$compColS] ?? null);
                    $u = Helpers::safeStr($sRow[$uomColS]  ?? null);
                    if ($c !== '') {
                        $uomLookup[$c] = $u;
                    }
                }
            }
        }

        // ── 4. Weekly receipts from RM_PO ────────────────────────────────────
        $receiptsMap = []; // (comp|||wk) → qty
        $poWeekly    = self::buildWeeklyPoTable($rmPoRows);
        if (!empty($poWeekly) && !empty($weekDateMap)) {
            $sortedWeeks = $weeks;
            $sortedWkDts = array_map(static fn($w) => $weekDateMap[$w] ?? null, $sortedWeeks);

            foreach ($poWeekly as $poRow) {
                $comp  = (string) ($poRow['Component']   ?? '');
                $poDt  = $poRow['PO_WeekDate'];
                $poQty = (float) ($poRow['PO_Qty'] ?? 0.0);
                if ($comp === '' || !($poDt instanceof \DateTime) || $poQty <= 0) {
                    continue;
                }
                $poTs = $poDt->getTimestamp();

                // First production week >= Del/finish date
                $matchedWk = null;
                foreach ($sortedWeeks as $i => $wk) {
                    $wkDt = $sortedWkDts[$i];
                    if ($wkDt !== null && $wkDt->getTimestamp() >= $poTs) {
                        $matchedWk = $wk;
                        break;
                    }
                }
                if ($matchedWk === null && !empty($sortedWeeks)) {
                    $matchedWk = end($sortedWeeks);
                }
                if ($matchedWk !== null) {
                    $key = $comp . '|||' . $matchedWk;
                    $receiptsMap[$key] = ($receiptsMap[$key] ?? 0.0) + $poQty;
                }
            }
        }

        // ── 5. Build 3-row-per-component coverage table ───────────────────────
        $coverageRows = [];
        $kpiRows      = [];

        foreach ($demandPvt as $comp => $compWeekly) {
            $desc       = $descMap[$comp] ?? '';
            $initialInv = $stockLookup[$comp] ?? 0.0;
            $uom        = $uomLookup[$comp] ?? '';

            $rowD = ['Component' => $comp, 'Component_Desc' => $desc, 'UOM' => $uom,
                     'Initial_Inventory' => $initialInv, 'Metric' => 'Demand'];
            $rowR = ['Component' => '', 'Component_Desc' => '', 'UOM' => '',
                     'Initial_Inventory' => null, 'Metric' => 'Receipts'];
            $rowB = ['Component' => '', 'Component_Desc' => '', 'UOM' => '',
                     'Initial_Inventory' => null, 'Metric' => 'Ending Balance'];

            $balance          = $initialInv;
            $firstShortageWk  = null;
            $shortageCount    = 0;
            $netDeficitWeeks  = 0.0;
            $peakShortage     = 0.0;

            foreach ($weeks as $wk) {
                $demandVal   = (float) ($compWeekly[$wk] ?? 0.0);
                $receiptsVal = (float) ($receiptsMap[$comp . '|||' . $wk] ?? 0.0);
                $balance     = $balance + $receiptsVal - $demandVal;

                $rowD[$wk] = $demandVal;
                $rowR[$wk] = $receiptsVal;
                $rowB[$wk] = round($balance, 2);

                if ($balance < 0) {
                    if ($firstShortageWk === null) {
                        $firstShortageWk = $wk;
                    }
                    $shortageCount++;
                    $netDeficitWeeks += abs($balance);
                    $peakShortage     = max($peakShortage, abs($balance));
                }
            }

            $coverageRows[] = $rowD;
            $coverageRows[] = $rowR;
            $coverageRows[] = $rowB;

            $pctCovered = count($weeks) > 0
                ? round((count($weeks) - $shortageCount) / count($weeks) * 100, 1)
                : 100.0;

            $kpiRows[] = [
                'Component'           => $comp,
                'Description'         => $desc,
                'Initial_Inventory'   => $initialInv,
                'First_Shortage_Week' => $firstShortageWk ?? '—',
                'Pct_Weeks_Covered'   => $pctCovered,
                'Peak_Shortage'       => round($peakShortage, 0),
                'Net_Deficit_Weeks'   => round($netDeficitWeeks, 0),
            ];
        }

        return ['coverage' => $coverageRows, 'kpi' => $kpiRows, 'weeks' => $weeks];
    }

    // ── 9. Line × Week output plan ────────────────────────────────────────────

    /**
     * Build Line × Week production output plan (Planned / FG Output / Shortage).
     *
     * Returns ['plan' => rows, 'kpi' => rows, 'weeks' => list]
     */
    public static function buildLineOutputPlan(
        array  $resultRows,
        array  $capabilityRows = [],
        string $weekCol        = 'ProductionWeek',
        string $qtyCol         = 'ScheduledQty',
        string $lineCol        = 'Line',
        string $lineNameCol    = 'Line Name',
    ): array {
        if (empty($resultRows)) {
            return ['plan' => [], 'kpi' => [], 'weeks' => []];
        }

        // ── 1. Planned from result rows ───────────────────────────────────────
        $lineNameMap = [];
        $plannedAgg  = [];
        $weeks       = [];
        $lines       = [];

        foreach ($resultRows as $row) {
            $line = (string) ($row[$lineCol]    ?? '');
            $wk   = (string) ($row[$weekCol]    ?? '');
            $qty  = Helpers::toNumber($row[$qtyCol] ?? null);
            if ($line === '' || $wk === '') {
                continue;
            }
            $lineNameMap[$line] = (string) ($row[$lineNameCol] ?? $line);
            $plannedAgg[$line][$wk] = ($plannedAgg[$line][$wk] ?? 0.0) + $qty;
            $weeks[$wk] = true;
            $lines[$line] = true;
        }
        ksort($weeks);
        ksort($lines);
        $weekList = array_keys($weeks);
        $lineList = array_keys($lines);

        // ── 2. FG Output from capability (CTB-constrained) ───────────────────
        $buildableAgg = [];
        if (!empty($capabilityRows)) {
            $hasBQ = isset($capabilityRows[0]['Buildable_Qty']);
            $hasPQ = isset($capabilityRows[0]['FG_Scheduled_Qty']);
            if ($hasBQ && $hasPQ) {
                foreach ($capabilityRows as $capRow) {
                    $capLine = (string) ($capRow['Line']            ?? '');
                    $capWk   = (string) ($capRow[$weekCol]          ?? '');
                    $bq      = Helpers::toNumber($capRow['Buildable_Qty']     ?? null);
                    $pq      = Helpers::toNumber($capRow['FG_Scheduled_Qty']  ?? null);
                    $bq      = min($pq, $bq);
                    if ($capLine !== '' && $capWk !== '') {
                        $buildableAgg[$capLine][$capWk] = ($buildableAgg[$capLine][$capWk] ?? 0.0) + $bq;
                    }
                }
            }
        }

        // ── 3. Build output ───────────────────────────────────────────────────
        $planRows = [];
        $kpiRows  = [];

        foreach ($lineList as $line) {
            $lname = $lineNameMap[$line] ?? '';

            $rowP = ['Line' => $line, 'Line_Name' => $lname, 'Metric' => 'Planned'];
            $rowO = ['Line' => $line, 'Line_Name' => $lname, 'Metric' => 'FG Output'];
            $rowS = ['Line' => $line, 'Line_Name' => $lname, 'Metric' => 'Shortage'];

            $firstShortageWk = null;
            $recoveryWk      = null;
            $foundShortage   = false;
            $totalPlanned    = 0.0;
            $totalOutput     = 0.0;
            $totalShortage   = 0.0;

            foreach ($weekList as $wk) {
                $planned = (float) ($plannedAgg[$line][$wk] ?? 0.0);

                if (!empty($buildableAgg)) {
                    $fgOut = min($planned, (float) ($buildableAgg[$line][$wk] ?? $planned));
                } else {
                    $fgOut = $planned;
                }
                $shortage = max(0.0, $planned - $fgOut);

                $rowP[$wk] = $planned;
                $rowO[$wk] = $fgOut;
                $rowS[$wk] = $shortage > 0 ? $shortage : 0.0;

                $totalPlanned  += $planned;
                $totalOutput   += $fgOut;
                $totalShortage += $shortage;

                if ($shortage > 0) {
                    $foundShortage = true;
                    if ($firstShortageWk === null) {
                        $firstShortageWk = $wk;
                    }
                } elseif ($foundShortage && $shortage === 0.0 && $recoveryWk === null) {
                    $recoveryWk = $wk;
                }
            }

            $planRows[] = $rowP;
            $planRows[] = $rowO;
            $planRows[] = $rowS;

            $pct = ($totalPlanned > 0) ? round($totalOutput / $totalPlanned * 100, 1) : 100.0;

            $kpiRows[] = [
                'Line'                => $line,
                'Line_Name'           => $lname,
                'Total_Planned'       => round($totalPlanned,  0),
                'Total_FG_Output'     => round($totalOutput,   0),
                'Total_Shortage_Pcs'  => round($totalShortage, 0),
                'Pct_Achievable'      => $pct,
                'First_Shortage_Week' => $firstShortageWk ?? '—',
                'Recovery_Week'       => (
                    $recoveryWk !== null ? $recoveryWk
                    : ($foundShortage ? 'No recovery yet' : '—')
                ),
            ];
        }

        return ['plan' => $planRows, 'kpi' => $kpiRows, 'weeks' => $weekList];
    }
}
