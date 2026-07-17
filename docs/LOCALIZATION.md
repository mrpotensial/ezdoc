# Localization

Locale-aware utilities for date/time formatting and future i18n primitives.

## Overview

Ezdoc ships `Ezdoc\Format\DateFormatter` — a lightweight, static utility for translating English date component names (day-of-week, month) to target locale.

| Component | Path | Responsibility |
|---|---|---|
| `DateFormatter` | `src/Format/DateFormatter.php` | Locale table + `localize()` + `registerLocale()` |

## Precedent

Design modeled after industry-standard i18n libraries:

- **Carbon** — `Carbon::setLocale('id')` + `$carbon->translatedFormat('l')` uses translation array keyed by English canonical name (**identical structure**)
- **Symfony Intl** — `IntlDateFormatter` proxy pattern (static utility class)
- **PHP native `IntlDateFormatter`** — final class with static formatting methods
- **CakePHP `Time`** — locale-aware date wrapper

Ezdoc's `DateFormatter` is a **CarbonLite equivalent** — minimal locale table without ICU (`ext-intl`) dependency. For full ICU/CLDR support consumer can adopt Carbon in application layer.

## Static API

```php
namespace Ezdoc\Format;

final class DateFormatter
{
 public static function localize(string $formattedDate, string $locale = 'id'): string;
 public static function registerLocale(string $locale, array $translations): void;
 public static function locales(): array;
}
```

### `localize()`

Translate English weekday/month names in a formatted date string.

**Parameters**:
- `$formattedDate` — output from PHP `date()` containing English names (e.g. `"Wednesday, 15 January 2025"`)
- `$locale` — target locale code (`"id"`, `"en"`, custom)

**Returns**: localized string, or input unchanged if locale unknown/empty.

**Example**:
```php
use Ezdoc\Format\DateFormatter;

$today = date('l, d F Y'); // "Wednesday, 15 January 2025"
$indo = DateFormatter::localize($today, 'id'); // "Rabu, 15 Januari 2025"

// English pass-through:
$eng = DateFormatter::localize($today, 'en'); // "Wednesday, 15 January 2025" (unchanged)
```

### `registerLocale()`

Register or override a locale translation table.

**Parameters**:
- `$locale` — locale code (e.g. `"ms"`, `"vi"`, `"th"`)
- `$translations` — `[english_canonical => localized]` map

**Example**:
```php
DateFormatter::registerLocale('ms', [
 // Days
 'Sunday' => 'Ahad',
 'Monday' => 'Isnin',
 'Tuesday' => 'Selasa',
 'Wednesday' => 'Rabu',
 'Thursday' => 'Khamis',
 'Friday' => 'Jumaat',
 'Saturday' => 'Sabtu',
 // Months
 'January' => 'Januari',
 'February' => 'Februari',
 // ... etc
]);

$today = DateFormatter::localize(date('l, d F Y'), 'ms');
// → "Rabu, 15 Januari 2025"
```

### `locales()`

Get list of registered locale codes.

**Example**:
```php
DateFormatter::locales();
// → ['en', 'id'] (before registerLocale)
// → ['en', 'id', 'ms'] (after registerLocale('ms', ...))
```

## Built-in Locales

Ezdoc ships two locales out-of-box:

- **`en`** — identity (passthrough, no translation)
- **`id`** — Bahasa Indonesia (dogfood default: Indonesian hospital app)

Additional locales register-on-demand via `registerLocale()` in consumer bootstrap.

## Usage in Templates

Template default value syntax `date:FORMAT` uses `DateFormatter::localize(..., 'id')` in `resolveDefault()`:

```
{{tanggal_dibuat|date:l, d F Y}}
```

Renders at generate/PDF time as `"Rabu, 15 Januari 2025"` (Indonesian).

**Locale override for other apps**: currently hardcoded to `'id'` in `resolveDefault()`. Future enhancement: accept locale from template metadata or `Context::locale`.

## Extension for Full i18n

For applications requiring full CLDR/ICU features (plural rules, formatting numbers, currencies), consumer should compose with **Carbon** or **Symfony Intl** at application layer:

```php
use Carbon\Carbon;

$carbon = Carbon::now('Asia/Jakarta');
$carbon->locale('id');
$formatted = $carbon->translatedFormat('l, d F Y H:i');
// → "Rabu, 15 Januari 2025 14:30"
```

Ezdoc `DateFormatter` remains the lightweight default for library-internal usage (template default resolution, generated document labels).

## Migration from `ubahTanggalKeIndonesia()` (legacy)

**Before** (consumer function dependency):
```php
// In consumer's koneksi.php:
function ubahTanggalKeIndonesia($tanggal) {
 return str_replace(
 ['Sunday', 'Monday', ..., 'January', 'February', ...],
 ['Minggu', 'Senin', ..., 'Januari', 'Februari', ...],
 $tanggal
 );
}

// Ezdoc calls global function:
$indo = ubahTanggalKeIndonesia(date('l, d F Y'));
```

**After** (library-native):
```php
use Ezdoc\Format\DateFormatter;

$indo = DateFormatter::localize(date('l, d F Y'), 'id');
```

Legacy function `ubahTanggalKeIndonesia()` still detected via `function_exists()` shim in `resolveDefault()` for backward-compat. Consumer apps can migrate at their own pace or delete the function once no external code depends on it.

## Testing

```php
use Ezdoc\Format\DateFormatter;

public function testLocalizeIndonesian(): void
{
 $input = 'Wednesday, 15 January 2025';
 $this->assertSame(
 'Rabu, 15 Januari 2025',
 DateFormatter::localize($input, 'id')
 );
}

public function testUnknownLocaleReturnsInput(): void
{
 $input = 'Wednesday';
 $this->assertSame(
 'Wednesday',
 DateFormatter::localize($input, 'unknown-locale')
 );
}

public function testRegisterCustomLocale(): void
{
 DateFormatter::registerLocale('test', ['Monday' => 'X-Monday']);
 $this->assertSame('X-Monday', DateFormatter::localize('Monday', 'test'));
}
```

## See Also

- `docs/PDF-RENDERING.md` — PdfRenderer contract (companion library-native extraction)
- `lib/doc_template_helpers.php` — `resolveDefault()` uses `DateFormatter`
- [Carbon Documentation](https://carbon.nesbot.com/docs/#api-localization) — for full CLDR i18n
- [Symfony Intl Component](https://symfony.com/doc/current/components/intl.html) — for ICU-backed formatting
