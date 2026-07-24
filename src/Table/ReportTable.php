<?php

declare(strict_types=1);

namespace Ezdoc\Table;

use Ezdoc\Exceptions\ValidationException;

/**
 * Ezdoc\Table\ReportTable — Excel/Jasper-style report table renderer.
 *
 * Produces deterministic HTML output dgn typed column formatting,
 * per-row computed columns (formula), optional group-by subtotals,
 * dan grand-total footer.
 *
 * Server-side render only — PDF-safe (dompdf compatible), print-safe,
 * screen-safe. No JS. `<tfoot>` semantic ensures totals repeated per
 * page saat table cross paper boundary (browser + PDF native behavior).
 *
 * ## Usage
 *
 * ```php
 * $table = new ReportTable(
 *     [
 *         ColumnDef::fromArray(['field' => 'nama', 'label' => 'Item']),
 *         ColumnDef::fromArray(['field' => 'qty', 'type' => 'number']),
 *         ColumnDef::fromArray(['field' => 'harga', 'type' => 'currency', 'aggregate' => 'sum']),
 *         ColumnDef::fromArray([
 *             'field' => 'total', 'type' => 'currency',
 *             'formula' => '{qty} * {harga}', 'aggregate' => 'sum'
 *         ]),
 *     ],
 *     $rowsFromSql, // array<array<string,mixed>>
 *     'kategori',   // groupBy field (optional)
 *     true          // showGrandTotal (optional, default true)
 * );
 * echo $table->render();
 * ```
 *
 * ## Aggregate semantics
 *
 * - SUM/AVG/MIN/MAX: skip null values (missing formula fields, empty
 *   cells, non-numeric data). AVG divisor = non-null count.
 * - COUNT: count of non-null values (works untuk semua types).
 * - Empty rows → empty aggregate result → footer shows `-` (dash)
 *   instead of confusing `0.00`.
 *
 * ## Group-by
 *
 * - Simple: pass field name as string → subtotal per group value
 * - Detailed: pass `['field' => 'kategori', 'showSubtotal' => true]`
 * - Rows assumed sorted by group field (caller responsibility — put
 *   ORDER BY di SQL). Renderer tidak re-sort.
 * - Subtotal row inserted saat group value changes
 * - Multi-level nested groupBy defer ke milestone berikutnya
 *
 * ## HTML structure
 *
 * ```html
 * <table class="dyntable-rendered">
 *   <thead>
 *     <tr><th>...</th></tr>
 *   </thead>
 *   <tbody>
 *     <tr><td>...</td></tr>  <!-- row -->
 *     <tr class="dyntable-subtotal"><td colspan="X">Subtotal: value</td>...</tr>
 *   </tbody>
 *   <tfoot>
 *     <tr class="dyntable-grand-total"><td>Total</td><td>...</td></tr>
 *   </tfoot>
 * </table>
 * ```
 *
 * PHP 7.4+ compatible.
 */
final class ReportTable
{
    /** @var array<ColumnDef> */
    private $columns;

    /** @var array<array<string,mixed>> raw rows from SQL */
    private $rows;

    /** @var string|null field name untuk groupBy */
    private $groupByField;

    /** @var bool */
    private $showSubtotal;

    /** @var bool */
    private $showGrandTotal;

    /** @var string */
    private $emptyMessage;

    /** @var string */
    private $locale;

    /** @var string CSS class untuk table element */
    private $tableClass;

    /**
     * @param ColumnDef[]                  $columns
     * @param array<array<string,mixed>>   $rows           each row = SQL result assoc array
     * @param string|array<string,mixed>|null $groupBy    field name atau {field, showSubtotal}
     * @param bool                         $showGrandTotal render `<tfoot>` dgn grand total (default true)
     * @param string                       $emptyMessage   ditampilkan kalau rows kosong (default 'Belum ada data')
     * @param string                       $locale         formatter locale (default 'id')
     * @param string                       $tableClass     CSS class table (default 'dyntable-rendered')
     * @throws ValidationException
     */
    public function __construct(
        array $columns,
        array $rows,
        $groupBy = null,
        bool $showGrandTotal = true,
        string $emptyMessage = '',
        string $locale = 'id',
        string $tableClass = 'dyntable-rendered'
    ) {
        if (empty($columns)) {
            throw ValidationException::forField('columns', 'must have at least 1 column');
        }
        foreach ($columns as $i => $c) {
            if (!$c instanceof ColumnDef) {
                throw ValidationException::forField(
                    "columns[$i]",
                    'must be ColumnDef instance'
                );
            }
        }
        $this->columns = array_values($columns);
        $this->rows = $rows;

        // Normalize groupBy
        $this->groupByField = null;
        $this->showSubtotal = true;
        if (is_string($groupBy) && $groupBy !== '') {
            $this->groupByField = $groupBy;
        } elseif (is_array($groupBy) && isset($groupBy['field']) && is_string($groupBy['field']) && $groupBy['field'] !== '') {
            $this->groupByField = $groupBy['field'];
            if (isset($groupBy['showSubtotal'])) {
                $this->showSubtotal = (bool) $groupBy['showSubtotal'];
            }
        }

        $this->showGrandTotal = $showGrandTotal;
        $this->emptyMessage = $emptyMessage !== '' ? $emptyMessage : 'Belum ada data';
        $this->locale = $locale;
        $this->tableClass = $tableClass;
    }

