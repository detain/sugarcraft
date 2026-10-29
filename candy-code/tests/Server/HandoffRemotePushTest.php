<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Session\Import\RemoteSessionPush;

/**
 * Regression tests for `/handoff --remote <url>` (roadmap C-SES-7.g).
 *
 * Grows in the implementation commit: a live loopback serve receives the
 * exported transcript through `session.import`, the refused-token path
 * surfaces a clear error, and the validate-before-dial rule refuses any
 * non-loopback `ws://` target without opening a socket.
 */
final class HandoffRemotePushTest extends TestCase
{
    public function testTheClientClassResolves(): void
    {
        self::assertTrue(
            class_exists(RemoteSessionPush::class),
            'the remote push client must exist for /handoff --remote to dial through'
        );
    }
}
