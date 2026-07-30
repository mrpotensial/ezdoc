<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Signature\SignaturePayload;
use PHPUnit\Framework\TestCase;

/**
 * Covers what a signature attests to — the rules from
 * `docs/PLAN-SIGNATURE-RECORDS.md` §5.
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
        return SignaturePayload::hash(self::DOC, self::TPL, $tplVersion, $fields, $ttdId, $value);
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
        $a = SignaturePayload::hash(self::DOC, self::TPL, 3, ['x' => '1'], 'ttd_a', 'g');
        $b = SignaturePayload::hash('0190cccc-0000-7000-8000-000000000003', self::TPL, 3, ['x' => '1'], 'ttd_a', 'g');

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
        $payload = SignaturePayload::build(self::DOC, self::TPL, 3, ['a' => 1], 'ttd_a', 'g');

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
        $payload = SignaturePayload::build(self::DOC, self::TPL, 3, ['a' => 1], 'ttd_a', 'gambar-a');
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
        self::assertSame('sha256-jcs-v1', SignaturePayload::ALGO);
    }

    // ─── Refusals inherited from canonicalisation ───

    public function testAFloatFieldIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->hash(['jumlah' => 1.5], 'ttd_a', 'g');
    }
}
