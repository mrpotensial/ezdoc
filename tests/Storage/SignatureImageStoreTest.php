<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Storage;

use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Storage\Blob;
use Ezdoc\Storage\DatabaseBlobStore;
use Ezdoc\Storage\SignatureImageStore;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rule that keeps existing signatures valid while new ones move out
 * of the document row.
 *
 * A signature's content hash includes the slot value. Re-encoding a stored inline
 * image into a reference would change that value and make a genuine signature
 * verify as `tampered` — the application invalidating its own evidence. And the
 * form re-posts every stored signature on every save, so without the rule the
 * first save after deploying would rewrite every slot in the document.
 */
final class SignatureImageStoreTest extends TestCase
{
    private \PDO $pdo;
    private SignatureImageStore $images;

    /** A tiny but genuine PNG, so content types and decoding are real. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec((string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/sqlite/2026_07_30_000001_create_ezdoc_blobs.sql'
        ));

        $this->images = new SignatureImageStore(new DatabaseBlobStore(new PdoConnection($this->pdo)));
    }

    private static function dataUri(string $b64 = self::PNG, string $type = 'png'): string
    {
        return 'data:image/' . $type . ';base64,' . $b64;
    }

    // ─── The rule ───

    /**
     * The case that matters most: a signed slot re-posting its stored value must
     * come back byte-identical. Anything else invalidates the signature.
     *
     * @dataProvider unchangedValues
     */
    public function testAnUnchangedValueIsReturnedVerbatim(string $value): void
    {
        self::assertSame($value, $this->images->valueToPersist($value, $value));
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM ezdoc_blobs')->fetchColumn(),
            'an unchanged re-post must not store anything'
        );
    }

    /** @return array<string, array{string}> */
    public static function unchangedValues(): array
    {
        return [
            'gambar inline lama' => [self::dataUri()],
            'penanda mode QR'    => ['qr:v1'],
            'referensi blob'     => ['blob:sha256-' . str_repeat('a', 64)],
            'kosong'             => [''],
        ];
    }

    public function testANewImageBecomesAReference(): void
    {
        $ref = $this->images->valueToPersist(self::dataUri(), '');

        self::assertTrue(Blob::isReference($ref));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ezdoc_blobs')->fetchColumn());
    }

    /** The reference must be the hash of the decoded bytes, not of the data URI. */
    public function testTheReferenceIsTheHashOfTheImageBytes(): void
    {
        $ref = $this->images->valueToPersist(self::dataUri(), '');

        self::assertSame(
            'blob:sha256-' . hash('sha256', (string) base64_decode(self::PNG, true)),
            $ref
        );
    }

    /**
     * Replacing a signature on an unsigned slot still converts — the rule is about
     * what is *stored*, not about whether anything was there before.
     */
    public function testReplacingAnImageConverts(): void
    {
        $first = $this->images->valueToPersist(self::dataUri(), '');
        $other = self::dataUri(base64_encode('gambar lain'));
        $second = $this->images->valueToPersist($other, $first);

        self::assertTrue(Blob::isReference($second));
        self::assertNotSame($first, $second);
    }

    /**
     * Values that are not images pass through untouched even when they differ —
     * the QR marker has nothing to store.
     */
    public function testNonImageValuesArePassedThrough(): void
    {
        self::assertSame('qr:v1', $this->images->valueToPersist('qr:v1', ''));
        self::assertSame('', $this->images->valueToPersist('', self::dataUri()));
    }

    // ─── store() ───

    public function testStoreRejectsWhatIsNotADataUri(): void
    {
        foreach (['', 'qr:v1', 'blob:sha256-x', 'data:text/plain;base64,AAAA', 'data:image/png,raw'] as $bad) {
            self::assertNull($this->images->store($bad), "accepted {$bad}");
        }
    }

    /**
     * Base64 is decoded strictly. Lenient decoding silently drops characters
     * outside the alphabet, which would store a subtly different image than the
     * one the operator drew.
     */
    public function testMalformedBase64IsRejected(): void
    {
        self::assertNull($this->images->store('data:image/png;base64,!!!!not base64!!!!'));
    }

    public function testJpgIsNormalisedToJpeg(): void
    {
        $ref = $this->images->store(self::dataUri(base64_encode('jpeg bytes'), 'jpg'));
        self::assertNotNull($ref);

        $type = $this->pdo->query('SELECT content_type FROM ezdoc_blobs')->fetchColumn();
        self::assertSame('image/jpeg', $type, 'jpg and jpeg must not become two content types');
    }

    // ─── resolve ───

    public function testALegacyInlineImageResolvesToItself(): void
    {
        $uri = self::dataUri();
        self::assertSame($uri, $this->images->resolveToDataUri($uri));
    }

    public function testAReferenceResolvesBackToTheSameDataUri(): void
    {
        $ref = $this->images->valueToPersist(self::dataUri(), '');

        self::assertSame(self::dataUri(), $this->images->resolveToDataUri($ref));
    }

    /**
     * A round trip must be lossless. If it were not, the printed document would
     * differ from what was signed.
     */
    public function testTheRoundTripPreservesTheBytesExactly(): void
    {
        $bytes = random_bytes(3000);
        $uri = 'data:image/png;base64,' . base64_encode($bytes);

        $resolved = $this->images->resolveToBytes($this->images->valueToPersist($uri, ''));

        self::assertNotNull($resolved);
        self::assertSame($bytes, $resolved['bytes']);
        self::assertSame('image/png', $resolved['content_type']);
    }

    /**
     * Nothing to render is null, not an empty string — callers must draw nothing
     * rather than emit an `<img>` with an empty src.
     *
     * @dataProvider unrenderable
     */
    public function testUnrenderableValuesResolveToNull(string $value): void
    {
        self::assertNull($this->images->resolveToDataUri($value));
        self::assertNull($this->images->resolveToBytes($value));
    }

    /** @return array<string, array{string}> */
    public static function unrenderable(): array
    {
        return [
            'kosong'             => [''],
            'penanda mode QR'    => ['qr:v1'],
            'teks biasa'         => ['123'],
            'referensi rusak'    => ['blob:sha256-bukanhex'],
            'objek tidak ada'    => ['blob:sha256-' . str_repeat('b', 64)],
        ];
    }

    /**
     * An object that has gone missing reads as nothing to render, not as an error
     * and not as a broken image. The document still opens; only the visual is
     * absent, and the cryptographic evidence for the signature is untouched.
     */
    public function testAMissingObjectIsNotAnError(): void
    {
        $ref = $this->images->valueToPersist(self::dataUri(), '');
        $this->pdo->exec('DELETE FROM ezdoc_blobs');

        self::assertNull($this->images->resolveToDataUri($ref));
    }

    /**
     * Identical images across documents are one object — the property content
     * addressing exists for.
     */
    public function testTheSameImageInTwoDocumentsIsStoredOnce(): void
    {
        $a = $this->images->valueToPersist(self::dataUri(), '');
        $b = $this->images->valueToPersist(self::dataUri(), '');

        self::assertSame($a, $b);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ezdoc_blobs')->fetchColumn());
    }
}
