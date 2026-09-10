<?php
/**
 * ezdoc/views/document/generate.php — full-featured document generator + printer.
 * Ported dari reference implementation (monolith consumer app cetak view).
 *
 * Expected vars in scope (all optional — bootstrap fallback below):
 *   @var \Ezdoc\Context   $ctx      DB + role provider + verify service
 *   @var \Ezdoc\UI\Config $config   App config (URLs, brand, slot identity)
 *   @var \Ezdoc\UI\Theme  $theme    Branding + asset URLs (optional)
 *
 * Cross-framework portability: this view is DUMB — no business logic.
 * All server operations via fetch to actions/*.php REST endpoints,
 * with endpoint URLs resolved via `$config` (see $ezdocUrls bag).
 * Consumer library user may swap Context to PDO/Doctrine backend.
 *
 * Modes:
 *   - view=pdf : Output as PDF (stream to browser)
 *   - debug=html : Dump raw PDF HTML for debugging
 *   - download=1 : Force PDF as attachment (Content-Disposition)
 *
 * spec: ezdoc-spec/views/generate.md
 * spec: ezdoc-spec/schemas/document.json
 */

// ═══════════════════════════════════════════════════════════════
// Bootstrap fallback — support standalone invocation dari consumer's page/ dir
// (kalau consumer wire manual, $ctx sudah ter-inject sebelum include ini)
// ═══════════════════════════════════════════════════════════════
if (!isset($ctx) || !($ctx instanceof \Ezdoc\Context)) {
    if (!class_exists(\Ezdoc\Context::class)) {
        $__bootstrapCandidates = [
            __DIR__ . '/../../bootstrap.php',
            __DIR__ . '/../../../ezdoc/bootstrap.php',
        ];
        foreach ($__bootstrapCandidates as $__b) {
            if (is_file($__b)) { require_once $__b; break; }
        }
    }
    $ctx = \Ezdoc\Context::default();
}
  
// Config resolution: consumer OR default empty config
if (!isset($config) || !($config instanceof \Ezdoc\UI\Config)) {
    $config = new \Ezdoc\UI\Config([]);
}

// Translator resolution: consumer OR default (Indonesian) locale.
// $GLOBALS promotion is REQUIRED (not just convenience) — same scope-isolation
// trap already fixed for $dbFields/$dbTtd in commit 1c4a12a: this view can be
// include()'d from Ezdoc\Http\Router::renderView(), a METHOD, in which case
// top-level vars here live in method-local scope, not global. The global t()
// helper below reads $GLOBALS directly so it works from inside function-scoped
// renderers (renderContent(), renderFieldForPdf(), etc.) too. See docs/I18N.md.
if (!isset($translator) || !($translator instanceof \Ezdoc\UI\Translator)) {
    $translator = \Ezdoc\UI\Translator::forView('generate', (string) $config->get('app.locale', 'id'));
}
$GLOBALS['translator'] = $translator;

// Consumer-app globals → abstracted via Context DI
$conn              = $ctx->db;
$author_id         = $ctx->roleProvider->currentUserId();
$author_role_array = $ctx->roleProvider->currentUserRoles();

// AJAX action dispatcher — extract AJAX endpoint handlers.
// Endpoints handled: generate_qr, save_document, toggle_doc_lock, list_versions,
// new_version, delete_version, delete_slot, restore_slot.
// spec: ezdoc-spec/actions/document.md
if (is_file(__DIR__ . '/../../actions/_dispatcher.php')) {
    require_once __DIR__ . '/../../actions/_dispatcher.php';
}

// Extracted helpers (v0.6.5) — pure SELECT lookups + template expression evaluators.
// Cari di 2 lokasi: library `ezdoc/lib/` (kalau sudah copied) atau consumer app
// `<consumer>/lib/` (fallback untuk monolith consumer yang masih share helpers).
// v0.9.9 target: move helpers wholly ke library, deprecate consumer-side fallback.
$__helperCandidates = [
    __DIR__ . '/../../lib/',              // ezdoc/lib/
    __DIR__ . '/../../../lib/',           // consumer/lib/ (one level up from ezdoc/)
    __DIR__ . '/../../../../lib/',        // deeper consumer layout
];
foreach (['doc_meta_helpers.php', 'doc_template_helpers.php', 'doc_verify_helpers.php'] as $__helperFile) {
    foreach ($__helperCandidates as $__dir) {
        $__full = $__dir . $__helperFile;
        if (is_file($__full)) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $__full;
            break;
        }
    }
}

// Local helper — escape output (keeps existing h() call sites working)
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Local helper — translate a dot-notation i18n key (see lang/id/*.php).
// $default preserves the original Indonesian copy so a missing/mistyped key
// still displays correct text instead of a raw key or blank string.
if (!function_exists('t')) {
    function t(string $key, array $params = [], ?string $default = null): string {
        $translator = $GLOBALS['translator'] ?? null;
        if (!($translator instanceof \Ezdoc\UI\Translator)) {
            return $default !== null ? $default : $key;
        }
        return $translator->t($key, $params, $default);
    }
}

if (!function_exists('safeDataImg')) {
    /**
     * Aman untuk dipasang ke `<img src>`.
     *
     * Hanya data URI gambar. Dipakai di tempat yang benar-benar mengeluarkan
     * atribut src — BUKAN untuk menyaring nilai slot tanda tangan, lihat
     * safeSignatureValue() di bawah.
     */
    function safeDataImg(string $s): string {
        if ($s === '') return '';

        // Dijangkar di KEDUA ujung, dengan alfabet base64 yang eksplisit.
        // Sebelumnya hanya awalannya yang diperiksa, jadi apa pun boleh mengikuti
        // `base64,` — termasuk `AAA" onerror="alert(1)`. Keluarannya selalu lewat
        // h(), jadi itu bukan XSS yang hidup, tapi "sesuatu yang bukan gambar
        // diterima sebagai gambar" tetap tidak boleh berhenti pada satu lapis.
        return preg_match('#^data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\r\n]+$#', $s) ? $s : '';
    }
}

if (!function_exists('safeSignatureValue')) {
    /**
     * Nilai slot tanda tangan yang dikenali, atau string kosong.
     *
     * Dulu penyaringnya safeDataImg(), dan itu benar selama nilai slot cuma bisa
     * berupa gambar. Kosakatanya bertambah dua kali sejak itu — penanda mode QR
     * (`qr:v1`) dan referensi objek (`blob:sha256-…`) — dan penyaringnya tidak
     * ikut diperbarui. Keduanya bukan data URI, jadi keduanya **dihapus diam-diam
     * saat halaman dirender**.
     *
     * Akibatnya dua hal yang terlihat sangat berbeda tapi berakar sama:
     *
     *   1. Tanda tangan mode QR "selalu hilang". `qr:v1` tersimpan, lalu lenyap
     *      di render berikutnya, dan tombol "Tanda tangani (QR)" muncul lagi.
     *   2. Tombol QR: OFF/ON menolak dengan "tanda tangan tidak bisa dihapus".
     *      Input tersembunyinya kosong, jadi menyimpan tampilan terbaca sebagai
     *      MENGHAPUS tanda tangan yang sudah tercatat.
     *
     * Yang kedua justru bukti guard §7b bekerja: tanpanya, mengubah tampilan akan
     * menghapus tanda tangan sungguhan dan meninggalkan recordnya menunjuk
     * ketiadaan.
     *
     * Tetap daftar-putih, bukan "apa saja yang tidak kosong". Nilai ini masuk ke
     * atribut HTML dan ke `signature_values`; melonggarkannya jadi bebas berarti
     * apa pun yang bisa di-POST orang bisa mendarat di kedua tempat itu.
     */
    function safeSignatureValue(string $s): string {
        $s = trim($s);
        if ($s === '') return '';

        // Bentuk data URI-nya dipinjam dari SignatureImageStore, bukan ditulis
        // ulang: dua pemeriksa dengan pola berbeda untuk nilai yang sama akan
        // berpisah diam-diam, dan yang longgar jadi jalan masuknya.
        if (\Ezdoc\Storage\SignatureImageStore::isDataUri($s)) return $s;
        if ($s === \Ezdoc\Signature\SignatureValue::QR_ACT) return $s;
        if (\Ezdoc\Storage\Blob::isReference($s)) return $s;

        return '';
    }
}

// ═══════════════════════════════════════════════════════════════
// Config-driven URLs + copy — swap-able per consumer
// spec: ezdoc-spec/config/urls.md
// ═══════════════════════════════════════════════════════════════
$urlGenerateQr    = (string) $config->get('urls.actions.document.generate_qr',     '?action=generate_qr');
$urlSaveDocument  = (string) $config->get('urls.actions.document.save',            '');
$urlDocAction     = (string) $config->get('urls.actions.document.doc_action',      '');
$urlDesigner      = (string) $config->get('urls.designer',                         '');
$urlDesignerCreate= (string) $config->get('urls.designer_create',                  '');
$urlPicker        = (string) $config->get('urls.picker',                           '');
$urlTrashList     = (string) $config->get('urls.trash_list',                       '');
$urlSelf          = (string) $config->get('urls.self',                             '?');
// Nama rute yang BENAR-BENAR memiliki halaman ini.
//
// Beberapa rute cuma meneruskan ke sini: `view` menyelesaikan uuid lalu
// memanggil handleGenerate(), `download` menyalakan download=1, dan
// `generate_list` MEMAKSA template_id=0. Ketiganya pintu, bukan tujuan.
//
// Tanpa ini, navigasi mandiri halaman (ganti versi, simpan, kembalikan)
// membawa nama rute yang mengantar — lalu mengirim param milik generate
// tanpa yang dituntut rute itu. `view` tanpa uuid menjawab "Document Not
// Found"; `generate_list` melempar balik ke pemilih template.
$selfPage         = (string) $config->get('urls.self_page',                        'generate');

// Genericized copy — consumer overrides for branding
$pickerPageTitle  = (string) $config->get('generate.picker_page_title',            'Select Template');
$pickerHeader     = (string) $config->get('generate.picker_header',                'Select Template');
$pickerManageLabel= (string) $config->get('generate.picker_manage_label',          'Manage');
$pickerEmptyMsg   = (string) $config->get('generate.picker_empty_message',         'No templates yet.');
$pickerCreateLabel= (string) $config->get('generate.picker_create_label',          'Create new');
$pdfFilenamePrefix= (string) $config->get('generate.pdf.filename_prefix',          'document');
$adminRoleSlug    = (string) $config->get('roles.admin',                           'superadmin');

// URL bag published to JS via inline JSON (data-attribute injection pattern)
// spec: ezdoc-spec/js/url_bag.md
$ezdocUrls = [
    'generateQr'  => $urlGenerateQr,
    'save'        => $urlSaveDocument,
    'docAction'   => $urlDocAction,
    'trashList'   => $urlTrashList,
    'picker'      => $urlPicker,
    // Dipakai _preservedParams(): halaman ini menavigasi ke NAMANYA SENDIRI,
    // bukan ke nama rute yang kebetulan mengantarnya ke sini.
    'selfPage'    => $selfPage,
];

// Superadmin capability — driven by RoleProvider + config-driven role slug
$isSuperadmin = $ctx->roleProvider->hasRole($adminRoleSlug);

$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$doc_id = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
$param_norm = trim($_GET['norm'] ?? $_POST['_norm'] ?? '');
$param_nopen = trim($_GET['nopen'] ?? $_POST['_nopen'] ?? '');
$param_label = trim($_GET['label'] ?? $_POST['_label'] ?? '-');
if ($param_label === '') $param_label = '-';
$param_version = isset($_GET['version']) ? (int)$_GET['version'] : 0; // 0 = latest
$param_is_locked = 0;

// Document metadata (populated after load)
$docMeta = [
    'id' => null,
    'created_by' => null,
    'created_by_name' => null,
    'created_at' => null,
    'updated_at' => null,
];

// Template selection — allow caller override $templates (demo/consumer bypass DB)
// spec: ezdoc-spec/views/generate.md#picker-templates-injection
if ($template_id <= 0) {
    if (!isset($templates) || !is_array($templates)) {
        $result = mysqli_query($conn, "SELECT id, name AS nama_template FROM ezdoc_templates WHERE content IS NOT NULL AND content != '' AND is_current = 1 AND deleted_at IS NULL ORDER BY name");
        $templates = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) $templates[] = $row;
        }
    }
    // Fragment mode: picker di-wrap layout.php (dapat primary nav).
    $__ezdoc_isFragment = !empty($__ezdoc_fragment);

    // v0.9.11 view separation — template picker extracted ke standalone file
    // untuk industry-standard MVC one-view-per-action pattern (Laravel/Filament/
    // Symfony convention). Inherits parent scope for shared vars.
    require __DIR__ . '/generate_list.php';

    // return (bukan exit) supaya kalau di-include Router::renderView, ob buffer
    // captured untuk layout wrapping. Di standalone mode, return top-level sama
    // efeknya dengan exit (script terminates cleanly).
    return;
}

