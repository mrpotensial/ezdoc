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
    public const ALGO = 'sha256-jcs-v2';

    /**
     * The first shape, kept because signatures were recorded under it.
     *
     * v1 covered every field in the document. That is wrong for more than one
     * signer: the second person fills in the name under their own signature, and
     * the first signature — which covered that field too — starts reporting the
     * document as altered. On a two-signer document the second person simply
     * could not sign.
     *
     * v2 scopes each signature to what it actually attests to. Signatures already
     * written stay verifiable under v1; nothing is rewritten, because rewriting a
     * recorded hash would destroy the only thing it is good for.
     */
    public const ALGO_V1 = 'sha256-jcs-v1';

    /**
     * The payload a signature covers — v1 shape.
     *
     * Kept to verify signatures already recorded under {@see ALGO_V1}. Not used
     * for new ones: it covers every field in the document, including the fields
     * that belong to other people's signature slots, which is the flaw v2 exists
     * to correct.
     *
     * @param array<string,mixed> $fields Document field values
     * @return array<string,mixed>
     */
    public static function buildV1(
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
     * SHA-256 over the canonical form of {@see buildV1()}.
     *
     * @param array<string,mixed> $fields
     * @throws \Ezdoc\Exceptions\ValidationException kalau field-nya tidak bisa dikanonikalisasi
     */
    public static function hashV1(
        string $documentUuid,
        string $templateUuid,
        int $templateVersion,
        array $fields,
        string $ttdId,
        string $ttdValue
    ): string {
        return CanonicalJson::hash(
            self::buildV1($documentUuid, $templateUuid, $templateVersion, $fields, $ttdId, $ttdValue)
        );
    }

    /**
     * The payload a signature covers — current shape.
     *
     * @param array<string,mixed> $fields       Document field values (all of them)
     * @param list<string>        $coveredKeys  Which of them this signature attests to
     * @return array<string,mixed>
     */
    public static function build(
        string $documentUuid,
        string $templateUuid,
        int $templateVersion,
        array $fields,
        string $ttdId,
        string $ttdValue,
        array $coveredKeys
    ): array {
        $covered = self::normaliseKeys($coveredKeys);

        $scoped = [];
        foreach ($covered as $key) {
            // Absent keys are recorded as absent rather than skipped. A field that
            // disappears from the document later must change the hash — skipping
            // it would let deletion pass as if nothing happened.
            $scoped[$key] = array_key_exists($key, $fields) ? $fields[$key] : null;
        }

        return [
            'document_uuid'    => $documentUuid,
            'template_uuid'    => $templateUuid,
            'template_version' => $templateVersion,
            // The key list is inside the payload, not only alongside it. A
            // verifier has to be told which fields to recompute over, and if that
            // instruction sat outside the signed data, anyone able to edit it
            // could shrink the covered set and have the signature still verify.
            // Inside, shrinking it changes the hash. Same reasoning as PAdES
            // putting /ByteRange inside the signed structure rather than beside it.
            'covered_fields'   => $covered,
            'fields'           => $scoped,
            'ttd_id'           => $ttdId,
            'ttd_value'        => $ttdValue,
        ];
    }

    /**
     * SHA-256 over the canonical form of {@see build()}.
     *
     * @param array<string,mixed> $fields
     * @param list<string>        $coveredKeys
     * @throws \Ezdoc\Exceptions\ValidationException kalau field-nya tidak bisa dikanonikalisasi
     */
    public static function hash(
        string $documentUuid,
        string $templateUuid,
        int $templateVersion,
        array $fields,
        string $ttdId,
        string $ttdValue,
        array $coveredKeys
    ): string {
        return CanonicalJson::hash(
            self::build($documentUuid, $templateUuid, $templateVersion, $fields, $ttdId, $ttdValue, $coveredKeys)
        );
    }

    /**
     * Which field keys a signature on `$ttdId` attests to.
     *
     * Everything in the document, minus two kinds of key:
     *
     * **Other slots' own fields** — the name printed under someone else's
     * signature, and their QR content. The second person to sign fills in their
     * own name; if that were covered by the first signature, the first would
     * report the document as altered for nothing but a colleague signing after
     * them. That is the bug this exists to fix.
     *
     * **Display mode** (`_ttd_mode_*`) — for every slot including this one.
     * Whether a signature renders as a drawn image or as a QR pointing at its
     * record does not change who signed what. PAdES draws the same line: the
     * appearance stream is not what the signature attests to.
     *
     * The signing slot's OWN name and QR content stay covered. Those are part of
     * what this person is putting their name to.
     *
     * @param list<string>                $allKeys    Every field key in the document
     * @param array<string,list<string>>  $slotFields ttd_id => [nama_field]
     * @return list<string>
     */
    public static function coveredKeysFor(string $ttdId, array $allKeys, array $slotFields): array
    {
        $excluded = [];
        foreach ($slotFields as $slotId => $names) {
            $namaField = isset($names[0]) ? (string) $names[0] : '';
            if ($namaField === '' || (string) $slotId === $ttdId) {
                continue;
            }
            $excluded[$namaField] = true;
            $excluded[$namaField . '_qr'] = true;
        }

        $covered = [];
        foreach ($allKeys as $key) {
            $key = (string) $key;
            if (isset($excluded[$key])) {
                continue;
            }
            if (strncmp($key, '_ttd_mode_', 10) === 0) {
                continue;
            }
            $covered[] = $key;
        }

        return self::normaliseKeys($covered);
    }

    /**
     * Sorted and deduplicated.
     *
     * Order must not change the hash — the caller builds this list by iterating a
     * map, and PHP's iteration order is insertion order, so an unsorted list
     * would make the same coverage hash differently depending on the order fields
     * happened to be collected.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normaliseKeys(array $keys): array
    {
        $unique = array_values(array_unique(array_map('strval', $keys)));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
