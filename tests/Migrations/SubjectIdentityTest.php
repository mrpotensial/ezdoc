<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Identitas subjek yang generik — tahap EXPAND.
 *
 * Lihat docs/PLAN-SUBJECT-SCHEMA.md. Yang diuji di sini bukan bahwa kolomnya
 * ada — itu bagian yang mudah — melainkan satu keputusan yang gampang dibalik
 * oleh orang berikutnya karena kelihatan seperti kelewatan: `subject_type`
 * NOT NULL DEFAULT '', bukan NULL.
 *
 * @see \Ezdoc\Tests\Migrations\DialectParityTest
 */
final class SubjectIdentityTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../migrations/sqlite/';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }
    }

    private function documents(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(self::MIGRATIONS . '2026_01_01_000001_create_ezdoc_templates.sql'));
        $pdo->exec((string) file_get_contents(self::MIGRATIONS . '2026_01_01_000002_create_ezdoc_documents.sql'));

        return $pdo;
    }

    /** @return array<string,array<string,mixed>> */
    private function columns(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('PRAGMA table_info(ezdoc_documents)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['name']] = $row;
        }

        return $out;
    }

    public function testTheThreeColumnsExist(): void
    {
        $columns = $this->columns($this->documents());

        foreach (['subject_type', 'subject_id', 'context_id'] as $name) {
            self::assertArrayHasKey($name, $columns);
        }
    }

    /** Kolom lama masih ada — tahap expand tidak membuang apa pun. */
    public function testTheOldColumnsAreStillThere(): void
    {
        $columns = $this->columns($this->documents());

        self::assertArrayHasKey('norm', $columns);
        self::assertArrayHasKey('nopen', $columns);
    }

    public function testSubjectTypeIsNotNullable(): void
    {
        $columns = $this->columns($this->documents());

        self::assertSame(1, (int) $columns['subject_type']['notnull']);
        self::assertSame("''", (string) $columns['subject_type']['dflt_value']);
    }

    /**
     * Alasannya, dibuktikan dengan menjalankannya.
     *
     * `subject_type` akan ikut masuk kunci unik slot. Di indeks unik, NULL
     * dianggap berbeda dari NULL — jadi kolom pembeda yang boleh NULL membuat
     * dua baris yang seharusnya bentrok sama-sama diterima, tanpa satu pun
     * pesan kesalahan.
     *
     * Dua tabel dibangun berdampingan supaya bedanya terlihat, bukan
     * dipercayai. Pelajaran ini pernah dibayar di proyek ini: menambahkan
     * `deleted_at` ke sebuah kunci unik menghancurkan kunci itu dengan cara
     * yang sama.
     */
    public function testANullableDiscriminatorWouldDestroyTheUniqueKey(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE boleh_null (subject_type TEXT NULL, subject_id TEXT, version INT)');
        $pdo->exec('CREATE UNIQUE INDEX uk_boleh ON boleh_null (subject_type, subject_id, version)');

        $pdo->exec("CREATE TABLE tidak_null (subject_type TEXT NOT NULL DEFAULT '', subject_id TEXT, version INT)");
        $pdo->exec('CREATE UNIQUE INDEX uk_tidak ON tidak_null (subject_type, subject_id, version)');

        // Dua baris yang, menurut maksudnya, adalah slot yang sama.
        $pdo->exec("INSERT INTO boleh_null VALUES (NULL, '1', 1)");
        $pdo->exec("INSERT INTO boleh_null VALUES (NULL, '1', 1)");

        self::assertSame(
            2,
            (int) $pdo->query('SELECT COUNT(*) FROM boleh_null')->fetchColumn(),
            'kalau ini 1, SQLite berubah perilaku dan alasan NOT NULL-nya perlu ditinjau ulang'
        );

        $pdo->exec("INSERT INTO tidak_null VALUES ('', '1', 1)");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO tidak_null VALUES ('', '1', 1)");
    }

    /**
     * `subject_id` dan `context_id` HARUS boleh NULL — dokumen lingkup umum
     * (tanpa pasien) bergantung pada itu, persis seperti `norm`/`nopen`
     * sekarang. Menyalakan NOT NULL di sini akan menolak dokumen yang hari ini
     * sah.
     */
    public function testTheIdentifiersThemselvesStayNullable(): void
    {
        $columns = $this->columns($this->documents());

        self::assertSame(0, (int) $columns['subject_id']['notnull']);
        self::assertSame(0, (int) $columns['context_id']['notnull']);
        self::assertSame((int) $columns['norm']['notnull'], (int) $columns['subject_id']['notnull']);
        self::assertSame((int) $columns['nopen']['notnull'], (int) $columns['context_id']['notnull']);
    }

    public function testTheLookupIndexExists(): void
    {
        $pdo = $this->documents();
        $indexes = array_column(
            $pdo->query('PRAGMA index_list(ezdoc_documents)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );

        self::assertContains('idx_ezdoc_documents_subject', $indexes);
    }

    /**
     * Migrasi MySQL menambahkan kolom lewat ALTER, bukan CREATE — di sana ada
     * data hidup. Berkas SQLite dipakai tes, yang selalu membangun dari nol.
     * Jalannya berbeda, hasilnya harus sama.
     */
    public function testTheMysqlSideAddsTheSameThree(): void
    {
        $mysql = (string) file_get_contents(
            __DIR__ . '/../../migrations/2026_08_04_000001_alter_ezdoc_documents_add_subject_identity.php'
        );

        foreach (['subject_type', 'subject_id', 'context_id'] as $name) {
            self::assertStringContainsString("ADD COLUMN {$name} ", $mysql);
        }

        self::assertStringContainsString("NOT NULL DEFAULT ''", $mysql);
        self::assertStringContainsString('SET subject_id = norm', $mysql);
        self::assertStringContainsString('context_id = nopen', $mysql);
    }

    /**
     * `subject_type` sengaja TIDAK diisi migrasi. Menyimpulkan bahwa baris lama
     * adalah pasien butuh pengetahuan yang cuma dimiliki aplikasi pemakainya,
     * dan menuliskannya di library berarti memaku satu domain ke dalam paket
     * yang justru sedang dilepaskan darinya.
     */
    public function testTheMigrationNeverGuessesTheSubjectType(): void
    {
        $mysql = (string) file_get_contents(
            __DIR__ . '/../../migrations/2026_08_04_000001_alter_ezdoc_documents_add_subject_identity.php'
        );

        // Baris SQL saja — sebutan 'patient' di komentar justru menjelaskan
        // kenapa ia tidak boleh ada di SQL-nya.
        $sqlOnly = preg_replace('/^\s*(\*|\/\*|\/\/).*$/m', '', $mysql) ?? $mysql;

        self::assertStringNotContainsString("SET subject_type = 'patient'", $sqlOnly);
        self::assertStringNotContainsString("subject_type = 'patient'", $sqlOnly);
    }
}
