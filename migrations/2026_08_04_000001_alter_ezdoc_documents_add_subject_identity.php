<?php
/**
 * Tahap EXPAND — tambahkan identitas subjek yang generik di `ezdoc_documents`.
 *
 * Lihat docs/260804-skema-subjek.md. Ini langkah E1 + E2: kolom baru berdiri
 * berdampingan dengan `norm`/`nopen`, lalu diisi dari keduanya. **Belum ada
 * satu pun kode yang membacanya.** Migrasi ini tidak bisa merusak apa pun, dan
 * bisa ditinggalkan begitu saja kalau rencananya berubah.
 *
 * ## Kenapa tiga kolom, bukan dua
 *
 * `norm` dan `nopen` bukan satu hal: `norm` menyebut SIAPA (pasiennya,
 * bertahan lintas kunjungan), `nopen` menyebut KUNJUNGAN MANA. Diperas jadi
 * satu `subject_id`, pertanyaan "semua dokumen pasien ini di semua kunjungan"
 * tidak bisa dijawab lagi.
 *
 * Bentuk `type` + `id` + konteks mengikuti `DocumentReference` FHIR, yang punya
 * `subject` (Patient) dan `context.encounter` (Encounter) — persis pasangan
 * ini. Nama `context` diambil dari sana.
 *
 * ## `subject_type` NOT NULL DEFAULT '', dan itu bukan kelalaian
 *
 * Kolom ini akan ikut masuk kunci unik slot (langkah E4). Di indeks unik MySQL,
 * NULL dianggap berbeda dari NULL — jadi kalau `subject_type` boleh NULL,
 * dua baris yang seharusnya bentrok akan sama-sama diterima dan keunikan slot
 * runtuh tanpa satu pun pesan kesalahan.
 *
 * String kosong berarti "belum dinyatakan". Bukan `'patient'`: nilai bawaan itu
 * akan memaku rumah sakit ke dalam skema library — persis yang sedang
 * dihilangkan rencana ini.
 *
 * ## Yang TIDAK diisi migrasi ini
 *
 * `subject_id` dan `context_id` diisi dari `norm`/`nopen` karena itu penyalinan
 * mekanis — tidak butuh tahu apa pun tentang domainnya.
 *
 * `subject_type` **dibiarkan kosong**. Menyimpulkan bahwa baris lama adalah
 * pasien memerlukan pengetahuan yang cuma dimiliki aplikasi pemakainya. Ia yang
 * mengisinya, satu perintah:
 *
 *     UPDATE ezdoc_documents SET subject_type = 'patient' WHERE subject_type = '';
 *
 * ## Idempoten
 *
 * Kolom diperiksa dulu lewat introspeksi, bukan diasumsikan belum ada. Registry
 * migrasi pernah kehilangan sinkron di proyek ini dan bootstrap punya jalur
 * auto-heal yang bisa menjalankan ulang — migrasi yang meledak saat diulang
 * mengubah pemulihan jadi kerusakan kedua.
 */

use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Db\Schema\ColumnIntrospector;

return [
    'name' => '2026_08_04_000001_alter_ezdoc_documents_add_subject_identity',
    'up' => function ($conn): void {
        $db = new MysqliConnection($conn);
        $introspector = new ColumnIntrospector($db);

        $columns = [
            'subject_type' => "ADD COLUMN subject_type VARCHAR(32) NOT NULL DEFAULT ''"
                . " COMMENT 'patient | employee | student | party | customer — diisi aplikasi pemakai.'"
                . " AFTER template_version",

            'subject_id' => "ADD COLUMN subject_id VARCHAR(50) NULL"
                . " COMMENT 'Identitas subjek di sistem pemakainya. Menggantikan norm.'"
                . " AFTER subject_type",

            'context_id' => "ADD COLUMN context_id VARCHAR(50) NULL"
                . " COMMENT 'Episode/konteks. Menggantikan nopen.'"
                . " AFTER subject_id",
        ];

        foreach ($columns as $name => $clause) {
            if ($introspector->hasColumn('ezdoc_documents', $name)) {
                continue;
            }

            if ($conn->query('ALTER TABLE ezdoc_documents ' . $clause) === false) {
                throw new \RuntimeException(
                    "add column {$name} to ezdoc_documents failed: " . $conn->error
                );
            }

            $introspector->forget('ezdoc_documents');
        }

        // ─── E2: isi dari kolom lama ───
        //
        // Hanya baris yang belum terisi, supaya menjalankan ulang tidak menimpa
        // koreksi yang mungkin sudah dibuat orang secara manual.
        $ok = $conn->query(
            "UPDATE ezdoc_documents
                SET subject_id = norm,
                    context_id = nopen
              WHERE subject_id IS NULL
                AND context_id IS NULL"
        );

        if ($ok === false) {
            throw new \RuntimeException('backfill subject_id/context_id failed: ' . $conn->error);
        }

        // Indeks pendamping untuk pola query slot. Kunci UNIK-nya menyusul di
        // langkah E4, setelah aplikasi pemakai mengisi subject_type — sebelum
        // itu setiap baris punya subject_type yang sama dan indeks uniknya tidak
        // menambah jaminan apa pun di atas yang lama.
        $conn->query(
            'CREATE INDEX idx_subject ON ezdoc_documents (subject_type, subject_id, context_id)'
        );
    },
    'down' => function ($conn): void {
        $conn->query('DROP INDEX idx_subject ON ezdoc_documents');
        $conn->query('ALTER TABLE ezdoc_documents DROP COLUMN context_id');
        $conn->query('ALTER TABLE ezdoc_documents DROP COLUMN subject_id');
        $conn->query('ALTER TABLE ezdoc_documents DROP COLUMN subject_type');
    },
];
