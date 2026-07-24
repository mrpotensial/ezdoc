<?php

declare(strict_types=1);

namespace Ezdoc\Table;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Table\ColumnDef — immutable typed column definition untuk
 * {@see ReportTable}.
 *
 * Value object mengikat kontrak schema JSON yg template author isi
 * (via designer UI atau raw JSON). Runtime valid + fail-fast di
 * factory instead of di render time.
 *
 * ## Schema
 *
 * ```json
 * {
 *   "field": "harga",           // SQL column atau formula target
 *   "label": "Harga (Rp)",      // optional; default = field name
 *   "type": "currency",         // text|number|currency|percent|date|datetime
 *   "align": "right",           // left|right|center; default per type
 *   "aggregate": "sum",         // null|sum|count|avg|min|max
 *   "formula": "{qty}*{harga}", // optional; per-row computed value
 *   "format": "d/m/Y",          // optional; type-specific format override
 *   "decimals": 2,              // optional; type-specific precision
 *   "currency": "IDR"           // optional; only for type=currency
 * }
 * ```
 *
 * ## Type → default align mapping
 *
 * - `text` → `left`
 * - `number`, `currency`, `percent` → `right` (typography convention)
 * - `date`, `datetime` → `left`
 *
 * ## Aggregate compatibility
 *
 * - `sum`, `avg`, `min`, `max` require numeric type (number/currency/percent)
 * - `count` valid untuk semua types (counts non-null values)
 * - null (default) = no footer aggregate for this column
 *
 * PHP 7.4+ compatible. Immutable.
 */
final class ColumnDef
{
    const TYPE_TEXT = 'text';
    const TYPE_NUMBER = 'number';
    const TYPE_CURRENCY = 'currency';
    const TYPE_PERCENT = 'percent';
    const TYPE_DATE = 'date';
    const TYPE_DATETIME = 'datetime';

    const AGG_SUM = 'sum';
    const AGG_COUNT = 'count';
    const AGG_AVG = 'avg';
    const AGG_MIN = 'min';
    const AGG_MAX = 'max';

    const ALIGN_LEFT = 'left';
    const ALIGN_RIGHT = 'right';
    const ALIGN_CENTER = 'center';

