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
                // Contract violation by the consumer's callback — surfaced in
                // full because it is a wiring bug, not runtime data.
                return OtpSendResult::failure(
                    'CallbackOtpProvider: callback must return an OtpSendResult, got ' . gettype($result)
                );
            }

            return $result;
        } catch (\Throwable $e) {
            // Message and stack trace stay in the log. A callback typically
            // wraps vendor SDKs whose exceptions carry endpoints, tokens and
            // file paths; none of that belongs in an HTTP response.
            @error_log(
                '[ezdoc:otp:callback] ' . get_class($e) . ': ' . $e->getMessage()
                . "\n" . $e->getTraceAsString()
            );
            return OtpSendResult::failure('OTP delivery failed.');
        }
    }
}
