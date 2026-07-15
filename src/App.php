<?php

declare(strict_types=1);

namespace Ezdoc;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Http\AssetHandler;
use Ezdoc\Http\RequestContext;
use Ezdoc\Http\ResponseWriter;
use Ezdoc\Http\Router;
use Ezdoc\UI\Config;
use Ezdoc\UI\Slot;
use Ezdoc\UI\SlotRegistry;
use Ezdoc\UI\Theme;

/**
 * Ezdoc\App — top-level static facade + 1-line consumer mount point.
 *
 * Contract (PRD §6.13):
 *   - Consumer writes a single line: `Ezdoc\App::run(['app.db' => $conn]);`
 *   - Or zero-config: `Ezdoc\App::run(); Ezdoc\App::demo();`
 *   - App bootstraps Config → wires Context (mysqli|PDO) → builds Router → dispatches
 *   - Returns null (streamed / no route match) or a string body (consumer echoes)
 *   - MUST NOT call exit() / die() (framework-agnostic)
 *
 * Backward compat:
 *   - Existing `page/ezdoc_ui_demo.php` manual wiring still works — App only fires
 *     when a route matches ({@see Router::match()}); no ezdoc_page query = no-op.
 *   - Existing `page/ezdoc_action.php` still works — new URLs may prefer
 *     `?ezdoc_page=action` but legacy path is preserved.
 *   - `views/document/{list,designer,generate}.php` are UNCHANGED — the router
 *     invokes them with the same var scope they already expect.
 *
 * PHP 7.4+ compatible.
 */
final class App
{
    /**
     * Main entry — mount the ezdoc router under the current request.
     *
     * @param array<string,mixed>|Config $config
     * @return string|null  null = router did not match OR streamed already;
     *                      string = page body for consumer to echo/return.
     */
    public static function run($config = [])
    {
        $cfg = self::normalizeConfig($config);
        self::applyDefaults($cfg);

        // Sanity: pdo or mysqli required OR demo mode.
        $db = $cfg->get('app.db');
        $isDemo = (bool) $cfg->get('app.demo_mode', false);
        if ($db === null && !$isDemo) {
            throw new EzdocException(
                'Ezdoc\\App::run() requires "app.db" (mysqli or PDO). Pass one, or call Ezdoc\\App::demo() for zero-config mode.'
            );
        }
        if ($db !== null && !($db instanceof \mysqli) && !($db instanceof \PDO)) {
            throw new EzdocException(
                'Ezdoc\\App::run(): "app.db" must be a mysqli or PDO instance, got ' . self::debugType($db) . '.'
            );
        }

        // Wire legacy globals so downstream lib/*.php + actions/*.php + views find them.
        if ($db !== null) {
            $GLOBALS['conn'] = $db;
        }
        $authorId = $cfg->get('app.author_id');
        if ($authorId !== null) {
            $GLOBALS['author_id'] = $authorId;
        }

        // Mark orchestration BEFORE bootstrap so bootstrap + inline dispatcher
        // requires can behave differently under App.
        if (!defined('EZDOC_APP_ORCHESTRATED')) {
            define('EZDOC_APP_ORCHESTRATED', true);
        }
        // Bootstrap library — idempotent (EZDOC_LOADED guard).
        if (!defined('EZDOC_LOADED')) {
            self::applyBootstrapFlags($cfg);
            /** @psalm-suppress UnresolvableInclude */
            require_once __DIR__ . '/../bootstrap.php';
        }

        // Build Theme + slots + router
        $theme = new Theme($cfg);
        self::applySlotConfig($cfg);

        $assetRoots = $cfg->get('app.asset_roots');
        if (!is_array($assetRoots) || empty($assetRoots)) {
            $assetRoots = [self::defaultAssetRoot()];
        }
        $assets = new AssetHandler($assetRoots, (int) $cfg->get('app.cache_ttl', 86400));

        $router = new Router($cfg, $db, $theme, $assets);

        // Consumer-supplied extension routes.
        $extRoutes = $cfg->get('app.routes', []);
        if (is_array($extRoutes)) {
            foreach ($extRoutes as $name => $handler) {
                if (is_string($name) && is_callable($handler)) {
                    $router->register($name, $handler);
                }
            }
        }

        $req = self::buildRequest($cfg);
        $res = new ResponseWriter();

        $body = $router->dispatch($req, $res);

        // If dispatch returned null AND consumer set app.default_page (e.g. demo
        // mode entry), retry dispatch dengan default page → prevent blank page
        // when public/index.php served but no ?ezdoc_page= param di URL.
        if ($body === null) {
            $defaultPage = (string) $cfg->get('app.default_page', '');
            $qk          = (string) $cfg->get('app.query_key', 'ezdoc_page');
            $ak          = (string) $cfg->get('app.asset_key', 'ezdoc_asset');
            // Only fallback kalau memang tidak ada page/asset param sama sekali
            $noRouteParams = ((string) $req->query($qk, '')) === ''
                          && ((string) $req->query($ak, '')) === '';
            if ($defaultPage !== '' && $noRouteParams) {
                $req->query[$qk] = $defaultPage;
                $body = $router->dispatch($req, $res);
            }
        }

        if ($body === null) {
            return null;
        }
        if ((bool) $cfg->get('app.emit', true)) {
            echo $body;
            return null;
        }
        return $body;
    }

