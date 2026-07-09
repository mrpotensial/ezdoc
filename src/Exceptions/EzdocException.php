<?php

declare(strict_types=1);

namespace Ezdoc\Exceptions;

/**
 * Base exception class untuk semua exception di library ezdoc.
 *
 * Extends `\RuntimeException` untuk backward compat — code existing yang
 * `catch (\RuntimeException $e)` tetap catch subclass ini.
 *
 * PHP 7.4+ compatible.
 *
 * @example
 *   try {
 *       $svc->save(...);
 *   } catch (\Ezdoc\Exceptions\EzdocException $e) {
 *       http_response_code($e->getStatusCode());
 *       echo json_encode($e->toArray());
 *   }
 */
class EzdocException extends \RuntimeException
{
    /** @var int HTTP status code untuk response (default 500). */
    protected $statusCode = 500;

    /** @var array<string,mixed> Extra context untuk audit log & debug. */
    protected $context = [];

    /**
     * @param string $message
     * @param array<string,mixed> $context
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = '', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);
        return $clone;
    }

    /**
     * Serialize untuk JSON response / audit log.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => static::class,
            'message' => $this->getMessage(),
            'status' => $this->statusCode,
            'context' => $this->context,
        ];
    }
}
