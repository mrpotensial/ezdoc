# ezdoc CLI

Command-line tools untuk maintenance & deployment library ezdoc.

## Available commands

| Script | Purpose |
| --- | --- |
| `migrate.php` | Run / reset / inspect DB migrations (butuh `$conn` dari koneksi.php) |
| `publish.php` | Copy views, assets, dan sample config ke consumer app (DB-free) |

---

## `publish.php`

Copy resource files (Blade/PHP views, static assets, sample config) dari library
folder ke lokasi consumer app supaya bisa di-customize tanpa nge-edit vendor code.

Publisher **tidak menyentuh database** — pure filesystem operation. Aman
dijalankan tanpa `koneksi.php` / `$conn`.

### Sintaks

```bash
php ezdoc/cli/publish.php <command> [target_dir] [--force]
```

### Commands

| Command | Argumen | Fungsi |
| --- | --- | --- |
| `views` | `<target_dir>` | Copy `ezdoc/views/` → `target_dir/` (recursive) |
| `assets` | `<target_dir>` | Copy `ezdoc/assets/` → `target_dir/` (recursive) |
| `config` | `<target_dir>` | Copy `config.sample.php` (atau fallback `config.php`) ke `target_dir/` |
| `all` | `<target_dir>` | Copy views + assets + config ke sub-folder di `target_dir/` |
| `list` | — | Dry-run: list semua file yang bakal di-copy |
| `help` | — | Tampilkan usage |

### Flags

| Flag | Fungsi |
| --- | --- |
| `--force`, `-f` | Overwrite target file kalau sudah ada. Tanpa flag ini, file existing di-skip. |
| `--help`, `-h` | Print usage message |

### Target directory

- **Wajib absolute path** (`/var/www/…` atau `C:/…`). Relative path ditolak.
- Path traversal (`..`) **ditolak** untuk keamanan.
- Directory dibuat otomatis kalau belum ada (`mkdir -p` equivalent).

### Exit codes

| Code | Arti |
| --- | --- |
| `0` | Sukses — semua file berhasil copied/skipped tanpa error |
| `1` | Error — source hilang, permission denied, atau ada file yang `[FAIL]` |
| `2` | Usage error — command invalid, argumen missing |

### Contoh output

Dry-run:

```
$ php ezdoc/cli/publish.php list
=== ezdoc publish tool ===
Library root : /var/www/app/pengeluaran/ezdoc
Command      : list

VIEWS (3):
  - /var/www/app/pengeluaran/ezdoc/views/document/preview.php
      → suggested: views/document/preview.php
  - /var/www/app/pengeluaran/ezdoc/views/template/editor.php
      → suggested: views/template/editor.php
  - /var/www/app/pengeluaran/ezdoc/views/verify/index.php
      → suggested: views/verify/index.php

CONFIG (1):
  - /var/www/app/pengeluaran/ezdoc/config.sample.php
      → suggested: config/config.sample.php
```

Publish views (fresh):

```
$ php ezdoc/cli/publish.php views /var/www/app/resources/views/ezdoc
=== ezdoc publish tool ===
Library root : /var/www/app/pengeluaran/ezdoc
Command      : views
Target       : /var/www/app/resources/views/ezdoc
Force        : no

[COPY] /var/www/app/resources/views/ezdoc/document/preview.php
[COPY] /var/www/app/resources/views/ezdoc/template/editor.php
[COPY] /var/www/app/resources/views/ezdoc/verify/index.php

Summary: 3 copied, 0 skipped, 0 failed
```

Re-run tanpa `--force`:

```
$ php ezdoc/cli/publish.php views /var/www/app/resources/views/ezdoc
...
[SKIP] /var/www/app/resources/views/ezdoc/document/preview.php  — Target sudah ada — pakai --force untuk overwrite.
[SKIP] /var/www/app/resources/views/ezdoc/template/editor.php  — Target sudah ada — pakai --force untuk overwrite.
[SKIP] /var/www/app/resources/views/ezdoc/verify/index.php  — Target sudah ada — pakai --force untuk overwrite.

Summary: 0 copied, 3 skipped, 0 failed
```

Force overwrite:

```
$ php ezdoc/cli/publish.php views /var/www/app/resources/views/ezdoc --force
...
[COPY] /var/www/app/resources/views/ezdoc/document/preview.php
[COPY] /var/www/app/resources/views/ezdoc/template/editor.php
[COPY] /var/www/app/resources/views/ezdoc/verify/index.php

Summary: 3 copied, 0 skipped, 0 failed
```

Bulk publish:

```
$ php ezdoc/cli/publish.php all C:/app/public/vendor/ezdoc --force
```

Buat sub-folder:
- `C:/app/public/vendor/ezdoc/views/…`
- `C:/app/public/vendor/ezdoc/assets/…`
- `C:/app/public/vendor/ezdoc/config/ezdoc.config.php` (atau `config.sample.php`)

### File yang di-skip otomatis

Publisher skip file yang match ignore patterns berikut (case-insensitive):

- `.DS_Store`, `Thumbs.db`
- `.gitignore`, `.gitkeep`, `.git/…`
- `*.tmp`, `*.swp`, `*.bak`, `*~`

---

## Integrasi dengan Composer autoload

Consumer install lib via Composer, lalu jalankan publisher via `vendor/bin`:

```jsonc
// composer.json — sisi consumer app
{
    "require": {
        "mrpotensial/ezdoc": "^1.0"
    },
    "scripts": {
        "ezdoc:publish": "php vendor/mrpotensial/ezdoc/cli/publish.php all public/vendor/ezdoc",
        "ezdoc:publish-force": "php vendor/mrpotensial/ezdoc/cli/publish.php all public/vendor/ezdoc --force"
    }
}
```

Kemudian:

```bash
composer ezdoc:publish
```

Publisher pakai `autoload.php` dari library, yang otomatis fallback ke Composer
autoload (kalau `vendor/autoload.php` ada) atau PSR-4 loader bawaan. Jadi
compatible baik saat di-run dari monolith standalone maupun via Composer package.

### Programmatic API

Kalau butuh trigger publish dari kode (mis. installer / setup wizard), pakai
`Ezdoc\UI\PublishCommand` langsung:

```php
use Ezdoc\UI\PublishCommand;

$publisher = new PublishCommand('/abs/path/to/ezdoc');

// Dry-run
$plan = $publisher->listPublishable();

// Execute
$results = $publisher->publishAll('/abs/path/to/app/public/vendor/ezdoc', true);
foreach ($results as $r) {
    // $r = ['file' => '...', 'status' => 'copied|skipped|failed', 'reason' => '...']
}
```

Exception dilempar sebagai:
- `Ezdoc\Exceptions\ValidationException` — target dir invalid / tidak writable
- `Ezdoc\Exceptions\NotFoundException` — source dir/file tidak ada
