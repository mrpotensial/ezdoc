# ezdoc — 5-line Quickstart

`ezdoc` mounts under any PHP host page in **one line**. This is the fastest path from install → working document designer + generator.

---

## Path A — Zero-config demo (SQLite)

Fastest way to see what ezdoc looks like. No DB config, no boilerplate.

```php
<?php
require 'ezdoc/autoload.php';   // or vendor/autoload.php with Composer
\Ezdoc\App::demo();
```

That's it. Under the hood:

1. Autoloader wires the `Ezdoc\` namespace.
2. `App::demo()` provisions a SQLite database in your temp directory (`sys_get_temp_dir()/ezdoc-demo.sqlite`).
3. Migrations run automatically.
4. Three sample templates are seeded.
5. Router dispatches `?ezdoc_page=list|designer|generate|action|asset`.

To try it via PHP's built-in server:

```bash
php ezdoc/cli/serve.php
# → open http://127.0.0.1:8765/?ezdoc_page=list
```

---

## Path B — Mount inside an existing consumer app (mysqli)

Replaces ~100 lines of manual URL / Config wiring with a single call.

```php
<?php
// Load your app's own bootstrap file (whatever name/location it uses —
// db.php, bootstrap.php, config.php, etc.). This is the file that sets up
// $conn (mysqli or PDO) and $_SESSION with authenticated user info.
require_once 'app-bootstrap.php';   // your consumer app's bootstrap
require_once 'ezdoc/autoload.php';

\Ezdoc\App::run([
    'app.db'         => $conn,                 // mysqli or PDO from consumer bootstrap
    'app.base_path'  => '?page=ezdoc_ui',      // whatever URL your host page uses
    'app.author_id'  => $_SESSION['user_id'] ?? null,
    'app.hmac_secret'=> getenv('EZDOC_HMAC_SECRET'),
    'brand.app_name' => 'My App — Documents',
]);
```

If the request does not match an `ezdoc_*` route, `App::run()` returns `null` and your host page continues rendering — this is **opt-in prefix** by design (PRD §6.13 anti-pattern #1).

---

## Path C — Framework adapter (Laravel example)

```php
// routes/web.php
Route::any('/ezdoc/{any?}', function () {
    return \Ezdoc\App::run([
        'app.db'        => DB::connection()->getPdo(),
        'app.base_path' => '/ezdoc',
        'app.emit'      => false,   // let Laravel own the response cycle
    ]);
})->where('any', '.*');
```

Because `app.emit` is false the App returns the body as a string; Laravel wraps it in a `Response` object with its own middleware chain.

---

## What you can access after mount

| URL | Purpose |
|-----|---------|
| `?ezdoc_page=list` | Document list view (dumb, filterable) |
| `?ezdoc_page=designer` | Full WYSIWYG template designer (TinyMCE) |
| `?ezdoc_page=designer&action=edit&id=42` | Edit template 42 |
| `?ezdoc_page=generate` | Document generator + PDF printer |
| `?ezdoc_page=view&uuid=…` | Read-only document detail |
| `?ezdoc_page=action` | Legacy action dispatcher (backward-compat) |
| `?ezdoc_asset=css/ezdoc.css` | Streamed asset (long-cache + ETag) |

Consumer bookmarks that still hit `page/ezdoc_ui_demo.php` or `page/ezdoc_action.php` **keep working** — deprecated but not removed until v1.1.

See [APP-API.md](APP-API.md) for the full config schema.
