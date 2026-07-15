<?php

declare(strict_types=1);

/**
 * Blueprint: ezdoc_audit_log
 *
 * Persistent event trail untuk compliance. Append-only. Source of truth
 * untuk spec-dump CLI.
 *
 * Note: original uses `DATETIME(3)` untuk millisecond precision. Blueprint
 * DSL saat ini tidak expose precision — Grammar map ke default DATETIME.
 * Fractional-second precision di v0.9.10+ (butuh Column->precision(3) hint).
 */

use Ezdoc\Db\Schema\Blueprint;

return Blueprint::create('ezdoc_audit_log', function (Blueprint $t) {
    $t->id();

    // Event identification
    $t->string('event_type', 64)->comment('e.g. doc.created, template.saved, authz.denied');
    $t->uuid('event_uuid')->nullable()->comment('UUID v7 untuk correlate cross-system');

    // Actor context
    $t->bigint('actor_id')->nullable()->comment('id_pegawai/user (NULL untuk public/system)');
    $t->string('actor_roles', 255)->nullable()->comment('Snapshot roles saat event');
    $t->enum('actor_type', ['user', 'system', 'public', 'api'])->default('user');

    // Target entity
    $t->string('target_type', 32)->nullable()->comment('template | document | signature | verification');
    $t->string('target_id', 64)->nullable()->comment('ID target (bisa non-int untuk slug/uuid)');

    // Denormalized refs untuk filter cepat
    $t->bigint('template_id')->nullable();
    $t->bigint('doc_id')->nullable();

    // Request context
    $t->string('ip_address', 45)->nullable()->comment('IPv4 or IPv6');
    $t->text('user_agent')->nullable();
    $t->uuid('request_id')->nullable()->comment('UUID v7 untuk correlate events per HTTP request');
    $t->string('session_id', 64)->nullable()->comment('Session identifier untuk timeline user');
    $t->uuid('trace_id')->nullable()->comment('Distributed tracing (OpenTelemetry compatible)');

    // Change tracking (field-level)
    $t->json('previous_value')->nullable()->comment('State sebelum change');
    $t->json('new_value')->nullable()->comment('State setelah change');

    // Extensibility
    $t->json('metadata')->nullable()->comment('Context-specific per event type');

    // Result
    $t->enum('result', ['success', 'denied', 'error', 'warning'])->default('success');
    $t->text('message')->nullable()->comment('Human-readable summary');

    // Timestamp
    $t->datetime('occurred_at')->defaultRaw('CURRENT_TIMESTAMP')->comment('Event timestamp');

    // Indexes (composite untuk query patterns)
    $t->index(['event_type', 'occurred_at'], 'idx_event_time');
    $t->index(['actor_id', 'occurred_at'], 'idx_actor_time');
    $t->index(['target_type', 'target_id'], 'idx_target');
    $t->index(['template_id', 'occurred_at'], 'idx_template_time');
    $t->index(['doc_id', 'occurred_at'], 'idx_doc_time');
    $t->index('occurred_at', 'idx_time');
    $t->index(['result', 'occurred_at'], 'idx_result_time');
    $t->index('request_id', 'idx_request');
    $t->index('session_id', 'idx_session');
    $t->index('trace_id', 'idx_trace');

    $t->comment('Audit log untuk compliance + event sourcing (append-only)');
});
