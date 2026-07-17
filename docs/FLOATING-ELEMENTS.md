# Floating Elements — Sidecar Metadata Pattern

Floating positioned elements (logo/TTD/QR/materai floating variants) di-store sebagai **sidecar JSON metadata** terpisah dari HTML content column. Editor content clean, floating overlays managed separately.

## Motivation

Sebelum v0.9.12: floating elements embedded langsung di HTML content column dgn markers seperti:
```html
<span class="logo-placeholder floating front" data-logo="hospital" data-pos-x="400" data-pos-y="100" style="top:100px; left:400px" contenteditable="false">...</span>
```

Problems dgn in-DOM approach:
1. **Empty line bugs** — TinyMCE wrap floating span dalam `<p>`, wrapper tanpa `.floating-only` class render full line-height → judul dokumen shifted down
2. **Accidental deletion** — user delete empty line ternyata delete floating element inside `<p>`
3. **Editor style interference** — wrapper `<p>` styling conflict dgn content flow
4. **Fragile classification** — client-side detection error-prone, timing-dependent

Sidecar pattern eliminates these by decoupling floating dari text flow **entirely**.

## Precedent (industry-standard proven)

Document object models yang separate positioned overlays dari text runs:

- **MS Office OOXML** — `<w:drawing>` element outside `<w:t>` text runs
- **Google Docs API** — `EmbeddedDrawing` object separate dari `Body` text stream
- **Figma / Sketch / Adobe InDesign** — Layer model, each object standalone
- **CKEditor 5 Widgets** — atomic non-editable widget system
- **Slate.js `void` nodes** — non-editable JSON structures separate dari text
- **Prosemirror NodeView** — atomic embedded content

## Schema

`floating_elements` JSON column di `ezdoc_templates` + `ezdoc_documents`:

```json
[
 {
 "id": "logo_hospital",
 "type": "logo",
 "position_x": 400,
 "position_y": 100,
 "z_index": "front",
 "width": "80px",
 "data": {}
 },
 {
 "id": "ttd_dokter",
 "type": "ttd",
 "position_x": 500,
 "position_y": 800,
 "z_index": "front",
 "width": "120px",
 "data": {
 "label": "Attending Physician",
 "nama_field": "nama_dokter",
 "ttd_modes": "image"
 }
 }
]
```

### Fields

| Field | Type | Description |
|---|---|---|
| `id` | string | Unique identifier (logo name, ttd id, qr name, materai id) |
| `type` | string | `logo` \| `ttd` \| `qr` \| `materai` |
| `position_x` | int | X coordinate in px from `.page` top-left |
| `position_y` | int | Y coordinate in px from `.page` top-left |
| `z_index` | string | `front` \| `behind` |
| `width` | string | CSS width value dgn unit (e.g. `"80px"`, `"60mm"`) |
| `data` | object | Type-specific additional properties |

### Type-specific `data`

- **logo**: `{}` (no extra props)
- **qr**: `{}`
- **ttd**: `{ label, nama_field, ttd_modes, qr_data?, default_nama? }`
- **materai**: `{ width?, height? }`

## Components

### `Ezdoc\Template\FloatingElement` — Value Object

Immutable representation of single floating element.

```php
use Ezdoc\Template\FloatingElement;

$logo = new FloatingElement(
 id: 'logo_hospital',
 type: FloatingElement::TYPE_LOGO,
 positionX: 400,
 positionY: 100,
 zIndex: FloatingElement::Z_FRONT,
 width: '80px',
 data: []
);

$logo->toArray(); // → array<string, mixed>
FloatingElement::fromArray($arr); // → FloatingElement

$moved = $logo->withPosition(500, 200); // → immutable copy
```

### `Ezdoc\Template\FloatingExtractor` — Service

Extract HTML markers → JSON metadata.

```php
use Ezdoc\Template\FloatingExtractor;

$result = FloatingExtractor::extract($htmlWithMarkers);
// $result = [
// 'html' => '<p>Clean HTML without markers</p>',
// 'floating' => [FloatingElement, FloatingElement, ...],
// ]

$json = FloatingExtractor::toJson($result['floating']); // JSON string for DB
$floating = FloatingExtractor::fromJson($json); // deserialize
```

### `Ezdoc\Template\FloatingInjector` — Service

Rehydrate JSON metadata → HTML markers (backward-compat).

```php
use Ezdoc\Template\FloatingInjector;

$rehydratedHtml = FloatingInjector::inject($cleanHtml, $floatingArray);
// → HTML with widget-wrapped floating markers appended at end
```

## Flow

### Save flow

```
Editor content → save_template.php
 ↓
FloatingExtractor::extract(html)
 ↓
 [cleanHtml, floatingArray]
 ↓
DB:
 - ezdoc_templates.content = cleanHtml
 - ezdoc_templates.floating_elements = json(floatingArray)
```

### Load flow (designer / generate)

```
DB → SELECT content, floating_elements FROM ezdoc_templates
 ↓
IF floating_elements IS NULL:
 templateHtml = content (backward-compat: markers still in HTML)
ELSE:
 floating = FloatingExtractor::fromJson(floating_elements)
 templateHtml = FloatingInjector::inject(content, floating)
 ↓
Rendering pipeline (renderContent) — receives HTML dgn markers as before
 ↓
Same rendered output as pre-v0.9.12
```

## Backward-compat guarantees

1. **Read from legacy rows** (floating_elements NULL): content column still has HTML markers → rendering pipeline works unchanged
2. **Save flow dual-write**: HTML content cleaned, JSON populated → new format
3. **Migration script optional**: existing rows can stay in legacy format indefinitely
4. **Zero visual regression**: rendered output identical to before (extract + inject round-trip preserves markers)

## Widget-wrapper pattern (v0.9.12 phase 1)

Complementary to sidecar: rendered floating markers wrapped dalam `<p class="floating-only" contenteditable="false">` widget wrapper.

- **`.floating-only`** — CSS collapse ke 0 height (via Ezdoc\UI\ContentCss rule)
- **`contenteditable="false"`** — cursor tidak bisa masuk wrapper, prevents accidental deletion

Precedent: CKEditor 5 Widget System, TinyMCE noneditable plugin.

## Migration path

**Existing rows** (v0.9.11 downgrade compatibility):
- Floating markers tetap embedded di `content` column
- `floating_elements` column NULL
- Save flow di v0.9.12 will extract + populate on next save
- Read flow prefers JSON kalau ada, fallback to embedded markers

**Optional bulk migration** (future v0.9.13):
```sql
-- Extract floating markers dari existing rows, populate JSON column
-- (implementation via CLI migration script yg call FloatingExtractor per row)
```

## Related

- `src/UI/ContentCss.php` — `.floating-only` CSS rule (collapse wrapper)
- `docs/CONTENT-CSS.md` — shared content styles
- CKEditor 5 Widget System — atomic non-editable block reference
- MS Word OOXML `w:drawing` — floating drawing schema reference
