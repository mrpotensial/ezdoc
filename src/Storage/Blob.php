<?php

declare(strict_types=1);

namespace Ezdoc\Storage;

/**
 * One stored binary object, described by its content.
 *
 * The identity is the content: `key` is the SHA-256 of the bytes, hex-encoded.
 * Content addressing is not a stylistic choice here — it is what makes the
 * signature hash rule hold. A signature's `content_hash` covers `ttd_value`, and
 * once the image moves out of the document row `ttd_value` becomes a reference.
 * A reference that names a *location* (a path, an id) can point at different
 * bytes tomorrow; a reference that names the *content* cannot. Paths may change,
 * content may not.
 *
 * Two more things fall out of it for free: identical bytes stored twice occupy
 * one row, and verifying an object needs no separate checksum column — the key
 * already is one.
 *
 * The shape follows Rails ActiveStorage's blob record (key, checksum, byte_size,
 * content_type) so that where the bytes live stays a backend choice rather than a
 * schema commitment.
 */
final class Blob
{
    /** @var string SHA-256 of the content, hex */
    private $key;

    /** @var int */
    private $byteSize;

    /** @var string */
    private $contentType;

    /** @var string Nama backend penyimpan byte-nya: 'database' | 'disk' */
    private $backend;

    /** @var string|null Set only when the caller already holds the bytes */
    private $content;

    public function __construct(
        string $key,
        int $byteSize,
        string $contentType,
        string $backend,
        ?string $content = null
    ) {
        $this->key = $key;
        $this->byteSize = $byteSize;
        $this->contentType = $contentType;
        $this->backend = $backend;
        $this->content = $content;
    }

    /**
     * Prefix for references stored in document JSON.
     *
     * Deliberately unmistakable. A slot value is now one of: a data URI (legacy,
     * still valid), the QR act marker, or one of these. Anything that inspects a
     * slot value has to be able to tell them apart without guessing, and a bare
     * hex string would look like plenty of other things.
     */
    public const REF_PREFIX = 'blob:sha256-';

    /** Content-addressed reference as it appears in `signature_values`. */
    public function reference(): string
    {
        return self::REF_PREFIX . $this->key;
    }

    /**
     * Extract the key from a reference, or null if this is not one.
     *
     * Validates the shape rather than trusting it: the value travels through
     * document JSON and POST bodies, and it is used to build filesystem paths in
     * the disk backend. A key that is not 64 hex characters never becomes a path.
     */
    public static function keyFromReference(string $value): ?string
    {
        $prefixLen = strlen(self::REF_PREFIX);
        if (strncmp($value, self::REF_PREFIX, $prefixLen) !== 0) {
            return null;
        }

        $key = substr($value, $prefixLen);

        return preg_match('/^[0-9a-f]{64}$/', $key) === 1 ? $key : null;
    }

    public static function isReference(string $value): bool
    {
        return self::keyFromReference($value) !== null;
    }

    /** Key for the given bytes. The only place the key is ever derived. */
    public static function keyFor(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function backend(): string
    {
        return $this->backend;
    }

    /** Bytes, when this instance carries them. Null means "fetch from the store". */
    public function content(): ?string
    {
        return $this->content;
    }

    /**
     * Do the bytes still hash to the key?
     *
     * This is the check the plan required before moving images out of the
     * document row: without it a stored object could be swapped with no trace,
     * and for a signature that is the one thing that must not be possible.
     */
    public static function verify(string $key, string $bytes): bool
    {
        return hash_equals($key, self::keyFor($bytes));
    }
}
