<?php

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require __DIR__ . '/vendor/autoload.php';

use Mps\Helpers;
use Mps\ExcelReader;
use Mps\PqLogic;
use Mps\BomAnalysis;
use Mps\ExcelExporter;

session_start();

// ── Constants ──────────────────────────────────────────────────────────────────
const UPLOAD_DIR = __DIR__ . '/uploads/';
const MAX_UPLOAD = 50 * 1024 * 1024; // 50 MB

if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtNum(mixed $v, int $dec = 0): string
{
    $f = is_numeric($v) ? (float) $v : 0.0;
    return number_format($f, $dec);
}

function pctColor(float $pct): string
{
    if ($pct >= 95) return '#C8E6C9';
    if ($pct >= 80) return '#FFF9C4';
    return '#FFCDD2';
}

// ── Handle file upload ────────────────────────────────────────────────────────
$uploadError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xlfile'])) {
    $f = $_FILES['xlfile'];
    if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= MAX_UPLOAD) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            $dest = UPLOAD_DIR . session_id() . '.xlsx';
            if (move_uploaded_file($f['tmp_name'], $dest)) {
                $_SESSION['xlfile']     = $dest;
                $_SESSION['xlfilename'] = $f['name'];
                // Clear previous results on new upload
                unset($_SESSION['result'], $_SESSION['sheets']);
            }
        } else {
            $uploadError = 'Only .xlsx files are supported.';
        }
    } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadError = 'Upload error: ' . $f['error'];
    }
}

// ── Use workspace MPS.xlsx if requested ──────────────────────────────────────
$wsFile = __DIR__ . '/../MPS.xlsx';
if (isset($_POST['use_workspace'])) {
    if (file_exists($wsFile)) {
        $dest = UPLOAD_DIR . session_id() . '.xlsx';
        copy($wsFile, $dest);
        $_SESSION['xlfile']     = $dest;
        $_SESSION['xlfilename'] = 'MPS.xlsx (workspace)';
        unset($_SESSION['result'], $_SESSION['sheets']);
    } else {
        $uploadError = 'MPS.xlsx not found in workspace directory.';
    }
}

// ── Load sheet names ──────────────────────────────────────────────────────────
$xlFile     = $_SESSION['xlfile']     ?? null;
$xlFileName = $_SESSION['xlfilename'] ?? null;
$sheets     = [];
$xlReader   = null;

if ($xlFile && file_exists($xlFile)) {
    try {
        $xlReader = new ExcelReader($xlFile);
        $sheets   = $xlReader->sheetNames();
        $_SESSION['sheets'] = $sheets;
    } catch (\Throwable $e) {
        $uploadError = 'Could not read Excel file: ' . $e->getMessage();
        $xlFile = null;
    }
}

// ── Helper: sheet select index ───────────────────────────────────────────────
function sheetIdx(array $sheets, string ...$candidates): int
{
    foreach ($candidates as $c) {
        $i = array_search($c, $sheets, true);
        if ($i !== false) {
            return (int) $i;
        }
    }
    return 0;
}

function noneIdx(array $options, string ...$candidates): int
{
    foreach ($candidates as $c) {
        $i = array_search($c, $options, true);
        if ($i !== false) {
            return (int) $i;
        }
    }
    return 0;
}

