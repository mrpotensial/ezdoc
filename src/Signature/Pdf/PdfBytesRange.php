<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Pdf;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Signature\Pdf\PdfBytesRange — utility parse & compute PDF /ByteRange.
 *
 * ## /ByteRange di PDF
 *
 * PDF signature dictionary carry key /ByteRange yang berbentuk array 4 int:
 *
 *   /ByteRange [ start1 length1 start2 length2 ]
 *
 * Dua range ini menutupi SELURUH file kecuali blok /Contents. Hash yang
 * di-sign adalah concat(bytes[start1..start1+length1], bytes[start2..start2+length2]).
 *
 * - start1 = 0
 * - start1+length1 = offset karakter '<' pembuka /Contents hex string
 * - start2 = offset karakter '>' penutup /Contents + 1
 * - start2+length2 = EOF (panjang file)
 *
 * ## Kenapa helper terpisah?
 *
 * - Parsing /ByteRange butuh regex over bytes PDF (bukan tugas envelope
 *   atau signer secara langsung).
 * - Verifier butuh compute hash dari /ByteRange tanpa harus load full PDF
 *   parser (TCPDF / FPDI).
 * - Test coverage: unit test pure — no filesystem, no OpenSSL dependency.
 *
 * ## Batas & keamanan
 *
 * - Format /ByteRange yang sah pakai spasi tunggal sebagai separator, tapi
 *   PDF spec membolehkan whitespace lain. Regex di sini toleran terhadap
 *   spaces / newlines multiple.
 * - `/ByteRange` HARUS meng-cover byte 0..EOF, dengan gap hanya di antara
 *   `< ... >` /Contents. Kalau gap ganda / partial → verifier harus reject
 *   (potential partial-signing attack). Method `isFullCoverage()`
 *   mengecek pola ini secara struktural.
 *
 * PHP 7.4+ compatible.
 */
final class PdfBytesRange
{
    /** @var int */
    private $start1;

    /** @var int */
    private $length1;

    /** @var int */
    private $start2;

    /** @var int */
    private $length2;

    /**
     * @param array<int,int> $range 4-element array of ints [s1, l1, s2, l2]
     * @throws ValidationException
     */
    public function __construct(array $range)
    {
        if (count($range) !== 4) {
            throw ValidationException::forField('range', 'must be 4-element array [s1, l1, s2, l2]');
        }
        // Reindex agar tolerant terhadap non-zero-indexed input.
        $r = array_values($range);
        foreach ($r as $i => $v) {
            if (!is_int($v) && !ctype_digit((string) $v)) {
                throw ValidationException::forField('range[' . $i . ']', 'must be integer');
            }
        }
        $s1 = (int) $r[0];
        $l1 = (int) $r[1];
        $s2 = (int) $r[2];
        $l2 = (int) $r[3];
        if ($s1 < 0 || $l1 < 0 || $s2 < 0 || $l2 < 0) {
            throw ValidationException::forField('range', 'all offsets must be non-negative');
        }
        if ($s2 < $s1 + $l1) {
            throw ValidationException::forField(
                'range',
                'range2 overlaps range1: s2 < s1+l1'
            );
        }
        $this->start1 = $s1;
        $this->length1 = $l1;
        $this->start2 = $s2;
        $this->length2 = $l2;
    }

    /**
     * @return array<int,int>
     */
    public function toArray(): array
    {
        return [$this->start1, $this->length1, $this->start2, $this->length2];
    }

    public function getStart1(): int
    {
        return $this->start1;
    }

    public function getLength1(): int
    {
        return $this->length1;
    }

    public function getStart2(): int
    {
        return $this->start2;
    }

    public function getLength2(): int
    {
        return $this->length2;
    }

    /**
     * Byte offset karakter '<' pembuka /Contents hex blob = end of range1.
     */
    public function getSignatureOffset(): int
    {
        return $this->start1 + $this->length1;
    }

    /**
     * Panjang reserved bytes /Contents (termasuk '<' dan '>').
     * = start2 - end_of_range1
     */
    public function getSignatureLength(): int
    {
        return $this->start2 - $this->getSignatureOffset();
    }

