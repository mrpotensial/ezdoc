<?php
/**
 * POST _ajax=1 template_id, _norm, _nopen, _label, _doc_id, <field data>
 *
 * Save/update dokumen ke ezdoc_documents. Kalau doc sudah ada di slot
 * (template_uuid, norm, nopen, label), update latest version. Kalau belum,
 * insert baru dgn version=1.
 *
 * Level 2/3 verify: setelah save sukses, auto-generate slug + compute data hash.
 *
 * Auth: authenticated user + RBAC per-template (create/edit) + per-TTD sign.
 *
 * Response (backward-compat dgn existing frontend):
 *   { success, message, doc_id, isEdit, label, verify_url, data_hash, data_hash_at, debug }
 *
 * ## v0.9.9 refactor
 *
 * Persistence via `Ezdoc\Db\Connection` (via MysqliConnection auto-wrap).
 * Template lookup via `TemplateRepository`. Per-doc lookup via
 * `DocumentRepository::findById`. Slot latest-version lookup masih raw SQL
 * (query pattern belum ideal untuk Repository API).
 *
 * Business logic (template parsing, field collection, TTD RBAC enforcement)
 * tetap disini — di W4.1 belum di-extract ke Service (defer v0.9.10 kalau
 * ada refactor pattern jelas).
 */

use Ezdoc\Template\TemplateRepository;
use Ezdoc\Template\FloatingExtractor;
use Ezdoc\Context;

global $author_id;

$template_id = (int)($_POST['template_id'] ?? 0);
// Menerima _subject_id/_context_id maupun nama lamanya — lihat ezdoc_slot_identity().
[$norm, $nopen] = ezdoc_slot_identity($_POST, '_');
$label = trim($_POST['_label'] ?? '-');
if ($label === '') $label = '-';
$doc_id = (int)($_POST['_doc_id'] ?? 0);

$db = Context::default()->connection();
$tplRepo = new TemplateRepository($db);

// ─── Load template (include name utk auto-computed doc title) ───
$tpl = $tplRepo->findById($template_id);
if (!$tpl) ezdoc_respond_error(t('response.template_not_found', [], 'Template not found'));

$templateUuid    = $tpl->getUuid();
$templateVersion = $tpl->getVersion();
$templateName    = $tpl->getName() ?: 'Untitled Template';
$templateHtml    = $tpl->getContent() ?: '';
$configTtdRaw    = $tpl->getSignatureConfig();
$tplDocScope     = $tpl->getScope();

// ─── RBAC: template-level access check ───
// Template::getAccessConfig() sudah return decoded array (bukan raw string),
// jadi TIDAK perlu ezdoc_parse_access_config() (yg expect string).
// null-safe: empty array = allow-all (v2 behavior, sama dgn parse hasil null).
$rawAccess = $tpl->getAccessConfig();
$accessConfig = $rawAccess === [] ? null : $rawAccess;
$rbacAction = ($doc_id > 0) ? 'edit' : 'create';

@error_log(sprintf(
    '[ezdoc:save] user=%d roles=%s template=%d action=%s access_config=%s',
    ezdoc_current_user_id(),
    implode(',', ezdoc_current_user_roles()),
    (int) $template_id,
    $rbacAction,
    $accessConfig ? json_encode($accessConfig) : 'null'
));

ezdoc_require_on_template($accessConfig, $rbacAction, "Tidak berhak {$rbacAction} dokumen dari template ini");

if ($tplDocScope === 'patient' && ($norm === '' || $nopen === '')) {
    ezdoc_respond_error(t('response.patient_identity_required', [], 'Medical record number and registration number are required'));
}

// ─── Build configTtd (support placeholder + legacy format) ───
$hasTtdPlaceholders = strpos($templateHtml, 'ttd-placeholder') !== false;
$configTtd = [];
if ($hasTtdPlaceholders) {
    preg_match_all('/<div([^>]*class="[^"]*ttd-placeholder[^"]*"[^>]*)>/s', $templateHtml, $ttdMatches, PREG_SET_ORDER);
    foreach ($ttdMatches as $m) {
        $attrs = $m[1];
        preg_match('/data-ttd="([^"]+)"/', $attrs, $tm);
        $ttdId = $tm[1] ?? '';
        if (!$ttdId) continue;
        preg_match('/data-label="([^"]+)"/', $attrs, $lm);
        preg_match('/data-nama-field="([^"]+)"/', $attrs, $nm);
        preg_match('/data-allowed-roles="([^"]*)"/', $attrs, $arm);
        preg_match('/data-allowed-users="([^"]*)"/', $attrs, $aum);
        preg_match('/data-require-otp="([^"]*)"/', $attrs, $otpm);
        preg_match('/data-otp-phone-source="([^"]*)"/', $attrs, $otppm);
        preg_match('/data-otp-ttl="([^"]*)"/', $attrs, $otptm);
        $configTtd[] = [
            'id'               => $ttdId,
            'label'            => $lm[1] ?? 'Tanda Tangan',
            'nama_field'       => $nm[1] ?? 'nama_' . $ttdId,
            'rbac'             => ezdoc_parse_ttd_config($arm[1] ?? '', $aum[1] ?? ''),
            'require_otp'      => !empty($otpm[1]) && ($otpm[1] === '1' || $otpm[1] === 'true'),
            'otp_phone_source' => $otppm[1] ?? 'nomor_hp_pasien',
            'otp_ttl_seconds'  => isset($otptm[1]) && $otptm[1] !== '' ? (int)$otptm[1] : 300,
        ];
    }
} else {
    $configTtd = array_map(function ($t) { return $t + ['rbac' => ['roles' => [], 'users' => []], 'require_otp' => !empty($t['require_otp'])]; }, $configTtdRaw);
}

