<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

/**
 * Translates between what a signature slot holds and what a browser renders.
 *
 * A slot value is now one of four things, and the set only grew — nothing was
 * taken away:
 *
 *   ''                           not signed
 *   'data:image/png;base64,…'    legacy inline image, still valid, still verified
 *   'qr:v1'                      signed in QR mode, no image
 *   'blob:sha256-…'              image stored as an object
 *
 * ## The rule that governs conversion
 *
 * A value that a recorded signature already covers must never change. The
 * signature's content hash includes `ttd_value`; re-encoding a stored image into
 * a reference would change that value and make a genuine signature verify as
 * `tampered` — the application invalidating its own evidence.
 *
 * So conversion happens for *new* images only, and legacy inline images stay
 * exactly as they are for as long as their document does. Migrating them is a
 * separate, deliberate operation that moves bytes verbatim (the reference is the
 * hash of the same bytes, so the hash rule survives it) — never a side effect of
 * saving.
 */
final class SignatureImageStore
{
    /**
     * Refuse anything larger than this as a signature image.
     *
     * Normalised signatures measure ~3.5 KB and the worst legacy one on record is
     * 111 KB. 2 MB is far above both — this is not a tuning knob, it is a stop on
     * a POST field that would otherwise accept whatever it was handed.
     */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** @var BlobStore */
    private $blobs;

    public function __construct(BlobStore $blobs)
    {
        $this->blobs = $blobs;
    }

    /** The data URI shape the signature canvas produces, and the only one accepted. */
    private const DATA_URI = '#^data:image/(png|jpe?g|gif);base64,([A-Za-z0-9+/=\r\n]+)$#';

    public static function isDataUri(string $value): bool
    {
        return preg_match(self::DATA_URI, $value) === 1;
    }

    /**
     * Store a data URI and return the reference to put in the document.
     *
     * Returns null when the value is not a storable image or cannot be stored.
     * Callers keep the original on null — this is a storage optimisation, and
     * losing a signature to it would be an absurd trade.
     */
    public function store(string $dataUri): ?string
    {
        if (preg_match(self::DATA_URI, $dataUri, $m) !== 1) {
            return null;
        }

        // Strict decoding: a data URI carrying characters outside the base64
        // alphabet is malformed, and PHP's lenient mode would silently drop them
        // and store a subtly different image.
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        try {
            return $this->blobs->put($bytes, 'image/' . ($m[1] === 'jpg' ? 'jpeg' : $m[1]))->reference();
        } catch (\Throwable $e) {
            @error_log('[ezdoc:storage] could not store signature image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * A renderable data URI for a slot value, whatever form it takes.
     *
     * Legacy inline images come back unchanged, references are fetched. Null means
     * "nothing to render" — an unsigned slot, the QR marker, or an object that has
     * gone missing. Callers must treat those the same way: draw nothing, rather
     * than emit a broken `<img>`.
     */
    public function resolveToDataUri(string $value): ?string
    {
        if (self::isDataUri($value)) {
            return $value;
        }

        $key = Blob::keyFromReference($value);
        if ($key === null) {
            return null;
        }

        $bytes = $this->blobs->get($key);
        if ($bytes === null) {
            return null;
        }

        $blob = $this->blobs->find($key);
        $type = $blob !== null ? $blob->contentType() : 'image/png';

        return 'data:' . $type . ';base64,' . base64_encode($bytes);
    }

    /** Raw bytes and content type for a slot value, for serving over HTTP. */
    public function resolveToBytes(string $value): ?array
    {
        if (self::isDataUri($value)) {
            preg_match(self::DATA_URI, $value, $m);
            $bytes = base64_decode($m[2], true);

            return $bytes === false || $bytes === ''
                ? null
                : ['bytes' => $bytes, 'content_type' => 'image/' . ($m[1] === 'jpg' ? 'jpeg' : $m[1])];
        }

        $key = Blob::keyFromReference($value);
        if ($key === null) {
            return null;
        }

        $bytes = $this->blobs->get($key);
        if ($bytes === null) {
            return null;
        }

        $blob = $this->blobs->find($key);

        return [
            'bytes' => $bytes,
            'content_type' => $blob !== null ? $blob->contentType() : 'image/png',
        ];
    }

    /**
     * Decide what to persist for a slot, given what was submitted and what is
     * already stored.
     *
     * This is where the "never change a signed value" rule is enforced, and it is
     * expressed as a comparison rather than as a flag: an unchanged re-post is
     * returned verbatim, so a stored inline image stays inline and a stored
     * reference stays a reference. Only a value that genuinely differs from what
     * is stored — a new signature — becomes an object.
     *
     * The form re-posts every stored signature on every save, so without this the
     * first save after deploying would rewrite every slot in the document and
     * invalidate every signature on it.
     */
    public function valueToPersist(string $submitted, string $stored): string
    {
        if ($submitted === $stored) {
            return $submitted;
        }
        if (!self::isDataUri($submitted)) {
            return $submitted;
        }

        return $this->store($submitted) ?? $submitted;
    }
}
