<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Protocol;

final class ProtocolTest extends TestCase
{
    public function testMajorIsOne(): void
    {
        self::assertSame(1, Protocol::MAJOR);
    }

    public function testSubprotocolNamesTheMajor(): void
    {
        self::assertSame('candy-code.v1', Protocol::SUBPROTOCOL);
        self::assertSame('candy-code.auth.', Protocol::AUTH_SUBPROTOCOL_PREFIX);
    }

    public function testFrameCapIsFourMebibytes(): void
    {
        self::assertSame(4_194_304, Protocol::MAX_SERVER_FRAME_BYTES);
    }

    public function testAskExpiryIsThirtyMinutes(): void
    {
        self::assertSame(1800, Protocol::ASK_EXPIRY_SECONDS);
    }
}