// ─── Collect all fields from template ───
$allFields = [];
preg_match_all('/\{\{([^}]+)\}\}/', $templateHtml, $matches);
foreach ($matches[1] as $fn) $allFields[$fn] = true;

foreach ($configTtd as $ttd) {
    $nf = $ttd['nama_field'] ?? '';
    if ($nf) $allFields[$nf] = true;
}

preg_match_all('/data-qr="([^"]+)"/', $templateHtml, $qrMatches);
foreach ($qrMatches[1] as $qrField) $allFields[$qrField] = true;

$fieldData = [];
foreach ($allFields as $fn => $v) {
    $fieldData[$fn] = $_POST[$fn] ?? '';
}

// Diagnostic bag (F12 Console visibility)
$__ezdocSaveDebug = [
    'allFields_keys'     => array_keys($allFields),
    'POST_keys'          => array_keys($_POST ?? []),
    'collected_nonempty' => array_keys(array_filter($fieldData, function ($v) { return $v !== ''; })),
    'field_values_count' => count(array_filter($fieldData, function ($v) { return $v !== ''; })),
];

// ─── TTD custom fields (mode + per-doc QR content) ───
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $nf  = $ttd['nama_field'] ?? ('nama_' . $tid);
    foreach (['_ttd_mode_' . $tid, $nf . '_qr'] as $k) {
        if (isset($_POST[$k])) $fieldData[$k] = $_POST[$k];
    }
}

// ─── Materai per-document fields ───
preg_match_all('/<div[^>]*class="[^"]*materai-placeholder[^"]*"[^>]*data-materai="([^"]+)"[^>]*>/s', $templateHtml, $matMatches);
$validMateraiIds = array_unique($matMatches[1] ?? []);
foreach ($validMateraiIds as $mid) {
    $imgKey    = '_materai_' . $mid . '_image';
    $serialKey = '_materai_' . $mid . '_serial';
    $uploadKey = '_materai_' . $mid . '_uploaded_at';

    if (isset($_POST[$imgKey])) {
        $val = (string) $_POST[$imgKey];
        $fieldData[$imgKey] = ($val !== '' && preg_match('#^data:image/(png|jpe?g|gif);base64,#', $val)) ? $val : '';
    }
    if (isset($_POST[$serialKey])) {
        $serial = trim((string) $_POST[$serialKey]);
        if (mb_strlen($serial) > 30) $serial = mb_substr($serial, 0, 30);
        $fieldData[$serialKey] = $serial;
    }
    if (isset($_POST[$uploadKey])) {
        $ua = trim((string) $_POST[$uploadKey]);
        $fieldData[$uploadKey] = ($ua !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ua)) ? $ua : '';
    }
}

// ─── TTD signatures dgn per-TTD RBAC & OTP enforcement ───
$ttdData = [];
$accessMode = ezdoc_access_mode($accessConfig);
$otpServiceInstance = null;

/**
 * Sign tokens accepted during validation, spent after the document is written.
 *
 * @var list<array{token: string, ttd_id: string, doc_id: int|null}>
 */
$pendingSignTokens = [];

// Signatures already stored on this document. The form re-posts every stored
// signature on each save, so this is what tells an actual new signature apart
// from an unchanged one — the difference between demanding a fresh OTP and
// letting an ordinary field edit through.
// Penyimpan gambar tanda tangan, kalau tabel objeknya sudah ada.
//
// Null berarti "biarkan gambar inline seperti sebelumnya". Consumer yang belum
// menjalankan migrasi `ezdoc_blobs` harus tetap bisa menyimpan dokumen —
// menggagalkan penyimpanan karena optimasi penyimpanan belum dipasang akan jadi
// pertukaran yang tidak masuk akal. Diperiksa sekali, bukan per slot.
$signatureImages = null;
try {
    if ((new \Ezdoc\Db\Schema\ColumnIntrospector($db))->columns('ezdoc_blobs') !== []) {
        $signatureImages = new \Ezdoc\Storage\SignatureImageStore(
            \Ezdoc\Storage\BlobStoreFactory::make($db)
        );
    }
} catch (\Throwable $e) {
    @error_log('[ezdoc:storage] penyimpanan objek tidak aktif: ' . $e->getMessage());
}

