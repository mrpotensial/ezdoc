<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp;

/**
 * Value Object representing the result of an OTP send request.
 */
final class OtpSendResult
{
    private bool $success;
    private string $message;
    private ?string $referenceId;
    private array $providerMeta;

    public function __construct(
        bool $success,
        string $message = '',
        ?string $referenceId = null,
        array $providerMeta = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->referenceId = $referenceId;
        $this->providerMeta = $providerMeta;
    }

    public static function success(string $message = 'OTP sent successfully', ?string $referenceId = null, array $meta = []): self
    {
        return new self(true, $message, $referenceId, $meta);
    }

    public static function failure(string $message = 'Failed to send OTP', array $meta = []): self
    {
        return new self(false, $message, null, $meta);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getReferenceId(): ?string
    {
        return $this->referenceId;
    }

    public function getProviderMeta(): array
    {
        return $this->providerMeta;
    }

    public function toArray(): array
    {
        return [
            'success'       => $this->success,
            'message'       => $this->message,
            'reference_id'  => $this->referenceId,
            'provider_meta' => $this->providerMeta,
        ];
    }
}