    /**
     * Zero-config demo mode. Priority:
     *   1. Consumer app dengan bootstrap file (mis. koneksi.php) mysqli — full features
     *   2. SQLite fallback (PDO) — list view only (views hard-coded mysqli, v0.9.9 fix)
     *   3. UI-only demo kalau semua gagal
     *
     * Kalau consumer punya real MySQL via koneksi.php, itu di-prefer supaya
     * designer + generator + PDF end-to-end works. SQLite mode limited karena
     * library views masih pakai mysqli_query() langsung (deprecated di v0.9.9
     * dengan DB abstraction layer).
     *
     * @param array<string,mixed> $overrides
     * @return string|null
     */
    public static function demo(array $overrides = [])
    {
        $base = [
            'app.demo_mode'    => true,
            'app.auto_migrate' => false,
            'app.strict_setup' => false,
            'app.hmac_secret'  => 'demo-secret-DO-NOT-USE-IN-PRODUCTION',
            'app.author_id'    => 'demo-user',
            'app.base_path'    => '',
            'app.query_key'    => 'ezdoc_page',
            'app.asset_key'    => 'ezdoc_asset',
            'app.emit'         => true,
            'app.default_page' => 'list',  // No ?ezdoc_page= → auto-render list
            // Demo/showcase surface (public/index.php) defaults to English —
            // this is the library's generic try-it-out entry point, distinct
            // from a real consumer app's own App::run() config (which keeps
            // Translator's 'id' default unless it explicitly sets app.locale).
            'app.locale'       => 'en',
            'brand.app_name'   => 'ezdoc Demo',
            'brand.primary_color' => '#0e7490',
        ];
        $merged = array_replace($base, $overrides);

        // Priority 1: Try consumer's monolith bootstrap file.
        // Convention: plain-PHP consumer apps often ship a bootstrap file yang
        // set `$conn = mysqli_connect(...)` (nama umum: koneksi.php, db.php, dll).
        // Require via helper (yang `global $conn` sehingga assignment write ke
        // global scope, bukan local method scope).
        // Consumer boleh override lewat 'app.bootstrap_file' config kalau path beda.
        $bootstrapCandidates = (isset($merged['app.bootstrap_candidates']) && is_array($merged['app.bootstrap_candidates']))
            ? $merged['app.bootstrap_candidates']
            : [
                dirname(__DIR__, 2) . '/koneksi.php',  // ../consumer-app/koneksi.php
                dirname(__DIR__, 2) . '/bootstrap.php',
                dirname(__DIR__, 2) . '/db.php',
                dirname(__DIR__, 3) . '/koneksi.php',
            ];
        if (isset($merged['app.bootstrap_file']) && is_string($merged['app.bootstrap_file'])) {
            array_unshift($bootstrapCandidates, $merged['app.bootstrap_file']);
        }
        foreach ($bootstrapCandidates as $bp) {
            if (is_file($bp) && !isset($merged['app.db'])) {
                try {
                    $mysqli = self::loadConsumerBootstrap($bp);
                    if ($mysqli instanceof \mysqli) {
                        $merged['app.db']         = $mysqli;
                        $merged['brand.app_name'] = 'ezdoc Demo (MySQL — full features)';
                        break;
                    }
                } catch (\Throwable $e) {
                    // Bootstrap file require failed — continue to next candidate
                }
            }
        }

        // Priority 2: SQLite fallback kalau mysqli belum ter-wire
        if (!isset($merged['app.db']) && extension_loaded('pdo_sqlite')) {
            try {
                $pdo = self::openDemoDatabase($merged);
                $merged['app.db']         = $pdo;
                $merged['brand.app_name'] = 'ezdoc Demo (SQLite — list-only, v0.9.9 pending)';
                $merged['runtime.demo_notice'] = 'SQLite mode — list view works. Designer/generator butuh MySQL (v0.9.9 fix pending).';
            } catch (\Throwable $e) {
                $merged['runtime.demo_error'] = $e->getMessage();
            }
        } elseif (!isset($merged['app.db'])) {
            $merged['runtime.demo_error'] = 'No koneksi.php mysqli found + pdo_sqlite extension not loaded.';
        }

        return self::run($merged);
    }