$existingTtdValues = [];
if ($doc_id > 0) {
    $existingRow = $db->fetchOne(
        'SELECT `signature_values` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
        [$doc_id]
    );
    if ($existingRow && !empty($existingRow['signature_values'])) {
        $decoded = json_decode((string) $existingRow['signature_values'], true);
        if (is_array($decoded)) {
            $existingTtdValues = $decoded;
        }
    }
}
foreach ($configTtd as $ttd) {
    $tid = $ttd['id'];
    $tv  = $_POST[$tid] ?? '';

    // Dua bentuk nilai yang sah, dan tidak ada yang lain.
    //
    // Gambar tetap diperiksa ketat sampai ke tipe MIME-nya. Penanda mode QR
    // dicocokkan persis — bukan dengan prefix, bukan dengan pola. Gerbang ini
    // satu-satunya yang menentukan apa yang boleh masuk ke `signature_values`,
    // dan melonggarkannya jadi "apa saja yang tidak kosong" akan membuat siapa
    // pun yang bisa POST bisa menaruh string pilihannya di sana.
    //
    // Sebelum ini penanda QR tersaring di sini tanpa pesan apa pun: mode QR
    // terlihat menandatangani, tapi tidak ada satu pun record yang tertulis.
    //
    // Bentuk ketiga menyusul: referensi ke objek tersimpan. Ia muncul di sini
    // karena form mengirim ulang setiap tanda tangan tersimpan pada tiap simpan —
    // jadi dokumen yang gambarnya sudah jadi objek akan mengirim balik
    // referensinya, dan menolaknya di gerbang ini akan membuat tanda tangannya
    // hilang pada penyimpanan berikutnya.
    $isImageValue = (bool) preg_match('#^data:image/(png|jpe?g|gif);base64,#', (string) $tv);
    $isQrActValue = ($tv === \Ezdoc\Signature\SignatureValue::QR_ACT);
    $isBlobRef    = \Ezdoc\Storage\Blob::isReference((string) $tv);
    if (!$isImageValue && !$isQrActValue && !$isBlobRef) continue;

    $ttdRbac = $ttd['rbac'] ?? ['roles' => [], 'users' => []];
    if (!ezdoc_can_sign_ttd($ttdRbac)) {
        if ($accessMode === 'strict') {
            $ttdLabel = $ttd['label'] ?? $tid;
            ezdoc_respond_error(t('response.ttd_sign_forbidden', ['label' => $ttdLabel], 'Not authorized to sign as "{label}"'), 403, [
                'ttd_id'         => $tid,
                'required_roles' => $ttdRbac['roles'] ?? [],
            ]);
        } else {
            @error_log(sprintf(
                '[ezdoc] Permissive: user %d bypassed TTD "%s" (required roles: %s)',
                ezdoc_current_user_id(),
                $ttd['label'] ?? $tid,
                implode(',', $ttdRbac['roles'] ?? [])
            ));
            continue;
        }
    }

    // ─── OTP enforcement ───
    // Only when this signature is actually being drawn or replaced. Re-posting
    // an unchanged signature (which every save does, since the hidden input
    // carries the stored value) must not demand a fresh code — the token was
    // already spent on the save that created it, so requiring one again would
    // make the document permanently unsaveable, blocking even unrelated edits.
    if (!empty($ttd['require_otp']) && $tv !== ($existingTtdValues[$tid] ?? '')) {
        $signToken = trim((string) ($_POST['_sign_token_' . $tid] ?? ''));
        if ($otpServiceInstance === null) {
            $otpServiceInstance = new \Ezdoc\Signature\Otp\OtpService();
        }
        // Checked now, spent only once the document is actually written.
        //
        // This used to consume the token here. Everything after this point can
        // still refuse the save — a locked document, a failed write — and the
        // token is single-use, so a refusal destroyed it. The operator had
        // signed, verified a code, and was then asked for another one for a
        // signature that was never stored.
        //
        // Bound to this template and document, not just the slot id — slot ids
        // are template-local, so `ttd1` exists on nearly every template.
        $tokenDocId = $doc_id > 0 ? (int) $doc_id : null;
        $tokenOk = $otpServiceInstance->checkSignToken(
            $signToken,
            $tid,
            (int) $template_id,
            $tokenDocId
        );
        if ($tokenOk) {
            // Same doc_id the check used — the slot lookup below may reassign
            // $doc_id, and a consume against a different one would not match.
            $pendingSignTokens[] = [
                'token'  => $signToken,
                'ttd_id' => $tid,
                'doc_id' => $tokenDocId,
            ];
        }
        if (!$tokenOk) {
            $ttdLabel = $ttd['label'] ?? $tid;
            ezdoc_respond_error(
                t('response.otp_token_required', ['label' => $ttdLabel], 'OTP verification is required before signing "{label}".'),
                403,
                ['ttd_id' => $tid]
            );
        }
    }

    // Gambar BARU dipindahkan ke objek tersimpan; sisanya lewat apa adanya.
    //
    // Perbandingan dengan nilai tersimpan yang menentukan, bukan sebuah flag.
    // Form mengirim ulang setiap tanda tangan tersimpan pada tiap simpan, jadi
    // tanpa perbandingan itu penyimpanan pertama setelah fitur ini aktif akan
    // menulis ulang SETIAP slot di dokumen — dan hash tanda tangan mencakup nilai
    // slot, jadi setiap tanda tangan yang sah berubah jadi `tampered`. Aplikasi
    // membatalkan buktinya sendiri.
    //
    // Efek sampingnya justru yang diinginkan: gambar inline lama tetap inline
    // selama dokumennya hidup. Memindahkannya adalah operasi tersendiri yang
    // memindahkan byte apa adanya, bukan efek samping menyimpan.
    $ttdData[$tid] = $signatureImages !== null
        ? $signatureImages->valueToPersist((string) $tv, (string) ($existingTtdValues[$tid] ?? ''))
        : $tv;
}

$jsonFields = json_encode($fieldData, JSON_UNESCAPED_UNICODE);
$jsonTtd    = json_encode($ttdData, JSON_UNESCAPED_UNICODE);
$authorId   = (int) ($author_id ?? 0);

// ─── Check exists & lock status ───
//
// A document is identified by its slot: (template_uuid, norm, nopen, label).
// Saving without a doc_id therefore does not necessarily create a new record —
// if the slot is taken, this save updates whatever is already there. That is
// what makes re-saving a form an update rather than a duplicate.
//
// The consequence is easy to miss: reusing the same NORM/NOPEN as an earlier
// document means addressing that same document, even when the screen says
// "new". If it happens to be locked, the save is refused for a document the
// user did not think they were touching.
$isEdit = false;
$existingLocked = 0;
$blocking = null;

if ($doc_id > 0) {
    $r = $db->fetchOne(
        'SELECT id, version, is_locked FROM ezdoc_documents WHERE id = ? AND deleted_at IS NULL',
        [$doc_id]
    );
    if ($r) { $isEdit = true; $existingLocked = (int) $r['is_locked']; $blocking = $r; }
}

