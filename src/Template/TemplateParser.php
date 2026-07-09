<?php

declare(strict_types=1);

namespace Ezdoc\Template;

/**
 * Ezdoc\Template\TemplateParser — extract marker tokens dari HTML content.
 *
 * Stateless: instantiable (bukan static) supaya bisa di-mock di test.
 *
 * ## Marker conventions (aligned dgn save_document.php runtime)
 *
 * 1. Text placeholders  : `{{field_name}}` — double curly braces (mustache-ish).
 *    Extracted → params + fields (type=text).
 *
 * 2. QR fields          : `<... data-qr="field_name" ...>` — QR code data source.
 *    Extracted → fields (type=qr).
 *
 * 3. Materai placeholder: `<div class="... materai-placeholder ..." data-materai="ID">`.
 *    Extracted → fields (type=materai) dengan name `_materai_ID`.
 *
 * 4. Signature slots    : `<div class="... ttd-placeholder ..." data-ttd="ID"
 *    data-label="…" data-nama-field="…" data-allowed-roles="csv" data-allowed-users="csv">`.
 *    Extracted → signatureSlots (positional index + role dari data-allowed-roles).
 *
 * Rasionale regex: pattern-pattern ini di-observe dari save_document.php runtime
 * yang sudah live (bukan spek {span data-field} di brief) — jadi parser bisa
 * mem-feed data yang konsisten dgn document render pipeline yang ada.
 *
 * PHP 7.4+ compatible.
 */
final class TemplateParser
{
    public function __construct()
    {
        // Stateless — no dependencies. Instantiable for DI/testability.
    }

    /**
     * Parse HTML content → ParsedTemplate.
     *
     * De-duplication: berdasarkan `name` (fields) atau string value (params).
     * Urutan: first-appearance dalam string.
     */
    public function parse(string $contentHtml): ParsedTemplate
    {
        $params         = $this->extractParams($contentHtml);
        $signatureSlots = $this->extractSignatureSlots($contentHtml);
        $fields         = $this->buildFields($contentHtml, $params, $signatureSlots);

        return new ParsedTemplate($fields, $params, $signatureSlots);
    }

