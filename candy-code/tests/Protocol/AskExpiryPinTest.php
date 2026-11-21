<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Host\AskExpiry;
use SugarCraft\ServeProtocol\Protocol;

/**
 * sugar-plugins keeps a literal copy of the host's approval expiry (AskExpiry) because it
 * cannot depend on sugar-serve-protocol. candy-code depends on both libs, so the pin
 * belongs here: a change to either side fails this test instead of drifting silently.
 */
final class AskExpiryPinTest extends TestCase
{
    public function testPluginCeilingEqualsProtocolExpiry(): void
    {
        self::assertSame(Protocol::ASK_EXPIRY_SECONDS, AskExpiry::ASK_EXPIRY_SECONDS);
    }

    public function testBothSidesPinTheShippedThirtyMinuteValue(): void
    {
        self::assertSame(1800, Protocol::ASK_EXPIRY_SECONDS);
        self::assertSame(1800, AskExpiry::ASK_EXPIRY_SECONDS);
    }
}