    /**
     * True kalau /ByteRange menutupi FULL file dengan single gap di /Contents.
     * Signature partial coverage (mis. range1 covers header saja, EOF diluar)
     * bisa jadi indikasi tampering.
     *
     * @param int $fileSize panjang file PDF
     */
    public function isFullCoverage(int $fileSize): bool
    {
        if ($this->start1 !== 0) {
            return false;
        }
        if ($this->start2 + $this->length2 !== $fileSize) {
            return false;
        }
        return true;
    }

    /**
     * Extract bytes yang dicover /ByteRange (hasil concat dua slice).
     * Consumer kemudian bisa hash bytes ini untuk verifikasi.
     *
     * @throws EzdocException kalau range out-of-bounds
     */
    public function computeHashedContent(string $pdfBytes): string
    {
        $len = strlen($pdfBytes);
        if ($this->start1 + $this->length1 > $len) {
            throw new EzdocException('PdfBytesRange: range1 out of bounds (file too short)');
        }
        if ($this->start2 + $this->length2 > $len) {
            throw new EzdocException('PdfBytesRange: range2 out of bounds (file too short)');
        }
        return substr($pdfBytes, $this->start1, $this->length1)
            . substr($pdfBytes, $this->start2, $this->length2);
    }

    /**
     * Convenience: compute digest bytes langsung.
     *
     * @param string $pdfBytes
     * @param self   $byteRange
     * @param string $algo   'sha256' | 'sha384' | 'sha512'
     * @return string raw binary hash
     * @throws EzdocException
     */
    public static function computeHash(string $pdfBytes, self $byteRange, string $algo = 'sha256'): string
    {
        $allowed = ['sha256', 'sha384', 'sha512'];
        if (!in_array($algo, $allowed, true)) {
            throw new EzdocException('PdfBytesRange::computeHash unsupported algo: ' . $algo);
        }
        $content = $byteRange->computeHashedContent($pdfBytes);
        $hash = hash($algo, $content, true);
        if ($hash === false || $hash === '') {
            throw new EzdocException('PdfBytesRange::computeHash: hash() returned empty');
        }
        return $hash;
    }

    /**
     * Factory: parse /ByteRange dari PDF bytes.
     *
     * Cari pola `/ByteRange [ N N N N ]`. PDF spec membolehkan whitespace
     * bebas; regex ini tolerant terhadap spasi ganda / newline.
     *
     * Kalau PDF punya lebih dari satu signature (mis. multi-sig), method
     * ini mengembalikan MATCH PERTAMA. Untuk multi-sig extraction, gunakan
     * `findAll()`.
     *
     * @throws EzdocException kalau tidak ditemukan
     */
    public static function fromPdf(string $pdfBytes): self
    {
        $all = self::findAll($pdfBytes);
        if (empty($all)) {
            throw new EzdocException('PdfBytesRange::fromPdf: /ByteRange not found in PDF');
        }
        return $all[0];
    }

    /**
     * Semua /ByteRange di PDF (untuk multi-signature scenario).
     *
     * @return array<int, self>
     */
    public static function findAll(string $pdfBytes): array
    {
        // Pola: /ByteRange [ s1 l1 s2 l2 ]
        // Tolerant: whitespace 1..* di antara elemen, boleh newline.
        $pattern = '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/';
        $out = [];
        if (preg_match_all($pattern, $pdfBytes, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $m) {
            try {
                $out[] = new self([(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]]);
            } catch (ValidationException $e) {
                // Skip malformed; continue
            }
        }
        return $out;
    }

    /**
     * Cek apakah PDF punya /ByteRange placeholder (semua nol) — indikasi
     * signing step 1 selesai tapi step 2 (patch offsets) belum jalan.
     */
    public function isPlaceholder(): bool
    {
        return $this->start1 === 0
            && $this->length1 === 0
            && $this->start2 === 0
            && $this->length2 === 0;
    }
}
