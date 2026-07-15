<?php

declare(strict_types=1);

namespace Ezdoc\Template;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Exceptions\NotFoundException;
use Ezdoc\UUID;
use mysqli;

/**
 * Ezdoc\Template\TemplateRepository — persistence gateway untuk ezdoc_templates.
 *
 * ## v0.9.9 refactor — Connection interface
 *
 * Sebelumnya hard-coupled `mysqli`. Sekarang accepts `Ezdoc\Db\Connection`
 * (interface). Constructor tetap backward-compat: kalau consumer lempar
 * `mysqli` global (koneksi.php pattern), otomatis di-wrap ke MysqliConnection.
 *
 * ## Versioning
 *
 * - `save()`               = INSERT (kalau id=0) atau UPDATE in-place (revision bump)
 * - `createNewVersion()`   = INSERT baris baru, uuid diwariskan, version+1,
 *                            is_current=1 (row lama set is_current=0),
 *                            parent_version_id = id lama
 *
 * PHP 7.4+ compatible.
 */
final class TemplateRepository
{
    /** @var Connection */
    private $db;

    /** @var string Comma-separated column list untuk SELECT — sinkron dgn Blueprint */
    private static $selectCols = 'id, uuid, slug, version, is_current, parent_version_id, name, category, scope, content, content_hash, signature_config, layout_config, verify_config, access_config, metadata, owner_id, is_active, is_locked, revision, deleted_at, deleted_by, deleted_reason, created_by, updated_by, created_at, updated_at';

    /**
     * @param Connection|mysqli $db Ezdoc\Db\Connection instance (preferred)
     *   atau raw mysqli (backward-compat, auto-wrap ke MysqliConnection).
     */
    public function __construct($db)
    {
        if ($db instanceof Connection) {
            $this->db = $db;
        } elseif ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } else {
            throw new \InvalidArgumentException(
                'TemplateRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }
    }

    // ─── Finders ─────────────────────────────────────────────────────────

    public function findById(int $id): ?Template
    {
        if ($id <= 0) return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols . ' FROM ezdoc_templates WHERE id = ? LIMIT 1',
            [$id]
        );
        return $row ? Template::fromRow($row) : null;
    }

    /**
     * Latest version dalam family. Tidak filter is_current — return baris dgn
     * version paling tinggi (untuk history/audit lookup).
     */
    public function findByUuid(string $uuid): ?Template
    {
        if ($uuid === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates WHERE uuid = ? ORDER BY version DESC LIMIT 1',
            [$uuid]
        );
        return $row ? Template::fromRow($row) : null;
    }

    /**
     * Current active version untuk family — WHERE is_current=1 AND deleted_at IS NULL.
     */
    public function findCurrentByUuid(string $uuid): ?Template
    {
        if ($uuid === '') return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE uuid = ? AND is_current = 1 AND deleted_at IS NULL LIMIT 1',
            [$uuid]
        );
        return $row ? Template::fromRow($row) : null;
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
     * @return array<int, Template>
     */
    public function listCurrent(int $limit = 100, int $offset = 0): array
    {
        $limit = $limit > 0 ? $limit : 100;
        $offset = $offset >= 0 ? $offset : 0;

        $rows = $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE is_current = 1 AND is_active = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        return array_map([Template::class, 'fromRow'], $rows);
    }

