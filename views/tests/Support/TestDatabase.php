<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Support;

use mysqli;

/**
 * Throwaway MySQL database for tests that cannot run on SQLite.
 *
 * A few classes still take `mysqli` directly rather than the `Connection`
 * abstraction — `Migrations\Runner` and anything reached through `Context`.
 * Those need a real MySQL server, and one that is safe to drop tables in:
 * `Runner::reset()` does exactly that.
 *
 * So this creates its own database, named distinctly, and never touches the
 * application's. Connection details come from environment variables, defaulting
 * to a local Laragon/XAMPP setup:
 *
 *   EZDOC_TEST_DB_HOST  (default 127.0.0.1)
 *   EZDOC_TEST_DB_USER  (default root)
 *   EZDOC_TEST_DB_PASS  (default empty)
 *   EZDOC_TEST_DB_NAME  (default ezdoc_test)
 *
 * When no server is reachable, {@see connect()} returns null and the calling
 * test skips — the suite stays green on machines without MySQL.
 */
final class TestDatabase
{
    /** @var mysqli|null */
    private static $conn = null;

    /** @var bool Set once we know a connection cannot be made. */
    private static $unavailable = false;

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function name(): string
    {
        return self::env('EZDOC_TEST_DB_NAME', 'ezdoc_test');
    }

    /**
     * Connect to (creating if needed) the test database.
     *
     * @return mysqli|null Null when no server is reachable.
     */
    public static function connect(): ?mysqli
    {
        if (self::$conn instanceof mysqli) {
            return self::$conn;
        }
        if (self::$unavailable) {
            return null;
        }

        $host = self::env('EZDOC_TEST_DB_HOST', '127.0.0.1');
        $user = self::env('EZDOC_TEST_DB_USER', 'root');
        $pass = self::env('EZDOC_TEST_DB_PASS', '');
        $name = self::name();

        // Guard against ever pointing the suite at a live database. Tests here
        // create and drop tables freely.
        if (!preg_match('/test/i', $name)) {
            throw new \RuntimeException(
                "Refusing to run tests against '{$name}': the database name must contain 'test'."
            );
        }

        // mysqli_report() returns bool, not the previous mode, so read it off
        // the driver before switching and restore that.
        $previous = class_exists('mysqli_driver', false)
            ? (new \mysqli_driver())->report_mode
            : MYSQLI_REPORT_OFF;

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli($host, $user, $pass);

        if ($conn->connect_errno) {
            mysqli_report($previous);
            self::$unavailable = true;
            return null;
        }

        $conn->query(
            'CREATE DATABASE IF NOT EXISTS `' . $name . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $conn->select_db($name);
        mysqli_report($previous);

        self::$conn = $conn;
        return $conn;
    }

    /**
     * Drop every table, giving each test file a clean slate.
     */
    public static function reset(): void
    {
        $conn = self::connect();
        if ($conn === null) {
            return;
        }

        $conn->query('SET FOREIGN_KEY_CHECKS = 0');
        $result = $conn->query('SHOW TABLES');
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $conn->query('DROP TABLE IF EXISTS `' . $row[0] . '`');
        }
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