if (!$isEdit) {
    // Find latest version in slot — search by template_uuid untuk lintas template version
    $row = $db->fetchOne(
        'SELECT id, version, is_locked FROM ezdoc_documents
         WHERE template_uuid = ? AND subject_id = ? AND context_id = ? AND label = ? AND deleted_at IS NULL
         ORDER BY version DESC LIMIT 1',
        [$templateUuid, $norm, $nopen, $label]
    );
    if ($row) { $doc_id = (int) $row['id']; $isEdit = true; $existingLocked = (int) $row['is_locked']; $blocking = $row; }
}

if ($isEdit && $existingLocked) {
    // Name the document doing the blocking. Without this the reply says only
    // "locked", which reads as "the thing I am making is locked" — and the
    // thing being made does not exist yet. Knowing the id, its version, and
    // the slot it was matched on is the difference between a dead end and an
    // obvious next step (unlock that one, or change the NORM/NOPEN/label).
    ezdoc_respond_error(
        t('response.document_locked_cannot_edit', [], 'This document is locked and cannot be edited. Unlock it first or create a new version.'),
        400,
        [
            'locked_doc_id' => (int) $blocking['id'],
            'locked_version' => (int) ($blocking['version'] ?? 0),
            'matched_slot' => [
                'norm'  => $norm,
                'nopen' => $nopen,
                'label' => $label,
            ],
            'matched_by' => ((int) ($_POST['_doc_id'] ?? 0)) > 0 ? 'doc_id' : 'slot',
        ]
    );
}

// ─── Snapshot tanda tangan tersimpan, dengan $doc_id yang SUDAH final ───
//
// $existingTtdValues di atas diambil dengan `_doc_id` mentah dari POST, sebelum
// pencarian slot berkesempatan mengubah $doc_id. Untuk gerbang OTP itu memadai;
// untuk memutuskan tanda tangan mana yang benar-benar BARU, tidak — menyimpan
// dengan `_doc_id = 0` yang lalu cocok ke dokumen slot yang sudah ada akan
// membuat setiap slot tampak baru, dan mencatat baris tanda tangan duplikat
// setiap kali dokumen disimpan.
//
// Diambil di sini: $doc_id sudah final, tulisan belum terjadi.
$ttdBeforeSave = [];
$fieldsBeforeSave = [];
if ($doc_id > 0) {
    $beforeRow = $db->fetchOne(
        'SELECT `field_values`, `signature_values` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
        [$doc_id]
    );
    if ($beforeRow) {
        $decodedBefore = json_decode((string) ($beforeRow['signature_values'] ?? ''), true);
        if (is_array($decodedBefore)) {
            $ttdBeforeSave = $decodedBefore;
        }
        $decodedFields = json_decode((string) ($beforeRow['field_values'] ?? ''), true);
        if (is_array($decodedFields)) {
            $fieldsBeforeSave = $decodedFields;
        }
    }
}

// ─── Isi dikunci saat sudah ada tanda tangan; slot tetap terbuka (§7b) ───
//
// Dua hal berbeda yang dikunci, bukan dua keadaan dari satu flag. `is_locked`
// mencampur keduanya, dan itu sebabnya menyimpan setelah satu tanda tangan bisa
// tertolak seluruhnya — orang kedua dan ketiga jadi tidak bisa menandatangani.
//
// Aturannya bukan pilihan kebijakan, tapi konsekuensi apa yang tanda tangan
// nyatakan (§5): hash-nya mencakup `fields`, jadi mengedit field setelah ada
// tanda tangan merusak tanda tangan itu — field harus beku. Hash-nya TIDAK
// mencakup slot lain, jadi orang berikutnya menandatangani tidak menyentuh yang
// sudah ada — slot boleh tetap terbuka.
//
// Ini pengingat dini, bukan batas keamanannya: verifikasi tetap membandingkan
// hash dan akan melaporkan perubahan apa pun, tertangkap di sini atau tidak.
// Karena itu guard-nya menyasar kasus yang jelas — nilai field tersimpan yang
// berubah, atau tanda tangan yang sudah ada ditukar — dan tidak menghalangi
// penyimpanan atas hal yang belum tentu suntingan.
$signedSlots = [];
// Gabungan field yang dicakup SEMUA tanda tangan yang sudah ada. Hanya inilah
// yang beku — bukan seluruh isi dokumen.
$frozenFields = [];
if ($doc_id > 0) {
    $signedRows = $db->fetchAll(
        'SELECT `metadata` FROM `ezdoc_signatures` WHERE `document_id` = ? AND `deleted_at` IS NULL',
        [$doc_id]
    );
    foreach ($signedRows as $sr) {
        $sm = json_decode((string) ($sr['metadata'] ?? ''), true);
        if (!is_array($sm) || empty($sm['ttd_id'])) {
            continue;
        }
        $signedSlots[(string) $sm['ttd_id']] = true;

        if (isset($sm['covered_fields']) && is_array($sm['covered_fields'])) {
            foreach ($sm['covered_fields'] as $ck) {
                $frozenFields[(string) $ck] = true;
            }
        } else {
            // Tanda tangan v1 tidak mencatat cakupannya, dan v1 memang mencakup
            // SELURUH field. Null di sini berarti "semuanya" — dinyatakan, bukan
            // disimpulkan dari daftar kosong.
            $frozenFields = null;
            break;
        }
    }
}

