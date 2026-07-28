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
     * Config for a template that carries none.
     *
     * The answer comes from `EZDOC_ACCESS_DEFAULT` rather than being decided
     * here. It used to return strict unconditionally while the procedural
     * helper in lib/authorization.php returned allow for the same situation —
     * two defaults, opposite answers, both implicit.
     *
     * @see defaultMode()
     */
    public static function empty(): self
    {
        return new self(self::defaultMode(), []);
    }

    /**
     * Mode applied when a template has no access_config.
     *
     * Falls back to permissive when the constant is absent or holds something
     * unrecognised — an unreadable setting must not quietly lock a deployment
     * out of its own documents.
     */
    public static function defaultMode(): string
    {
        $configured = defined('EZDOC_ACCESS_DEFAULT') ? (string) EZDOC_ACCESS_DEFAULT : self::MODE_PERMISSIVE;

        return $configured === self::MODE_STRICT ? self::MODE_STRICT : self::MODE_PERMISSIVE;
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
        // An explicit mode in the stored config always wins. Anything else —
        // absent, or a value we do not recognise — falls back to the
        // deployment default rather than to a mode chosen here.
        if (isset($data['mode']) && is_string($data['mode'])) {
            if ($data['mode'] === self::MODE_PERMISSIVE) {
                $mode = self::MODE_PERMISSIVE;
            } elseif ($data['mode'] === self::MODE_STRICT) {
                $mode = self::MODE_STRICT;
            } else {
                $mode = self::defaultMode();
            }
        } else {
            $mode = self::defaultMode();
        }

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