// Load template — pakai alias supaya kode rendering existing tetap works
$stmt = mysqli_prepare($conn, "
    SELECT id, uuid, version,
           name AS nama_template,
           category,
           scope AS doc_scope,
           content AS template_html,
           floating_elements,
           signature_config AS config_ttd,
           layout_config AS config_header,
           verify_config,
           access_config,
           is_locked
    FROM ezdoc_templates
    WHERE id = ? AND content IS NOT NULL
");
mysqli_stmt_bind_param($stmt, "i", $template_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$template = mysqli_fetch_assoc($result);

if (!$template) {
    // spec: ezdoc-spec/views/generate.md#not-found-redirect
    $__redir = $urlPicker !== '' ? $urlPicker : $urlSelf;
    header("Location: " . $__redir);
    exit;
}

// Derivasi tata letak template pindah ke lib/pdf_compose.php (Tahap 2b).
//
// Semuanya murni fungsi dari baris template — HTML + elemen mengambang, konfig
// tanda tangan, logo, kertas, orientasi, padding, mode tata letak. Dipindah
// supaya jalur pembekuan dokumen bisa merakit PDF yang sama dari
// save_document.php, tanpa menjalankan halaman ini.
//
// Dibongkar ke nama yang sama seperti dulu, jadi sisa halaman tidak berubah.
$__tataLetak = ezdoc_template_layout($template);
$templateHtml       = $__tataLetak['templateHtml'];
$configTtdRaw       = $__tataLetak['configTtdRaw'];
$configHeader       = $__tataLetak['configHeader'];
$templateDocScope   = $__tataLetak['templateDocScope'];
$isGeneralDoc       = $__tataLetak['isGeneralDoc'];
$hasTtdPlaceholders = $__tataLetak['hasTtdPlaceholders'];
$configTtd          = $__tataLetak['configTtd'];
$logos              = $__tataLetak['logos'];
$logoSizes          = $__tataLetak['logoSizes'];
$paperSizes         = $__tataLetak['paperSizes'];
$paperSize          = $__tataLetak['paperSize'];
$orientation        = $__tataLetak['orientation'];
$paperDim           = $__tataLetak['paperDim'];
$padding            = $__tataLetak['padding'];
$padTop             = $__tataLetak['padTop'];
$padRight           = $__tataLetak['padRight'];
$padBottom          = $__tataLetak['padBottom'];
$padLeft            = $__tataLetak['padLeft'];
$layoutMode         = $__tataLetak['layoutMode'];

// Load document
$dokumen = null;
$dbFields = [];
$dbTtd = [];
// BUG FIX: init $GLOBALS['dbFields'] + $GLOBALS['dbTtd'] awal supaya helper
// functions `v()`, `vRaw()` yang pakai `global $dbFields` selalu work walau
// generate.php di-include dari method scope (Router::renderView).
$GLOBALS['dbFields'] = $dbFields;
$GLOBALS['dbTtd']    = $dbTtd;
$isEditMode = false;
// Membuka versi yang disembunyikan lewat ?preview_deleted=1 — SELALU baca-saja.
//
// Haknya sama persis dengan hak menyembunyikan, bukan superadmin saja seperti
// dulu. Yang bisa menyembunyikan harus bisa melihat apa yang ia sembunyikan;
// kalau tidak, tiap salah ketuk jadi tiket ke superadmin.
//
// Aturannya diambil dari lib/authorization.php — satu tempat, empat pemanggil.
//
// Dinamai dan dihitung sekali, karena markup toolbar juga memakainya. Sebelumnya
// jawabannya cuma hidup di dalam ekspresi ini, jadi tombol Hide/Restore Version
// masih ikut $isSuperadmin — hak yang sudah dinaikkan tidak pernah sampai ke
// tombolnya, dan servernya mengizinkan sesuatu yang tidak ada tombolnya.
$bolehSembunyikanVersi = ezdoc_can_hide_versions(
    ezdoc_parse_access_config($template['access_config'] ?? null)
);
$preview_deleted = !empty($_GET['preview_deleted']) && $bolehSembunyikanVersi;
$param_is_deleted = 0;
$param_deleted_at = null;
$param_deleted_by = null;

if ($doc_id > 0) {
    // Bypass deleted_at filter only for superadmin preview
    $delClause = $preview_deleted ? '' : ' AND deleted_at IS NULL';
    $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE id = ? AND template_id = ?" . $delClause);
    mysqli_stmt_bind_param($stmt, "ii", $doc_id, $template_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $dokumen = mysqli_fetch_assoc($result);
    if ($dokumen) {
        $isEditMode = true;
        $dbFields = json_decode($dokumen['field_values'] ?: '{}', true) ?: [];
        $dbTtd = json_decode($dokumen['signature_values'] ?: '{}', true) ?: [];
        // BUG FIX (see note near line 344) — promote ke GLOBALS untuk v()/vRaw()
        $GLOBALS['dbFields'] = $dbFields;
        $GLOBALS['dbTtd']    = $dbTtd;
        $param_norm = $dokumen['norm'] ?? $param_norm;
        $param_nopen = $dokumen['nopen'] ?? $param_nopen;
        $param_label = $dokumen['label'] ?? $param_label;
        $param_version = (int)($dokumen['version'] ?? 1);
        $param_is_locked = (int)($dokumen['is_locked'] ?? 0);
        $param_is_deleted = !empty($dokumen['deleted_at']) ? 1 : 0;
        $param_deleted_at = $dokumen['deleted_at'] ?? null;
        $param_deleted_by = $dokumen['deleted_by'] ?? null;
        $docMeta = [
            'id' => (int)$dokumen['id'],
            'created_by' => $dokumen['created_by'] ?? null,
            'created_by_name' => null,
            'created_at' => $dokumen['created_at'] ?? null,
            'updated_at' => $dokumen['updated_at'] ?? null,
        ];
    }
}

// Lookup dokumen existing:
//   - Kalau scope=patient: lookup by (template_id, norm, nopen, label, version)
//   - Kalau scope=general: lookup by (template_id, label, version) — norm/nopen kosong OK
$canLookup = false;
if ($isGeneralDoc) {
    $canLookup = ($param_label !== '' && $param_label !== '-') || $param_version > 0;
} else {
    $canLookup = ($param_norm !== '' && $param_nopen !== '');
}
// Init client-side debug bag (dump ke window.EZDOC_DEBUG lewat script inline di bawah)
$__ezdocLoadDebug = null;

if (!$dokumen && $canLookup) {
    $delClause = $preview_deleted ? '' : ' AND deleted_at IS NULL';
    if ($isGeneralDoc) {
        // General: lookup by (template_id, label, [version])
        if ($param_version > 0) {
            $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND label = ? AND version = ?" . $delClause);
            mysqli_stmt_bind_param($stmt, "isi", $template_id, $param_label, $param_version);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND label = ?" . $delClause . " ORDER BY version DESC LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $template_id, $param_label);
        }
    } elseif ($param_version > 0) {
        // Specific version requested
        $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND norm = ? AND nopen = ? AND label = ? AND version = ?" . $delClause);
        mysqli_stmt_bind_param($stmt, "isssi", $template_id, $param_norm, $param_nopen, $param_label, $param_version);
    } else {
        // Load latest version (MAX)
        $stmt = mysqli_prepare($conn, "SELECT * FROM ezdoc_documents WHERE template_id = ? AND norm = ? AND nopen = ? AND label = ?" . $delClause . " ORDER BY version DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "isss", $template_id, $param_norm, $param_nopen, $param_label);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $dokumen = mysqli_fetch_assoc($result);
    if ($dokumen) {
        $isEditMode = true;
        $doc_id = $dokumen['id'];
        $dbFields = json_decode($dokumen['field_values'] ?: '{}', true) ?: [];
        $dbTtd = json_decode($dokumen['signature_values'] ?: '{}', true) ?: [];

        // v0.9.12 sidecar per-doc floating override — kalau doc punya
        // `floating_elements` non-null, override template default (rehydrate
        // ke $templateHtml supaya rendering pipeline unchanged). Kalau NULL,
        // inherit template.floating_elements yg sudah rehydrated earlier.
        if (!empty($dokumen['floating_elements'])) {
            $docFloating = \Ezdoc\Template\FloatingExtractor::fromJson($dokumen['floating_elements']);
            if (!empty($docFloating)) {
                // Strip any template-level markers pertama (template rehydration
                // sudah append earlier), lalu inject doc-level override
                $stripped = \Ezdoc\Template\FloatingExtractor::extract($templateHtml);
                $templateHtml = \Ezdoc\Template\FloatingInjector::inject(
                    $stripped['html'],
                    $docFloating
                );
            }
        }
        // BUG FIX: promote $dbFields + $dbTtd ke $GLOBALS supaya helper functions
        // `v()`, `t()`, `dbFieldRaw()` (yang pakai `global $dbFields`) bisa akses
        // saat generate.php di-include dari method Router::renderView() (scope
        // isolation antara caller method dan include file).
        $GLOBALS['dbFields'] = $dbFields;
        $GLOBALS['dbTtd']    = $dbTtd;
        // Client-side debug — expose diagnostic ke window.EZDOC_DEBUG.load
        // supaya user cek via F12 Console tanpa akses server error log.
        $__ezdocLoadDebug = [
            'query'  => compact('template_id') + ['norm' => $param_norm, 'nopen' => $param_nopen, 'label' => $param_label],
            'result' => ['found' => true, 'doc_id' => (int)$dokumen['id']],
            'raw'    => [
                'field_values'     => (string)($dokumen['field_values'] ?? ''),
                'signature_values' => (string)($dokumen['signature_values'] ?? ''),
            ],
            'parsed' => [
                'dbFields_keys' => array_keys($dbFields),
                'dbFields'      => $dbFields,
                'dbTtd_keys'    => array_keys($dbTtd),
            ],
        ];
        $param_version = (int)($dokumen['version'] ?? 1);
        $param_is_locked = (int)($dokumen['is_locked'] ?? 0);
        $param_is_deleted = !empty($dokumen['deleted_at']) ? 1 : 0;
        $param_deleted_at = $dokumen['deleted_at'] ?? null;
        $param_deleted_by = $dokumen['deleted_by'] ?? null;
        $docMeta = [
            'id' => (int)$dokumen['id'],
            'created_by' => $dokumen['created_by'] ?? null,
            'created_by_name' => null,
            'created_at' => $dokumen['created_at'] ?? null,
            'updated_at' => $dokumen['updated_at'] ?? null,
        ];
    } else {
        // Client-side debug — dokumen not found scenario
        $__ezdocLoadDebug = [
            'query'  => ['template_id' => $template_id, 'norm' => $param_norm, 'nopen' => $param_nopen, 'label' => $param_label, 'version' => $param_version, 'scope' => $isGeneralDoc ? 'general' : 'patient'],
            'result' => ['found' => false, 'doc_id' => null],
            'raw'    => null,
            'parsed' => null,
            'hint'   => 'Query no match — cek exact values (whitespace, case-sensitive) vs DB row',
        ];
    }
}

// Force read-only mode for deleted previews (regardless of is_locked)
if ($param_is_deleted) {
    $param_is_locked = 1;
}

// For new doc (no match), default version = 1
if (!$dokumen) $param_version = 1;

// Nama pembuat dokumen. Satu pintu — ezdoc_actor_names() yang memutuskan dari
// mana namanya datang, dan ia yang menyimpan jalur usangnya. Sebelumnya blok ini
// memanggil $ctx->roleProvider->displayName() lewat method_exists: metode yang
// tidak ada di antarmuka RoleProvider, tanpa implementasi, dan merujuk berkas
// spesifikasi yang tidak pernah ada.
if (!empty($docMeta['created_by'])) {
    $__creatorId = (int) $docMeta['created_by'];
    $__creatorNames = function_exists('ezdoc_actor_names') ? ezdoc_actor_names([$__creatorId]) : [];
    if (isset($__creatorNames[$__creatorId])) {
        $docMeta['created_by_name'] = $__creatorNames[$__creatorId];
    }
}

// Nama yang menyembunyikan versi ini. Lewat pintu yang sama dengan panel
// riwayat: dua tempat yang menyebut orang yang sama tidak boleh menyebutnya
// dengan cara yang berbeda.
//
// Aturan tampilnya menyalin actorLabel() di generate_scripts.php — tanpa nama
// tampil `#18`, yaitu id yang jelas-jelas id dan bukan angka telanjang yang
// menyamar jadi nama; dengan nama tampil namanya saja; dan id-nya baru ikut
// untuk yang memang boleh melihat detail audit.
$param_deleted_by_label = '';
if ((int) ($param_deleted_by ?? 0) > 0) {
    $__hidId     = (int) $param_deleted_by;
    $__hidNames  = function_exists('ezdoc_actor_names') ? ezdoc_actor_names([$__hidId]) : [];
    $__hidName   = $__hidNames[$__hidId] ?? '';
    $__hidDetail = function_exists('ezdoc_can_view_audit_detail') && ezdoc_can_view_audit_detail();

    $param_deleted_by_label = $__hidName === ''
        ? '#' . $__hidId
        : ($__hidDetail ? $__hidName . ' (#' . $__hidId . ')' : $__hidName);
}

// Get field value (escaped for HTML output)
function v($name) {
    global $dbFields;
    if (isset($dbFields[$name]) && $dbFields[$name] !== '') return h($dbFields[$name]);
    if (isset($_POST[$name]) && $_POST[$name] !== '') return h($_POST[$name]);
    if (isset($_GET[$name]) && $_GET[$name] !== '') return h($_GET[$name]);
    return '';
}

// Get raw field value (not escaped, for processing like QR generation)
function vRaw($name) {
    global $dbFields;
    if (isset($dbFields[$name]) && $dbFields[$name] !== '') return $dbFields[$name];
    if (isset($_POST[$name]) && $_POST[$name] !== '') return $_POST[$name];
    if (isset($_GET[$name]) && $_GET[$name] !== '') return $_GET[$name];
    return '';
}

// Load whitelisted default variables (extracted to ezdoc/lib/doc_meta_helpers.php)
// resolveDefault() moved to ezdoc/lib/doc_template_helpers.php — reads $whitelistedVars via global.
$whitelistedVars = ezdoc_load_whitelisted_vars($conn);

// ─── OTP phone, resolved server-side ───
// The signing modal looks for the number in the DOM, which only finds it when
// the phone happens to be one of the template's own fields. A caller that
// passes it in the request instead — opening the letter from a patient record,
// say — left the modal with nothing to read, so the operator had to retype a
// number the application already knew.
//
// Resolved here rather than where $configTtd is built: vRaw() reads $dbFields,
// and that is not populated until the document has been loaded further up.
// Same precedence as every other field — saved value, then POST, then GET.
foreach ($configTtd as $__i => $__ttd) {
    if (empty($__ttd['require_otp'])) {
        continue;
    }
    $configTtd[$__i]['otp_phone_value'] = trim((string) vRaw(
        $__ttd['otp_phone_source'] ?? 'nomor_hp_pasien'
    ));
}
unset($__i, $__ttd);

// Dynamic Tables config ({{tabledb.ns.selector|format}} enhanced markers)
// See processTabledbRows() untuk full marker syntax.
$tableDbQueries = $configHeader['tableDbQueries'] ?? [];


// renderDynTable() removed — replaced by processTabledbRows() (v0.9.14b enhanced marker system).

// Conditional section evaluators + processConditionalSections extracted to
// ezdoc/lib/doc_template_helpers.php (evalCondExprPHP, evalSingleCondPHP,
// processConditionalSections). Loaded near top of file.

// Handle save
$saveMessage = '';
$saveSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save'])) {
    $norm = trim($_POST['_norm'] ?? '');
    $nopen = trim($_POST['_nopen'] ?? '');
    $label = trim($_POST['_label'] ?? '-');
    if ($label === '') $label = '-';

    // spec: ezdoc-spec/schemas/slot_identity.md — patient-slot fields (norm, nopen) enforced when scope=patient
    if ($norm === '' || $nopen === '') {
        $saveMessage = (string) $config->get('generate.error_missing_identity', 'Patient identity fields (norm, nopen) are required');
    } else {
        // Collect all fields from template
        $allFields = [];
        preg_match_all('/\{\{([^}]+)\}\}/', $templateHtml, $matches);
        foreach ($matches[1] as $fieldName) $allFields[$fieldName] = true;

        // Also collect QR field names from data-qr attributes
        preg_match_all('/data-qr="([^"]+)"/', $templateHtml, $qrMatches);
        foreach ($qrMatches[1] as $qrField) $allFields[$qrField] = true;

        $fieldData = [];
        foreach ($allFields as $fieldName => $v) {
            $fieldData[$fieldName] = $_POST[$fieldName] ?? '';
        }
        foreach ($configTtd as $ttd) {
            $nameField = $ttd['nama_field'] ?? '';
            if ($nameField) $fieldData[$nameField] = $_POST[$nameField] ?? '';
            // Save TTD mode selection
            $modeKey = '_ttd_mode_' . $ttd['id'];
            if (isset($_POST[$modeKey])) $fieldData[$modeKey] = $_POST[$modeKey];
        }

        $ttdData = [];
        foreach ($configTtd as $ttd) {
            $ttdId = $ttd['id'];
            // safeSignatureValue(), bukan safeDataImg(): penanda mode QR dan
            // referensi objek bukan data URI, dan menyaringnya dengan aturan
            // gambar akan MENGHAPUSNYA dari dokumen tanpa suara.
            $ttdValue = safeSignatureValue((string) ($_POST[$ttdId] ?? ''));
            if ($ttdValue !== '') $ttdData[$ttdId] = $ttdValue;
        }

        $jsonFields = json_encode($fieldData);
        $jsonTtd = json_encode($ttdData);
        // $author_id already resolved via $ctx->roleProvider at bootstrap (top of file)
        $author = $author_id !== null ? $author_id : 'system';

        if ($isEditMode && $doc_id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE ezdoc_documents SET norm=?, nopen=?, label=?, field_values=?, signature_values=? WHERE id=?");
            mysqli_stmt_bind_param($stmt, "sssssi", $norm, $nopen, $label, $jsonFields, $jsonTtd, $doc_id);
        } else {
            // INSERT baru — generate UUID + populate template snapshot
            // Fetch template_uuid + version untuk konsistensi
            $tplUuid = (string)($template['uuid'] ?? '');
            $tplVer  = (int)($template['version'] ?? 1);
            // ezdoc_uuid_v7() is guaranteed by bootstrap.php now that view lives inside the lib
            $docUuid = function_exists('ezdoc_uuid_v7') ? ezdoc_uuid_v7() : bin2hex(random_bytes(18));
            $authorInt = (int)($author_id !== null ? $author_id : 0);
            $stmt = mysqli_prepare($conn, "
                INSERT INTO ezdoc_documents
                (uuid, template_id, template_uuid, template_version,
                 norm, nopen, label, version,
                 field_values, signature_values, status, published_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'published', NOW(), ?)
            ");
            mysqli_stmt_bind_param($stmt, "sisisssssi", $docUuid, $template_id, $tplUuid, $tplVer, $norm, $nopen, $label, $jsonFields, $jsonTtd, $authorInt);
        }

        if (mysqli_stmt_execute($stmt)) {
            $saveSuccess = true;
            $saveMessage = t('save.success', [], 'Document saved successfully');
            if (!$isEditMode) { $doc_id = mysqli_insert_id($conn); $isEditMode = true; }
            $dbFields = $fieldData;
            $dbTtd = $ttdData;
            // BUG FIX: promote ke GLOBALS untuk v()/vRaw() helper functions
            $GLOBALS['dbFields'] = $dbFields;
            $GLOBALS['dbTtd']    = $dbTtd;
            $param_norm = $norm;
            $param_nopen = $nopen;
            $param_label = $label;
            // Level 3: compute + store data hash (best-effort)
            @doc_verify_compute_and_store_hash($conn, (int)$doc_id);
        } else {
            $saveMessage = t('save.failed', ['error' => mysqli_error($conn)], 'Failed to save: {error}');
        }
    }
}

// Get TTD values
$ttdValues = [];
foreach ($configTtd as $ttd) {
    $ttdId = $ttd['id'];
    $val = $dbTtd[$ttdId] ?? $_POST[$ttdId] ?? '';
    // Nilai MENTAH yang dikenali — bukan yang sudah aman untuk `src`. Ia harus
    // kembali utuh ke input tersembunyi, kalau tidak penyimpanan berikutnya
    // mengirimkannya kosong dan terbaca sebagai penghapusan tanda tangan.
    // Untuk ditampilkan, ezdoc_resolve_signature_image() yang membukanya.
    $ttdValues[$ttdId] = safeSignatureValue((string) $val);
}

// ─── Keadaan penandatanganan — DIHITUNG, bukan disetel ───
//
// Tidak ada tombol yang menentukan ini. Dulu ada ("Lock Final"), dan itu justru
// sumber kebingungannya: tombol yang mengulang pembekuan yang sudah dikerjakan
// tanda tangan, sambil merangkap menyatakan selesai.
//
// Semua slot dianggap wajib. Kalau nanti ada slot opsional, di sinilah tempat
// pembedaannya masuk — dan hanya di sini.
$__recordedSlots = ezdoc_recorded_signature_slots($conn, (int) $doc_id);
$__slotCount     = count($configTtd);
$__signedCount   = 0;
foreach ($configTtd as $__t) {
    if (in_array((string) $__t['id'], $__recordedSlots, true)) $__signedCount++;
}

/** 'draft' | 'in_progress' | 'complete' */
$docSigningState = $__slotCount === 0
    ? 'draft'
    : ($__signedCount === 0 ? 'draft' : ($__signedCount >= $__slotCount ? 'complete' : 'in_progress'));

// Selesai berarti tidak ada lagi yang bisa diubah lewat tombol Update: setiap
// field sudah dicakup tanda tangan, dan setiap slot sudah terisi.
//
// Tombolnya berganti jadi New Version begitu ADA SATU tanda tangan, bukan
// menunggu semuanya.
//
// Yang berubah HANYA tampilan tombolnya. Menyimpan tetap boleh sampai seluruh
// slot terisi — saveSign() memanggil submitForm() sendiri di mode gambar maupun
// QR, dan penjaga di save_document.php tidak ikut diubah: yang beku tetap hanya
// field yang dicakup tanda tangan yang sudah ada. Penanda tangan kedua dan
// ketiga berjalan persis seperti sebelumnya.
//
// Yang dihilangkan cuma ajakannya. Dilaporkan dari pemakaian: sesudah
// menandatangani, orang menekan Simpan berulang kali, dan tiap tekanan menulis
// baris audit untuk perubahan yang tidak ada. Riwayat yang penuh simpanan kosong
// membuat yang benar-benar terjadi jadi sulit dilihat.
//
// $docSigningComplete tetap dipakai di tempat lain — ia menjawab pertanyaan yang
// berbeda, yaitu apakah dokumennya sudah lengkap.
$docSigningComplete = ($docSigningState === 'complete');

// Render HTML: replace placeholders with inputs, logos with images, TTD with signature areas
function renderContent($html, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables = [], $conn = null, $param_norm = '', $param_nopen = '', $param_is_locked = 0, $tableDbQueries = [], $doc_id = 0) {
    // Replace logo placeholders with actual images (handles inline and floating logos)
    $html = preg_replace_callback('/<span[^>]*class="([^"]*logo-placeholder[^"]*)"[^>]*data-logo="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?(?:[^>]*style="([^"]*)")?[^>]*>.*?<\/span>/s', function($m) use ($logos, $logoSizes) {
        $classes = $m[1] ?? '';
        $logoName = $m[2];
        $width = $m[3] ?? $logoSizes[$logoName] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';
        $src = $logos[$logoName] ?? '';

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        if ($src) {
            $style = "width:" . h($width) . ";";
            $imgClass = "logo-img";

            if ($isFloating) {
                $imgClass .= " logo-floating";
                $imgClass .= $isBehind ? " logo-behind" : " logo-front";
                $style .= "position:absolute;left:{$posX}px;top:{$posY}px;";
            }

            return '<img src="' . h($src) . '" class="' . $imgClass . '" style="' . $style . '" alt="' . h($logoName) . '">';
        }
        return '<span class="logo-empty">[' . h($logoName) . ']</span>';
    }, $html);

    // Position offset correction for floating elements (to match generator/logo)
    // Based on user testing: logo is accurate reference, QR and TTD should match logo
    // Set to 0 = no adjustment (same as logo rendering)
    $posOffsetQR = 0;
    $posOffsetTTD = 0;

    // Replace TTD placeholders with signature areas
    //
    // Note: TTD placeholder has 3 nested divs inside, so we match each inner div pair then outer close
    // Slot mana yang sudah punya BARIS di ezdoc_signatures.
    //
    // Dibedakan dari "ada nilainya": slot bisa berisi gambar tanpa record —
    // dokumen yang dibuat sebelum pencatatan diaktifkan, atau tanda tangan yang
    // belum tersimpan. Yang mengunci UI harus recordnya, karena itu yang membuat
    // server menolak perubahan.
    $signedTtdIds = ezdoc_recorded_signature_slots($conn, (int) $doc_id);

    $html = preg_replace_callback('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<div[^>]*>.*?<\/div>\s*<\/div>/s', function($m) use ($configTtd, $ttdValues, $dbFields, $posOffsetTTD, $conn, $doc_id, $signedTtdIds) {
        $tag = $m[1];
        // Parse all needed attributes from the opening tag
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-ttd="([^"]+)"/', $tag, $tm); $ttdId = $tm[1] ?? '';
        preg_match('/data-label="([^"]+)"/', $tag, $lm); $label = $lm[1] ?? t('fallback.signature', [], 'Signature');
        preg_match('/data-nama-field="([^"]+)"/', $tag, $nm); $namaField = $nm[1] ?? 'nama_' . $ttdId;
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '50';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '100';
        preg_match('/data-ttd-modes="([^"]+)"/', $tag, $mm); $ttdModes = $mm[1] ?? 'image';
        preg_match('/data-ttd-qr-data="([^"]+)"/', $tag, $qm); $qrDataTpl = html_entity_decode($qm[1] ?? '');
        // Default value untuk nama (fallback kalau field belum diisi)
        preg_match('/data-default-nama="([^"]*)"/', $tag, $dnm); $defaultNama = html_entity_decode($dnm[1] ?? '');
        // RBAC per-TTD (siapa yang boleh sign) — parse dari data-allowed-* attributes
        preg_match('/data-allowed-roles="([^"]*)"/', $tag, $arm); $allowedRolesRaw = $arm[1] ?? '';
        preg_match('/data-allowed-users="([^"]*)"/', $tag, $aum); $allowedUsersRaw = $aum[1] ?? '';
        $ttdRbac = function_exists('ezdoc_parse_ttd_config')
            ? ezdoc_parse_ttd_config($allowedRolesRaw, $allowedUsersRaw)
            : ['roles' => [], 'users' => []];
        $canSignThisTtd = function_exists('ezdoc_can_sign_ttd')
            ? ezdoc_can_sign_ttd($ttdRbac)
            : true;

        if (!$ttdId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $ttdClass = 'ttd-item-inline';
        $style = '';
        if ($isFloating) {
            $ttdClass = 'ttd-item-floating';
            $ttdClass .= $isBehind ? ' ttd-behind' : ' ttd-front';
            $adjX = max(0, intval($posX) - $posOffsetTTD);
            $adjY = max(0, intval($posY) - $posOffsetTTD);
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        $ttdImg = $ttdValues[$ttdId] ?? '';
        // Resolve with fall-through on empty (same semantics as v() helper)
        $pick = function($key) use ($dbFields) {
            if (isset($dbFields[$key]) && $dbFields[$key] !== '') return $dbFields[$key];
            if (isset($_POST[$key]) && $_POST[$key] !== '') return $_POST[$key];
            if (isset($_GET[$key]) && $_GET[$key] !== '') return $_GET[$key];
            return '';
        };
        $namaRaw = $pick($namaField);
        // Fallback ke data-default-nama kalau field kosong
        if ($namaRaw === '' && $defaultNama !== '') $namaRaw = $defaultNama;
        $namaValue = h($namaRaw);

        $hasImage = strpos($ttdModes, 'image') !== false;
        $hasQr = strpos($ttdModes, 'qr') !== false;
        $hasBoth = $hasImage && $hasQr;

        // Determine active mode from saved data or default
        $savedMode = $pick('_ttd_mode_' . $ttdId);
        $activeMode = $savedMode ?: ($hasImage ? 'image' : 'qr');

        // ─── Verify QR mode (dokumen-level) ───
        // Artinya: "pakai mode QR untuk semua slot sekaligus". Dulu ia jalur
        // render ketiga yang menimpa tiap slot dengan URL verifikasi dokumen —
        // nilai yang sama untuk semua slot, jadi tidak ada cara tahu QR yang mana
        // milik siapa. Sekarang ia cuma memaksa modenya; isinya ikut jalur
        // per-tanda-tangan yang sama seperti toggle per slot.
        //
        // Alasan mode ini tetap ada: ia menyembunyikan SEMUA gambar tanda tangan.
        // Gambar bisa disalin ke dokumen lain; pointer ke record server tidak.
        // Karena itu ia memaksa QR bahkan pada slot yang template-nya cuma
        // mengizinkan gambar — kalau tidak, satu gambar tetap tampil dan gunanya
        // hilang.
        $showVerifyQrMode = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || ($_POST['_show_verify_qr'] ?? '') === '1'
            || ($_GET['_show_verify_qr'] ?? '') === '1';
        if ($showVerifyQrMode) {
            $activeMode = 'qr';
            $hasQr = true;
        }

        // Mode toggle buttons (only if both modes available)
        $modeToggle = '';
        if ($hasBoth) {
            $modeToggle = '<div class="ttd-mode-toggle">
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'image' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'image\')">' . h(t('ttd.mode_image', [], 'Image')) . '</button>
                <button type="button" class="ttd-mode-btn ' . ($activeMode === 'qr' ? 'active' : '') . '" onclick="switchTtdMode(\'' . h($ttdId) . '\', \'qr\')">' . h(t('ttd.mode_qr', [], 'QR')) . '</button>
            </div>';
        }

        // Nilai slot bisa berupa gambar inline, referensi ke objek tersimpan, atau
        // penanda tindakan mode QR. Cuma yang pertama boleh langsung masuk ke
        // atribut src — lihat Ezdoc\Signature\SignatureValue dan
        // Ezdoc\Storage\SignatureImageStore.
        //
        // Gambarnya dibuka jadi data URI di sisi server, bukan dilayani lewat URL
        // tersendiri. Alasannya bukan kesederhanaan: URL gambar tanda tangan yang
        // bisa diambil sendiri akan bocor lewat riwayat browser, log proxy, dan
        // header referer. Di dalam halaman, byte-nya ikut kontrol akses halaman
        // itu. Jalur PDF juga menuntut byte inline — dompdf tidak membawa sesi.
        $ttdDisplaySrc = ezdoc_resolve_signature_image($conn, $ttdImg);
        $ttdIsImage = $ttdDisplaySrc !== '';
        $ttdIsSigned = \Ezdoc\Signature\SignatureValue::isSigned($ttdImg);

        // Tanda tangan yang SUDAH TERCATAT tidak boleh menawarkan ubah maupun
        // hapus.
        //
        // Bukan sekadar kerapian UI: keduanya ditolak server (§7b) karena hash
        // tanda tangan mencakup nilai slot. Menawarkan tombol yang pasti ditolak
        // hanya memancing petugas ke dinding — persis seperti field nama sebelum
        // diperbaiki.
        //
        // Ini juga yang dilakukan setiap sistem tanda tangan: begitu ditandatangani,
        // yang tersedia adalah "buat versi baru", bukan "edit". Adobe Sign dan
        // DocuSign tidak punya tombol edit pada dokumen yang sudah ditandatangani
        // sama sekali.
        $ttdRecorded = $ttdIsSigned && in_array($ttdId, $signedTtdIds, true);

        // Image area — kalau user tidak allowed sign, disable interaksi + tampil badge lock
        if ($canSignThisTtd && !$ttdRecorded) {
            $actionsHtml = '<div class="ttd-actions">
                <button type="button" class="btn-edit" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')">&#9998; ' . h(t('actions.edit', [], 'Edit')) . '</button>
                <button type="button" class="btn-delete" onclick="clearTtd(\'' . h($ttdId) . '\')">&#10005; ' . h(t('actions.delete', [], 'Delete')) . '</button>
            </div>';
            $imgContent = $ttdIsImage
                ? '<img src="' . h($ttdDisplaySrc) . '" class="ttd-signature" alt="TTD">' . $actionsHtml
                : '<div class="ttd-canvas-placeholder" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')"></div>';
        } elseif ($ttdRecorded) {
            // Tercatat: tampilkan apa adanya, dengan lencana yang menyebut
            // ALASANNYA. "Tidak bisa diklik" tanpa penjelasan terbaca sebagai rusak.
            $sealBadge = '<div class="ttd-sealed-badge" title="' . h(t('title.signature_sealed', [], 'Already signed and recorded — create a new version to revise')) . '"><i class="fa fa-lock"></i></div>';
            $imgContent = $ttdIsImage
                ? '<img src="' . h($ttdDisplaySrc) . '" class="ttd-signature" alt="TTD">' . $sealBadge
                : '<div class="ttd-canvas-placeholder ttd-locked" style="cursor:default;background:#f9fafb;position:relative;">' . $sealBadge . '</div>';
        } else {
            // Not allowed — read-only display, no edit button, no clickable canvas
            $rolesHint = $allowedRolesRaw !== '' ? ' (' . h($allowedRolesRaw) . ')' : '';
            $lockBadge = '<div class="ttd-locked-badge" style="position:absolute;top:2px;right:2px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:600;z-index:5;pointer-events:none;" title="' . h(t('title.no_sign_permission', ['roles' => $rolesHint], 'Not authorized to sign{roles}')) . '"><i class="fa fa-lock"></i></div>';
            $imgContent = $ttdIsImage
                ? '<img src="' . h($ttdDisplaySrc) . '" class="ttd-signature" alt="TTD">' . $lockBadge
                : '<div class="ttd-canvas-placeholder ttd-locked" style="cursor:not-allowed;background:#f3f4f6;position:relative;" title="' . h(t('title.no_sign_permission_as', ['label' => $label, 'roles' => $rolesHint], 'Not authorized to sign as {label}{roles}')) . '">' . $lockBadge . '</div>';
        }
        $imgDisplay = ($activeMode === 'image' || !$hasQr) ? '' : 'display:none;';
        $imgArea = '<div class="ttd-area-image" id="ttd_area_image_' . h($ttdId) . '" style="' . $imgDisplay . '">' . $imgContent . '</div>';

        // Per-document QR content resolution: dbFields -> POST -> GET -> fallback pattern.
        // Key convention: <namaField>_qr (mengikuti nama TTD yang user set, cukup suffix _qr di URL)
        $qrContentKey = $namaField . '_qr';
        $qrContent = $pick($qrContentKey);

        // Per-signature verification pointer.
        //
        // A signature QR carries a pointer to *this signature*, not to the
        // document. Four signers on one page must not produce four identical
        // codes — a reader scanning "Head of Unit" has to land on the record for
        // Head of Unit, with the signer, the role, and the time it was signed.
        //
        // Signed content stays out of the code deliberately. Anything printed
        // inside a QR is unauthenticated text: a name or a timestamp in there
        // proves nothing, because anyone can generate a code saying anything.
        // The pointer is the only part that gains its meaning from the server.
        //
        // This is not a template pattern, and it is not the standalone QR's
        // `{verify_url}` marker. Those two exist for different jobs and answering
        // both from one value is what made the earlier attempt wrong.
        // `$conn` diperiksa, bukan diasumsikan. Ia parameter ber-default null,
        // dan hook consumer-nya mysqli-only — memanggilnya dengan null memberi
        // "Call to a member function query() on null", pesan yang menuding tempat
        // yang sama sekali salah.
        $ttdVerifyUrl = '';
        $__slugTtd = '';
        if ($conn instanceof \mysqli && function_exists('doc_verify_ensure_slug') && (int) $doc_id > 0) {
            $__slugTtd = (string) doc_verify_ensure_slug($conn, (int) $doc_id);
            if ($__slugTtd !== '' && function_exists('doc_verify_build_url')) {
                $ttdVerifyUrl = doc_verify_build_url($__slugTtd, $ttdId);
            }
        }

        // Auto-heal nilai tersimpan: base URL yang berubah, atau kunci HMAC yang
        // dirotasi, membuat QR tercetak gagal diverifikasi. Keduanya sembuh
        // dengan membangun ulang URL-nya untuk slot ini.
        if ($qrContent !== '' && $__slugTtd !== '' && function_exists('doc_verify_resolve_qr_value')) {
            $qrContent = doc_verify_resolve_qr_value($qrContent, $__slugTtd, $ttdId);
        }

        // Compute resolved pattern (used as placeholder hint, and also fallback when user leaves input empty)
        $resolvedPattern = '';
        if ($qrDataTpl !== '') {
            $resolvedPattern = preg_replace_callback('/\{([^}]+)\}/', function($pm) use ($pick, $conn) {
                $key = $pm[1];
                // Magic variable: {verify_url} → URL verifikasi dokumen (untuk QR scannable)
                if ($key === 'verify_url' || $key === 'verify') {
                    $docId = (int)($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0);
                    if ($docId > 0 && function_exists('doc_verify_ensure_slug')) {
                        $slug = doc_verify_ensure_slug($conn, $docId);
                        return $slug ? doc_verify_build_url($slug) : '';
                    }
                    return '';
                }
                return $pick($key);
            }, $qrDataTpl);
        }

        // QR area — konten QR disembunyikan, ada tombol edit
        $qrArea = '';
        if ($hasQr) {
            $qrDisplay = $activeMode === 'qr' ? '' : 'display:none;';
            $patternForJs = addslashes($resolvedPattern);

            // Pointer otomatis hanya boleh muncul kalau slot ini SUDAH
            // ditandatangani. QR yang menunjuk record yang belum ada adalah
            // klaim palsu: yang memindai mendapat "tanda tangan tidak dikenal",
            // padahal kertasnya terlihat sudah bertanda tangan.
            //
            // Isi tersimpan dan pattern template tidak ikut aturan ini. Keduanya
            // nilai yang sengaja diisi orang atau ditetapkan template, dan tidak
            // pernah mengklaim ada record di server — cuma pointer otomatis yang
            // mengklaim itu. Aturan ini juga yang membuat dokumen lama dengan QR
            // yang diisi tangan tetap tampil apa adanya.
            $qrNeedsSigning = !$ttdIsSigned && $qrContent === '' && $qrDataTpl === '';

            // `data-ttd-verify-url` adalah pilihan terakhir saat tidak ada isi
            // tersimpan dan template tidak menyetel pattern. Ditaruh di atribut,
            // bukan di input tersembunyi, supaya tidak pernah ikut terkirim dan
            // membeku jadi nilai tersimpan — nilainya selalu dihitung ulang dari
            // slug + ttd_id di setiap render.
            $qrArea = '<div class="ttd-area-qr" id="ttd_area_qr_' . h($ttdId) . '" style="' . $qrDisplay . '"'
                . ' data-ttd-verify-url="' . h($ttdVerifyUrl) . '"'
                . ' data-ttd-signed="' . ($ttdIsSigned ? '1' : '0') . '">
                <div class="ttd-qr-preview" id="ttd_qr_preview_' . h($ttdId) . '">' . (
                    $qrNeedsSigning
                        ? ($canSignThisTtd
                            ? '<button type="button" class="ttd-qr-sign-cta" onclick="openSign(\'' . h($ttdId) . '\', \'' . h($label) . '\')">'
                                . '<span class="ttd-qr-sign-cta-icon">&#10003;</span>'
                                . '<span>' . h(t('ttd.sign_with_qr', [], 'Sign with QR')) . '</span></button>'
                            : '<small style="color:#9ca3af;font-size:10px;">' . h(t('ttd.not_signed_yet', [], 'Not signed yet')) . '</small>')
                        : ''
                ) . '</div>
                <input type="hidden" class="ttd-qr-content-input" name="' . h($qrContentKey) . '" id="ttd_qr_content_' . h($ttdId) . '" value="' . h($qrContent) . '">' . (
                    // Isi QR hidup di `<namaField>_qr`, yang ada di dalam
                    // `field_values` — jadi ia ikut hash tanda tangan. Setelah
                    // slotnya tercatat, mengubahnya ditolak server, dan yang lebih
                    // penting: kalau bisa diubah, QR pada dokumen yang SUDAH
                    // ditandatangani bisa diarahkan ke mana saja dengan diketik
                    // ulang. Itu bukan soal kerapian.
                    $ttdRecorded
                        ? ''
                        : '<button type="button" class="btn-edit-qr-content" onclick="editQrContent(\'' . h($ttdId) . '\', \'' . h($patternForJs) . '\')" title="' . h(t('title.edit_qr_content', [], 'Edit QR content')) . '">&#9998; ' . h(t('ttd.edit_qr_button', [], 'Edit QR')) . '</button>'
                ) . '
            </div>';
        }

        // Nama di bawah tanda tangan tidak bisa diedit setelah slot ini
        // ditandatangani.
        //
        // Bukan pengetatan sewenang-wenang: hash tanda tangan mencakup seluruh
        // `fields`, dan nama ini salah satunya. Kalau bisa diubah sesudahnya,
        // tanda tangan yang sama bisa diatributkan ke orang lain — dan verifikasi
        // akan melaporkan `tampered` untuk perubahan yang aplikasinya sendiri
        // mengizinkan. Yang lebih buruk dari melarang adalah membolehkan lalu
        // menyebutnya palsu.
        //
        // Sebelum ini field-nya tetap terlihat bisa diedit, jadi petugas mengetik
        // namanya, menekan simpan, dan ditolak — tanpa jalan maju, karena nama
        // yang benar tidak akan pernah bisa masuk. Sekarang namanya diminta di
        // dalam modal tanda tangan, jadi ia sudah selesai saat menandatangani.
        // Pola yang sama dipakai DocuSign dan Adobe Sign: nama penanda tangan
        // ditangkap di dialog penandatanganan, bukan sebagai isi dokumen yang
        // diedit belakangan.
        $namaEditable = $ttdIsSigned ? 'false' : 'true';
        $namaClass = $ttdIsSigned ? 'f ttd-name-locked' : 'f';
        $namaTitle = $ttdIsSigned
            ? ' title="' . h(t('title.name_locked_after_signing', [], 'Already signed — the name is part of what the signature covers. Create a new version to revise.')) . '"'
            : '';

        return '<div class="' . $ttdClass . '" style="' . $style . '">
            <div class="ttd-label">' . h($label) . '</div>
            ' . $modeToggle . '
            <div class="ttd-img" id="preview_' . h($ttdId) . '">' . $imgArea . $qrArea . '</div>
            <div class="ttd-name">(<span class="' . $namaClass . '" contenteditable="' . $namaEditable . '" data-field="' . h($namaField) . '"' . $namaTitle . '>' . ($namaValue ?: '') . '</span><input type="hidden" name="' . h($namaField) . '" id="ttd_nama_' . h($ttdId) . '" value="' . $namaValue . '">)</div>
            <input type="hidden" name="' . h($ttdId) . '" id="ttd_' . h($ttdId) . '" value="' . h($ttdImg) . '">
            <input type="hidden" name="_ttd_mode_' . h($ttdId) . '" id="ttd_mode_' . h($ttdId) . '" value="' . h($activeMode) . '">
            <input type="hidden" name="_ttd_qr_data_' . h($ttdId) . '" id="ttd_qr_data_' . h($ttdId) . '" value="' . h($qrDataTpl) . '">
        </div>';
    }, $html);

    // Replace MATERAI placeholders with upload UI / empty area
    // Pattern: <div class="materai-placeholder ..." data-materai="X" data-label="..." data-mode="upload|kosong" data-pos-x data-pos-y data-width data-height>...nested div...</div>
    $html = preg_replace_callback('/<div([^>]*class="[^"]*materai-placeholder[^"]*"[^>]*)>\s*<div[^>]*>[\s\S]*?<\/div>\s*<\/div>/s', function($m) use ($dbFields, $param_is_locked) {
        $tag = $m[1];
        preg_match('/class="([^"]*)"/', $tag, $cm); $classes = $cm[1] ?? '';
        preg_match('/data-materai="([^"]+)"/', $tag, $im); $materaiId = $im[1] ?? '';
        // Label may be empty/missing (optional)
        $label = '';
        if (preg_match('/data-label="([^"]*)"/', $tag, $lm)) $label = $lm[1];
        preg_match('/data-mode="([^"]+)"/', $tag, $mm); $matMode = $mm[1] ?? 'upload';
        preg_match('/data-pos-x="([^"]+)"/', $tag, $xm); $posX = $xm[1] ?? '350';
        preg_match('/data-pos-y="([^"]+)"/', $tag, $ym); $posY = $ym[1] ?? '500';
        preg_match('/data-width="([^"]+)"/', $tag, $wm); $matW = max(20, intval($wm[1] ?? 100));
        preg_match('/data-height="([^"]+)"/', $tag, $hm); $matH = max(20, intval($hm[1] ?? 140));

        if (!$materaiId) return $m[0];

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind   = strpos($classes, 'behind') !== false;

        $cls = 'materai-item-inline';
        $style = '';
        if ($isFloating) {
            $cls = 'materai-item-floating';
            $cls .= $isBehind ? ' materai-behind' : ' materai-front';
            $adjX = max(0, intval($posX));
            $adjY = max(0, intval($posY));
            $style = "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        // Per-document materai state from data_fields JSON
        $imgKey    = '_materai_' . $materaiId . '_image';
        $serialKey = '_materai_' . $materaiId . '_serial';
        $uploadKey = '_materai_' . $materaiId . '_uploaded_at';

        $matImg = '';
        if (isset($dbFields[$imgKey]) && $dbFields[$imgKey] !== '') {
            // Use safeDataImg() if available to validate base64
            $rawImg = $dbFields[$imgKey];
            if (function_exists('safeDataImg')) {
                $matImg = safeDataImg($rawImg);
            } else {
                $matImg = preg_match('#^data:image/(png|jpe?g|gif);base64,#', $rawImg) ? $rawImg : '';
            }
        }
        $matSerial = h($dbFields[$serialKey] ?? '');

        $fileInputId = 'materai_file_' . h($materaiId);
        $sizeStyle = "width:{$matW}px;height:{$matH}px;";

        $imgInner = '';
        if ($matMode === 'kosong') {
            // Always show the empty area (for manual stamping)
            $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="' . h(t('title.materai_manual_area', [], 'Materai area (manual stamp)')) . '"></div>';
        } else {
            // Upload mode
            if ($matImg) {
                $imgInner = '<img src="' . h($matImg) . '" class="materai-image" alt="Materai" style="max-width:' . $matW . 'px;max-height:' . $matH . 'px;">';
                if (!$param_is_locked) {
                    $imgInner .= '<div class="materai-actions">
                        <label for="' . $fileInputId . '" class="btn-edit" style="cursor:pointer;">&#9998; ' . h(t('materai.replace', [], 'Replace')) . '</label>
                        <button type="button" class="btn-delete" onclick="clearMaterai(\'' . h($materaiId) . '\')">&#10005; ' . h(t('actions.delete', [], 'Delete')) . '</button>
                    </div>';
                }
            } else {
                if (!$param_is_locked) {
                    // Use <label for=""> so click reliably opens hidden file input
                    $imgInner = '<label for="' . $fileInputId . '" class="materai-upload-box" style="' . $sizeStyle . 'cursor:pointer;">
                        <strong>' . t('materai.upload_prompt', [], 'UPLOAD<br>e-MATERAI') . '</strong>
                    </label>';
                } else {
                    $imgInner = '<div class="materai-empty-box" style="' . $sizeStyle . '" title="' . h(t('title.materai_not_uploaded', [], 'Not uploaded yet')) . '"></div>';
                }
            }
        }

        $hidden  = '<input type="hidden" name="' . h($imgKey) . '" id="materai_img_' . h($materaiId) . '" value="' . h($matImg) . '">';
        $hidden .= '<input type="hidden" name="' . h($uploadKey) . '" id="materai_upload_' . h($materaiId) . '" value="' . h($dbFields[$uploadKey] ?? '') . '">';

        $fileInput = '';
        $serialInput = '';
        if ($matMode === 'upload' && !$param_is_locked) {
            $fileInput = '<input type="file" id="' . $fileInputId . '" accept="image/png,image/jpeg" style="position:absolute;left:-9999px;opacity:0;width:0;height:0;" onchange="handleMateraiUpload(this, \'' . h($materaiId) . '\')">';
            $serialInput = '<input type="text" class="materai-serial-input" name="' . h($serialKey) . '" id="materai_serial_' . h($materaiId) . '" value="' . $matSerial . '" placeholder="' . h(t('placeholder.materai_serial', [], 'Serial No. (optional)')) . '" maxlength="30">';
        } elseif ($matMode === 'upload' && $param_is_locked) {
            // Locked: still expose serial as readonly hidden so it persists in form data
            $serialInput = '<input type="hidden" name="' . h($serialKey) . '" value="' . $matSerial . '">';
        }

        // Optional label — hide entirely if empty
        $labelHtml = ($label !== '') ? '<div class="materai-label">' . h($label) . '</div>' : '';

        return '<div class="materai-wrap ' . $cls . '" data-materai-id="' . h($materaiId) . '" data-materai-mode="' . h($matMode) . '" style="' . $style . '">
            ' . $labelHtml . '
            <div class="materai-img" id="materai_preview_' . h($materaiId) . '" style="width:' . $matW . 'px;height:' . $matH . 'px;">' . $imgInner . '</div>
            ' . $serialInput . '
            ' . $hidden . $fileInput . '
        </div>';
    }, $html);

    // Replace QR placeholders with QR code + editable input
    $html = preg_replace_callback('/<span[^>]*class="([^"]*qr-placeholder[^"]*)"[^>]*data-qr="([^"]+)"(?:[^>]*data-width="([^"]+)")?(?:[^>]*data-pos-mode="([^"]+)")?(?:[^>]*data-pos-x="([^"]+)")?(?:[^>]*data-pos-y="([^"]+)")?[^>]*>.*?<\/span>/s', function($m) use ($posOffsetQR, $conn, $doc_id) {
        $classes = $m[1] ?? '';
        $fieldName = $m[2];
        $width = $m[3] ?? '80px';
        $posX = $m[5] ?? '20';
        $posY = $m[6] ?? '20';

        // Get raw field value for QR data (not escaped, for QR generation)
        $qrDataRaw = vRaw($fieldName);

        // Auto-heal stale verify URL / resolve {verify_url} marker → pakai base URL current.
        // Kalau nilai tersimpan mengandung /verifikasi/<slug>?s=<hex> dengan base yang beda
        // (mis. dulu di-save saat local dev), auto-replace ke DOC_VERIFY_BASE_URL sekarang.
        if ($qrDataRaw !== '' && function_exists('doc_verify_resolve_qr_value') && function_exists('doc_verify_ensure_slug')) {
            $__slugQR = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slugQR) $qrDataRaw = doc_verify_resolve_qr_value($qrDataRaw, $__slugQR);
        }

        $isFloating = strpos($classes, 'floating') !== false;
        $isBehind = strpos($classes, 'behind') !== false;

        $qrClass = 'qr-item-inline';
        $style = "width:" . h($width) . ";";
        if ($isFloating) {
            $qrClass = 'qr-item-floating';
            $qrClass .= $isBehind ? ' qr-behind' : ' qr-front';
            // Apply offset correction (QR has 8px padding in generator)
            $adjX = max(0, intval($posX) - $posOffsetQR);
            $adjY = max(0, intval($posY) - $posOffsetQR);
            $style .= "position:absolute;left:{$adjX}px;top:{$adjY}px;";
        }

        // QR image or placeholder — klik area → buka modal untuk generate QR
        // Modal support: default isi = verify_url dokumen (untuk kemudahan) atau user override manual
        $qrImgHtml = '';
        if ($qrDataRaw) {
            $qrSrc = ezdoc_qr_data_uri($qrDataRaw, 200, 5);
            // Kalau sudah ada value → tampil QR. Klik → modal untuk edit
            $qrImgHtml = '<img src="' . $qrSrc . '" class="qr-img" style="width:100%;height:auto;cursor:pointer;" alt="QR" id="qrimg_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" title="' . h(t('title.click_edit_qr', [], 'Click to edit QR content')) . '">';
        } else {
            // Kosong → placeholder clickable. Klik → modal muncul dengan default verify_url
            $qrImgHtml = '<div class="qr-canvas-placeholder" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="' . h(t('title.click_generate_qr', [], 'Click to generate QR (default: verification URL)')) . '"></div>';
        }

        // Klik container QR → buka modal (onclick di parent, jadi bekerja bahkan setelah updateQrPreview replace innerHTML)
        return '<div class="' . $qrClass . '" style="' . $style . '" data-qr-field="' . h($fieldName) . '">
            <div class="qr-preview" id="qrpreview_' . h($fieldName) . '" onclick="openQrFieldModal(\'' . h($fieldName) . '\')" style="cursor:pointer;" title="' . h(t('title.click_fill_edit_qr', [], 'Click to fill/edit QR')) . '">' . $qrImgHtml . '</div>
            <div class="qr-input" style="display:none;">
                <input type="text" class="qr-field" id="qrinput_' . h($fieldName) . '" name="' . h($fieldName) . '"
                    value="' . h($qrDataRaw) . '" placeholder="' . h(t('placeholder.qr_data', [], 'QR data...')) . '" onchange="updateQrPreview(\'' . h($fieldName) . '\')">
            </div>
        </div>';
    }, $html);

    // ===== TABLEDB (enhanced markers v0.9.14b) =====
    // Process <tr> rows dgn {{tabledb.<ns>.<selector>[|format]}} markers.
    // Supports: raw col, formula (=qty*harga), aggregate (sum:harga), format
    // pipes (|currency|number|percent|date|datetime).
    // Must happen BEFORE field-placeholder replacement.
    if (!empty($tableDbQueries) && is_array($tableDbQueries)) {
        $html = processTabledbRows($html, $tableDbQueries, $param_norm, $param_nopen, $dbFields, $conn);
    }

    // Field yang sudah dibekukan tanda tangan yang ada. `null` berarti semuanya
    // (tanda tangan v1 mencakup seluruh field).
    //
    // Dipakai untuk membuat field itu TERLIHAT beku. Sebelumnya pembekuan baru
    // terasa saat menyimpan: petugas mengetik, menekan simpan, dan ditolak. Batas
    // sebenarnya tetap guard §7b di sisi simpan — ini supaya tidak ada yang
    // menabraknya tanpa peringatan.
    $frozenFields = ezdoc_frozen_field_keys($conn, (int) $doc_id);

    // Replace field placeholders with appropriate input types
    $html = preg_replace_callback('/<span[^>]*class=["\']field-placeholder[^"\']*["\']([^>]*)>\{\{([^}:]+)(?::text)?\}\}<\/span>/', function($m) use ($frozenFields) {
        $attrs = $m[1];
        $name = $m[2];
        $val = v($name);

        // Parse attributes
        $type = 'text';
        $options = '';
        $label = '';
        $default = '';
        if (preg_match('/data-type=["\']([^"\']+)["\']/', $attrs, $tm)) $type = $tm[1];
        if (preg_match('/data-options=["\']([^"\']+)["\']/', $attrs, $om)) $options = html_entity_decode($om[1]);
        if (preg_match('/data-label=["\']([^"\']+)["\']/', $attrs, $lm)) $label = html_entity_decode($lm[1]);
        if (preg_match('/data-default=["\']([^"\']+)["\']/', $attrs, $dm)) $default = html_entity_decode($dm[1]);

        // Validation attributes (#6) — pass through to renderFieldInput as 6th arg
        $validation = [];
        if (preg_match('/data-required=["\']1["\']/', $attrs)) $validation['required'] = '1';
        if (preg_match('/data-min=["\']([^"\']+)["\']/', $attrs, $mn)) $validation['min'] = $mn[1];
        if (preg_match('/data-max=["\']([^"\']+)["\']/', $attrs, $mx)) $validation['max'] = $mx[1];
        if (preg_match('/data-pattern=["\']([^"\']+)["\']/', $attrs, $pt)) $validation['pattern'] = html_entity_decode($pt[1]);
        if (preg_match('/data-error-msg=["\']([^"\']+)["\']/', $attrs, $em)) $validation['errorMsg'] = html_entity_decode($em[1]);

        // If no value from db/post/get, use resolved default
        if ($val === '' && $default !== '') {
            $val = h(resolveDefault($default));
        }

        $isFrozen = $frozenFields === null || isset($frozenFields[$name]);

        return renderFieldInput($name, $type, $val, $options, $label, $validation, $isFrozen);
    }, $html);

    // Handle bare {{field}} without span wrapper (fallback) - text type
    $html = preg_replace_callback('/\{\{([^}:]+)(?::text)?\}\}/', function($m) use ($frozenFields) {
        $name = $m[1];
        $val = v($name);
        $isFrozen = $frozenFields === null || isset($frozenFields[$name]);

        return renderFieldInput($name, 'text', $val, '', '', [], $isFrozen);
    }, $html);

    // Process <p> tags - mark floating-only for paragraphs with only floating elements
    $html = preg_replace_callback('/<p([^>]*)>([\s\S]*?)<\/p>/i', function($match) {
        $attrs = $match[1];
        $content = $match[2];

        // Check if contains floating elements
        $floatingClasses = ['ttd-item-floating', 'qr-item-floating', 'logo-floating'];
        $hasFloating = false;
        foreach ($floatingClasses as $fc) {
            if (strpos($content, $fc) !== false) {
                $hasFloating = true;
                break;
            }
        }

        // If no floating elements, keep unchanged (including empty <p> for spacing)
        if (!$hasFloating) {
            return $match[0];
        }

        // Get text content only (strip all tags)
        $textOnly = strip_tags($content);
        $textOnly = preg_replace('/&nbsp;/i', ' ', $textOnly);
        $textOnly = trim($textOnly);

        // If has floating element but no text content, mark as floating-only
        if (empty($textOnly)) {
            if (strpos($attrs, 'class=') !== false) {
                $attrs = preg_replace('/class=["\']([^"\']*)["\']/', 'class="$1 floating-only"', $attrs);
            } else {
                $attrs .= ' class="floating-only"';
            }
            return '<p' . $attrs . '>' . $content . '</p>';
        }

        return $match[0]; // Has floating + text, keep unchanged
    }, $html);

    return $html;
}

/**
 * Bikin satu field yang sudah dirender jadi tidak bisa diisi.
 *
 * Memakai mekanisme yang SAMA dengan dokumen terkunci (`edit-off`), cuma
 * cakupannya satu field. Itu disengaja: sebelumnya field beku dirender ulang
 * sebagai teks biasa, dan itu jadi cara KEDUA untuk pekerjaan yang sama —
 * dengan hasil yang lebih buruk. Field tanggal tampil `2026-08-03` mentah,
 * padahal input aslinya menampilkan format lokal browser.
 *
 * Tiga hal yang harus dilakukan bersamaan, karena masing-masing sendirian bisa
 * dilewati:
 *   - `readonly` menghentikan pengetikan
 *   - `contenteditable="false"` untuk field berbasis span
 *   - `pointer-events: none` (dari CSS) menghentikan klik dan pemilih tanggal
 *
 * Nilainya tetap terkirim karena input-nya tetap input — tidak perlu input
 * tersembunyi tambahan, dan tidak ada nilai yang hilang saat menyimpan.
 */
function freezeFieldHtml(string $html, string $title): string
{
    $t = ' title="' . h($title) . '"';

    // Span contenteditable → matikan penyuntingannya, bukan sekadar tampilannya.
    $html = str_replace('contenteditable="true"', 'contenteditable="false"' . $t, $html);

    // Input teks/date/time/number → readonly. `readonly` dipilih daripada
    // `disabled` karena input yang disabled TIDAK ikut terkirim, dan menyimpan
    // dokumen akan terbaca sebagai mengosongkan field.
    $html = preg_replace('/<input(?![^>]*\btype="hidden")/', '<input readonly' . $t, $html);

    // Checkbox, radio, dan select tidak punya `readonly` yang berlaku. CSS yang
    // mematikan interaksinya; nilainya tetap terkirim karena tidak di-disable.
    $html = str_replace('<select', '<select' . $t, (string) $html);

    return '<span class="field-frozen">' . $html . '</span>';
}

// Render field input based on type. $validation = ['required'=>'1','min'=>...,'max'=>...,'pattern'=>...,'errorMsg'=>...]
//
// $frozen: field ini dicakup tanda tangan yang sudah ada, jadi mengubahnya akan
// ditolak guard §7b. Dirender tidak bisa diedit supaya penolakan itu tidak
// datang sebagai kejutan setelah petugas mengetik. Batas sebenarnya tetap di
// sisi simpan — ini keterlihatan, bukan keamanan.
function renderFieldInput($name, $type, $val, $options, $label, $validation = [], $frozen = false) {
    $html = renderFieldInputRaw($name, $type, $val, $options, $label, $validation);

    if (!$frozen) {
        return $html;
    }

    // Dibekukan SESUDAH dirender, bukan dirender berbeda. Field beku dan field
    // biasa harus tetap satu markup — begitu keduanya dibangun terpisah, yang
    // satu akan tertinggal dari yang lain (dan itu sudah terjadi sekali: versi
    // teks-biasa menampilkan tanggal mentah `2026-08-03` alih-alih format lokal).
    return freezeFieldHtml(
        $html,
        t('title.field_frozen_by_signature', [], 'Already signed — the content can no longer change. Create a new version to revise.')
    );
}

function renderFieldInputRaw($name, $type, $val, $options, $label, $validation = []) {
    $hName = h($name);
    $hVal = h($val);

    // Build validation data attributes (rendered as data-v-* on the span/input)
    $vAttrs = '';
    if (!empty($validation['required'])) $vAttrs .= ' data-v-required="1"';
    if (isset($validation['min']) && $validation['min'] !== '') $vAttrs .= ' data-v-min="' . h($validation['min']) . '"';
    if (isset($validation['max']) && $validation['max'] !== '') $vAttrs .= ' data-v-max="' . h($validation['max']) . '"';
    if (!empty($validation['pattern'])) $vAttrs .= ' data-v-pattern="' . h($validation['pattern']) . '"';
    if (!empty($validation['errorMsg'])) $vAttrs .= ' data-v-error-msg="' . h($validation['errorMsg']) . '"';
    $reqMark = !empty($validation['required']) ? '<span class="field-required-mark" title="' . h(t('validation.required', [], 'Required')) . '">*</span>' : '';

    switch ($type) {
        case 'number':
            // Filter non-numeric on input (contenteditable tidak respect type="number").
            // Allowed: digits + optional decimal point + leading minus (industri standard).
            return '<span class="f-wrap"' . $vAttrs . '><span class="f field-number" contenteditable="true" inputmode="decimal" data-field="' . $hName . '" oninput="filterNumeric(this)" onpaste="handleNumericPaste(event)">' . ($hVal ?: '') . '</span><input type="hidden" name="' . $hName . '" value="' . $hVal . '">' . $reqMark . '</span>';

        case 'date':
            return '<span class="f-wrap"' . $vAttrs . '><input type="date" class="field-date" name="' . $hName . '" value="' . $hVal . '" data-field="' . $hName . '">' . $reqMark . '</span>';

        case 'time':
            // `type="time"` menyimpan dan mengirim HH:MM 24 jam apa pun locale
            // browsernya, sementara yang ditampilkan mengikuti setelan pengguna.
            // Itu yang membuat nilai tersimpan tidak berubah arti saat dokumen
            // yang sama dibuka di perangkat dengan format jam berbeda.
            return '<span class="f-wrap"' . $vAttrs . '><input type="time" class="field-time" name="' . $hName . '" value="' . $hVal . '" data-field="' . $hName . '">' . $reqMark . '</span>';

        case 'checkbox':
            $checked = ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'Ya') ? ' checked' : '';
            $labelHtml = $label !== '' ? ' ' . h($label) : '';
            return '<label class="field-checkbox-wrap"' . $vAttrs . '><input type="checkbox" class="field-checkbox" name="' . $hName . '" value="1"' . $checked . ' data-field="' . $hName . '">' . $labelHtml . $reqMark . '</label>';

        case 'radio':
            $opts = array_map('trim', explode(',', $options));
            $html = '<span class="field-radio-wrap" data-field="' . $hName . '"' . $vAttrs . '>';
            foreach ($opts as $opt) {
                $hOpt = h($opt);
                $checked = ($val === $opt) ? ' checked' : '';
                $html .= '<label class="field-radio-label"><input type="radio" name="' . $hName . '" value="' . $hOpt . '"' . $checked . '> ' . $hOpt . '</label> ';
            }
            $html .= $reqMark . '</span>';
            return $html;

        case 'select':
            $opts = array_map('trim', explode(',', $options));
            $html = '<select class="field-select" name="' . $hName . '" data-field="' . $hName . '">';
            $html .= '<option value="">' . h(t('field.select_placeholder', [], '-- Select --')) . '</option>';
            foreach ($opts as $opt) {
                $hOpt = h($opt);
                $selected = ($val === $opt) ? ' selected' : '';
                $html .= '<option value="' . $hOpt . '"' . $selected . '>' . $hOpt . '</option>';
            }
            $html .= '</select>';
            return '<span class="f-wrap"' . $vAttrs . '>' . $html . $reqMark . '</span>';

        default: // text
            return '<span class="f-wrap"' . $vAttrs . '><span class="f" contenteditable="true" data-field="' . $hName . '">' . ($hVal ?: '') . '</span><input type="hidden" name="' . $hName . '" value="' . $hVal . '">' . $reqMark . '</span>';
    }
}

