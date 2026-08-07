<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Document\ArtifactRepository;
use Ezdoc\Document\FreezeService;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Membekukan dokumen jadi artefak yang byte-nya tidak berubah lagi.
 *
 * Yang benar-benar diuji di sini bukan "apakah barisnya tersimpan" tapi tiga
 * janji yang membuat pembekuan ada gunanya:
 *
 *   1. yang beku tidak bisa dibekukan dua kali diam-diam
 *   2. mencabut tidak menghapus apa pun — siklus lama tetap bisa diambil
 *   3. hash artefaknya bukan salinan kedua, melainkan kunci isinya sendiri
 *
 * Migrasi yang dijalankan adalah yang benar-benar dikirim, bukan skema yang
 * ditulis ulang di sini. Skema salinan akan lulus sementara yang aslinya rusak —
 * pelajaran dari empat migrasi SQLite yang berbohong sepanjang Agustus 2026.
 *
 * Butuh pdo_sqlite.
 */
final class FreezeServiceTest extends TestCase
{
    private const ACTOR = 'user:7';

    private \PDO $pdo;
    private PdoConnection $db;
    private FreezeService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $migrations = dirname(__DIR__, 2) . '/migrations/sqlite/';
        foreach ([
            '2026_01_01_000001_create_ezdoc_templates.sql',
            '2026_01_01_000002_create_ezdoc_documents.sql',
            '2026_01_01_000005_create_ezdoc_signatures.sql',
            '2026_07_30_000001_create_ezdoc_blobs.sql',
            '2026_08_08_000001_create_ezdoc_document_artifacts.sql',
        ] as $file) {
            $this->pdo->exec((string) file_get_contents($migrations . $file));
        }

        $this->db = new PdoConnection($this->pdo);

        // Templatenya nyata, bukan disiasati dengan mematikan foreign key.
        // FK yang dimatikan di tes membuat baris tidak sah lolos di sini dan
        // gagal di produksi.
        $this->pdo->exec(
            "INSERT INTO ezdoc_templates (id, uuid, name) VALUES (1, 'tpl-uuid-1', 'Uji')"
        );
        $this->pdo->exec(
            "INSERT INTO ezdoc_documents (id, uuid, template_id, template_uuid, template_version, label)
             VALUES (1, 'doc-uuid-1', 1, 'tpl-uuid-1', 1, '-')"
        );

