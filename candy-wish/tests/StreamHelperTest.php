<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\StreamHelper;

final class StreamHelperTest extends TestCase
{
    public function testNullOpensTargetStream(): void
    {
        $stream = StreamHelper::openOrValidate(null, 'php://memory');
        $this->assertIsResource($stream);
        fclose($stream);
    }

    public function testInjectedResourceIsReturnedAsIs(): void
    {
        $injected = fopen('php://memory', 'w');
        $this->assertNotFalse($injected);

        try {
            $this->assertSame($injected, StreamHelper::openOrValidate($injected));
        } finally {
            fclose($injected);
        }
    }

    public function testNonResourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamHelper::openOrValidate('not a stream');
    }
}
