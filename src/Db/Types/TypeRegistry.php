<?php

declare(strict_types=1);

namespace Ezdoc\Db\Types;

use Ezdoc\Db\Exception\SchemaException;

/**
 * Ezdoc\Db\Types\TypeRegistry — static lookup canonical name → Type instance.
 *
 * Diakses Blueprint saat build ColumnDef (untuk cache Type object) dan Connection
 * saat fetch row (untuk toPhp conversion).
 *
 * Core types di-preregister via constructor. Consumer bisa `register()` custom
 * type di runtime (mis. `MoneyType`, `IpAddressType`).
 *
 * ## Kenapa registry, bukan static factory
 *
 * Custom Type dari consumer perlu bisa inject tanpa modify library source.
 * Registry pattern = per-App instance, consumer register di bootstrap.
 *
 * @implementation-notes TypeRegistry mirroring Doctrine DBAL Types::add() +
 *   Types::getType() approach. Reimplemented from spec.
 */
final class TypeRegistry
{
    /** @var array<string,Type> */
    private $types = [];

    public function __construct()
    {
        // Register core types
        $this->register(new StringType());
        $this->register(new IntegerType());
        $this->register(new BigIntType());
        $this->register(new BooleanType());
        $this->register(new JsonType());
        $this->register(new UuidType());
        $this->register(new DateTimeType());
        $this->register(new TextType());
        // EnumType TIDAK di-register default — butuh constructor param (allowed list).
        // Consumer register per-schema kalau butuh named enum shared.
    }

    /**
     * Register (atau replace) Type by canonical name.
     */
    public function register(Type $type): void
    {
        $this->types[$type->name()] = $type;
    }

    /**
     * Get Type by canonical name.
     *
     * @throws SchemaException Kalau nama tidak dikenal.
     */
    public function get(string $name): Type
    {
        if (!isset($this->types[$name])) {
            throw new SchemaException(
                "Unknown type '$name' — available: " . implode(', ', array_keys($this->types))
            );
        }
        return $this->types[$name];
    }

    /**
     * True kalau canonical name terdaftar.
     */
    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * @return array<string,Type>
     */
    public function all(): array
    {
        return $this->types;
    }
}
