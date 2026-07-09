<?php

declare(strict_types=1);

namespace Ezdoc\Template;

/**
 * Ezdoc\Template\ParsedTemplate — hasil parse HTML content template.
 *
 * Immutable value object berisi field-field yang di-extract dari WYSIWYG
 * markup: raw params (`{{name}}`), structured fields (typed), dan signature
 * slots (TTD placeholders).
 *
 * PHP 7.4+ compatible.
 *
 * @see TemplateParser::parse()
 */
final class ParsedTemplate
{
    /** @var array<int, array{name: string, type: string, default: string, index: int}> */
    private $fields;

    /** @var array<int, string> Names of {{param}} placeholders, dedup + order preserved */
    private $params;

    /** @var array<int, array{index: int, role: string|null, config: array<string,mixed>}> */
    private $signatureSlots;

    /**
     * @param array<int, array{name: string, type: string, default: string, index: int}> $fields
     * @param array<int, string> $params
     * @param array<int, array{index: int, role: string|null, config: array<string,mixed>}> $signatureSlots
     */
    public function __construct(array $fields, array $params, array $signatureSlots)
    {
        // Normalize keys → 0-indexed sequential (in case caller passed assoc array)
        $this->fields         = array_values($fields);
        $this->params         = array_values($params);
        $this->signatureSlots = array_values($signatureSlots);
    }

    /**
     * @return array<int, array{name: string, type: string, default: string, index: int}>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<int, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @return array<int, array{index: int, role: string|null, config: array<string,mixed>}>
     */
    public function getSignatureSlots(): array
    {
        return $this->signatureSlots;
    }

    /**
     * Just the names, for quick lookup / iteration.
     *
     * @return array<int, string>
     */
    public function getFieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $f) {
            if (isset($f['name']) && is_string($f['name'])) {
                $names[] = $f['name'];
            }
        }
        return $names;
    }

    public function hasField(string $name): bool
    {
        foreach ($this->fields as $f) {
            if (isset($f['name']) && $f['name'] === $name) return true;
        }
        return false;
    }

    /**
     * Serialize untuk JSON response (frontend / API).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fields'          => $this->fields,
            'params'          => $this->params,
            'signature_slots' => $this->signatureSlots,
        ];
    }
}
