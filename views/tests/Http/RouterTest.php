<?php

declare(strict_types=1);

namespace Ezdoc\Tests\Http;

use Ezdoc\Http\AssetHandler;
use Ezdoc\Http\RequestContext;
use Ezdoc\Http\ResponseWriter;
use Ezdoc\Http\Router;
use Ezdoc\UI\Config;
use Ezdoc\UI\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests untuk Ezdoc\Http\Router.
 *
 * Focus:
 * - match() basic behavior (whitelist, asset short-circuit, legacy action)
 * - register() adds handler + dispatch invokes it
 * - Route alias (v1.0-prep) — backward-compat forwarding
 * - Direct sub-view routes (template_list, template_designer, etc.) whitelisted
 *
 * PHP 7.4+ compatible.
 */
final class RouterTest extends TestCase
{
    private function makeRouter(array $configOverrides = []): Router
    {
        $config = new Config(array_merge([
            'app.query_key' => 'ezdoc_page',
            'app.asset_key' => 'ezdoc_asset',
            'app.base_path' => '',
        ], $configOverrides));
        $theme  = new Theme($config);
        $assets = new AssetHandler([__DIR__ . '/../../assets'], 86400);
        return new Router($config, null, $theme, $assets);
    }

    private function makeRequest(array $query = [], array $post = [], string $method = 'GET'): RequestContext
    {
        return RequestContext::fromArray([
            'method' => $method,
            'query'  => $query,
            'post'   => $post,
        ]);
    }

    // ─── match() basics ────────────────────────────────────────────────

    public function testMatchReturnsNullWhenNoQuery(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest();
        $this->assertNull($router->match($req));
    }

    public function testMatchWhitelistedPage(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $this->assertSame('list', $router->match($req));
    }