    /**
     * Extract `{{name}}` occurrences → deduplicated ordered list of names.
     *
     * @return array<int, string>
     */
    private function extractParams(string $html): array
    {
        $out = [];
        $seen = [];
        if ($html === '') return $out;

        // Align dgn runtime pattern di save_document.php: /\{\{([^}]+)\}\}/
        // (broad — trim + skip whitespace-only tokens).
        if (preg_match_all('/\{\{([^}]+)\}\}/', $html, $m)) {
            foreach ($m[1] as $name) {
                $name = trim((string) $name);
                if ($name === '' || isset($seen[$name])) continue;
                $seen[$name] = true;
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Extract TTD placeholder divs → structured slot list.
     *
     * @return array<int, array{index: int, role: string|null, config: array<string,mixed>}>
     */
    private function extractSignatureSlots(string $html): array
    {
        $out = [];
        if ($html === '') return $out;

        // Match <div ... class="... ttd-placeholder ..." ...> — capture full attribute string
        if (!preg_match_all('/<div\b([^>]*\bclass\s*=\s*"[^"]*\bttd-placeholder\b[^"]*"[^>]*)>/is', $html, $matches, PREG_SET_ORDER)) {
            return $out;
        }

        $seenIds = [];
        $idx = 0;
        foreach ($matches as $m) {
            $attrs = $m[1];

            $ttdId    = $this->extractAttr($attrs, 'data-ttd');
            $label    = $this->extractAttr($attrs, 'data-label');
            $namaFld  = $this->extractAttr($attrs, 'data-nama-field');
            $rolesCsv = $this->extractAttr($attrs, 'data-allowed-roles');
            $usersCsv = $this->extractAttr($attrs, 'data-allowed-users');

            if ($ttdId === null || $ttdId === '') continue;
            if (isset($seenIds[$ttdId])) continue;
            $seenIds[$ttdId] = true;

            $rolesArr = $this->splitCsv($rolesCsv);
            $usersArr = $this->splitCsv($usersCsv);

            $firstRole = isset($rolesArr[0]) && $rolesArr[0] !== '' ? $rolesArr[0] : null;

            $out[] = [
                'index'  => $idx,
                'role'   => $firstRole,
                'config' => [
                    'id'            => $ttdId,
                    'label'         => $label !== null ? $label : 'Tanda Tangan',
                    'nama_field'    => $namaFld !== null && $namaFld !== '' ? $namaFld : ('nama_' . $ttdId),
                    'allowed_roles' => $rolesArr,
                    'allowed_users' => $usersArr,
                ],
            ];
            $idx++;
        }

        return $out;
    }

    /**
     * Build structured fields list: union of {{params}}, data-qr, data-materai,
     * dan TTD nama_field. Type di-annotate per source; index = insertion order.
     *
     * @param array<int, string> $params
     * @param array<int, array{index: int, role: string|null, config: array<string,mixed>}> $signatureSlots
     * @return array<int, array{name: string, type: string, default: string, index: int}>
     */
    private function buildFields(string $html, array $params, array $signatureSlots): array
    {
        $out = [];
        $seen = [];
        $idx = 0;

        // 1. Params → type=text
        foreach ($params as $name) {
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $out[] = [
                'name'    => $name,
                'type'    => 'text',
                'default' => '',
                'index'   => $idx++,
            ];
        }

        // 2. QR fields: data-qr="fieldName"
        if (preg_match_all('/\bdata-qr\s*=\s*"([^"]+)"/', $html, $qrMatches)) {
            foreach ($qrMatches[1] as $qrName) {
                $qrName = (string) $qrName;
                if ($qrName === '' || isset($seen[$qrName])) continue;
                $seen[$qrName] = true;
                $out[] = [
                    'name'    => $qrName,
                    'type'    => 'qr',
                    'default' => '',
                    'index'   => $idx++,
                ];
            }
        }

        // 3. Materai placeholder: <div ... class="materai-placeholder" data-materai="ID">
        //    Field name = "_materai_{ID}" (aligned dgn save_document.php key convention)
        if (preg_match_all('/<div\b[^>]*\bclass\s*=\s*"[^"]*\bmaterai-placeholder\b[^"]*"[^>]*\bdata-materai\s*=\s*"([^"]+)"[^>]*>/is', $html, $matMatches)) {
            foreach ($matMatches[1] as $mid) {
                $name = '_materai_' . (string) $mid;
                if (isset($seen[$name])) continue;
                $seen[$name] = true;
                $out[] = [
                    'name'    => $name,
                    'type'    => 'materai',
                    'default' => '',
                    'index'   => $idx++,
                ];
            }
        }

        // 4. Signature name fields (dari TTD placeholder data-nama-field)
        foreach ($signatureSlots as $slot) {
            $cfg = isset($slot['config']) && is_array($slot['config']) ? $slot['config'] : [];
            $namaField = isset($cfg['nama_field']) ? (string) $cfg['nama_field'] : '';
            if ($namaField === '' || isset($seen[$namaField])) continue;
            $seen[$namaField] = true;
            $out[] = [
                'name'    => $namaField,
                'type'    => 'signature_name',
                'default' => '',
                'index'   => $idx++,
            ];
        }

        return $out;
    }

    /**
     * Extract single HTML attribute value from an attrs blob (opening-tag inner).
     * Return null kalau attribute tidak ada.
     */
    private function extractAttr(string $attrs, string $name): ?string
    {
        $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i';
        if (preg_match($pattern, $attrs, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Split CSV attribute value → trimmed non-empty tokens.
     *
     * @return array<int, string>
     */
    private function splitCsv(?string $csv): array
    {
        if ($csv === null || $csv === '') return [];
        $parts = explode(',', $csv);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }
}
