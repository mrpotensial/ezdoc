# UI Customization Guide (v0.6.6+)

Ezdoc ships with **starter** views, CSS, and JS. Nothing here is meant to be the final look — the point is to give you a working skeleton in under 5 minutes, then peel back exactly as much of it as you need to make it feel like *your* product.

## Table of contents

1. [The 4 tingkat customization](#the-4-tingkat-customization)
2. [Level 1 — Config only (5 min)](#level-1--config-only-5-min)
3. [Level 2 — CSS override (30 min)](#level-2--css-override-30-min)
4. [Level 3 — View publish (1–2 hours)](#level-3--view-publish-12-hours)
5. [Level 4 — Full UI replacement](#level-4--full-ui-replacement)
6. [Slot system reference](#slot-system-reference)
7. [Framework adapter samples](#framework-adapter-samples)

---

## The 4 tingkat customization

Pick the lowest level that still gives you what you need — you can always upgrade later.

| Level | What you touch                              | Effort  | Best for                                              |
|-------|---------------------------------------------|---------|-------------------------------------------------------|
| 1     | A single `ezdoc.php` config file             | 5 min   | Change the app name, logo, colors, page copy          |
| 2     | Append a stylesheet, override CSS variables | 30 min  | Match your visual identity without touching markup    |
| 3     | Publish views into your app, edit as PHP    | 1–2 hr  | Add app-specific fields, restructure sections         |
| 4     | Ignore the shipped UI, build your own       | days    | Fully custom SPA / server-rendered UI (React/Vue/etc) |

Levels compose — Level 2 still uses the Level 1 config, Level 3 still respects your Level 2 stylesheet, and so on.

---

## Level 1 — Config only (5 min)

Ezdoc reads from a single `Config` bag at runtime. All starter views call `$config->get('...')` for their strings, colors, and asset paths.

**Step 1.** Copy the sample:

```bash
cp vendor/ezdoc/config/ezdoc.example.php /app/config/ezdoc.php
```

**Step 2.** Load it during your app's bootstrap, *before* rendering any Ezdoc page:

```php
use Ezdoc\Config;

Config::fromFile('/app/config/ezdoc.php');
```

**Step 3.** Edit `/app/config/ezdoc.php`.

**Available keys**

| Key                          | Type       | Default            | Used by                       |
|------------------------------|------------|--------------------|-------------------------------|
| `brand.app_name`             | `string`   | `"Ezdoc"`          | `layout.php` `<title>`, header |
| `brand.primary_color`        | `string`   | `#0e7490`          | injected as `--ezdoc-primary`  |
| `brand.secondary_color`      | `string`   | `#f59e0b`          | injected as `--ezdoc-secondary`|
| `brand.logo_url`             | `?string`  | `null`             | `layout.php` header `<img>`    |
| `pages.list.title`           | `string`   | `"Documents"`      | `document/list.php` `<h1>`     |
| `pages.list.empty_message`   | `string`   | (default copy)     | empty-state text               |
| `pages.form.title`           | `string`   | `"Create Document"`| `document/form.php` `<h1>`     |
| `pages.form.submit_label`    | `string`   | `"Save Document"`  | submit button label            |
| `custom_css`                 | `string[]` | `[]`               | appended `<link>` tags         |
| `custom_js`                  | `string[]` | `[]`               | appended `<script>` tags       |
| `urls.list`                  | `string`   | `#`                | Cancel link on form            |

That's it. No view changes required.

---

## Level 2 — CSS override (30 min)

When config strings aren't enough — you need to change spacing, override a component style, or add whole new visual affordances — reach for CSS.

### Pattern: append a stylesheet

`assets/css/ezdoc.css` uses **CSS variables** for every color, radius, and spacing token. Your custom sheet loads *after* it, so anything you re-declare wins:

```css
/* /public/css/branding.css */
:root {
    --ezdoc-primary:        #7c3aed;
    --ezdoc-primary-hover:  #6d28d9;
    --ezdoc-radius:         0.75rem;
    --ezdoc-radius-lg:      1rem;
    --ezdoc-font:           "Inter", system-ui, sans-serif;
}

/* Component-level override */
.ezdoc-card {
    box-shadow: 0 10px 30px rgba(124, 58, 237, 0.15);
}
```

Register it via config:

```php
return [
    'custom_css' => ['/css/branding.css'],
];
```

The layout loops `$theme->getCustomCssPaths()` and appends each after core CSS.

### Full variable reference

Every token is defined in `assets/css/ezdoc.css` under `:root`:

- Palette — `--ezdoc-primary`, `--ezdoc-primary-contrast`, `--ezdoc-primary-hover`, `--ezdoc-secondary`, `--ezdoc-secondary-contrast`
- Surfaces — `--ezdoc-bg`, `--ezdoc-surface`, `--ezdoc-border`, `--ezdoc-text`, `--ezdoc-text-muted`
- Shape — `--ezdoc-radius`, `--ezdoc-radius-lg`
- Rhythm — `--ezdoc-spacing-xs`, `--ezdoc-spacing-sm`, `--ezdoc-spacing-md`, `--ezdoc-spacing-lg`
- Elevation — `--ezdoc-shadow-sm`, `--ezdoc-shadow-md`
- Typography — `--ezdoc-font`

---

## Level 3 — View publish (1–2 hours)

When you need to restructure markup — add a patient picker, drop a whole column, embed a custom widget between the fields and the submit button — publish the views into your app tree and edit the copies.

### Step 1: publish

```bash
php vendor/ezdoc/cli/publish.php views /app/resources/views/vendor/ezdoc
```

This copies `views/**/*.php` into your app. From here the files are yours — commit them, edit them, refactor them.

### Step 2: edit

Every starter view has a header comment listing the vars in scope. Example — adding a "Priority" column to `document/list.php`:

```php
<th>Priority</th>
<!-- ... -->
<td>
    <?= htmlspecialchars((string)($doc->getFieldValues()['priority'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
</td>
```

### Step 3: nothing — ViewResolver picks them up

At render time, `Ezdoc\UI\ViewResolver` checks your publish directory first, then falls back to the bundled starter. Zero configuration once the file is on disk.

Rule of thumb: if you can express your change purely as *added* markup, prefer a **slot** (Level 2.5) so you keep upgrade friendliness. Publish only when you need to *change* or *remove* something in the starter.

---

## Level 4 — Full UI replacement

If you're building a React/Vue/Svelte/HTMX front-end, you can skip Ezdoc's views entirely.

### The 4-layer architecture

```
┌────────────────────────────────────────────────┐
│  Layer 4: UI                                    │  ← yours
│  (React SPA, Blade, plain PHP, ...)             │
├────────────────────────────────────────────────┤
│  Layer 3: Action endpoints                      │  ← ships as JSON HTTP
│  actions/document/*.php, actions/template/*.php │
├────────────────────────────────────────────────┤
│  Layer 2: Services + domain                     │  ← ships as PHP classes
│  Ezdoc\Document\DocumentService, ...            │
├────────────────────────────────────────────────┤
│  Layer 1: Storage + Context                     │  ← ships as mysqli + DI
│  ezdoc_documents, ezdoc_templates, ...          │
└────────────────────────────────────────────────┘
```

You can enter at any layer:

- **Consume Layer 3** (action endpoints) — post JSON to `actions/document/save.php`, get JSON back. Ezdoc handles validation, storage, audit trail. You render however you like.
- **Consume Layer 2** (services) — instantiate `DocumentService` in your controller and skip the HTTP hop.
- **Consume Layer 1** (repositories) — talk directly to `DocumentRepository` for the rawest access.

Each layer down gives you more control at the cost of writing more integration code. Layer 3 is the sweet spot for most SPAs.

### Example: minimal fetch-based flow

```js
const res = await Ezdoc.postJson("/ezdoc/actions/document/save.php", {
    template_uuid: "...",
    subject_type: "patient",
    subject_id: "12345",
    field_values: { diagnosis: "...", plan: "..." }
}, { csrfToken: window.csrfToken });

console.log(res.document.uuid);
```

---

## Slot system reference

Slots let you *add* to a shipped view without publishing it. They're the single most upgrade-friendly extension point.

### Named slots

| Slot name                         | Rendered in            | Typical use                     |
|-----------------------------------|------------------------|---------------------------------|
| `layout:head-extra`               | `layout.php` `<head>`   | Meta tags, extra `<link>`       |
| `layout:header-extra`             | `layout.php` header     | Nav items, user badge           |
| `layout:footer-extra`             | `layout.php` footer     | Copyright, build info           |
| `document-list:filters-extra`     | `list.php` filter row   | Extra dropdowns                 |
| `document-list:actions-extra`     | `list.php` row actions  | Row-level buttons               |
| `document-form:before-fields`     | `form.php` (top)        | App-specific selectors          |
| `document-form:after-fields`      | `form.php` (bottom)     | Notes, attachments              |
| `designer:sidebar-extra`          | designer sidebar        | Extra template tools            |

### Registration (PHP)

```php
use Ezdoc\UI\Slot;

Slot::register('document-form:before-fields', function ($context) {
    ?>
    <div class="mb-3">
        <label class="form-label">Department</label>
        <select name="field_values[department]" class="form-select">
            <option>Cardio</option>
            <option>Neuro</option>
        </select>
    </div>
    <?php
}, 50); // priority 50 → runs before default priority 100
```

### Registration (JS)

```js
Ezdoc.slots.register("document-form:after-fields", function (target, ctx) {
    var el = document.createElement("div");
    el.textContent = "Autosave every 30s";
    target.appendChild(el);
}, 100);

// Later, inside the view or on DOMContentLoaded:
Ezdoc.slots.render("document-form:after-fields", document.querySelector("[data-slot='after-fields']"));
```

### Priority ordering

- Lower number → runs earlier.
- Default is `100`.
- Multiple callbacks are supported; they run in ascending priority.
- Callbacks that throw are logged but do not block subsequent ones.

---

## Framework adapter samples

### Laravel (planned v0.7)

A first-class Laravel adapter package is on the roadmap for **v0.7**. It will provide:

- `EzdocServiceProvider` — auto-registers Config, Context, ViewResolver.
- Facade `Ezdoc::documents()->list($filters)` etc.
- Blade view namespace `ezdoc::document.list`.
- Publish command `php artisan vendor:publish --tag=ezdoc-views`.

Until then, follow the Plain PHP pattern below inside a Laravel controller.

### Plain PHP monolith (available now)

The starter setup used by SIMpel:

```php
// bootstrap.php — run once per request, e.g. from index.php
require __DIR__ . '/koneksi.php';         // exposes $conn (mysqli)
require __DIR__ . '/ezdoc/autoload.php';

use Ezdoc\Config;
use Ezdoc\Context;

Config::fromFile(__DIR__ . '/config/ezdoc.php');
Context::setDefault(Context::fromGlobals());
```

Then in any page:

```php
$ctx  = Context::default();
$svc  = new \Ezdoc\Document\DocumentService($ctx);
$docs = $svc->list(['status' => 'draft']);

// Render starter view with normal include:
$config    = Config::instance();
$theme     = new \Ezdoc\UI\Theme($config);
$documents = $docs;
$filters   = $_GET;
$baseUrl   = '/ezdoc';

ob_start();
require __DIR__ . '/ezdoc/views/document/list.php';
$content = ob_get_clean();

$title = $config->get('pages.list.title');
require __DIR__ . '/ezdoc/views/layout.php';
```

### WordPress plugin (pattern)

A typical WP plugin structure:

```
my-ezdoc-plugin/
├── my-ezdoc-plugin.php        // header + activation hook
├── includes/
│   └── bootstrap.php           // instantiate Config + Context using $wpdb->dbh
└── vendor/ezdoc/               // composer require or vendored copy
```

Bootstrap adapter sketch:

```php
add_action('plugins_loaded', function () {
    global $wpdb;

    require_once __DIR__ . '/vendor/ezdoc/autoload.php';

    \Ezdoc\Config::fromFile(__DIR__ . '/config/ezdoc.php');
    \Ezdoc\Context::setDefault(
        new \Ezdoc\Context($wpdb->dbh, new WpRoleProvider())
    );
});

add_shortcode('ezdoc_documents', function () {
    ob_start();
    require plugin_dir_path(__FILE__) . 'vendor/ezdoc/views/document/list.php';
    return ob_get_clean();
});
```

`WpRoleProvider` implements `Ezdoc\Auth\RoleProvider` and delegates to `current_user_can()`.

---

**Next steps.** Start at Level 1. If you find yourself editing a Level-1 config with `!important` in a nearby CSS file, that's the signal to move to Level 2 (or 3). Don't reach for Level 4 until you've confirmed you actually need it — the starter views cover more ground than they look like they do.
