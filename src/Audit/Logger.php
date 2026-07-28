<?php

declare(strict_types=1);

namespace Ezdoc\Audit;

use Ezdoc\Context;
use Ezdoc\UUID;

/**
 * Ezdoc\Audit\Logger — persistent audit trail writer.
 *
 * Silent-fail: kalau insert audit gagal (DB down, table missing), NO EXCEPTION.
 * Audit adalah observability, bukan blocker untuk business logic.
 *
 * @example
 *   $logger = new Logger($ctx);
 *   $logger->log('doc.created', [
 *     'target_type' => 'document',
 *     'target_id' => (string)$docId,
 *     'metadata' => ['norm' => '92164'],
 *   ]);
 */
final class Logger
{
    /** @var Context */
    private $ctx;

    public function __construct(Context $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * Log 1 event ke ezdoc_audit_log.
     *
     * @param string $eventType Format `<subject>.<action>` (mis. 'doc.created').
     * @param array{
     *   actor_id?: int|null,
     *   actor_type?: 'user'|'system'|'public'|'api',
     *   target_type?: string,
     *   target_id?: string|int,
     *   template_id?: int|null,
     *   doc_id?: int|null,
     *   metadata?: array<string,mixed>|null,
     *   previous_value?: array<string,mixed>|null,
     *   new_value?: array<string,mixed>|null,
     *   result?: 'success'|'denied'|'error'|'warning',
     *   message?: string,
     *   request_id?: string|null,
     *   session_id?: string|null,
     *   trace_id?: string|null,
     * } $ctx
     */
    public function log(string $eventType, array $ctx = []): void
    {
        $db = $this->ctx->db;
        if ($eventType === '' || strlen($eventType) > 64) return;

        // Actor: default = current user via role provider
        $actorId = $ctx['actor_id'] ?? $this->ctx->roleProvider->currentUserId();
        $actorId = ((int) $actorId > 0) ? (int) $actorId : null;

        $actorRolesStr = null;
        $roles = $this->ctx->roleProvider->currentUserRoles();
        if (!empty($roles)) {
            $s = implode(',', $roles);
            $actorRolesStr = strlen($s) > 255 ? substr($s, 0, 255) : $s;
        }

        $actorType = $ctx['actor_type'] ?? 'user';
        if (!in_array($actorType, ['user', 'system', 'public', 'api'], true)) {
            $actorType = 'user';
        }

        $eventUuid = UUID::v7(); // Time-ordered event ID untuk cross-system correlation

        $targetType = isset($ctx['target_type']) ? substr((string) $ctx['target_type'], 0, 32) : null;
        $targetId = isset($ctx['target_id']) ? substr((string) $ctx['target_id'], 0, 64) : null;

        $templateId = isset($ctx['template_id']) ? ((int) $ctx['template_id'] ?: null) : null;
        $docId = isset($ctx['doc_id']) ? ((int) $ctx['doc_id'] ?: null) : null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip !== null) $ip = substr($ip, 0, 45);

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua !== null) $ua = substr($ua, 0, 2000);

        $requestId = $ctx['request_id'] ?? null;
        $sessionId = $ctx['session_id'] ?? null;
        $traceId = $ctx['trace_id'] ?? null;

        $metadata = isset($ctx['metadata']) && is_array($ctx['metadata'])
            ? json_encode($ctx['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $previousValue = isset($ctx['previous_value']) && is_array($ctx['previous_value'])
            ? json_encode($ctx['previous_value'], JSON_UNESCAPED_UNICODE)
            : null;

        $newValue = isset($ctx['new_value']) && is_array($ctx['new_value'])
            ? json_encode($ctx['new_value'], JSON_UNESCAPED_UNICODE)
            : null;

        $result = $ctx['result'] ?? 'success';
        if (!in_array($result, ['success', 'denied', 'error', 'warning'], true)) {
            $result = 'success';
        }

        $message = isset($ctx['message']) ? substr((string) $ctx['message'], 0, 2000) : null;

        // Auditing must never take down the operation it is recording, so every
        // failure here is swallowed. The `@` operator alone is not enough:
        // under MYSQLI_REPORT_STRICT — which the migration runner turns on, and
        // which many hosts set globally — these calls raise
        // mysqli_sql_exception, which `@` does not suppress.
        try {
            $stmt = @mysqli_prepare($db, "
                INSERT INTO ezdoc_audit_log
                (event_type, event_uuid, actor_id, actor_roles, actor_type,
                 target_type, target_id, template_id, doc_id,
                 ip_address, user_agent, request_id, session_id, trace_id,
                 previous_value, new_value, metadata, result, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) return;

            // 19 params, in the order listed above:
            //   1  event_type      s
            //   2  event_uuid      s
            //   3  actor_id        i
            //   4  actor_roles     s
            //   5  actor_type      s
            //   6  target_type     s
            //   7  target_id       s
            //   8  template_id     i
            //   9  doc_id          i
            //  10  ip_address      s   <- string; binding this as `i` turned
            //  11  user_agent      s      "192.168.1.190" into 192
            //  12  request_id      s
            //  13  session_id      s
            //  14  trace_id        s
            //  15  previous_value  s
            //  16  new_value       s
            //  17  metadata        s
            //  18  result          s
            //  19  message         s
            @mysqli_stmt_bind_param(
                $stmt,
                'ssissssiissssssssss',
                $eventType, $eventUuid, $actorId, $actorRolesStr, $actorType,
                $targetType, $targetId, $templateId, $docId,
                $ip, $ua, $requestId, $sessionId, $traceId,
                $previousValue, $newValue, $metadata, $result, $message
            );
            @mysqli_stmt_execute($stmt);
        } catch (\Throwable $e) {
            @error_log('[ezdoc:audit] failed to record "' . $eventType . '": ' . $e->getMessage());
        }
    }

    /**
     * Shortcut: log RBAC denied event (result = denied).
     */
    public function denied(string $action, string $reason, array $extra = []): void
    {
        $extra['result'] = 'denied';
        $extra['message'] = $reason;
        $this->log('authz.denied.' . $action, $extra);
    }
}