    /** @var array<string> */
    private static $ALLOWED_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_CURRENCY,
        self::TYPE_PERCENT,
        self::TYPE_DATE,
        self::TYPE_DATETIME,
    ];

    /** @var array<string> */
    private static $ALLOWED_AGGS = [
        self::AGG_SUM,
        self::AGG_COUNT,
        self::AGG_AVG,
        self::AGG_MIN,
        self::AGG_MAX,
    ];

    /** @var array<string> Numeric types — aggregate math applies */
    private static $NUMERIC_TYPES = [
        self::TYPE_NUMBER,
        self::TYPE_CURRENCY,
        self::TYPE_PERCENT,
    ];

    /** @var string */
    private $field;

    /** @var string */
    private $label;

    /** @var string */
    private $type;

    /** @var string */
    private $align;

    /** @var string|null */
    private $aggregate;

    /** @var string|null */
    private $formula;

    /** @var string|null */
    private $format;

    /** @var int|null */
    private $decimals;

    /** @var string */
    private $currency;

    /**
     * @param string      $field     SQL column / formula target (non-empty)
     * @param string      $label     header text (defaults to field kalau '')
     * @param string      $type      TYPE_* constant
     * @param string|null $align     ALIGN_* atau null (auto per type)
     * @param string|null $aggregate AGG_* atau null (no footer aggregate)
     * @param string|null $formula   safe expression atau null (raw field value)
     * @param string|null $format    type-specific format override
     * @param int|null    $decimals  precision override
     * @param string      $currency  ISO 4217 code (only used kalau type=currency)
     * @throws ValidationException
     */
    public function __construct(
        string $field,
        string $label = '',
        string $type = self::TYPE_TEXT,
        ?string $align = null,
        ?string $aggregate = null,
        ?string $formula = null,
        ?string $format = null,
        ?int $decimals = null,
        string $currency = 'IDR'
    ) {
        if ($field === '') {
            throw ValidationException::forField('column.field', 'required non-empty string');
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw ValidationException::forField(
                'column.field',
                "must match [a-zA-Z_][a-zA-Z0-9_]* (got '$field')"
            );
        }
        $this->field = $field;
        $this->label = $label !== '' ? $label : $field;

        $t = strtolower($type);
        if (!in_array($t, self::$ALLOWED_TYPES, true)) {
            throw ValidationException::forField(
                'column.type',
                'must be one of: ' . implode(', ', self::$ALLOWED_TYPES)
            );
        }
        $this->type = $t;

        // Align — default per type
        if ($align === null) {
            $this->align = in_array($t, self::$NUMERIC_TYPES, true)
                ? self::ALIGN_RIGHT
                : self::ALIGN_LEFT;
        } else {
            $a = strtolower($align);
            if (!in_array($a, [self::ALIGN_LEFT, self::ALIGN_RIGHT, self::ALIGN_CENTER], true)) {
                throw ValidationException::forField(
                    'column.align',
                    'must be left|right|center'
                );
            }
            $this->align = $a;
        }

        // Aggregate validation
        if ($aggregate !== null) {
            $agg = strtolower($aggregate);
            if (!in_array($agg, self::$ALLOWED_AGGS, true)) {
                throw ValidationException::forField(
                    'column.aggregate',
                    'must be one of: ' . implode(', ', self::$ALLOWED_AGGS)
                );
            }
            // sum/avg/min/max requires numeric type; count is universal
            if ($agg !== self::AGG_COUNT && !in_array($t, self::$NUMERIC_TYPES, true)) {
                throw ValidationException::forField(
                    'column.aggregate',
                    "aggregate '$agg' requires numeric type (number|currency|percent), got '$t'"
                );
            }
            $this->aggregate = $agg;
        } else {
            $this->aggregate = null;
        }

        // Formula — validation delegated ke FormulaEvaluator::evaluate at
        // render time. Kita parse eager di sini juga supaya fail-fast
        // detected at schema-save time (designer UI).
        if ($formula !== null && $formula !== '') {
            try {
                // Parse-only trial dgn empty context — errors are structural
                // (bad syntax). Missing fields = null return, not error.
                FormulaEvaluator::evaluate($formula, []);
            } catch (ValidationException $e) {
                // Re-throw dgn field context clearer
                throw ValidationException::forField(
                    'column.formula',
                    "invalid expression: " . $e->getMessage()
                );
            }
            $this->formula = $formula;
        } else {
            $this->formula = null;
        }

        $this->format = ($format !== null && $format !== '') ? $format : null;
        $this->decimals = ($decimals !== null && $decimals >= 0) ? $decimals : null;
        $this->currency = strtoupper($currency);
    }

    public function getField(): string { return $this->field; }
    public function getLabel(): string { return $this->label; }
    public function getType(): string { return $this->type; }
    public function getAlign(): string { return $this->align; }
    public function getAggregate(): ?string { return $this->aggregate; }
    public function getFormula(): ?string { return $this->formula; }
    public function getFormat(): ?string { return $this->format; }
    public function getDecimals(): ?int { return $this->decimals; }
    public function getCurrency(): string { return $this->currency; }

    public function hasFormula(): bool { return $this->formula !== null; }
    public function hasAggregate(): bool { return $this->aggregate !== null; }
    public function isNumeric(): bool { return in_array($this->type, self::$NUMERIC_TYPES, true); }

    /**
     * Format options untuk {@see Formatter::format()} — assembled dari
     * config yg berlaku untuk type ini.
     *
     * @return array<string,mixed>
     */
    public function getFormatOptions(string $locale = 'id'): array
    {
        $opts = ['locale' => $locale];
        if ($this->type === self::TYPE_CURRENCY) {
            $opts['currency'] = $this->currency;
        }
        if ($this->format !== null) {
            $opts['format'] = $this->format;
        }
        if ($this->decimals !== null) {
            $opts['decimals'] = $this->decimals;
        }
        return $opts;
    }

    /**
     * Factory dari assoc array — untuk deserialization dari JSON schema.
     *
     * @param array<string,mixed> $data
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['field']) ? (string) $data['field'] : '',
            isset($data['label']) ? (string) $data['label'] : '',
            isset($data['type']) ? (string) $data['type'] : self::TYPE_TEXT,
            isset($data['align']) ? (string) $data['align'] : null,
            isset($data['aggregate']) && $data['aggregate'] !== '' ? (string) $data['aggregate'] : null,
            isset($data['formula']) && $data['formula'] !== '' ? (string) $data['formula'] : null,
            isset($data['format']) && $data['format'] !== '' ? (string) $data['format'] : null,
            isset($data['decimals']) && is_numeric($data['decimals']) ? (int) $data['decimals'] : null,
            isset($data['currency']) ? (string) $data['currency'] : 'IDR'
        );
    }

    /**
     * Serialize ke array (untuk JSON storage).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'field' => $this->field,
            'label' => $this->label,
            'type' => $this->type,
            'align' => $this->align,
        ];
        if ($this->aggregate !== null) $arr['aggregate'] = $this->aggregate;
        if ($this->formula !== null) $arr['formula'] = $this->formula;
        if ($this->format !== null) $arr['format'] = $this->format;
        if ($this->decimals !== null) $arr['decimals'] = $this->decimals;
        if ($this->type === self::TYPE_CURRENCY) $arr['currency'] = $this->currency;
        return $arr;
    }
}