$currentUrl = "?template_id=$template_id";
if ($doc_id > 0) $currentUrl .= "&doc_id=$doc_id";
elseif ($param_norm && $param_nopen) {
    $currentUrl .= "&norm=" . urlencode($param_norm) . "&nopen=" . urlencode($param_nopen);
    if ($param_label !== '-') $currentUrl .= "&label=" . urlencode($param_label);
}

// ============================================
// PDF VIEW MODE
// ============================================
if (isset($_GET['view']) && $_GET['view'] === 'pdf') {
    // Generate PDF version
    $forceDownload = isset($_GET['download']) && $_GET['download'] == '1';

    // Merge GET parameters into dbFields (GET takes priority for auto-fill)
    $pdfFields = $dbFields;
    foreach ($_GET as $key => $val) {
        // Skip system parameters
        if (in_array($key, ['view', 'debug', 'download', 'template_id', 'doc_id', 'norm', 'nopen'])) continue;
        if (is_string($val) && $val !== '') {
            $pdfFields[$key] = $val;
        }
    }

    // Nilai dari permintaan dirakit DI SINI, lalu diserahkan.
    //
    // Ketiganya salinan persis dari yang dulu tertanam di dalam perendernya,
    // supaya keluarannya hari ini tidak bergeser sedikit pun. Yang berpindah
    // cuma siapa yang memutuskan — dan itu yang membuat jalur pembekuan bisa
    // memanggil perender yang sama tanpa membawa permintaan cetak.
    $permintaanPdf = [
        'values'         => ezdoc_pdf_request_values($_POST, $_GET),
        // Dipisah dari 'values' dengan sengaja: yang ini menanyakan "apakah
        // NILAINYA tepat '1'", bukan "apa nilai pertama yang tidak kosong".
        // Digabung, `_show_verify_qr=0` di POST akan menutupi `=1` di GET.
        'show_verify_qr' => ($_POST['_show_verify_qr'] ?? '') === '1'
                            || ($_GET['_show_verify_qr'] ?? '') === '1',
        // Kunci yang berbeda per sumber, dan pakai isset bukan tidak-kosong.
        'verify_doc_id'  => (int) ($_GET['doc_id'] ?? $_POST['_doc_id'] ?? 0),
    ];

    // Render content for PDF (with filled values, no form elements)
    $pdfContent = renderContentForPdf($templateHtml, $logos, $logoSizes, $configTtd, $ttdValues, $pdfFields, [], $conn, $param_norm, $param_nopen, $tableDbQueries, (int)$doc_id, $permintaanPdf);

    // Kerangka dokumennya dirakit di lib/pdf_compose.php — CSS, @page, tiga titik
    // sisipan consumer, blok tanda tangan format lama, dan penutupnya.
    //
    // Dipindah ke sana supaya pembekuan dokumen bisa merakit HTML yang sama dari
    // tempat yang sama sekali berbeda; lihat docs/260811-beku-pdf-tiap-tanda-tangan.md.
    // Perenderan ISI masih tinggal di berkas ini, tapi sejak Tahap 1b ia tidak
    // lagi membaca permintaan — nilainya diserahkan lewat $permintaanPdf.
    $pdfHtml = ezdoc_compose_pdf_html([
        'template'             => $template,
        'doc_id'               => (int) $doc_id,
        'paper'                => $paperDim,
        'pad_top'              => $padTop,
        'pad_right'            => $padRight,
        'pad_bottom'           => $padBottom,
        'pad_left'             => $padLeft,
        'content'              => $pdfContent,
        'config_ttd'           => $configTtd,
        'ttd_values'           => $ttdValues,
        'pdf_fields'           => $pdfFields,
        'has_ttd_placeholders' => $hasTtdPlaceholders,
        'conn'                 => $conn ?? null,
    ]);


    // Debug mode: view=html to see raw HTML before PDF conversion
    if (isset($_GET['debug']) && $_GET['debug'] === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo $pdfHtml;
        exit;
    }

    // Generate filename — config-driven prefix, safe slug of label
    // spec: ezdoc-spec/generate/pdf_filename.md
    $labelPart = ($param_label !== '-' && $param_label !== '') ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $param_label) : '';
    $filename = $pdfFilenamePrefix . '_' . ($param_norm ?: 'draft') . $labelPart . '_' . date('Ymd_His') . '.pdf';

    // echo $pdfHtml;
    // exit;

    // Render PDF — library-native pattern (Ezdoc\Rendering\PdfRenderer contract).
    //
    // Priority:
    //   1. Consumer-injected renderer via Context::withPdf() → $ctx->pdf
    //   2. Ezdoc-native DompdfRenderer default (kalau dompdf/dompdf composer
    //      package tersedia — most common case)
    //   3. Error page (no PDF backend available)
    //
    // Zero dependency ke consumer's local functions (generatePDF or bootstrap-specific helpers).
    // spec: docs/PDF-RENDERING.md
    $paperMm     = [$paperDim['width'], $paperDim['height']];
    // $orientation already set at header parsing (line ~339) from configHeader.
    // Fallback ke portrait kalau tidak set (defensive).
    $orientation = $orientation ?? 'portrait';

    $pdfRenderer = null;
    if (isset($ctx->pdf) && $ctx->pdf instanceof \Ezdoc\Rendering\PdfRenderer) {
        $pdfRenderer = $ctx->pdf;
    } elseif (isset($ctx->pdf) && is_object($ctx->pdf) && method_exists($ctx->pdf, 'stream')) {
        // Backward-compat: consumer wired object dgn stream() method but
        // tidak implement PdfRenderer interface — duck-typing accept.
        $pdfRenderer = $ctx->pdf;
    } elseif (class_exists('\\Dompdf\\Dompdf')) {
        // Auto-instantiate ezdoc-native DompdfRenderer (default fallback).
        // basePath = views/document/../.. = ezdoc root, untuk relative asset resolution.
        $pdfRenderer = new \Ezdoc\Rendering\DompdfRenderer([], __DIR__ . '/../../');
    }

    if ($pdfRenderer !== null) {
        $pdfRenderer->stream($pdfHtml, $filename, $paperMm, $orientation);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><body><h1>PDF renderer not available</h1>'
            . '<p>Install <code>dompdf/dompdf</code> via Composer, or wire a custom renderer via '
            . '<code>Ezdoc\\Rendering\\PdfRenderer</code> interface (inject via <code>Context::withPdf()</code>).</p>'
            . '<p>spec: <code>docs/PDF-RENDERING.md</code></p></body>';
    }
    exit;
}