    /**
     * Build router + context without dispatching. Handy for tests / harnesses.
     *
     * @param array<string,mixed>|Config $config
     * @return array{router:Router, config:Config, theme:Theme}
     */
    public static function bootstrap($config = []): array
    {
        $cfg = self::normalizeConfig($config);
        self::applyDefaults($cfg);
        $theme = new Theme($cfg);
        $roots = $cfg->get('app.asset_roots');
        if (!is_array($roots) || empty($roots)) {
            $roots = [self::defaultAssetRoot()];
        }
        $assets = new AssetHandler($roots, (int) $cfg->get('app.cache_ttl', 86400));
        $db = $cfg->get('app.db');
        return [
            'router' => new Router($cfg, $db, $theme, $assets),
            'config' => $cfg,
            'theme'  => $theme,
        ];
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|Config $config
     */
    private static function normalizeConfig($config): Config
    {
        if ($config instanceof Config) {
            return $config;
        }
        if (is_array($config)) {
            return Config::fromArray($config);
        }
        throw new EzdocException(
            'Ezdoc\\App: config must be array or Ezdoc\\UI\\Config, got ' . self::debugType($config) . '.'
        );
    }

    /**
     * Fill url.* + assets.base_url defaults so views work without consumer wiring 15 keys.
     */
    private static function applyDefaults(Config $cfg): void
    {
        $base = (string) $cfg->get('app.base_path', '');
        $qk   = (string) $cfg->get('app.query_key', 'ezdoc_page');
        $ak   = (string) $cfg->get('app.asset_key', 'ezdoc_asset');
        $join = (strpos($base, '?') === false) ? '?' : '&';

        $urlPage = function (string $page) use ($base, $join, $qk): string {
            return $base . $join . $qk . '=' . $page;
        };

        $defaults = [
            'urls.list'                             => $urlPage('list'),
            'urls.new'                              => $urlPage('designer') . '&action=create',
            'urls.create'                           => $urlPage('designer') . '&action=create',
            'urls.edit'                             => $urlPage('designer') . '&action=edit&id={id}',
            'urls.view_pattern'                     => $urlPage('view') . '&uuid={uuid}',
            'urls.print_pattern'                    => $urlPage('view') . '&print=1&uuid={uuid}',
            'urls.designer'                         => $urlPage('designer'),
            'urls.designer_create'                  => $urlPage('designer') . '&action=create',
            'urls.picker'                           => $urlPage('generate'),
            'urls.print'                            => $urlPage('generate'),
            'urls.self'                             => $urlPage('generate'),
            'urls.actions.template.save'            => $urlPage('action'),
            'urls.actions.template.copy'            => $urlPage('action'),
            'urls.actions.template.delete'          => $urlPage('action'),
            'urls.actions.template.toggle_lock'     => $urlPage('action'),
            'urls.actions.template.analyze_query'   => $urlPage('action'),
            'urls.actions.template.list_categories' => $urlPage('action'),
            'urls.actions.template.field_usage'     => $urlPage('action'),
            'urls.actions.template.rename_field'    => $urlPage('action'),
            'urls.actions.template.cleanup_orphans' => $urlPage('action'),
            'urls.actions.default_vars.list_vars'   => $urlPage('action'),
            'urls.actions.default_vars.add_var'     => $urlPage('action'),
            'urls.actions.default_vars.delete_var'  => $urlPage('action'),
            'urls.actions.document.generate_qr'     => $urlPage('action'),
            'urls.actions.document.save'            => $urlPage('action'),
            'urls.actions.document.doc_action'      => $urlPage('action'),
            'assets.base_url'                       => $base . $join . $ak . '=',
        ];
        foreach ($defaults as $key => $val) {
            if (!$cfg->has($key)) {
                $cfg->set($key, $val);
            }
        }
    }

    /**
     * Translate Ezdoc app.* flags to legacy EZDOC_* constants BEFORE bootstrap
     * requires them. Only defines constants that aren't already set.
     */
    private static function applyBootstrapFlags(Config $cfg): void
    {
        if (!defined('EZDOC_AUTO_MIGRATE')) {
            define('EZDOC_AUTO_MIGRATE', (bool) $cfg->get('app.auto_migrate', true));
        }
        if (!defined('EZDOC_STRICT_SETUP')) {
            define('EZDOC_STRICT_SETUP', (bool) $cfg->get('app.strict_setup', true));
        }
    }

    private static function applySlotConfig(Config $cfg): void
    {
        $slotReg = $cfg->get('app.slots');
        if ($slotReg instanceof SlotRegistry) {
            Slot::setRegistry($slotReg);
        }
    }

    private static function buildRequest(Config $cfg): RequestContext
    {
        $override = $cfg->get('app.request');
        if ($override instanceof RequestContext) {
            return $override;
        }
        if (is_array($override)) {
            return RequestContext::fromArray($override);
        }
        return RequestContext::fromGlobals();
    }

    private static function defaultAssetRoot(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets';
    }

    /**
     * Require consumer's monolith bootstrap file (mis. koneksi.php / db.php /
     * bootstrap.php) and lift its `$conn` (+ friends) ke $GLOBALS.
     *
     * The `global` declaration aliases scope-local $conn ke $GLOBALS['conn'],
     * jadi kalau bootstrap file do plain `$conn = mysqli_connect(...)`, the
     * assignment lands di global scope (bukan tetap local ke method ini).
     *
     * @return \mysqli|null  mysqli instance kalau bootstrap success + set $conn,
     *                       null kalau tidak.
     */
    private static function loadConsumerBootstrap(string $path): ?\mysqli
    {
        // Import globals BEFORE require so koneksi.php's assignments write to global scope.
        global $conn, $author_id, $author_role_array;
        /** @psalm-suppress UnresolvableInclude */
        require_once $path;
        if (!isset($conn) || !($conn instanceof \mysqli)) {
            return null;
        }
        // Belt-and-suspenders: ensure $GLOBALS mirrors are set explicitly.
        $GLOBALS['conn'] = $conn;
        if (isset($author_id)) {
            $GLOBALS['author_id'] = $author_id;
        }
        if (isset($author_role_array)) {
            $GLOBALS['author_role_array'] = $author_role_array;
        }
        return $conn;
    }

    /**
     * Open (creating + migrating if missing) a SQLite demo DB.
     *
     * @param array<string,mixed> $cfg
     */
    private static function openDemoDatabase(array $cfg): \PDO
    {
        $path = isset($cfg['app.demo_db_path']) && is_string($cfg['app.demo_db_path']) && $cfg['app.demo_db_path'] !== ''
            ? $cfg['app.demo_db_path']
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezdoc-demo.sqlite';

        $freshInstall = !is_file($path);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $sqliteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'sqlite';
        if (is_dir($sqliteDir)) {
            $files = glob($sqliteDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $sql = @file_get_contents($file);
                if (!is_string($sql) || $sql === '') {
                    continue;
                }
                try {
                    $pdo->exec($sql);
                } catch (\Throwable $e) {
                    // Idempotent migrations should tolerate re-run.
                }
            }
        }

        // Seed 3 sample templates (idempotent via INSERT OR IGNORE).
        try {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO ezdoc_templates (uuid, slug, name, category, content, created_at) '
                . 'VALUES (:uuid, :slug, :name, :category, :content, CURRENT_TIMESTAMP)'
            );
            $samples = [
                ['uuid' => 'demo-tpl-0001', 'slug' => 'sample-rujukan', 'name' => 'Surat Rujukan (Sample)', 'category' => 'Rujukan',
                 'content' => '<h1>{{title}}</h1><p>Pasien: {{patient_name}}</p><p>Diagnosa: {{diagnosis}}</p>'],
                ['uuid' => 'demo-tpl-0002', 'slug' => 'sample-sk-karyawan', 'name' => 'SK Karyawan (Sample)', 'category' => 'HR',
                 'content' => '<h1>SK No. {{ref}}</h1><p>{{name}} — {{position}}</p>'],
                ['uuid' => 'demo-tpl-0003', 'slug' => 'sample-invoice', 'name' => 'Invoice Standard (Sample)', 'category' => 'Finance',
                 'content' => '<h1>Invoice {{ref}}</h1><p>Total: {{total}} {{currency}}</p>'],
            ];
            foreach ($samples as $sample) {
                $stmt->execute($sample);
            }
        } catch (\Throwable $e) {
            // Non-fatal — seed table may not exist if SQLite migrations dir is empty.
        }

        return $pdo;
    }

    /**
     * @param mixed $value
     */
    private static function debugType($value): string
    {
        if (function_exists('get_debug_type')) {
            return get_debug_type($value);
        }
        if (is_object($value)) {
            return get_class($value);
        }
        return gettype($value);
    }
}
