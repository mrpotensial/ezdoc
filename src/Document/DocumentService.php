<?php

declare(strict_types=1);

namespace Ezdoc\Document;

use Ezdoc\Access\AccessConfig;
use Ezdoc\Access\AccessControl;
use Ezdoc\Audit\Logger;
use Ezdoc\Context;
use Ezdoc\Exceptions\AccessDeniedException;
use Ezdoc\Exceptions\NotFoundException;

/**
 * Ezdoc\Document\DocumentService — orchestration layer over DocumentRepository.
 *
 * Responsibilities:
 *   - Fetch actor id from Context::roleProvider.
 *   - Load template + its access_config JSON for RBAC.
 *   - Delegate persistence to DocumentRepository.
 *   - Emit audit events via Ezdoc\Audit\Logger (silent-fail).
 *
 * PHP 7.4+ compatible.
 */
final class DocumentService
{
    /** @var Context */
    private $ctx;

    /** @var DocumentRepository */
    private $repo;

    /** @var Logger */
    private $logger;

    /** @var AccessControl */
    private $access;

    public function __construct(Context $ctx)
    {
        $this->ctx = $ctx;
        $this->repo = new DocumentRepository($ctx->db);
        $this->logger = new Logger($ctx);
        $this->access = new AccessControl($ctx->roleProvider);
    }

    /**
     * Save (INSERT or UPDATE) a document.
     *
     * @throws NotFoundException     when template or update target missing
     * @throws AccessDeniedException when actor lacks required action permission
     */
    public function save(SaveDocumentRequest $req): SaveDocumentResult
    {
        $actorId = $this->ctx->roleProvider->currentUserId();

        // Load template row for access_config + existence check.
        $template = $this->loadTemplateRow($req->getTemplateId());
        if ($template === null) {
            throw NotFoundException::forResource('template', $req->getTemplateId());
        }

        $accessConfig = AccessConfig::fromJson(
            isset($template['access_config']) ? (string) $template['access_config'] : null
        );

        $action = $req->isUpdate() ? 'edit' : 'create';

        // Throws AccessDeniedException on deny.
        try {
            $this->access->assertCan($actorId, $action, $accessConfig);
        } catch (AccessDeniedException $e) {
            $this->logger->denied('document.' . $action, (string) $e->getReason(), [
                'template_id' => $req->getTemplateId(),
                'doc_id' => $req->getDocumentId(),
                'target_type' => 'document',
                'target_id' => (string) ($req->getDocumentId() ?? ''),
            ]);
            throw $e;
        }

        $result = $this->repo->save($req, $actorId);

        // Audit success. Logger silent-fails so no try/catch needed.
        $eventType = $result->isNew() ? 'document.created' : 'document.updated';
        $this->logger->log($eventType, [
            'target_type' => 'document',
            'target_id' => (string) $result->getDocumentId(),
            'template_id' => $req->getTemplateId(),
            'doc_id' => $result->getDocumentId(),
            'metadata' => [
                'uuid' => $result->getUuid(),
                'revision' => $result->getRevision(),
                'content_hash' => $result->getContentHash(),
                'status' => $req->getStatus(),
                'subject_type' => $req->getSubjectType(),
                'subject_id' => $req->getSubjectId(),
            ],
            'result' => 'success',
        ]);

        return $result;
    }

    /**
     * @throws NotFoundException when id doesn't resolve
     */
    public function findById(int $id): Document
    {
        $doc = $this->repo->findById($id);
        if ($doc === null) {
            throw NotFoundException::forResource('document', $id);
        }
        return $doc;
    }

    /**
     * Load raw template row for access_config lookup.
     * Kept private — repository layer for templates is out of scope here.
     *
     * @return array<string,mixed>|null
     */
    private function loadTemplateRow(int $templateId): ?array
    {
        if ($templateId <= 0) return null;
        $sql = 'SELECT id, uuid, version, access_config FROM ezdoc_templates WHERE id = ? LIMIT 1';
        $stmt = mysqli_prepare($this->ctx->db, $sql);
        if (!$stmt) return null;
        mysqli_stmt_bind_param($stmt, 'i', $templateId);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return null;
        }
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        return $row ?: null;
    }

    /**
     * Expose repo for consumers that need read-only access without the service overhead.
     */
    public function getRepository(): DocumentRepository
    {
        return $this->repo;
    }
}
