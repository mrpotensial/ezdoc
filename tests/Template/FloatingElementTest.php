<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Template;

use Ezdoc\Exceptions\ValidationException;
use Ezdoc\Template\FloatingElement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Ezdoc\Template\FloatingElement value object.
 *
 * PHP 7.4+ compatible.
 */
final class FloatingElementTest extends TestCase
{
    public function testConstructValidLogo(): void
    {
        $el = new FloatingElement(
            'logo_hospital',
            FloatingElement::TYPE_LOGO,
            400,
            100,
            FloatingElement::Z_FRONT,
            '80px',
            []
        );

        $this->assertSame('logo_hospital', $el->id);
        $this->assertSame('logo', $el->type);
        $this->assertSame(400, $el->positionX);
        $this->assertSame(100, $el->positionY);
        $this->assertSame('front', $el->zIndex);
        $this->assertSame('80px', $el->width);
        $this->assertSame([], $el->data);
    }

    public function testConstructInvalidTypeThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid floating element type/');

        new FloatingElement('x', 'invalid_type', 0, 0, 'front', '80px');
    }

    public function testConstructInvalidZIndexThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Invalid z_index/');

        new FloatingElement('x', 'logo', 0, 0, 'invalid_z', '80px');
    }

    public function testConstructEmptyIdThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/id cannot be empty/');

        new FloatingElement('', 'logo', 0, 0, 'front', '80px');
    }

    public function testFromArrayValidData(): void
    {
        $el = FloatingElement::fromArray([
            'id' => 'ttd_dokter',
            'type' => 'ttd',
            'position_x' => 500,
            'position_y' => 800,
            'z_index' => 'behind',
            'width' => '120px',
            'data' => ['label' => 'Doctor', 'nama_field' => 'nama_dokter'],
        ]);

        $this->assertSame('ttd_dokter', $el->id);
        $this->assertSame('ttd', $el->type);
        $this->assertSame(500, $el->positionX);
        $this->assertSame(800, $el->positionY);
        $this->assertSame('behind', $el->zIndex);
        $this->assertSame(['label' => 'Doctor', 'nama_field' => 'nama_dokter'], $el->data);
    }

    public function testFromArrayDefaultsForMissingKeys(): void
    {
        // Missing z_index → default 'front'; missing width → default '80px'
        $el = FloatingElement::fromArray([
            'id' => 'x',
            'type' => 'logo',
            'position_x' => 0,
            'position_y' => 0,
        ]);

        $this->assertSame('front', $el->zIndex);
        $this->assertSame('80px', $el->width);
        $this->assertSame([], $el->data);
    }

    public function testToArrayRoundTrip(): void
    {
        $original = new FloatingElement(
            'qr_verify',
            FloatingElement::TYPE_QR,
            300,
            600,
            FloatingElement::Z_FRONT,
            '60px',
            ['pattern' => '{verify_url}']
        );

        $arr = $original->toArray();
        $restored = FloatingElement::fromArray($arr);

        $this->assertSame($original->id, $restored->id);
        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->positionX, $restored->positionX);
        $this->assertSame($original->positionY, $restored->positionY);
        $this->assertSame($original->zIndex, $restored->zIndex);
        $this->assertSame($original->width, $restored->width);
        $this->assertSame($original->data, $restored->data);
    }

    public function testWithPositionReturnsImmutableCopy(): void
    {
        $original = new FloatingElement('x', 'logo', 100, 200, 'front', '80px');
        $moved = $original->withPosition(500, 700);

        // Original unchanged
        $this->assertSame(100, $original->positionX);
        $this->assertSame(200, $original->positionY);

        // Copy dgn new position
        $this->assertSame(500, $moved->positionX);
        $this->assertSame(700, $moved->positionY);

        // Other fields preserved
        $this->assertSame($original->id, $moved->id);
        $this->assertSame($original->type, $moved->type);
        $this->assertSame($original->zIndex, $moved->zIndex);

        // Different instance
        $this->assertNotSame($original, $moved);
    }

    public function testAllValidTypes(): void
    {
        foreach ([
            FloatingElement::TYPE_LOGO,
            FloatingElement::TYPE_TTD,
            FloatingElement::TYPE_QR,
            FloatingElement::TYPE_MATERAI,
        ] as $type) {
            $el = new FloatingElement('x', $type, 0, 0, 'front', '80px');
            $this->assertSame($type, $el->type);
        }
    }

    public function testAllValidZIndexes(): void
    {
        foreach ([FloatingElement::Z_FRONT, FloatingElement::Z_BEHIND] as $z) {
            $el = new FloatingElement('x', 'logo', 0, 0, $z, '80px');
            $this->assertSame($z, $el->zIndex);
        }
    }
}
