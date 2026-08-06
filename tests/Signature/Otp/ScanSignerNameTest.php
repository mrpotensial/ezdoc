<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature\Otp;

use PHPUnit\Framework\TestCase;

/**
 * `ezdoc_scan_signer_name()` — which field holds the name of the person being
 * asked to sign this slot.
 *
 * This looks cosmetic and is not. The name goes into the consent message, the
 * consent message is stored verbatim as the evidence of what the signer agreed
 * to, and a message naming the wrong person is a consent record for someone who
 * never consented. An absent name is recoverable; a confidently wrong one is
 * not.
 *
 * So the rule is the same one the template parser and the document writer use:
 * the slot declares its field via `data-nama-field`, and the only fallback is
 * the documented default `nama_<ttd_id>`. Nothing is guessed from common words.
 */
final class ScanSignerNameTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/lib/scan_otp.php';
    }

    /**
     * The production failure, verbatim: a document whose slot declared
     * `pernyataan_oleh` reported the value of an unrelated field called `nama`,
     * because the resolver searched by convention instead of reading the
     * declaration.
     */
    public function testTheDeclaredFieldWinsOverAGenericOne(): void
    {
        $values = json_encode([
            'nama'            => 'Budi',      // belongs to some other part of the form
            'pernyataan_oleh' => 'Siti',
        ]);

        self::assertSame(
            'Siti',
            ezdoc_scan_signer_name((string) $values, 'pernyataan_oleh', 'ttd_1776221449464')
        );
    }

    /**
     * One document, two signers. Guessing cannot tell them apart — which is
     * exactly why the slot declares its own field.
     */
    public function testEachSlotResolvesItsOwnSigner(): void
    {
        $values = (string) json_encode([
            'nama_pasien' => 'Siti',
            'nama_saksi'  => 'Budi',
        ]);

        self::assertSame('Siti', ezdoc_scan_signer_name($values, 'nama_pasien', 'ttd_a'));
        self::assertSame('Budi', ezdoc_scan_signer_name($values, 'nama_saksi', 'ttd_b'));
    }

    /** Slots saved before `data-nama-field` existed still resolve. */
    public function testFallsBackToTheDocumentedDefault(): void
    {
        $values = (string) json_encode(['nama_ttd_a' => 'Budi']);

        self::assertSame('Budi', ezdoc_scan_signer_name($values, '', 'ttd_a'));
    }

    /**
     * The regression guard. `nama` is present and readable; returning it would
     * name a person who is not the signer, so nothing is returned at all.
     */
    public function testAnUnrelatedFieldIsNeverSubstituted(): void
    {
        $values = (string) json_encode([
            'nama'        => 'Budi',
            'nama_pasien' => 'Siti',
        ]);

        self::assertSame('', ezdoc_scan_signer_name($values, 'nama_dokter', 'ttd_dokter'));
    }

    /**
     * The declared field exists but is blank — the operator has not filled it in
     * yet. Falling through to `nama_<ttd_id>` is fine; both name the same slot.
     */
    public function testABlankValueFallsThroughToTheDefaultField(): void
    {
        $values = (string) json_encode([
            'pernyataan_oleh' => '   ',
            'nama_ttd_a'      => 'Siti',
        ]);

        self::assertSame('Siti', ezdoc_scan_signer_name($values, 'pernyataan_oleh', 'ttd_a'));
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $values = (string) json_encode(['nama_pasien' => "  Siti Aminah \n"]);

        self::assertSame('Siti Aminah', ezdoc_scan_signer_name($values, 'nama_pasien', 'ttd_a'));
    }

    /**
     * This runs inside a webhook. Unreadable stored values must produce a
     * nameless message, not an exception that makes the chat provider retry and
     * mint a second code.
     */
    public function testUnusableInputIsNamelessNotFatal(): void
    {
        foreach ([
            '',
            'not json',
            '[]',
            '"a string"',
            'null',
            (string) json_encode(['nama_pasien' => ['a', 'b']]),   // non-scalar
            (string) json_encode(['nama_pasien' => null]),
        ] as $input) {
            self::assertSame(
                '',
                ezdoc_scan_signer_name($input, 'nama_pasien', 'ttd_a'),
                "input '{$input}' should resolve to no name"
            );
        }
    }
}
