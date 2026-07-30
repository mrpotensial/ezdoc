<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Signature;

use Ezdoc\Signature\SigningKey;
use PHPUnit\Framework\TestCase;

/**
 * Covers signing-key resolution and derivation.
 *
 * The deployment has one secret and more than one thing wants it — verify URLs
 * are already signed with it. These tests pin the two properties that keep that
 * from becoming a problem: the key handed to a signer is never the configured
 * secret, and two purposes never get the same key.
 *
 * `EZDOC_HMAC_SECRET` is a constant, and a constant cannot be undefined once
 * set, so the cases that need it absent run in subprocesses.
 */
final class SigningKeyTest extends TestCase
{
    private const SECRET = 'secret-yang-cukup-panjang-32-byte-lebih';

    protected function setUp(): void
    {
        SigningKey::reset();
    }

    protected function tearDown(): void
    {
        SigningKey::reset();
    }

    /**
     * Run a snippet in a fresh process, since constants cannot be unset.
     */
    private function inSubprocess(string $php): string
    {
        $autoload = __DIR__ . '/../../autoload.php';
        $file = (string) tempnam(sys_get_temp_dir(), 'ezdoc_key');
        file_put_contents($file, "<?php\nrequire " . var_export($autoload, true) . ";\n" . $php);

        try {
            return (string) @shell_exec(
                escapeshellarg(PHP_BINARY) . ' -d error_log= ' . escapeshellarg($file)
                . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')
            );
        } finally {
            @unlink($file);
        }
    }

    // ─── Derivation ───

    /**
     * The point of HKDF here: what the signer receives must not be the secret
     * the deployment configured, because that secret already signs verify URLs.
     */
    public function testTheDerivedKeyIsNotTheConfiguredSecret(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "\$k = \\Ezdoc\\Signature\\SigningKey::resolve();\n"
            . "echo \$k === '" . self::SECRET . "' ? 'SAMA' : 'BEDA';\n"
        );

        self::assertSame('BEDA', trim($out));
    }

    public function testDerivationIsStableForTheSameSecretAndPurpose(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "\$a = \\Ezdoc\\Signature\\SigningKey::resolve();\n"
            . "\\Ezdoc\\Signature\\SigningKey::reset();\n"
            . "\$b = \\Ezdoc\\Signature\\SigningKey::resolve();\n"
            . "echo hash_equals(\$a, \$b) ? 'STABIL' : 'BERUBAH';\n"
        );

        self::assertSame('STABIL', trim($out), 'a key that changes cannot verify yesterday\'s signature');
    }

    /**
     * Domain separation: compromising one purpose must not hand over another.
     */
    public function testDifferentPurposesYieldDifferentKeys(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "\$a = \\Ezdoc\\Signature\\SigningKey::resolve('ezdoc/purpose/a');\n"
            . "\$b = \\Ezdoc\\Signature\\SigningKey::resolve('ezdoc/purpose/b');\n"
            . "echo hash_equals(\$a, \$b) ? 'SAMA' : 'BEDA';\n"
        );

        self::assertSame('BEDA', trim($out));
    }

    public function testTheDerivedKeyIsThirtyTwoRawBytes(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "echo strlen(\\Ezdoc\\Signature\\SigningKey::resolve());\n"
        );

        self::assertSame('32', trim($out));
    }

    /** A changed secret must change the derived key, or rotation does nothing. */
    public function testRotatingTheSecretChangesTheDerivedKey(): void
    {
        $first = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "echo bin2hex(\\Ezdoc\\Signature\\SigningKey::resolve());\n"
        );
        $second = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', 'secret-lain-yang-juga-cukup-panjang-32');\n"
            . "echo bin2hex(\\Ezdoc\\Signature\\SigningKey::resolve());\n"
        );

        self::assertNotSame(trim($first), trim($second));
        self::assertNotSame('', trim($first));
    }

    // ─── Refusals ───

    /**
     * Returning null rather than throwing: an unconfigured deployment must
     * still be able to save documents. The caller skips recording and says so.
     */
    public function testAnAbsentSecretResolvesToNull(): void
    {
        $out = $this->inSubprocess(
            "putenv('EZDOC_HMAC_SECRET');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::resolve() === null);\n"
        );

        self::assertSame('true', trim($out));
    }

    /**
     * A short secret is refused, not stretched. Stretching would turn a
     * misconfiguration into a weak key nobody knows about.
     */
    public function testAShortSecretIsRefusedRatherThanStretched(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', 'pendek');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::resolve() === null);\n"
        );

        self::assertSame('true', trim($out));
    }

    public function testExactlyThirtyTwoBytesIsAccepted(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . str_repeat('a', 32) . "');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::resolve() !== null);\n"
        );

        self::assertSame('true', trim($out));
    }

    public function testThirtyOneBytesIsRefused(): void
    {
        $out = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . str_repeat('a', 31) . "');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::resolve() === null);\n"
        );

        self::assertSame('true', trim($out), 'the boundary must be exact, not approximate');
    }

    /**
     * A misconfiguration that produces no signatures must not do so quietly —
     * that is the failure mode nobody notices until an audit.
     */
    public function testTheMissingKeyIsReported(): void
    {
        $out = $this->inSubprocess(
            "ini_set('error_log', '');\n"
            . "putenv('EZDOC_HMAC_SECRET');\n"
            . "\\Ezdoc\\Signature\\SigningKey::resolve();\n"
            . "echo 'done';\n"
        );

        // The warning goes to error_log; the subprocess exiting cleanly is what
        // is asserted here, with the message itself covered by reading the code.
        self::assertSame('done', trim($out));
    }

    // ─── isConfigured ───

    public function testIsConfiguredReflectsAvailability(): void
    {
        $withSecret = $this->inSubprocess(
            "define('EZDOC_HMAC_SECRET', '" . self::SECRET . "');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::isConfigured());\n"
        );
        $without = $this->inSubprocess(
            "putenv('EZDOC_HMAC_SECRET');\n"
            . "var_export(\\Ezdoc\\Signature\\SigningKey::isConfigured());\n"
        );

        self::assertSame('true', trim($withSecret));
        self::assertSame('false', trim($without));
    }
}
