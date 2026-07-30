<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Document;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Document\DocumentRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the list/fetch projection split against a real SQL engine.
 *
 * The saving is measured, not guessed: `signature_values` was 97.3% of document
 * data (16.5 KB per document, 46 documents), and `findRecent()` serves up to
 * 1000 rows. Every list load pulled every signature image only to discard it.
 *
 * Asserted through behaviour rather than by inspecting the SQL string. A test
 * that greps the query would pass while the column still arrives via `SELECT *`
 * somewhere, and would fail on a harmless reordering. What matters is what the
 * caller can and cannot read.
 */
final class DocumentRepositoryProjectionTest extends TestCase
{
    private \PDO $pdo;
    private DocumentRepository $repo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Only the columns the repository asks for, plus deleted_at for the
        // WHERE clause. ColumnIntrospector intersects its wish list with what
        // actually exists, so a narrow table is a valid consumer schema.
        $this->pdo->exec(
            'CREATE TABLE ezdoc_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL,
                template_id INTEGER NULL,
                template_uuid TEXT NULL,
                template_version INTEGER NULL,
                title TEXT NULL,
                norm TEXT NULL,
                nopen TEXT NULL,
                label TEXT NULL,
                version INTEGER NULL,
                field_values TEXT NULL,
                signature_values TEXT NULL,
                metadata TEXT NULL,
                status TEXT NULL,
                is_locked INTEGER NULL,
                revision INTEGER NULL,
                deleted_at TEXT NULL
            )'
        );

        $this->repo = new DocumentRepository(new PdoConnection($this->pdo));
    }

    private function insert(string $status = 'draft', int $templateId = 3): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_documents
               (uuid, template_id, template_uuid, template_version, title,
                field_values, signature_values, metadata, status, revision)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            '0190aaaa-' . bin2hex(random_bytes(6)),
            $templateId,
            '0190bbbb-0000-7000-8000-000000000002',
            'Surat',
            '{"nama":"Budi"}',
            '{"ttd_a":"data:image/png;base64,AAAABBBBCCCC"}',
            '{}',
            $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    // ─── Single-document fetch keeps the signatures ───

    public function testFindByIdLoadsSignatures(): void
    {
        $id = $this->insert();
        $doc = $this->repo->findById($id);

        self::assertNotNull($doc);
        self::assertTrue($doc->hasLoadedSignatureValues());
        self::assertSame(
            ['ttd_a' => 'data:image/png;base64,AAAABBBBCCCC'],
            $doc->getSignatureValues()
        );
    }

    public function testFindByUuidLoadsSignatures(): void
    {
        $this->insert();
        $uuid = (string) $this->pdo->query('SELECT uuid FROM ezdoc_documents LIMIT 1')->fetchColumn();

        $doc = $this->repo->findByUuid($uuid);
        self::assertNotNull($doc);
        self::assertTrue($doc->hasLoadedSignatureValues());
    }

    // ─── List queries leave them behind ───

    /**
     * @dataProvider listMethods
     */
    public function testListQueriesOmitSignatures(string $method, array $args): void
    {
        $this->insert();
        $this->insert();

        /** @var array<int,\Ezdoc\Document\Document> $docs */
        $docs = $this->repo->{$method}(...$args);

        self::assertNotEmpty($docs, "{$method} returned nothing — the fixture or the filter is wrong");
        foreach ($docs as $doc) {
            self::assertFalse(
                $doc->hasLoadedSignatureValues(),
                "{$method} should not load signature images — they are ~97% of the payload and no list renders them"
            );
        }
    }

    /** @return array<string, array{string, list<mixed>}> */
    public static function listMethods(): array
    {
        return [
            'findRecent'     => ['findRecent', ['', '', 100]],
            'listByTemplate' => ['listByTemplate', [3, 100, 0]],
            'listByStatus'   => ['listByStatus', ['draft', 100]],
        ];
    }

    /**
     * Reading them off a list result must fail loudly. Returning [] would report
     * a signed document as unsigned.
     */
    public function testReadingSignaturesOffAListResultThrows(): void
    {
        $this->insert();
        $docs = $this->repo->findRecent();

        $this->expectException(\LogicException::class);
        $docs[0]->getSignatureValues();
    }

    /**
     * The remedy the error message names has to actually work — otherwise the
     * advice is wrong and the caller is stuck.
     */
    public function testReloadingByIdRecoversTheSignatures(): void
    {
        $id = $this->insert();
        $listed = $this->repo->findRecent()[0];
        self::assertFalse($listed->hasLoadedSignatureValues());

        $full = $this->repo->findById($listed->getId());
        self::assertNotNull($full);
        self::assertSame($id, $full->getId());
        self::assertTrue($full->hasLoadedSignatureValues());
        self::assertArrayHasKey('ttd_a', $full->getSignatureValues());
    }

    // ─── Everything else survives the split ───

    /**
     * The list loses exactly one column. If it lost more, pages would start
     * showing blanks for fields the projection quietly dropped.
     */
    public function testListsStillCarryEverythingTheyRender(): void
    {
        $this->insert('published');
        $doc = $this->repo->findRecent()[0];

        self::assertGreaterThan(0, $doc->getId());
        self::assertNotSame('', $doc->getUuid());
        self::assertSame('Surat', $doc->getTitle());
        self::assertSame('published', $doc->getStatus());
        self::assertSame(1, $doc->getTemplateVersion());
        self::assertSame('Budi', $doc->getFieldValues()['nama']);
    }

    /**
     * The filters still filter. A projection change that also changed the WHERE
     * clause would be caught here rather than by someone noticing the wrong rows.
     */
    public function testFiltersStillApply(): void
    {
        $this->insert('draft', 3);
        $this->insert('published', 3);
        $this->insert('draft', 9);

        self::assertCount(1, $this->repo->listByStatus('published', 100));
        self::assertCount(2, $this->repo->listByTemplate(3, 100, 0));
        self::assertCount(1, $this->repo->listByTemplate(9, 100, 0));
        self::assertCount(3, $this->repo->findRecent('', '', 100));
    }

    public function testSoftDeletedRowsStayOutOfLists(): void
    {
        $keep = $this->insert();
        $gone = $this->insert();
        $this->pdo->exec('UPDATE ezdoc_documents SET deleted_at = \'2026-07-30 00:00:00\' WHERE id = ' . $gone);

        $docs = $this->repo->findRecent();
        self::assertCount(1, $docs);
        self::assertSame($keep, $docs[0]->getId());
        self::assertNull($this->repo->findById($gone));
    }
}
