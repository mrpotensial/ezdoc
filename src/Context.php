<?php

declare(strict_types=1);

namespace Ezdoc;

use Ezdoc\Auth\HasRoleProvider;
use Ezdoc\Auth\RoleProvider;
use Ezdoc\Db\Connection;
use Ezdoc\Db\Mysqli\MysqliConnection;
use Ezdoc\Db\Pdo\PdoConnection;
use Ezdoc\Exceptions\EzdocException;
use Ezdoc\Rendering\PdfRenderer;
use Ezdoc\Signature\Otp\UserOtpProvider;
use mysqli;
use PDO;

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

    /**
     * The handle the host application supplied, untouched.
     *
     * Kept as-is rather than normalised because existing call sites pass it
     * straight to a Repository (which accepts either) or to mysqli functions.
     * Code that wants a portable handle should call {@see connection()}.
     *
     * @var mysqli|PDO|Connection
     */
    public $db;

    /** @var Connection|null lazily adapted from $db */
    private $connection;

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

    /**
     * Typed `mixed` on purpose: this is the validating boundary, so it has to
     * be able to see — and reject — whatever a host actually passes. Narrowing
     * the docblock to the accepted union would make the guard below look like
     * dead code to a static analyser, which is how such guards get deleted.
     *
     * @param mixed $db Database handle: mysqli, PDO, or Ezdoc\Db\Connection.
     *   The first two are adapted on demand; a Connection is used as given.
     * @throws EzdocException kalau $db bukan salah satu dari ketiganya
     */
    public function __construct($db, RoleProvider $roleProvider, ?PdfRenderer $pdf = null, ?UserOtpProvider $otpProvider = null)
    {
        if (!($db instanceof mysqli) && !($db instanceof PDO) && !($db instanceof Connection)) {
            throw new EzdocException(
                'Ezdoc\\Context: $db must be mysqli, PDO or Ezdoc\\Db\\Connection, got '
                . (is_object($db) ? get_class($db) : gettype($db)) . '.'
            );
        }
        $this->db = $db;
        $this->roleProvider = $roleProvider;
        $this->pdf = $pdf;
        $this->otpProvider = $otpProvider;
    }

    /**
     * The database handle as a portable {@see Connection}.
     *
     * Everything that speaks SQL should go through this rather than `$db`.
     * `$db` is whatever the host handed over — often a raw mysqli — and using
     * it directly is what tied this library to one database engine while the
     * Db layer sat there supporting five.
     *
     * mysqli and PDO are adapted once and cached; the driver picks its own
     * grammar, so the same call works on MySQL, MariaDB, SQLite, Postgres and
     * SQL Server.
     */
    public function connection(): Connection
    {
        if ($this->connection === null) {
            if ($this->db instanceof Connection) {
                $this->connection = $this->db;
            } elseif ($this->db instanceof PDO) {
                $this->connection = new PdoConnection($this->db);
            } else {
                $this->connection = new MysqliConnection($this->db);
            }
        }
        return $this->connection;
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
        if (!($db instanceof mysqli) && !($db instanceof PDO) && !($db instanceof Connection)) {
            // EzdocException extends \RuntimeException — backward-compat:
            // existing code yang `catch (\RuntimeException)` tetap catch ini.
            throw new EzdocException(
                'Context::fromGlobals() memerlukan $GLOBALS[\'conn\'] berupa mysqli, PDO, '
                . 'atau Ezdoc\\Db\\Connection. Pastikan bootstrap host app sudah di-require '
                . 'sebelum call ini. Got: ' . (isset($GLOBALS['conn']) ? (is_object($GLOBALS['conn']) ? get_class($GLOBALS['conn']) : gettype($GLOBALS['conn'])) : 'nothing')
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

    /**
     * Immutable copy dengan db diubah.
     *
     * @param mysqli|PDO|Connection $db
     */
    public function withDb($db): self
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
