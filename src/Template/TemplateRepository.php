<?php

declare(strict_types=1);

namespace Ezdoc\Template;

use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\UUID;
use mysqli;
use mysqli_stmt;

/**
 * Ezdoc\Template\TemplateRepository — persistence gateway untuk ezdoc_templates.
 *
 * Semua query pakai mysqli prepared statement. Konsumsi mysqli langsung
 * (bukan Context) supaya bisa di-inject di service tanpa boot penuh.
 *
 * ## Versioning
 *
 * `save()` = INSERT (kalau id=0) atau UPDATE in-place (kalau id>0, revision bump).
 * `createNewVersion()` = INSERT baris baru dgn uuid diwariskan, version+1,
 *                        is_current=1 (row lama di-set is_current=0),
 *                        parent_version_id = id lama.
 *
 * PHP 7.4+ compatible.
 */
final class TemplateRepository
{
    /** @var mysqli */
    private $db;

    /** @var string Comma-separated column list untuk SELECT — sinkron dgn schema */
    private static $selectCols = 'id, uuid, slug, version, is_current, parent_version_id, name, category, scope, content, content_hash, signature_config, layout_config, verify_config, access_config, metadata, owner_id, is_active, is_locked, revision, deleted_at, deleted_by, deleted_reason, created_by, updated_by, created_at, updated_at';

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // ─── Finders ─────────────────────────────────────────────────────────

    public function findById(int $id): ?Template
    {
        if ($id <= 0) return null;

        $sql = 'SELECT ' . self::$selectCols . ' FROM ezdoc_templates WHERE id = ? LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;

        mysqli_stmt_bind_param($stmt, 'i', $id);
        return $this->fetchOne($stmt);
    }

    /**
     * Latest version dalam family. Tidak filter is_current — return baris dgn
     * version paling tinggi (untuk history/audit lookup).
     */
    public function findByUuid(string $uuid): ?Template
    {
        if ($uuid === '') return null;

        $sql = 'SELECT ' . self::$selectCols . ' FROM ezdoc_templates WHERE uuid = ? ORDER BY version DESC LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;

        mysqli_stmt_bind_param($stmt, 's', $uuid);
        return $this->fetchOne($stmt);
    }

    /**
     * Current active version untuk family — where is_current=1 AND deleted_at IS NULL.
     */
    public function findCurrentByUuid(string $uuid): ?Template
    {
        if ($uuid === '') return null;

        $sql = 'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE uuid = ? AND is_current = 1 AND deleted_at IS NULL'
            . ' LIMIT 1';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return null;

        mysqli_stmt_bind_param($stmt, 's', $uuid);
        return $this->fetchOne($stmt);
    }

    /**
     * @throws NotFoundException kalau template tidak ada
     */
    public function findByIdOrFail(int $id): Template
    {
        $tpl = $this->findById($id);
        if ($tpl === null) {
            throw NotFoundException::forResource('template', $id);
        }
        return $tpl;
    }

    // ─── Listers ─────────────────────────────────────────────────────────

    /**
     * List current active templates (is_current=1 AND is_active=1 AND !deleted).
     *
     * @return array<int, Template>
     */
    public function listCurrent(int $limit = 100, int $offset = 0): array
    {
        $limit  = $limit > 0 ? $limit : 100;
        $offset = $offset >= 0 ? $offset : 0;

        $sql = 'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE is_current = 1 AND is_active = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC'
            . ' LIMIT ? OFFSET ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return [];

        mysqli_stmt_bind_param($stmt, 'ii', $limit, $offset);
        return $this->fetchMany($stmt);
    }

    /**
     * @return array<int, Template>
     */
    public function listByOwner(int $ownerId, int $limit = 100): array
    {
        if ($ownerId <= 0) return [];
        $limit = $limit > 0 ? $limit : 100;

        $sql = 'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE owner_id = ? AND is_current = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC'
            . ' LIMIT ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return [];

        mysqli_stmt_bind_param($stmt, 'ii', $ownerId, $limit);
        return $this->fetchMany($stmt);
    }

