<?php

declare(strict_types=1);

namespace Ezdoc\Tests\UI;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\UI\SlotRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Ezdoc\UI\SlotRegistry.
 *
 * Focus:
 * - Register + render basic (string + callable content)
 * - Priority ordering + registration-order ties
 * - Empty slot returns empty string
 * - hasSlot / clear semantics
 * - Alias resolution (v1.0 slot rename backward-compat)
 *
 * PHP 7.4+ compatible.
 */
final class SlotRegistryTest extends TestCase
{
    // ─── Basic register + render ────────────────────────────────────────

    public function testEmptySlotRendersEmpty(): void
    {
        $reg = new SlotRegistry();
        $this->assertSame('', $reg->render('unknown:slot'));
    }

    public function testRegisterStringAndRender(): void
    {
        $reg = new SlotRegistry();
        $reg->register('nav:extra', '<li>Extra</li>');
        $this->assertSame('<li>Extra</li>', $reg->render('nav:extra'));
    }

    public function testRegisterCallableReceivesContext(): void
    {
        $reg = new SlotRegistry();
        $reg->register('greet:main', function (array $ctx) {
            return 'Hello ' . ($ctx['name'] ?? 'world');
        });
        $this->assertSame('Hello Alice', $reg->render('greet:main', ['name' => 'Alice']));
    }

    public function testRegisterCallableWithoutContext(): void
    {
        $reg = new SlotRegistry();
        $reg->register('nav:extra', function () { return 'static'; });
        $this->assertSame('static', $reg->render('nav:extra'));
    }

    // ─── Priority + registration order ─────────────────────────────────

    public function testMultipleEntriesConcatenatedInPriorityOrder(): void
    {
        $reg = new SlotRegistry();
        $reg->register('nav:extra', 'C', 30);
        $reg->register('nav:extra', 'A', 10);
        $reg->register('nav:extra', 'B', 20);
        $this->assertSame('ABC', $reg->render('nav:extra'));
    }

    public function testTiesResolveByRegistrationOrder(): void
    {
        $reg = new SlotRegistry();
        $reg->register('nav:extra', 'first', 10);
        $reg->register('nav:extra', 'second', 10);
        $reg->register('nav:extra', 'third', 10);
        $this->assertSame('firstsecondthird', $reg->render('nav:extra'));
    }

    // ─── Validation ─────────────────────────────────────────────────────

    public function testRegisterEmptySlotNameThrows(): void
    {
        $reg = new SlotRegistry();
        $this->expectException(ValidationException::class);
        $reg->register('', 'x');
    }

    public function testRegisterInvalidContentThrows(): void
    {
        $reg = new SlotRegistry();
        $this->expectException(ValidationException::class);
        /** @phpstan-ignore-next-line — deliberate wrong type */
        $reg->register('nav:extra', 12345);
    }

    // ─── hasSlot / clear ────────────────────────────────────────────────

    public function testHasSlotReturnsFalseUntilRegistered(): void
    {
        $reg = new SlotRegistry();
        $this->assertFalse($reg->hasSlot('nav:extra'));
        $reg->register('nav:extra', 'x');
        $this->assertTrue($reg->hasSlot('nav:extra'));
    }

    public function testClearRemovesAllEntries(): void
    {
        $reg = new SlotRegistry();
        $reg->register('nav:extra', 'A');
        $reg->register('nav:extra', 'B');
        $reg->clear('nav:extra');
        $this->assertFalse($reg->hasSlot('nav:extra'));
        $this->assertSame('', $reg->render('nav:extra'));
    }

    // ─── Alias (v1.0 slot rename backward-compat) ───────────────────────

    public function testAliasForwardsRegistration(): void
    {
        $reg = new SlotRegistry();
        // Old name 'designer:list-header-extra' → canonical 'template_list:header-extra'
        $reg->alias('designer:list-header-extra', 'template_list:header-extra');

        // Consumer registers against OLD name
        $reg->register('designer:list-header-extra', 'legacy-entry');

        // Both names should resolve to same storage
        $this->assertSame('legacy-entry', $reg->render('designer:list-header-extra'));
        $this->assertSame('legacy-entry', $reg->render('template_list:header-extra'));
    }

    public function testAliasForwardsRegistrationWhenNewNameUsed(): void
    {
        $reg = new SlotRegistry();
        $reg->alias('designer:list-header-extra', 'template_list:header-extra');

        // Register against NEW canonical name
        $reg->register('template_list:header-extra', 'canonical-entry');

        // OLD name (via alias) should also see it
        $this->assertSame('canonical-entry', $reg->render('designer:list-header-extra'));
        $this->assertSame('canonical-entry', $reg->render('template_list:header-extra'));
    }

    public function testAliasMixOldAndNewNames(): void
    {
        $reg = new SlotRegistry();
        $reg->alias('designer:list-header-extra', 'template_list:header-extra');

        $reg->register('designer:list-header-extra', 'A', 10);
        $reg->register('template_list:header-extra', 'B', 20);

        // Both entries appear (in priority order)
        $this->assertSame('AB', $reg->render('template_list:header-extra'));
        $this->assertSame('AB', $reg->render('designer:list-header-extra'));
    }

    public function testAliasHasSlotWorksBidirectionally(): void
    {
        $reg = new SlotRegistry();
        $reg->alias('designer:list-header-extra', 'template_list:header-extra');

        $reg->register('designer:list-header-extra', 'x');

        $this->assertTrue($reg->hasSlot('designer:list-header-extra'));
        $this->assertTrue($reg->hasSlot('template_list:header-extra'));
    }

    public function testAliasClearRemovesEntriesFromBoth(): void
    {
        $reg = new SlotRegistry();
        $reg->alias('designer:list-header-extra', 'template_list:header-extra');
        $reg->register('designer:list-header-extra', 'x');

        // Clear via OLD name
        $reg->clear('designer:list-header-extra');

        $this->assertFalse($reg->hasSlot('template_list:header-extra'));
        $this->assertSame('', $reg->render('template_list:header-extra'));
    }

    public function testAliasChainResolution(): void
    {
        // v1 → v2 → v3 chain
        $reg = new SlotRegistry();
        $reg->alias('slot:v1', 'slot:v2');
        $reg->alias('slot:v2', 'slot:v3');

        $reg->register('slot:v1', 'chained');

        // All three names resolve to slot:v3 storage
        $this->assertSame('chained', $reg->render('slot:v1'));
        $this->assertSame('chained', $reg->render('slot:v2'));
        $this->assertSame('chained', $reg->render('slot:v3'));
    }

    public function testAliasCycleDoesNotInfiniteLoop(): void
    {
        // Defensive: pathological config (cycle)
        $reg = new SlotRegistry();
        $reg->alias('slot:a', 'slot:b');
        $reg->alias('slot:b', 'slot:a');

        // Should not hang. Empty slot returns empty string.
        $this->assertSame('', $reg->render('slot:a'));
    }

    public function testAliasEmptyNameThrows(): void
    {
        $reg = new SlotRegistry();
        $this->expectException(ValidationException::class);
        $reg->alias('', 'canonical');
    }

    public function testAliasIdempotent(): void
    {
        $reg = new SlotRegistry();
        $reg->alias('old', 'new');
        $reg->alias('old', 'new'); // no-op
        $reg->register('old', 'x');
        $this->assertSame('x', $reg->render('new'));
    }
}
