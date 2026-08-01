<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Signature\DocumentSignatureVerifier;
use Ezdoc\Signature\SignaturePayload;
use Ezdoc\Signature\SigningKey;
use PHPUnit\Framework\TestCase;

/**
 * Covers signature verification against a real SQL engine (SQLite in-memory).
 *
 * The distinction under test is the one the class exists for: a document edited
 * after signing and a signature row written to directly are different problems,
 * and a verifier that reports both as "invalid" tells nobody which happened.
 *
 * Rows are inserted directly rather than through the save path — the point here
 * is what the verifier makes of stored state, including states the save path
 * would never produce.
 */
final class DocumentSignatureVerifierTest extends TestCase
{
    private const SECRET = 'secret-uji-verifier-yang-cukup-panjang-32';

    private \PDO $pdo;
    private PdoConnection $db;
    private DocumentSignatureVerifier $verifier;
    private int $docId = 0;
    private string $docUuid = '0190aaaa-0000-7000-8000-000000000001';
    private string $tplUuid = '0190bbbb-0000-7000-8000-000000000002';

    /** @var array<string,string> */
    private array $marks = ['ttd_a' => 'gambar-a', 'ttd_b' => 'gambar-b'];

    /** @var array<string,string> */
    private array $fields = ['nama' => 'Budi', 'nik' => '123'];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }
        if (!defined('EZDOC_HMAC_SECRET')) {
            define('EZDOC_HMAC_SECRET', self::SECRET);
        }
        if (constant('EZDOC_HMAC_SECRET') !== self::SECRET) {
            self::markTestSkipped('EZDOC_HMAC_SECRET already defined with another value.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // ezdoc_signatures comes from the shipped SQLite migration, so a column
        // added there and not here shows up as a failure rather than drifting.
        $this->pdo->exec((string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/sqlite/2026_01_01_000005_create_ezdoc_signatures.sql'
        ));

        // ezdoc_documents is a deliberate stand-in, not the shipped migration.
        // The SQLite and MySQL document schemas have diverged structurally —
        // MySQL has norm/nopen/label/signature_values, SQLite has
        // subject_type/subject_id/rendered_html — so the shipped SQLite version
        // cannot hold what this test needs. Only the five columns the verifier
        // reads are declared; widening it would imply a parity that does not
        // exist. See PRD for the divergence itself.
        $this->pdo->exec(
            'CREATE TABLE ezdoc_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL,
                template_uuid TEXT NULL,
                template_version INTEGER NULL,
                field_values TEXT NULL,
                signature_values TEXT NULL
            )'
        );

        $this->db = new PdoConnection($this->pdo);
        $this->verifier = new DocumentSignatureVerifier($this->db);
        SigningKey::reset();

        $this->docId = $this->insertDocument();
    }

    protected function tearDown(): void
    {
        SigningKey::reset();
    }

    private function insertDocument(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_documents (uuid, template_uuid, template_version, field_values, signature_values)
             VALUES (?, ?, 3, ?, ?)'
        );
        $stmt->execute([
            $this->docUuid,
            $this->tplUuid,
            (string) json_encode($this->fields),
            (string) json_encode($this->marks),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Record a signature the way the save path would.
     *
     * @param array<string,mixed> $overrides Force a stored state on purpose
     */
    private function recordSignature(string $ttdId, array $overrides = []): void
    {
        $key = SigningKey::resolve();
        self::assertNotNull($key, 'the test secret should resolve');

        // Default: v1 shape, since most cases here predate the split and describe
        // behaviour that is the same either way. `covered` opts a case into v2.
        $covered = $overrides['covered'] ?? null;

        $contentHash = $overrides['content_hash'] ?? ($covered === null
            ? SignaturePayload::hashV1(
                $this->docUuid,
                $this->tplUuid,
                3,
                $this->fields,
                $ttdId,
                (string) ($this->marks[$ttdId] ?? '')
            )
            : SignaturePayload::hash(
                $this->docUuid,
                $this->tplUuid,
                3,
                $this->fields,
                $ttdId,
                (string) ($this->marks[$ttdId] ?? ''),
                $covered
            ));
        $envelope = $overrides['envelope'] ?? bin2hex(hash_hmac('sha256', (string) $contentHash, $key, true));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ezdoc_signatures
               (uuid, document_id, signature_id_within_doc, signer_id, signer_role,
                provider, level, envelope_format, envelope, content_hash, content_hash_algo,
                signed_at, verify_status, metadata)
             VALUES (?, ?, 0, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            '0190cccc-' . substr(md5($ttdId . mt_rand()), 0, 12),
            $this->docId,
            '18',
            'kepala_unit',
            'hmac',
            $overrides['envelope_format'] ?? 'hmac',
            $envelope,
            $contentHash,
            $overrides['content_hash_algo']
                ?? ($covered === null ? SignaturePayload::ALGO_V1 : SignaturePayload::ALGO),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            $overrides['verify_status'] ?? 'valid',
            (string) json_encode(
                array_key_exists('metadata', $overrides)
                    ? $overrides['metadata']
                    : (['ttd_id' => $ttdId, 'ttd_label' => strtoupper($ttdId)]
                        + ($covered === null ? [] : ['covered_fields' => $covered]))
            ),
        ]);
    }

    /**
     * @param array<string,string> $fields
     */
    private function rewriteDocument(?array $fields = null, ?array $marks = null): void
    {
        if ($fields !== null) $this->fields = $fields;
        if ($marks !== null) $this->marks = $marks;
        $stmt = $this->pdo->prepare('UPDATE ezdoc_documents SET field_values = ?, signature_values = ? WHERE id = ?');
        $stmt->execute([
            (string) json_encode($this->fields),
            (string) json_encode($this->marks),
            $this->docId,
        ]);
    }

    private function statusOf(string $ttdId): string
    {
        $verdicts = $this->verifier->verify($this->docId, $ttdId);
        self::assertCount(1, $verdicts, "expected one verdict for {$ttdId}");

        return (string) $verdicts[0]['status'];
    }

    // ─── The happy case ───

    public function testAnUntouchedSignatureIsValid(): void
    {
        $this->recordSignature('ttd_a');

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));
    }

    public function testTheVerdictCarriesWhoSignedAndWhen(): void
    {
        $this->recordSignature('ttd_a');
        $v = $this->verifier->verify($this->docId, 'ttd_a')[0];

        self::assertSame('18', $v['signer_id']);
        self::assertSame('kepala_unit', $v['signer_role']);
        self::assertNotSame('', $v['signed_at']);
        self::assertSame(1, $v['level']);
        self::assertSame('TTD_A', $v['ttd_label']);
    }

    // ─── The distinction that matters ───

    /**
     * An ordinary, expected event: somebody revised the document. The signature
     * itself is genuine, so reporting it as untrusted would send people looking
     * for an intruder.
     */
    public function testEditingTheDocumentReportsTamperedNotUntrusted(): void
    {
        $this->recordSignature('ttd_a');

        $this->rewriteDocument(['nama' => 'DIUBAH', 'nik' => '123']);

        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_a'));
    }

    /**
     * A different problem: the signature row itself does not hold up. Either the
     * database was written to directly or the key changed — worth investigating,
     * not the same as a revision.
     */
    public function testAnAlteredSignatureRowReportsUntrusted(): void
    {
        $this->recordSignature('ttd_a', ['envelope' => str_repeat('00', 32)]);

        self::assertSame(DocumentSignatureVerifier::STATUS_UNTRUSTED, $this->statusOf('ttd_a'));
    }

    /**
     * Swapping the recorded hash to match edited content does not launder it:
     * the envelope covers the hash, so changing the hash breaks the envelope.
     */
    public function testRewritingTheStoredHashBreaksTheEnvelope(): void
    {
        // The attack: someone with database access edits the document, then
        // rewrites content_hash to match the new content — hoping the signature
        // will simply agree. Without the signing key they cannot recompute the
        // envelope, so the old envelope stays behind and no longer covers the
        // hash it sits next to.
        //
        // Note the envelope is computed from the ORIGINAL hash on purpose. Let
        // the helper derive it from the forged hash and the row becomes
        // internally consistent, which is a different situation entirely — and
        // one only reachable by someone who already holds the key.
        $genuineHash = SignaturePayload::hashV1(
            $this->docUuid, $this->tplUuid, 3, $this->fields, 'ttd_a', $this->marks['ttd_a']
        );
        $genuineEnvelope = bin2hex(hash_hmac('sha256', $genuineHash, (string) SigningKey::resolve(), true));

        $this->recordSignature('ttd_a', [
            'content_hash' => hash('sha256', 'isi-karangan'),
            'envelope'     => $genuineEnvelope,
        ]);

        self::assertSame(DocumentSignatureVerifier::STATUS_UNTRUSTED, $this->statusOf('ttd_a'));
    }

    /**
     * The counterpart, spelled out because the difference is easy to lose: a row
     * that is internally consistent but describes content the document no longer
     * has is `tampered`, not `untrusted`. Only the key holder can produce it,
     * and the honest reading is "this was signed, then the document changed".
     */
    public function testAConsistentRowDescribingOtherContentIsTamperedNotUntrusted(): void
    {
        $forged = hash('sha256', 'isi-lain');
        $this->recordSignature('ttd_a', [
            'content_hash' => $forged,
            'envelope'     => bin2hex(hash_hmac('sha256', $forged, (string) SigningKey::resolve(), true)),
        ]);

        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_a'));
    }

    // ─── Signatures do not affect each other (§5) ───

    /**
     * The rule the design turns on, checked against stored rows rather than in
     * the abstract: B signing later must leave A alone.
     */
    public function testASecondSignatureLeavesTheFirstValid(): void
    {
        $this->recordSignature('ttd_a');
        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));

        $this->recordSignature('ttd_b');

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));
        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_b'));
    }

    /** Swapping one slot's mark must not implicate the other signer. */
    public function testSwappingOneMarkBreaksOnlyThatSignature(): void
    {
        $this->recordSignature('ttd_a');
        $this->recordSignature('ttd_b');

        $this->rewriteDocument(null, ['ttd_a' => 'gambar-a', 'ttd_b' => 'gambar-b-palsu']);

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));
        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_b'));
    }

    // ─── States the save path would not produce ───

    public function testARevokedSignatureReportsRevokedRegardlessOfHashes(): void
    {
        $this->recordSignature('ttd_a', ['verify_status' => 'revoked']);

        self::assertSame(DocumentSignatureVerifier::STATUS_REVOKED, $this->statusOf('ttd_a'));
    }

    /**
     * A signature recorded under a payload shape this version cannot rebuild
     * must say so, not guess. Guessing would report a false verdict either way.
     */
    public function testAnUnknownAlgorithmReportsUnverifiable(): void
    {
        $this->recordSignature('ttd_a', ['content_hash_algo' => 'sha256-something-else']);

        self::assertSame(DocumentSignatureVerifier::STATUS_UNVERIFIABLE, $this->statusOf('ttd_a'));
    }

    public function testANonHmacEnvelopeReportsUnverifiable(): void
    {
        $this->recordSignature('ttd_a', ['envelope_format' => 'pkcs7']);

        self::assertSame(DocumentSignatureVerifier::STATUS_UNVERIFIABLE, $this->statusOf('ttd_a'));
    }

    // ─── Scope ───

    public function testAllSignaturesAreReturnedWhenNoSlotIsNamed(): void
    {
        $this->recordSignature('ttd_a');
        $this->recordSignature('ttd_b');

        self::assertCount(2, $this->verifier->verify($this->docId));
    }

    /** What a per-signature QR asks for: this slot, not the whole document. */
    public function testNamingASlotReturnsOnlyThatOne(): void
    {
        $this->recordSignature('ttd_a');
        $this->recordSignature('ttd_b');

        $verdicts = $this->verifier->verify($this->docId, 'ttd_b');

        self::assertCount(1, $verdicts);
        self::assertSame('ttd_b', $verdicts[0]['ttd_id']);
    }

    public function testAnUnknownSlotReturnsNothing(): void
    {
        $this->recordSignature('ttd_a');

        self::assertSame([], $this->verifier->verify($this->docId, 'ttd_tidak_ada'));
    }

    public function testAnUnsignedDocumentReturnsNothing(): void
    {
        self::assertSame([], $this->verifier->verify($this->docId));
    }

    public function testAMissingDocumentReturnsNothing(): void
    {
        self::assertSame([], $this->verifier->verify(0));
        self::assertSame([], $this->verifier->verify(999999999));
    }

    // ─── Document-level summary ───

    /**
     * A document is not "valid" because most of its signatures are. The worst
     * verdict has to win, or a summary line hides the one that matters.
     */
    public function testTheSummaryTakesTheWorstVerdict(): void
    {
        self::assertSame('unsigned', DocumentSignatureVerifier::summarise([]));

        self::assertSame('valid', DocumentSignatureVerifier::summarise([
            ['status' => 'valid'],
            ['status' => 'valid'],
        ]));

        self::assertSame('tampered', DocumentSignatureVerifier::summarise([
            ['status' => 'valid'],
            ['status' => 'tampered'],
        ]));

        self::assertSame('untrusted', DocumentSignatureVerifier::summarise([
            ['status' => 'tampered'],
            ['status' => 'untrusted'],
        ]), 'untrusted outranks tampered — one is a revision, the other wants investigating');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Two signers on one document
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The case that could not happen before.
     *
     * A signs. B then fills in the name printed under *their own* signature and
     * signs. Under v1 that field was covered by A's signature too, so A turned
     * `tampered` — punished for a colleague doing their job. On a three-signer
     * document nobody past the first could ever complete it.
     */
    public function testASecondSignerDoesNotTamperWithTheFirst(): void
    {
        $this->rewriteDocument(['nama' => 'Budi', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => '']);

        $coveredA = ['nama', 'nama_ttd_a'];   // milik B (pernyataan_oleh) tidak ikut
        $this->recordSignature('ttd_a', ['covered' => $coveredA]);

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));

        // B mengisi namanya sendiri lalu menandatangani.
        $this->rewriteDocument(['nama' => 'Budi', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => 'Siti']);
        $this->recordSignature('ttd_b', ['covered' => ['nama', 'pernyataan_oleh']]);

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'),
            "the first signature must survive the second signer filling in their own name");
        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_b'));
    }

    /** Shared content still binds both of them. */
    public function testEditingSharedContentBreaksBothSignatures(): void
    {
        $this->rewriteDocument(['nama' => 'Budi', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => 'Siti']);
        $this->recordSignature('ttd_a', ['covered' => ['nama', 'nama_ttd_a']]);
        $this->recordSignature('ttd_b', ['covered' => ['nama', 'pernyataan_oleh']]);

        $this->rewriteDocument(['nama' => 'ORANG LAIN', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => 'Siti']);

        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_a'));
        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_b'));
    }

    /**
     * Shrinking the recorded coverage must not quietly narrow what is checked.
     * The list is inside the hashed payload, so editing the copy in metadata
     * changes the recomputed hash instead of being obeyed.
     */
    public function testEditingTheRecordedCoverageReadsAsTampering(): void
    {
        $this->rewriteDocument(['nama' => 'Budi', 'rahasia' => 'asli']);
        $this->recordSignature('ttd_a', ['covered' => ['nama', 'rahasia']]);
        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));

        // Penyerang membuang `rahasia` dari daftar cakupan supaya bisa mengubahnya.
        $stmt = $this->pdo->prepare('UPDATE ezdoc_signatures SET metadata = ? WHERE 1');
        $stmt->execute([(string) json_encode(['ttd_id' => 'ttd_a', 'covered_fields' => ['nama']])]);
        $this->rewriteDocument(['nama' => 'Budi', 'rahasia' => 'diubah']);

        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_a'),
            'narrowing the coverage must change the hash, not the answer');
    }

    /** v2 without its coverage list cannot be recomputed, and must say so. */
    public function testV2WithoutItsCoverageListIsUnverifiable(): void
    {
        $this->recordSignature('ttd_a', [
            'content_hash_algo' => SignaturePayload::ALGO,
            'metadata'          => ['ttd_id' => 'ttd_a'],
        ]);

        $v = $this->verifier->verify($this->docId, 'ttd_a')[0];
        self::assertSame(DocumentSignatureVerifier::STATUS_UNVERIFIABLE, $v['status']);
        self::assertStringContainsString('covered-field list', $v['reason']);
    }

    /** Signatures written under v1 keep verifying. Nothing was rewritten. */
    public function testV1SignaturesStillVerify(): void
    {
        $this->recordSignature('ttd_a'); // default: v1

        self::assertSame(DocumentSignatureVerifier::STATUS_VALID, $this->statusOf('ttd_a'));

        $this->rewriteDocument(['nama' => 'diubah']);
        self::assertSame(DocumentSignatureVerifier::STATUS_TAMPERED, $this->statusOf('ttd_a'));
    }

    /**
     * The name printed under the signature comes from the record, not the
     * document. That is the point: the document says what it says now, the
     * record says what was signed.
     */
    public function testTheSignerNameComesFromTheRecord(): void
    {
        $this->recordSignature('ttd_a', [
            'metadata' => [
                'ttd_id'      => 'ttd_a',
                'ttd_label'   => 'Kepala Unit',
                'signer_name' => 'dr. Budi Santoso',
            ],
        ]);

        $verdicts = $this->verifier->verify($this->docId);
        self::assertCount(1, $verdicts);
        self::assertSame('dr. Budi Santoso', $verdicts[0]['signer_name']);
        self::assertSame('valid', $verdicts[0]['status']);
    }

    /**
     * A signature recorded before the name was captured must read as *absent*,
     * not as an empty name. Rendering an empty string would claim the slot was
     * signed with no name printed — which is a different fact, and one the
     * record does not actually contain.
     */
    public function testAnUnrecordedSignerNameIsNullNotEmpty(): void
    {
        $this->recordSignature('ttd_a'); // metadata default: tanpa signer_name

        $verdicts = $this->verifier->verify($this->docId);
        self::assertArrayHasKey('signer_name', $verdicts[0]);
        self::assertNull($verdicts[0]['signer_name']);
    }

    /**
     * An explicitly empty name is also absent. Some slots print no name at all —
     * a stamp, an unnamed witness — and storing '' for those must not become a
     * blank line the reader mistakes for missing data.
     */
    public function testAnEmptySignerNameIsNormalisedToNull(): void
    {
        $this->recordSignature('ttd_a', [
            'metadata' => ['ttd_id' => 'ttd_a', 'ttd_label' => 'Saksi', 'signer_name' => ''],
        ]);

        self::assertNull($this->verifier->verify($this->docId)[0]['signer_name']);
    }

    /**
     * The name is inside `fields`, so the content hash already covers it.
     * Changing it in the document after signing must surface as tampering — and
     * the record must keep showing the name that was actually signed, so the two
     * can be compared.
     */
    public function testRenamingTheSignerAfterSigningIsTampering(): void
    {
        $this->fields['nama_ttd_a'] = 'dr. Budi Santoso';
        $this->rewriteDocument($this->fields);

        $this->recordSignature('ttd_a', [
            'metadata' => [
                'ttd_id'      => 'ttd_a',
                'ttd_label'   => 'Kepala Unit',
                'signer_name' => 'dr. Budi Santoso',
            ],
        ]);

        self::assertSame('valid', $this->verifier->verify($this->docId)[0]['status']);

        // Someone re-attributes the signature by editing the document.
        $this->fields['nama_ttd_a'] = 'dr. Siti Aminah';
        $this->rewriteDocument($this->fields);

        $after = $this->verifier->verify($this->docId)[0];
        self::assertSame('tampered', $after['status']);
        self::assertSame('dr. Budi Santoso', $after['signer_name'],
            'the record must still name who actually signed, not who the document now claims');
    }
}