    /**
     * @return array<int, Template>
     */
    public function listByCategory(string $category, int $limit = 100): array
    {
        $limit = $limit > 0 ? $limit : 100;

        $sql = 'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE category = ? AND is_current = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC'
            . ' LIMIT ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return [];

        mysqli_stmt_bind_param($stmt, 'si', $category, $limit);
        return $this->fetchMany($stmt);
    }

    // ─── Writers ─────────────────────────────────────────────────────────

    /**
     * INSERT (kalau id=0) atau UPDATE (kalau id>0, revision bump).
     * Return template id.
     */
    public function save(Template $template, int $actorId): int
    {
        if ($template->getId() === 0) {
            return $this->insert($template, $actorId);
        }
        $this->updateInPlace($template, $actorId);
        return $template->getId();
    }

    /**
     * Buat versi baru dalam family yang sama:
     *   - Row lama: is_current=0
     *   - Row baru: INSERT, uuid diwariskan, version+1, is_current=1,
     *     parent_version_id = row lama.
     *
     * $changedData: override kolom apapun sebelum insert (mis. ['content'=>...]).
     *
     * Return id baris baru.
     *
     * @param array<string,mixed> $changedData
     */
    public function createNewVersion(Template $currentTemplate, array $changedData, int $actorId): int
    {
        $currentId = $currentTemplate->getId();
        if ($currentId <= 0) {
            // Nothing to fork from → treat as fresh insert
            return $this->save($currentTemplate, $actorId);
        }

        // Snapshot dari current + overlay changes
        $base = $currentTemplate->toArray();
        foreach ($changedData as $k => $v) {
            $base[$k] = $v;
        }

        // Force version-chain semantics
        $base['id']                = 0;
        $base['uuid']              = $currentTemplate->getUuid();
        $base['version']           = $currentTemplate->getVersion() + 1;
        $base['is_current']        = 1;
        $base['parent_version_id'] = $currentId;
        $base['revision']          = 1;

        // Slug baru per version (uniqueness guard — schema tidak enforce, tapi
        // kita tetap generate distinct slug supaya index tidak collide di masa
        // depan kalau constraint ditambah).
        $base['slug'] = $this->generateSlug($currentTemplate->getName(), $currentTemplate->getSlug());

        $newTemplate = new Template($base);

        // Turn off is_current di old row DALAM transaksi
        mysqli_begin_transaction($this->db);
        try {
            $newId = $this->insert($newTemplate, $actorId);

            $sqlOld = 'UPDATE ezdoc_templates SET is_current = 0 WHERE id = ?';
            $stmtOld = mysqli_prepare($this->db, $sqlOld);
            if (!$stmtOld) {
                mysqli_rollback($this->db);
                return 0;
            }
            mysqli_stmt_bind_param($stmtOld, 'i', $currentId);
            if (!mysqli_stmt_execute($stmtOld)) {
                mysqli_rollback($this->db);
                return 0;
            }

            mysqli_commit($this->db);
            return $newId;
        } catch (\Throwable $e) {
            mysqli_rollback($this->db);
            return 0;
        }
    }

    /**
     * Soft-delete: set deleted_at, deleted_by, deleted_reason. Row tetap ada.
     */
    public function softDelete(int $id, int $actorId, string $reason = ''): bool
    {
        if ($id <= 0) return false;

        $sql = 'UPDATE ezdoc_templates
                SET deleted_at = CURRENT_TIMESTAMP,
                    deleted_by = ?,
                    deleted_reason = ?,
                    is_current = 0
                WHERE id = ? AND deleted_at IS NULL';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return false;

        $deletedBy = (string) $actorId;
        mysqli_stmt_bind_param($stmt, 'ssi', $deletedBy, $reason, $id);
        if (!mysqli_stmt_execute($stmt)) return false;

        return mysqli_stmt_affected_rows($stmt) > 0;
    }

