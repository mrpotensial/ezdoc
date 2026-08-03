<?php

declare(strict_types=1);

namespace Ezdoc\UI;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\UI\Config — consumer-facing key/value config store.
 *
 * Backing store is a plain nested array. Access is dot-notation aware
 * (`'brand.primary_color'` = `$data['brand']['primary_color']`).
 *
 * Value types supported: string, int, float, bool, array, null. Objects
 * are accepted but not persisted from file loaders.
 *
 * PHP 7.4+ compatible — no promotion, no union types.
 */
final class Config
{
    /** @var array<string,mixed> */
    private $data;

    /**
     * @param array<string,mixed> $defaults
     */
    public function __construct(array $defaults = [])
    {
        $this->data = $defaults;
    }

    /**
     * Static factory from an array.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Static factory from a PHP file that returns an array.
     *
     * @throws NotFoundException if file does not exist.
     * @throws ValidationException if file does not return array.
     */
    public static function fromFile(string $phpFile): self
    {
        if (!is_file($phpFile)) {
            throw NotFoundException::forResource('config-file', $phpFile);
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = require $phpFile;
        if (!is_array($data)) {
            throw new ValidationException(
                "Config file '{$phpFile}' must return an array, got "
                . (is_object($data) ? get_class($data) : gettype($data)) . '.'
            );
        }
        return new self($data);
    }

    /**
     * Get value by dot-notation key.
     *
     * Accepts BOTH flat and nested storage — untuk DX yang forgiving:
     *   Nested:  fromArray(['assets' => ['base_url' => 'x']])->get('assets.base_url')
     *   Flat:    fromArray(['assets.base_url' => 'x'])->get('assets.base_url')
     * Flat lookup ditest dulu (O(1)), fallback ke nested (O(depth)).
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if ($key === '') {
            return $default;
        }
        // Fast path: literal flat key match (consumers who pass ['a.b' => v])
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        if (strpos($key, '.') === false) {
            return $default;
        }
        // Fallback: dot-notation nested traversal
        $ref = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return $default;
            }
            $ref = $ref[$segment];
        }
        return $ref;
    }

    /**
     * Set value by dot-notation key. Creates intermediate arrays as needed.
     *
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        if ($key === '') {
            return;
        }
        if (strpos($key, '.') === false) {
            $this->data[$key] = $value;
            return;
        }
        $segments = explode('.', $key);
        $ref = &$this->data;
        while (count($segments) > 1) {
            $seg = (string) array_shift($segments);
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref[$segments[0]] = $value;
        unset($ref);
    }

    /**
     * True if key exists (even if value is null). Accepts flat + nested.
     */
    public function has(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (array_key_exists($key, $this->data)) {
            return true;
        }
        if (strpos($key, '.') === false) {
            return false;
        }
        $ref = $this->data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($ref) || !array_key_exists($segment, $ref)) {
                return false;
            }
            $ref = $ref[$segment];
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Recursive merge — associative keys are overwritten by $config; numeric
     * arrays are replaced (not concatenated).
     *
     * @param array<string,mixed> $config
     */
    public function merge(array $config): void
    {
        $this->data = self::deepMerge($this->data, $config);
    }

    /**
     * @param array<int|string,mixed> $base
     * @param array<int|string,mixed> $override
     * @return array<int|string,mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_string($key)
                && is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && self::isAssoc($base[$key])
                && self::isAssoc($value)
            ) {
                $base[$key] = self::deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * @param array<int|string,mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true; // treat empty as assoc for merge purposes
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
