<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Support\CanonicalJson;

/**
 * Builds what a signature attests to, and hashes it.
 *
 * ## What a signature covers
 *
 * The document's identity, its field values, and **one** signature slot:
 *
 *     document_uuid, template_uuid, template_version,
 *     fields, ttd_id, ttd_value
 *
 * ## What it deliberately leaves out: the other slots
 *
 * If a signature covered every slot's value, then the second person to sign
 * would change what the first person's signature covers, and the first
 * signature would report the document as altered — for doing nothing but
 * letting a colleague sign after them.
 *
 * PDF resolves this the same way: each signature covers the bytes up to its own
 * point, and later signatures are appended as incremental updates without
 * invalidating earlier ones. A signature attests to the content, not to who
 * else has signed.
 *
 * The consequences, all of them intended:
 *
 * | Event                            | This signature |
 * |----------------------------------|----------------|
 * | Someone signs a different slot   | unaffected     |
 * | Signing order differs            | unaffected     |
 * | A field is edited                | **broken** — the statement changed |
 * | This slot's mark is swapped      | **broken**     |
 * | Another slot's mark is swapped   | unaffected     |
 *
 * Field order cannot matter either, which is why the payload goes through
 * {@see CanonicalJson} rather than `json_encode()`.
 */
final class SignaturePayload
{
    /**
     * Identifies both the hash input shape and its canonicalisation.
     *
     * Stored in `ezdoc_signatures.content_hash_algo` so a verifier knows how to
     * recompute. Changing what goes into the payload means changing this too, or
     * old signatures become unverifiable without anyone noticing why.
     */
    public const ALGO = 'sha256-jcs-v1';

    /**
     * The payload a signature covers.
     *
     * @param array<string,mixed> $fields Document field values
     * @return array<string,mixed>
     */
    public static function build(
        string $documentUuid,
        string $templateUuid,
        int $templateVersion,
        array $fields,
        string $ttdId,
        string $ttdValue
    ): array {
        return [
            'document_uuid'    => $documentUuid,
            'template_uuid'    => $templateUuid,
            'template_version' => $templateVersion,
            'fields'           => $fields,
            'ttd_id'           => $ttdId,
            'ttd_value'        => $ttdValue,
        ];
    }

    /**
     * SHA-256 over the canonical form of {@see build()}.
     *
     * @param array<string,mixed> $fields
     * @throws \Ezdoc\Exceptions\ValidationException kalau field-nya tidak bisa dikanonikalisasi
     */
    public static function hash(
        string $documentUuid,
        string $templateUuid,
        int $templateVersion,
        array $fields,
        string $ttdId,
        string $ttdValue
    ): string {
        return CanonicalJson::hash(
            self::build($documentUuid, $templateUuid, $templateVersion, $fields, $ttdId, $ttdValue)
        );
    }
}
