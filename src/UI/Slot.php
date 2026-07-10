<?php

declare(strict_types=1);

namespace Ezdoc\UI;

/**
 * Ezdoc\UI\Slot — static facade over a default {@see SlotRegistry}.
 *
 * Purpose: let plain PHP view files render extension points without
 * dragging a registry instance through view scope:
 *
 * ```php
 * <?= \Ezdoc\UI\Slot::render('designer:sidebar-extra', ['docId' => 42]) ?>
 * ```
 *
 * Consumers wiring their own registry (e.g. from a DI container) call
 * {@see self::setRegistry()} once at bootstrap. If left unset, a
 * lazy-initialized empty registry is used.
 *
 * PHP 7.4+ compatible.
 */
final class Slot
{
    /** @var SlotRegistry|null */
    private static $registry = null;

    /**
     * Not instantiable — pure static facade.
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Override the default registry (e.g. inject one built by a DI container).
     */
    public static function setRegistry(SlotRegistry $registry): void
    {
        self::$registry = $registry;
    }

    /**
     * Get the default registry, lazily creating an empty one on first call.
     */
    public static function getRegistry(): SlotRegistry
    {
        if (self::$registry === null) {
            self::$registry = new SlotRegistry();
        }
        return self::$registry;
    }

    /**
     * Render a slot via the default registry.
     *
     * @param array<string,mixed> $context
     */
    public static function render(string $name, array $context = []): string
    {
        return self::getRegistry()->render($name, $context);
    }

    /**
     * Reset the default registry (primarily for tests).
     */
    public static function reset(): void
    {
        self::$registry = null;
    }
}
