<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use SugarCraft\Charts\Picture\Protocol;
use PHPUnit\Framework\TestCase;

/**
 * @see Protocol
 */
final class ProtocolTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = Protocol::cases();
        self::assertCount(3, $cases);
    }

    public function testSixelCase(): void
    {
        $case = Protocol::Sixel;
        self::assertSame('sixel', $case->value);
        self::assertSame('Sixel', $case->name);
    }

    public function testKittyCase(): void
    {
        $case = Protocol::Kitty;
        self::assertSame('kitty', $case->value);
        self::assertSame('Kitty', $case->name);
    }

    public function testITerm2Case(): void
    {
        $case = Protocol::ITerm2;
        self::assertSame('iterm2', $case->value);
        self::assertSame('ITerm2', $case->name);
    }

    /**
     * @dataProvider casesProvider
     */
    public function testValueIsString(Protocol $protocol): void
    {
        self::assertIsString($protocol->value);
    }

    /**
     * @dataProvider casesProvider
     */
    public function testFromValueRoundTrip(Protocol $protocol): void
    {
        self::assertSame($protocol, Protocol::from($protocol->value));
    }

    /**
     * @return iterable<string, array{Protocol}>
     */
    public static function casesProvider(): iterable
    {
        foreach (Protocol::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(Protocol::tryFrom('unknown'));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(\ValueError::class);
        Protocol::from('unknown');
    }
}
