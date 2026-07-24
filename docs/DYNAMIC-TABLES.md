# Dynamic Tables (v0.9.14)

Ezdoc menyediakan **2 sistem tabel dinamis** untuk template dokumen:

| System | Marker | Use case | Since |
|--------|--------|----------|-------|
| **Row-repeating** | `{{tabledb.<ns>.<col>}}` | Clone satu `<tr>` template per row hasil SQL | v0.9.7 |
| **Report tables** | `{{report.<ns>}}` | Full `<table>` dgn typed columns, formula, aggregate, subtotal, grand total | **v0.9.14** |

Doc ini fokus ke **Report Tables** (v0.9.14). Untuk row-repeating pattern, lihat inline docs di `views/document/generate.php` (`processTabledbRows`).

## Table of Contents

- [Overview](#overview)
- [Quick start](#quick-start)
- [Schema reference](#schema-reference)
- [Column types](#column-types)
- [Aggregates](#aggregates)
- [Formulas](#formulas)
- [Group-by + subtotals](#group-by--subtotals)
- [Formatter (locale + currency)](#formatter-locale--currency)
- [Safety](#safety)
- [Architecture](#architecture)
- [FAQ / Non-goals](#faq--non-goals)

---

## Overview

Report Tables adalah Excel/Jasper-style renderer untuk laporan tabular di template dokumen. Author config satu kali di `$configHeader['reportTables']`, tempatkan marker `{{report.namespace}}` di HTML content, dan sistem render:

- **Typed columns** dgn format konsisten (currency IDR, number, percent, date, dst)
- **Computed columns** via formula per-row (misal `{qty} * {harga}`)
- **Aggregate footer** (`<tfoot>`) dgn SUM/AVG/COUNT/MIN/MAX per kolom
- **Group-by** dgn subtotal per group + grand total
- **Locale-aware** formatting (Rp untuk IDR, `Rabu` untuk id, dst)

Rendering **server-side, deterministic, PDF-safe** — no JS runtime, no external dep.

---

## Quick start

**1. Config di template's `configHeader`** (JSON stored di `ezdoc_templates` row):

```php
$configHeader['reportTables'] = [
    'penjualan' => [
        'sql' => "SELECT kategori, nama, qty, harga
                  FROM stok_keluar
                  WHERE norm = {norm}
                  ORDER BY kategori",
        'columns' => [
            ['field' => 'kategori', 'label' => 'Kategori'],
            ['field' => 'nama', 'label' => 'Item'],
            ['field' => 'qty', 'type' => 'number'],
            ['field' => 'harga', 'type' => 'currency'],
            [
                'field' => 'total',
                'label' => 'Total',
                'type' => 'currency',
                'formula' => '{qty} * {harga}',
                'aggregate' => 'sum'
            ],
        ],
        'groupBy' => 'kategori',
        'showGrandTotal' => true,
    ],
];
```

**2. Marker di template HTML content**:

```html
<p><strong>Laporan Penjualan</strong></p>
{{report.penjualan}}
```

**3. Render** (generate view) → marker replaced by full `<table>` HTML.

---

## Schema reference

### Namespace-level

```php
[
    'sql' => string,                    // required — SELECT statement (WITH juga OK)
    'columns' => array<ColumnDef>,      // required — at least 1
    'groupBy' => string|array|null,     // optional
    'showGrandTotal' => bool,           // optional, default true
    'emptyMessage' => string,           // optional, default 'Belum ada data'
    'locale' => string,                 // optional, default 'id'
    'tableClass' => string,             // optional, default 'dyntable-rendered'
]
```

### `sql` — SELECT-only

- Prepared statement dgn `{param}` placeholder syntax
- Auto-bind: `{nopen}` → `$param_nopen`, `{norm}` → `$param_norm`, `{field_name}` → `$dbFields[field_name]`
- **SELECT-only guarded** — INSERT/UPDATE/DELETE/DROP/dst REJECTED (via existing `executeDynQuery` di generate.php)

### `columns[]` — see [Column types](#column-types) below

### `groupBy` — 3 acceptable forms

```php
'groupBy' => null,                                         // no grouping (flat rows)
'groupBy' => 'kategori',                                   // simple: group by field, subtotal ON
'groupBy' => ['field' => 'kategori', 'showSubtotal' => true],  // detailed
```

**Rows harus sudah sorted by group field di SQL** (renderer tidak re-sort). Add `ORDER BY kategori` di SQL.

---

## Column types

| Type | Alignment default | Formatter output example |
|------|-------------------|---------------------------|
| `text` | left | HTML-escaped raw value |
| `number` | right | `1.500.000` (id) / `1,500,000` (en) |
| `currency` | right | `Rp 1.500.000` (IDR) / `$1,500,000.00` (USD) / `€ 1.500.000,00` (EUR) |
| `percent` | right | `50,00%` (ratio input) atau `50%` (raw input) |
| `date` | left | `24/07/2026` (default) atau any PHP date format |
| `datetime` | left | `24/07/2026 14:30` (default) |

### Per-column options

```php
[
    'field' => 'harga',              // required — SQL column name (must match [a-zA-Z_][a-zA-Z0-9_]*)
    'label' => 'Harga Satuan',       // optional — header text (default: field name)
    'type' => 'currency',            // one of column types above (default 'text')
    'align' => 'right',              // 'left'|'right'|'center' (default per type)
    'aggregate' => 'sum',            // 'sum'|'count'|'avg'|'min'|'max'|null
    'formula' => '{qty} * {harga}',  // safe expression (see Formulas below)
    'format' => 'd/m/Y',             // type-specific format override
    'decimals' => 2,                 // precision override
    'currency' => 'IDR',             // only for type=currency (default 'IDR')
]
```

---

## Aggregates

5 aggregate types supported di footer + subtotal rows:

| Aggregate | Behavior | Requires numeric type |
|-----------|----------|----------------------|
| `sum` | Sum of non-null numeric values | Yes |
| `avg` | Average (SUM / non-null count) | Yes |
| `min` | Minimum value | Yes |
| `max` | Maximum value | Yes |
| `count` | Count of non-null values | No (any type) |

### Aggregate footer rendering

- **First cell TANPA aggregate** → jadi "TOTAL" label (grand total row) atau "Subtotal <group>:" label (subtotal row)
- **Cells WITH aggregate** → show computed value
- **Cells TANPA aggregate** (setelah label position) → empty

### Null-safe

Rows dgn `null` / empty string / non-numeric value di aggregate column → **skipped** dari sum/avg/min/max. AVG divisor = non-null count. Empty rows result → footer shows `-` (dash), bukan misleading `0`.

---

## Formulas

Computed columns via safe expression parser (shunting-yard, no `eval`).

### Grammar

```
expr    := term (('+' | '-') term)*
term    := factor (('*' | '/') factor)*
factor  := NUMBER | FIELD_REF | '(' expr ')'
NUMBER  := digit+ ('.' digit+)?
FIELD_REF := '{' [a-zA-Z_][a-zA-Z0-9_]* '}'
```

### Allowed

- 4 operators: `+ - * /`
- Parentheses `( )`
- Number literals (integer atau decimal)
- Field references `{field_name}` (referring ke SQL column atau earlier formula column dalam same row)

### Runtime semantics

| Input | Output |
|-------|--------|
| Missing field ref | `null` (row skipped di aggregate) |
| Field value is `null` / `''` | `null` |
| Field value non-numeric | `null` |
| Division by zero | `null` (safe, no throw) |
| Syntax error | `ValidationException` at parse time (caught di designer save) |

### Examples

```
{qty} * {harga}                          // → total per row
{harga} * {qty} * (1 - {diskon} / 100)   // → total setelah diskon %
{subtotal} * 11 / 100                    // → PPN 11%
({harga_jual} - {harga_beli}) / {harga_beli}  // → profit margin ratio
```

### Chained formulas

Kolom formula bisa reference kolom formula LAIN dari same row, **asalkan urutan di config sudah benar** (dependency column harus SEBELUM dependent column).

---

## Group-by + subtotals

```php
'groupBy' => 'kategori',
```

**Requirements**:
- SQL harus `ORDER BY kategori` (renderer tidak re-sort)
- Group field bisa column type apa saja (biasanya text)

**Rendering**:
- Setiap kali group value change → emit subtotal row untuk previous group
- Subtotal computed per kolom yg punya `aggregate` (SUM/AVG/dst)
- Kolom non-aggregate → cell kosong di subtotal row
- First non-aggregate column jadi label position: `<em>Subtotal Obat:</em>`

**Multi-level nested groupBy** (grouping by 2+ fields) **NOT supported di v0.9.14** — defer ke milestone berikutnya. Workaround: gunakan compound key di SQL (concat field1 + '/' + field2 as group_key).

**Disable subtotal** kalau cuma perlu visual grouping (border between groups yg identical values):

```php
'groupBy' => ['field' => 'kategori', 'showSubtotal' => false],
```

---

## Formatter (locale + currency)

### Currency codes supported (built-in)

| Code | Symbol | Thousands sep | Decimal sep | Default decimals |
|------|--------|---------------|-------------|------------------|
| `IDR` | `Rp ` | `.` | `,` | 0 |
| `USD` | `$` | `,` | `.` | 2 |
| `EUR` | `€ ` | `.` | `,` | 2 |
| `SGD` | `S$` | `,` | `.` | 2 |
| `MYR` | `RM ` | `,` | `.` | 2 |

### Locale

- `id` (default) — Indonesian: `.` thousands, `,` decimals, weekday `Rabu` etc.
- `en` — English: `,` thousands, `.` decimals, weekday `Wednesday`

Set di namespace config: `'locale' => 'en'`.

### Date formats

Any PHP `date()` format spec. Locale-aware weekday/month names via `Ezdoc\Format\DateFormatter::localize()`:

```
'd/m/Y'         → 24/07/2026
'l, d F Y'      → Rabu, 22 Juli 2026 (id) / Wednesday, 22 July 2026 (en)
'Y-m-d H:i:s'   → 2026-07-24 14:30:00
```

---

## Safety

### FormulaEvaluator injection guards

Grammar HANYA allow: digits, `+ - * /`, parens, `{name}` refs. Everything else REJECTED at tokenize:

| Attack | Rejected at | Reason |
|--------|-------------|--------|
| `system('rm -rf /')` | tokenize | `s` = invalid char |
| `{system('rm')}` | field regex | not `[a-zA-Z_][a-zA-Z0-9_]*` |
| `{obj}.prop` | tokenize | `.` outside number = invalid |
| `'malicious'` | tokenize | `'` = invalid char |
| `1 + 2; 3` | tokenize | `;` = invalid char |
| `` `whoami` `` | tokenize | backtick = invalid char |

### HTML escape

- **All column values** pass through `htmlspecialchars(ENT_QUOTES | ENT_HTML5)` di Formatter
- **Column labels** juga escaped
- **Group value** di subtotal label escaped
- XSS via user data (from SQL) → safe

### SQL injection

- SQL statement itself masih author-defined (bukan user-input) → author responsibility
- `{param}` placeholders → prepared statement bindings (existing `executeDynQuery` guard)
- SELECT-only guard reject INSERT/UPDATE/DELETE/DROP/dst

---

## Architecture

```
generate.php renderContent()
    |
    v processReportTables($html, $reportTables, ...)
    |
    v foreach {{report.<ns>}} marker:
      |
      v executeDynQuery($cfg['sql'], $params) → array of rows
      |
      v Ezdoc\Table\ReportTable::fromArray([
          'columns' => $cfg['columns'],
          'rows' => $rows,
          'groupBy' => $cfg['groupBy'],
          'showGrandTotal' => $cfg['showGrandTotal'],
        ])
      |
      v $table->render() → <table>...</table>
```

### Class dependency graph

```
ReportTable
  ├── ColumnDef        (typed column definition + validation)
  ├── FormulaEvaluator (safe expression parser, shunting-yard RPN)
  └── Formatter        (currency/number/percent/date/HTML)
       └── DateFormatter (locale-aware weekday/month, from Ezdoc\Format)
```

### File locations

- `src/Table/ReportTable.php` — main renderer
- `src/Table/ColumnDef.php` — typed column value object
- `src/Table/Formatter.php` — locale-aware formatting
- `src/Table/FormulaEvaluator.php` — safe expression parser
- `src/Format/DateFormatter.php` — locale weekday/month (v0.9.10)

---

## FAQ / Non-goals

### Q: Can I do VLOOKUP-style cross-table references?

**No.** Formulas are row-local only. Use SQL `JOIN` di `sql` config untuk cross-table data — hasilnya jadi rows biasa yg diproses per row.

### Q: Can users edit table cells interactively?

**No.** Report Tables adalah **read-only snapshot**. Untuk editable forms, use existing `{{field_name}}` placeholders (renderFieldInput).

### Q: Can I do multi-level nested groupBy?

**Not in v0.9.14.** Defer ke milestone berikutnya. Workaround: use compound key di SQL: `CONCAT(field1, '/', field2) AS group_key`.

### Q: Charts/pivot tables?

**No.** Separate concern. If demand → separate milestone (probably ChartJS integration or SVG chart lib).

### Q: Excel .xlsx import/export?

**No.** Ezdoc render → HTML/PDF only. Export to Excel = separate library (PhpSpreadsheet) yg out-of-scope untuk ezdoc core.

### Q: Client-side sorting/filtering/pagination?

**No.** Tables adalah SERVER-RENDERED snapshot. Filter di SQL WHERE, sort di SQL ORDER BY, pagination via SQL LIMIT + additional {{report.<ns>_page2}} etc. Interactive JS tables out-of-scope.

### Q: Formula supports date arithmetic?

**Not yet.** v0.9.14 supports `+ - * /` on numeric only. Date arithmetic (`{tanggal_akhir} - {tanggal_mulai}`) requires date-aware operators — defer ke v0.9.15+.

### Q: Table dgn 10k+ rows performance?

**Recommendation**: pre-aggregate di SQL kalau dataset besar. Rendering 10k+ rows ke HTML → dompdf PDF converter juga slow. Report Tables optimal untuk 10-500 rows/page (executive summary use case).

---

## Related docs

- [SCREEN-PAGINATION.md](SCREEN-PAGINATION.md) — how tables page-break di paged mode
- [LOCALIZATION.md](LOCALIZATION.md) — locale weekday/month via DateFormatter
- [PDF-RENDERING.md](PDF-RENDERING.md) — dompdf compatibility notes
- [VIEWS.md](VIEWS.md) — generate view integration

spec: `src/Table/*` inline docblocks
