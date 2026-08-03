<?php

declare(strict_types=1);

namespace Ezdoc\Access;

use Ezdoc\Auth\RoleProvider;

/**
 * Value object representing 1 permission rule dalam access config.
 *
 * Rule syntax (canonical form):
 *   - `role:<name>`     — allow siapapun dengan role X (mis. `role:admin`)
 *   - `user:<id>`       — allow user ID spesifik (mis. `user:42`)
 *   - `*`               — allow all (wildcard — hati-hati)
 *
 * PHP 7.4+ compatible. Immutable.
 *
 * @example
 *   $rule = PermissionRule::parse('role:admin');
 *   $rule->matches($roleProvider, 42);    // true kalau user 42 punya role admin
 */
final class PermissionRule
{
    public const TYPE_ROLE = 'role';
    public const TYPE_USER = 'user';
    public const TYPE_WILDCARD = 'wildcard';

    /** @var string */
    private $type;

    /** @var string */
    private $value;

    private function __construct(string $type, string $value)
    {
        $this->type = $type;
        $this->value = $value;
    }

    /**
     * Parse rule string ke PermissionRule object.
     * Invalid format → return null (permissive: skip rule).
     */
    public static function parse(string $ruleStr): ?self
    {
        $ruleStr = trim($ruleStr);
        if ($ruleStr === '') return null;

        if ($ruleStr === '*') {
            return new self(self::TYPE_WILDCARD, '*');
        }

        if (strpos($ruleStr, ':') === false) return null;

        [$type, $value] = explode(':', $ruleStr, 2);
        $type = strtolower(trim($type));
        $value = trim($value);

        if ($value === '') return null;

        if ($type === self::TYPE_ROLE) {
            return new self(self::TYPE_ROLE, $value);
        }
        if ($type === self::TYPE_USER) {
            if (!ctype_digit($value)) return null;
            return new self(self::TYPE_USER, $value);
        }

        return null;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * String representation (canonical form).
     */
    public function toString(): string
    {
        if ($this->type === self::TYPE_WILDCARD) return '*';
        return "{$this->type}:{$this->value}";
    }

    /**
     * Check apakah user (userId) match rule ini.
     */
    public function matches(RoleProvider $roleProvider, int $userId): bool
    {
        switch ($this->type) {
            case self::TYPE_WILDCARD:
                return true;

            case self::TYPE_USER:
                return $userId > 0 && (int) $this->value === $userId;

            case self::TYPE_ROLE:
                return $roleProvider->hasRole($this->value);

            default:
                return false;
        }
    }
}
