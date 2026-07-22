# Views + Routing

**Files**: `views/document/*.php`, `src/Http/Router.php`, `src/UI/SlotRegistry.php`
**Milestone**: v0.9.11 (view separation), v1.0-prep (direct routing + aliases)

Documentation untuk ezdoc's view file structure, router route names, slot
extension points, dan v1.0 aliasing conventions.

## View files

Ezdoc ships beberapa view files di `views/document/` mengikuti MVC one-view-
per-action convention (Laravel `resources/views/documents/{action}.blade.php`,
Filament `ListResource`/`EditResource`, Symfony `templates/{controller}/{action}.html.twig`,
Rails `views/{controller}/{action}.html.erb`).

| View file | Purpose | Include pattern |
|-----------|---------|-----------------|
| `template_list.php` | Template list — grid of templates dgn actions | Included dari designer.php kalau `action=list` |
| `designer.php` | Template editor (edit/create) — TinyMCE + property panels | Direct load kalau `action=edit\|create` |
| `generate_list.php` | Template picker — select template untuk generate | Included dari generate.php kalau `template_id=0` |
| `generate.php` | Generate view — full document render + form fill | Direct load kalau `template_id > 0` |
| `list.php` | Document list — all generated docs across templates | Direct load |

**Backward-compat**: `designer.php` + `generate.php` retain internal dispatcher
untuk backward-compat dgn old URLs. Consumer bisa include them monolithically
(older pattern) OR route langsung ke sub-views (v1.0-prep pattern).

## Router routes

Ezdoc's internal router (via `Ezdoc\Http\Router`) resolves `?ezdoc_page=X`
query param ke handler methods. All route names di-whitelist untuk security
(prevent arbitrary include).

### Canonical routes (v1.0)

Direct sub-view routes matching extracted view files. Recommended untuk new
consumer integrations.

| Route | Handler | View file |
|-------|---------|-----------|
| `?ezdoc_page=list` | `handleList()` | `list.php` |
| `?ezdoc_page=template_list` | `handleTemplateList()` | `template_list.php` (via designer.php dispatcher) |
| `?ezdoc_page=template_designer&id={N}` | `handleTemplateDesigner()` | `designer.php` (action=edit\|create) |
| `?ezdoc_page=generate_list` | `handleGenerateList()` | `generate_list.php` (via generate.php dispatcher) |
| `?ezdoc_page=document_generate&template_id={N}` | `handleDocumentGenerate()` | `generate.php` |
| `?ezdoc_page=view&uuid={UUID}` | `handleView()` | Redirects ke generate |
| `?ezdoc_page=download` | `handleDownload()` | `generate.php` w/ download=1 |
| `?ezdoc_page=admin_migrate` | `handleAdminMigrate()` | `views/admin/migrate.php` |
| `?ezdoc_page=action` | `handleAction()` | Dispatches ke `actions/*.php` |

### Legacy routes (backward-compat)

Existing consumer URLs continue working. Retained handlers dispatch internally
ke same view files as canonical routes.

| Legacy route | Equivalent canonical |
|--------------|----------------------|
| `?ezdoc_page=designer` | `?ezdoc_page=template_designer` OR `?ezdoc_page=template_list` (depends on `action=` param) |
| `?ezdoc_page=designer&action=list` | `?ezdoc_page=template_list` |
| `?ezdoc_page=designer&action=edit&id={N}` | `?ezdoc_page=template_designer&id={N}` |
| `?ezdoc_page=generate` | `?ezdoc_page=generate_list` OR `?ezdoc_page=document_generate` (depends on `template_id`) |
| `?ezdoc_page=new_document` | Alias of `generate` (friendlier route name) |

### Custom routes

Consumer bisa register custom handlers atau override defaults:

```php
$router->register('my_custom', function ($req, $res) {
    return '<div>Custom page</div>';
});

// Override default
$router->register('list', function ($req, $res) use ($customRepo) {
    // Custom list rendering
    return $myView->render($customRepo->all());
});
```

**Custom name** must ALSO be di whitelist. Currently whitelist is static;
override via config `app.routes` in `App::run()`:

```php
Ezdoc\App::run([
    'app.routes' => [
        'my_custom' => function ($req, $res) { ... },
    ],
]);
```

### Route aliases

`Router::alias($oldName, $canonicalName)` — forward legacy route names ke
canonical. Depth-capped cycle detection.