if ($signedSlots !== []) {
    // Nilai field tersimpan yang berubah atau hilang. Field BARU dibiarkan
    // lewat: itu bisa jadi template yang bertambah kolom, bukan orang mengubah
    // pernyataan yang sudah ditandatangani. Verifikasi tetap menangkapnya.
    //
    // Yang diperiksa hanya field yang benar-benar DICAKUP tanda tangan yang ada.
    // Sebelumnya seluruh isi dokumen dibekukan begitu ada satu tanda tangan, dan
    // itu membuat dokumen dua penanda tangan mustahil: orang kedua mengisi nama
    // di bawah tanda tangannya sendiri, guard melihat field berubah, dan
    // menolaknya. Dokumen dengan tiga slot tidak akan pernah bisa lengkap.
    $changedFields = [];
    foreach ($fieldsBeforeSave as $fname => $fvalue) {
        if (!array_key_exists($fname, $fieldData)) {
            continue; // form tidak mengirimnya — bukan bukti suntingan
        }
        if ($frozenFields !== null && !isset($frozenFields[(string) $fname])) {
            continue; // milik slot lain, atau tampilan saja — tidak ditandatangani siapa pun
        }
        if ((string) $fieldData[$fname] !== (string) $fvalue) {
            $changedFields[] = (string) $fname;
        }
    }

    if ($changedFields !== []) {
        ezdoc_respond_error(
            t(
                'response.document_signed_fields_frozen',
                ['fields' => implode(', ', array_slice($changedFields, 0, 5))],
                'This document has been signed, so its content can no longer change. Create a new version to revise it. Changed: {fields}'
            ),
            409,
            [
                'signed_slots'   => array_keys($signedSlots),
                'changed_fields' => $changedFields,
                'remedy'         => 'new_version',
            ]
        );
    }

    // Menandatangani ulang slot yang sudah ditandatangani adalah pencabutan
    // lalu tanda tangan baru. Itu harus tindakan eksplisit, bukan efek samping
    // menyimpan.
    //
    // MENGHAPUSNYA juga, dan itu sebelumnya lolos. Syarat lama menuntut
    // `$submitted !== ''`, jadi menyimpan dengan slot dikosongkan tidak
    // ditolak apa pun: nilai slot hilang dari dokumen sementara barisnya masih
    // ada di `ezdoc_signatures`. Verifikasi lalu menghitung ulang hash atas isi
    // yang sudah tidak memuat tanda tangan itu, tidak cocok, dan melaporkan
    // `tampered` — untuk perubahan yang aplikasinya sendiri izinkan.
    //
    // Menghapus sebenarnya lebih merusak daripada mengganti: yang mengganti
    // meninggalkan sesuatu untuk diperiksa, yang menghapus meninggalkan record
    // yang menunjuk ketiadaan.
    foreach (array_keys($signedSlots) as $signedTtd) {
        $submitted = (string) ($ttdData[$signedTtd] ?? '');
        $stored = (string) ($ttdBeforeSave[$signedTtd] ?? '');

        if ($stored !== '' && $submitted === '') {
            ezdoc_respond_error(
                t(
                    'response.signature_removal_needs_new_version',
                    ['ttd' => $signedTtd],
                    'Signature "{ttd}" is already recorded and cannot be removed by saving. Create a new version instead.'
                ),
                409,
                ['ttd_id' => $signedTtd, 'remedy' => 'new_version']
            );
        }

        if ($submitted !== '' && $stored !== '' && $submitted !== $stored) {
            ezdoc_respond_error(
                t(
                    'response.signature_replace_needs_new_version',
                    ['ttd' => $signedTtd],
                    'Signature "{ttd}" is already recorded and cannot be replaced by saving. Create a new version instead.'
                ),
                409,
                ['ttd_id' => $signedTtd, 'remedy' => 'new_version']
            );
        }
    }
}

// Auto-compute document title (Filament/Nova pattern)
$computedTitle = trim((string) ($_POST['title'] ?? ''));
if ($computedTitle === '') {
    if ($tplDocScope === 'patient' && ($norm !== '' || $nopen !== '')) {
        $computedTitle = $templateName . ' — ' . trim($norm . '/' . $nopen, '/');
    } elseif ($label !== '' && $label !== '-') {
        $computedTitle = $templateName . ' (' . $label . ')';
    } else {
        $computedTitle = $templateName;
    }
    if (mb_strlen($computedTitle) > 255) $computedTitle = mb_substr($computedTitle, 0, 255);
}

// ─── Per-doc floating_elements override (v0.9.12 sidecar pattern) ───
// Client CAN send `floating_elements_json` untuk override template default
// (mis. user drag floating logo ke posisi berbeda per-doc). NULL/absent =
// inherit dari template.floating_elements di render time.
$docFloatingJson = null;
$docFloatingRaw = trim((string) ($_POST['floating_elements_json'] ?? ''));
if ($docFloatingRaw !== '' && $docFloatingRaw !== 'null' && $docFloatingRaw !== '[]') {
    // Validate + normalize
    $docFloating = FloatingExtractor::fromJson($docFloatingRaw);
    if (!empty($docFloating)) {
        $docFloatingJson = FloatingExtractor::toJson($docFloating);
    }
}

