<?php

declare(strict_types=1);

namespace Ezdoc\UI;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\UI\SlotRegistry — named extension points for views.
 *
 * Consumers `register()` a callable or a raw HTML/text string against a
 * slot name (e.g. `'designer:sidebar-extra'`), and the view template calls
 * `render()` at the injection point. Multiple contributions are
 * concatenated in priority order (lower priority = earlier output);
 * ties resolve to registration order.
 *
 * PHP 7.4+ compatible.
 */
final class SlotRegistry
{
    /**
     * Slot storage:
     *   [slotName => [ [priority, seq, content], ... ] ]
     * where content is callable|string.
     *
     * @var array<string, array<int, array{0:int,1:int,2:mixed}>>
     */
    private $slots;

    /** @var int Monotonic sequence to preserve registration order on ties. */
    private $sequence;

    public function __construct()
    {
        $this->slots = [];
        $this->sequence = 0;
    }

    /**
     * Register a contribution to a slot.
     *
     * @param callable|string $content Callable receives $context, returns string.
     *                                 String is appended verbatim.
     * @throws ValidationException on invalid content or empty slot name.
     */
    public function register(string $slotName, $content, int $priority = 10): void
    {
        if ($slotName === '') {
            throw new ValidationException('Slot name cannot be empty.');
        }
        if (!is_string($content) && !is_callable($content)) {
            throw new ValidationException(
                "Slot '{$slotName}' content must be string or callable, got "
                . (is_object($content) ? get_class($content) : gettype($content)) . '.'
            );
        }
        if (!isset($this->slots[$slotName])) {
            $this->slots[$slotName] = [];
        }
        $this->slots[$slotName][] = [$priority, $this->sequence++, $content];
    }

    /**
     * Render all contributions of a slot, concatenated.
     *
     * Callables are invoked with $context; the return value is cast to
     * string. Non-scalar returns (e.g. arrays, objects without __toString)
     * are dropped and an error entry appended in debug mode — but for a
     * production library we silently coerce via (string) cast to avoid
     * fatal on __toString-less objects.
     *
     * @param array<string,mixed> $context
     */
    public function render(string $slotName, array $context = []): string
    {
        if (!isset($this->slots[$slotName]) || $this->slots[$slotName] === []) {
            return '';
        }

        $entries = $this->slots[$slotName];
        // Sort by priority ASC, then by sequence ASC to preserve registration order.
        usort($entries, static function ($a, $b) {
            if ($a[0] === $b[0]) {
                return $a[1] <=> $b[1];
            }
            return $a[0] <=> $b[0];
        });

        $out = '';
        foreach ($entries as $entry) {
            $content = $entry[2];
            if (is_string($content)) {
                $out .= $content;
                continue;
            }
            // Callable.
            $result = call_user_func($content, $context);
            if (is_string($result)) {
                $out .= $result;
            } elseif (is_scalar($result)) {
                $out .= (string) $result;
            } elseif (is_object($result) && method_exists($result, '__toString')) {
                $out .= (string) $result;
            }
            // else: null/array/object w/o __toString — drop silently.
        }
        return $out;
    }

    public function hasSlot(string $slotName): bool
    {
        return isset($this->slots[$slotName]) && $this->slots[$slotName] !== [];
    }

    /**
     * @return array<int,string>
     */
    public function getSlotNames(): array
    {
        return array_keys($this->slots);
    }

    /**
     * Remove all contributions from a slot.
     */
    public function clear(string $slotName): void
    {
        unset($this->slots[$slotName]);
    }
}
