<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests\Msg;

use CandyCore\Core\Msg\ForegroundColorMsg;
use PHPUnit\Framework\TestCase;

final class ForegroundColorMsgTest extends TestCase
{
    public function testIsDarkForBlack(): void
    {
        $this->assertTrue((new ForegroundColorMsg(0, 0, 0))->isDark());
    }

    public function testIsDarkForWhite(): void
    {
        $this->assertFalse((new ForegroundColorMsg(255, 255, 255))->isDark());
    }

    public function testHex(): void
    {
        $this->assertSame('#aabbcc', (new ForegroundColorMsg(0xAA, 0xBB, 0xCC))->hex());
    }

    public function testHexAllZero(): void
    {
        $this->assertSame('#000000', (new ForegroundColorMsg(0, 0, 0))->hex());
    }

    public function testHexAllMax(): void
    {
        $this->assertSame('#ffffff', (new ForegroundColorMsg(255, 255, 255))->hex());
    }
}
