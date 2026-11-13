<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\StreamHelper;

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

    public function testChmodOpenedFileRefusesANameThatMovedToAnotherInode(): void
    {
        // Audit 037/038 shared guard: the mode lands only while the name
        // still resolves to the inode the caller holds. Swap a different
        // file under the name — a plain chmod would stamp the interloper;
        // the guarded helper stamps nothing.
        $a      = sys_get_temp_dir() . '/wish-sh-held-' . uniqid() . '.log';
        $b      = sys_get_temp_dir() . '/wish-sh-interloper-' . uniqid() . '.log';
        $stream = @fopen($a, 'a');
        $this->assertNotFalse($stream);
        try {
            $this->assertNotFalse(file_put_contents($b, 'interloper'));
            $this->assertTrue(chmod($b, 0640));
            $this->assertTrue(unlink($a));
            $this->assertTrue(rename($b, $a), 'the name now resolves to a different inode');
            \clearstatcache(true, $a);
            $this->assertSame(0640, \fileperms($a) & 0777);

            StreamHelper::chmodOpenedFile($stream, $a, 0600);

            \clearstatcache(true, $a);
            $this->assertSame(0640, \fileperms($a) & 0777, 'the interloper keeps its mode');
        } finally {
            @unlink($a);
            @unlink($b);
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
