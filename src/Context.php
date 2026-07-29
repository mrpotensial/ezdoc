<?php

declare(strict_types=1);

namespace Ezdoc;

use Ezdoc\Auth\HasRoleProvider;
use Ezdoc\Auth\RoleProvider;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Rendering\PdfRenderer;
use Ezdoc\Signature\Otp\UserOtpProvider;
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
 * Legacy PHP monolith (consumer's own bootstrap sets `$GLOBALS['conn']`):
 * ```php
 * global $conn;               // from consumer's bootstrap file
 * $ctx = Context::fromGlobals();
 * ```
 *
 * Custom wiring (framework consumer, Laravel/Symfony/Filament):
 * ```php
 * $ctx = new Context(
 *     $mysqli,
 *     new LaravelRoleProvider(),  // implements Ezdoc\Auth\RoleProvider
 *     new DompdfRenderer()        // implements Ezdoc\Rendering\PdfRenderer
 * );
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

    /** @var UserOtpProvider|null */
    public $otpProvider;

    public function __construct(mysqli $db, RoleProvider $roleProvider, ?PdfRenderer $pdf = null, ?UserOtpProvider $otpProvider = null)
    {
        $this->db = $db;
        $this->roleProvider = $roleProvider;
        $this->pdf = $pdf;
        $this->otpProvider = $otpProvider;
    }

    /**
     * Create Context dari consumer's own bootstrap globals ($conn, $author_id,
     * $author_role_array). Convenience factory untuk legacy monolith codebase
     * yang pakai global-based bootstrap pattern.
     *
     * Consumer harus set `$GLOBALS['conn']` sebagai mysqli instance dari
     * bootstrap file (whatever the consumer names it — koneksi.php, db.php,
     * config.php, dst.).
     */
    public static function fromGlobals(): self
    {
        $db = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
        if (!$db instanceof mysqli) {
            // EzdocException extends \RuntimeException — backward-compat:
            // existing code yang `catch (\RuntimeException)` tetap catch ini.
            throw new EzdocException(
                'Context::fromGlobals() memerlukan $GLOBALS[\'conn\'] sebagai mysqli instance. '
                . 'Pastikan consumer app\'s bootstrap file sudah di-require sebelum call ini.'
            );
        }

        // Lazy-load OTP provider setup.
        // Resolution order (first match wins):
        //   1. $GLOBALS['EZDOC_OTP_PROVIDER'] — pre-built instance (manual DI)
        //   2. EZDOC_OTP_PROVIDER_CLASS::fromGlobals() — static factory (auto-resolve deps)
        //   3. new EZDOC_OTP_PROVIDER_CLASS() — no-arg constructor (simple providers)
        $otpProvider = null;
        if (isset($GLOBALS['EZDOC_OTP_PROVIDER']) && $GLOBALS['EZDOC_OTP_PROVIDER'] instanceof UserOtpProvider) {
            $otpProvider = $GLOBALS['EZDOC_OTP_PROVIDER'];
        } elseif (defined('EZDOC_OTP_PROVIDER_CLASS') && EZDOC_OTP_PROVIDER_CLASS !== '' && class_exists(EZDOC_OTP_PROVIDER_CLASS)) {
            $otpProviderClass = EZDOC_OTP_PROVIDER_CLASS;
            try {
                // Prefer static factory method if available (Factory Method pattern).
                // Allows provider to resolve its own dependencies from globals/env/config.
                if (method_exists($otpProviderClass, 'fromGlobals')) {
                    $otpProvider = $otpProviderClass::fromGlobals();
                } else {
                    $otpProvider = new $otpProviderClass();
                }
            } catch (\Throwable $e) {
                // Provider stays null — OtpService will throw EzdocException when used.
                @error_log('[ezdoc] OTP provider instantiation failed: ' . $e->getMessage());
            }
        }

        return new self($db, new HasRoleProvider(), null, $otpProvider);
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
        return new self($db, $this->roleProvider, $this->pdf, $this->otpProvider);
    }

    /** Immutable copy dengan roleProvider diubah. */
    public function withRoleProvider(RoleProvider $rp): self
    {
        return new self($this->db, $rp, $this->pdf, $this->otpProvider);
    }

    /** Immutable copy dengan PDF renderer diubah. */
    public function withPdf(?PdfRenderer $pdf): self
    {
        return new self($this->db, $this->roleProvider, $pdf, $this->otpProvider);
    }

    /** Immutable copy dengan UserOtpProvider diubah. */
    public function withOtpProvider(?UserOtpProvider $otpProvider): self
    {
        return new self($this->db, $this->roleProvider, $this->pdf, $otpProvider);
    }
}