// ── POST: Run query ───────────────────────────────────────────────────────────
$runResult   = null;
$runError    = '';
$ma3Data     = null;
$bomData     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_query']) && $xlReader !== null) {
    try {
        // ── Settings from form ────────────────────────────────────────────────
        $baseWeek       = max(1, min(53, (int) ($_POST['base_week']       ?? 24)));
        $colQty         = trim($_POST['col_qty']         ?? Helpers::DEFAULT_COL_QTY);
        $colLine        = trim($_POST['col_line']        ?? Helpers::DEFAULT_COL_LINE);
        $colReqDate     = trim($_POST['col_req_date']    ?? Helpers::DEFAULT_COL_REQ_DATE);
        $colCommitDate  = trim($_POST['col_commit_date'] ?? Helpers::DEFAULT_COL_COMMIT_DATE);
        $colStdPack     = trim($_POST['col_std_pack']    ?? Helpers::DEFAULT_COL_STD_PACK);
        $capHeaderRow   = max(0, (int) ($_POST['cap_header_row'] ?? 1));
        $capLineCol     = trim($_POST['cap_line_col'] ?? 'Line');

        $dataSheet    = $_POST['data_sheet']      ?? ($sheets[0] ?? '');
        $capSheet     = $_POST['cap_sheet']       ?? '(none)';
        $hrsSheet     = $_POST['hrs_sheet']       ?? '(none)';
        $costSheet    = $_POST['cost_sheet']      ?? '(none)';
        $spSheet      = $_POST['sp_sheet']        ?? '(none)';
        $bomSheet     = $_POST['bom_sheet']       ?? '(none)';
        $stockSheet   = $_POST['stock_sheet']     ?? '(none)';
        $rmPoSheet    = $_POST['rm_po_sheet']     ?? '(none)';

        // ── Read sheets ───────────────────────────────────────────────────────
        $dataRows  = $xlReader->readSheet($dataSheet);
        $capRows   = ($capSheet   !== '(none)') ? $xlReader->readSheet($capSheet, $capHeaderRow) : [];
        $hrsRows   = ($hrsSheet   !== '(none)') ? $xlReader->readSheet($hrsSheet)   : [];
        $costRows  = ($costSheet  !== '(none)') ? $xlReader->readSheet($costSheet)  : [];
        $spRows    = ($spSheet    !== '(none)') ? $xlReader->readSheet($spSheet)    : [];
        $bomRows   = ($bomSheet   !== '(none)') ? $xlReader->readSheet($bomSheet)   : [];
        $stockRows = ($stockSheet !== '(none)') ? $xlReader->readSheet($stockSheet) : [];
        $rmPoRows  = ($rmPoSheet  !== '(none)') ? $xlReader->readSheet($rmPoSheet)  : [];

        // ── Run scheduling ────────────────────────────────────────────────────
        $result = PqLogic::runQuery(
            dataRows:       $dataRows,
            capRows:        $capRows,
            overrideRows:   [],
            baseWeek:       $baseWeek,
            hrsRows:        $hrsRows,
            costRows:       $costRows,
            spRows:         $spRows,
            colQty:         $colQty,
            colLine:        $colLine,
            colReqDate:     $colReqDate,
            colCommitDate:  $colCommitDate,
            colStdPack:     $colStdPack,
            capacityLineCol: $capLineCol,
        );

        // ── MA3 Summary ───────────────────────────────────────────────────────
        $ma3Data = PqLogic::buildMa3Summary($result);

        // ── BOM Analysis ──────────────────────────────────────────────────────
        if (!empty($bomRows) && !empty($result)) {
            $costDatedLookup = BomAnalysis::buildCostLookupDated($costRows);
            $bomClean        = BomAnalysis::parseBom($bomRows);
            $explodedBase    = BomAnalysis::explodeBomDemand($result, $bomClean);

            $hasSupply = (!empty($stockRows) || !empty($rmPoRows));
            $explodedSupply = $hasSupply
                ? BomAnalysis::computeBuildCapability($explodedBase, $stockRows, $rmPoRows)
                : $explodedBase;

            $capabilityRows = BomAnalysis::buildCapabilitySummary(
                $explodedSupply, $costDatedLookup, $hasSupply,
            );
            $shortageData    = BomAnalysis::shortageReport($explodedSupply);
            $demandPivotData = BomAnalysis::componentDemandPivot($explodedBase);
            $rmCovData       = BomAnalysis::buildRmCoverageTable($explodedBase, $stockRows, $rmPoRows);
            $lineOutputData  = BomAnalysis::buildLineOutputPlan($result, $capabilityRows);

            $bomData = compact(
                'bomClean', 'explodedBase', 'explodedSupply',
                'capabilityRows', 'shortageData', 'demandPivotData',
                'rmCovData', 'lineOutputData', 'costDatedLookup', 'hasSupply',
            );
        }

        // ── Store in session for downloads ────────────────────────────────────
        $_SESSION['result']    = $result;
        $_SESSION['ma3Data']   = $ma3Data;
        $_SESSION['bomData']   = $bomData;
        $_SESSION['settings']  = compact(
            'dataSheet', 'capSheet', 'hrsSheet', 'costSheet', 'spSheet',
            'bomSheet', 'stockSheet', 'rmPoSheet',
            'baseWeek', 'colQty', 'colLine', 'colReqDate', 'colCommitDate',
            'colStdPack', 'capHeaderRow', 'capLineCol',
        );

        $runResult = $result;

    } catch (\Throwable $e) {
        $runError = $e->getMessage() . "\n" . $e->getTraceAsString();
    }
} elseif (isset($_SESSION['result'])) {
    $runResult = $_SESSION['result'];
    $ma3Data   = $_SESSION['ma3Data']  ?? null;
    $bomData   = $_SESSION['bomData']  ?? null;
}

// ── POST: Download ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $type = $_POST['download'];
    $res  = $_SESSION['result']  ?? [];
    $ma3  = $_SESSION['ma3Data'] ?? [];
    $bom  = $_SESSION['bomData'] ?? null;

    $bytes = '';
    $fname = 'export.xlsx';
    $mime  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    switch ($type) {
        case 'result':
            $weekPivot = PqLogic::buildWeekPivot($res);
            $bytes = ExcelExporter::scheduledResultToXlsx($res, $weekPivot['rows'], $weekPivot['weeks']);
            $fname = 'scheduled_result.xlsx';
            break;
        case 'ma3':
            $bytes = ExcelExporter::ma3ToXlsx($ma3);
            $fname = 'ma3_summary.xlsx';
            break;
        case 'rm_coverage':
            if ($bom && !empty($bom['rmCovData']['coverage'])) {
                $bytes = ExcelExporter::rmCoverageToXlsx(
                    $bom['rmCovData']['coverage'],
                    $bom['rmCovData']['kpi'],
                    $bom['rmCovData']['weeks'],
                );
                $fname = 'rm_coverage_report.xlsx';
            }
            break;
        case 'line_output':
            if ($bom && !empty($bom['lineOutputData']['plan'])) {
                $bytes = ExcelExporter::lineOutputToXlsx(
                    $bom['lineOutputData']['plan'],
                    $bom['lineOutputData']['kpi'],
                    $bom['lineOutputData']['weeks'],
                );
                $fname = 'line_output_plan.xlsx';
            }
            break;
        case 'result_csv':
            if (!empty($res)) {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="scheduled_result.csv"');
                $cols = array_keys($res[0]);
                echo implode(',', array_map('addslashes', $cols)) . "\n";
                foreach ($res as $row) {
                    $vals = array_map(function ($v) {
                        if ($v instanceof \DateTime) return $v->format('Y-m-d');
                        return '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
                    }, array_values($row));
                    echo implode(',', $vals) . "\n";
                }
                exit;
            }
            break;
    }

    if ($bytes !== '') {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}

