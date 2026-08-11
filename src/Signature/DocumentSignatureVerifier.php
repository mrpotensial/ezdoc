<?php

declare(strict_types=1);

namespace Ezdoc\Signature;

use Ezdoc\Db\Connection;

/**
 * Checks the signatures recorded against a document, one verdict per slot.
 *
 * Two independent questions are asked about each signature, and keeping them
 * apart is the whole value of this class:
 *
 *  1. **Does the envelope verify?** Recompute the HMAC over the stored
 *     `content_hash` with the derived key. This asks whether the *record* is
 *     genuine — whether anyone edited the signature row itself.
 *  2. **Does the content still hash the same?** Recompute the hash from the
 *     document as it stands now. This asks whether the *document* changed after
 *     it was signed.
 *
 * Collapsing them into one boolean loses the distinction that matters. A
 * document edited after signing is an ordinary, expected event — someone
 * revised it and should have made a new version. A signature row whose envelope
 * fails is a different thing entirely: either the database was written to
 * directly, or the signing key changed. The first is a workflow problem, the
 * second wants investigating.
 *
 * @see docs/260730-catatan-tanda-tangan.md
 */
final class DocumentSignatureVerifier
{
    /** Record and content both check out. */
    public const STATUS_VALID = 'valid';

    /** Signature genuine, but the document changed after it was signed. */
    public const STATUS_TAMPERED = 'tampered';

    /** The signature row itself does not verify — direct write, or key rotated. */
    public const STATUS_UNTRUSTED = 'untrusted';

    /** Cannot be checked: no key configured, or an algorithm we do not know. */
    public const STATUS_UNVERIFIABLE = 'unverifiable';

