<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Db\Pdo\PdoConnection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the gate that decides which signature slots stop offering edit and
 * delete.
 *
 * The distinction it draws is the whole point: a slot holding an image is not
 * the same as a slot with a row in `ezdoc_signatures`. Documents created before
 * recording was switched on hold images with no record, and those must stay
 * editable — locking them would strand every older document with no way forward.
 *
 * What must lock is the *record*, because that is what makes the server refuse
 * the change. A UI that locks on anything else either invites an action that
 * will be rejected, or forbids one that would have been fine.
 */
final class RecordedSlotsTest extends TestCase
{
    private \PDO $pdo;
    private PdoConnection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        require_once dirname(__DIR__, 2) . '/lib/signature_image.php';

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // ezdoc_signatures carries a foreign key to ezdoc_documents, so the
        // parent table has to exist even though nothing here reads it.
        $this->pdo->exec('CREATE TABLE ezdoc_documents (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->pdo->exec((string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/sqlite/2026_01_01_000005_create_ezdoc_signatures.sql'
        ));
        $this->pdo->exec('INSERT INTO ezdoc_documents (id) VALUES (7), (8)');

        $this->db = new PdoConnection($this->pdo);
    }

    /** @param array<string,mixed>|null $metadata */
    private function record(int $docId, ?array $metadata, ?string $deletedAt = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_signatures
               (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                envelope_format, envelope, content_hash, content_hash_algo, signed_at,
                verify_status, metadata, deleted_at)
             VALUES (?, ?, 0, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            '0190dddd-' . bin2hex(random_bytes(6)),
            $docId,
            '18',
            'hmac',
            'hmac',
            'envelope',
            'hash',
            'sha256-jcs-v1',
            '2026-07-30 10:00:00.000',
            'valid',
            $metadata === null ? null : (string) json_encode($metadata),
            $deletedAt,
        ]);
    }

    public function testARecordedSlotIsReported(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a', 'ttd_label' => 'Kepala Unit']);

        self::assertSame(['ttd_a'], ezdoc_recorded_signature_slots($this->db, 7));
    }

    public function testADocumentWithNoRecordsReportsNothing(): void
    {
        self::assertSame([], ezdoc_recorded_signature_slots($this->db, 7));
    }

    /**
     * The case that keeps older documents usable: an image with no record must
     * not lock. Locking it would strand every document created before recording
     * was switched on.
     */
    public function testOnlyRecordsCountNotImages(): void
    {
        // No row inserted at all — the document may well hold images.
        self::assertSame([], ezdoc_recorded_signature_slots($this->db, 7));
    }

    public function testRecordsOfOtherDocumentsAreNotReported(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a']);
        $this->record(8, ['ttd_id' => 'ttd_b']);

        self::assertSame(['ttd_a'], ezdoc_recorded_signature_slots($this->db, 7));
        self::assertSame(['ttd_b'], ezdoc_recorded_signature_slots($this->db, 8));
    }

    /**
     * A withdrawn signature stops locking the slot. That is what withdrawal is
     * for — otherwise revoking would leave the slot permanently unusable while
     * claiming the signature no longer counts.
     */
    public function testSoftDeletedRecordsDoNotLock(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a'], '2026-07-30 12:00:00');

        self::assertSame([], ezdoc_recorded_signature_slots($this->db, 7));
    }

    public function testMultipleSlotsAreAllReported(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a']);
        $this->record(7, ['ttd_id' => 'ttd_b']);

        $slots = ezdoc_recorded_signature_slots($this->db, 7);
        sort($slots);
        self::assertSame(['ttd_a', 'ttd_b'], $slots);
    }

    /**
     * Re-signing writes a second row for the same slot. The slot is one slot, so
     * a duplicate must not turn into a duplicate entry — callers use `in_array`,
     * and a list that repeats itself is a list nobody can reason about.
     */
    public function testARepeatedlySignedSlotAppearsOnce(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a']);
        $this->record(7, ['ttd_id' => 'ttd_a']);

        self::assertSame(['ttd_a'], ezdoc_recorded_signature_slots($this->db, 7));
    }

    /**
     * Rows whose metadata cannot name a slot are skipped rather than throwing.
     * Failing to render a document because one audit row is malformed would be a
     * poor trade — and the real boundary is the save guard, not this.
     *
     * @dataProvider unusableMetadata
     */
    public function testUnusableMetadataIsSkipped(?string $json): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_signatures
               (uuid, document_id, signature_id_within_doc, signer_id, provider, level,
                envelope_format, envelope, content_hash, content_hash_algo, signed_at,
                verify_status, metadata)
             VALUES (?, 7, 0, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            '0190eeee-' . bin2hex(random_bytes(6)),
            '18', 'hmac', 'hmac', 'envelope', 'hash', 'sha256-jcs-v1',
            '2026-07-30 10:00:00.000', 'valid', $json,
        ]);

        self::assertSame([], ezdoc_recorded_signature_slots($this->db, 7));
    }

    /** @return array<string, array{string|null}> */
    public static function unusableMetadata(): array
    {
        return [
            'null'              => [null],
            'kosong'            => ['']  ,
            'JSON rusak'        => ['{tidak valid'],
            'tanpa ttd_id'      => ['{"ttd_label":"Kepala Unit"}'],
            'ttd_id kosong'     => ['{"ttd_id":""}'],
            'bukan objek'       => ['"sebuah string"'],
        ];
    }

    public function testANonPositiveDocumentIdReportsNothing(): void
    {
        $this->record(7, ['ttd_id' => 'ttd_a']);

        self::assertSame([], ezdoc_recorded_signature_slots($this->db, 0));
        self::assertSame([], ezdoc_recorded_signature_slots($this->db, -1));
    }

    /**
     * A missing table must not break rendering. The document still opens; the
     * save guard is what actually enforces the rule.
     */
    public function testAMissingTableIsNotFatal(): void
    {
        $bare = new \PDO('sqlite::memory:');
        $bare->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        self::assertSame([], ezdoc_recorded_signature_slots(new PdoConnection($bare), 7));
    }
}
