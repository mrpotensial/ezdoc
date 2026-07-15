<?php

/**
 * ezdoc i18n — strings for views/document/generate.php.
 *
 * Keys are English identifiers — only the VALUES are Indonesian (see
 * lang/id/common.php header comment + docs/I18N.md for why).
 *
 * Do NOT redeclare top-level keys `actions` / `fallback` here — those are
 * reserved by lang/id/common.php.
 *
 * NOTE on key shape: keys here are NOT prefixed with "generate." (e.g.
 * `toolbar.print`, not `generate.toolbar.print`). Ezdoc\UI\Translator::forView()
 * merges this file's top-level sections (`toolbar`, `title`, ...) directly into
 * the flat catalog alongside common.php's `actions`/`fallback` — there is no
 * top-level `generate` wrapper key in the merged array, so a `generate.`-prefixed
 * key would never resolve past Config::get()'s first dot-segment and would
 * silently always fall back to the English $default. Verified against
 * src/UI/Config.php's dot-path traversal + Translator::forView()'s merge order.
 *
 * spec: docs/I18N.md
 */

return [
    'toolbar' => [
        'print'                 => 'Print',
        'new'                   => 'Baru',
        'document_details'      => 'Detail dokumen',
        'deleted'               => 'TERHAPUS',
        'deleted_by'            => 'Oleh: {name}',
        'general_letter'        => 'Surat Umum',
        'label'                 => 'Label',
        'optional'              => 'opsional',
        'norm'                  => 'NORM',
        'nopen'                 => 'NOPEN',
        'version_label'         => 'Versi',
        'version_current_suffix'=> '(current)',
        'locked'                => 'Locked',
        'update'                => 'Update',
        'save_new'              => 'Simpan Baru',
        'saving'                => 'Menyimpan...',
        'more'                  => 'Lainnya',
        'admin'                 => 'Admin',
        'lock_final'            => 'Lock Final',
        'unlock'                => 'Unlock',
        'new_version'           => 'Versi Baru',
        'shortcuts'             => 'Shortcuts',
        'pdf_raw'               => 'PDF Raw',
        'restore_slot'          => 'Restore Slot',
        'trash_list'            => 'Trash List',
        'hide_label'            => 'Sembunyikan',
        'qr_status_on'          => 'ON',
        'qr_status_off'         => 'OFF',
        'doc_info_id_edit'      => 'ID: {id} (Edit)',
    ],
    'title' => [
        'print_shortcut'         => 'Print (Ctrl+P)',
        'toggle_toolbar'         => 'Tampilkan/Sembunyikan Toolbar',
        'show_toolbar'           => 'Tampilkan Toolbar',
        'hide_toolbar'           => 'Sembunyikan Toolbar',
        'toggle_verify_qr'       => 'Ganti TTD gambar dengan QR verifikasi',
        'lock_final'             => 'Kunci versi ini (final)',
        'unlock_version'         => 'Unlock versi ini (superadmin)',
        'locked_superadmin_only' => 'Locked — unlock hanya oleh superadmin',
        'new_version'            => 'Buat versi baru',
        'shortcuts'              => 'Keyboard shortcuts (Ctrl+/)',
        'view_pdf_raw'           => 'Lihat hasil PDF mentah',
        'delete_version_locked'  => 'Locked - unlock dulu',
        'locked_cannot_update'   => 'Locked - tidak bisa update',
        'delete_version'         => 'Hapus versi ini',
        'restore_slot'           => 'Pulihkan slot ini',
        'no_sign_permission'     => 'Anda tidak berhak sign{roles}',
        'no_sign_permission_as'  => 'Anda tidak berhak sign sebagai {label}{roles}',
        'edit_qr_content'        => 'Edit isi QR',
        'click_edit_qr'          => 'Klik untuk edit isi QR',
        'click_generate_qr'      => 'Klik untuk generate QR (default: URL verifikasi)',
        'click_fill_edit_qr'     => 'Klik untuk isi/edit QR',
        'materai_manual_area'    => 'Area materai (tempel manual)',
        'materai_not_uploaded'   => 'Belum di-upload',
    ],
    'placeholder' => [
        'default_dash'      => '- (default)',
        'norm_hint'         => 'No RM',
        'nopen_hint'        => 'Pendaftaran',
        'qr_data'           => 'Data QR...',
        'materai_serial'    => 'No. Seri (opsional)',
    ],
    'field' => [
        'select_placeholder' => '-- Pilih --',
    ],
    'save' => [
        'success' => 'Dokumen berhasil disimpan',
        'failed'  => 'Gagal menyimpan: {error}',
    ],
    'ttd' => [
        'mode_image'                     => 'Gambar',
        'mode_qr'                        => 'QR',
        'edit_qr_button'                 => 'Edit QR',
        'save_first'                     => 'Simpan dokumen dulu',
        'save_first_for_verify_url'      => 'Simpan dokumen dulu untuk generate verify URL',
        'loading_qr'                     => 'Loading QR...',
        'generating'                     => 'Generating...',
        'scan_to_verify'                 => 'Scan untuk verifikasi',
        'fill_field_first'               => 'Isi field terlebih dulu',
        'fill_or_set_pattern'            => 'Isi konten QR atau atur pattern di template',
        'qr_failed'                      => 'QR gagal: {error}',
        'locked_no_sign'                 => 'Dokumen ini locked. Tidak bisa tanda tangan. Untuk revisi, buat versi baru.',
        'confirm_delete'                 => 'Hapus tanda tangan ini?',
        'edit_qr_prompt_label'           => 'Isi konten QR untuk TTD ini:',
        'edit_qr_prompt_default_hint'    => "\n\nTemplate default: {pattern}\n(Kosongkan untuk pakai template default)",
        'verify_qr_alt'                  => 'QR Verifikasi',
        'qr_alt'                         => 'QR TTD',
    ],
    'materai' => [
        'replace'              => 'Ganti',
        'upload_prompt'        => 'UPLOAD<br>e-MATERAI',
        'locked_no_upload'     => 'Dokumen locked, tidak bisa upload.',
        'invalid_format'       => 'Format harus PNG / JPG.',
        'max_size'             => 'Ukuran file maksimal 2MB.',
        'invalid_file'         => 'File tidak valid.',
        'confirm_delete'       => 'Hapus e-Materai ini?',
        'lock_missing_warning' => "Materai berikut belum di-upload:\n  - {list}\n\nTetap lock versi ini?",
    ],
    'validation' => [
        'required'         => 'Wajib diisi',
        'min_value'        => 'Minimal nilai {min}',
        'min_length'       => 'Minimal {min} karakter',
        'max_value'        => 'Maksimal nilai {max}',
        'max_length'       => 'Maksimal {max} karakter',
        'pattern_mismatch' => 'Format tidak sesuai',
    ],
    'alert' => [
        'identity_required' => 'No RM dan No Pendaftaran wajib diisi!',
        'generic_failed'    => 'Gagal: {reason}',
        'restore_success'   => 'Berhasil di-restore ({count} versi)',
    ],
    'confirm' => [
        'delete_version' => 'Hapus versi v{version}? Aksi ini tidak bisa di-undo.',
        'lock_final'     => "Lock versi ini sebagai FINAL?\n\nSetelah locked, versi ini tidak bisa diedit. Hanya superadmin yang bisa unlock. Untuk revisi, buat versi baru.",
        'unlock_version' => 'Unlock versi ini? (akses superadmin)',
        'restore_slot'   => 'Pulihkan seluruh slot dokumen ini? Semua versi yang ter-soft-delete akan kembali aktif.',
    ],
    'toast' => [
        'invalid_fields'           => 'Ada field yang belum valid. Periksa highlight merah.',
        'save_failed'              => 'Gagal menyimpan: {error}',
        'verify_url_not_ready'     => 'URL Verifikasi belum tersedia. Simpan dokumen dulu.',
        'qr_content_required'      => 'Isi QR tidak boleh kosong',
        'qr_filled'                => 'QR terisi. Klik Simpan untuk persist.',
        'identity_required_for_save' => 'NORM & Nopen wajib diisi dulu untuk save',
    ],
    'modal' => [
        'doc_info' => [
            'title'                 => 'Detail Dokumen',
            'document_id_label'     => 'Document ID',
            'version_row_label'     => 'Versi',
            'editable_suffix'       => '(Editable)',
            'norm_row_label'        => 'No RM',
            'nopen_row_label'       => 'No Pendaftaran',
            'label_row_label'       => 'Label',
            'created_by_label'      => 'Dibuat oleh',
            'created_at_label'      => 'Dibuat pada',
            'updated_at_label'      => 'Terakhir diupdate',
            'materai_heading'       => 'Materai',
            'materai_serial_label'  => 'No. Seri: {serial}',
            'materai_upload_label'  => 'Upload: {date}',
            'materai_manual_status' => 'Tempel manual',
            'materai_filled_status' => 'Terisi',
            'materai_missing_status'=> 'Belum upload',
        ],
        'new_version' => [
            'title'         => 'Buat Versi Baru',
            'description'   => 'Pilih sumber data untuk versi baru:',
            'source_blank'  => 'Kosong (start dari awal)',
            'source_copy'   => 'Copy dari versi:',
        ],
        'shortcuts' => [
            'title'            => 'Keyboard Shortcuts',
            'save_doc'         => 'Simpan dokumen',
            'print_browser'    => 'Print (browser)',
            'view_pdf_admin'   => 'Lihat PDF (admin)',
            'focus_label'      => 'Fokus ke input Label',
            'close_modal'      => 'Tutup modal aktif / canvas TTD',
            'show_help'        => 'Tampilkan help ini',
        ],
        'verify_qr' => [
            'header_title'              => 'Aktifkan Mode QR Verifikasi',
            'main_desc_1'               => 'Semua <strong>TTD gambar</strong> di dokumen ini akan diganti dengan <strong>QR Verifikasi</strong>.',
            'main_desc_2'               => 'Berguna untuk share PDF/print — penerima scan QR → diarahkan ke halaman verifikasi resmi untuk cek keaslian dokumen.',
            'url_label'                 => 'URL Verifikasi:',
            'warn_not_saved'            => 'Dokumen belum di-save. Klik "Simpan & Aktifkan" untuk simpan dulu baru aktifkan.',
            'saving_text'               => 'Menyimpan dokumen...',
            'pending_url'               => '(akan digenerate setelah dokumen di-save)',
            'confirm_activate'          => 'OK, Aktifkan',
            'save_and_activate'         => 'Simpan & Aktifkan',
            'identity_required_warning' => 'NORM & Nopen wajib diisi dulu. Tutup modal ini → isi di sidebar kanan → klik toggle lagi.',
        ],
        'qr_field' => [
            'header_title'         => 'Isi QR Code',
            'choose_prompt'        => 'Pilih isi QR untuk field',
            'use_verify_url'       => 'Pakai URL Verifikasi Dokumen (Recommended)',
            'save_first_warning'   => 'Simpan dokumen dulu untuk generate URL verifikasi.',
            'use_custom'           => 'Isi manual (custom)',
            'custom_placeholder'   => 'Tulis isi QR di sini...',
            'not_saved_placeholder'=> '(dokumen belum di-save)',
            'confirm_button'       => 'OK, Isi QR',
        ],
        'sign' => [
            'hint' => 'Gambar tanda tangan di area di atas menggunakan mouse atau sentuhan layar',
        ],
    ],
];
