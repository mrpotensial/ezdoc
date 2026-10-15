# Dynamic Tables (v0.9.14b)

Sistem tabel dinamis di ezdoc pakai **enhanced tabledb markers** — user bebas design `<table>` di TinyMCE editor, sisipkan markers di cells untuk data/format/formula/aggregate. Data source (SQL) di-setup sekali via Designer sidebar; presentation (format, formula, aggregate) inline di marker.

**Mental model**: Word Mail Merge fields dgn switches (`\# "Rp #,##0"`) — table structure milik user, marker inject data + format.

## Table of Contents

- [Quick start](#quick-start)
- [Marker anatomy](#marker-anatomy)
- [Selectors](#selectors)
- [Format pipes](#format-pipes)
- [Formula grammar](#formula-grammar)
- [Row classification](#row-classification)
- [Real-world examples](#real-world-examples)
- [Designer shortcuts](#designer-shortcuts)
- [Safety](#safety)
- [Architecture](#architecture)
- [FAQ / Non-goals](#faq--non-goals)

---

## Quick start

**1. Setup data source** (Designer sidebar → **Query DB** panel):
- Namespace: `stok`
- SQL: `SELECT nama, qty, harga FROM stok_keluar WHERE nopen={nopen}`
- Klik **Analyze Query** → deteksi kolom + tampil helper widgets

**2. Insert `<table>` bebas di TinyMCE** (via toolbar Table → Insert Table)

**3. Isi cells dgn markers**:

```html
<table border="1">
  <thead>
    <tr>
      <th>Item</th><th>Qty</th><th>Harga</th><th>Total</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>{{tabledb.stok.nama}}</td>
      <td>{{tabledb.stok.qty}}</td>
      <td>{{tabledb.stok.harga|currency}}</td>
      <td>{{tabledb.stok.=qty*harga|currency}}</td>
    </tr>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="3"><strong>GRAND TOTAL</strong></td>
      <td>{{tabledb.stok.sum:=qty*harga|currency}}</td>
    </tr>
  </tfoot>
</table>
```

**4. Save & Generate** → sistem otomatis:
- tbody row **cloned per SQL row**
- Setiap row: nama/qty raw, `harga` formatted `Rp 500`, `total = qty*harga` computed & formatted
- tfoot row **rendered SEKALI** dgn SUM formula

---

## Marker anatomy

```
{{tabledb[.<namespace>].<selector>[|<format>[:<arg1>[:<arg2>]]]}}
   ─────── ─────────  ──────────  ──────── ────────
       │        │           │          │        │
       │        │           │          │        └─ optional args
       │        │           │          └─ format modifier
       │        │           └─ what to compute
       │        └─ optional namespace (default: 'default')
       └─ literal
```

**Legal characters**:
- Namespace: `[a-zA-Z_][a-zA-Z0-9_]*`
- Selector: freeform up to `|` or `}}`
- Format: freeform up to `}}`

---

## Selectors

| Selector | Type | Example |
|----------|------|---------|
| `col_name` | Raw column | `{{tabledb.s.nama}}` |
| `=<expression>` | Formula (per-row, bare column names) | `{{tabledb.s.=qty*harga}}` |
| `sum:<col>` | SUM aggregate | `{{tabledb.s.sum:harga}}` |
| `sum:=<formula>` | SUM of computed formula | `{{tabledb.s.sum:=qty*harga}}` |
| `avg:<col>` | AVG aggregate | `{{tabledb.s.avg:nilai}}` |
| `count:*` | COUNT all rows | `{{tabledb.s.count:*}}` |
| `count:<col>` | COUNT non-null | `{{tabledb.s.count:harga}}` |
| `min:<col>` / `max:<col>` | MIN/MAX | `{{tabledb.s.min:harga}}` |

---

## Format pipes

| Pipe | Default | Example output |
|------|---------|----------------|
| `\|text` | HTML escape (default when no pipe) | `<script>` → `&lt;script&gt;` |
| `\|number[:decimals]` | 0 decimals | `1500000` → `1.500.000` |
| `\|currency[:code]` | IDR | `1500000` → `Rp 1.500.000` |
| `\|percent[:decimals]` | 2 decimals, as-ratio | `0.5` → `50,00%` |
| `\|date[:format]` | `d/m/Y` | `2026-07-24` → `24/07/2026` |
| `\|datetime[:format]` | `d/m/Y H:i` | Timestamp → `24/07/2026 14:30` |

**Currency codes**: IDR, USD, EUR, SGD, MYR (built-in).
**Locale**: Indonesian default (dot thousands, comma decimals, Rabu weekday). Set global locale via configHeader.

---

## Formula grammar

Safe expression parser (shunting-yard, no `eval`). Grammar:

```
expr    := term (('+' | '-') term)*
term    := factor (('*' | '/') factor)*
factor  := NUMBER | IDENTIFIER | '(' expr ')'
```

**Bare identifier** = column name dari SQL row. `qty*harga` → looks up `qty` and `harga` columns per row.

**Runtime semantics**:

| Input | Output |
|-------|--------|
| Missing field ref | `null` (cell renders `—`) |
| Field value `null` / `''` | `null` |
| Field value non-numeric | `null` |
| Division by zero | `null` (safe) |
| Syntax error | Visible red error indicator in cell |

**Examples**:
- `qty*harga` — total per row
- `harga*(1-diskon/100)` — after discount
- `subtotal*11/100` — PPN 11%
- `(jual-beli)/beli` — profit margin ratio

---

## Row classification

Setiap `<tr>` classified **otomatis** based on markers-nya:

| Row markers | Class | Behavior |
|-------------|-------|----------|
| Semua raw col OR formula | `clone` | **tbody row cloned N kali** (N = data rows) |
| Semua aggregate | `aggregate` | **tfoot row rendered SEKALI** (footer style) |
| Mixed | `clone` | Aggregate evaluated with row context (rarely useful) |
| No markers | (unchanged) | Row as-is (thead labels, statik cells) |

**Convention**: raw/formula markers di `<tbody>`, aggregate markers di `<tfoot>`. Ini bukan HTML validation, cuma convention untuk readability.

---

## Real-world examples

### Invoice with subtotal

```html
<table>
  <thead>
    <tr><th>Item</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>{{tabledb.item.nama}}</td>
      <td>{{tabledb.item.qty}}</td>
      <td>{{tabledb.item.harga|currency}}</td>
      <td>{{tabledb.item.=qty*harga|currency}}</td>
    </tr>
  </tbody>
  <tfoot>
    <tr><td colspan="3">Subtotal</td><td>{{tabledb.item.sum:=qty*harga|currency}}</td></tr>
    <tr><td colspan="3">PPN 11%</td><td>{{tabledb.item.sum:=qty*harga*0.11|currency}}</td></tr>
    <tr><td colspan="3"><strong>Grand Total</strong></td><td>{{tabledb.item.sum:=qty*harga*1.11|currency}}</td></tr>
  </tfoot>
</table>
```

### Discount calculation

```html
<tr>
  <td>{{tabledb.p.nama}}</td>
  <td>{{tabledb.p.harga|currency}}</td>
  <td>{{tabledb.p.diskon|percent:0:false}}</td>
  <td>{{tabledb.p.=harga*(1-diskon/100)|currency}}</td>
</tr>
```

### Multi-metrics footer

```html
<tfoot>
  <tr><td>Jumlah Item</td><td>{{tabledb.s.count:*}}</td></tr>
  <tr><td>Rata-rata Harga</td><td>{{tabledb.s.avg:harga|currency}}</td></tr>
  <tr><td>Termahal</td><td>{{tabledb.s.max:harga|currency}}</td></tr>
  <tr><td>Termurah</td><td>{{tabledb.s.min:harga|currency}}</td></tr>
  <tr><td><strong>Total</strong></td><td>{{tabledb.s.sum:harga|currency}}</td></tr>
</tfoot>
```

### Two independent tables di 1 template

Beda namespace = beda data source:

```html
<h3>Penjualan</h3>
<table>
  <tr>
    <td>{{tabledb.jual.nama}}</td><td>{{tabledb.jual.total|currency}}</td>
  </tr>
</table>

<h3>Pengeluaran</h3>
<table>
  <tr>
    <td>{{tabledb.keluar.item}}</td><td>{{tabledb.keluar.jumlah|currency}}</td>
  </tr>
</table>
```

Setup namespace `jual` dan `keluar` di Query DB panel — 2 SQL queries independent.

---

## Designer shortcuts

Setelah **Analyze Query** di tabledb modal, muncul 3 helper widgets untuk generate markers dgn 3 klik (no manual typing):

### 1. Column + Format

Pilih kolom dari dropdown + format modifier → **Insert Marker**.
Hasil: `{{tabledb.stok.harga|currency}}`

### 2. Formula per-row

Ketik formula (bare column names) + format → **Insert Formula**.
Hasil: `{{tabledb.stok.=qty*harga|currency}}`

### 3. Aggregate (footer)

Pilih verb (sum/avg/count/min/max) + target (kolom atau =formula) + format → **Insert Aggregate**.
Hasil: `{{tabledb.stok.sum:=qty*harga|currency}}`

**Tip**: Marker inserted ke posisi cursor di TinyMCE. Cursor di tbody row → clone; cursor di tfoot row → aggregate.

---

## Safety

### FormulaEvaluator (bare mode)

Grammar HANYA allow: digits, `+ - * /`, parens, bare identifiers (auto-wrapped ke `{}` untuk safety). Everything else REJECTED at tokenize:

| Attack | Rejected at | Reason |
|--------|-------------|--------|
| `system(1)` | eval | `{system}({1})` — no function grammar, fields access instead |
| `'malicious'` | tokenize | `'` = invalid char |
| `1 + 2; 3` | tokenize | `;` = invalid char |
| `` `whoami` `` | tokenize | backtick = invalid char |
| `{system('rm')}` | field regex | not `[a-zA-Z_][a-zA-Z0-9_]*` |

**No PHP `eval()`**. **No function calls**. **No property access**. **No string literals**.

### HTML escape

- Raw column values pass through `htmlspecialchars(ENT_QUOTES | ENT_HTML5)`
- Format pipes always safe output (text pipe explicit escape)
- Cell markup di template author designs = author responsibility

### SQL injection

- SQL statement author-defined (bukan user-input) → author responsibility
- `{param}` placeholders → prepared statement bindings (existing `executeDynQuery` guard)
- SELECT-only guard rejects INSERT/UPDATE/DELETE/DROP/dst

---

## Architecture

```
generate.php renderContent()
    |
    v processTabledbRows($html, $tableDbQueries, ...)
    |
    v scan setiap <tr> for markers via regex
    |
    v foreach row:
      1. Extract all markers dgn preg_match_all
      2. Classify row: aggregate vs clone (based on selectors)
      3. Execute SQL (cached per namespace)
      4. Render:
         - Clone: iterate data rows, substitute markers per row
         - Aggregate: compute aggregate values, render ONCE
      5. Concat output
```

### File locations

- `views/document/generate.php` — `processTabledbRows()` + 6 helpers (`_tabledbClassifyRow`, `_tabledbParseSelector`, `_tabledbParseFormat`, `_tabledbRenderRow`, `_tabledbComputeAggregate`, `_tabledbFormatValue`)
- `src/Table/FormulaEvaluator.php` — safe expression parser + `evaluateBare()`
- `src/Table/Formatter.php` — locale-aware format (currency/number/percent/date)
- `src/Format/DateFormatter.php` — locale weekday/month (v0.9.10)

### Backward compat

Old marker `{{tabledb.ns.col}}` (tanpa format pipe) tetap works **identical** to pre-v0.9.14b behavior — HTML escape default. Zero migration effort.

---

## Blok berulang, prosedur tersimpan, dan format tambahan (v4, 2026-10-15)

### Blok berulang — `data-repeat`

Satu bagian tata letak diulang per baris kueri, bukan hanya satu `<tr>`:

```html
<div data-repeat="bayi" data-repeat-break="page">
  <p>Bayi ke-{{tabledb.bayi.urut}}: {{tabledb.bayi.nama}}</p>   <!-- di paragraf pun boleh -->
  <table><tr><td>Berat</td><td>{{tabledb.bayi.berat|number}} g</td></tr></table>
</div>
```

- Semua penanda `{{tabledb.<ns>.*}}` di dalam blok diisi dari baris itu, di mana saja letaknya; agregat (`sum:` dst) dihitung atas seluruh baris `<ns>`.
- Kueri namespace lain di dalam blok dijalankan ulang **per baris induk**, dengan kolom induk sebagai parameter `{<ns>__<kolom>}`, mis. `CALL layanan.CetakHasilRad({tind__ID})` di dalam `<div data-repeat="tind">`.
- `data-repeat-break="page"` = pemisah halaman di antara blok. Tidak ada baris = bloknya hilang. Blok boleh bersarang. Hanya `<div>` dan `<section>`.
- Pola region berulang mail-merge Word / `for` Jinja.

### Prosedur tersimpan — `CALL`

`sql` boleh berupa satu `CALL skema.prosedur(arg, ...)` **hanya** untuk prosedur yang didaftarkan aplikasi pemakai:

```php
define('EZDOC_TABLEDB_PROCEDURES', ['bpjs.CetakSEP', 'layanan.CetakHasilRad']);
```

Argumen hanya `{param}`, teks `'...'` tanpa kutip/backslash, angka, atau `NULL` — tanpa subkueri, fungsi, atau pernyataan kedua. Result set tambahan dari `CALL` dikuras supaya kueri berikutnya tidak gagal "out of sync". Tanpa konstanta = tidak ada prosedur yang boleh (perilaku lama). Pola daftar izin (OWASP allowlist); sumber data prosedur di JasperReports/SSRS diatur administrator dengan cara yang sama.

### Format tambahan

| Pipe | Contoh | Hasil |
|---|---|---|
| `\|qr[:lebar]` | `{{tabledb.rad.DOKTER\|qr:80}}` | gambar QR berisi nilai kolom (tanpa pembuat QR: teks ter-escape) |
| `\|default:<teks>` | `{{tabledb.sep.PRB\|default:}}`, `\|default:-` | isi pengganti saat kosong (filter `default` Jinja/Twig); tanpa ini kosong = "—" |

Perbaikan: argumen `date`/`datetime`/`default` kini boleh memuat `:` — `|datetime:d/m/y H:i` dulu terpotong jadi `H` dan menitnya hilang.

### Contoh template milik aplikasi pemakai — `EZDOC_EXAMPLES_PATHS`

Template yang memanggil skema basis data pemakai bukan milik pustaka. Folder contoh tambahan:

```php
define('EZDOC_EXAMPLES_PATHS', [__DIR__ . '/ezdoc_contoh']);
```

Dibaca sesudah `examples/` bawaan; slug yang sama → bawaan menang.

## FAQ / Non-goals

### Q: Bisa formula pakai date arithmetic?

**Not yet.** v0.9.14b supports `+ - * /` on numeric only. Date arithmetic (`tanggal_akhir - tanggal_mulai`) requires date-aware operators — defer.

### Q: Group-by dgn subtotal per group?

**Not supported** di enhanced tabledb pattern. Cocok untuk simple aggregate footer saja. Untuk grouped report dgn subtotal per group boundary, workaround: multiple queries dgn WHERE per group, atau consumer decide.

### Q: Interactive editing (Excel-like)?

**No.** Tables adalah SERVER-RENDERED snapshot. Untuk editable forms, use `{{field_name}}` placeholders.

### Q: Charts/pivot?

**No.** Separate concern.

### Q: Multi-namespace di 1 row?

**Not supported.** Namespace assumed uniform per row (dari first marker). Kalau butuh mixed data source, split ke 2 tables/rows.

### Q: 10k+ rows performance?

**Recommendation**: pre-aggregate di SQL kalau dataset besar. Rendering 10k+ rows ke HTML → dompdf PDF converter juga slow. Optimal untuk 10-500 rows/page.

---

## Related docs

- [SCREEN-PAGINATION.md](SCREEN-PAGINATION.md) — how tables page-break di paged mode
- [LOCALIZATION.md](LOCALIZATION.md) — locale weekday/month via DateFormatter
- [PDF-RENDERING.md](PDF-RENDERING.md) — dompdf compatibility notes

spec: `src/Table/{FormulaEvaluator, Formatter}.php` + `views/document/generate.php` `processTabledbRows()` inline docblocks
