<?php

declare(strict_types=1);

namespace Ezdoc;

use Ezdoc\Auth\HasRoleProvider;
use Ezdoc\Auth\RoleProvider;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Rendering\PdfRenderer;
use mysqli;

/**
 * Ezdoc\Context — dependency injection container untuk library.
 *
 * Kumpulan runtime dependencies (DB, auth provider, dll) yang dipakai
 * services. Immutable — pakai `with*()` untuk create modified copy.
 *
 * ## Compatibility
 * PHP 7.4+ (immutability by convention, bukan `readonly` keyword yang PHP 8.1+).
 *
 * ## Usage
 *
 * Native PHP + koneksi.php (monolith):
 * ```php
 * global $conn;
 * $ctx = Context::fromGlobals();
 * ```
 *
 * Custom (library consumer):
 * ```php
 * $ctx = new Context($mysqli, new LaravelRoleProvider());
 * ```
 *
 * Default global instance untuk backward compat:
 * ```php
 * Context::setDefault($customCtx);
 * $default = Context::default();
 * ```
 */
final class Context
{
    /** @var Context|null */
    private static $defaultInstance = null;

    /** @var mysqli */
    public $db;

    /** @var RoleProvider */
    public $roleProvider;

    /**
     * PDF rendering backend. Null → auto-instantiate DompdfRenderer di runtime
     * kalau dompdf composer package tersedia. Consumer inject via withPdf()
     * untuk custom backend (mPDF, wkhtmltopdf, Weasyprint).
     *
     * @var PdfRenderer|null
     */
    public $pdf;

    public function __construct(mysqli $db, RoleProvider $roleProvider, ?PdfRenderer $pdf = null)
    {
        $this->db = $db;
        $this->roleProvider = $roleProvider;
        $this->pdf = $pdf;
    }

    /**
     * Create Context dari koneksi.php globals ($conn, $author_id, $author_role_array).
     * Convenience factory untuk existing koneksi.php-based monolith codebase.
     */
    public static function fromGlobals(): self
    {
        $db = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
        if (!$db instanceof mysqli) {
            // EzdocException extends \RuntimeException — backward-compat:
            // existing code yang `catch (\RuntimeException)` tetap catch ini.
            throw new EzdocException(
                'Context::fromGlobals() memerlukan $GLOBALS[\'conn\'] sebagai mysqli instance. '
                . 'Pastikan koneksi.php sudah di-require sebelum call ini.'
            );
        }

        return new self($db, new HasRoleProvider());
    }

    /**
     * Get default context (lazy-init dari globals kalau belum di-set).
     * Dipakai oleh global function wrappers (ezdoc_audit_log, dll).
     */
    public static function default(): self
    {
        if (self::$defaultInstance === null) {
            self::$defaultInstance = self::fromGlobals();
        }
        return self::$defaultInstance;
    }

    /**
     * Override default context — untuk consumer yang mau custom auth/db.
     */
    public static function setDefault(?Context $ctx): void
    {
        self::$defaultInstance = $ctx;
    }

    /**
     * Reset default context (untuk testing).
     */
    public static function resetDefault(): void
    {
        self::$defaultInstance = null;
    }

    /** Immutable copy dengan db diubah. */
    public function withDb(mysqli $db): self
    {
        return new self($db, $this->roleProvider, $this->pdf);
    }

    /** Immutable copy dengan roleProvider diubah. */
    public function withRoleProvider(RoleProvider $rp): self
    {
        return new self($this->db, $rp, $this->pdf);
    }

    /** Immutable copy dengan PDF renderer diubah. */
    public function withPdf(?PdfRenderer $pdf): self
    {
        return new self($this->db, $this->roleProvider, $pdf);
    }
}
