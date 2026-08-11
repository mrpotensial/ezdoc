<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\SignaturePayload;
use PHPUnit\Framework\TestCase;

/**
 * Covers what a signature attests to — the rules from
 * `docs/260730-catatan-tanda-tangan.md` §5.
 *
 * Every test here is one row of the consequence table in that section. They are
 * written as pairs — change one thing, assert the hash moves or does not — since
 * what matters is not the digest itself but which changes it responds to.
 */
final class SignaturePayloadTest extends TestCase
{
    private const DOC = '0190aaaa-0000-7000-8000-000000000001';
    private const TPL = '0190bbbb-0000-7000-8000-000000000002';

    /**
     * @param array<string,mixed> $fields
     */
    private function hash(array $fields, string $ttdId, string $value, int $tplVersion = 3): string
    {
        return SignaturePayload::hashV1(self::DOC, self::TPL, $tplVersion, $fields, $ttdId, $value);
    }

    // ─── What must NOT break a signature ───

    /**
     * The rule the whole design turns on. If a signature covered every slot,
     * the second signer would break the first one's signature by doing nothing
     * except signing after them.
     */
    public function testAnotherSlotSigningDoesNotAffectThisSignature(): void
    {
        $fields = ['nama' => 'Budi', 'nik' => '123'];

        // Slot A's hash never sees slot B, so B's value cannot enter it.
        $beforeB = $this->hash($fields, 'ttd_a', 'gambar-a');
        $afterB = $this->hash($fields, 'ttd_a', 'gambar-a');

        self::assertSame($beforeB, $afterB);
    }

    public function testAnotherSlotsMarkBeingSwappedDoesNotAffectThisSignature(): void
    {
        $fields = ['nama' => 'Budi'];

        self::assertSame(
            $this->hash($fields, 'ttd_a', 'gambar-a'),
            $this->hash($fields, 'ttd_a', 'gambar-a'),
            "slot B's value is not part of slot A's payload at all"
        );
    }

    /** Field order is a storage detail, not part of the statement. */
    public function testFieldOrderDoesNotAffectTheSignature(): void
    {
        self::assertSame(
            $this->hash(['nama' => 'Budi', 'nik' => '123'], 'ttd_a', 'g'),
            $this->hash(['nik' => '123', 'nama' => 'Budi'], 'ttd_a', 'g')
        );
    }

    public function testNestedFieldOrderDoesNotAffectTheSignature(): void
    {
        self::assertSame(
            $this->hash(['tabel' => ['b' => 2, 'a' => 1]], 'ttd_a', 'g'),
            $this->hash(['tabel' => ['a' => 1, 'b' => 2]], 'ttd_a', 'g')
        );
    }

    // ─── What must break it ───

    /** The statement changed, so the signature over it no longer holds. */
    public function testEditingAFieldBreaksTheSignature(): void
    {
        self::assertNotSame(
            $this->hash(['nominal' => '1000000'], 'ttd_a', 'g'),
            $this->hash(['nominal' => '9000000'], 'ttd_a', 'g')
        );
    }

    public function testAddingAFieldBreaksTheSignature(): void
    {
        self::assertNotSame(
            $this->hash(['nama' => 'Budi'], 'ttd_a', 'g'),
            $this->hash(['nama' => 'Budi', 'catatan' => 'tambahan'], 'ttd_a', 'g')
        );
    }

    public function testRemovingAFieldBreaksTheSignature(): void
    {
        self::assertNotSame(
            $this->hash(['nama' => 'Budi', 'nik' => '123'], 'ttd_a', 'g'),
            $this->hash(['nama' => 'Budi'], 'ttd_a', 'g')
        );
    }

    public function testSwappingThisSlotsMarkBreaksTheSignature(): void
    {
        self::assertNotSame(
            $this->hash(['nama' => 'Budi'], 'ttd_a', 'gambar-asli'),
            $this->hash(['nama' => 'Budi'], 'ttd_a', 'gambar-palsu')
        );
    }

    /**
     * Two slots signed with the same mark must not produce the same hash, or a
     * signature could be lifted from one slot and replayed into another.
     */
    public function testTheSameMarkInADifferentSlotHashesDifferently(): void
    {
        self::assertNotSame(
            $this->hash(['nama' => 'Budi'], 'ttd_a', 'gambar'),
            $this->hash(['nama' => 'Budi'], 'ttd_b', 'gambar')
        );
    }

    /**
     * Identity is in the payload so a signature cannot be moved to another
     * document that happens to hold the same values.
     */
    public function testADifferentDocumentHashesDifferently(): void
    {
        $a = SignaturePayload::hashV1(self::DOC, self::TPL, 3, ['x' => '1'], 'ttd_a', 'g');
        $b = SignaturePayload::hashV1('0190cccc-0000-7000-8000-000000000003', self::TPL, 3, ['x' => '1'], 'ttd_a', 'g');

        self::assertNotSame($a, $b);
    }

