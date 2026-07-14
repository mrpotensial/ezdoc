<?php

declare(strict_types=1);

namespace Ezdoc\Http;

use Ezdoc\Exceptions\AccessDeniedException;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\UI\Config;
use Ezdoc\UI\Theme;

/**
 * Ezdoc\Http\Router — internal URL router.
 *
 * Reads request query keys (`app.query_key` = default `ezdoc_page`,
 * `app.asset_key` = default `ezdoc_asset`) plus legacy action-dispatch
 * signals (`ajax=1&action=X`, `_ajax=1`, `_doc_action=X`, `action=delete`,
 * `action=generate_qr`) and dispatches to a named handler.
 *
 * Opt-in prefix: when no ezdoc_* query key is present AND no legacy signal
 * matches, {@see match()} returns null and {@see dispatch()} yields
 * `null` so the consumer's own page keeps rendering. This satisfies PRD
 * §6.13 anti-pattern #1 (must not force all URLs through Ezdoc).
 *
 * PHP 7.4+ compatible.
 */
final class Router
{
    /** @var Config */
    private $config;

    /** @var mixed DB handle (mysqli|PDO|null). */
    private $db;

    /** @var Theme */
    private $theme;

    /** @var AssetHandler */
    private $assets;

    /** @var array<string,callable> route-name → handler(RequestContext, ResponseWriter): string|null */
    private $handlers = [];

    /** @var array<int,string> Whitelisted page names (protects against arbitrary include). */
    private static $PAGE_WHITELIST = [
        'list', 'designer', 'generate', 'view', 'download', 'action', 'new_document',
    ];

    /**
     * @param mixed $db mysqli or PDO or null (demo may pass PDO).
     */
    public function __construct(Config $config, $db, Theme $theme, AssetHandler $assets)
    {
        $this->config = $config;
        $this->db     = $db;
        $this->theme  = $theme;
        $this->assets = $assets;
        $this->registerDefaults();
    }

    /**
     * Register / override a named route.
     *
     * @param callable(RequestContext,ResponseWriter):(string|null) $handler
     */
    public function register(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler;
    }

    /**
     * Determine which handler (if any) matches this request. Pure — no side effects.
     */
    public function match(RequestContext $req): ?string
    {
        $qk = (string) $this->config->get('app.query_key', 'ezdoc_page');
        $ak = (string) $this->config->get('app.asset_key', 'ezdoc_asset');

        // 1. Asset requests short-circuit (highest priority).
        $assetVal = $req->query($ak, '');
        if (is_string($assetVal) && $assetVal !== '') {
            return 'asset';
        }

        // 2. Explicit page query.
        $page = (string) $req->query($qk, '');
        if ($page !== '' && in_array($page, self::$PAGE_WHITELIST, true)) {
            return $page;
        }

        // 3. Legacy action-dispatch signals (backward compat).
        if ($req->method() === 'POST') {
            if (isset($req->post['_ajax']) || isset($req->post['_doc_action'])) {
                return 'action';
            }
            if (isset($req->post['ajax']) && isset($req->post['action'])) {
                return 'action';
            }
            if ((string) ($req->post['action'] ?? '') === 'delete' && isset($req->post['delete_id'])) {
                return 'action';
            }
        }
        if ($req->method() === 'GET' && (string) $req->query('action', '') === 'generate_qr') {
            return 'action';
        }

        return null; // passthrough — consumer page keeps rendering
    }

