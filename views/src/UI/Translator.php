<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Ezdoc\UI\Translator — view-scoped i18n string catalog.
 *
 * Composes a {@see Config} internally (same relationship {@see Theme} has
 * with Config) rather than reimplementing dot-notation lookup. Catalog is
 * built by loading `lang/{locale}/common.php` as the base, then merging
 * `lang/{locale}/{view}.php` on top via `Config::merge()`.
 *
 * Never throws at lookup time — a missing key falls back to the caller's
 * `$default` (or the key itself), and a missing/malformed lang file
 * degrades to an empty catalog rather than crashing a live page. This
 * mirrors bootstrap.php's own "degrade, log, don't die" philosophy for
 * non-critical subsystems.
 *
 * Interpolation uses single-brace `{param}` placeholders — NOT `{{param}}`,
 * which is ezdoc's own document-template mustache syntax (`{{field_name}}`,
 * `{{tabledb.ns.col}}`) and lives in a completely different string universe
 * (per-document author content vs. app-chrome UI copy).
 *
 * PHP 7.4+ compatible — no promotion, no union types.
 */
final class Translator
{
    /** @var Config */
    private $catalog;

    /** @var string */
    private $locale;

    /** @var string */
    private $view;

    public function __construct(Config $catalog, string $locale = 'id', string $view = '')
    {
        $this->catalog = $catalog;
        $this->locale  = $locale;
        $this->view    = $view;
    }

    /**
     * Build a Translator for a given view + locale.
     *
     * Loads (in order, later overrides earlier on key conflict):
     *   1. {$langDir}/{$locale}/common.php  — shared cross-view strings
     *   2. {$langDir}/{$locale}/{$view}.php — view-specific strings
     *
     * Missing files are not an error (empty section). A file that exists
     * but doesn't return an array is logged and skipped, not thrown —
     * a broken lang file must never 500 a live page.
     */
    public static function forView(string $view, string $locale = 'id', ?string $langDir = null): self
    {
        $dir = $langDir !== null ? rtrim($langDir, '/\\') : (dirname(__DIR__, 2) . '/lang');

        $catalog = self::loadCatalogFile($dir . '/' . $locale . '/common.php');
        $viewCatalog = self::loadCatalogFile($dir . '/' . $locale . '/' . $view . '.php');
        $catalog->merge($viewCatalog->all());

        return new self($catalog, $locale, $view);
    }

    /**
     * Load one catalog file into a Config. Never throws — missing file or
     * malformed contents both degrade to an empty Config (logged for the
     * malformed case, since that's an authoring bug worth surfacing).
     */
    private static function loadCatalogFile(string $file): Config
    {
        if (!is_file($file)) {
            return new Config([]);
        }
        try {
            return Config::fromFile($file);
        } catch (\Throwable $e) {
            @error_log('[ezdoc:i18n] Failed to load lang file "' . $file . '": ' . $e->getMessage());
            return new Config([]);
        }
    }

    /**
     * Translate a dot-notation key. Falls back to $default (or the key
     * itself) when missing or non-string — never throws.
     *
     * @param array<string,mixed> $params {name} placeholders to interpolate
     */
    public function t(string $key, array $params = [], ?string $default = null): string
    {
        $value = $this->catalog->get($key);
        if (!is_string($value) || $value === '') {
            $value = $default !== null ? $default : $key;
        }
        if (empty($params)) {
            return $value;
        }
        $replace = [];
        foreach ($params as $name => $paramValue) {
            $replace['{' . $name . '}'] = (string) $paramValue;
        }
        return strtr($value, $replace);
    }

    public function has(string $key): bool
    {
        return $this->catalog->has($key);
    }

    /**
     * Full merged catalog — for injecting into JS as one dictionary
     * (mirrors how $ezdocUrls is already json_encode()'d into views).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->catalog->all();
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getView(): string
    {
        return $this->view;
    }
}
