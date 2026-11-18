<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\ServeProtocol\Protocol;

/**
 * The candy-code.v1 wire constants are owned by sugar-serve-protocol and
 * re-exported by candy-code's own classes (PL-1b). This pins both sides: the
 * host constant still carries the value the protocol promises, and it equals
 * the library's constant, so a change on one side cannot pass unnoticed.
 */
final class ConstantsPinTest extends TestCase
{
    public function testProtocolMajorIsOneOnBothSides(): void
    {
        self::assertSame(1, Protocol::MAJOR);
        self::assertSame(Protocol::MAJOR, ServerConfig::PROTOCOL_MAJOR);
    }

    public function testSubprotocolIsCandyCodeV1OnBothSides(): void
    {
        self::assertSame('candy-code.v1', Protocol::SUBPROTOCOL);
        self::assertSame(Protocol::SUBPROTOCOL, ServerConfig::SUBPROTOCOL);
    }

    public function testAuthSubprotocolPrefixOnBothSides(): void
    {
        self::assertSame('candy-code.auth.', Protocol::AUTH_SUBPROTOCOL_PREFIX);
        self::assertSame(Protocol::AUTH_SUBPROTOCOL_PREFIX, ServerConfig::AUTH_SUBPROTOCOL_PREFIX);
    }

    public function testMaxServerFrameBytesOnBothSides(): void
    {
        self::assertSame(4_194_304, Protocol::MAX_SERVER_FRAME_BYTES);
        self::assertSame(Protocol::MAX_SERVER_FRAME_BYTES, Dispatcher::MAX_SERVER_FRAME_BYTES);
    }

    public function testAskExpirySecondsOnBothSides(): void
    {
        self::assertSame(1800, Protocol::ASK_EXPIRY_SECONDS);
        self::assertSame(Protocol::ASK_EXPIRY_SECONDS, PendingAsk::EXPIRY_SECONDS);
    }
}