    /**
     * Render complete `<table>` HTML.
     */
    public function render(): string
    {
        $html = '<table class="' . Formatter::escapeHtml($this->tableClass) . '">';
        $html .= $this->renderThead();

        if (empty($this->rows)) {
            $html .= $this->renderEmptyBody();
        } else {
            $html .= $this->renderTbody();
            if ($this->showGrandTotal && $this->hasAnyAggregate()) {
                $html .= $this->renderTfoot();
            }
        }

        $html .= '</table>';
        return $html;
    }

    private function renderThead(): string
    {
        $html = '<thead><tr>';
        foreach ($this->columns as $col) {
            $html .= '<th style="text-align:' . $col->getAlign() . '">'
                . Formatter::escapeHtml($col->getLabel())
                . '</th>';
        }
        $html .= '</tr></thead>';
        return $html;
    }

    private function renderEmptyBody(): string
    {
        $colspan = count($this->columns);
        return '<tbody><tr><td colspan="' . $colspan . '" class="dyntable-empty">'
            . Formatter::escapeHtml($this->emptyMessage)
            . '</td></tr></tbody>';
    }

    private function renderTbody(): string
    {
        $html = '<tbody>';

        // Group accumulator: values per column (indexed by field) → list of numeric values
        $groupAccum = $this->newAggAccumulator();
        $prevGroupValue = null;
        $groupHasRows = false;

        foreach ($this->rows as $row) {
            // Compute formula values first (dan tambahkan ke row supaya bisa
            // dipakai as formula input dari kolom lain di same row)
            $enrichedRow = $this->enrichRowWithFormulas($row);

            // Group change detection
            if ($this->groupByField !== null) {
                $groupValue = isset($enrichedRow[$this->groupByField])
                    ? (string) $enrichedRow[$this->groupByField]
                    : '';
                if ($prevGroupValue !== null && $groupValue !== $prevGroupValue && $groupHasRows) {
                    // Emit subtotal for previous group
                    if ($this->showSubtotal) {
                        $html .= $this->renderSubtotalRow($prevGroupValue, $groupAccum);
                    }
                    $groupAccum = $this->newAggAccumulator();
                }
                $prevGroupValue = $groupValue;
                $groupHasRows = true;
            }

            $html .= $this->renderDataRow($enrichedRow);

            // Accumulate for group + grand total
            $this->accumulateRow($enrichedRow, $groupAccum);
        }

        // Emit final group subtotal
        if ($this->groupByField !== null && $this->showSubtotal && $groupHasRows) {
            $html .= $this->renderSubtotalRow($prevGroupValue, $groupAccum);
        }

        $html .= '</tbody>';
        return $html;
    }

    /**
     * Enrich row dgn formula-computed values. Formula columns baca dari row +
     * fields yg sudah dihitung (dukung chained formulas asalkan urutan
     * kolom di config sudah benar — column dgn formula ref column lain
     * harus setelah kolom yg di-ref).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function enrichRowWithFormulas(array $row): array
    {
        foreach ($this->columns as $col) {
            if (!$col->hasFormula()) continue;
            $formula = $col->getFormula();
            // @phpstan-ignore-next-line — formula non-null guaranteed by hasFormula
            $result = FormulaEvaluator::evaluate($formula, $row);
            $row[$col->getField()] = $result;
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function renderDataRow(array $row): string
    {
        $html = '<tr>';
        foreach ($this->columns as $col) {
            $value = $row[$col->getField()] ?? null;
            $formatted = Formatter::format($value, $col->getType(), $col->getFormatOptions($this->locale));
            $html .= '<td style="text-align:' . $col->getAlign() . '">' . $formatted . '</td>';
        }
        $html .= '</tr>';
        return $html;
    }

    /**
     * Render subtotal row untuk group. Cells non-aggregate kosong except
     * cell pertama yg tampil label "Subtotal: {groupValue}".
     *
     * @param string                      $groupValue
     * @param array<string,array<float>>  $accum       aggregate accumulator
     */
    private function renderSubtotalRow(string $groupValue, array $accum): string
    {
        $html = '<tr class="dyntable-subtotal" style="font-weight:bold;background:#f5f5f5;">';
        $labelShown = false;
        foreach ($this->columns as $col) {
            $align = $col->getAlign();
            if ($col->hasAggregate()) {
                $aggValue = $this->computeAggregate($col->getAggregate(), $accum[$col->getField()] ?? []);
                $formatted = $aggValue === null
                    ? '-'
                    : Formatter::format($aggValue, $col->getType(), $col->getFormatOptions($this->locale));
                $html .= '<td style="text-align:' . $align . '">' . $formatted . '</td>';
                continue;
            }
            if (!$labelShown) {
                // First non-aggregate cell = subtotal label
                $html .= '<td style="text-align:left"><em>Subtotal ' . Formatter::escapeHtml($groupValue) . ':</em></td>';
                $labelShown = true;
                continue;
            }
            $html .= '<td style="text-align:' . $align . '"></td>';
        }
        $html .= '</tr>';
        return $html;
    }

