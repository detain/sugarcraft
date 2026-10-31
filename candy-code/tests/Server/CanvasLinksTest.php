<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\Http\CanvasLinks;

/**
 * The canvas link ledger (C-TOOL-6.g): a minted token is 64 hex, consumes
 * exactly once to the sessionId/artifactId pair it was minted for, dies when
 * its 60-second ttl passes, and the bound capacity prunes oldest-first so a
 * click-happy client cannot grow the live-link table without end.
 */
final class CanvasLinksTest extends TestCase
{
    public function testTheLinkTtlIsSixtySeconds(): void
    {
        self::assertSame(60.0, CanvasLinks::TTL_SECONDS);
    }

    public function testAMintedTokenIsHexAndConsumesOnceToItsOwnBinding(): void
    {
        $links = CanvasLinks::new();
        $session = 'sess-' . \uniqid('a', true);
        $artifact = 'canvas-' . \uniqid('b', true);

        $token = $links->mint($session, $artifact);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame(1, $links->pending());
        self::assertSame(['sessionId' => $session, 'artifactId' => $artifact], $links->consume($token));
        self::assertNull($links->consume($token), 'a link is a capability consumed on first sight');
        self::assertSame(0, $links->pending());
    }

    public function testTwoLinksToTheSameArtifactAreIndependentTokens(): void
    {
        $links = CanvasLinks::new();
        $first = $links->mint('s-a', 'art-x');
        $second = $links->mint('s-a', 'art-x');

        self::assertNotSame($first, $second);
        self::assertNotNull($links->consume($first));
        self::assertNotNull($links->consume($second));
    }

    public function testUnknownAndEmptyTokensConsumeNothingAndBreakNothing(): void
    {
        $links = CanvasLinks::new();
        $live = $links->mint('s-live', 'art-live');

        self::assertNull($links->consume(''));
        self::assertNull($links->consume('nope'));
        self::assertNull($links->consume(\bin2hex(\random_bytes(32))));
        self::assertSame(1, $links->pending());
        self::assertNotNull($links->consume($live));
    }

    public function testLinksDieWhenTheirTtlPasses(): void
    {
        $now = 1000.0;
        $links = CanvasLinks::new(static function () use (&$now): float {
            return $now;
        });
        $token = $links->mint('s-ttl', 'art-ttl');

        $now += 31.0;
        self::assertNotNull($links->consume($token), 'half the ttl, still alive — the binding rode through');

        $second = $links->mint('s-ttl', 'art-second');
        $now += 61.0;
        self::assertNull($links->consume($second), 'past sixty seconds the ledger forgets it');
        self::assertSame(0, $links->pending(), 'the expired entry is purged by the passing consume');
    }

    public function testTheCapacityBoundPrunesTheOldestLiveLinkFirst(): void
    {
        $now = 5000.0;
        $links = CanvasLinks::new(static function () use (&$now): float {
            return $now;
        });

        $oldest = $links->mint('s-cap', 'art-0');
        for ($i = 1; $i < 257; $i++) {
            $links->mint('s-cap', 'art-' . $i);
        }
        self::assertLessThanOrEqual(256, $links->pending(), 'the ledger never grows past its bound');
        self::assertNull($links->consume($oldest), 'expiry ties break by age of mint: the first link dies');
        self::assertNotNull($links->consume($links->mint('s-cap', 'art-latest')));
    }
}