    /**
     * @return array<int, Template>
     */
    public function listByOwner(int $ownerId, int $limit = 100): array
    {
        if ($ownerId <= 0) return [];
        $limit = $limit > 0 ? $limit : 100;

        $rows = $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE owner_id = ? AND is_current = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC LIMIT ?',
            [$ownerId, $limit]
        );
        return array_map([Template::class, 'fromRow'], $rows);
    }

    /**
     * @return array<int, Template>
     */
    public function listByCategory(string $category, int $limit = 100): array
    {
        $limit = $limit > 0 ? $limit : 100;

        $rows = $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_templates'
            . ' WHERE category = ? AND is_current = 1 AND deleted_at IS NULL'
            . ' ORDER BY updated_at DESC LIMIT ?',
            [$category, $limit]
        );
        return array_map([Template::class, 'fromRow'], $rows);
    }

    // ─── Writers ─────────────────────────────────────────────────────────

    /**
     * INSERT (kalau id=0) atau UPDATE (kalau id>0, revision bump). Return template id.
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
     *   - Row baru: INSERT dgn uuid diwariskan, version+1, is_current=1,
     *     parent_version_id = row lama.
     *
     * $changedData: override kolom apapun sebelum insert (mis. ['content'=>...]).
     *
     * @param array<string,mixed> $changedData
     * @return int id baris baru, atau 0 kalau gagal.
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

        // Slug baru per version (uniqueness guard)
        $base['slug'] = $this->generateSlug($currentTemplate->getName(), $currentTemplate->getSlug());

        $newTemplate = new Template($base);

        // Insert new + flip old is_current dalam transaction
        try {
            return $this->db->transaction(function () use ($newTemplate, $actorId, $currentId) {
                $newId = $this->insert($newTemplate, $actorId);
                if ($newId <= 0) {
                    throw new \RuntimeException('insert new version failed');
                }
                $affected = $this->db->execute(
                    'UPDATE ezdoc_templates SET is_current = 0 WHERE id = ?',
                    [$currentId]
                );
                if ($affected < 1) {
                    throw new \RuntimeException('failed to unset is_current on old version');
                }
                return $newId;
            });
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Soft-delete: set deleted_at, deleted_by, deleted_reason. Row tetap ada.
     */
    public function softDelete(int $id, int $actorId, string $reason = ''): bool
    {
        if ($id <= 0) return false;

        $affected = $this->db->execute(
            'UPDATE ezdoc_templates
                SET deleted_at = CURRENT_TIMESTAMP,
                    deleted_by = ?,
                    deleted_reason = ?,
                    is_current = 0
                WHERE id = ? AND deleted_at IS NULL',
            [(string) $actorId, $reason, $id]
        );
        return $affected > 0;
    }

    /**
     * Touch updated_at tanpa ubah kolom lain — dipakai lock/unlock events supaya
     * cache/list-order akurat.
     */
    public function touchUpdatedAt(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $this->db->execute(
                'UPDATE ezdoc_templates SET updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$id]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    private function insert(Template $template, int $actorId): int
    {
        $data = $template->toArray();

        $uuid = ($data['uuid'] !== '') ? (string) $data['uuid'] : UUID::v7();
        $slug = ($data['slug'] !== '') ? (string) $data['slug'] : $this->generateSlug($data['name'] ?? '', '');

        $sql = 'INSERT INTO ezdoc_templates
            (uuid, slug, version, is_current, parent_version_id,
             name, category, scope, content, content_hash,
             signature_config, layout_config, verify_config, access_config, metadata,
             owner_id, is_active, is_locked, revision,
             created_by, updated_by)
            VALUES (?, ?, ?, ?, ?,  ?, ?, ?, ?, ?,  ?, ?, ?, ?, ?,  ?, ?, ?, ?,  ?, ?)';

        try {
            $this->db->execute($sql, [
                $uuid,
                $slug,
                isset($data['version']) ? (int) $data['version'] : 1,
                !empty($data['is_current']) ? 1 : 0,
                $data['parent_version_id'] !== null ? (int) $data['parent_version_id'] : null,
                (string) ($data['name'] ?? ''),
                (string) ($data['category'] ?? ''),
                (($data['scope'] ?? 'patient') === 'general') ? 'general' : 'patient',
                (string) ($data['content'] ?? ''),
                ($data['content_hash'] !== null && $data['content_hash'] !== '') ? (string) $data['content_hash'] : null,
                $this->encodeJsonCol($data['signature_config'] ?? []),
                $this->encodeJsonCol($data['layout_config'] ?? []),
                $this->encodeJsonCol($data['verify_config'] ?? []),
                $this->encodeJsonCol($data['access_config'] ?? []),
                $this->encodeJsonCol($data['metadata'] ?? []),
                $data['owner_id'] !== null ? (int) $data['owner_id'] : ($actorId > 0 ? $actorId : null),
                !empty($data['is_active']) ? 1 : 0,
                !empty($data['is_locked']) ? 1 : 0,
                isset($data['revision']) ? (int) $data['revision'] : 1,
                $actorId > 0 ? $actorId : null,
                $actorId > 0 ? $actorId : null,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }

        return (int) $this->db->lastInsertId();
    }

    private function updateInPlace(Template $template, int $actorId): bool
    {
        $data = $template->toArray();
        $id   = (int) $data['id'];
        if ($id <= 0) return false;

        $sql = 'UPDATE ezdoc_templates SET
                name = ?, category = ?, scope = ?, content = ?, content_hash = ?,
                signature_config = ?, layout_config = ?, verify_config = ?, access_config = ?, metadata = ?,
                is_active = ?, is_locked = ?, updated_by = ?,
                revision = revision + 1
            WHERE id = ?';

        try {
            $this->db->execute($sql, [
                (string) ($data['name'] ?? ''),
                (string) ($data['category'] ?? ''),
                (($data['scope'] ?? 'patient') === 'general') ? 'general' : 'patient',
                (string) ($data['content'] ?? ''),
                ($data['content_hash'] !== null && $data['content_hash'] !== '') ? (string) $data['content_hash'] : null,
                $this->encodeJsonCol($data['signature_config'] ?? []),
                $this->encodeJsonCol($data['layout_config'] ?? []),
                $this->encodeJsonCol($data['verify_config'] ?? []),
                $this->encodeJsonCol($data['access_config'] ?? []),
                $this->encodeJsonCol($data['metadata'] ?? []),
                !empty($data['is_active']) ? 1 : 0,
                !empty($data['is_locked']) ? 1 : 0,
                $actorId > 0 ? $actorId : null,
                $id,
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Encode array → JSON string untuk kolom JSON. Empty array → null (biar
     * kolom tidak menyimpan '[]' / '{}' setiap kali).
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
     * unik antar-versi maupun antar-family. Fallback "template" kalau base kosong.
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
}
