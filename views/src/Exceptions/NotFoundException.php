<?php

declare(strict_types=1);

namespace Ezdoc\Exceptions;

/**
 * Thrown ketika resource tidak ditemukan (template, document, dll).
 * HTTP status: 404 Not Found.
 *
 * PHP 7.4+ compatible.
 *
 * @example
 *   throw NotFoundException::forResource('template', $templateId);
 */
class NotFoundException extends EzdocException
{
    /** @var int */
    protected $statusCode = 404;

    /** @var string|null Tipe resource (mis. 'template', 'document'). */
    protected $resourceType = null;

    /** @var string|null ID resource yang dicari. */
    protected $resourceId = null;

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    /**
     * Factory: build exception dengan tipe + ID.
     *
     * @param string|int $id
     */
    public static function forResource(string $type, $id): self
    {
        $idStr = (string) $id;
        $message = ucfirst($type) . " with ID '{$idStr}' not found";
        $ex = new self($message, [
            'resource_type' => $type,
            'resource_id' => $idStr,
        ]);
        $ex->resourceType = $type;
        $ex->resourceId = $idStr;
        return $ex;
    }
}
