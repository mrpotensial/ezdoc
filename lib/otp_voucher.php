<?php

declare(strict_types=1);

/**
 * Issuing side of the patient-initiated OTP route, for the host's inbound
 * message handler to call.
 *
 * ## Why a function and not an HTTP endpoint
 *
 * The obvious shape would be `POST actions/document/issue_voucher.php`. It is
 * the wrong one here.
 *
 * The caller is the host's own webhook handler, running on the same machine, in
 * the same PHP installation, with no browser session. Reaching it over HTTP
 * would mean the server calling itself, which needs a shared secret to
 * authenticate — an extra credential to store, rotate and leak, bought in
 * exchange for nothing. It also inherits every way a loopback request can fail
 * that a function call cannot: the front controller's own auth layer, a proxy
 * in between, TLS to yourself, a firewall rule written for outside traffic.
 *
 * That last one is not hypothetical in this codebase. An earlier revision had
 * ezdoc reach a consumer webhook over loopback and it came back 403; the fix
 * was to call the adapter in-process. Same reasoning, opposite direction.
 *
 * If the bot ever moves to a separate host, add a transport in front of this
 * function rather than replacing it — the rules belong in
 * {@see \Ezdoc\Signature\Otp\OtpVoucherService} either way.
 *
 * ## Usage from the host's inbound handler
 *
 * ```php
 * require_once __DIR__ . '/../ezdoc/bootstrap.php';
 *
 * $result = ezdoc_issue_otp_voucher($senderPhone, ['message_id' => $msgId]);
 *
 * if ($result['ok']) {
 *     reply("Kode Anda: {$result['code']}\nBerlaku {$result['ttl_minutes']} menit.");
 * } elseif ($result['reason'] === 'cooldown') {
 *     reply("Mohon tunggu {$result['retry_after']} detik lagi.");
 * } else {
 *     reply("Nomor tidak dikenali.");
 * }
 * ```
 *
 * The plaintext code is in the return value. It exists nowhere else — storage
 * keeps only a hash — so deliver it and drop it. Do not log this array.
 */

if (defined('EZDOC_OTP_VOUCHER_LOADED')) {
    return;
}
define('EZDOC_OTP_VOUCHER_LOADED', true);

/**
 * Mint a one-time code for a phone number that just asked for one.
 *
 * Never throws: an inbound webhook that dies leaves the sender with silence and
 * usually gets retried, which would mint codes nobody reads. Every outcome is a
 * value the caller can turn into a reply.
 *
 * @param string              $phone  Sender's number, any format.
 * @param array<string,mixed> $meta   Worth keeping for later tracing — message id, channel.
 * @param string              $source Which channel asked.
 * @return array{
 *     ok: bool, reason: string, code?: string, expires_at?: string,
 *     ttl_seconds?: int, ttl_minutes?: int, retry_after?: int, message?: string
 * }
 *     `reason` is one of: `issued`, `cooldown`, `bad_phone`, `error`.
 */
function ezdoc_issue_otp_voucher(
    string $phone,
    array $meta = [],
    string $source = 'whatsapp_inbound'
): array {
    // Built in its own step: if wiring the service fails there is no service to
    // ask about cooldowns in the handler below, and referring to it there would
    // turn a configuration problem into a fatal.
    try {
        $service = new \Ezdoc\Signature\Otp\OtpVoucherService();
    } catch (\Throwable $e) {
        @error_log('[ezdoc:voucher:issue] ' . $e->getMessage());

        return ['ok' => false, 'reason' => 'error'];
    }

    try {
        // Asked before calling issue() so the refusal can carry a number. The
        // service refuses on its own too — this is for the wording, not the
        // rule.
        $wait = $service->cooldownRemaining($phone);
        if ($wait > 0) {
            return [
                'ok'          => false,
                'reason'      => 'cooldown',
                'retry_after' => $wait,
            ];
        }

        $issued = $service->issue($phone, $meta, $source);
    } catch (\Ezdoc\Exceptions\EzdocException $e) {
        // Thrown for an unusable number, and for a cooldown that started
        // between the check above and the write — rare, but the service is the
        // authority, not the pre-check.
        return [
            'ok'      => false,
            'reason'  => $service->cooldownRemaining($phone) > 0 ? 'cooldown' : 'bad_phone',
            'message' => $e->getMessage(),
        ];
    } catch (\Throwable $e) {
        @error_log('[ezdoc:voucher:issue] ' . $e->getMessage());

        return ['ok' => false, 'reason' => 'error'];
    }

    // The number is masked here on purpose. An audit row saying which number
    // asked for a code, in full, would turn the log into a directory of
    // everyone who ever messaged the service.
    ezdoc_audit_log('otp.voucher_issued', [
        'target_type' => 'otp_voucher',
        'target_id'   => (string) $issued['voucher_id'],
        'metadata'    => [
            'masked_phone' => \Ezdoc\Signature\Otp\OtpService::maskPhone($phone),
            'source'       => $source,
            'expires_at'   => $issued['expires_at'],
        ],
    ]);

    return [
        'ok'          => true,
        'reason'      => 'issued',
        'code'        => $issued['code'],
        'expires_at'  => $issued['expires_at'],
        'ttl_seconds' => $issued['ttl_seconds'],
        'ttl_minutes' => (int) round($issued['ttl_seconds'] / 60),
    ];
}
