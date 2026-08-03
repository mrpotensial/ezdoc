<?php

declare(strict_types=1);

namespace Ezdoc\Exceptions;

/**
 * Thrown ketika input validation gagal (invalid params, missing required field, dll).
 * HTTP status: 400 Bad Request.
 *
 * PHP 7.4+ compatible.
 *
 * @example
 *   throw ValidationException::forField('template_id', 'ID tidak valid');
 *
 * @example multi-error:
 *   $ex = new ValidationException('Multiple validation errors', [
 *       'errors' => ['name' => 'required', 'email' => 'invalid format']
 *   ]);
 */
class ValidationException extends EzdocException
{
    /** @var int */
    protected $statusCode = 400;

    /** @var array<string,string> Field name → error message. */
    protected $errors = [];

    /**
     * @return array<string,string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Factory: single-field validation error.
     */
    public static function forField(string $field, string $errorMessage): self
    {
        $message = "Validation failed for '{$field}': {$errorMessage}";
        $ex = new self($message, [
            'field' => $field,
            'error' => $errorMessage,
        ]);
        $ex->errors = [$field => $errorMessage];
        return $ex;
    }

    /**
     * Factory: multiple field errors.
     *
     * @param array<string,string> $errors field → message
     */
    public static function forFields(array $errors, string $message = 'Validation failed'): self
    {
        $ex = new self($message, ['errors' => $errors]);
        $ex->errors = $errors;
        return $ex;
    }

    public function toArray(): array
    {
        $arr = parent::toArray();
        $arr['errors'] = $this->errors;
        return $arr;
    }
}
