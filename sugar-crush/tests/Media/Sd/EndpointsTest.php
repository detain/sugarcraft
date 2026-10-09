<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Sd\Endpoints;
use SugarCraft\Crush\Media\Sd\SdException;

/**
 * The endpoint registry lock: legacy coercion table, pass-through, rejects
 * with recorded reasons, closest-suggestion fail-fast, the closed canonical
 * set, and the bidirectional drift guard against the clone-route snapshot.
 */
final class EndpointsTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function legacyRewrites(): array
    {
        return [
            ['/sdapi/v1/settings', Endpoints::OPTIONS],
            ['/option/sd_model_checkpoint', Endpoints::OPTIONS],
            ['/txt2img/progress', Endpoints::PROGRESS],
            ['/txt2img/skip', Endpoints::SKIP],
            ['/txt2img/interrupt', Endpoints::INTERRUPT],
            ['/tick', Endpoints::INTERNAL_PING],
            ['/v1/images', Endpoints::OPENAI_IMAGES_GENERATIONS],
            ['/refresh-checkpoints-models', Endpoints::REFRESH_CHECKPOINTS],
            ['/mem-usage', Endpoints::MEMORY],
        ];
    }

    /** @dataProvider legacyRewrites */
    public function testEachLegacySpellingRewritesToItsLiveEquivalent(string $legacy, string $canonical): void
    {
        self::assertSame($canonical, Endpoints::resolve($legacy));
    }

    public function testTheTwelveMystageClausesAreAllAccountedForInCode(): void
    {
        // 12 verbatim clauses -> 14 concrete spellings (per-tab trio expands
        // to three) + the /option/ pattern clause stands for its one clause.
        $clauseNames = [
            '/sd-metadata.json', '/internal/info', '/sdapi/v1/settings', '/option/{key}', '/tick',
            '/txt2img/progress', '/txt2img/skip', '/txt2img/interrupt',
            '/v1/images', '/v1/embeddings', '/refresh-checkpoints-models', '/reload-clips',
            '/mem-usage', '/img2img-grids',
        ];
        $covered = 0;
        foreach ($clauseNames as $name) {
            $concrete = str_replace('{key}', 'anything', $name);
            if (isset(Endpoints::LEGACY_ALIASES[$name]) || isset(Endpoints::LEGACY_REJECTIONS[$name]) || str_starts_with($concrete, Endpoints::LEGACY_OPTION_PREFIX)) {
                $covered++;
            }
        }
        self::assertSame(count($clauseNames), $covered, 'every mystage-(h) legacy name carries a mapping-or-reject verdict');
    }

    public function testTheFiveNoEquivalentNamesRefuseWithTheirRecordedReasons(): void
    {
        foreach (Endpoints::LEGACY_REJECTIONS as $name => $why) {
            try {
                Endpoints::resolve($name);
                self::fail("{$name} must be refused, not rewritten");
            } catch (SdException $e) {
                self::assertStringContainsString($name, $e->getMessage());
                self::assertStringContainsString(substr($why, 0, 40), $e->getMessage(), "{$name} refusal must name its why");
            }
        }
    }

    public function testLivePathsPassThroughUnchanged(): void
    {
        foreach (Endpoints::CANONICAL as $path) {
            self::assertSame($path, Endpoints::resolve($path));
        }
    }

    public function testAnUnregisteredNameFailsFastNamingClosestCandidates(): void
    {
        try {
            Endpoints::resolve('/sdapi/v1/txt2imgg');
            self::fail('unknown endpoints must be refused');
        } catch (SdException $e) {
            self::assertStringContainsString('closest registered', $e->getMessage());
            self::assertStringContainsString(Endpoints::TXT2IMG, $e->getMessage(), 'the typo\'s true target must be suggested');
        }
    }

    public function testARelativePathIsRefusedRatherThanGuessedAgainstABase(): void
    {
        $this->expectException(SdException::class);
        $this->expectExceptionMessage('server-root-relative');
        Endpoints::resolve('sdapi/v1/txt2img');
    }

    public function testTheBareOptionPrefixWithoutAKeyIsNotAPath(): void
    {
        $this->expectException(SdException::class);
        Endpoints::resolve(Endpoints::LEGACY_OPTION_PREFIX);
    }

    public function testTheCanonicalRegistryIsAClosedSet(): void
    {
        // Allowlist-by-omission guard: adding an exposed endpoint WITHOUT
        // registering it here (and justifying it against the clone snapshot)
        // reddens this pin. The server-control family stays unregistered by
        // decision — /train/*, /create/*, /flush-memory, server-kill/restart/stop.
        $expected = [
            '/internal/ping',
            '/sdapi/v1/cmd-flags',
            '/sdapi/v1/embeddings',
            '/sdapi/v1/extra-batch-images',
            '/sdapi/v1/extra-single-image',
            '/sdapi/v1/extensions',
            '/sdapi/v1/face-restorers',
            '/sdapi/v1/hypernetworks',
            '/sdapi/v1/img2img',
            '/sdapi/v1/interrupt',
            '/sdapi/v1/interrogate',
            '/sdapi/v1/latent-upscale-modes',
            '/sdapi/v1/memory',
            '/sdapi/v1/options',
            '/sdapi/v1/png-info',
            '/sdapi/v1/progress',
            '/sdapi/v1/prompt-styles',
            '/sdapi/v1/realesrgan-models',
            '/sdapi/v1/refresh-checkpoints',
            '/sdapi/v1/refresh-embeddings',
            '/sdapi/v1/refresh-vae',
            '/sdapi/v1/reload-checkpoint',
            '/sdapi/v1/script-info',
            '/sdapi/v1/scripts',
            '/sdapi/v1/sd-models',
            '/sdapi/v1/sd-vae',
            '/sdapi/v1/samplers',
            '/sdapi/v1/schedulers',
            '/sdapi/v1/skip',
            '/sdapi/v1/txt2img',
            '/sdapi/v1/unload-checkpoint',
            '/sdapi/v1/upscalers',
        ];
        sort($expected);
        $actual = Endpoints::CANONICAL;
        sort($actual);
        self::assertSame($expected, $actual);
    }

    public function testEveryLegacyTargetIsEitherRegisteredOrAFamilyProjection(): void
    {
        foreach (Endpoints::LEGACY_ALIASES as $legacy => $target) {
            if (in_array($target, Endpoints::FAMILY_EXEMPT_TARGETS, true)) {
                self::assertSame('/v1/images', $legacy, 'only the OpenAI images clause may target a non-sdapi family path');

                continue;
            }
            self::assertContains($target, Endpoints::CANONICAL, "{$legacy} rewrites to an unregistered path");
        }
    }

    public function testTheCloneRouteSnapshotAndTheRegistryAgreeBothDirections(): void
    {
        $clone = self::cloneRoutes();
        // Every route the clone registers is either exposed by us or on the
        // deliberate do-not-expose roster (server control trio).
        $notExposed = ['/sdapi/v1/server-kill', '/sdapi/v1/server-restart', '/sdapi/v1/server-stop'];
        foreach ($clone as $route) {
            self::assertTrue(
                in_array($route, Endpoints::CANONICAL, true) || in_array($route, $notExposed, true),
                "clone route {$route} is neither registered nor on the do-not-expose roster — the snapshot drifted",
            );
        }
        // And everything we register exists on the clone.
        foreach (Endpoints::CANONICAL as $path) {
            self::assertContains($path, $clone, "registered path {$path} is absent from the clone snapshot");
        }
    }

    /** @return list<string> */
    private static function cloneRoutes(): array
    {
        $lines = file(__DIR__ . '/../../fixtures/sd/clone-routes.txt', FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($lines);
        $routes = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $routes[] = trim(preg_replace('/\s*\(.*\)$/', '', $line));
        }
        self::assertGreaterThan(20, count($routes), 'a truncated snapshot is not an oracle');

        return $routes;
    }
}