    private function renderTfoot(): string
    {
        // Recompute grand total dari SEMUA rows (bukan dari group accum yg
        // sudah di-reset per group).
        $grandAccum = $this->newAggAccumulator();
        foreach ($this->rows as $row) {
            $enriched = $this->enrichRowWithFormulas($row);
            $this->accumulateRow($enriched, $grandAccum);
        }

        $html = '<tfoot><tr class="dyntable-grand-total" style="font-weight:bold;border-top:2px solid #333;background:#eeeeee;">';
        $labelShown = false;
        foreach ($this->columns as $col) {
            $align = $col->getAlign();
            if ($col->hasAggregate()) {
                $aggValue = $this->computeAggregate($col->getAggregate(), $grandAccum[$col->getField()] ?? []);
                $formatted = $aggValue === null
                    ? '-'
                    : Formatter::format($aggValue, $col->getType(), $col->getFormatOptions($this->locale));
                $html .= '<td style="text-align:' . $align . '"><strong>' . $formatted . '</strong></td>';
                continue;
            }
            if (!$labelShown) {
                $html .= '<td style="text-align:left"><strong>TOTAL</strong></td>';
                $labelShown = true;
                continue;
            }
            $html .= '<td style="text-align:' . $align . '"></td>';
        }
        $html .= '</tr></tfoot>';
        return $html;
    }

    // ─── Aggregate machinery ───

    /**
     * @return array<string,array<float>> field name → list of numeric values
     */
    private function newAggAccumulator(): array
    {
        $accum = [];
        foreach ($this->columns as $col) {
            if ($col->hasAggregate()) {
                $accum[$col->getField()] = [];
            }
        }
        return $accum;
    }

    /**
     * @param array<string,mixed>         $row
     * @param array<string,array<float>>  $accum reference
     */
    private function accumulateRow(array $row, array &$accum): void
    {
        foreach ($this->columns as $col) {
            if (!$col->hasAggregate()) continue;
            $val = $row[$col->getField()] ?? null;
            if ($val === null || $val === '') continue;
            if ($col->getAggregate() === ColumnDef::AGG_COUNT) {
                // COUNT: any non-null counts (even string), store dummy 1
                $accum[$col->getField()][] = 1.0;
                continue;
            }
            // SUM/AVG/MIN/MAX need numeric
            if (!is_numeric($val)) continue;
            $accum[$col->getField()][] = (float) $val;
        }
    }

    /**
     * @param string       $type   AGG_* constant
     * @param array<float> $values
     * @return float|null null kalau values kosong (empty → no aggregate)
     */
    private function computeAggregate(string $type, array $values): ?float
    {
        if (empty($values)) return null;
        switch ($type) {
            case ColumnDef::AGG_SUM:   return array_sum($values);
            case ColumnDef::AGG_COUNT: return (float) count($values);
            case ColumnDef::AGG_AVG:   return array_sum($values) / count($values);
            case ColumnDef::AGG_MIN:   return (float) min($values);
            case ColumnDef::AGG_MAX:   return (float) max($values);
        }
        return null;
    }

    private function hasAnyAggregate(): bool
    {
        foreach ($this->columns as $col) {
            if ($col->hasAggregate()) return true;
        }
        return false;
    }

    // ─── Factory ───

    /**
     * Build ReportTable dari schema JSON assoc array (typical usage:
     * deserialize dari template config).
     *
     * Schema shape:
     * ```
     * [
     *   'columns' => [<ColumnDef schema>, ...],
     *   'rows' => [<row>, ...],
     *   'groupBy' => 'field' | ['field' => '...', 'showSubtotal' => true],
     *   'showGrandTotal' => true,
     *   'emptyMessage' => '...',
     *   'locale' => 'id',
     * ]
     * ```
     *
     * @param array<string,mixed> $schema
     * @throws ValidationException
     */
    public static function fromArray(array $schema): self
    {
        if (!isset($schema['columns']) || !is_array($schema['columns'])) {
            throw ValidationException::forField('schema.columns', 'required array');
        }
        $columns = [];
        foreach ($schema['columns'] as $i => $colData) {
            if (!is_array($colData)) {
                throw ValidationException::forField("schema.columns[$i]", 'must be array');
            }
            $columns[] = ColumnDef::fromArray($colData);
        }
        $rows = isset($schema['rows']) && is_array($schema['rows']) ? $schema['rows'] : [];

        return new self(
            $columns,
            $rows,
            $schema['groupBy'] ?? null,
            isset($schema['showGrandTotal']) ? (bool) $schema['showGrandTotal'] : true,
            isset($schema['emptyMessage']) ? (string) $schema['emptyMessage'] : '',
            isset($schema['locale']) ? (string) $schema['locale'] : 'id',
            isset($schema['tableClass']) ? (string) $schema['tableClass'] : 'dyntable-rendered'
        );
    }
}