// ─── Save (update / insert) via Connection ───
try {
    if ($isEdit) {
        // UPDATE — uuid, template_uuid, template_version immutable per doc
        $db->execute(
            // subject_type sengaja TIDAK ikut diperbarui. Ia bagian dari identitas
            // slot; mengubahnya saat update berarti dokumen berpindah slot diam-diam
            // hanya karena konfigurasi berubah di antara dua penyimpanan.
            'UPDATE ezdoc_documents SET title = ?, norm = ?, nopen = ?, label = ?,
                subject_id = ?, context_id = ?,
                field_values = ?, signature_values = ?, floating_elements = ?, updated_by = ?
             WHERE id = ?',
            [$computedTitle, $norm, $nopen, $label, $norm, $nopen,
             $jsonFields, $jsonTtd, $docFloatingJson, $authorId, $doc_id]
        );
    } else {
        $docUuid = ezdoc_uuid_v7();
        $publishedAt = date('Y-m-d H:i:s');

        // Nomor versi berikutnya, MENGHITUNG baris yang sudah di-soft-delete.
        //
        // Dulu ini di-hardcode 1, dan itu meledak begitu ada yang menghapus versi
        // terakhir lalu membuat dokumennya lagi: barisnya masih ada (soft-delete),
        // masih memegang versi 1, dan unique key `uk_slot_version` tidak memuat
        // `deleted_at` — jadi INSERT-nya bertabrakan dan errornya sampai ke layar
        // sebagai "Duplicate entry ... for key uk_slot_version".
        //
        // Nomor versi bersifat monoton dan tidak pernah dipakai ulang. Itu bukan
        // akal-akalan menghindari constraint, itu memang cara sistem berversi
        // bekerja: menghapus versi 3 di Confluence membuat simpan berikutnya jadi
        // versi 4, dan version id S3 maupun hash Git tidak pernah didaur ulang.
        //
        // new_version.php sudah melakukan ini sejak awal, dengan alasan yang sama
        // tertulis di komentarnya. Jalur insert ini yang tidak pernah ikut.
        $nextVersion = (int) $db->fetchScalar(
            'SELECT COALESCE(MAX(version), 0) + 1
             FROM ezdoc_documents
             WHERE template_uuid = ? AND subject_id = ? AND context_id = ? AND label = ?',
            [$templateUuid, $norm, $nopen, $label]
        );
        if ($nextVersion < 1) $nextVersion = 1;

        // Penulisan ganda selama peralihan: kolom lama DAN yang baru.
        // Yang dibaca masih yang lama — lihat docs/260804-skema-subjek.md.
        $db->execute(
            'INSERT INTO ezdoc_documents
             (uuid, template_id, template_uuid, template_version,
              title, norm, nopen, label, version,
              subject_type, subject_id, context_id,
              field_values, signature_values, floating_elements,
              status, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $docUuid, $template_id, $templateUuid, $templateVersion,
                $computedTitle, $norm, $nopen, $label, $nextVersion,
                ezdoc_subject_type(), $norm, $nopen,
                $jsonFields, $jsonTtd, $docFloatingJson,
                'published', $publishedAt, $authorId,
            ]
        );
        $doc_id = (int) $db->lastInsertId();
    }
} catch (\Throwable $e) {
    @error_log(sprintf(
        '[ezdoc:save] FAILED template_id=%d norm=%s nopen=%s label=%s isEdit=%s err=%s',
        $template_id, $norm, $nopen, $label, $isEdit ? 'yes' : 'no', $e->getMessage()
    ));

    // Tabrakan slot dijelaskan, bukan diteruskan mentah.
    //
    // "Duplicate entry '019f5e99-…-1-1---1' for key 'ezdoc_documents.uk_slot_version'"
    // tidak memberi tahu petugas apa pun yang bisa dilakukannya, dan sekaligus
    // membocorkan nama tabel serta indeks ke layar. Dua orang menyimpan slot yang
    // sama pada saat bersamaan bisa sampai ke sini, dan jawabannya sederhana:
    // muat ulang, karena dokumennya sudah ada.
    if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
        ezdoc_respond_error(
            t(
                'response.slot_version_conflict',
                [],
                'This document was created by another save a moment ago. Reload the page to continue from the existing version.'
            ),
            409,
            ['remedy' => 'reload']
        );
    }

    ezdoc_respond_error(t('response.save_failed', ['error' => $e->getMessage()], 'Failed: {error}'), 500);
}

@error_log(sprintf(
    '[ezdoc:save] SAVED doc_id=%d template_id=%d template_uuid=%s norm=[%s] nopen=[%s] label=[%s] isEdit=%s',
    $doc_id, $template_id, $templateUuid, $norm, $nopen, $label, $isEdit ? 'yes' : 'no'
));

