<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Support\ErrorLine;

final class ErrorLineTest extends TestCase
{
    public function testTheLineNamesThePrefixTheClassTheMessageAndTheSite(): void
    {
        $e = new \RuntimeException('bad thing');

        $line = ErrorLine::of($e, 'plugin require demo');

        $this->assertStringStartsWith('plugin require demo: RuntimeException: bad thing at ', $line);
        $this->assertStringEndsWith(':' . $e->getLine(), $line);
    }

    public function testControlBytesAreFlattenedSoOneLineStaysOneLine(): void
    {
        $e = new \RuntimeException("first\nsecond\x00third");

        $this->assertStringNotContainsString("\n", ErrorLine::of($e));
        $this->assertStringContainsString('first second third', ErrorLine::of($e));
    }

    public function testTheMessageIsCappedAtTheByteLimit(): void
    {
        $line = ErrorLine::of(new \RuntimeException(str_repeat('x', 500)));

        $this->assertStringContainsString(str_repeat('x', ErrorLine::MAX_MESSAGE_BYTES), $line);
        $this->assertStringNotContainsString(str_repeat('x', ErrorLine::MAX_MESSAGE_BYTES + 1), $line);
    }

    public function testAMultibyteCharacterStraddlingTheCapIsDroppedWhole(): void
    {
        $flat = ErrorLine::flatten(str_repeat('a', ErrorLine::MAX_MESSAGE_BYTES - 1) . 'é');

        $this->assertTrue(mb_check_encoding($flat, 'UTF-8'));
        $this->assertSame(str_repeat('a', ErrorLine::MAX_MESSAGE_BYTES - 1), $flat);
    }
}
