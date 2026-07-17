<?php

declare(strict_types=1);

namespace Ezdoc\Template;

use Ezdoc\Exceptions\ValidationException;
use InvalidArgumentException;

/**
 * Floating element value object — position-anchored non-flow element.
 *
 * Represents logo/TTD/QR/materai floating variants yg dulu di-embed langsung
 * di HTML content, sekarang di-store sebagai sidecar metadata JSON terpisah.
 *
 * ## Design
 *
 * Immutable value object (per Ezdoc convention). PHP 7.4-compatible (no
 * readonly keyword). Construct via factory methods {@see fromArray()}, sanitized
 * output via {@see toArray()}.
 *
 * ## Precedent
 *
 * Modeled after industry-standard document object models:
 * - **MS Office OOXML** `<w:drawing>` — floating positioned drawing outside `<w:t>` text runs
 * - **Google Docs API** `EmbeddedDrawing` object — position + rendered content decoupled dari text stream
 * - **Figma REST API** `NODE` types (RECTANGLE, TEXT, IMAGE) — absolute position + parent-relative layout
 * - **Prosemirror Node schema** — `isBlock`, `isAtom` types
 *
 * ## Schema
 *
 * Serialized as JSON array elements di `ezdoc_templates.floating_elements` /
 * `ezdoc_documents.floating_elements` column:
 *
 * ```json
 * {
 *   "id": "logo_hospital",
 *   "type": "logo",
 *   "position_x": 400,
 *   "position_y": 100,
 *   "z_index": "front",
 *   "width": "80px",
 *   "data": { "additional": "type-specific properties" }
 * }
 * ```
 *
 * @see \Ezdoc\Template\FloatingExtractor Untuk extraction dari HTML content.
 */
final class FloatingElement
{
    public const TYPE_LOGO = 'logo';
    public const TYPE_TTD = 'ttd';
    public const TYPE_QR = 'qr';
    public const TYPE_MATERAI = 'materai';

    public const Z_FRONT = 'front';
    public const Z_BEHIND = 'behind';

    private const VALID_TYPES = [
        self::TYPE_LOGO,
        self::TYPE_TTD,
        self::TYPE_QR,
        self::TYPE_MATERAI,
    ];

    private const VALID_Z_INDEX = [
        self::Z_FRONT,
        self::Z_BEHIND,
    ];

    /** @var string */
    public $id;

    /** @var string One of TYPE_* constants */
    public $type;

    /** @var int X coordinate in px from .page top-left */
    public $positionX;

    /** @var int Y coordinate in px from .page top-left */
    public $positionY;

    /** @var string One of Z_* constants */
    public $zIndex;

    /** @var string Width value dgn unit (mis. "80px", "60mm") */
    public $width;

    /** @var array<string, mixed> Type-specific additional properties */
    public $data;

    /**
     * @param array<string, mixed> $data Type-specific properties (mis. logo name,
     *                                   TTD label + nama_field, QR data pattern)
     */
    public function __construct(
        string $id,
        string $type,
        int $positionX,
        int $positionY,
        string $zIndex,
        string $width,
        array $data = []
    ) {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new ValidationException(
                sprintf('Invalid floating element type "%s". Expected one of: %s',
                    $type, implode(', ', self::VALID_TYPES))
            );
        }
        if (!in_array($zIndex, self::VALID_Z_INDEX, true)) {
            throw new ValidationException(
                sprintf('Invalid z_index "%s". Expected one of: %s',
                    $zIndex, implode(', ', self::VALID_Z_INDEX))
            );
        }
        if ($id === '') {
            throw new ValidationException('FloatingElement id cannot be empty');
        }

        $this->id = $id;
        $this->type = $type;
        $this->positionX = $positionX;
        $this->positionY = $positionY;
        $this->zIndex = $zIndex;
        $this->width = $width;
        $this->data = $data;
    }

    /**
     * Deserialize dari array (JSON row).
     *
     * @param array<string, mixed> $arr
     */
    public static function fromArray(array $arr): self
    {
        return new self(
            (string) ($arr['id'] ?? ''),
            (string) ($arr['type'] ?? ''),
            (int) ($arr['position_x'] ?? 0),
            (int) ($arr['position_y'] ?? 0),
            (string) ($arr['z_index'] ?? self::Z_FRONT),
            (string) ($arr['width'] ?? '80px'),
            is_array($arr['data'] ?? null) ? $arr['data'] : []
        );
    }

    /**
     * Serialize ke array (untuk JSON encode).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'position_x' => $this->positionX,
            'position_y' => $this->positionY,
            'z_index' => $this->zIndex,
            'width' => $this->width,
            'data' => $this->data,
        ];
    }

    /**
     * Immutable copy dgn position updated.
     */
    public function withPosition(int $x, int $y): self
    {
        return new self(
            $this->id,
            $this->type,
            $x,
            $y,
            $this->zIndex,
            $this->width,
            $this->data
        );
    }
}
