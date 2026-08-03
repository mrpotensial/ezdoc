<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

/**
 * Interface untuk pengiriman OTP ke user (bisa lewat WhatsApp, SMS, atau Email).
 */
interface UserOtpProvider
{
    /**
     * Kirim kode OTP.
     *
     * @param string $phone Nomor handphone / identitas kontak.
     * @param string $name Nama user.
     * @param string $code Kode OTP (6 digit).
     * @param array $context Konteks tambahan (misal: doc_id, template_id).
     * @return OtpSendResult Hasil pengiriman.
     */
    public function send(string $phone, string $name, string $code, array $context = []): OtpSendResult;
}