// ── Build pivot data for display ───────────────────────────────────────────────
$weekPivot = null;
if ($runResult) {
    $weekPivot = PqLogic::buildWeekPivot($runResult);
}

// ── Settings defaults ─────────────────────────────────────────────────────────
$settings  = $_SESSION['settings'] ?? [];
$baseWeek  = $settings['baseWeek']       ?? 24;
$colQty    = $settings['colQty']         ?? Helpers::DEFAULT_COL_QTY;
$colLine   = $settings['colLine']        ?? Helpers::DEFAULT_COL_LINE;
$colReqDate= $settings['colReqDate']     ?? Helpers::DEFAULT_COL_REQ_DATE;
$colCommit = $settings['colCommitDate']  ?? Helpers::DEFAULT_COL_COMMIT_DATE;
$colSP     = $settings['colStdPack']     ?? Helpers::DEFAULT_COL_STD_PACK;
$capHdrRow = $settings['capHeaderRow']   ?? 1;
$capLineC  = $settings['capLineCol']     ?? 'Line';

$selData   = $settings['dataSheet']  ?? '';
$selCap    = $settings['capSheet']   ?? '(none)';
$selHrs    = $settings['hrsSheet']   ?? '(none)';
$selCost   = $settings['costSheet']  ?? '(none)';
$selSP     = $settings['spSheet']    ?? '(none)';
$selBom    = $settings['bomSheet']   ?? '(none)';
$selStock  = $settings['stockSheet'] ?? '(none)';
$selRmPo   = $settings['rmPoSheet']  ?? '(none)';

// Sheet options with "(none)" prefix
$noneOpts = array_merge(['(none)'], $sheets);

