<?php

declare(strict_types=1);

/**
 * Global audit log helpers — thin wrappers ke Ezdoc\Audit\Logger.
 *
 * Backward compat untuk existing procedural code. New code (library consumer)
 * sebaiknya pakai Ezdoc\Audit\Logger langsung dengan Context injection.
 *
 * @example Existing (backward compat):
 *   ezdoc_audit_log('doc.created', ['doc_id' => 42]);
 *
 * @example New (library-ready):
 *   $logger = new Ezdoc\Audit\Logger($ctx);
 *   $logger->log('doc.created', ['doc_id' => 42]);
 */

if (defined('EZDOC_AUDIT_LOADED')) return;
define('EZDOC_AUDIT_LOADED', true);

function ezdoc_audit_log(string $eventType, array $ctx = []): void
{
    // Route via default Context (auto-init from globals)
    try {
        $context = \Ezdoc\Context::default();
        $logger = new \Ezdoc\Audit\Logger($context);
        $logger->log($eventType, $ctx);
    } catch (\Throwable $e) {
        // Silent-fail — audit tidak boleh crash caller
        @error_log('[ezdoc:audit] ' . $e->getMessage());
    }
}

function ezdoc_audit_denied(string $action, string $reason, array $ctx = []): void
{
    try {
        $context = \Ezdoc\Context::default();
        $logger = new \Ezdoc\Audit\Logger($context);
        $logger->denied($action, $reason, $ctx);
    } catch (\Throwable $e) {
        @error_log('[ezdoc:audit] ' . $e->getMessage());
    }
}