    public function testMatchRejectsNonWhitelistedPage(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'malicious_page']);
        $this->assertNull($router->match($req));
    }

    public function testAssetShortCircuit(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_asset' => 'foo.css']);
        $this->assertSame('asset', $router->match($req));
    }

    public function testLegacyActionAjaxPost(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest([], ['_ajax' => 1], 'POST');
        $this->assertSame('action', $router->match($req));
    }

    public function testGenerateQrLegacyDispatch(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['action' => 'generate_qr']);
        $this->assertSame('action', $router->match($req));
    }

    // ─── Direct sub-view routes (v1.0-prep) ────────────────────────────

    public function testTemplateListRouteWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'template_list']);
        $this->assertSame('template_list', $router->match($req));
    }

    public function testTemplateDesignerRouteWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'template_designer']);
        $this->assertSame('template_designer', $router->match($req));
    }

    public function testGenerateListRouteWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'generate_list']);
        $this->assertSame('generate_list', $router->match($req));
    }

    public function testDocumentGenerateRouteWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'document_generate']);
        $this->assertSame('document_generate', $router->match($req));
    }

    // ─── Legacy sub-view dispatch routes tetap ada ─────────────────────

    public function testLegacyDesignerRouteStillWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'designer']);
        $this->assertSame('designer', $router->match($req));
    }

    public function testLegacyGenerateRouteStillWhitelisted(): void
    {
        $router = $this->makeRouter();
        $req = $this->makeRequest(['ezdoc_page' => 'generate']);
        $this->assertSame('generate', $router->match($req));
    }

    // ─── register() + dispatch() ───────────────────────────────────────

    public function testCustomHandlerDispatched(): void
    {
        $router = $this->makeRouter();
        $router->register('list', function ($req, $res) {
            return 'CUSTOM_LIST';
        });
        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $res = new ResponseWriter();
        $this->assertSame('CUSTOM_LIST', $router->dispatch($req, $res));
    }

    // ─── Alias mechanism (v1.0-prep) ───────────────────────────────────

    public function testAliasForwardsMatch(): void
    {
        $router = $this->makeRouter();
        // Simulate v1.0 rename: 'designer' → 'template_designer'
        $router->alias('designer', 'template_designer');

        $req = $this->makeRequest(['ezdoc_page' => 'designer']);
        // match() resolves alias → returns canonical
        $this->assertSame('template_designer', $router->match($req));
    }

    public function testAliasForwardsRegistration(): void
    {
        $router = $this->makeRouter();
        $router->alias('old_name', 'list'); // 'old_name' isn't whitelisted, but alias register works

        // Register against alias name — should land in canonical storage
        $called = 0;
        $router->register('old_name', function () use (&$called) {
            $called++;
            return 'ALIAS_HANDLER';
        });

        // Verify canonical handler was overridden
        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $res = new ResponseWriter();
        $this->assertSame('ALIAS_HANDLER', $router->dispatch($req, $res));
        $this->assertSame(1, $called);
    }

    public function testAliasChainResolution(): void
    {
        $router = $this->makeRouter();
        $router->alias('v1', 'v2');
        $router->alias('v2', 'list');

        $router->register('v1', function () { return 'CHAINED'; });

        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $res = new ResponseWriter();
        $this->assertSame('CHAINED', $router->dispatch($req, $res));
    }

    public function testAliasCycleDoesNotInfiniteLoop(): void
    {
        $router = $this->makeRouter();
        $router->alias('a', 'b');
        $router->alias('b', 'a');

        // Register against 'list' (real route) — alias cycle for a/b shouldn't affect
        $router->register('list', function () { return 'OK'; });

        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $res = new ResponseWriter();
        $this->assertSame('OK', $router->dispatch($req, $res));
    }

    public function testAliasEmptyNameIgnored(): void
    {
        $router = $this->makeRouter();
        // Should not throw — empty names silently ignored
        $router->alias('', 'list');
        $router->alias('empty_target', '');

        // Router still functional
        $req = $this->makeRequest(['ezdoc_page' => 'list']);
        $this->assertSame('list', $router->match($req));
    }

    // ─── Unmatched action ──────────────────────────────────────────────

    /**
     * Drive an action request the way a browser does.
     *
     * The dispatcher reads `$_POST` and `$_SERVER` directly rather than the
     * RequestContext the Router carries, so a request built only from the
     * context never reaches the branches being tested. Mirroring production
     * means setting both.
     *
     * @param array<string,mixed> $post
     * @return array{0: string, 1: RequestContext}
     */
    private function dispatchAction(array $post): array
    {
        $savedPost = $_POST;
        $savedServer = $_SERVER;

        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        try {
            $router = $this->makeRouter();
            $req = $this->makeRequest(['ezdoc_page' => 'action'], $post, 'POST');
            $res = new ResponseWriter();

            return [(string) $router->dispatch($req, $res), $req];
        } finally {
            $_POST = $savedPost;
            $_SERVER = $savedServer;
        }
    }

    /**
     * An action request that no dispatcher branch claims used to come back as
     * "200 OK" with an empty body. The caller does `await resp.json()`, so what
     * a developer actually saw was "Unexpected end of JSON input" — which names
     * neither the endpoint nor the missing parameter, and looks exactly like a
     * crash. Hours go into the wrong hypothesis.
     *
     * The legacy consumer-side mount has always answered with a diagnostic
     * here; this asserts both doors now do.
     */
    public function testAnUnmatchedActionAnswersWithADiagnosticNotAnEmptyBody(): void
    {
        [$body] = $this->dispatchAction(['tidak_dikenal' => '1']);

        self::assertNotSame('', $body, 'an empty body is the bug this replaces');

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "not JSON, got: {$body}");
        self::assertFalse($decoded['success']);
        self::assertSame('No matching action for this request', $decoded['message']);
    }

    /**
     * The reply has to say enough to find the mistake — which keys arrived,
     * under which method. Keys only: the values are the caller's payload and
     * do not belong in an error echoed back.
     */
    public function testTheDiagnosticNamesTheKeysItReceived(): void
    {
        [$body] = $this->dispatchAction(['salah_ketik' => 'send_ttd_otp', 'phone' => '08123']);

        $decoded = json_decode($body, true);

        self::assertSame('POST', $decoded['method']);
        self::assertContains('salah_ketik', $decoded['post_keys']);
        self::assertContains('ezdoc_page', $decoded['get_keys']);
        self::assertStringNotContainsString(
            '08123',
            (string) json_encode($decoded),
            'keys are diagnostic; values are the caller\'s payload'
        );
    }
}
