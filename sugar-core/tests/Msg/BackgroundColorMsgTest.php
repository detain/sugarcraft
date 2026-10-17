<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use PHPUnit\Framework\TestCase;

final class BackgroundColorMsgTest extends TestCase
{
    public function testIsDarkForBlack(): void
    {
        $msg = new BackgroundColorMsg(0, 0, 0);
        $this->assertTrue($msg->isDark());
    }

    public function testIsDarkForWhite(): void
    {
        $msg = new BackgroundColorMsg(255, 255, 255);
        $this->assertFalse($msg->isDark());
    }

    public function testIsDarkForMidGray(): void
    {
        // Mid-gray (128,128,128) → ~0.502 luminance — just over the 0.5 line.
        $msg = new BackgroundColorMsg(128, 128, 128);
        $this->assertFalse($msg->isDark());
    }

    public function testIsDarkForDarkBlue(): void
    {
        $msg = new BackgroundColorMsg(20, 20, 80);
        $this->assertTrue($msg->isDark());
    }

    public function testHexFormatIsLowercase(): void
    {
        $msg = new BackgroundColorMsg(255, 128, 0);
        $this->assertSame('#ff8000', $msg->hex());
    }

    public function testHexZeroPads(): void
    {
        $msg = new BackgroundColorMsg(0, 0, 0);
        $this->assertSame('#000000', $msg->hex());
    }

    public function testRgbFieldsAreReadable(): void
    {
        $msg = new BackgroundColorMsg(10, 20, 30);
        $this->assertSame(10, $msg->r);
        $this->assertSame(20, $msg->g);
        $this->assertSame(30, $msg->b);
    }
}