function buildSelect(string $name, array $options, string $selected, string $class = ''): string
{
    $html = "<select name=\"{$name}\" class=\"form-select form-select-sm {$class}\">";
    foreach ($options as $opt) {
        $sel  = ($opt === $selected) ? ' selected' : '';
        $html .= '<option value="' . esc($opt) . '"' . $sel . '>' . esc($opt) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🏭 MPS – Capacity Schedule Tester (PHP)</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-3 py-2">
  <span class="navbar-brand mb-0 h5">🏭 MPS Capacity Scheduler <small class="text-muted fs-6">PHP Edition</small></span>
</nav>

<div class="container-fluid px-3 py-3">

<!-- ── Settings sidebar row ────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-header fw-bold">⚙️ Settings</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="mainForm">
          <label class="form-label small fw-semibold">Base Week (ISO)</label>
          <input type="number" name="base_week" class="form-control form-control-sm mb-2"
                 value="<?= esc($baseWeek) ?>" min="1" max="53">

          <label class="form-label small fw-semibold mt-1">Qty column</label>
          <input type="text" name="col_qty" class="form-control form-control-sm mb-2" value="<?= esc($colQty) ?>">

          <label class="form-label small fw-semibold">Line column (MRP)</label>
          <input type="text" name="col_line" class="form-control form-control-sm mb-2" value="<?= esc($colLine) ?>">

          <label class="form-label small fw-semibold">Requested date column</label>
          <input type="text" name="col_req_date" class="form-control form-control-sm mb-2" value="<?= esc($colReqDate) ?>">

          <label class="form-label small fw-semibold">Plan/Commit date column</label>
          <input type="text" name="col_commit_date" class="form-control form-control-sm mb-2" value="<?= esc($colCommit) ?>">

          <label class="form-label small fw-semibold">Std Pack column</label>
          <input type="text" name="col_std_pack" class="form-control form-control-sm mb-2" value="<?= esc($colSP) ?>">

          <label class="form-label small fw-semibold">Capacity header row (0=first)</label>
          <input type="number" name="cap_header_row" class="form-control form-control-sm mb-2"
                 value="<?= esc($capHdrRow) ?>" min="0" max="10">

          <label class="form-label small fw-semibold">Capacity key column</label>
          <input type="text" name="cap_line_col" class="form-control form-control-sm mb-2" value="<?= esc($capLineC) ?>">

          <hr>
          <div class="small text-muted mb-1 fw-semibold">Default Capacities (fallback)</div>
          <?php foreach (Helpers::DEFAULT_CAPS as $lg => $cap): ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <label class="form-label small mb-0 w-50"><?= esc($lg) ?></label>
            <input type="number" name="cap_<?= esc($lg) ?>" class="form-control form-control-sm"
                   value="<?= esc($cap) ?>" step="500">
          </div>
          <?php endforeach ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-9">

    <!-- ── 1. Data source ─────────────────────────────────────────────────── -->
    <div class="card mb-3">
      <div class="card-header fw-bold">1 · Data Source</div>
      <div class="card-body">
        <?php if ($uploadError): ?>
          <div class="alert alert-danger py-1"><?= esc($uploadError) ?></div>
        <?php endif ?>
        <div class="row g-2">
          <div class="col-md-6">
            <form method="post" enctype="multipart/form-data">
              <label class="form-label small fw-semibold">Upload Excel file (.xlsx)</label>
              <div class="input-group input-group-sm">
                <input type="file" name="xlfile" accept=".xlsx" class="form-control">
                <button type="submit" class="btn btn-outline-primary">Upload</button>
              </div>
            </form>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Or use workspace file</label>
            <form method="post">
              <button type="submit" name="use_workspace" class="btn btn-sm btn-outline-secondary"
                <?= file_exists($wsFile) ? '' : 'disabled' ?>>
                📂 Use MPS.xlsx from workspace
                <?php if (!file_exists($wsFile)): ?><small>(not found)</small><?php endif ?>
              </button>
            </form>
          </div>
        </div>
        <?php if ($xlFileName): ?>
          <div class="alert alert-success py-1 mt-2 mb-0">
            ✅ Loaded: <strong><?= esc($xlFileName) ?></strong>
            <?php if (!empty($sheets)): ?>(<?= count($sheets) ?> sheets)<?php endif ?>
          </div>
        <?php endif ?>
      </div>
    </div>

    <?php if (!empty($sheets)): ?>
    <!-- ── 2. Sheet selection ────────────────────────────────────────────── -->
    <form method="post" id="runForm">
      <!-- Copy settings from sidebar form via hidden inputs -->
      <div class="card mb-3">
        <div class="card-header fw-bold">2 · Sheet Selection</div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Data sheet *</label>
              <?= buildSelect('data_sheet', $sheets, $selData ?: ($sheets[sheetIdx($sheets, 'Entry Open Orders', 'Data', $sheets[0] ?? '')] ?? '')) ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Capacity sheet</label>
              <?= buildSelect('cap_sheet', $noneOpts, $selCap ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Capacity header row</label>
              <input type="number" name="cap_header_row" class="form-control form-control-sm"
                     value="<?= esc($capHdrRow) ?>" min="0" max="10">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Hrs sheet</label>
              <?= buildSelect('hrs_sheet', $noneOpts, $selHrs ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Cost sheet</label>
              <?= buildSelect('cost_sheet', $noneOpts, $selCost ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Std Pack master sheet</label>
              <?= buildSelect('sp_sheet', $noneOpts, $selSP ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">BOM sheet</label>
              <?= buildSelect('bom_sheet', $noneOpts, $selBom ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Stock sheet</label>
              <?= buildSelect('stock_sheet', $noneOpts, $selStock ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">RM_PO sheet</label>
              <?= buildSelect('rm_po_sheet', $noneOpts, $selRmPo ?: '(none)') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Capacity key column</label>
              <input type="text" name="cap_line_col" class="form-control form-control-sm"
                     value="<?= esc($capLineC) ?>">
            </div>
            <!-- Settings mirrors -->
            <input type="hidden" name="base_week"      value="<?= esc($baseWeek) ?>">
            <input type="hidden" name="col_qty"         value="<?= esc($colQty) ?>">
            <input type="hidden" name="col_line"        value="<?= esc($colLine) ?>">
            <input type="hidden" name="col_req_date"    value="<?= esc($colReqDate) ?>">
            <input type="hidden" name="col_commit_date" value="<?= esc($colCommit) ?>">
            <input type="hidden" name="col_std_pack"    value="<?= esc($colSP) ?>">
          </div>
        </div>
      </div>

      <!-- ── 3. Run ──────────────────────────────────────────────────────── -->
      <div class="mb-3 d-flex gap-2">
        <button type="submit" name="run_query" class="btn btn-primary">
          ▶ Run Query
        </button>
        <a href="?" class="btn btn-outline-secondary">↻ Reset</a>
      </div>
    </form>

    <?php if ($runError): ?>
      <div class="alert alert-danger">
        <strong>Error:</strong><br><pre class="mb-0 small"><?= esc(substr($runError, 0, 2000)) ?></pre>
      </div>
    <?php endif ?>

    <?php if ($runResult !== null && !empty($runResult)): ?>
    <!-- ── 4. Results ─────────────────────────────────────────────────────── -->
    <?php
      $totalRows      = count($runResult);
      $splitCount     = count(array_filter($runResult, static fn($r) => ($r['SplitFlag'] ?? '') === 'SPLIT'));
      $lineCount      = count(array_unique(array_column($runResult, 'Line')));
      $totalSchedQty  = array_sum(array_column($runResult, 'ScheduledQty'));
      $totalExcess    = array_sum(array_column($runResult, 'Excess Std Pack'));
    ?>
    <div class="alert alert-success mb-3">
      ✅ Done — <?= fmtNum($totalRows) ?> rows
    </div>

    <div class="row g-2 mb-3">
      <?php foreach ([
        ['Output rows',          fmtNum($totalRows)],
        ['Split orders',         fmtNum($splitCount)],
        ['Lines',                $lineCount],
        ['Total Sched. Qty',     fmtNum($totalSchedQty)],
        ['Total Excess Std Pack',fmtNum($totalExcess)],
      ] as [$lbl, $val]): ?>
      <div class="col">
        <div class="card text-center py-2">
          <div class="card-body py-1 px-2">
            <div class="fw-bold fs-5"><?= esc($val) ?></div>
            <div class="text-muted small"><?= esc($lbl) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach ?>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────────────────────── -->
    <ul class="nav nav-tabs mb-3" id="mainTabs">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabSchedule">📊 Schedule</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabMA3">📈 MA3 Summary</a></li>
      <?php if ($bomData): ?>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBomDemand">📦 Component Demand</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabBomCapability">🏗️ Build Capability</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabShortage">⚠️ Shortage</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabRmCoverage">📋 RM Coverage</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabLineOutput">🏭 Line Output</a></li>
      <?php endif ?>
    </ul>

    <div class="tab-content">

      <!-- ── Tab: Schedule ───────────────────────────────────────────────── -->
      <div class="tab-pane fade show active" id="tabSchedule">
        <h6>Summary — Line × Production Week</h6>
        <?php if ($weekPivot && !empty($weekPivot['rows'])): ?>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered table-hover small">
            <thead class="table-dark">
              <tr>
                <th>Line</th><th>Line Name</th>
                <?php foreach ($weekPivot['weeks'] as $wk): ?><th><?= esc($wk) ?></th><?php endforeach ?>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($weekPivot['rows'] as $row): ?>
              <tr>
                <td><?= esc($row['Line']) ?></td>
                <td><?= esc($row['Line Name']) ?></td>
                <?php foreach ($weekPivot['weeks'] as $wk): ?>
                <td class="text-end"><?= fmtNum($row[$wk] ?? 0) ?></td>
                <?php endforeach ?>
                <td class="text-end fw-bold"><?= fmtNum($row['Total'] ?? 0) ?></td>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php endif ?>

        <h6>Download</h6>
        <form method="post" class="d-flex gap-2">
          <button type="submit" name="download" value="result" class="btn btn-sm btn-outline-success">⬇️ Excel (.xlsx)</button>
          <button type="submit" name="download" value="result_csv" class="btn btn-sm btn-outline-secondary">⬇️ CSV</button>
        </form>

        <details class="mt-3">
          <summary class="fw-semibold text-primary">📄 Full Result (first 200 rows)</summary>
          <div class="table-responsive mt-2" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-bordered small">
              <thead class="table-light sticky-top">
                <tr>
                  <?php foreach (array_keys($runResult[0] ?? []) as $col): ?>
                  <th><?= esc($col) ?></th>
                  <?php endforeach ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($runResult, 0, 200) as $row): ?>
                <tr class="<?= ($row['SplitFlag'] ?? '') === 'SPLIT' ? 'table-warning' : '' ?>">
                  <?php foreach ($row as $v): ?>
                  <td><?= $v instanceof \DateTime ? esc($v->format('Y-m-d')) : esc($v) ?></td>
                  <?php endforeach ?>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>

      <!-- ── Tab: MA3 Summary ─────────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabMA3">
        <?php if ($ma3Data): ?>
          <?php $ma3Months = $ma3Data['months'] ?? []; ?>
          <?php foreach ([
            ['MA3 Pieces (Scheduled Qty)', 'Pieces', 0],
            ['MA3 Hours (Total Hours)',    'Hours',  1],
            ['MA3 Sales (Total Value $)',  'Value',  2],
          ] as [$title, $key, $dec]): ?>
          <h6><?= esc($title) ?></h6>
          <?php if (!empty($ma3Data[$key])): ?>
          <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered small">
              <thead class="table-dark">
                <tr>
                  <th>Line</th><th>Line Name</th>
                  <?php foreach ($ma3Months as $m): ?><th><?= esc($m) ?></th><?php endforeach ?>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ma3Data[$key] as $row): ?>
                <tr>
                  <td><?= esc($row['Line']) ?></td>
                  <td><?= esc($row['Line Name']) ?></td>
                  <?php foreach ($ma3Months as $m): ?>
                  <td class="text-end"><?= $dec === 2 ? '$' . fmtNum($row[$m] ?? 0, 2) : fmtNum($row[$m] ?? 0, $dec) ?></td>
                  <?php endforeach ?>
                  <td class="text-end fw-bold"><?= $dec === 2 ? '$' . fmtNum($row['Total'] ?? 0, 2) : fmtNum($row['Total'] ?? 0, $dec) ?></td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p class="text-muted small">No data (ProductionWeekDate or month columns missing).</p>
          <?php endif ?>
          <?php endforeach ?>
          <form method="post">
            <button type="submit" name="download" value="ma3" class="btn btn-sm btn-outline-success">⬇️ MA3 Summary Excel</button>
          </form>
        <?php endif ?>
      </div>

      <?php if ($bomData): ?>

      <!-- ── Tab: Component Demand ─────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabBomDemand">
        <?php
          $dp = $bomData['demandPivotData'];
          $dpRows  = $dp['rows']  ?? [];
          $dpWeeks = $dp['weeks'] ?? [];
          $nFgs    = count(array_unique(array_column($bomData['explodedBase'], 'FG')));
          $nComp   = count(array_unique(array_column($bomData['explodedBase'], 'Component')));
          $totalD  = array_sum(array_column($bomData['explodedBase'], 'Comp_Demand'));
        ?>
        <div class="row g-2 mb-3">
          <?php foreach ([['Matched FGs', $nFgs], ['Unique Components', $nComp], ['Total Comp. Demand', fmtNum($totalD)]] as [$l, $v]): ?>
          <div class="col-auto">
            <div class="card text-center py-2 px-3">
              <div class="fw-bold"><?= esc($v) ?></div>
              <div class="text-muted small"><?= esc($l) ?></div>
            </div>
          </div>
          <?php endforeach ?>
        </div>
        <h6>Raw Material Demand — Component × Production Week</h6>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
          <table class="table table-sm table-bordered small">
            <thead class="table-dark sticky-top">
              <tr>
                <th>Component</th><th>Description</th>
                <?php foreach ($dpWeeks as $wk): ?><th>Wk <?= esc($wk) ?></th><?php endforeach ?>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dpRows as $row): ?>
              <tr>
                <td><?= esc($row['Component']) ?></td>
                <td><?= esc($row['Component_Desc']) ?></td>
                <?php foreach ($dpWeeks as $wk): ?>
                <td class="text-end"><?= fmtNum($row[$wk] ?? 0) ?></td>
                <?php endforeach ?>
                <td class="text-end fw-bold"><?= fmtNum($row['Total'] ?? 0) ?></td>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── Tab: Build Capability ─────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabBomCapability">
        <?php $cap = $bomData['capabilityRows']; ?>
        <?php if (!empty($cap)): ?>
          <?php
            $totalBld  = array_sum(array_column($cap, 'Buildable_Qty'));
            $totalSch  = array_sum(array_column($cap, 'FG_Scheduled_Qty'));
            $avgCov    = count($cap) > 0 ? array_sum(array_column($cap, 'Material_Coverage_Pct')) / count($cap) : 0;
            $totalCost = array_sum(array_column($cap, 'Total_Cost'));
          ?>
          <div class="row g-2 mb-3">
            <?php foreach ([
              ['Total Scheduled Qty', fmtNum($totalSch)],
              ['Total Buildable Qty', fmtNum($totalBld)],
              ['Avg Coverage %',      number_format($avgCov, 1) . '%'],
              ['Total Cost',          '$' . fmtNum($totalCost)],
            ] as [$l, $v]): ?>
            <div class="col-auto">
              <div class="card text-center py-2 px-3">
                <div class="fw-bold"><?= esc($v) ?></div>
                <div class="text-muted small"><?= esc($l) ?></div>
              </div>
            </div>
            <?php endforeach ?>
          </div>
          <div class="table-responsive" style="max-height:500px;overflow-y:auto">
            <table class="table table-sm table-bordered small">
              <thead class="table-dark sticky-top">
                <tr>
                  <?php $capCols = ['Part_Number','FG_Description','Line','Line Name','ProductionWeek',
                    'FG_Scheduled_Qty','Buildable_Qty','Material_Coverage_Pct',
                    'Constraint_Component','Total_Shortage','Std_Cost','Cost_Effective_Date','Total_Cost']; ?>
                  <?php foreach ($capCols as $c): ?><th><?= esc($c) ?></th><?php endforeach ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cap as $row): ?>
                <?php $cov = (float) ($row['Material_Coverage_Pct'] ?? 100); ?>
                <tr>
                  <?php foreach ($capCols as $c): ?>
                  <td <?= in_array($c, ['FG_Scheduled_Qty','Buildable_Qty','Total_Shortage','Std_Cost','Total_Cost'], true) ? 'class="text-end"' : '' ?>
                      <?= ($c === 'Material_Coverage_Pct') ? 'style="background:' . esc(pctColor($cov)) . '"' : '' ?>>
                    <?php $v = $row[$c] ?? ''; ?>
                    <?php if ($c === 'Material_Coverage_Pct'): ?>
                      <?= esc(number_format((float)$v, 1)) ?>%
                    <?php elseif (in_array($c, ['FG_Scheduled_Qty','Buildable_Qty','Total_Shortage'], true)): ?>
                      <?= fmtNum($v) ?>
                    <?php elseif (in_array($c, ['Std_Cost','Total_Cost'], true)): ?>
                      $<?= fmtNum($v, 2) ?>
                    <?php else: ?>
                      <?= $v instanceof \DateTime ? esc($v->format('Y-m-d')) : esc($v) ?>
                    <?php endif ?>
                  </td>
                  <?php endforeach ?>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted">No capability data. Ensure BOM and scheduled result are available.</p>
        <?php endif ?>
      </div>

      <!-- ── Tab: Shortage ─────────────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabShortage">
        <?php $sh = $bomData['shortageData']; $shRows = $sh['rows'] ?? []; $shWks = $sh['weeks'] ?? []; ?>
        <?php if (!empty($shRows)): ?>
          <div class="alert alert-warning py-1">⚠️ <?= count($shRows) ?> component(s) with shortages</div>
          <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm table-bordered small">
              <thead class="table-dark sticky-top">
                <tr><th>Component</th><th>Description</th>
                  <?php foreach ($shWks as $wk): ?><th>Wk <?= esc($wk) ?></th><?php endforeach ?>
                  <th>Total Shortage</th></tr>
              </thead>
              <tbody>
                <?php foreach ($shRows as $row): ?>
                <tr>
                  <td><?= esc($row['Component']) ?></td>
                  <td><?= esc($row['Component_Desc']) ?></td>
                  <?php foreach ($shWks as $wk): ?>
                  <td class="text-end <?= ($row[$wk] ?? 0) > 0 ? 'table-danger' : '' ?>"><?= fmtNum($row[$wk] ?? 0) ?></td>
                  <?php endforeach ?>
                  <td class="text-end fw-bold table-danger"><?= fmtNum($row['Total Shortage'] ?? 0) ?></td>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="alert alert-success">✅ No shortages — component demand is fully covered.</div>
        <?php endif ?>
      </div>

      <!-- ── Tab: RM Coverage ──────────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabRmCoverage">
        <?php
          $rmc      = $bomData['rmCovData'];
          $covRows  = $rmc['coverage'] ?? [];
          $kpiRows  = $rmc['kpi']      ?? [];
          $covWeeks = $rmc['weeks']    ?? [];
          $atRisk   = count(array_filter($kpiRows, static fn($r) => ($r['Pct_Weeks_Covered'] ?? 100) < 100));
          $peakTot  = array_sum(array_column($kpiRows, 'Peak_Shortage'));
        ?>
        <?php if (!empty($covRows)): ?>
          <div class="row g-2 mb-3">
            <div class="col-auto"><div class="card px-3 py-2 text-center">
              <div class="fw-bold"><?= $atRisk ?> / <?= count($kpiRows) ?></div>
              <div class="text-muted small">Materials at Risk</div>
            </div></div>
            <div class="col-auto"><div class="card px-3 py-2 text-center">
              <div class="fw-bold"><?= fmtNum($peakTot) ?></div>
              <div class="text-muted small">Total Peak Shortage</div>
            </div></div>
          </div>

          <h6>Coverage Table</h6>
          <div class="table-responsive" style="max-height:500px;overflow-y:auto">
            <table class="table table-sm table-bordered small">
              <thead class="table-dark sticky-top">
                <tr><th>Part Number</th><th>Description</th><th>UOM</th><th>Init. Inv.</th><th>Metric</th>
                  <?php foreach ($covWeeks as $wk): ?><th><?= esc(is_int($wk) ? "Wk {$wk}" : $wk) ?></th><?php endforeach ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($covRows as $row):
                  $metric  = $row['Metric'] ?? '';
                  $isPnRow = trim((string) ($row['Component'] ?? '')) !== '';
                  $trClass = $isPnRow ? 'coverage-part' : '';
                ?>
                <tr class="<?= esc($trClass) ?>">
                  <td><?= esc($row['Component']) ?></td>
                  <td><?= esc($row['Component_Desc']) ?></td>
                  <td><?= esc($row['UOM']) ?></td>
                  <td class="text-end"><?= $row['Initial_Inventory'] !== null ? fmtNum($row['Initial_Inventory']) : '' ?></td>
                  <td class="fw-semibold"><?= esc($metric) ?></td>
                  <?php foreach ($covWeeks as $wk):
                    $v = $row[$wk] ?? '';
                    $cellClass = '';
                    if ($metric === 'Ending Balance' && is_numeric($v)) {
                        if ((float)$v < 0)       $cellClass = 'cell-red';
                        elseif ((float)$v > 0)   $cellClass = 'cell-green';
                        else                     $cellClass = 'cell-gray';
                    } elseif ($metric === 'Receipts' && is_numeric($v) && (float)$v > 0) {
                        $cellClass = 'cell-receipt';
                    }
                  ?>
                  <td class="text-end <?= esc($cellClass) ?>"><?= is_numeric($v) ? fmtNum($v) : esc($v) ?></td>
                  <?php endforeach ?>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>

          <details class="mt-3">
            <summary class="fw-semibold text-primary">📊 KPI Summary</summary>
            <div class="table-responsive mt-2">
              <table class="table table-sm table-bordered small">
                <thead class="table-dark">
                  <tr><th>Component</th><th>Description</th><th>Init. Inv.</th>
                    <th>First Shortage Wk</th><th>% Weeks Covered</th>
                    <th>Peak Shortage</th><th>Net Deficit Wks</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($kpiRows as $row):
                    $pct = (float) ($row['Pct_Weeks_Covered'] ?? 100);
                  ?>
                  <tr>
                    <td><?= esc($row['Component']) ?></td>
                    <td><?= esc($row['Description']) ?></td>
                    <td class="text-end"><?= fmtNum($row['Initial_Inventory'] ?? 0) ?></td>
                    <td><?= esc($row['First_Shortage_Week'] ?? '—') ?></td>
                    <td class="text-end" style="background:<?= esc(pctColor($pct)) ?>"><?= number_format($pct, 1) ?>%</td>
                    <td class="text-end"><?= fmtNum($row['Peak_Shortage'] ?? 0) ?></td>
                    <td class="text-end"><?= fmtNum($row['Net_Deficit_Weeks'] ?? 0) ?></td>
                  </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>
          </details>

          <form method="post" class="mt-2">
            <button type="submit" name="download" value="rm_coverage" class="btn btn-sm btn-outline-success">⬇️ RM Coverage Excel (Formatted)</button>
          </form>
        <?php else: ?>
          <p class="text-muted">No coverage data. Ensure BOM + Stock/RM_PO sheets are loaded.</p>
        <?php endif ?>
      </div>

      <!-- ── Tab: Line Output Plan ─────────────────────────────────────────── -->
      <div class="tab-pane fade" id="tabLineOutput">
        <?php
          $lo      = $bomData['lineOutputData'];
          $loRows  = $lo['plan'] ?? [];
          $loKpi   = $lo['kpi']  ?? [];
          $loWeeks = $lo['weeks'] ?? [];
          $totPl   = array_sum(array_column($loKpi, 'Total_Planned'));
          $totOut  = array_sum(array_column($loKpi, 'Total_FG_Output'));
          $totSh   = array_sum(array_column($loKpi, 'Total_Shortage_Pcs'));
          $linesRisk = count(array_filter($loKpi, static fn($r) => ($r['Total_Shortage_Pcs'] ?? 0) > 0));
          $pctGlobal = $totPl > 0 ? round($totOut / $totPl * 100, 1) : 100.0;
        ?>
        <?php if (!empty($loRows)): ?>
          <div class="row g-2 mb-3">
            <?php foreach ([
              ['Total Planned',      fmtNum($totPl)],
              ['Total FG Output',    fmtNum($totOut)],
              ['Total Shortage Pcs', fmtNum($totSh)],
              ['Lines at Risk',      "{$linesRisk} / " . count($loKpi) . " ({$pctGlobal}% achievable)"],
            ] as [$l, $v]): ?>
            <div class="col-auto"><div class="card px-3 py-2 text-center">
              <div class="fw-bold"><?= esc($v) ?></div>
              <div class="text-muted small"><?= esc($l) ?></div>
            </div></div>
            <?php endforeach ?>
          </div>

          <h6>Production Output — Line × Production Week</h6>
          <div class="table-responsive" style="max-height:500px;overflow-y:auto">
            <table class="table table-sm table-bordered small">
              <thead class="table-dark sticky-top">
                <tr><th>Line</th><th>Line Name</th><th>Metric</th>
                  <?php foreach ($loWeeks as $wk): ?><th><?= esc($wk) ?></th><?php endforeach ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loRows as $row):
                  $metric = $row['Metric'] ?? '';
                  $trClass = match ($metric) {
                    'FG Output' => 'output-fg',
                    'Shortage'  => 'output-shortage',
                    default     => 'output-planned',
                  };
                ?>
                <tr class="<?= esc($trClass) ?>">
                  <td><?= esc($row['Line']) ?></td>
                  <td><?= esc($row['Line_Name']) ?></td>
                  <td class="fw-semibold"><?= esc($metric) ?></td>
                  <?php foreach ($loWeeks as $wk): ?>
                  <td class="text-end"><?= ($row[$wk] ?? 0) > 0 ? fmtNum($row[$wk]) : ($metric === 'Shortage' ? '' : fmtNum($row[$wk] ?? 0)) ?></td>
                  <?php endforeach ?>
                </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>

          <details class="mt-3">
            <summary class="fw-semibold text-primary">📊 Line KPI Breakdown</summary>
            <div class="table-responsive mt-2">
              <table class="table table-sm table-bordered small">
                <thead class="table-dark">
                  <tr><th>Line</th><th>Line Name</th><th>Planned</th><th>FG Output</th>
                    <th>Shortage Pcs</th><th>% Achievable</th><th>First Shortage Wk</th><th>Recovery Wk</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($loKpi as $row):
                    $pct = (float) ($row['Pct_Achievable'] ?? 100);
                  ?>
                  <tr>
                    <td><?= esc($row['Line']) ?></td>
                    <td><?= esc($row['Line_Name']) ?></td>
                    <td class="text-end"><?= fmtNum($row['Total_Planned'] ?? 0) ?></td>
                    <td class="text-end"><?= fmtNum($row['Total_FG_Output'] ?? 0) ?></td>
                    <td class="text-end <?= ($row['Total_Shortage_Pcs'] ?? 0) > 0 ? 'table-danger' : '' ?>"><?= fmtNum($row['Total_Shortage_Pcs'] ?? 0) ?></td>
                    <td class="text-end fw-bold" style="background:<?= esc(pctColor($pct)) ?>"><?= number_format($pct, 1) ?>%</td>
                    <td><?= esc($row['First_Shortage_Week'] ?? '—') ?></td>
                    <td><?= esc($row['Recovery_Week'] ?? '—') ?></td>
                  </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>
          </details>

          <form method="post" class="mt-2">
            <button type="submit" name="download" value="line_output" class="btn btn-sm btn-outline-success">⬇️ Line Output Plan Excel (Formatted)</button>
          </form>
        <?php else: ?>
          <p class="text-muted">No line output data.</p>
        <?php endif ?>
      </div>

      <?php endif // bomData ?>

    </div><!-- /.tab-content -->
    <?php endif // runResult ?>
    <?php endif // sheets available ?>

  </div><!-- /.col-md-9 -->
</div><!-- /.row -->
</div><!-- /.container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sync sidebar settings into the run form
document.addEventListener('DOMContentLoaded', function () {
    const mainForm = document.getElementById('mainForm');
    const runForm  = document.getElementById('runForm');
    if (!mainForm || !runForm) return;
    const sidebarFields = ['base_week','col_qty','col_line','col_req_date','col_commit_date','col_std_pack'];
    sidebarFields.forEach(function(name) {
        const src = mainForm.querySelector('[name="' + name + '"]');
        const dst = runForm.querySelector('[name="' + name + '"]');
        if (src && dst) {
            src.addEventListener('input', function() { dst.value = src.value; });
        }
    });
});
</script>
</body>
</html>
