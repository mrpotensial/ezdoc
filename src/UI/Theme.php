<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Ezdoc\UI\Theme — typed convenience wrapper on {@see Config} for common
 * branding/theming keys.
 *
 * All getters look up dot-notation keys under `brand.*` / `assets.*` in the
 * backing Config and fall back to library defaults. Consumers who need
 * additional theme keys should read them straight from the Config instead
 * of subclassing (this class is final).
 *
 * PHP 7.4+ compatible.
 */
final class Theme
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Access the underlying Config (for keys not surfaced by Theme).
     */
    public function config(): Config
    {
        return $this->config;
    }

    public function getPrimaryColor(): string
    {
        $v = $this->config->get('brand.primary_color', '#0e7490');
        return is_string($v) && $v !== '' ? $v : '#0e7490';
    }

    public function getSecondaryColor(): string
    {
        $v = $this->config->get('brand.secondary_color', '#f59e0b');
        return is_string($v) && $v !== '' ? $v : '#f59e0b';
    }

    public function getLogoUrl(): ?string
    {
        $v = $this->config->get('brand.logo_url', null);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getFaviconUrl(): ?string
    {
        $v = $this->config->get('brand.favicon_url', null);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getAppName(): string
    {
        $v = $this->config->get('brand.app_name', 'ezdoc');
        return is_string($v) && $v !== '' ? $v : 'ezdoc';
    }

    /**
     * Resolve asset URL relative to library `assets/` directory.
     *
     * Consumer boleh set `assets.base_url` di Config untuk override default
     * (mis. CDN path atau app-specific mount point). Default fallback:
     * `ezdoc/assets/<path>` (relative — works untuk plain PHP host).
     *
     * @param string $relativePath Asset path (mis. 'css/ezdoc.css' or 'js/ezdoc.js')
     * @return string Absolute or relative URL untuk src="..." / href="..."
     *
     * @example Basic (no config override):
     *   $theme->assetUrl('css/ezdoc.css') → 'ezdoc/assets/css/ezdoc.css'
     *
     * @example With CDN override in config:
     *   Config: ['assets.base_url' => 'https://cdn.example.com/ezdoc']
     *   $theme->assetUrl('js/ezdoc.js') → 'https://cdn.example.com/ezdoc/js/ezdoc.js'
     */
    public function assetUrl(string $relativePath): string
    {
        $base = $this->config->get('assets.base_url', 'ezdoc/assets');
        if (!is_string($base) || $base === '') {
            $base = 'ezdoc/assets';
        }
        $base = rtrim($base, '/');
        $path = '/' . ltrim($relativePath, '/');
        return $base . $path;
    }

    /**
     * Extra CSS URLs loaded after the library core stylesheet.
     *
     * @return array<int,string>
     */
    public function getCustomCssPaths(): array
    {
        return $this->readStringList('assets.custom_css');
    }

    /**
     * Extra JS URLs loaded after the library core script.
     *
     * @return array<int,string>
     */
    public function getCustomJsPaths(): array
    {
        return $this->readStringList('assets.custom_js');
    }

    /**
     * Read a config key that is expected to be a list of strings. Silently
     * filters non-string / empty entries.
     *
     * @return array<int,string>
     */
    private function readStringList(string $key): array
    {
        $raw = $this->config->get($key, []);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