    /**
     * Touch updated_at tanpa ubah kolom lain — dipakai lock/unlock events supaya
     * cache/list-order akurat.
     *
     * Set is_locked-nya sendiri harus lewat query lain — utility ini hanya
     * tanda "row bergerak". Update updated_at eksplisit (ON UPDATE trigger
     * schema tidak jalan kalau tidak ada column actual change).
     */
    public function touchUpdatedAt(int $id): bool
    {
        if ($id <= 0) return false;

        $sql = 'UPDATE ezdoc_templates SET updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return false;

        mysqli_stmt_bind_param($stmt, 'i', $id);
        return mysqli_stmt_execute($stmt);
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    /**
     * INSERT baru: generate uuid v7 (kalau kosong), generate slug, ownership,
     * timestamps default oleh schema.
     */
    private function insert(Template $template, int $actorId): int
    {
        $data = $template->toArray();

        $uuid = $data['uuid'] !== '' ? (string) $data['uuid'] : UUID::v7();
        $slug = $data['slug'] !== '' ? (string) $data['slug'] : $this->generateSlug($data['name'] ?? '', '');

        $version         = isset($data['version']) ? (int) $data['version'] : 1;
        $isCurrent       = !empty($data['is_current']) ? 1 : 0;
        $parentVersionId = $data['parent_version_id'] !== null ? (int) $data['parent_version_id'] : null;
        $name            = (string) ($data['name'] ?? '');
        $category        = (string) ($data['category'] ?? '');
        $scope           = ($data['scope'] ?? 'patient') === 'general' ? 'general' : 'patient';
        $content         = (string) ($data['content'] ?? '');
        $contentHash     = $data['content_hash'] !== null && $data['content_hash'] !== '' ? (string) $data['content_hash'] : null;

        $signatureConfigStr = $this->encodeJsonCol($data['signature_config'] ?? []);
        $layoutConfigStr    = $this->encodeJsonCol($data['layout_config'] ?? []);
        $verifyConfigStr    = $this->encodeJsonCol($data['verify_config'] ?? []);
        $accessConfigStr    = $this->encodeJsonCol($data['access_config'] ?? []);
        $metadataStr        = $this->encodeJsonCol($data['metadata'] ?? []);

        $ownerId  = $data['owner_id'] !== null ? (int) $data['owner_id'] : ($actorId > 0 ? $actorId : null);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isLocked = !empty($data['is_locked']) ? 1 : 0;
        $revision = isset($data['revision']) ? (int) $data['revision'] : 1;
        $createdBy = $actorId > 0 ? $actorId : null;
        $updatedBy = $actorId > 0 ? $actorId : null;

        $sql = 'INSERT INTO ezdoc_templates
            (uuid, slug, version, is_current, parent_version_id,
             name, category, scope, content, content_hash,
             signature_config, layout_config, verify_config, access_config, metadata,
             owner_id, is_active, is_locked, revision,
             created_by, updated_by)
            VALUES (?, ?, ?, ?, ?,  ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?,  ?, ?, ?, ?,  ?, ?)';

        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return 0;

        // Types: s s i i i  s s s s s  s s s s s  i i i i  i i  (21 params)
        mysqli_stmt_bind_param(
            $stmt,
            'ssiiisssssssssssiiiiii',
            $uuid, $slug, $version, $isCurrent, $parentVersionId,
            $name, $category, $scope, $content, $contentHash,
            $signatureConfigStr, $layoutConfigStr, $verifyConfigStr, $accessConfigStr, $metadataStr,
            $ownerId, $isActive, $isLocked, $revision,
            $createdBy, $updatedBy
        );

        if (!mysqli_stmt_execute($stmt)) return 0;

        return (int) mysqli_insert_id($this->db);
    }

    /**
     * UPDATE in-place. Revision bumped by SQL itu sendiri (revision = revision + 1).
     */
    private function updateInPlace(Template $template, int $actorId): bool
    {
        $data = $template->toArray();
        $id   = (int) $data['id'];
        if ($id <= 0) return false;

        $name        = (string) ($data['name'] ?? '');
        $category    = (string) ($data['category'] ?? '');
        $scope       = ($data['scope'] ?? 'patient') === 'general' ? 'general' : 'patient';
        $content     = (string) ($data['content'] ?? '');
        $contentHash = $data['content_hash'] !== null && $data['content_hash'] !== '' ? (string) $data['content_hash'] : null;

        $signatureConfigStr = $this->encodeJsonCol($data['signature_config'] ?? []);
        $layoutConfigStr    = $this->encodeJsonCol($data['layout_config'] ?? []);
        $verifyConfigStr    = $this->encodeJsonCol($data['verify_config'] ?? []);
        $accessConfigStr    = $this->encodeJsonCol($data['access_config'] ?? []);
        $metadataStr        = $this->encodeJsonCol($data['metadata'] ?? []);

        $isActive  = !empty($data['is_active']) ? 1 : 0;
        $isLocked  = !empty($data['is_locked']) ? 1 : 0;
        $updatedBy = $actorId > 0 ? $actorId : null;

        $sql = 'UPDATE ezdoc_templates SET
                name = ?,
                category = ?,
                scope = ?,
                content = ?,
                content_hash = ?,
                signature_config = ?,
                layout_config = ?,
                verify_config = ?,
                access_config = ?,
                metadata = ?,
                is_active = ?,
                is_locked = ?,
                updated_by = ?,
                revision = revision + 1
            WHERE id = ?';

        $stmt = mysqli_prepare($this->db, $sql);
        if (!$stmt) return false;

        // Types: s s s s s  s s s s s  i i i  i  (14 params)
        mysqli_stmt_bind_param(
            $stmt,
            'ssssssssssiiii',
            $name, $category, $scope, $content, $contentHash,
            $signatureConfigStr, $layoutConfigStr, $verifyConfigStr, $accessConfigStr, $metadataStr,
            $isActive, $isLocked, $updatedBy,
            $id
        );

        return mysqli_stmt_execute($stmt);
    }

    /**
     * Encode array → JSON string untuk kolom JSON. Empty array → null (biar
     * kolom JSON tidak menyimpan '[]' / '{}' setiap kali).
     *
     * @param array<string,mixed>|mixed $val
     */
    private function encodeJsonCol($val): ?string
    {
        if (!is_array($val) || empty($val)) return null;
        $encoded = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? null : $encoded;
    }

    /**
     * Generate slug baru — safe untuk kolom VARCHAR(120), suffix random supaya
     * unik antar-versi maupun antar-family. Fallback prefix "template" kalau
     * base kosong. $legacy dipertahankan hanya untuk parity dgn save_template.php
     * behaviour (tidak dipakai selain fallback validation).
     */
    private function generateSlug(string $name, string $legacy): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)));
        $base = trim($base, '_');
        if ($base === '') {
            $base = $legacy !== '' ? preg_replace('/_[a-f0-9]{4,}$/', '', $legacy) : 'template';
            if ($base === '' || $base === null) $base = 'template';
        }

        try {
            $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        } catch (\Throwable $e) {
            $suffix = substr(dechex((int) (microtime(true) * 1000)), -8);
        }

        $slug = $base . '_' . $suffix;
        if (strlen($slug) > 120) $slug = substr($slug, 0, 120);
        return $slug;
    }

    /**
     * Fetch single row → Template atau null.
     */
    private function fetchOne(mysqli_stmt $stmt): ?Template
    {
        if (!mysqli_stmt_execute($stmt)) return null;
        $result = mysqli_stmt_get_result($stmt);
        if (!$result) return null;

        $row = mysqli_fetch_assoc($result);
        if (!$row) return null;

        return Template::fromRow($row);
    }

    /**
     * Fetch multiple rows → list of Template.
     *
     * @return array<int, Template>
     */
    private function fetchMany(mysqli_stmt $stmt): array
    {
        if (!mysqli_stmt_execute($stmt)) return [];
        $result = mysqli_stmt_get_result($stmt);
        if (!$result) return [];

        $out = [];
        while (($row = mysqli_fetch_assoc($result)) !== null) {
            $out[] = Template::fromRow($row);
        }
        return $out;
    }
}