// ─── Catat tanda tangan sebagai record ───
//
// Sampai sekarang TTD hanya tersimpan sebagai base64 di JSON dokumen. Itu
// gambar, bukan tanda tangan yang bisa diverifikasi: tidak ada catatan siapa,
// kapan, atau apa yang ditandatangani. Di sini tiap TTD yang BARU atau BERUBAH
// jadi satu baris di `ezdoc_signatures`, dengan hash isi yang diikatnya.
//
// Berlaku untuk mode gambar maupun QR — keduanya tampilan untuk tindakan yang
// sama. Lihat docs/260730-catatan-tanda-tangan.md.
//
// Seperti audit log, kegagalan di sini tidak boleh menjatuhkan penyimpanan yang
// sudah berhasil: dokumennya sudah tertulis, dan memberi tahu user bahwa
// simpannya gagal justru tidak benar.
$recordedSignatureCount = 0;
try {
    $signingKey = \Ezdoc\Signature\SigningKey::resolve();

    if ($signingKey === null) {
        // SigningKey sudah mencatat alasannya sekali per proses. Tidak menulis
        // baris apa pun adalah pilihan sadar: record yang "ditandatangani" tanpa
        // kunci sungguhan terlihat seperti bukti tanpa menjadi bukti.
        $signatureRecordNote = 'skipped: no signing key configured';
    } else {
        // Identitas dokumen. Pada INSERT sudah ada di $docUuid; pada UPDATE
        // perlu dibaca — dan baru sekarang $doc_id pasti final.
        if (!isset($docUuid) || $docUuid === '') {
            $uuidRow = $db->fetchOne('SELECT `uuid` FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1', [$doc_id]);
            $docUuid = (string) ($uuidRow['uuid'] ?? '');
        }

        $sigRepo = new \Ezdoc\Signature\SignatureRepository($db);
        $provider = new \Ezdoc\Signature\Providers\HmacProvider($signingKey);
        $signerId = (string) ezdoc_current_user_id();
        $signerRoles = ezdoc_current_user_roles();
        // Snapshot, bukan referensi: kalau orangnya pindah jabatan tahun depan,
        // dokumen tetap menunjukkan jabatan saat menandatangani.
        $signerRole = !empty($signerRoles) ? substr(implode(',', $signerRoles), 0, 64) : null;

        // Field mana milik slot mana. Dipakai untuk mengeluarkan field milik
        // penanda tangan LAIN dari cakupan tiap tanda tangan — lihat
        // SignaturePayload::coveredKeysFor().
        $slotFieldMap = [];
        foreach ($configTtd as $c) {
            $slotFieldMap[(string) $c['id']] = [(string) ($c['nama_field'] ?? ('nama_' . $c['id']))];
        }
        $allFieldKeys = array_map('strval', array_keys($fieldData));

        $slotIndex = 0;
        $recorded = 0;
        foreach ($configTtd as $ttdCfg) {
            $tid = $ttdCfg['id'];
            $slotIndex++;

            $newValue = (string) ($ttdData[$tid] ?? '');
            $oldValue = (string) ($ttdBeforeSave[$tid] ?? '');

            // Form mengirim ulang setiap tanda tangan tersimpan pada tiap
            // penyimpanan. Tanpa perbandingan ini, mengedit satu field akan
            // mencatat ulang semua tanda tangan yang tidak berubah.
            if ($newValue === '' || $newValue === $oldValue) {
                continue;
            }

            // Cakupan tanda tangan ini: seluruh isi dokumen KECUALI field milik
            // slot orang lain. Tanpa pengecualian itu, orang kedua yang mengisi
            // namanya sendiri akan merusak tanda tangan orang pertama — dan pada
            // dokumen dua penanda tangan, orang kedua sama sekali tidak bisa
            // menandatangani.
            $coveredKeys = \Ezdoc\Signature\SignaturePayload::coveredKeysFor(
                (string) $tid,
                $allFieldKeys,
                $slotFieldMap
            );

            $contentHash = \Ezdoc\Signature\SignaturePayload::hash(
                $docUuid,
                (string) $templateUuid,
                (int) $templateVersion,
                $fieldData,
                $tid,
                $newValue,
                $coveredKeys
            );

            // Nama yang tercetak di bawah tanda tangan, ditangkap ke dalam
            // recordnya.
            //
            // Nilainya sudah ada di `fields`, jadi hash-nya sudah mengikatnya —
            // ini bukan soal integritas, tapi soal record yang bisa dibaca sendiri.
            // Tanpa ini, satu baris `ezdoc_signatures` cuma menyebut slot mana yang
            // ditandatangani; halaman verify harus membuka JSON dokumen untuk tahu
            // nama siapa yang tercetak di sana.
            $signerNameField = $ttdCfg['nama_field'] ?? ('nama_' . $tid);
            $signerName = trim((string) ($fieldData[$signerNameField] ?? ''));

            $sigMetadata = [
                'ttd_id'      => $tid,
                'ttd_label'   => $ttdCfg['label'] ?? $tid,
                'signer_name' => $signerName !== '' ? $signerName : null,
                // Verifier butuh daftar ini untuk menghitung ulang. Ia juga ada DI
                // DALAM payload yang di-hash, jadi mengubahnya di sini tidak
                // melebarkan atau menyempitkan yang diperiksa — ia mengubah hasil
                // hitungannya dan muncul sebagai perubahan.
                'covered_fields' => $coveredKeys,
                'replaced'    => $oldValue !== '',
            ];

            $signResult = $provider->sign(new \Ezdoc\Signature\SignRequest([
                'contentBytes' => $contentHash,
                'contentHash'  => $contentHash,
                'signerId'     => $signerId,
                'metadata'     => $sigMetadata,
            ]));

            $sigRepo->insert([
                'document_id'             => $doc_id,
                'signature_id_within_doc' => $slotIndex - 1,
                'signer_id'               => $signerId,
                'signer_role'             => $signerRole,
                'signer_user_id'          => ezdoc_current_user_id() > 0 ? ezdoc_current_user_id() : null,
                'provider'                => $signResult->getProviderName(),
                'level'                   => $signResult->getLevel(),
                'envelope_format'         => $signResult->getEnvelopeFormat(),
                'envelope'                => $signResult->getEnvelope(),
                'content_hash'            => $contentHash,
                // Menamai bentuk payload DAN kanonikalisasinya, supaya verifier
                // tahu cara menghitung ulang.
                'content_hash_algo'       => \Ezdoc\Signature\SignaturePayload::ALGO,
                // DateTimeImmutable, bukan date(): date() menerima timestamp
                // integer detik, jadi format 'v' selalu menghasilkan 000 dan
                // presisi milidetik yang jadi alasan kolom ini DATETIME(3)
                // terbuang tanpa suara. Dua tanda tangan dalam detik yang sama
                // akan kehilangan urutannya.
                'signed_at'               => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'verify_status'           => 'valid',
                'metadata'                => $sigMetadata,
            ]);
            $recorded++;
        }

        $recordedSignatureCount = $recorded;
        $signatureRecordNote = $recorded > 0 ? "recorded {$recorded}" : 'nothing new to record';
    }
} catch (\Throwable $e) {
    $signatureRecordNote = 'failed: ' . $e->getMessage();
    @error_log(sprintf(
        '[ezdoc:signature] gagal mencatat tanda tangan untuk doc_id=%d: %s',
        $doc_id,
        $e->getMessage()
    ));
}

