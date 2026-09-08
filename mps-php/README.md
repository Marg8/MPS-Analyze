# MPS-PHP

PHP port of the MPS Capacity Scheduler (originally built with Python + Streamlit).

Runs in **XAMPP** (or any PHP 8.1+ web server) with **zero Composer dependencies** —
uses only PHP built-ins (`ext-zip`, `ext-simplexml`) which are enabled by default in XAMPP.

## Features

Identical business logic to the Python version:

| Feature | Details |
|---|---|
| Capacity scheduling | Order-level BIN packing against weekly capacity buckets, std-pack rounding |
| MA3 Summary | Pieces / Hours / Value pivot by Line × Month |
| BOM Analysis | BOM explosion, CTB coverage, shortage report |
| RM Coverage | Weekly MRP simulation: Demand / Receipts / Ending Balance |
| Line Output Plan | Planned vs FG Output vs Shortage per Line × Week |
| Excel export | Formatted multi-sheet .xlsx export (no external library) |
| File upload | Upload any `.xlsx` via browser; or use workspace `MPS.xlsx` |

## Requirements

- PHP 8.1 or higher
- Extensions: `zip`, `simplexml` (both enabled by default in XAMPP)
- **No Composer install required**

## Quick Start — XAMPP

1. Copy the `mps-php/` folder into your XAMPP `htdocs/` directory:

   ```
   C:\xampp\htdocs\mps-php\
   ```

2. Start **Apache** in the XAMPP Control Panel.

3. Open your browser and go to:

   ```
   http://localhost/mps-php/
   ```

4. Upload your `MPS.xlsx` file (or click **Use MPS.xlsx from workspace** if the file
   is in the parent directory).

5. Select sheets, configure settings, and click **▶ Run Query**.

## Quick Start — PHP built-in server (no XAMPP)

```bash
cd mps-php
php -S localhost:8080
```

Then open http://localhost:8080 in your browser.

## Optional: Composer autoloader

A `composer.json` is included. If Composer is available you can run:

```bash
composer dump-autoload
```

to regenerate the autoloader (the `vendor/` directory is already committed with
the generated autoloader — no packages need to be downloaded).

## Directory layout

```
mps-php/
├── index.php          # Main application (UI + controller)
├── composer.json      # PSR-4 autoloader config (no external packages)
├── vendor/            # Generated autoloader only
├── uploads/           # Uploaded Excel files (auto-created, gitignored)
├── assets/
│   └── app.css        # Stylesheet
└── src/
    ├── Helpers.php       # Utility functions (toNumber, toDate, normalizeLine, …)
    ├── ExcelReader.php   # Self-contained xlsx reader (ZipArchive + SimpleXML)
    ├── ExcelExporter.php # Self-contained xlsx writer
    ├── PqLogic.php       # Capacity scheduling engine (port of pq_logic.py)
    └── BomAnalysis.php   # BOM analysis, CTB, RM coverage (port of bom_analysis.py)
```

## Differences from the Python/Streamlit version

| Python (Streamlit) | PHP |
|---|---|
| pandas DataFrames | PHP arrays of associative arrays |
| openpyxl / pandas Excel I/O | Built-in ZipArchive + SimpleXML |
| Streamlit UI widgets | Bootstrap 5 HTML forms + tabs |
| matplotlib charts | Chart.js (CDN, rendered in browser) |
| Session state | PHP `$_SESSION` |

The scheduling algorithms and BOM logic are a faithful 1:1 port.

## XAMPP PHP configuration

No special `php.ini` changes are needed. Verify these extensions are enabled
(they are on by default):

```ini
extension=zip
extension=simplexml
```

Check via: **http://localhost/dashboard/phpinfo.php**

## Security notes

- Uploaded files are stored per-session in `uploads/` and named by session ID.
- Only `.xlsx` files are accepted (MIME type checked server-side).
- The `uploads/` folder is automatically created with `0755` permissions.
- For production use, add authentication and move `uploads/` outside the web root.
