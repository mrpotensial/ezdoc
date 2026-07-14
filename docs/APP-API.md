# `Ezdoc\App::run()` — Config Schema Reference

Complete list of accepted config keys. All are optional except where noted; sensible defaults keep the mount 1-line for most consumers.

Config values are read via `Ezdoc\UI\Config::get()`, which accepts both **flat dot-notation** (`'app.db'`) and **nested arrays** (`['app' => ['db' => $conn]]`). Consumer values always win over App defaults.

---

## `app.*` — Core wiring

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `app.db` | `mysqli` \| `PDO` \| null | **required** unless `app.demo_mode` | Auto-detected. Both branches are wired into `$GLOBALS['conn']` for legacy action files. |
| `app.base_path` | `string` | `''` | URL prefix the consumer mounts under. Router prepends this to every internal URL it generates. Examples: `?page=ezdoc_ui`, `/admin/docs`, or empty (query-only mode for `public/index.php`). |
| `app.query_key` | `string` | `ezdoc_page` | Query param used for page dispatch (`?<query_key>=list`). |
| `app.asset_key` | `string` | `ezdoc_asset` | Query param used for asset streaming (`?<asset_key>=css/ezdoc.css`). |
| `app.author_id` | `int` \| `string` \| null | null | Signer id for audit trail. Set as `$GLOBALS['author_id']` for legacy actions. |
| `app.auto_migrate` | `bool` | `true` | Runs migrations on first bootstrap. Maps to `EZDOC_AUTO_MIGRATE` constant. |
| `app.strict_setup` | `bool` | `true` | Show friendly error page when core tables are missing. Maps to `EZDOC_STRICT_SETUP`. |
| `app.hmac_secret` | `string` | env `EZDOC_HMAC_SECRET` | L1 signature secret. Fatal if empty in production; auto-set to a demo constant when `app.demo_mode=true`. |
| `app.emit` | `bool` | `true` | If true App echoes the body itself; if false, `App::run()` returns the body as a string for the framework adapter to wrap. |
| `app.asset_roots` | `string[]` | `[EZDOC_ROOT/assets]` | Whitelist for `AssetHandler`. First match wins — useful for brand theme overrides. |
| `app.cache_ttl` | `int` (sec) | `86400` | `Cache-Control: max-age` for streamed assets. |
| `app.role_provider` | `\Ezdoc\Auth\RoleProvider` | `HasRoleProvider` | RBAC source. Consumer replaces with their own for Laravel/Symfony auth. |
| `app.slots` | `\Ezdoc\UI\SlotRegistry` | (lazy empty) | Pre-populated slot registry. Overrides the static `Slot::` default. |
| `app.routes` | `array<string, callable>` | `[]` | Consumer-added routes. Each callable receives `(RequestContext, ResponseWriter)` and returns `string\|null`. |
| `app.request` | `RequestContext` \| `array` | (globals) | Override request source — for tests + framework adapters. |
| `app.error_handler` | `callable(\Throwable): ?string` | internal | Custom exception → response mapper. Not yet wired in v0.9.8 (planned v0.9.9). |

## `app.demo_*` — Demo mode

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `app.demo_mode` | `bool` | `false` (`true` under `App::demo()`) | Enables permissive defaults + demo banner + sample seed. |
| `app.demo_db_path` | `string` | `sys_get_temp_dir()/ezdoc-demo.sqlite` | SQLite file location. Created + migrated on first hit. |

## `brand.*` — Branding

| Key | Type | Default |
|-----|------|---------|
| `brand.app_name` | `string` | `ezdoc` |
| `brand.primary_color` | `string` (CSS color) | `#0e7490` |
| `brand.secondary_color` | `string` | `#f59e0b` |
| `brand.logo_url` | `?string` | null |
| `brand.favicon_url` | `?string` | null |
| `brand.lang` | `string` | `en` |

Read via `Ezdoc\UI\Theme` (already documented in `UI-CUSTOMIZATION.md`).

## `urls.*` — Auto-derived

You **do not** normally set these. App computes them from `app.base_path` + `app.query_key`:

- `urls.list`, `urls.new`, `urls.create`, `urls.edit`, `urls.designer`, `urls.picker`, `urls.print`
- `urls.view_pattern`, `urls.print_pattern` (contain `{uuid}` placeholder)
- `urls.actions.template.{save|copy|delete|toggle_lock|analyze_query|list_categories|field_usage|rename_field|cleanup_orphans}`
- `urls.actions.default_vars.{list_vars|add_var|delete_var}`
- `urls.actions.document.{generate_qr|save|doc_action}`
- `assets.base_url` — pre-set to `<base_path>?<asset_key>=` so views concatenate the relative path

Any of these can be overridden explicitly — consumer wins.

## `assets.*` — Extra CSS/JS

| Key | Type | Notes |
|-----|------|-------|
| `assets.custom_css` | `string[]` | Extra CSS URLs appended after `ezdoc.css`. |
| `assets.custom_js` | `string[]` | Extra JS URLs appended after `ezdoc.js`. |

---

## Return semantics

- `App::run()` returns `string\|null`:
  - `null` → router did not match (opt-in fallthrough; consumer keeps rendering), OR the response was streamed via `ResponseWriter::stream()` (assets, downloads).
  - `string` → page body. If `app.emit=true` App echoes it and returns null; if `app.emit=false` the string is returned untouched for the framework adapter.
- `App::demo()` is equivalent to `App::run([...demo defaults, ...$overrides])`.
- `App::bootstrap($config)` returns `['router', 'config', 'theme']` **without** dispatching — for tests / harnesses.

## Route table (default)

| Route name | Query match | Handler |
|-----------|-------------|---------|
| `list` | `?ezdoc_page=list` | includes `views/document/list.php` |
| `designer` | `?ezdoc_page=designer[&action=&id=]` | includes `views/document/designer.php` |
| `generate` | `?ezdoc_page=generate` | includes `views/document/generate.php` |
| `new_document` | `?ezdoc_page=new_document` | alias of `generate` |
| `view` | `?ezdoc_page=view&uuid=X` | stub detail card (full view.php in v0.9.7-b) |
| `download` | `?ezdoc_page=download&uuid=X` | routes through generate with `download=1` |
| `asset` | `?ezdoc_asset=<path>` | `AssetHandler::serve()` |
| `action` | `?ezdoc_page=action` OR legacy `ajax=1&action=X` / `_ajax=1` / `_doc_action=X` / `action=delete` / `action=generate_qr` | `require actions/_dispatcher.php` |
| _(none)_ | anything else | returns null — consumer host page renders normally |

Consumer may add or override routes via `app.routes` at bootstrap.
