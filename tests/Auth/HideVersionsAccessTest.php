<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Auth;

use PHPUnit\Framework\TestCase;

/**
 * Siapa boleh menyembunyikan versi dokumen — satu aturan, empat pemanggil.
 *
 * Aturannya gabungan: **superadmin bawaannya, kecuali templatenya mengatur
 * sendiri lewat `access_config.delete`.** Dulu ia hidup di dalam
 * `delete_version.php`, dan itu tidak masalah selama hanya satu berkas
 * memakainya.
 *
 * Sekarang pemakainya empat: menyembunyikan, mengembalikan, mendaftar yang
 * tersembunyi, dan membukanya. Empat salinan aturan izin akan menyimpang, dan
 * menyimpangnya tidak berisik — yang muncul cuma "kenapa saya bisa
 * menyembunyikan tapi tidak bisa mengembalikan".
 *
 * Berkas ini menjaga aturannya tetap satu, dan menjaga isinya benar.
 */
final class HideVersionsAccessTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/role_provider.php';
        require_once dirname(__DIR__, 2) . '/lib/authorization.php';
    }

    protected function tearDown(): void
    {
        // Penyedia peran disimpan di GLOBALS, bukan di Context — jadi ia
        // BERTAHAN antar-tes kalau tidak dibersihkan, dan tes berikutnya
        // akan lulus atau gagal tergantung urutan.
        unset($GLOBALS['__ezdoc_role_provider_override']);
        unset($GLOBALS['author_id'], $GLOBALS['author_role_array']);
    }

    /**
     * @param list<string> $roles
     */
    private function sebagai(array $roles, int $userId = 7): void
    {
        $GLOBALS['author_id'] = $userId;
        $GLOBALS['author_role_array'] = $roles;

        ezdoc_set_role_provider([
            'has_role' => static function ($r) use ($roles) {
                foreach ((array) $r as $one) {
                    if (in_array($one, $roles, true)) {
                        return true;
                    }
                }

                return false;
            },
            'current_user_id'    => static function () use ($userId) { return $userId; },
            'current_user_roles' => static function () use ($roles) { return $roles; },
        ]);
    }

    // ─── Tanpa pengaturan template: ikut hak edit ───

    public function testSuperadminBolehSaatTemplateTidakMengatur(): void
    {
        $this->sebagai(['superadmin']);

        self::assertTrue(ezdoc_can_hide_versions(null));
        self::assertTrue(ezdoc_can_hide_versions([]));
    }

    /**
     * Tanpa pengaturan template, yang menentukan adalah hak `edit`.
     *
     * Bawaannya dulu superadmin, dan itu membuat fiturnya praktis tidak ada:
     * hampir tidak ada template yang punya `access_config`, jadi tidak seorang
     * pun selain superadmin pernah melihat tombolnya. Dilaporkan dari pemakaian.
     *
     * `edit` yang jadi ukurannya karena menyembunyikan versi lebih ringan
     * daripada yang sudah boleh dilakukan pemegang hak itu: ia bisa mengubah isi
     * dan membuat versi baru, sementara menyembunyikan tidak menghapus apa pun
     * dan bisa dikembalikan.
     */
    public function testTanpaPengaturanIkutHakEdit(): void
    {
        $this->sebagai(['dokter', 'perawat']);

        // Deployment permissive: tanpa aturan, `edit` terbuka — jadi ini juga.
        self::assertTrue(ezdoc_can_hide_versions(null));

        // Template yang membatasi `edit` ikut membatasi ini.
        $hanyaKepala = ['edit' => ['roles' => ['kepala_bidang'], 'users' => []]];
        self::assertFalse(ezdoc_can_hide_versions($hanyaKepala));

        $this->sebagai(['kepala_bidang']);
        self::assertTrue(ezdoc_can_hide_versions($hanyaKepala));
    }

    /**
     * Pengaturan yang ADA tapi KOSONG bukan pengaturan.
     *
     * `access_config` yang pernah disunting lalu dikosongkan lagi meninggalkan
     * `delete => ['roles' => [], 'users' => []]`. Membacanya sebagai "template
     * mengatur" akan menyerahkan keputusan ke daftar kosong — yang mengizinkan
     * atau menolak semua orang, tergantung penafsiran, dan keduanya bukan yang
     * dimaksud siapa pun.
     */
    public function testPengaturanKosongJatuhKeBawaan(): void
    {
        $kosong = ['delete' => ['roles' => [], 'users' => []]];

        // Bukan "tidak seorang pun" dan bukan "semua orang tanpa syarat":
        // pertanyaannya diteruskan ke hak `edit`, sama seperti kalau kunci
        // `delete` tidak pernah ada.
        $this->sebagai(['dokter']);
        self::assertTrue(ezdoc_can_hide_versions($kosong));

        $kosongTapiEditDibatasi = $kosong + ['edit' => ['roles' => ['kepala_bidang'], 'users' => []]];
        self::assertFalse(ezdoc_can_hide_versions($kosongTapiEditDibatasi));

        $this->sebagai(['superadmin']);
        self::assertTrue(ezdoc_can_hide_versions($kosong));
    }

    // ─── Dengan pengaturan template ───

    public function testPeranYangDisebutTemplateBoleh(): void
    {
        $this->sebagai(['kepala_ruangan']);

        self::assertTrue(ezdoc_can_hide_versions(['delete' => ['roles' => ['kepala_ruangan']]]));
    }

    public function testPeranYangTidakDisebutTidakBoleh(): void
    {
        $this->sebagai(['perawat']);

        self::assertFalse(ezdoc_can_hide_versions(['delete' => ['roles' => ['kepala_ruangan']]]));
    }

    public function testPenggunaYangDisebutTemplateBoleh(): void
    {
        $this->sebagai(['perawat'], 42);

        self::assertTrue(ezdoc_can_hide_versions(['delete' => ['users' => [42]]]));
    }

    /**
     * Superadmin tetap lolos walau template menyebut peran lain.
     *
     * Ini perilaku `ezdoc_can_on_template()` yang sudah ada sejak lama —
     * dicatat di sini supaya ia jadi keputusan yang tertulis, bukan kejutan.
     *
     * Akibatnya: `access_config.delete` hanya bisa MEMPERLUAS, tidak pernah
     * membatasi. Template yang ingin menutup akses superadmin tidak bisa
     * melakukannya lewat sini, dan itu memang wajar untuk peran yang tugasnya
     * membereskan keadaan yang tidak diduga.
     */
    public function testSuperadminTetapLolosWalauTemplateMenyebutPeranLain(): void
    {
        $this->sebagai(['superadmin']);

        self::assertTrue(ezdoc_can_hide_versions(['delete' => ['roles' => ['kepala_ruangan']]]));
    }

    // ─── Bentuk yang tidak masuk akal tidak boleh meledak ───

    /**
     * Ini berjalan di jalur permintaan web. `access_config` datang dari kolom
     * JSON yang bisa berisi apa saja setelah bertahun-tahun disunting orang.
     * Yang melempar di sini menjatuhkan halamannya, bukan menolak dengan sopan.
     */
    public function testBentukAnehDijawab_bukanDilempar(): void
    {
        $this->sebagai(['dokter']);

        foreach ([
            null,
            [],
            ['delete' => null],
            ['delete' => 'bukan array'],
            ['delete' => ['roles' => 'bukan list']],
            ['delete' => ['users' => null]],
            ['lain' => ['roles' => ['dokter']]],
        ] as $i => $cfg) {
            self::assertIsBool(
                ezdoc_can_hide_versions($cfg),
                "bentuk #{$i} seharusnya dijawab boolean"
            );
        }
    }

    /**
     * Izinnya dihitung TERLEPAS dari apakah versi tersembunyi sedang diminta.
     *
     * Versi pertama `list_versions.php` menghitungnya di dalam `if ($minta)`,
     * dan itu berputar: pemuatan pertama tidak mengirim `include_hidden`, jadi
     * jawabannya selalu 0, jadi tombol penyaringnya tidak pernah muncul —
     * padahal tombol itulah satu-satunya yang bisa mengirim `include_hidden`.
     *
     * Bug yang tidak menjatuhkan apa pun: tidak ada galat, tidak ada tes merah,
     * cuma tombol yang tidak pernah ada. Dilaporkan pengguna, bukan ditemukan
     * di sini — dan itu jenis yang paling lama hidup.
     *
     * "Boleh mengurus versi tersembunyi" adalah sifat PENGGUNANYA, bukan sifat
     * permintaan yang sedang berjalan.
     */
    public function testIzinDihitungTerlepasDariPermintaan(): void
    {
        // Komentarnya dibuang lebih dulu. Berkas itu MENJELASKAN kenapa logika
        // berputar tadi salah — dan penjelasan itu menyebut `include_hidden`
        // jauh sebelum kodenya. Pemeriksa yang ikut membaca komentar akan
        // menuduh dokumentasi yang baik sebagai bug.
        //
        // Kesalahan yang sama pernah terjadi di PdfComposeSnapshotTest, dan
        // diperbaiki dengan cara yang sama.
        $kode = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 2) . '/actions/document/list_versions.php'
        )) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        $posIzin  = strpos($kode, 'ezdoc_can_hide_versions(');
        $posMinta = strpos($kode, 'include_hidden');

        self::assertNotFalse($posIzin, 'list_versions.php tidak lagi menanyakan izinnya');
        self::assertNotFalse($posMinta, 'list_versions.php tidak lagi mengenal include_hidden');

        self::assertLessThan(
            $posMinta,
            $posIzin,
            'izinnya dihitung SESUDAH memeriksa include_hidden — itu berputar: '
            . 'tombolnya baru muncul kalau sudah ditekan'
        );
    }

    /** Aturannya harus ada di SATU tempat, bukan disalin ke pemanggilnya. */
    public function testAturannyaTidakDisalinKeTempatLain(): void
    {
        $root = dirname(__DIR__, 2);
        $penyalin = [];

        foreach ([
            'actions/document/delete_version.php',
            'actions/document/restore_version.php',
            'actions/document/list_versions.php',
            'views/document/generate.php',
        ] as $rel) {
            $isi = (string) file_get_contents($root . '/' . $rel);

            // Tanda aturannya disalin: memeriksa sendiri isi access_config.delete
            // alih-alih menanyakannya ke satu tempat.
            if (preg_match("/\\\$\w*[Dd]eleteCfg|\['delete'\]\s*\?\?/", $isi) === 1) {
                $penyalin[] = $rel;
            }
        }

        self::assertSame(
            [],
            $penyalin,
            "aturan izin disalin ke: " . implode(', ', $penyalin)
            . " — empat salinan akan menyimpang, dan menyimpangnya tidak berisik"
        );
    }

    /**
     * Banner "disembunyikan" menyebut NAMA, bukan id mentah.
     *
     * Dilaporkan dari pemakaian: bannernya berbunyi "By: 18", sementara panel
     * riwayat di halaman yang sama sudah menyebut namanya. Dua tempat yang
     * menunjuk orang yang sama, dengan dua cara berbeda — dan yang satu tidak
     * bisa dibaca siapa pun tanpa membuka tabel.
     *
     * Yang dijaga: bannernya lewat ezdoc_actor_names(), pintu yang sama dengan
     * riwayat. Bukan query sendiri, bukan id yang dicetak apa adanya.
     *
     * Komentarnya dibuang dulu. Penjelasan di atas menyebut id mentah sebagai
     * contoh yang salah, dan pemeriksa yang ikut membaca komentar akan menuduh
     * dokumentasi sebagai bug — kesalahan yang sudah dua kali terjadi di suite
     * ini.
     */
    public function testBannerTersembunyiMenyebutNamaBukanId(): void
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 2) . '/views/document/generate.php'
        )) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        $pos = strpos($kode, "'toolbar.hidden_by'");
        self::assertNotFalse($pos, 'banner versi tersembunyi hilang — premis tes ini perlu ditinjau');

        $baris = substr($kode, $pos, 200);

        self::assertStringContainsString(
            '$param_deleted_by_label',
            $baris,
            'banner memakai nilai lain — id mentah akan tampil sebagai "By: 18"'
        );

        self::assertStringNotContainsString(
            'h($param_deleted_by ',
            $baris,
            'id mentahnya dicetak langsung ke banner'
        );

        self::assertStringContainsString(
            'ezdoc_actor_names([$__hidId])',
            $kode,
            'namanya harus lewat pintu yang sama dengan panel riwayat'
        );
    }

    /**
     * Tombol Hide/Restore Version ikut HAK-nya, bukan $isSuperadmin.
     *
     * Dilaporkan dari pemakaian: haknya sudah dinaikkan supaya bukan superadmin
     * saja yang bisa menyembunyikan versi — delete_version.php dan
     * restore_version.php sudah memakai penjaga bersama — tapi tombolnya masih
     * di dalam blok yang dijaga $isSuperadmin. Servernya mengizinkan sesuatu
     * yang tidak ada tombolnya.
     *
     * Jenis bug yang tidak berisik: tidak ada galat, tidak ada penolakan, cuma
     * fitur yang tidak pernah terlihat oleh orang yang berhak memakainya.
     *
     * Yang dijaga sekaligus: PDF Raw TIDAK ikut naik. Ia memperlihatkan keluaran
     * mentah dokumen milik siapa pun, dan membuka satu bagian toolbar tidak
     * boleh diam-diam membagikan seluruh isinya.
     */
    public function testTombolVersiIkutHaknyaBukanSuperadmin(): void
    {
        $kode = '';
        foreach (token_get_all((string) file_get_contents(
            dirname(__DIR__, 2) . '/views/document/generate.php'
        )) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kode .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringContainsString(
            '$bolehSembunyikanVersi = ezdoc_can_hide_versions(',
            $kode,
            'izinnya harus dinamai sekali, dari penjaga bersama'
        );

        foreach (['deleteThisVersion()', 'restoreThisVersion()'] as $aksi) {
            $pos = strpos($kode, 'onclick="' . $aksi . '"');
            self::assertNotFalse($pos, "tombol {$aksi} hilang — premis tes ini perlu ditinjau");

            $sebelum = substr($kode, max(0, $pos - 500), min($pos, 500));
            self::assertStringContainsString(
                '$bolehSembunyikanVersi',
                $sebelum,
                "{$aksi} masih dijaga hak lain — yang berhak tidak akan pernah melihat tombolnya"
            );
        }

        $posPdf = strpos($kode, 'onclick="viewPdfRaw()"');
        self::assertNotFalse($posPdf, 'tombol PDF Raw hilang — premis tes ini perlu ditinjau');

        // Bagian Admin dijaga superadmin saja, tanpa "atau boleh menyembunyikan".
        self::assertStringContainsString('if ($isSuperadmin): ?>', $kode);
        self::assertStringNotContainsString(
            'if ($isSuperadmin || $bolehSembunyikanVersi)',
            $kode,
            'bagian Admin ikut terbuka untuk yang bukan admin'
        );

        // Dan tombol versinya berada SEBELUM bagian Admin — artinya di MORE.
        //
        // Sejak haknya ikut `edit`, menyembunyikan versi bukan aksi admin. Bagian
        // bernama ADMIN yang isinya aksi non-admin membuat orang yang berhak
        // mengira ia sedang menyalahi wewenang. Dilaporkan dari layar pengguna.
        foreach (['deleteThisVersion()', 'restoreThisVersion()'] as $aksi) {
            self::assertLessThan(
                $posPdf,
                strpos($kode, 'onclick="' . $aksi . '"'),
                "{$aksi} kembali masuk bagian Admin — padahal haknya bukan hak admin"
            );
        }
    }
}
