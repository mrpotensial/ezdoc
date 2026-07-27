<?php

declare(strict_types=1);

namespace Ezdoc\Signature\Otp\Providers;

use Ezdoc\Signature\Otp\UserOtpProvider;
use Ezdoc\Signature\Otp\OtpSendResult;

/**
 * Universal callable-based OTP provider.
 *
 * The most flexible provider — accepts any PHP callable that handles OTP delivery.
 * Consumer applications inject their own send logic without needing to create a full class.
 *
 * The callable signature must be:
 *   function(string $phone, string $name, string $code, array $context): OtpSendResult
 *
 * Usage examples:
 *
 *   // Using a closure:
 *   $provider = new CallbackOtpProvider(function($phone, $name, $code, $ctx) {
 *       // Your custom logic — call any API, function, or service
 *       $result = your_sms_api_send($phone, "Your OTP is: $code");
 *       return $result ? OtpSendResult::success('Sent') : OtpSendResult::failure('Failed');
 *   });
 *
 *   // Using a static method:
 *   $provider = new CallbackOtpProvider([MyOtpHandler::class, 'send']);
 *
 *   // Using an invokable object:
 *   $provider = new CallbackOtpProvider(new MyOtpSender());
 */
class CallbackOtpProvider implements UserOtpProvider
{
    /** @var callable(string, string, string, array): OtpSendResult */
    private $callback;

    /**
     * @param callable $callback Callable with signature:
     *                           fn(string $phone, string $name, string $code, array $context): OtpSendResult
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function send(string $phone, string $name, string $code, array $context = []): OtpSendResult
    {
        try {
            $result = ($this->callback)($phone, $name, $code, $context);

            if (!$result instanceof OtpSendResult) {
                return OtpSendResult::failure(
                    'CallbackOtpProvider: callback harus mengembalikan instance OtpSendResult, got ' . gettype($result)
                );
            }

            return $result;
        } catch (\Throwable $e) {
            return OtpSendResult::failure(
                'CallbackOtpProvider error: ' . $e->getMessage(),
                ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]
            );
        }
    }
}
