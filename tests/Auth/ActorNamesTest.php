<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Auth;

use Ezdoc\Auth\ActorDirectory;
use Ezdoc\Auth\CallableRoleProvider;
use Ezdoc\Context;
use PDO;
use Ezdoc\Db\Pdo\PdoConnection;
use PHPUnit\Framework\TestCase;

/**
 * `ezdoc_actor_names()` — the single door between an actor id and a readable
 * name.
 *
 * It replaced three half-built mechanisms: a duck-typed
 * `RoleProvider::displayName()` that nothing implemented and whose referenced
 * spec file never existed, a helper that ran `SELECT nama_pegawai FROM pegawai`
 * from inside the library, and nothing at all.
 *
 * Two properties matter most here and both are about failure. This runs while
 * rendering an audit panel, so a directory that misbehaves must cost names and
 * nothing else — not the history, not the page.
 */
final class ActorNamesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not enabled.');
        }

        require_once dirname(__DIR__, 2) . '/lib/actor_names.php';
    }

    protected function tearDown(): void
    {
        Context::resetDefault();
    }

    private function boot(?ActorDirectory $directory): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $provider = new CallableRoleProvider(
            static function () { return false; },
            static function () { return 7; },
            static function () { return []; }
        );

        Context::setDefault(new Context(new PdoConnection($pdo), $provider, null, null, $directory));
    }

    public function testAWiredDirectoryProvidesNames(): void
    {
        $this->boot(new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                return [18 => 'Budi Santoso', 42 => 'Siti Rahayu'];
            }
        });

        self::assertSame(
            [18 => 'Budi Santoso', 42 => 'Siti Rahayu'],
            ezdoc_actor_names([18, 42])
        );
    }

    /**
     * The interface says implementations must not throw. This is what happens
     * when one does anyway — which is the case worth testing, because the
     * implementation belongs to somebody else.
     */
    public function testADirectoryThatThrowsCostsNamesAndNothingElse(): void
    {
        $this->boot(new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                throw new \RuntimeException('user database is down');
            }
        });

        self::assertSame([], ezdoc_actor_names([18]));
    }

    /** Ids that resolve to nothing are simply absent — the caller decides. */
    public function testUnknownIdsAreOmittedRatherThanFilledIn(): void
    {
        $this->boot(new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                return [18 => 'Budi Santoso'];
            }
        });

        $names = ezdoc_actor_names([18, 999]);

        self::assertArrayHasKey(18, $names);
        self::assertArrayNotHasKey(999, $names);
    }

    /**
     * A host implementation can return anything. Callers are entitled to assume
     * a map of int to non-empty string.
     */
    public function testMalformedReturnsAreFilteredOut(): void
    {
        $this->boot(new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                /** @phpstan-ignore-next-line deliberately wrong shapes */
                return [18 => 'Budi', 19 => '', 20 => null, 21 => 12345, 0 => 'nobody'];
            }
        });

        self::assertSame([18 => 'Budi'], ezdoc_actor_names([18, 19, 20, 21]));
    }

    public function testNoDirectoryAndNoFallbackYieldsNothing(): void
    {
        $this->boot(null);

        self::assertSame([], ezdoc_actor_names([18]));
    }

    /** Duplicates, zeroes, strings and negatives are all normalised away. */
    public function testTheIdListIsCleanedBeforeItIsAsked(): void
    {
        $recorder = new RecordingDirectory();
        $this->boot($recorder);

        ezdoc_actor_names(['18', 18, 0, '', -3, '18']);

        self::assertCount(1, $recorder->calls, 'the directory must be asked once, not once per id');
        self::assertSame([18], $recorder->calls[0]);
    }

    public function testAnEmptyListNeverReachesTheDirectory(): void
    {
        $this->boot(new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                throw new \LogicException('should not be called for an empty list');
            }
        });

        self::assertSame([], ezdoc_actor_names([]));
        self::assertSame([], ezdoc_actor_names([0, '', null]));
    }

    // ─── Context plumbing ───

    /**
     * `with*()` returns a modified copy. Forgetting to carry a dependency
     * across is a silent loss: everything keeps working, names just stop
     * appearing, and nothing points at the copy that dropped them.
     */
    public function testTheDirectorySurvivesEveryImmutableCopy(): void
    {
        $directory = new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                return [];
            }
        };

        $pdo = new PDO('sqlite::memory:');
        $provider = new CallableRoleProvider(
            static function () { return false; },
            static function () { return 1; },
            static function () { return []; }
        );

        $ctx = new Context(new PdoConnection($pdo), $provider, null, null, $directory);

        self::assertSame($directory, $ctx->withPdf(null)->actorDirectory);
        self::assertSame($directory, $ctx->withOtpProvider(null)->actorDirectory);
        self::assertSame($directory, $ctx->withRoleProvider($provider)->actorDirectory);
        self::assertSame($directory, $ctx->withDb(new PdoConnection($pdo))->actorDirectory);
    }

    public function testTheDirectoryCanBeReplacedOnACopy(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $provider = new CallableRoleProvider(
            static function () { return false; },
            static function () { return 1; },
            static function () { return []; }
        );

        $ctx = new Context(new PdoConnection($pdo), $provider);
        self::assertNull($ctx->actorDirectory);

        $directory = new class implements ActorDirectory {
            public function displayNames(array $ids): array
            {
                return [];
            }
        };

        self::assertSame($directory, $ctx->withActorDirectory($directory)->actorDirectory);
        self::assertNull($ctx->actorDirectory, 'the original must not be mutated');
    }
}

/**
 * Records what it was asked, so the batching promise can be checked.
 *
 * A named class rather than an anonymous one: the recording has to outlive the
 * call, and reaching back into an anonymous class from the test turned out to
 * need reflection gymnastics that tested the gymnastics.
 */
final class RecordingDirectory implements ActorDirectory
{
    /** @var list<list<int>> */
    public array $calls = [];

    public function displayNames(array $ids): array
    {
        $this->calls[] = $ids;

        return [];
    }
}