?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // important:true → all Tailwind utility classes get !important. Needed karena
        // <style> block existing pakai selectors dengan specificity tinggi (mis. .toolbar button),
        // yang tanpa !important akan menang atas utility classes single-class.
        tailwind.config = { important: true };
    </script>
    <title><?= h($template['nama_template']) ?></title>
    <?php include __DIR__ . '/../_partials/generate_styles.php'; ?>
    <?php
    // Consumer custom CSS/JS, loaded last so it can override anything above.
    //
    // Like the designer, this view renders its own <head> rather than going
    // through layout.php, so `assets.custom_css` used to apply to the template
    // picker but not to the document being filled in — the screen a consumer
    // is most likely to want restyled.
    //
    // The PDF <head> further up is deliberately left alone: dompdf gets only
    // the print stylesheet, and letting arbitrary screen CSS into it would
    // change what comes out of the printer.
    if (isset($theme) && $theme instanceof \Ezdoc\UI\Theme):
        foreach ($theme->getCustomCssPaths() as $cssPath): ?>
    <link rel="stylesheet" href="<?= h($cssPath) ?>">
    <?php endforeach;
        foreach ($theme->getCustomJsPaths() as $jsPath): ?>
    <script src="<?= h($jsPath) ?>" defer></script>
    <?php endforeach;
    endif; ?>
