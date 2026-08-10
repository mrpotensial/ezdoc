<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Signature\Envelope\PkcsSevenEnvelope;
use Ezdoc\Signature\KeyStore\KeyStore;
use Ezdoc\Signature\Pdf\IncrementalPdfSigner;

/**
 * Menyegel artefak dengan sertifikat yang dipegang sendiri.
 *
 * Inilah yang membuat berkas keluaran bisa dibuktikan **tanpa PSrE**. Buka
 * hasilnya di pembaca PDF mana pun, ubah satu byte, dan ia langsung melaporkan
 * dokumen sudah diubah. Yang belum dijawabnya cuma satu: apakah nama di
 * sertifikat itu benar-benar siapa yang ia sebut — Adobe menulis *"Validity
 * unknown"* sampai penerbitnya dipercaya. Itu yang ditambahkan PSrE nanti,
 * menumpuk di atas segel ini.
 *
 * ## Tiga langkah, dan kenapa harus urut begitu
 *
 *   1. `prepare()` menyusun PDF beserta lubang kosong dan `/ByteRange`
 *   2. ringkasan byte tercakup ditandatangani jadi CMS terpisah
 *   3. `inject()` menaruh CMS-nya ke lubang tadi
 *
 * Ringkasannya yang ditandatangani, **bukan dokumennya**. Itu bukan penghematan:
 * begitu penandatanganan pindah ke HSM atau API PSrE, mengirim dokumen berarti
 * mengirim isinya ke pihak luar. Bentuk ini sudah benar sejak sekarang, jadi
 * tidak ada yang perlu dirombak saat itu tiba.
 *
 * PHP 7.4+ compatible.
 */
final class PkiArtifactSigner implements ArtifactSigner
{
    public const NAME = 'local_pki';

    /** @var KeyStore */
    private $keyStore;

    /** @var string */
    private $alias;

    /** @var IncrementalPdfSigner */
    private $pdfSigner;

    /** @var int */
    private $reservedSize;

    public function __construct(
        KeyStore $keyStore,
        string $alias,
        ?IncrementalPdfSigner $pdfSigner = null,
        int $reservedSize = IncrementalPdfSigner::DEFAULT_RESERVED_SIZE
    ) {
        if ($alias === '') {
            throw new EzdocException('PkiArtifactSigner butuh alias kunci.');
        }

        $this->keyStore = $keyStore;
        $this->alias = $alias;
        $this->pdfSigner = $pdfSigner ?? new IncrementalPdfSigner();
        $this->reservedSize = $reservedSize;
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function sign(string $pdfBytes, array $context = []): string
    {
        $cert = $this->keyStore->loadCertificate($this->alias);
        $key = $this->keyStore->loadPrivateKey($this->alias);

        $prepared = $this->pdfSigner->prepare($pdfBytes, [
            'reserved_size' => $this->reservedSize,
            'field_name'    => 'EzdocSignature1',
            'name'          => self::text($context, 'name') ?? $cert->getSubjectCN(),
            'reason'        => self::text($context, 'reason'),
            'location'      => self::text($context, 'location'),
        ]);

        // Terpisah (*detached*): CMS memuat tanda tangan dan sertifikatnya, bukan
        // isinya. Isinya adalah PDF itu sendiri, dan menaruh salinannya di dalam
        // berkas yang sama akan melipatgandakan ukurannya tanpa menambah bukti
        // apa pun.
        $envelope = new PkcsSevenEnvelope('', true);

        $cms = $envelope->pack('', $prepared->signedBytes(), $cert, ['private_key' => $key]);

        if ($cms === '') {
            throw new EzdocException('Penyegelan menghasilkan CMS kosong.');
        }

        return $prepared->inject($cms);
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function text(array $context, string $key): ?string
    {
        if (!isset($context[$key]) || !is_scalar($context[$key])) {
            return null;
        }

        $value = trim((string) $context[$key]);

        return $value === '' ? null : $value;
    }
}