```php
// Consumer wants old route name to keep working after v2.0 rename
$router->alias('my_old_route', 'my_new_route');
```

Applied di `register()` + `match()` — canonical storage keyed by resolved name,
existing consumer URLs continue working transparently.

## Slot extension points

Ezdoc uses named slots untuk consumer UI injection points (Filament / shadcn-ui
pattern). Register via `SlotRegistry::register($name, $content, $priority)`,
render di view via `Slot::render($name, $context)`.

### Slot naming convention (v1.0)

Slots namespace by VIEW file (bukan by originating monolith view):

| Canonical slot name | View file | Purpose |
|---------------------|-----------|---------|
| `template_list:header-extra` | `template_list.php` | UI hook top of list (extra actions, filters) |
| `template_list:row-actions-extra` | `template_list.php` | Per-row extra action buttons |
| `generate:before-signatures` | `generate.php` | Closing phrase, place/date ahead of TTD |
| `generate:toolbar-extra-actions` | `generate.php` | Extra toolbar buttons (Export, WhatsApp, Email) |
| `generate:watermark` | `generate.php` | Preview-mode watermark overlay |
| `generate:pdf-head-extra` | `generate.php` (PDF path) | Custom CSS, @page rules, embedded fonts |
| `generate:pdf-body-start` | `generate.php` (PDF path) | Top of every rendered PDF page |
| `generate:pdf-body-end` | `generate.php` (PDF path) | Bottom of every PDF (footer, page number) |

### Legacy slot aliases (v1.0-prep)

Old slot names retained via `SlotRegistry::alias()` mechanism — existing
consumer registrations continue working. Registered automatically di
`App::registerLegacySlotAliases()`.

| Legacy slot name | Canonical (v1.0) |
|------------------|------------------|
| `designer:list-header-extra` | `template_list:header-extra` |
| `designer:list-row-actions-extra` | `template_list:row-actions-extra` |

### Registering slot contributions

```php
// From consumer bootstrap
use Ezdoc\UI\Slot;

// Simple string
Slot::getRegistry()->register('template_list:header-extra', '<button>Import CSV</button>');

// Callable dgn context
Slot::getRegistry()->register('generate:toolbar-extra-actions', function ($ctx) {
    $docId = $ctx['doc_id'] ?? 0;
    return "<button onclick='exportToExcel({$docId})'>Excel</button>";
});

// Priority (lower = earlier output)
Slot::getRegistry()->register('generate:pdf-body-end', $footerHtml, 100);
```

Multiple contributions concatenate in priority order. Ties resolve to
registration order.

## Publishing views (customization)

Consumer bisa **publish** ezdoc's default views ke consumer's project directory
untuk override:

```bash
php cli/publish.php views
```

Copies `ezdoc/views/document/*.php` ke consumer's chosen path. Consumer's
customized version takes precedence via config `app.view_resolver`.

Publish workflow: precedent Filament, shadcn/ui, Blueprint publish pattern.

## Adding new routes (contributor guide)

Untuk add new named route ke ezdoc:

1. **Add to whitelist**: `Router::$PAGE_WHITELIST` (static array)
2. **Register handler**: di `Router::registerDefaults()` atau via `Router::register()`
3. **Implement handler method**: signature `handleXxx(RequestContext $req, ResponseWriter $res): ?string`
4. **Document di this file**: canonical table + purpose
5. **Test coverage**: add ke `tests/Http/RouterTest.php`

If renaming existing route:

1. Add new canonical name to whitelist + register handler
2. Add `Router::alias($oldName, $newName)` di `registerDefaults()` untuk
   backward-compat forwarding
3. Retain old handler kalau internal-dispatch behavior berbeda (like
   `handleDesigner` yg checks `action=` param)
4. Update this doc dgn alias mapping table

## Cross-references

- **Router source**: `src/Http/Router.php`
- **SlotRegistry source**: `src/UI/SlotRegistry.php`
- **Views**: `views/document/*.php`, `views/admin/*.php`
- **Tests**: `tests/Http/RouterTest.php`, `tests/UI/SlotRegistryTest.php`
- **PRD milestone**: §6.16 (v0.9.11 view separation), §6.19 (v1.0 extraction)
- **CHANGELOG**: v1.0-prep track (slot + router aliases)
