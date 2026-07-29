<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Covers the shutdown guard that turns a fatal into JSON.
 *
 * It cannot be exercised in-process — the thing it handles ends the process —
 * so each case runs a real PHP subprocess and inspects what came out. That is
 * also the only way to prove the guard survives the failure mode it exists for.
 */
final class FatalJsonGuardTest extends TestCase
{
    private const RESPONSES = __DIR__ . '/../../lib/responses.php';

    /**
     * Run a snippet in a fresh PHP process with the guard loaded.
     */
    private function runSnippet(string $php, bool $debug = false): string
    {
        $prelude = "<?php\n";
        if ($debug) {
            $prelude .= "define('EZDOC_DEBUG', true);\n";
        }
        $prelude .= 'require ' . var_export(self::RESPONSES, true) . ";\n"
            . "ezdoc_guard_fatal_as_json();\n";

        $file = (string) tempnam(sys_get_temp_dir(), 'ezdoc_fatal');
        file_put_contents($file, $prelude . $php);

        try {
            // display_errors off mirrors production, where the empty body appears.
            $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_log= '
                . escapeshellarg($file) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
            return (string) @shell_exec($cmd);
        } finally {
            @unlink($file);
        }
    }

    public function testAFatalErrorStillProducesJson(): void
    {
        $out = $this->runSnippet('fungsi_yang_tidak_ada();');

        $decoded = json_decode($out, true);

        self::assertIsArray($decoded, "not JSON, got: {$out}");
        self::assertFalse($decoded['success']);
        self::assertTrue($decoded['fatal']);
    }

    /**
     * The point of the guard: without it the body is empty and the caller
     * reports a parse error that names nothing.
     */
    public function testWithoutTheGuardTheBodyWouldBeEmpty(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ezdoc_nofatal');
        file_put_contents($file, "<?php\nfungsi_yang_tidak_ada();\n");

        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d error_log= '
                . escapeshellarg($file) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
            $out = (string) @shell_exec($cmd);
        } finally {
            @unlink($file);
        }

        self::assertSame('', trim($out), 'baseline: this is the symptom the guard removes');
    }

    /**
     * A fatal message can name file paths and internals, so it travels only
     * when someone asked for it.
     */
    public function testTheFatalDetailIsWithheldUnlessDebugIsOn(): void
    {
        $out = $this->runSnippet('fungsi_yang_tidak_ada();');
        $decoded = json_decode($out, true);

        self::assertStringNotContainsString('fungsi_yang_tidak_ada', $decoded['message']);
        self::assertStringContainsString('[ezdoc:fatal]', $decoded['message'], 'it should say where to look');
    }

    public function testDebugModeIncludesTheDetail(): void
    {
        $out = $this->runSnippet('fungsi_yang_tidak_ada();', true);
        $decoded = json_decode($out, true);

        self::assertStringContainsString('fungsi_yang_tidak_ada', $decoded['message']);
    }

    /**
     * The Router runs actions inside an output buffer. Partial output from a
     * request that died mid-way must not be left in front of the JSON — that
     * would make the body unparseable for a second reason.
     */
    public function testPartialOutputInsideABufferIsDiscarded(): void
    {
        $out = $this->runSnippet("ob_start();\necho 'setengah jadi';\nfungsi_yang_tidak_ada();");

        self::assertStringNotContainsString('setengah jadi', $out);
        self::assertIsArray(json_decode($out, true), "not JSON, got: {$out}");
    }

    // ─── What it must leave alone ───

    public function testASuccessfulResponseIsNotTouched(): void
    {
        $out = $this->runSnippet("ezdoc_respond_success(['challenge_id' => 7], 'terkirim');");

        $decoded = json_decode($out, true);

        self::assertTrue($decoded['success']);
        self::assertSame(7, $decoded['challenge_id']);
        self::assertArrayNotHasKey('fatal', $decoded);
    }

    public function testAnErrorResponseIsNotTouched(): void
    {
        $out = $this->runSnippet("ezdoc_respond_error('Nomor wajib diisi', 400);");

        $decoded = json_decode($out, true);

        self::assertFalse($decoded['success']);
        self::assertSame('Nomor wajib diisi', $decoded['message']);
        self::assertArrayNotHasKey('fatal', $decoded, 'a handled error is not a fatal');
    }

    /**
     * Warnings and notices are not failures. Replacing a delivered response
     * because something emitted a notice would break working endpoints.
     */
    public function testANoticeDoesNotReplaceADeliveredResponse(): void
    {
        $out = $this->runSnippet(
            "@trigger_error('sekadar peringatan', E_USER_WARNING);\n"
            . "ezdoc_respond_success([], 'tetap sukses');"
        );

        $decoded = json_decode($out, true);

        self::assertTrue($decoded['success']);
        self::assertArrayNotHasKey('fatal', $decoded);
    }

    public function testACleanExitProducesNothingExtra(): void
    {
        $out = $this->runSnippet("echo 'ok';");

        self::assertSame('ok', $out);
    }

    public function testRegisteringTwiceDoesNotDoubleTheOutput(): void
    {
        $out = $this->runSnippet("ezdoc_guard_fatal_as_json();\nfungsi_yang_tidak_ada();");

        self::assertSame(1, substr_count($out, '"fatal"'));
    }
}
