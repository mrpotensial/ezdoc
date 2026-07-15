<?php

declare(strict_types=1);

namespace Ezdoc\Audit;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use mysqli;

/**
 * Ezdoc\Audit\AuditRepository — READ-side gateway untuk ezdoc_audit_log.
 *
 * Write side ditangani `Ezdoc\Audit\Logger` (append-only, transactional-safe).
 * Repository ini hanya untuk QUERY audit trail — dipakai admin dashboard,
 * compliance report, forensic investigation.
 *
 * ## Query patterns yang di-cover
 *
 *   Timeline user:    findByActor($actorId, $limit)
 *   Doc history:      findByDocument($docId, $limit)
 *   Event type:       findByEvent($eventType, $limit)
 *   Failed access:    findDenied($limit) — result='denied'
 *   Recent all:       findRecent($limit)
 *   By request:       findByRequestId($requestId) — correlate multi-event per HTTP req
 *
 * ## Result rows
 *
 * Return plain assoc arrays (bukan value object) — audit rows heterogeneous
 * shape (metadata JSON varies per event_type). Consumer decide interpretation.
 *
 * PHP 7.4+ compatible.
 */
final class AuditRepository
{
    /** @var Connection */
    private $db;

    /** @var string SELECT column list — sinkron dgn migrations/blueprints/ezdoc_audit_log.php */
    private static $selectCols = 'id, event_type, event_uuid, actor_id, actor_roles, actor_type, '
        . 'target_type, target_id, template_id, doc_id, '
        . 'ip_address, user_agent, request_id, session_id, trace_id, '
        . 'previous_value, new_value, metadata, result, message, occurred_at';

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
                'AuditRepository requires Ezdoc\\Db\\Connection or mysqli, got: '
                . (is_object($db) ? get_class($db) : gettype($db))
            );
        }
    }

    // ─── Finders ─────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = $this->db->fetchOne(
            'SELECT ' . self::$selectCols . ' FROM ezdoc_audit_log WHERE id = ? LIMIT 1',
            [$id]
        );
        return $row ?: null;
    }

    // ─── Listers ─────────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public function findRecent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log ORDER BY id DESC LIMIT ?',
            [$limit]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByActor(int $actorId, int $limit = 100): array
    {
        if ($actorId <= 0) return [];
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE actor_id = ?'
            . ' ORDER BY occurred_at DESC LIMIT ?',
            [$actorId, $limit]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByDocument(int $docId, int $limit = 100): array
    {
        if ($docId <= 0) return [];
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE doc_id = ?'
            . ' ORDER BY occurred_at DESC LIMIT ?',
            [$docId, $limit]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByTemplate(int $templateId, int $limit = 100): array
    {
        if ($templateId <= 0) return [];
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE template_id = ?'
            . ' ORDER BY occurred_at DESC LIMIT ?',
            [$templateId, $limit]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByEvent(string $eventType, int $limit = 100): array
    {
        if ($eventType === '') return [];
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE event_type = ?'
            . ' ORDER BY occurred_at DESC LIMIT ?',
            [$eventType, $limit]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByRequestId(string $requestId): array
    {
        if ($requestId === '') return [];
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE request_id = ?'
            . ' ORDER BY occurred_at ASC LIMIT 500',
            [$requestId]
        );
    }

    /**
     * Denied events untuk security audit / compliance report.
     *
     * @return list<array<string,mixed>>
     */
    public function findDenied(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        return $this->db->fetchAll(
            'SELECT ' . self::$selectCols
            . ' FROM ezdoc_audit_log WHERE result = \'denied\''
            . ' ORDER BY occurred_at DESC LIMIT ?',
            [$limit]
        );
    }

    // ─── Aggregations ────────────────────────────────────────────────────

    public function countByActor(int $actorId): int
    {
        if ($actorId <= 0) return 0;
        $v = $this->db->fetchScalar(
            'SELECT COUNT(*) FROM ezdoc_audit_log WHERE actor_id = ?',
            [$actorId]
        );
        return (int) $v;
    }

    public function countByEvent(string $eventType): int
    {
        if ($eventType === '') return 0;
        $v = $this->db->fetchScalar(
            'SELECT COUNT(*) FROM ezdoc_audit_log WHERE event_type = ?',
            [$eventType]
        );
        return (int) $v;
    }
}