    /**
     * Dispatch a request. Returns:
     *   - string:  page body for consumer to echo / return
     *   - null:    either no match (opt-in fallthrough) or a streamed response
     *              already handled via ResponseWriter::stream()
     */
    public function dispatch(RequestContext $req, ResponseWriter $res): ?string
    {
        $name = $this->match($req);
        if ($name === null) {
            return null;
        }
        if (!isset($this->handlers[$name])) {
            $res->status(404)->html('<h1>Not Found</h1><p>Route "' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" has no handler.</p>', 404);
            return $res->emit();
        }
        try {
            $ret = ($this->handlers[$name])($req, $res);
            if ($ret === null) {
                return $res->emit();
            }
            return is_string($ret) ? $ret : $res->emit();
        } catch (AccessDeniedException $e) {
            $res->json(['success' => false, 'message' => $e->getMessage()], 403);
            return $res->emit();
        } catch (NotFoundException $e) {
            $res->status(404)->html('<h1>Not Found</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>', 404);
            return $res->emit();
        } catch (EzdocException $e) {
            $res->status($e->getStatusCode())->json(['success' => false, 'error' => $e->getMessage()]);
            return $res->emit();
        } catch (\Throwable $e) {
            $res->status(500)->json(['success' => false, 'error' => $e->getMessage()]);
            return $res->emit();
        }
    }

    /**
     * Build an internal URL for a named route + params.
     *
     * @param array<string,scalar> $params
     */
    public function url(string $name, array $params = []): string
    {
        $base = (string) $this->config->get('app.base_path', '');
        $qk   = (string) $this->config->get('app.query_key', 'ezdoc_page');
        $join = (strpos($base, '?') === false) ? '?' : '&';
        $url  = $base . $join . $qk . '=' . rawurlencode($name);
        foreach ($params as $k => $v) {
            $url .= '&' . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $url;
    }

    /**
     * Default route table.
     */
    private function registerDefaults(): void
    {
        $this->handlers['list']         = [$this, 'handleList'];
        $this->handlers['designer']     = [$this, 'handleDesigner'];
        $this->handlers['generate']     = [$this, 'handleGenerate'];
        $this->handlers['new_document'] = [$this, 'handleNewDocument'];
        $this->handlers['view']         = [$this, 'handleView'];
        $this->handlers['download']     = [$this, 'handleDownload'];
        $this->handlers['asset']        = [$this, 'handleAsset'];
        $this->handlers['action']       = [$this, 'handleAction'];
    }

    // ─── Handlers ────────────────────────────────────────────────────

    /**
     * @return string|null
     */
    public function handleAsset(RequestContext $req, ResponseWriter $res)
    {
        $ak       = (string) $this->config->get('app.asset_key', 'ezdoc_asset');
        $relPath  = (string) $req->query($ak, '');
        $this->assets->serve($relPath, $req, $res);
        return $res->emit();
    }

    public function handleList(RequestContext $req, ResponseWriter $res): ?string
    {
        $qParam      = (string) $req->query('q', '');
        $statusParam = (string) $req->query('status', '');
        $debugMsg    = '';

        // Priority 1: runtime.documents kalau consumer inject (test/mock scenarios).
        $documents = $this->config->get('runtime.documents');
        if (!is_array($documents)) {
            $documents = [];
            // Priority 2: kalau db mysqli available, auto-query real docs via Repository.
            if ($this->db instanceof \mysqli) {
                try {
                    // Sanity: apakah tabel exists?
                    $chk = @mysqli_query($this->db, "SHOW TABLES LIKE 'ezdoc_documents'");
                    $tableExists = ($chk && mysqli_num_rows($chk) > 0);
                    if (!$tableExists) {
                        $debugMsg = 'Table `ezdoc_documents` belum ada — jalankan migrations dulu.';
                    } else {
                        $repo = new \Ezdoc\Document\DocumentRepository($this->db);
                        $documents = $repo->findRecent($statusParam, $qParam, 100);
                        if (empty($documents)) {
                            // Cek raw count untuk beri feedback
                            $cntRes = @mysqli_query($this->db, "SELECT COUNT(*) c FROM ezdoc_documents WHERE deleted_at IS NULL");
                            if ($cntRes) {
                                $cnt = (int)(mysqli_fetch_assoc($cntRes)['c'] ?? 0);
                                if ($cnt === 0) {
                                    $debugMsg = 'Belum ada dokumen di database (0 rows di `ezdoc_documents`).';
                                } else {
                                    $debugMsg = "Ada $cnt dokumen di DB tapi query findRecent() return empty — cek Document::fromRow() hydration.";
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $debugMsg = 'Query error: ' . $e->getMessage();
                }
            } else {
                $debugMsg = 'DB handle bukan mysqli (SQLite/PDO belum di-support di Repository — pending v0.9.9).';
            }
        }

        return $this->renderView(__DIR__ . '/../../views/document/list.php', [
            'documents' => $documents,
            'filters'   => [
                'q'      => $qParam,
                'status' => $statusParam,
            ],
            'baseUrl'   => (string) $this->config->get('app.base_path', ''),
            'debugMsg'  => $debugMsg,  // dev diagnostic — list.php boleh render kalau non-empty
        ], $res);
    }

    public function handleDesigner(RequestContext $req, ResponseWriter $res): ?string
    {
        $action = (string) $req->query('action', 'list');
        if (!in_array($action, ['list', 'create', 'edit'], true)) {
            $action = 'list';
        }
        // List mode → fragment (layout.php wraps dengan primary nav).
        // Editor mode (create/edit) → full page (own layout, no nav).
        // Industri: Rails render partial / Symfony partial-vs-full toggle.
        return $this->renderView(__DIR__ . '/../../views/document/designer.php', [
            'action'           => $action,
            'id'               => (int) $req->query('id', 0),
            'message'          => '',
            'messageType'      => '',
            '__ezdoc_fragment' => ($action === 'list'),
        ], $res);
    }

    public function handleGenerate(RequestContext $req, ResponseWriter $res): ?string
    {
        // generate.php inlines dispatcher require — guard with EZDOC_APP_ORCHESTRATED.
        if (!defined('EZDOC_APP_ORCHESTRATED')) {
            define('EZDOC_APP_ORCHESTRATED', true);
        }
        // Picker mode (template_id <= 0) → fragment. Full render (template_id > 0)
        // → full page (dompdf-oriented, own layout).
        $templateId = (int) $req->query('template_id', 0);
        return $this->renderView(__DIR__ . '/../../views/document/generate.php', [
            '__ezdoc_fragment' => ($templateId <= 0),
        ], $res);
    }

    public function handleNewDocument(RequestContext $req, ResponseWriter $res): ?string
    {
        // Alias of generate — new_document is a friendlier route name.
        return $this->handleGenerate($req, $res);
    }

    public function handleView(RequestContext $req, ResponseWriter $res): ?string
    {
        $uuid = (string) $req->query('uuid', '');

        // Industry-standard: view route resolves doc by uuid, then hands off ke
        // generate.php (which owns the full render pipeline). Symfony Route + Nova
        // Resource pattern: route mapping tidak duplicate view logic.
        if ($uuid !== '' && $this->db instanceof \mysqli) {
            try {
                $repo = new \Ezdoc\Document\DocumentRepository($this->db);
                $doc  = $repo->findByUuid($uuid);
                if ($doc !== null) {
                    // Forward vars ke generate.php via $_GET (which generate.php reads).
                    // spec: ezdoc-spec/routes/view.md#uuid-forward
                    $_GET['template_id'] = (string) $doc->getTemplateId();
                    $_GET['doc_id']      = (string) $doc->getId();
                    // Preserve print/download intent kalau ada
                    if ($req->query('print') !== null) $_GET['view']     = 'pdf';
                    if ($req->query('download') !== null) $_GET['download'] = '1';
                    return $this->handleGenerate($req, $res);
                }
            } catch (\Throwable $e) {
                // fallthrough → stub not-found
            }
        }

        // Not found — return 404 dengan explicit message
        $safeUuid = htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8');
        $html = '<div class="rounded-lg border border-red-200 bg-red-50 p-6 shadow-sm">'
              . '<h2 class="text-lg font-semibold text-red-900 mb-2">Document Not Found</h2>'
              . '<p class="text-sm text-red-700">UUID: <code>' . $safeUuid . '</code></p>'
              . '<p class="text-xs text-red-600 mt-2">Dokumen tidak ditemukan di database (mungkin sudah di-delete atau uuid salah).</p>'
              . '</div>';
        $res->status(404)->html($this->wrapLayout($html, 'Document Not Found'));
        return $res->emit();
    }

    public function handleDownload(RequestContext $req, ResponseWriter $res): ?string
    {
        // Route through generate with download=1 flag preserved.
        $_GET['download'] = 1;
        return $this->handleGenerate($req, $res);
    }

    /**
     * @return string|null
     */
    public function handleAction(RequestContext $req, ResponseWriter $res)
    {
        $dispatcher = __DIR__ . '/../../actions/_dispatcher.php';
        if (!is_file($dispatcher)) {
            $res->error('Action dispatcher not found', 500);
            return $res->emit();
        }
        // Wire globals expected by legacy action files (~15 files assume $conn + $author_id).
        if ($this->db !== null) {
            $GLOBALS['conn'] = $this->db;
        }
        $authorId = $this->config->get('app.author_id');
        if ($authorId !== null) {
            $GLOBALS['author_id'] = $authorId;
        }
        // Legacy dispatcher calls exit() inside handlers — capture via ob buffer.
        // We do not attempt to prevent exit; consumer's after-emit code will not run,
        // but this route is the terminal in any request cycle anyway.
        ob_start();
        try {
            /** @psalm-suppress UnresolvableInclude */
            require $dispatcher;
        } catch (\Throwable $e) {
            $captured = ob_get_clean();
            $res->error($e->getMessage(), 500);
            $emit = $res->emit();
            return $emit === null ? $captured : ($emit . $captured);
        }
        $captured = ob_get_clean();
        // If dispatcher returned without exit (no match), let App decide passthrough by returning captured buffer.
        return $captured === false ? '' : $captured;
    }

    // ─── View rendering helpers ───────────────────────────────────────

    /**
     * Render a view file with the given var scope + wrap in layout.
     *
     * @param array<string,mixed> $vars
     */
    private function renderView(string $viewFile, array $vars, ResponseWriter $res): ?string
    {
        if (!is_file($viewFile)) {
            $res->status(404)->html('<h1>View missing</h1><p>' . htmlspecialchars(basename($viewFile), ENT_QUOTES, 'UTF-8') . '</p>', 404);
            return $res->emit();
        }
        // Inject standard scope for all views.
        $config = $this->config;
        $theme  = $this->theme;
        // Expose Context::default so views' fallback logic finds a proper ctx.
        try {
            $ctx = \Ezdoc\Context::default();
        } catch (\Throwable $e) {
            $ctx = null;
        }
        extract($vars, EXTR_OVERWRITE);

        ob_start();
        try {
            /** @psalm-suppress UnresolvableInclude */
            include $viewFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            $res->error($e->getMessage(), 500);
            return $res->emit();
        }
        $content = (string) ob_get_clean();

        // If the view already included layout.php itself (like generate.php does
        // full-page render), we return content as-is. Detect via <html doctype.
        if (stripos($content, '<!doctype') !== false || stripos($content, '<html') !== false) {
            $res->html($content);
            return $res->emit();
        }

        $res->html($this->wrapLayout($content, (string) $this->config->get('pages.list.title', $this->theme->getAppName())));
        return $res->emit();
    }

    /**
     * Wrap a page body in views/layout.php (which reads $content + $title + $config + $theme).
     */
    private function wrapLayout(string $bodyContent, string $title): string
    {
        $layout = __DIR__ . '/../../views/layout.php';
        if (!is_file($layout)) {
            // Bare fallback — minimal HTML shell.
            return '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head><body>' . $bodyContent . '</body></html>';
        }
        $config  = $this->config;
        $theme   = $this->theme;
        $content = $bodyContent;

        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        include $layout;
        $out = ob_get_clean();
        return $out === false ? $bodyContent : $out;
    }
}
