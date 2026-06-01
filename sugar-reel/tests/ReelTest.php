<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Reel;

final class ReelTest extends TestCase
{
    public function testOpenRecordsThePath(): void
    {
        $this->assertSame('/tmp/x.mp4', Reel::open('/tmp/x.mp4')->path());
    }

    public function testNewConstructsWithEmptyPath(): void
    {
        $this->assertSame('', Reel::new()->path());
    }
}