</head>
<?php
// Versi tersembunyi dibuka dalam keadaan baca-saja, memakai mekanisme yang SAMA
// dengan dokumen terkunci.
//
// Dulu komentarnya mengaku "(read-only mode)" tapi kodenya tidak menegakkannya:
// halamannya terbuka dan tampak bisa disunting. Penyimpanannya memang menahan —
// save_document.php menyaring `deleted_at IS NULL` — tapi yang dilihat pengguna
// adalah halaman yang mengundang disunting, dan suntingannya lalu menghilang
// tanpa penjelasan. Itu jenis kebingungan yang mahal dicari sebabnya.
$__bacaSaja = $param_is_locked || ($preview_deleted && $param_is_deleted);
?>
<body class="<?= $__bacaSaja ? 'edit-off' : 'edit-on' ?>">
    <?php if ($saveMessage): ?>
    <div class="toast <?= $saveSuccess ? 'success' : 'error' ?>"><?= h($saveMessage) ?></div>
    <?php endif; ?>

    <form method="POST" id="mainForm" action="<?= h($currentUrl) ?>">
        <input type="hidden" name="_ajax" value="1">
        <input type="hidden" name="template_id" value="<?= $template_id ?>">
        <input type="hidden" name="_doc_id" id="docIdInput" value="<?= $doc_id ?>">
        <?php
        // Hidden field verify_url — supaya QR pattern {verify_url} bisa di-resolve di JS
        // Nilainya ter-precompute server-side (kalau doc sudah tersimpan → ada slug; kalau belum → kosong)
        $verifyUrlPreComputed = '';
        if ($doc_id > 0) {
            $__slug = doc_verify_ensure_slug($conn, (int)$doc_id);
            if ($__slug) $verifyUrlPreComputed = doc_verify_build_url($__slug);
        }
        ?>
        <input type="hidden" name="verify_url" id="verifyUrlInput" value="<?= h($verifyUrlPreComputed) ?>">
        <?php
        // Verify QR mode state — persist di data_fields JSON via key `_show_verify_qr`
        $__showVerifyQrInit = ($dbFields['_show_verify_qr'] ?? '') === '1'
            || ($_POST['_show_verify_qr'] ?? '') === '1'
            || ($_GET['_show_verify_qr'] ?? '') === '1';
        ?>
        <input type="hidden" name="_show_verify_qr" id="inputShowVerifyQr" value="<?= $__showVerifyQrInit ? '1' : '0' ?>">
        <?php
        // For old format (no placeholders), create hidden inputs here
        if (!$hasTtdPlaceholders):
            foreach ($configTtd as $ttd): ?>
        <input type="hidden" name="<?= h($ttd['id']) ?>" id="ttd_<?= h($ttd['id']) ?>" value="<?= h($ttdValues[$ttd['id']] ?? '') ?>">
        <?php endforeach;
        endif; ?>

        <div class="page">
            <?php // Slot: watermark — preview-mode overlay ('DRAFT'/'COPY'/state-based watermarks)
            // spec: ezdoc-spec/slots/generate.md#watermark ?>
            <?= \Ezdoc\UI\Slot::render('generate:watermark', [
                'doc_id'      => (int)$doc_id,
                'is_locked'   => (bool)$param_is_locked,
                'is_deleted'  => (bool)$param_is_deleted,
                'is_edit'     => (bool)$isEditMode,
            ]) ?>
            <div class="content">
                <?= renderContent($templateHtml, $logos, $logoSizes, $configTtd, $ttdValues, $dbFields, $dynamicTables ?? [], $conn, $param_norm, $param_nopen, $param_is_locked, $tableDbQueries, (int)$doc_id) ?>
            </div>

            <?php // Slot: before-signatures — closing phrase, place/date, witness list ahead of TTD wrap
            // spec: ezdoc-spec/slots/generate.md#before-signatures ?>
            <?= \Ezdoc\UI\Slot::render('generate:before-signatures', [
                'doc_id'   => (int)$doc_id,
                'ttd'      => $configTtd,
            ]) ?>

            <?php // Old format: show ttd-wrap section if no placeholders in content
            if (!$hasTtdPlaceholders && !empty($configTtd)): ?>
            <div class="ttd-wrap">
                <?php foreach ($configTtd as $ttd): ?>
                <div class="ttd-item">
                    <div class="ttd-label"><?= h($ttd['label'] ?? t('fallback.signature', [], 'Signature')) ?></div>
                    <div class="ttd-img" id="preview_<?= h($ttd['id']) ?>">
                        <?php // Nilai slot dibuka dulu: `qr:v1` dan `blob:sha256-…` bukan
                        // gambar, dan menaruhnya di `src` mencetak ikon rusak.
                        $__src = ezdoc_resolve_signature_image($conn, (string) ($ttdValues[$ttd['id']] ?? ''));
                        if ($__src !== ''): ?>
                        <img src="<?= h($__src) ?>" alt="TTD">
                        <?php endif; ?>
                    </div>
                    <div class="ttd-name">
                        <?php $namaField = $ttd['nama_field'] ?? 'nama_' . $ttd['id']; ?>
                        (<span class="f" contenteditable="true" data-field="<?= h($namaField) ?>"><?= v($namaField) ?></span><input type="hidden" name="<?= h($namaField) ?>" value="<?= v($namaField) ?>">)
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php // Slot: after-signatures — signature legend, notaris stamp, distribution list ('Tembusan')
            // spec: ezdoc-spec/slots/generate.md#after-signatures ?>
            <?= \Ezdoc\UI\Slot::render('generate:after-signatures', [
                'doc_id' => (int)$doc_id,
                'ttd'    => $configTtd,
            ]) ?>
        </div>

        <!-- TOOLBAR V1 Style -->
        <div class="toolbar" id="toolbarPanel">
            <button type="button" class="toolbar-toggle" id="toolbarToggleBtn" onclick="toggleToolbar()" title="<?= h(t('title.toggle_toolbar', [], 'Show/Hide Toolbar')) ?>">&#9776;</button>
            <div class="toolbar-body">
                <!-- ═══ HEADER (compact 1-line stack) ═══ -->
                <div class="flex items-center justify-between gap-1 mb-1">
                    <div class="min-w-0 flex-1">
                        <div class="text-[11px] text-gray-200 font-medium truncate leading-tight" title="<?= h($template['nama_template']) ?>"><?= h($template['nama_template']) ?></div>
                        <div class="text-[9px] text-gray-400 leading-tight">
                            <?= h($paperSize) ?> <?= $paperDim['width'] ?>×<?= $paperDim['height'] ?>
                            <?php if ($isEditMode): ?>
                                · <span class="doc-info <?= $param_is_deleted ? 'text-red-400' : ($param_is_locked ? 'text-amber-400' : 'text-emerald-400') ?>">
                                    #<?= $doc_id ?> v<?= $param_version ?>
                                    <?php if ($param_is_deleted): ?><i class="bi bi-trash-fill"></i>
                                    <?php elseif ($param_is_locked): ?><i class="bi bi-lock-fill"></i>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                · <span class="doc-info new text-amber-400"><?= t('toolbar.new', [], 'New') ?></span>
                            <?php endif; ?>
                        </div>

                        <?php // Keadaan penandatanganan — baris sendiri, bukan disambung.
                        //
                        // Disambung dengan "·" ia ikut membungkus di tengah kata dan terpotong,
                        // sementara ini justru satu-satunya keterangan yang menjelaskan kenapa
                        // sebagian field tidak bisa diketik.
                        //
                        // Satu tempat, dan cuma tulisan. Sebelumnya keadaan yang sama muncul di
                        // tiga tempat — indikator, label tombol Update ("Locked"), dan tombol
                        // lock yang mati — masing-masing dengan syarat tampilnya sendiri, jadi
                        // bisa saling bertentangan.
                        //
                        // Tidak ditampilkan kalau template tidak punya slot tanda tangan:
                        // dokumen tanpa TTD tidak punya keadaan penandatanganan.
                        if ($isEditMode && $__slotCount > 0):
                            $__stateText = [
                                'draft'       => t('signing_state.draft', [], 'Not signed'),
                                'in_progress' => t('signing_state.in_progress', ['signed' => $__signedCount, 'total' => $__slotCount], 'Signed {signed} of {total}'),
                                'complete'    => t('signing_state.complete', [], 'Fully signed'),
                            ][$docSigningState];
                            $__stateCls = [
                                'draft'       => 'bg-gray-700 text-gray-300',
                                'in_progress' => 'bg-amber-900/50 text-amber-300',
                                'complete'    => 'bg-emerald-900/50 text-emerald-300',
                            ][$docSigningState];
                        ?>
                        <div class="mt-0.5">
                            <span class="inline-block rounded px-1.5 py-0.5 text-[9px] leading-none <?= $__stateCls ?>"
                                  title="<?= h(t('title.signing_state', [], 'Content is frozen for whatever the signatures already cover')) ?>"><?= h($__stateText) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($isEditMode): ?>
                    <button class="!bg-gray-700 !text-gray-300 hover:!bg-gray-600 !rounded-full !w-[18px] !h-[18px] !p-0 !m-0 !text-[10px] !leading-none flex-shrink-0" type="button" onclick="showDocInfo()" title="<?= t('toolbar.document_details', [], 'Document details') ?>">i</button>
                    <?php endif; ?>
                </div>
                <?php if ($param_is_deleted): ?>
                <div class="bg-red-900 text-white p-1.5 rounded-md mb-1.5 text-[10px] text-center border border-dashed border-red-300">
                    <i class="bi bi-trash-fill"></i> <strong><?= t('toolbar.hidden', [], 'HIDDEN FROM LIST') ?></strong> · <?= h(date('d/m/Y', strtotime($param_deleted_at))) ?>
                    <div class="text-[9px] text-[#fecaca]"><?= t('toolbar.hidden_by', ['name' => h($param_deleted_by_label ?: '-')], 'By: {name} — can be restored') ?></div>
                </div>
                <?php endif; ?>
                <div class="border-t border-gray-700 mb-2"></div>

                <?php // Slot: toolbar-metadata — barcode, workflow status, external system ref, badge chips
                // spec: ezdoc-spec/slots/generate.md#toolbar-metadata ?>
                <?= \Ezdoc\UI\Slot::render('generate:toolbar-metadata', [
                    'doc_id'    => (int)$doc_id,
                    'template'  => $template,
                    'is_locked' => (bool)$param_is_locked,
                    'meta'      => $docMeta,
                ]) ?>

                <div class="meta-section">
                    <?php if ($isGeneralDoc): ?>
                        <div class="bg-indigo-950 text-indigo-300 py-1 px-1.5 rounded text-[10px] mb-1.5 leading-tight">
                            <i class="bi bi-info-circle"></i> <?= h(t('toolbar.general_letter', [], 'General Letter')) ?>
                        </div>
                        <input type="hidden" name="_norm" id="inputNorm" value="">
                        <input type="hidden" name="_nopen" id="inputNopen" value="">
                        <label><?= h(t('toolbar.label', [], 'Label')) ?> <span class="text-red-400">*</span></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="<?= h(t('placeholder.default_dash', [], '- (default)')) ?>" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php else: ?>
                        <div class="meta-grid">
                            <div>
                                <label><?= h(t('toolbar.norm', [], 'NORM')) ?> <span class="text-red-400">*</span></label>
                                <input type="text" name="_norm" id="inputNorm" value="<?= h($param_norm) ?>" placeholder="<?= h(t('placeholder.norm_hint', [], 'MR No.')) ?>" required <?= $param_is_locked ? 'readonly' : '' ?>>
                            </div>
                            <div>
                                <label><?= h(t('toolbar.nopen', [], 'NOPEN')) ?> <span class="text-red-400">*</span></label>
                                <input type="text" name="_nopen" id="inputNopen" value="<?= h($param_nopen) ?>" placeholder="<?= h(t('placeholder.nopen_hint', [], 'Registration')) ?>" required <?= $param_is_locked ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        <label><?= h(t('toolbar.label', [], 'Label')) ?> <span class="text-gray-500 normal-case">(<?= h(t('toolbar.optional', [], 'optional')) ?>)</span></label>
                        <input type="text" name="_label" id="inputLabel" value="<?= h($param_label) ?>" placeholder="<?= h(t('placeholder.default_dash', [], '- (default)')) ?>" <?= $param_is_locked ? 'readonly' : '' ?>>
                    <?php endif; ?>
                    <?php if ($isEditMode): ?>
                    <label><?= h(t('toolbar.version_label', [], 'Version')) ?></label>
                    <select id="versionSelect" onchange="switchVersion(this.value)">
                        <option value="<?= $param_version ?>">v<?= $param_version ?> <?= h(t('toolbar.version_current_suffix', [], '(current)')) ?></option>
                    </select>
                        <?php
                        // Penyaring versi tersembunyi. Sengaja disembunyikan sejak awal
                        // dan baru ditampilkan JS setelah server menjawab bahwa
                        // penggunanya memang boleh mengurusnya — lihat refreshVersionList().
                        //
                        // Digambar di sini, bukan dibuat JS, supaya ia tetap ada di
                        // markup dan bisa diperiksa tes cakupan fungsi.
                        //
                        // id-nya SENGAJA beda dari nama fungsinya. Tombol ini ada di
                        // dalam <form id="mainForm">, dan `onclick` sebaris dicari
                        // lewat rantai: elemen -> form induk -> document -> window.
                        // Form mengekspos kontrolnya berdasarkan id, jadi id yang sama
                        // dengan nama fungsi membuat nama itu ketemu TOMBOLNYA lebih
                        // dulu -- lalu tombol dipanggil sebagai fungsi. Gejalanya
                        // "toggleHiddenVersions is not a function", padahal fungsinya
                        // ada dan global. Semua tombol lain di berkas ini sudah
                        // memakai awalan btn* karena alasan yang sama.
                        ?>
                        <button type="button" id="btnToggleHidden" onclick="toggleHiddenVersions()" style="display:none"
                                class="!bg-transparent !text-gray-400 hover:!text-gray-200 !text-[9px] !py-0 !px-1 !m-0 underline"
                                title="<?= h(t('title.toggle_hidden_versions', [], 'Show or hide versions that were hidden from the list')) ?>"><?= h(t('toolbar.show_hidden_versions', [], 'Show hidden')) ?></button>
                    <?php endif; ?>
                </div>

                <!-- ═══ PRIMARY ROW: Update (2/3) + Print (1/3 icon) ═══ -->
                <div class="grid grid-cols-3 gap-1 mt-1">
                    <?php
                    // Kalau tidak ada lagi yang bisa di-update, tombolnya BERGANTI jadi
                    // aksi yang memang bisa dilakukan — bukan tombol mati.
                    //
                    // Tombol mati tidak menjelaskan dirinya. Orang menekannya, tidak terjadi
                    // apa-apa, dan tooltip cuma muncul kalau kebetulan berhenti di atasnya.
                    // Pola yang dipakai GitHub pada PR yang sudah di-merge: tombol Merge
                    // tidak jadi abu-abu, ia digantikan aksi berikutnya.
                    //
                    // Dokumen yang sudah ditandatangani cuma punya satu langkah maju yang
                    // perlu tombol: versi baru. Jadi itu yang ditawarkan.
                    //
                    // Pemicunya ada-tidaknya tanda tangan, bukan lengkap-tidaknya.
                    // Satu tanda tangan sudah cukup: sesudah itu tidak ada lagi yang
                    // perlu disimpan dengan menekan tombol.
                    $__adaTtd = ($__signedCount > 0);

                    // Dinamai karena bagian MORE juga perlu menanyakannya: tombol yang
                    // sama di dua tempat membuat orang mengira keduanya berbeda.
                    $__versiBaruDiBarisUtama = ($__adaTtd && $isEditMode && !$param_is_locked);

                    if ($__versiBaruDiBarisUtama): ?>
                        <button type="button" class="col-span-2 !m-0 !py-1.5 !text-[12px] !bg-violet-500 hover:!bg-violet-600" onclick="showNewVersionModal()" title="<?= h($docSigningComplete
                            ? t('title.update_off_all_signed', [], 'Every signature is in place — create a new version to revise')
                            : t('title.update_off_signed', [], 'Already signed — the remaining signers save as they sign; create a new version to revise')) ?>">
                            <i class="bi bi-plus-circle"></i> <?= h(t('toolbar.new_version', [], 'New Version')) ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-success col-span-2 !m-0 !py-1.5 !text-[12px]" onclick="submitForm()" <?= $param_is_locked ? 'disabled title="' . h(t('title.locked_cannot_update', [], 'Locked - cannot update')) . '"' : '' ?>>
                            <?= $isEditMode ? h(t('toolbar.update', [], 'Update')) : h(t('toolbar.save_new', [], 'Save New')) ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="!m-0 !py-1.5 !px-1 !text-[11px]" onclick="window.print()" title="<?= h(t('title.print_shortcut', [], 'Print (Ctrl+P)')) ?>"><i class="bi bi-printer"></i> <?= h(t('toolbar.print', [], 'Print')) ?></button>
                </div>

                <?php // Slot: toolbar-extra-actions — consumer buttons (Export Excel, WhatsApp, Email PDF, e-Sign, Copy Link) ?>
                <?= \Ezdoc\UI\Slot::render('generate:toolbar-extra-actions', [
                    'doc_id'    => (int)$doc_id,
                    'template'  => $template,
                    'is_locked' => (bool)$param_is_locked,
                ]) ?>

                <?php if ($isEditMode && $param_is_deleted && $isSuperadmin): ?>
                    <button class="bg-green-600 mt-2" type="button" onclick="restoreDeletedSlot()" title="<?= h(t('title.restore_slot', [], 'Restore this slot')) ?>">
                        <i class="bi bi-arrow-counterclockwise"></i> <?= h(t('toolbar.restore_slot', [], 'Restore Slot')) ?>
                    </button>
                    <?php if ($urlTrashList !== ''):
                        $__trashHref = str_replace('{template_id}', (string)$template_id, $urlTrashList);
                    ?>
                    <a href="<?= h($__trashHref) ?>" style="background:#374151;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;display:inline-block;text-align:center;font-size:13px;margin-top:4px;">
                        <i class="bi bi-arrow-left"></i> <?= h(t('toolbar.trash_list', [], 'Trash List')) ?>
                    </a>
                    <?php endif; ?>
                <?php else: ?>

                    <!-- ═══ Accordion: hanya 1 section terbuka biar hemat ruang ═══ -->
                    <div x-data="{ open: '' }" class="mt-2">

                        <!-- ▸ LAINNYA (icon grid 2-col) -->
                        <div class="border-t border-gray-700 pt-1.5">
                            <button type="button" @click="open = (open === 'more' ? '' : 'more')"
                                    class="!bg-transparent !text-gray-400 hover:!text-gray-100 !py-0.5 !px-1 !text-[10px] !m-0 flex items-center gap-1 w-full uppercase tracking-wider">
                                <i class="bi bi-chevron-right text-[9px]" :class="open === 'more' && 'rotate-90'" style="transition:transform .15s"></i><?= h(t('toolbar.more', [], 'More')) ?>
                            </button>
                            <div x-show="open === 'more'" x-collapse>
                                <div class="icon-grid mt-1.5">
                                    <button type="button" id="btnToggleVerifyQr"
                                            onclick="toggleVerifyQrMode()"
                                            title="<?= h(t('title.toggle_verify_qr', [], 'Replace signature image with verification QR')) ?>"
                                            style="background:<?= $__showVerifyQrInit ? '#0d9488' : '#475569' ?>;color:#fff;">
                                        <i class="bi bi-qr-code"></i>
                                        <span>QR: <span id="btnToggleVerifyQrLabel"><?= $__showVerifyQrInit ? h(t('toolbar.qr_status_on', [], 'ON')) : h(t('toolbar.qr_status_off', [], 'OFF')) ?></span></span>
                                    </button>
                                    <?php if ($isEditMode): ?>
                                        <?php // Tombol Lock Final / Unlock / Locked dibuang.
                                        //
                                        // Namanya menggabungkan dua pekerjaan: "Lock" (bekukan isi) dan
                                        // "Final" (nyatakan selesai). Yang pertama sudah dikerjakan tanda
                                        // tangan — isi beku otomatis begitu ada yang menandatangani. Yang
                                        // kedua bisa dihitung: semua slot terisi berarti selesai.
                                        //
                                        // Unlock hanya ada karena Lock ada. Dan tombol "Locked" yang mati
                                        // itu label KEADAAN yang dipasang sebagai tombol, mengulang apa
                                        // yang sudah tertulis di indikator status.
                                        //
                                        // DocuSign dan Adobe Sign tidak punya tombol kunci sama sekali:
                                        // tanda tangannya yang jadi kunci, status penyelesaian dihitung.
                                        //
                                        // Kolom `is_locked` TIDAK dihapus — masih dipakai merender dokumen
                                        // terhapus dalam mode baca-saja. Yang hilang cuma tombolnya. ?>
                                        <?php // Disembunyikan kalau sudah naik ke baris utama — tombol yang
                                        // sama di dua tempat membuat orang mengira keduanya berbeda.
                                        //
                                        // Dulu ini menanyakan $docSigningComplete, dan itu benar selama baris
                                        // utama juga memakainya. Begitu baris utama pindah ke "ada satu tanda
                                        // tangan", keduanya berhenti sepakat — dan pada dokumen yang baru
                                        // sebagian ditandatangani, New Version muncul dua kali.
                                        if (!$__versiBaruDiBarisUtama): ?>
                                        <button class="bg-violet-500" type="button" onclick="showNewVersionModal()" title="<?= h(t('title.new_version', [], 'Create new version')) ?>"><i class="bi bi-plus-circle"></i><?= h(t('toolbar.new_version', [], 'New Version')) ?></button>
                                        <?php endif; ?>

                                        <?php
                                        // Menyembunyikan versi ada DI SINI, bukan di bagian Admin.
                                        //
                                        // Sejak haknya ikut `edit`, ia bukan lagi aksi admin — dan
                                        // meninggalkannya di bawah label ADMIN membuat orang yang berhak
                                        // mengira ia sedang memakai sesuatu yang bukan haknya. Label yang
                                        // salah tidak sekadar membingungkan; ia membuat orang ragu memakai
                                        // fitur yang memang untuk mereka.
                                        //
                                        // Yang tinggal di Admin cuma PDF Raw, yang memang superadmin.
                                        if ($bolehSembunyikanVersi && $param_is_deleted): ?>
                                            <?php
                                            // Versi yang sedang dilihat memang tersembunyi. Yang masuk akal
                                            // di sini cuma mengembalikannya — menyembunyikan yang sudah
                                            // tersembunyi tidak berarti apa-apa, dan tombolnya cuma akan
                                            // ditolak server.
                                            ?>
                                            <button type="button" onclick="restoreThisVersion()" class="!bg-sky-700 hover:!bg-sky-600" title="<?= h(t('title.restore_version', [], 'Bring this version back into the list')) ?>"><i class="bi bi-arrow-counterclockwise"></i><?= h(t('toolbar.restore_version', [], 'Restore Version')) ?></button>
                                        <?php elseif ($bolehSembunyikanVersi): ?>
                                            <button type="button" onclick="deleteThisVersion()" class="!bg-red-700 hover:!bg-red-600" <?= $param_is_locked ? 'disabled title="' . h(t('title.hide_version_locked', [], 'Locked - unlock first')) . '"' : 'title="' . h(t('title.hide_version', [], 'Hide this version from the list — it stays openable and can be restored')) . '"' ?>><i class="bi bi-trash"></i><?php
                                            // "Sembunyikan Versi", bukan "Hapus". Tombolnya mengurus SATU
                                            // versi, dan label umum menjanjikan dokumennya yang hilang.
                                            echo h(t('toolbar.hide_version', [], 'Hide Version'));
                                        ?></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php // `wide` cuma dipakai saat tombolnya akan tertinggal sendirian di
                                    // barisnya. Dua tombol per baris, rata — bukan satu setengah lebar
                                    // dengan ruang kosong di sebelahnya.
                                    //
                                    // Dihitung, bukan ditebak. Syarat yang disalin ke sini pernah menyimpang
                                    // dari tombol yang dijelaskannya, dan gejalanya cuma tata letak yang
                                    // sedikit salah — jenis yang tidak pernah dilaporkan siapa pun.
                                    $__sebelumShortcuts = 1
                                        + (($isEditMode && !$__versiBaruDiBarisUtama) ? 1 : 0)
                                        + (($isEditMode && $bolehSembunyikanVersi) ? 1 : 0);
                                    $__shortcutsWide = ($__sebelumShortcuts % 2 === 0); ?>
                                    <button class="!bg-slate-600<?= $__shortcutsWide ? ' wide' : '' ?>" type="button" onclick="showShortcutsHelp()" title="<?= h(t('title.shortcuts', [], 'Keyboard shortcuts (Ctrl+/)')) ?>"><i class="bi bi-keyboard"></i><?= h(t('toolbar.shortcuts', [], 'Shortcuts')) ?></button>
                                </div>
                            </div>
                        </div>

                        <?php
                        // Isinya cuma PDF Raw, dan itu memang superadmin: ia
                        // memperlihatkan keluaran mentah dokumen milik siapa pun.
                        //
                        // Menyembunyikan versi sempat ikut di sini waktu haknya masih
                        // superadmin. Sejak haknya ikut `edit`, ia pindah ke MORE —
                        // bagian bernama ADMIN yang isinya aksi non-admin membuat orang
                        // yang berhak mengira ia sedang menyalahi wewenang.
                        if ($isSuperadmin): ?>
                        <!-- ▸ ADMIN (icon grid) -->
                        <div class="border-t border-gray-700 mt-1 pt-1.5">
                            <button type="button" @click="open = (open === 'admin' ? '' : 'admin')"
                                    class="!bg-transparent !text-amber-400 hover:!text-amber-200 !py-0.5 !px-1 !text-[10px] !m-0 flex items-center gap-1 w-full uppercase tracking-wider">
                                <i class="bi bi-chevron-right text-[9px]" :class="open === 'admin' && 'rotate-90'" style="transition:transform .15s"></i><i class="bi bi-shield-lock text-[9px]"></i><?= h(t('toolbar.admin', [], 'Admin')) ?>
                            </button>
                            <div x-show="open === 'admin'" x-collapse>
                                <div class="icon-grid mt-1.5">
                                    <?php // Sendirian di bagiannya, jadi selalu selebar barisnya. ?>
                                    <button class="!bg-emerald-700 hover:!bg-emerald-600 wide" type="button" onclick="viewPdfRaw()" title="<?= h(t('title.view_pdf_raw', [], 'View raw PDF output')) ?>"><i class="bi bi-file-earmark-pdf"></i><?= h(t('toolbar.pdf_raw', [], 'PDF Raw')) ?></button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Sign Modal - Fullscreen / Responsive -->
    <div class="modal" id="signModal">
        <div class="modal-box max-w-[890px]">
            <div class="modal-header flex items-center justify-between">
                <span id="signTitle"><?= h(t('fallback.signature', [], 'Signature')) ?></span>
                <span id="signOtpBadge" class="hidden inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                    <i class="bi bi-patch-check-fill mr-1"></i> <?= h(t('modal.otp.verified_badge', [], 'OTP Terverifikasi')) ?>
                </span>
            </div>

            <!-- OTP Container: jalur voucher (utama) + Step 1 & 2 (cadangan) -->
            <div id="signOtpContainer" class="hidden p-4 bg-slate-50 border-b border-slate-200">

                <?php // ── Jalur utama: pasien meminta kodenya sendiri lewat QR ──
                // Nomornya terkunci ke nomor dokumen. Itu menghapus dua hal sekaligus:
                // salah ketik (tidak ada yang diketik) dan kemungkinan petugas
                // mengarahkan kode ke nomornya sendiri. "Ubah nomor" tetap ada untuk
                // kasus langka, dan pemakaiannya tercatat di log audit.
                //
                // Petunjuk status di bawah kolom nomor adalah inti perbaikannya:
                // tanpa itu, satu digit salah terbaca sebagai tiga kali "kode salah".
                ?>
                <?php // ── V6: kode diminta penanda tangan lewat QR di layar ini ──
                //
                // Tiga keadaan dalam satu panel: belum diminta → menunggu dipindai
                // → sudah dipindai. Dipisah jadi tiga panel akan membuat perpindahan
                // terasa seperti melompat halaman, padahal ini satu urusan.
                //
                // Tidak ada kolom nomor HP di sini, dan itu inti perbaikannya:
                // nomornya ketahuan dari siapa yang memindai, jadi tidak ada yang
                // bisa salah ketik dan tidak ada yang bisa mengarahkan kode ke
                // nomor lain.
                ?>
                <div id="signOtpStepScan" class="hidden">
                    <h6 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-1.5">
                        <i class="bi bi-qr-code-scan text-slate-700 text-base"></i>
                        <span><?= h(t('modal.otp.scan_title', [], 'Minta Kode ke Penanda Tangan')) ?></span>
                    </h6>

                    <?php // Keadaan 1 — belum diminta ?>
                    <div id="signScanIdle">
                        <p class="text-xs text-slate-600 mb-3"><?= h(t('modal.otp.scan_idle_desc', [], 'Tampilkan QR di layar ini, lalu minta penanda tangan memindainya.')) ?></p>
                        <button type="button" id="btnRequestScan" onclick="requestScanCode()"
                                class="w-full py-2 px-3 bg-slate-900 hover:bg-slate-800 text-white rounded text-xs font-bold transition flex items-center justify-center gap-2">
                            <i class="bi bi-qr-code"></i>
                            <span id="btnRequestScanText"><?= h(t('modal.otp.scan_show_btn', [], 'Tampilkan QR')) ?></span>
                        </button>
                    </div>

                    <?php // Keadaan 2 — menunggu dipindai ?>
                    <div id="signScanWaiting" class="hidden text-center">
                        <div id="signScanDocIdentity" class="text-[11px] text-slate-600 mb-2 leading-tight"></div>
                        <img id="signScanQr" alt="" class="mx-auto rounded border border-slate-300 bg-white p-1.5" style="width:180px;height:180px;object-fit:contain;">
                        <p class="text-xs text-slate-600 mt-2 mb-1"><?= h(t('modal.otp.scan_waiting', [], 'Menunggu penanda tangan memindai…')) ?></p>
                        <div id="signScanCountdown" class="text-[11px] text-slate-500 tabular-nums"></div>
                    </div>

                    <?php // Keadaan 3 — sudah dipindai, kodenya sudah di tangan orangnya ?>
                    <div id="signScanEntry" class="hidden">
                        <div class="text-[11px] text-emerald-700 mb-2 flex items-center gap-1">
                            <i class="bi bi-check-circle-fill"></i>
                            <span id="signScanScannedBy"></span>
                        </div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1"><?= h(t('modal.otp.scan_code_label', [], 'Kode dari penanda tangan (6 digit):')) ?></label>
                        <input type="text" id="signScanCode" maxlength="6" inputmode="numeric"
                               class="w-full text-center text-lg tracking-[0.5em] font-bold p-2 border border-slate-300 rounded font-mono focus:border-slate-800"
                               placeholder="<?= h(t('modal.otp.otp_placeholder', [], '123456')) ?>" autocomplete="off">
                        <div id="signScanAttempts" class="text-[11px] text-amber-600 mt-1 font-semibold"></div>
                        <button type="button" id="btnVerifyScan" onclick="verifyScanCode()"
                                class="w-full mt-2 py-2 px-3 bg-slate-900 hover:bg-slate-800 text-white rounded text-xs font-bold transition flex items-center justify-center gap-2">
                            <i class="bi bi-check-circle-fill"></i>
                            <span id="btnVerifyScanText"><?= h(t('modal.otp.scan_verify_btn', [], 'Verifikasi Kode')) ?></span>
                        </button>
                    </div>

                    <div class="mt-3 text-[11px]">
                        <button type="button" onclick="showOtpFallback()" class="text-slate-500 hover:text-slate-800 underline underline-offset-2">
                            <?= h(t('modal.otp.scan_fallback_link', [], 'Pasien tidak bisa memindai?')) ?>
                        </button>
                    </div>
                </div>

                <!-- Step 1: Kirim OTP (jalur cadangan) -->
                <div id="signOtpStep1" class="hidden">
                    <h6 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-1.5">
                        <i class="bi bi-whatsapp text-emerald-600 text-base"></i>
                        <span><?= h(t('modal.otp.step1_title', [], 'Verifikasi OTP Pasien')) ?></span>
                    </h6>
                    <p class="text-xs text-slate-600 mb-3"><?= h(t('modal.otp.step1_desc', [], 'Fitur Tanda Tangan ini memerlukan verifikasi Kode OTP yang dikirimkan ke nomor WhatsApp pasien.')) ?></p>
                    
                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-slate-700 mb-1"><?= h(t('modal.otp.phone_label', [], 'Nomor WhatsApp Pasien:')) ?></label>
                        <input type="text" id="signOtpPhoneInput" class="w-full text-xs p-2 border border-slate-300 rounded font-mono focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500" placeholder="08xxxxxxxxxx / 628xxxxxxxxxx">
                        <small class="text-[11px] text-slate-500"><?= h(t('modal.otp.phone_hint', [], 'Masukkan/sesuaikan nomor WhatsApp pasien (format: 08xx / 628xx)')) ?></small>
                    </div>

                    <button type="button" id="btnSendOtp" onclick="sendTtdOtp()" class="w-full py-2 px-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs font-bold transition flex items-center justify-center gap-2">
                        <i class="bi bi-send-fill"></i> <span id="btnSendOtpText"><?= h(t('modal.otp.send_btn', [], 'Kirim Kode OTP via WA')) ?></span>
                    </button>

                    <?php // Jalan pulang ke jalur pindaian. Tanpa ini, petugas yang
                    // salah menekan tautan cadangan hanya punya satu pilihan:
                    // mengirim pesan berbayar.
                    //
                    // Hanya muncul kalau jalur pindaian memang tersedia — kalau
                    // pemasangan belum mengonfigurasinya, tidak ada tempat untuk
                    // kembali. ?>
                    <div class="mt-3 text-[11px]" id="signOtpBackToScan" style="display:none;">
                        <button type="button" onclick="showScanRoute()" class="text-slate-500 hover:text-slate-800 underline underline-offset-2">
                            <?= h(t('modal.otp.scan_back_link', [], 'Kembali ke kode dari pasien')) ?>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Input 6-Digit OTP -->
                <div id="signOtpStep2" class="hidden">
                    <h6 class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-1.5">
                        <i class="bi bi-shield-lock-fill text-emerald-600 text-base"></i>
                        <span><?= h(t('modal.otp.step2_title', [], 'Masukkan Kode OTP')) ?></span>
                    </h6>
                    <p class="text-xs text-slate-600 mb-3" id="signOtpStep2Desc"></p>

                    <div class="mb-3">
                        <label class="block text-xs font-semibold text-slate-700 mb-1"><?= h(t('modal.otp.otp_input_label', [], 'Kode OTP (6 Digit):')) ?></label>
                        <input type="text" id="signOtpCodeInput" maxlength="6" class="w-full text-center text-lg tracking-[0.5em] font-bold p-2 border border-slate-300 rounded font-mono focus:border-emerald-500" placeholder="123456" autocomplete="off">
                        <div id="signOtpAttemptsLeft" class="text-[11px] text-amber-600 mt-1 font-semibold"></div>
                    </div>

                    <div class="flex gap-2">
                        <button type="button" id="btnVerifyOtp" onclick="verifyTtdOtp()" class="flex-1 py-2 px-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-xs font-bold transition flex items-center justify-center gap-2">
                            <i class="bi bi-check-circle-fill"></i> <span id="btnVerifyOtpText"><?= h(t('modal.otp.verify_btn', [], 'Verifikasi OTP')) ?></span>
                        </button>
                        <button type="button" id="btnResendOtp" onclick="resendTtdOtp()" disabled class="py-2 px-3 bg-slate-200 text-slate-600 rounded text-xs font-medium transition">
                            <span id="btnResendOtpText"><?= h(t('modal.otp.resend_btn', [], 'Kirim Ulang')) ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <?php // Nama penanda tangan diminta DI SINI, bukan diedit di dokumen setelahnya.
            // Hash tanda tangan mencakup seluruh field, nama ini termasuk — jadi setelah
            // ditandatangani ia tidak bisa berubah tanpa membuat tanda tangannya tidak sah.
            // Sebelum ini field-nya tetap terlihat bisa diedit, jadi petugas mengetik nama,
            // menekan simpan, dan ditolak tanpa jalan maju. DocuSign dan Adobe Sign
            // menangkap nama penanda tangan di dialog penandatanganan untuk alasan yang sama. ?>
            <div id="signNameRow" class="hidden px-4 pt-3 pb-1">
                <label class="block text-xs font-semibold text-slate-700 mb-1" for="signNameInput">
                    <?= h(t('modal.sign.name_label', [], 'Name printed under the signature')) ?>
                </label>
                <input type="text" id="signNameInput" maxlength="120"
                       class="w-full text-sm p-2 border border-slate-300 rounded focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                       placeholder="<?= h(t('modal.sign.name_placeholder', [], 'e.g. Dr. Budi Santoso')) ?>">
                <small class="text-[11px] text-slate-500"><?= h(t('modal.sign.name_hint', [], 'Part of what the signature covers — it cannot be changed after signing.')) ?></small>
            </div>

            <!-- Modal Body Canvas -->
            <div class="modal-body" id="signCanvasArea">
                <div class="w-full relative">
                    <canvas id="signCanvas" width="850" height="400"></canvas>

                    <?php // Mode QR memakai modal yang SAMA, cuma menukar isi tengahnya.
                    // Ditulis begini supaya OTP, RBAC, cek dokumen terkunci, dan pengelolaan
                    // sign token tetap satu jalur — bukan alur kedua yang harus dijaga sejajar.
                    // Tidak ada yang digambar di mode ini: yang mengikat adalah recordnya,
                    // gambarnya cuma tampilan. Sama seperti tanda tangan tak terlihat di PAdES. ?>
                    <div id="signQrConfirmArea" class="hidden py-10 px-6 text-center">
                        <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center text-xl mb-3 mx-auto">
                            <i class="bi bi-qr-code"></i>
                        </div>
                        <h6 class="text-sm font-semibold text-slate-800 mb-1.5"><?= h(t('modal.sign_qr.title', [], 'Sign with QR')) ?></h6>
                        <p class="text-xs text-slate-500 max-w-md mx-auto mb-4"><?= h(t('modal.sign_qr.desc', [], 'A QR code is placed in this slot. Scanning it opens the verification page for this signature — showing who signed, in what role, and when.')) ?></p>
                        <dl class="inline-grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-left text-xs">
                            <dt class="text-slate-400"><?= h(t('modal.sign_qr.as_label', [], 'Signing as')) ?></dt>
                            <dd class="text-slate-700 font-medium" id="signQrAs">—</dd>
                            <dt class="text-slate-400"><?= h(t('modal.sign_qr.slot_label', [], 'Slot')) ?></dt>
                            <dd class="text-slate-700 font-medium" id="signQrSlot">—</dd>
                        </dl>
                        <p class="text-[11px] text-slate-400 mt-4 max-w-md mx-auto"><?= h(t('modal.sign_qr.commit_note', [], 'Signing saves the document — a signature has no draft state.')) ?></p>
                    </div>

                    <div id="signCanvasOverlay" class="hidden absolute inset-0 bg-slate-100/90 backdrop-blur-[2px] z-10 flex flex-col items-center justify-center p-6 text-center">
                        <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl mb-2">
                            <i class="bi bi-lock-fill"></i>
                        </div>
                        <h6 class="text-sm font-bold text-slate-800 mb-1"><?= h(t('modal.otp.step1_title', [], 'Verifikasi OTP Pasien')) ?></h6>
                        <p class="text-xs text-slate-500 max-w-sm"><?= h(t('modal.otp.step1_desc', [], 'Silakan selesaikan pengiriman dan verifikasi kode OTP di atas untuk mengaktifkan canvas tanda tangan.')) ?></p>
                    </div>
                    <div class="sign-hint" id="signCanvasHint"><?= h(t('modal.sign.hint', [], 'Gambar tanda tangan di area di atas menggunakan mouse atau sentuhan layar')) ?></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="bg-red-500 text-white" type="button" id="btnClearSign" onclick="clearSign()"><?= h(t('actions.delete', [], 'Delete')) ?></button>
                <button class="bg-green-500 text-white" type="button" id="btnSaveSign" onclick="saveSign()"><?= h(t('actions.save', [], 'Save')) ?></button>
                <button class="bg-gray-500 text-white" type="button" onclick="closeSign()"><?= h(t('actions.close', [], 'Close')) ?></button>
            </div>
        </div>
    </div>

    <!-- QR Field Generate Modal — untuk standalone QR field, klik area → modal → OK → isi verify_url -->
    <div class="modal hidden" id="qrFieldModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#3b82f6 0%,#6366f1 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> <?= h(t('modal.qr_field.header_title', [], 'Fill QR Code')) ?>
            </div>
            <div class="modal-body p-5 block">
                <p class="text-[13px] text-gray-700" style="margin:0 0 12px;">
                    <?= h(t('modal.qr_field.choose_prompt', [], 'Choose QR content for field')) ?> <strong id="qrFieldModalName">-</strong>:
                </p>
                <div class="mb-3">
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="verify" id="qrFieldSourceVerify" checked>
                        <?= h(t('modal.qr_field.use_verify_url', [], 'Use Document Verification URL (Recommended)')) ?>
                    </label>
                    <div class="p-2 bg-green-50 rounded text-[11px] text-green-800 ml-5 break-all" id="qrFieldVerifyBox" style="font-family:monospace;">-</div>
                    <div class="hidden ml-5 mt-1.5 text-amber-700 text-[11px]" id="qrFieldVerifyWarn">
                        <i class="bi bi-exclamation-triangle"></i> <?= h(t('modal.qr_field.save_first_warning', [], 'Save the document first to generate the verification URL.')) ?>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-semibold text-gray-700 block mb-1">
                        <input class="mr-1.5" type="radio" name="qrFieldSource" value="custom" id="qrFieldSourceCustom">
                        <?= h(t('modal.qr_field.use_custom', [], 'Manual entry (custom)')) ?>
                    </label>
                    <input class="w-[calc(100%_-_20px)] ml-5 p-1.5 text-xs border border-gray-300 rounded" type="text" id="qrFieldCustomInput" placeholder="<?= h(t('modal.qr_field.custom_placeholder', [], 'Type QR content here...')) ?>">
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeQrFieldModal()"><?= h(t('actions.cancel', [], 'Cancel')) ?></button>
                <button class="bg-blue-500 text-white" type="button" id="btnQrFieldConfirm" onclick="confirmQrField()"><?= h(t('modal.qr_field.confirm_button', [], 'OK, Fill QR')) ?></button>
            </div>
        </div>
    </div>

    <!-- Verify QR Mode Confirmation Modal -->
    <div class="modal hidden" id="verifyQrConfirmModal">
        <div class="modal-box max-w-[520px]">
            <div class="modal-header" style="background:linear-gradient(90deg,#0d9488 0%,#14b8a6 100%);color:#fff;">
                <i class="bi bi-qr-code"></i> <?= h(t('modal.verify_qr.header_title', [], 'Activate Verification QR Mode')) ?>
            </div>
            <div class="modal-body p-5">
                <div id="verifyQrModalMain">
                    <p style="margin:0 0 12px;"><?= t('modal.verify_qr.main_desc_1', [], 'Every <strong>signature image</strong> in this document is replaced with a <strong>QR code</strong>.') ?></p>
                    <p class="text-xs text-gray-500" style="margin:0 0 8px;"><?= h(t('modal.verify_qr.main_desc_2', [], 'Each QR points at its own signature — whoever scans it sees who signed, in what role, and when.')) ?></p>
                    <p class="text-xs text-gray-500" style="margin:0 0 12px;"><?= h(t('modal.verify_qr.main_desc_3', [], 'A signature image can be copied into another document; a pointer to the record on the server cannot.')) ?></p>
                    <?php // Dulu di sini ada input readonly "URL Verifikasi" berisi URL dokumen.
                    // Setelah tiap slot punya pointer-nya sendiri, URL itu tidak ada di QR mana
                    // pun — menampilkannya cuma memberi kesan salah tentang apa yang dipindai.
                    // Keadaan "belum disimpan" tetap disampaikan lewat kotak peringatan di bawah. ?>
                    <div class="hidden text-amber-700 text-xs mt-2.5 p-2 bg-amber-100 rounded" id="verifyQrModalWarn">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span id="verifyQrModalWarnMsg"><?= h(t('modal.verify_qr.warn_not_saved', [], 'Document not saved yet. Click "Save & Activate" to save first, then activate.')) ?></span>
                    </div>
                </div>
                <div class="hidden text-center p-6" id="verifyQrModalSaving">
                    <div class="tw-spinner inline-block w-[2rem] h-[2rem] rounded-full" role="status" style="border:0.25em solid #cbd5e1;border-right-color:#3b82f6;animation:tw-spin 0.75s linear infinite;"></div>
                    <div class="mt-2.5 text-[13px] text-gray-500"><?= h(t('modal.verify_qr.saving_text', [], 'Saving document...')) ?></div>
                </div>
            </div>
            <div class="modal-footer text-right">
                <button class="bg-gray-500 text-white" type="button" onclick="closeVerifyQrModal()"><?= h(t('actions.cancel', [], 'Cancel')) ?></button>
                <button class="bg-teal-600 text-white" type="button" id="btnVerifyQrConfirm" onclick="confirmVerifyQrMode()"><?= h(t('modal.verify_qr.confirm_activate', [], 'OK, Activate')) ?></button>
            </div>
        </div>
    </div>

    <?php // Slot: modals-extra — consumer modals (export options, share dialog, e-sign integration, custom prompts)
    // spec: ezdoc-spec/slots/generate.md#modals-extra ?>
    <?= \Ezdoc\UI\Slot::render('generate:modals-extra', [
        'doc_id'   => (int)$doc_id,
        'template' => $template,
    ]) ?>

    <?php // Hook mount points for JS event bridge (before-save / after-save) —
    // consumers listen on window.ezcetak:before-save / window.ezcetak:after-save
    // spec: ezdoc-spec/slots/generate.md#save-hook-pre
    // spec: ezdoc-spec/slots/generate.md#save-hook-post ?>
    <?= \Ezdoc\UI\Slot::render('generate:save-hook-pre', ['doc_id' => (int)$doc_id]) ?>
    <?= \Ezdoc\UI\Slot::render('generate:save-hook-post', ['doc_id' => (int)$doc_id]) ?>

    <script>
        // spec: ezdoc-spec/js/url_bag.md — endpoint URLs injected server-side, JS reads from EZDOC_URLS
        window.EZDOC_URLS = <?= json_encode($ezdocUrls, JSON_UNESCAPED_SLASHES) ?>;

        <?php
        // Identitas penanda tangan, untuk konfirmasi mode QR.
        //
        // Nama kalau aplikasi pemakai menyambungkan Ezdoc\Auth\ActorDirectory,
        // kalau tidak jatuh ke `#18`. Konfirmasi "Menandatangani sebagai" dibaca
        // manusia tepat sebelum ia menandatangani — nomor internal tidak
        // membantunya memastikan apa pun.
        //
        // Daftar role sengaja TIDAK dikirim. Ia tidak berguna untuk konfirmasi,
        // dan mengirimnya menaruh daftar role di HTML setiap halaman generate.
        $__signerId = function_exists('ezdoc_current_user_id') ? ezdoc_current_user_id() : 0;
        $__signerName = '';
        if ($__signerId > 0 && function_exists('ezdoc_actor_names')) {
            $__names = ezdoc_actor_names([$__signerId]);
            $__signerName = $__names[$__signerId] ?? '';
        }
        ?>
        window.EZDOC_SIGNER = {
            id: <?= json_encode((string) $__signerId) ?>,
            name: <?= json_encode($__signerName, JSON_UNESCAPED_UNICODE) ?>
        };

        <?php
        // Bolehkah pengguna ini melihat nomor pegawai di samping namanya?
        //
        // Gerbang yang sama dengan alamat IP dan daftar role di riwayat —
        // EZDOC_AUDIT_DETAIL_ROLES, tanpa nama role yang dipaku di library.
        //
        // Penting: ini KOSMETIK, bukan batas keamanan. Batas keamanannya ada di
        // server, di document_history.php, yang benar-benar menghapus `ip` dan
        // `roles` dari respons. Nomor pegawai selalu terkirim karena ia jadi
        // penampilan cadangan saat nama tidak ketemu; yang diputuskan di sini
        // cuma apakah ia ikut ditampilkan ketika namanya ADA.
        ?>
        window.EZDOC_AUDIT_DETAIL = <?= (function_exists('ezdoc_can_view_audit_detail') && ezdoc_can_view_audit_detail()) ? 'true' : 'false' ?>;

        <?php // Apakah jalur "minta kode lewat QR" tersedia di pemasangan ini.
        // Diputuskan server, bukan ditebak sisi tampilan: tanpa pola tautan yang
        // dikonfigurasi, tombolnya cuma akan menghasilkan 503. ?>
        window.EZDOC_SCAN_ENABLED = <?= (defined('EZDOC_SCAN_URL_TEMPLATE') && EZDOC_SCAN_URL_TEMPLATE !== '' && strpos((string) EZDOC_SCAN_URL_TEMPLATE, '{token}') !== false) ? 'true' : 'false' ?>;

        // i18n dictionary — mirrors EZDOC_URLS above. spec: docs/I18N.md
        window.EZDOC_I18N = <?= json_encode($translator->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        // Translate a dot-notation key against EZDOC_I18N, with {param} interpolation.
        // Mirrors Ezdoc\UI\Config's own dot-path traversal (PHP side) so both stay in sync.
        window.t = function(key, params) {
            params = params || {};
            var ref = window.EZDOC_I18N || {};
            var segs = key.split('.');
            for (var i = 0; i < segs.length; i++) {
                if (ref && typeof ref === 'object' && segs[i] in ref) { ref = ref[segs[i]]; }
                else { console.warn('[ezdoc:i18n] missing key', key); return key; }
            }
            if (typeof ref !== 'string') { console.warn('[ezdoc:i18n] non-string value', key); return key; }
            return ref.replace(/\{(\w+)\}/g, function(m, name) {
                return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : m;
            });
        };
        var t = window.t;

        // Resolve an endpoint URL by bag key. Falls back to same-page (window.location.href) for POST
        // routes (save, docAction) and to '?action=<key>' for generate_qr — preserves legacy behavior
        // when consumer has not overridden the URL bag.
        window._ezdocEndpoint = function(key, fallback) {
            var v = (window.EZDOC_URLS || {})[key];
            if (v && v !== '') return v;
            if (typeof fallback !== 'undefined') return fallback;
            return window.location.href;
        };
        window._ezdocQrUrl = function(data) {
            var base = window._ezdocEndpoint('generateQr', '?action=generate_qr');
            var sep  = base.indexOf('?') >= 0 ? '&' : '?';
            // Ensure dispatcher-recognized signal (action=generate_qr) di URL.
            // App orchestrator route ke ?ezdoc_page=action tapi dispatcher tetap
            // butuh $_GET['action'] === 'generate_qr' untuk route ke generate_qr.php.
            var url = base;
            if (url.indexOf('action=generate_qr') < 0) {
                url += sep + 'action=generate_qr';
                sep = '&';
            }
            return url + sep + 'data=' + encodeURIComponent(data);
        };
    </script>
    <?php include __DIR__ . '/../_partials/generate_scripts.php'; ?>

    <!-- Screen pagination — visual multi-paper cards + JS spacer at boundary.
         Loaded LAST supaya semua content sudah settled (fields, floating elements,
         TTD modal ready). Runs on DOMContentLoaded. -->
    <script>
        <?= \Ezdoc\UI\ScreenPagination::renderJs(
            (float)$paperDim['height'],
            (float)$padTop,
            (float)$padBottom,
            12.0,
            $layoutMode
        ) ?>
    </script>

    <?php include __DIR__ . '/../_partials/dialog_helper.php'; ?>
</body>
</html>