        $this->service = new FreezeService(
            $this->db,
            new ArtifactRepository($this->db, new FrozenClock('2026-08-08 09:00:00')),
            new DatabaseBlobStore($this->db)
        );
    }

    // ─── membekukan ───

    public function testFreezingProducesAnArtifactWhoseKeyIsTheContentHash(): void
    {
        $bytes = '%PDF-1.4 pura-pura ini dokumen';

        $artifact = $this->service->freeze(1, $bytes, self::ACTOR);

        self::assertSame(1, $artifact->getCycle());
        self::assertTrue($artifact->isActive());
        self::assertSame(hash('sha256', $bytes), $artifact->getBlobKey());
    }

    public function testTheBytesComeBackExactly(): void
    {
        $bytes = "%PDF-1.4\x00\x01\x02 byte biner apa adanya";

        $artifact = $this->service->freeze(1, $bytes, self::ACTOR);

        self::assertSame($bytes, $this->service->bytes($artifact));
    }

    public function testADocumentIsFrozenOnlyAfterFreezing(): void
    {
        self::assertFalse($this->service->isFrozen(1));

        $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        self::assertTrue($this->service->isFrozen(1));
    }

    /**
     * Membekukan dua kali diam-diam akan menghasilkan dua artefak "aktif", dan
     * tidak ada yang bisa menjawab mana yang berlaku.
     */
    public function testFreezingTwiceIsRefused(): void
    {
        $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        $this->expectException(ValidationException::class);
        $this->service->freeze(1, '%PDF-1.4 y', self::ACTOR);
    }

    public function testFreezingNothingIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->freeze(1, '', self::ACTOR);
    }

    public function testFreezingAnAbsentDocumentIsRefused(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->freeze(999, '%PDF-1.4 x', self::ACTOR);
    }

    // ─── mencabut ───

    public function testUnfreezingMakesTheDocumentEditableAgain(): void
    {
        $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        self::assertTrue($this->service->unfreeze(1, 'salah tanggal lahir pasien', self::ACTOR));
        self::assertFalse($this->service->isFrozen(1));
    }

    /**
     * Pertanyaan yang diajukan setahun kemudian bukan "kapan dicabut" tapi
     * "kenapa". Catatan tanpa alasan tidak menjawabnya.
     */
    public function testUnfreezingWithoutARealReasonIsRefused(): void
    {
        $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        $this->expectException(ValidationException::class);
        $this->service->unfreeze(1, 'ok', self::ACTOR);
    }

    public function testUnfreezingWhatIsNotFrozenChangesNothing(): void
    {
        self::assertFalse($this->service->unfreeze(1, 'tidak ada yang beku di sini', self::ACTOR));
    }

    /**
     * Yang dicabut tetap bisa diambil. Orang yang memegang cetakan siklus lama
     * harus tetap bisa memeriksanya — justru karena ia sudah tidak berlaku.
     */
    public function testASupersededArtifactKeepsItsBytes(): void
    {
        $bytes = '%PDF-1.4 siklus pertama';
        $first = $this->service->freeze(1, $bytes, self::ACTOR);
        $this->service->unfreeze(1, 'perlu diperbaiki dulu isinya', self::ACTOR);

        self::assertSame($bytes, $this->service->bytes($first));

        $repo = new ArtifactRepository($this->db);
        $reloaded = $repo->findByUuid($first->getUuid());

        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
        self::assertSame('perlu diperbaiki dulu isinya', $reloaded->getSupersededReason());
    }

    // ─── siklus ───

    public function testRefreezingStartsANewCycleWithoutTouchingTheOldOne(): void
    {
        $first = $this->service->freeze(1, '%PDF-1.4 satu', self::ACTOR);
        $this->service->unfreeze(1, 'nama penanda tangan keliru', self::ACTOR);
        $second = $this->service->freeze(1, '%PDF-1.4 dua', self::ACTOR);

        self::assertSame(1, $first->getCycle());
        self::assertSame(2, $second->getCycle());
        self::assertNotSame($first->getBlobKey(), $second->getBlobKey());

        $history = (new ArtifactRepository($this->db))->historyFor(1);

        self::assertCount(2, $history, 'mencabut tidak boleh menghapus siklus lama');
        self::assertSame(2, $history[0]->getCycle());
    }

    /**
     * Dua siklus dengan byte identik menunjuk satu baris blob — kuncinya isi,
     * dan isi yang sama tidak disimpan dua kali.
     */
    public function testIdenticalBytesShareOneStoredObject(): void
    {
        $bytes = '%PDF-1.4 isinya persis sama';

        $first = $this->service->freeze(1, $bytes, self::ACTOR);
        $this->service->unfreeze(1, 'dicabut lalu dibekukan lagi apa adanya', self::ACTOR);
        $second = $this->service->freeze(1, $bytes, self::ACTOR);

        self::assertSame($first->getBlobKey(), $second->getBlobKey());
        self::assertSame(
            1,
            (int) $this->db->fetchScalar('SELECT COUNT(*) FROM ezdoc_blobs')
        );
    }

    // ─── manifes ───

    public function testTheManifestNamesTheSignaturesItFroze(): void
    {
        $this->insertSignature('sig-uuid-1', 'ttd_pasien', 'Siti', 'hash-aaa');
        $this->insertSignature('sig-uuid-2', 'ttd_dokter', 'dr. Budi', 'hash-bbb', 1);

        $manifest = $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR)->getManifest();

        self::assertSame('doc-uuid-1', $manifest['document_uuid']);
        self::assertCount(2, $manifest['signatures']);

        self::assertSame('ttd_pasien', $manifest['signatures'][0]['ttd_id']);
        self::assertSame('Siti', $manifest['signatures'][0]['signer_name']);
        self::assertSame('hash-aaa', $manifest['signatures'][0]['content_hash']);
    }

    /**
     * `content_hash` disalin, bukan cuma uuid-nya. Rujukan yang menyebut baris
     * bisa menunjuk isi lain besok; rujukan yang menyebut isi tidak bisa.
     */
    public function testTheManifestCarriesTheHashNotJustTheReference(): void
    {
        $this->insertSignature('sig-uuid-1', 'ttd_pasien', 'Siti', 'hash-asli');

        $artifact = $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        $this->pdo->exec("UPDATE ezdoc_signatures SET content_hash = 'hash-diganti'");

        $reloaded = (new ArtifactRepository($this->db))->findByUuid($artifact->getUuid());

        self::assertNotNull($reloaded);
        self::assertSame(
            'hash-asli',
            $reloaded->signatures()[0]['content_hash'],
            'manifes membekukan apa yang benar-benar ditandatangani saat itu'
        );
    }

    public function testAnUnsignedDocumentStillFreezes(): void
    {
        $artifact = $this->service->freeze(1, '%PDF-1.4 x', self::ACTOR);

        self::assertSame([], $artifact->signatures());
    }

    private function insertSignature(
        string $uuid,
        string $ttdId,
        string $signerName,
        string $contentHash,
        int $withinDoc = 0
    ): void {
        $meta = json_encode(['ttd_id' => $ttdId, 'signer_name' => $signerName]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_signatures
                (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                 envelope_format, envelope, content_hash, signed_at, metadata)
             VALUES (?, 1, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $uuid,
            $withinDoc,
            'user:7',
            'hmac',
            'hmac',
            'x',
            $contentHash,
            '2026-08-08 09:00:00.000',
            $meta,
        ]);
    }
}
