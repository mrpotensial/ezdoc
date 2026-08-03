<?php

declare(strict_types=1);

namespace Ezdoc\DefaultVars;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use mysqli;

/**
 * Ezdoc\DefaultVars\DefaultVarsRepository — persistence gateway untuk
 * ezdoc_default_vars (whitelist variables untuk template placeholder).
 *
 * Simple lookup table CRUD. Row shape adalah plain assoc array — schema flat
 * enough tidak butuh value object.
 *
 * ## Usage
 *
 * ```php
 * $repo = new DefaultVarsRepository($conn);
 * $vars = $repo->listAll();          // untuk designer autocomplete
 * $repo->add('nama_pegawai', 'Nama pegawai yang login');
 * $repo->delete(42);
 * ```
 *
 * PHP 7.4+ compatible.
 */
final class DefaultVarsRepository
{
    /** @var Connection */
    private $db;

    /**
     * @param Connection|mysqli $db
     */
    public function __construct($db)
    {
        if ($db instanceof Connection) {
            $this->db = $db;
        } elseif ($db instanceof mysqli) {
            $this->db = new MysqliConnection($db);
        } else {
            throw new \InvalidArgumentException(
                'DefaultVarsRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }
    }

    /**
     * List semua enabled default vars, ordered by var_name.
     *
     * @return list<array{id:int, var_name:string, description:?string}>
     */
    public function listAll(bool $onlyEnabled = true): array
    {
        $sql = 'SELECT id, var_name, description FROM ezdoc_default_vars';
        if ($onlyEnabled) $sql .= ' WHERE is_enabled = 1';
        $sql .= ' ORDER BY var_name ASC';

        $rows = $this->db->fetchAll($sql);
        // Normalize types
        return array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'var_name' => (string) $r['var_name'],
                'description' => isset($r['description']) ? (string) $r['description'] : null,
            ];
        }, $rows);
    }

    /**
     * @return array{id:int, var_name:string, description:?string, is_enabled:bool}|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = $this->db->fetchOne(
            'SELECT id, var_name, description, is_enabled FROM ezdoc_default_vars WHERE id = ? LIMIT 1',
            [$id]
        );
        if (!$row) return null;
        return [
            'id' => (int) $row['id'],
            'var_name' => (string) $row['var_name'],
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'is_enabled' => (bool) $row['is_enabled'],
        ];
    }

    /**
     * @return array{id:int, var_name:string, description:?string, is_enabled:bool}|null
     */
    public function findByName(string $varName): ?array
    {
        if ($varName === '') return null;
        $row = $this->db->fetchOne(
            'SELECT id, var_name, description, is_enabled FROM ezdoc_default_vars WHERE var_name = ? LIMIT 1',
            [$varName]
        );
        if (!$row) return null;
        return [
            'id' => (int) $row['id'],
            'var_name' => (string) $row['var_name'],
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'is_enabled' => (bool) $row['is_enabled'],
        ];
    }

    /**
     * Add new default variable. Idempotent — duplicate var_name silent skip
     * (INSERT IGNORE semantic via check-first).
     *
     * @return int Inserted id, atau 0 kalau sudah ada.
     */
    public function add(string $varName, ?string $description = null): int
    {
        if ($varName === '') return 0;
        // Check-first pattern (portable, tidak semua driver support INSERT IGNORE)
        $existing = $this->findByName($varName);
        if ($existing !== null) return 0;

        $this->db->execute(
            'INSERT INTO ezdoc_default_vars (var_name, description) VALUES (?, ?)',
            [$varName, $description]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update deskripsi. Var name tidak boleh berubah (immutable identifier).
     */
    public function updateDescription(int $id, ?string $description): bool
    {
        if ($id <= 0) return false;
        $affected = $this->db->execute(
            'UPDATE ezdoc_default_vars SET description = ? WHERE id = ?',
            [$description, $id]
        );
        return $affected > 0;
    }

    /**
     * Toggle enabled state (soft-disable tanpa delete).
     */
    public function setEnabled(int $id, bool $enabled): bool
    {
        if ($id <= 0) return false;
        $affected = $this->db->execute(
            'UPDATE ezdoc_default_vars SET is_enabled = ? WHERE id = ?',
            [$enabled ? 1 : 0, $id]
        );
        return $affected > 0;
    }

    /**
     * Hard-delete row. Simple lookup table — tidak butuh soft-delete pattern.
     */
    public function delete(int $id): bool
    {
        if ($id <= 0) return false;
        $affected = $this->db->execute(
            'DELETE FROM ezdoc_default_vars WHERE id = ?',
            [$id]
        );
        return $affected > 0;
    }
}
