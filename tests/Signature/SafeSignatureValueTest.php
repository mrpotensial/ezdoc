<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Signature\SignatureValue;
use Ezdoc\Storage\Blob;
use PHPUnit\Framework\TestCase;

/**
 * Covers the filter that decides which slot values survive a render.
 *
 * It began as `safeDataImg()` — accept a data URI, reject everything else — and
 * that was right while a signature slot could only ever hold an image. The
 * vocabulary then grew twice: `qr:v1` for signing in QR mode, and
 * `blob:sha256-…` once images moved out of the document row. The filter was not
 * revisited either time, so both new forms were stripped on their way to the
 * page.
 *
 * The two symptoms looked unrelated and shared one cause:
 *
 * 1. A QR signature "kept disappearing". `qr:v1` was stored, wiped on the next
 *    render, and the "sign with QR" button came back as if nothing had happened.
 * 2. The QR display toggle failed with "signature cannot be removed". Its hidden
 *    input had been emptied, so saving a *display* setting read as deleting a
 *    recorded signature.
 *
 * The second is the §7b guard doing its job. Without it, changing how a
 * signature is displayed would have destroyed the signature and left its record
 * pointing at nothing.
 *
 * Extraction runs the shipped function rather than a copy: this exact filter has
 * fallen behind the value vocabulary twice, and a copy would keep passing while
 * the real one kept dropping values.
 */
final class SafeSignatureValueTest extends TestCase
{
    private const VIEW = 'views/document/generate.php';

    private const PNG = 'data:image/png;base64,iVBORw0KGgo=';

    /**
     * Load the two filters out of the view.
     *
     * The view cannot be included — it renders a page and expects a request. The
     * functions are self-contained, so they are extracted and defined here under
     * their own names.
     */
    public static function setUpBeforeClass(): void
    {
        if (function_exists('safeSignatureValue')) {
            return;
        }

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::VIEW);

        foreach (['safeDataImg', 'safeSignatureValue'] as $fn) {
            $start = strpos($src, 'function ' . $fn . '(');
            self::assertNotFalse($start, "{$fn}() is gone from " . self::VIEW);

            $open = strpos($src, '{', $start);
            self::assertNotFalse($open);

            $depth = 0;
            $end = null;
            for ($i = $open, $n = strlen($src); $i < $n; $i++) {
                if ($src[$i] === '{') { $depth++; }
                if ($src[$i] === '}') {
                    $depth--;
                    if ($depth === 0) { $end = $i; break; }
                }
            }
            self::assertNotNull($end, "{$fn}() has no matching closing brace");

            eval(substr($src, $start, ($end - $start) + 1));
        }
    }

    // ─── What must survive ───

    /**
     * @dataProvider accepted
     */
    public function testRecognisedValuesPassThroughUnchanged(string $value): void
    {
        self::assertSame($value, safeSignatureValue($value));
    }

    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'gambar PNG'    => [self::PNG],
            'gambar JPEG'   => ['data:image/jpeg;base64,/9j/4AAQ'],
            'gambar JPG'    => ['data:image/jpg;base64,/9j/4AAQ'],
            'gambar GIF'    => ['data:image/gif;base64,R0lGOD'],
            'penanda QR'    => [SignatureValue::QR_ACT],
            'referensi blob' => [Blob::REF_PREFIX . str_repeat('a', 64)],
        ];
    }

    /**
     * The two that were being dropped, named individually so a failure says which
     * feature just broke rather than "a case failed".
     */
    public function testTheQrMarkerSurvives(): void
    {
        self::assertSame(
            'qr:v1',
            safeSignatureValue('qr:v1'),
            'dropping this makes QR-mode signatures vanish on every render'
        );
    }

    public function testABlobReferenceSurvives(): void
    {
        $ref = Blob::REF_PREFIX . str_repeat('f', 64);

        self::assertSame(
            $ref,
            safeSignatureValue($ref),
            'dropping this empties the hidden input, and the next save reads as deleting the signature'
        );
    }

    // ─── What must not ───

    /**
     * Still a whitelist. The value goes into an HTML attribute and into
     * `signature_values`; "anything non-empty" would put whatever anyone can POST
     * into both.
     *
     * @dataProvider rejected
     */
    public function testUnrecognisedValuesAreDropped(string $value): void
    {
        self::assertSame('', safeSignatureValue($value));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'kosong'              => [''],
            'spasi'               => ['   '],
            'teks biasa'          => ['123'],
            'javascript: URL'     => ['javascript:alert(1)'],
            'data URI HTML'       => ['data:text/html;base64,PHNjcmlwdD4='],
            'data URI SVG'        => ['data:image/svg+xml;base64,PHN2Zz4='],
            'URL biasa'           => ['https://contoh.test/gambar.png'],
            'penanda QR salah'    => ['qr:v2'],
            'penanda QR sebagian' => ['qr:'],
            'blob tanpa algo'     => ['blob:' . str_repeat('a', 64)],
            'blob kependekan'     => [Blob::REF_PREFIX . str_repeat('a', 63)],
            'blob bukan hex'      => [Blob::REF_PREFIX . str_repeat('z', 64)],
            'kutip lolos atribut' => ['data:image/png;base64,AAA" onerror="alert(1)'],
            'prefix di tengah'    => ['x data:image/png;base64,AAA'],
        ];
    }

    /**
     * SVG stays out. It can carry script, and an `<img src>` is not the place to
     * find that out.
     */
    public function testSvgIsNotAcceptedAsAnImage(): void
    {
        self::assertSame('', safeSignatureValue('data:image/svg+xml;base64,PHN2Zz4='));
    }

    // ─── The two filters are not interchangeable ───

    /**
     * `safeDataImg()` is still correct for what it is named after — putting a
     * value in an `<img src>`. It must keep rejecting the non-image forms, which
     * is exactly why using it to filter slot values was the bug.
     */
    public function testTheImageFilterStillRejectsNonImages(): void
    {
        self::assertSame('', safeDataImg('qr:v1'));
        self::assertSame('', safeDataImg(Blob::REF_PREFIX . str_repeat('a', 64)));
        self::assertSame(self::PNG, safeDataImg(self::PNG));
    }

    /**
     * The vocabulary is defined in one place. If a form is added to
     * SignatureValue or Blob and not here, this is where it should show up.
     */
    public function testEveryFormTheSaveGateAcceptsIsAlsoAcceptedHere(): void
    {
        // Mirrors the gate in actions/document/save_document.php.
        foreach ([self::PNG, SignatureValue::QR_ACT, Blob::REF_PREFIX . str_repeat('0', 64)] as $value) {
            self::assertNotSame('', safeSignatureValue($value),
                'the save path accepts ' . $value . ' but the render drops it — it will not survive a round trip');
        }
    }
}
