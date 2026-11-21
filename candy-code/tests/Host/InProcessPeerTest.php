<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\InProcessPeer;

/** The in-process peer carries no credential, so every door-specific identity is null (PL-5c). */
final class InProcessPeerTest extends TestCase
{
    public function testEachPeerHasItsOwnIdThatNamesItsKind(): void
    {
        $first = InProcessPeer::new();
        $second = InProcessPeer::new();

        self::assertStringStartsWith('in-process-', $first->id());
        self::assertNotSame($first->id(), $second->id());
    }

    public function testItAuthenticatesAsInProcessWithNoCredential(): void
    {
        $peer = InProcessPeer::new();

        self::assertSame('in-process', $peer->principal());
        self::assertNull($peer->cookieSession());
        self::assertNull($peer->tokenId());
        self::assertNull($peer->viewerSessions());
    }
}
