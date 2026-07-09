<?php

declare(strict_types=1);

namespace Ezdoc\Access;

/**
 * Result of an access check: allow atau deny + reason.
 * Dipakai untuk audit log & debugging trace.
 *
 * PHP 7.4+ compatible. Immutable.
 *
 * @example
 *   $decision = $ac->can(42, 'edit', $config);
 *   if ($decision->isAllowed()) { ... }
 *   else { $audit->log('denied', ['reason' => $decision->getReason()]); }
 */
final class AccessDecision
{
    /** @var bool */
    private $allowed;

    /** @var string */
    private $reason;

    /** @var string|null Rule yang match (kalau allowed). */
    private $matchedRule;

    private function __construct(bool $allowed, string $reason, ?string $matchedRule = null)
    {
        $this->allowed = $allowed;
        $this->reason = $reason;
        $this->matchedRule = $matchedRule;
    }

    /**
     * Factory: allow decision.
     */
    public static function allow(string $matchedRule, string $reason = ''): self
    {
        $reason = $reason !== '' ? $reason : "Matched rule: {$matchedRule}";
        return new self(true, $reason, $matchedRule);
    }

    /**
     * Factory: deny decision.
     */
    public static function deny(string $reason): self
    {
        return new self(false, $reason, null);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return !$this->allowed;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getMatchedRule(): ?string
    {
        return $this->matchedRule;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'matched_rule' => $this->matchedRule,
        ];
    }
}
