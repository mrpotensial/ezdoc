<?php
/**
 * Add `floating_elements` JSON column ke `ezdoc_templates` + `ezdoc_documents`.
 *
 * ## Sidecar metadata pattern (v0.9.12)
 *
 * Floating elements (logo/TTD/QR/materai floating variants) sebelumnya embedded
 * langsung di HTML content column. Sekarang di-store sebagai sidecar JSON metadata
 * terpisah — memungkinkan:
 *
 * - Editor content clean (no floating markers → no empty line bugs, no accidental
 *   deletion)
 * - Semantic separation antara text flow (HTML) dan positioned overlays (JSON)
 * - Cross-format portability (JSON schema universal, HTML markers specific ke
 *   PHP+dompdf pipeline)
 *
 * ## Backward-compat migration strategy
 *
 * Column added dgn NULL default. Existing rows: floating markers TETAP di HTML
 * content column (backward-compat, no data destruction). Save flow (v0.9.12
 * onwards) writes BOTH:
 * - Extracted floating → JSON column
 * - Cleaned HTML (no floating markers) → content column
 *
 * Read flow prioritizes JSON column; falls back to HTML-embedded parsing kalau
 * JSON NULL (legacy row).
 *
 * Migration data extraction (populate JSON dari existing HTML) di-run sebagai
 * separate optional migration step — tidak destructive.
 *
 * ## Precedent
 *
 * Sidecar column pattern digunakan di:
 * - **PostgreSQL JSONB columns** for metadata extension without schema migration
 * - **MongoDB embedded documents** for hierarchical structured data
 * - **Doctrine `serialized` type** untuk arbitrary object storage
 * - **Laravel model casts to array/collection** on JSON columns
 *
 * spec: docs/FLOATING-ELEMENTS.md
 */

return [
    'name' => '2026_07_16_000001_alter_ezdoc_templates_add_floating_elements',
    'up' => function ($conn): void {
        // Add ke ezdoc_templates
        $conn->query("
            ALTER TABLE ezdoc_templates
            ADD COLUMN floating_elements JSON NULL
            COMMENT 'Sidecar metadata JSON array: floating logo/TTD/QR/materai dgn position + type + data. Replaces HTML-embedded markers (v0.9.12). See docs/FLOATING-ELEMENTS.md.'
            AFTER content
        ");

        // Add ke ezdoc_documents (per-doc floating positions, overrides template default)
        $conn->query("
            ALTER TABLE ezdoc_documents
            ADD COLUMN floating_elements JSON NULL
            COMMENT 'Per-doc floating elements overrides. NULL = inherit dari template.'
            AFTER signature_values
        ");
    },
];
