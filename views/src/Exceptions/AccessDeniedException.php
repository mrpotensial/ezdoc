<?php

declare(strict_types=1);

namespace Ezdoc\Exceptions;

/**
 * Thrown ketika user tidak berhak melakukan action pada resource tertentu.
 * HTTP status: 403 Forbidden.
 *
 * PHP 7.4+ compatible.
 *
 * @example
 *   throw AccessDeniedException::forAction('edit', $userId, 'Not template owner');
 *
 * @example catch specific:
 *   try { ... } catch (AccessDeniedException $e) {
 *     $auditLogger->denied($e->getAction(), $e->getReason());
 *   }
 */
class AccessDeniedException extends EzdocException
{
    /** @var int */
    protected $statusCode = 403;

    /** @var string|null Action yang di-deny (mis. 'edit', 'delete'). */
    protected $action = null;

    /** @var string|null Alasan textual untuk audit log. */
    protected $reason = null;

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * Factory: build exception dengan konteks action + user + reason.
     *
     * @param array<string,mixed> $extraContext
     */
    public static function forAction(
        string $action,
        int $userId = 0,
        string $reason = '',
        array $extraContext = []
    ): self {
        $message = $reason !== ''
            ? "Access denied for action '{$action}': {$reason}"
            : "Access denied for action '{$action}'";

        $ctx = array_merge([
            'action' => $action,
            'user_id' => $userId,
            'reason' => $reason,
        ], $extraContext);

        $ex = new self($message, $ctx);
        $ex->action = $action;
        $ex->reason = $reason;
        return $ex;
    }

    /**
     * Factory: user missing required role.
     *
     * @param array<string> $requiredRoles
     */
    public static function missingRole(array $requiredRoles, int $userId = 0): self
    {
        $roleList = implode(', ', $requiredRoles);
        $message = "User missing required role: {$roleList}";
        $ex = new self($message, [
            'required_roles' => $requiredRoles,
            'user_id' => $userId,
        ]);
        $ex->reason = "missing role: {$roleList}";
        return $ex;
    }
}