    /** Withdrawn deliberately; recorded state wins over any recomputation. */
    public const STATUS_REVOKED = 'revoked';

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Verdicts for every signature on a document, in signing order.
     *
     * @param int $documentId
     * @param string|null $onlyTtdId Restrict to one slot — what a per-signature
     *                               QR asks for. Null returns all.
     * @return list<array<string,mixed>>
     */
    public function verify(int $documentId, ?string $onlyTtdId = null): array
    {
        if ($documentId <= 0) {
            return [];
        }

        $doc = $this->db->fetchOne(
            'SELECT `uuid`, `template_uuid`, `template_version`, `field_values`, `signature_values`
             FROM `ezdoc_documents` WHERE `id` = ? LIMIT 1',
            [$documentId]
        );
        if (!$doc) {
            return [];
        }

        $fields = json_decode((string) ($doc['field_values'] ?? ''), true);
        $fields = is_array($fields) ? $fields : [];
        $marks = json_decode((string) ($doc['signature_values'] ?? ''), true);
        $marks = is_array($marks) ? $marks : [];

        $rows = $this->db->fetchAll(
            'SELECT `signature_id_within_doc`, `signer_id`, `signer_role`, `provider`, `level`,
                    `envelope_format`, `envelope`, `content_hash`, `content_hash_algo`,
                    `signed_at`, `verify_status`, `metadata`
             FROM `ezdoc_signatures`
             WHERE `document_id` = ? AND `deleted_at` IS NULL
             ORDER BY `signed_at` ASC, `id` ASC',
            [$documentId]
        );

        // Resolved once: a document with four signatures should not derive the
        // key four times.
        $key = SigningKey::resolve();

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $ttdId = (string) ($meta['ttd_id'] ?? '');

            if ($onlyTtdId !== null && $ttdId !== $onlyTtdId) {
                continue;
            }

            $out[] = $this->judge($row, $meta, $ttdId, $doc, $fields, $marks, $key);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $marks
     * @return array<string,mixed>
     */
    private function judge(
        array $row,
        array $meta,
        string $ttdId,
        array $doc,
        array $fields,
        array $marks,
        ?string $key
    ): array {
        $verdict = [
            'ttd_id'      => $ttdId,
            'ttd_label'   => (string) ($meta['ttd_label'] ?? $ttdId),
            'slot'        => (int) ($row['signature_id_within_doc'] ?? 0),
            'signer_id'   => (string) ($row['signer_id'] ?? ''),
            // The name printed under the signature, as it was when signed.
            //
            // Read from the record rather than from the document, deliberately.
            // The document says what it says now; this says what was signed. When
            // the two disagree the content hash already reports it, and having
            // both is what makes the disagreement legible instead of just failed.
            //
            // Null for signatures recorded before this was captured — absent, not
            // empty, so a reader can tell "no name" from "not recorded".
            'signer_name' => isset($meta['signer_name']) && $meta['signer_name'] !== ''
                ? (string) $meta['signer_name']
                : null,
            'signer_role' => $row['signer_role'] !== null ? (string) $row['signer_role'] : null,
            'signed_at'   => (string) ($row['signed_at'] ?? ''),
            'provider'    => (string) ($row['provider'] ?? ''),
            'level'       => (int) ($row['level'] ?? 0),
            'replaced'    => !empty($meta['replaced']),
        ];

        // A withdrawal is a decision someone made. No recomputation overrides it.
        if ((string) ($row['verify_status'] ?? '') === 'revoked') {
            return $verdict + [
                'status' => self::STATUS_REVOKED,
                'reason' => 'signature was revoked',
            ];
        }

        $algo = (string) ($row['content_hash_algo'] ?? '');
        if ($algo !== SignaturePayload::ALGO && $algo !== SignaturePayload::ALGO_V1) {
            return $verdict + [
                'status' => self::STATUS_UNVERIFIABLE,
                'reason' => "recorded under '{$algo}', which this version cannot recompute",
            ];
        }

        // v2 records which fields it covers. Without that list there is nothing to
        // recompute over, and guessing would either check too much (reporting
        // honest documents as altered) or too little (accepting edits the
        // signature did cover).
        $coveredFields = null;
        if ($algo === SignaturePayload::ALGO) {
            $coveredFields = isset($meta['covered_fields']) && is_array($meta['covered_fields'])
                ? array_values(array_map('strval', $meta['covered_fields']))
                : null;

            if ($coveredFields === null) {
                return $verdict + [
                    'status' => self::STATUS_UNVERIFIABLE,
                    'reason' => 'recorded under ' . $algo . ' but its covered-field list is missing',
                ];
            }
        }

        if ((string) ($row['envelope_format'] ?? '') !== 'hmac') {
            return $verdict + [
                'status' => self::STATUS_UNVERIFIABLE,
                'reason' => 'envelope format ' . (string) $row['envelope_format'] . ' needs its own verifier',
            ];
        }

        if ($key === null) {
            return $verdict + [
                'status' => self::STATUS_UNVERIFIABLE,
                'reason' => 'no signing key configured, so nothing can be checked',
            ];
        }

        // Question 1: is the record itself genuine?
        $expected = bin2hex(hash_hmac('sha256', (string) $row['content_hash'], $key, true));
        if (!hash_equals($expected, (string) ($row['envelope'] ?? ''))) {
            return $verdict + [
                'status' => self::STATUS_UNTRUSTED,
                'reason' => 'the signature record does not verify — it was altered, or the signing key changed',
            ];
        }

        // Question 2: does the document still say what was signed?
        //
        // The covered-field list comes from metadata, but it is also inside the
        // hashed payload — so editing it there does not quietly widen or narrow
        // what gets checked; it changes the recomputed hash and shows up as
        // tampering.
        $recomputed = $coveredFields === null
            ? SignaturePayload::hashV1(
                (string) ($doc['uuid'] ?? ''),
                (string) ($doc['template_uuid'] ?? ''),
                (int) ($doc['template_version'] ?? 0),
                $fields,
                $ttdId,
                (string) ($marks[$ttdId] ?? '')
            )
            : SignaturePayload::hash(
                (string) ($doc['uuid'] ?? ''),
                (string) ($doc['template_uuid'] ?? ''),
                (int) ($doc['template_version'] ?? 0),
                $fields,
                $ttdId,
                (string) ($marks[$ttdId] ?? ''),
                $coveredFields
            );

        if (!hash_equals((string) $row['content_hash'], $recomputed)) {
            return $verdict + [
                'status' => self::STATUS_TAMPERED,
                'reason' => 'the signature is genuine but the document changed after it was signed',
            ];
        }

        return $verdict + [
            'status' => self::STATUS_VALID,
            'reason' => 'signature and content both check out',
        ];
    }

    /**
     * One status for the whole document, for a summary line.
     *
     * The worst individual verdict wins: a document is not "valid" because most
     * of its signatures are. Ordered by how much attention each deserves.
     *
     * @param list<array<string,mixed>> $verdicts
     */
    public static function summarise(array $verdicts): string
    {
        if ($verdicts === []) {
            return 'unsigned';
        }

        foreach ([self::STATUS_UNTRUSTED, self::STATUS_TAMPERED, self::STATUS_REVOKED, self::STATUS_UNVERIFIABLE] as $worst) {
            foreach ($verdicts as $v) {
                if (($v['status'] ?? '') === $worst) {
                    return $worst;
                }
            }
        }

        return self::STATUS_VALID;
    }
}