    public function testADifferentTemplateVersionHashesDifferently(): void
    {
        self::assertNotSame(
            $this->hash(['x' => '1'], 'ttd_a', 'g', 3),
            $this->hash(['x' => '1'], 'ttd_a', 'g', 4)
        );
    }

    // ─── Shape ───

    public function testPayloadCarriesExactlyTheAgreedKeys(): void
    {
        $payload = SignaturePayload::buildV1(self::DOC, self::TPL, 3, ['a' => 1], 'ttd_a', 'g');

        self::assertSame(
            ['document_uuid', 'template_uuid', 'template_version', 'fields', 'ttd_id', 'ttd_value'],
            array_keys($payload)
        );
    }

    /**
     * No key names another slot's data. Asserted structurally so a future edit
     * that widens the payload has to change this test on purpose.
     */
    public function testPayloadContainsNoOtherSlotsData(): void
    {
        $payload = SignaturePayload::buildV1(self::DOC, self::TPL, 3, ['a' => 1], 'ttd_a', 'gambar-a');
        $encoded = (string) json_encode($payload);

        self::assertStringNotContainsString('signature_values', $encoded);
        self::assertStringNotContainsString('ttd_b', $encoded);
    }

    public function testHashIsSha256Hex(): void
    {
        $hash = $this->hash(['a' => '1'], 'ttd_a', 'g');

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * The algorithm label is stored with each signature so a verifier knows how
     * to recompute. Widening the payload without changing it would make old
     * signatures unverifiable with no clue why.
     */
    public function testTheAlgorithmLabelNamesBothShapeAndCanonicalisation(): void
    {
        self::assertSame('sha256-jcs-v1', SignaturePayload::ALGO_V1);
        self::assertSame('sha256-jcs-v2', SignaturePayload::ALGO);
        self::assertNotSame(SignaturePayload::ALGO, SignaturePayload::ALGO_V1,
            'a new payload shape must get a new label, or old signatures silently stop verifying');
    }

    // ─── Refusals inherited from canonicalisation ───

    public function testAFloatFieldIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->hash(['jumlah' => 1.5], 'ttd_a', 'g');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // v2 — scoping a signature to what it actually attests to
    // ═══════════════════════════════════════════════════════════════════════
    //
    // v1 covered every field in the document, and that made a second signer
    // impossible: filling in the name under their own signature changed a field
    // the first signature covered, so the first signature reported the document
    // as altered — and the save guard refused before it even got that far.
    //
    // PDF answers this with incremental updates: a later signature does not
    // invalidate an earlier one. v2 is the same idea for a field-based document —
    // a signature covers the content plus its own slot, and not the fields that
    // belong to other people's slots.

    /** @param array<string,mixed> $fields @param list<string> $covered */
    private function hashV2(array $fields, string $ttdId, string $value, array $covered): string
    {
        return SignaturePayload::hash(self::DOC, self::TPL, 3, $fields, $ttdId, $value, $covered);
    }

    /** Two slots, each owning the name printed beneath it. */
    private const SLOTS = [
        'ttd_a' => ['nama_ttd_a'],
        'ttd_b' => ['pernyataan_oleh'],
    ];

    public function testASignatureDoesNotCoverAnotherSlotsName(): void
    {
        $keys = ['isi', 'nama_ttd_a', 'pernyataan_oleh'];

        $covered = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        self::assertContains('isi', $covered);
        self::assertContains('nama_ttd_a', $covered, 'the signer attests to their own printed name');
        self::assertNotContains('pernyataan_oleh', $covered, "another slot's name is not this signature's business");
    }

    public function testASignatureDoesNotCoverAnotherSlotsQrContent(): void
    {
        $keys = ['nama_ttd_a', 'nama_ttd_a_qr', 'pernyataan_oleh', 'pernyataan_oleh_qr'];

        $covered = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        self::assertContains('nama_ttd_a_qr', $covered);
        self::assertNotContains('pernyataan_oleh_qr', $covered);
    }

    /**
     * Display mode is left out for every slot, this one included. Whether a
     * signature renders as a drawn image or as a QR does not change who signed
     * what — the same line PAdES draws around the appearance stream.
     */
    public function testDisplayModeIsNeverCovered(): void
    {
        $keys = ['isi', '_ttd_mode_ttd_a', '_ttd_mode_ttd_b'];

        $covered = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        self::assertSame(['isi'], $covered);
    }

    /**
     * The scenario that could not happen before: two signers, one document.
     *
     * A signs. B then fills in their own name and signs. A's hash must still
     * recompute to the same value — otherwise A's signature reports tampering
     * for nothing but B doing their job.
     */
    public function testASecondSignerDoesNotBreakTheFirstSignature(): void
    {
        $keys = ['isi', 'nama_ttd_a', 'pernyataan_oleh'];
        $coveredA = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        $beforeB = ['isi' => 'pernyataan', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => ''];
        $hashAtSigning = $this->hashV2($beforeB, 'ttd_a', 'gambar-a', $coveredA);

        // B fills in their name and signs.
        $afterB = ['isi' => 'pernyataan', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => 'Siti'];

        self::assertSame(
            $hashAtSigning,
            $this->hashV2($afterB, 'ttd_a', 'gambar-a', $coveredA),
            "the first signature must survive the second signer filling in their own name"
        );
    }

    /** Editing the actual content still breaks it. That part must not have loosened. */
    public function testEditingCoveredContentStillBreaksTheSignature(): void
    {
        $keys = ['isi', 'nama_ttd_a', 'pernyataan_oleh'];
        $covered = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        $before = ['isi' => 'pernyataan', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => ''];
        $after  = ['isi' => 'pernyataan LAIN', 'nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => ''];

        self::assertNotSame(
            $this->hashV2($before, 'ttd_a', 'g', $covered),
            $this->hashV2($after, 'ttd_a', 'g', $covered)
        );
    }

    /** Re-attributing the signer is still caught — their own name stays covered. */
    public function testRenamingTheOwnSignerStillBreaksTheSignature(): void
    {
        $keys = ['nama_ttd_a', 'pernyataan_oleh'];
        $covered = SignaturePayload::coveredKeysFor('ttd_a', $keys, self::SLOTS);

        self::assertNotSame(
            $this->hashV2(['nama_ttd_a' => 'dr. Budi', 'pernyataan_oleh' => ''], 'ttd_a', 'g', $covered),
            $this->hashV2(['nama_ttd_a' => 'dr. Siti', 'pernyataan_oleh' => ''], 'ttd_a', 'g', $covered)
        );
    }

    /**
     * Deleting a covered field must break the hash. Skipping absent keys instead
     * of recording them as absent would let removal pass unnoticed.
     */
    public function testDeletingACoveredFieldBreaksTheSignature(): void
    {
        $covered = ['isi', 'nama_ttd_a'];

        self::assertNotSame(
            $this->hashV2(['isi' => 'x', 'nama_ttd_a' => 'B'], 'ttd_a', 'g', $covered),
            $this->hashV2(['nama_ttd_a' => 'B'], 'ttd_a', 'g', $covered)
        );
    }

    /**
     * A field added after signing does not break anything — the signature never
     * covered it. This is what lets a template gain a column without invalidating
     * every document already signed under it.
     */
    public function testAFieldAddedAfterSigningIsIgnored(): void
    {
        $covered = ['isi'];

        self::assertSame(
            $this->hashV2(['isi' => 'x'], 'ttd_a', 'g', $covered),
            $this->hashV2(['isi' => 'x', 'baru' => 'y'], 'ttd_a', 'g', $covered)
        );
    }

    /**
     * The covered list is part of what is hashed. If it lived only beside the
     * signature, anyone able to edit it could shrink the covered set and have the
     * signature keep verifying over less and less.
     */
    public function testShrinkingTheCoveredListChangesTheHash(): void
    {
        $fields = ['isi' => 'x', 'nama_ttd_a' => 'B'];

        self::assertNotSame(
            $this->hashV2($fields, 'ttd_a', 'g', ['isi', 'nama_ttd_a']),
            $this->hashV2($fields, 'ttd_a', 'g', ['isi'])
        );
    }

    public function testTheCoveredListIsInThePayload(): void
    {
        $payload = SignaturePayload::build(self::DOC, self::TPL, 3, ['b' => 2, 'a' => 1], 'ttd_a', 'g', ['a', 'b']);

        self::assertSame(['a', 'b'], $payload['covered_fields']);
        self::assertSame(['a' => 1, 'b' => 2], $payload['fields']);
    }

    /**
     * Key order must not change the hash. The caller builds the list by iterating
     * a map, and PHP iterates in insertion order — so the same coverage collected
     * in a different order would otherwise hash differently.
     */
    public function testCoveredKeyOrderDoesNotChangeTheHash(): void
    {
        $fields = ['a' => 1, 'b' => 2];

        self::assertSame(
            $this->hashV2($fields, 'ttd_a', 'g', ['a', 'b']),
            $this->hashV2($fields, 'ttd_a', 'g', ['b', 'a'])
        );
    }

    public function testDuplicateCoveredKeysAreCollapsed(): void
    {
        $fields = ['a' => 1];

        self::assertSame(
            $this->hashV2($fields, 'ttd_a', 'g', ['a']),
            $this->hashV2($fields, 'ttd_a', 'g', ['a', 'a', 'a'])
        );
    }

    /** v1 and v2 must not collide — different shapes, different hashes. */
    public function testV1AndV2HashDifferently(): void
    {
        $fields = ['a' => 1];

        self::assertNotSame(
            SignaturePayload::hashV1(self::DOC, self::TPL, 3, $fields, 'ttd_a', 'g'),
            $this->hashV2($fields, 'ttd_a', 'g', ['a'])
        );
    }
}
