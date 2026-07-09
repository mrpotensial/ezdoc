<?php

declare(strict_types=1);

namespace Ezdoc\Access;

/**
 * Value object wrapping template's `access_config JSON`.
 *
 * Config format (canonical):
 * ```json
 * {
 *   "mode": "strict",
 *   "create": ["role:admin", "role:manager"],
 *   "edit":   ["role:admin", "user:42"],
 *   "lock":   ["role:admin"],
 *   "delete": ["role:admin"]
 * }
 * ```
 *
 * Backward compat: format lama `{"roles": [...], "users": [...]}` per action juga
 * di-support via internal normalization.
 *
 * PHP 7.4+ compatible. Immutable.
 */
final class AccessConfig
{
    public const MODE_STRICT = 'strict';
    public const MODE_PERMISSIVE = 'permissive';

    /** @var string */
    private $mode;

    /** @var array<string, array<PermissionRule>> action → list of rules */
    private $rules;

    /**
     * @param array<string, array<PermissionRule>> $rules
     */
    private function __construct(string $mode, array $rules)
    {
        $this->mode = $mode;
        $this->rules = $rules;
    }

    /**
     * Empty config — no rules defined, mode strict (deny by default).
     */
    public static function empty(): self
    {
        return new self(self::MODE_STRICT, []);
    }

    /**
     * Parse dari JSON string. Return empty config kalau JSON invalid.
     */
    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') return self::empty();
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return self::empty();
        return self::fromArray($decoded);
    }

    /**
     * Parse dari associative array.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $mode = isset($data['mode']) && is_string($data['mode']) && $data['mode'] === self::MODE_PERMISSIVE
            ? self::MODE_PERMISSIVE
            : self::MODE_STRICT;

        $rules = [];
        foreach ($data as $action => $ruleList) {
            if ($action === 'mode') continue;

            $parsed = self::parseRuleList($ruleList);
            if (!empty($parsed)) {
                $rules[(string) $action] = $parsed;
            }
        }

        return new self($mode, $rules);
    }

    /**
     * Support canonical `["role:admin", "user:42"]` OR legacy `{"roles": [...], "users": [...]}`.
     *
     * @param mixed $data
     * @return array<PermissionRule>
     */
    private static function parseRuleList($data): array
    {
        $parsed = [];

        if (is_array($data)) {
            // Canonical: flat list of strings ["role:admin", ...]
            if (isset($data[0])) {
                foreach ($data as $ruleStr) {
                    if (!is_string($ruleStr)) continue;
                    $rule = PermissionRule::parse($ruleStr);
                    if ($rule !== null) $parsed[] = $rule;
                }
                return $parsed;
            }
            // Legacy: {"roles": [...], "users": [...]}
            if (isset($data['roles']) && is_array($data['roles'])) {
                foreach ($data['roles'] as $r) {
                    if (!is_string($r)) continue;
                    $rule = PermissionRule::parse("role:{$r}");
                    if ($rule !== null) $parsed[] = $rule;
                }
            }
            if (isset($data['users']) && is_array($data['users'])) {
                foreach ($data['users'] as $u) {
                    if (!is_int($u) && !ctype_digit((string) $u)) continue;
                    $rule = PermissionRule::parse("user:" . (int) $u);
                    if ($rule !== null) $parsed[] = $rule;
                }
            }
        }

        return $parsed;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isStrict(): bool
    {
        return $this->mode === self::MODE_STRICT;
    }

    /**
     * Return list of rules untuk action tertentu. Empty array kalau tidak defined.
     *
     * @return array<PermissionRule>
     */
    public function getRulesFor(string $action): array
    {
        return $this->rules[$action] ?? [];
    }

    /**
     * Check apakah config define rules untuk action ini.
     */
    public function hasRulesFor(string $action): bool
    {
        return isset($this->rules[$action]) && !empty($this->rules[$action]);
    }

    /**
     * @return array<string>
     */
    public function getDefinedActions(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Serialize back to array (canonical format).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = ['mode' => $this->mode];
        foreach ($this->rules as $action => $ruleList) {
            $out[$action] = array_map(
                function (PermissionRule $r) { return $r->toString(); },
                $ruleList
            );
        }
        return $out;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }
}