// ─── Spend the sign tokens ───
// Only now. The signature is stored, so the one use each token was good for
// has been taken. Doing this during validation meant any later refusal — a
// locked document, a failed write — burned a token for a signature that never
// landed, and the operator was asked for a fresh code with no way to recover
// the old one.
//
// The consume is still the atomic UPDATE that guarantees single use; the check
// earlier only asked whether it would succeed. If one fails here the document
// is already written, so the save stands and the mismatch is logged rather
// than surfaced — the alternative is telling the user their save failed when
// it did not.
foreach ($pendingSignTokens as $pending) {
    $spent = $otpServiceInstance->validateSignToken(
        $pending['token'],
        $pending['ttd_id'],
        (int) $template_id,
        $pending['doc_id']
    );
    if (!$spent) {
        @error_log(sprintf(
            '[ezdoc:otp] sign token passed validation but could not be spent: ttd=%s template=%d doc=%s saved_doc=%d',
            $pending['ttd_id'],
            $template_id,
            $pending['doc_id'] === null ? 'null' : (string) $pending['doc_id'],
            $doc_id
        ));
    }
}

// ─── Post-save: verify slug + data hash ───
//
// Hook opsional milik consumer app. Keduanya menerima handle mysqli mentah,
// bukan `Connection` — mereka ditulis sebelum lapisan abstraksi ada, dan
// memanggil `->query()` langsung.
//
// Handle-nya diambil dari Context, bukan dari `$conn`.
//
// `$conn` di sini dulunya variabel yang tidak pernah dideklarasikan. Ia
// kebetulan terisi HANYA lewat satu pintu masuk: consumer yang me-require
// dispatcher di scope global, tempat `$conn` milik bootstrap-nya ikut terlihat.
// Lewat `App::run()`, dispatcher di-require di dalam sebuah method, jadi `$conn`
// null dan penyimpanan gagal dengan "Call to a member function query() on null"
// — padahal `$GLOBALS['conn']` sudah diisi. File ini mendeklarasikan
// `global $author_id` tapi tidak `$conn`, dan itu bedanya.
//
// Context adalah jawaban yang benar karena tidak bergantung pada bagaimana file
// ini dimuat. Pola yang sama sudah dipakai action lain sejak v0.9.10.
$rawDb = Context::default()->db;

// Hook-nya mysqli-only. Mount yang memakai PDO harus melewati keduanya, bukan
// jatuh dengan error yang menuding tempat yang salah.
$verifyUrl = '';
$hashInfo = null;
if ($rawDb instanceof \mysqli) {
    if (function_exists('doc_verify_ensure_slug') && function_exists('doc_verify_build_url')) {
        $verifySlug = doc_verify_ensure_slug($rawDb, (int) $doc_id);
        if ($verifySlug) $verifyUrl = doc_verify_build_url($verifySlug);
    }

    if (function_exists('doc_verify_compute_and_store_hash')) {
        $hashInfo = doc_verify_compute_and_store_hash($rawDb, (int) $doc_id);
    }
} elseif (function_exists('doc_verify_ensure_slug')) {
    @error_log('[ezdoc] hook doc_verify_* dilewati: butuh mysqli, dapat '
        . (is_object($rawDb) ? get_class($rawDb) : gettype($rawDb)));
}

ezdoc_audit_log($isEdit ? 'doc.updated' : 'doc.created', [
    'target_type' => 'document',
    'target_id'   => (string) $doc_id,
    'template_id' => (int) $template_id,
    'doc_id'      => (int) $doc_id,
    'metadata'    => [
        'norm'             => $norm,
        'nopen'            => $nopen,
        'label'            => $label,
        'ttd_count'        => count($ttdData),
        'data_hash'        => $hashInfo['hash'] ?? null,
        'template_uuid'    => $templateUuid,
        'template_version' => $templateVersion,
    ],
    'message' => $isEdit
        ? "Update dokumen (template {$template_id}, norm {$norm}, label {$label})"
        : "Buat dokumen baru (template {$template_id}, norm {$norm}, label {$label})",
]);

ezdoc_respond_success([
    'doc_id'       => $doc_id,
    'isEdit'       => $isEdit,
    'label'        => $label,
    'verify_url'   => $verifyUrl,
    'data_hash'    => $hashInfo['hash'] ?? null,
    'data_hash_at' => $hashInfo['hash_at'] ?? null,
    // Berapa tanda tangan yang baru saja tercatat pada penyimpanan INI.
    //
    // Pemanggil butuh ini karena penyimpanan pada dokumen yang sudah ada tidak
    // memuat ulang halaman — ia memperbarui di tempat. Setelah menandatangani,
    // markup yang masih di layar berasal dari SEBELUM tanda tangan itu ada:
    // tombol Edit dan Hapus masih menempel, dan menekannya menghasilkan
    // penolakan "sudah tercatat" atas tanda tangan yang baru saja dibuat sendiri.
    //
    // Angkanya, bukan boolean: nol berarti tidak ada yang berubah dan halaman
    // tidak perlu diganggu.
    'signatures_recorded' => $recordedSignatureCount,
    'debug'        => $__ezdocSaveDebug,
], t('response.document_saved', [], 'Document saved successfully'));
