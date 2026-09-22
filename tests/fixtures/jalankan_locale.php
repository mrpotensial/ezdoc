<?php
/**
 * Muat dispatcher action dengan config tertentu, lalu cetak bahasa yang terpasang.
 *
 *   php jalankan_locale.php <base64 dari JSON config>
 *
 * Base64, bukan JSON mentah: escapeshellarg() di Windows mengganti tanda kutip
 * ganda dengan spasi.
 *
 * Permintaan GET tanpa action: dispatcher memasang penerjemah dan bahasa isi
 * dokumen, tidak menemukan rute, dan kembali — sama seperti saat ia dimuat dari
 * view yang lalu lanjut merender.
 *
 * Dipakai tests/Format/LocaleFollowsConfigTest.php.
 */

$root = dirname(__DIR__, 2);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_POST = [];

require $root . '/bootstrap.php';

$config = new \Ezdoc\UI\Config((array) json_decode((string) base64_decode($argv[1] ?? ''), true));

require $root . '/actions/_dispatcher.php';

echo json_encode(['layar' => ezdoc_locale(), 'isi' => ezdoc_document_locale()]);
