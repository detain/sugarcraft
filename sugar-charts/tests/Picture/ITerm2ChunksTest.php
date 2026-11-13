<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;

/**
 * iTerm2 payload chunking (sugar-charts-095, CH-25): the OSC 1337 arm emits
 * the base64 payload in 4072-byte frames instead of one unbounded escape
 * sequence, with the full arguments repeated per frame and the announced
 * `size` letting an accumulator know when the image is complete.
 */
final class ITerm2ChunksTest extends TestCase
{
    private const FRAME_RE = '/\e\]1337;File=inline=1;size=(\d+):(.*)\a/sU';

    public function testSmallPayloadStaysOneFrameByteIdenticalToLegacyShape(): void
    {
        $png = 'PNG' . str_repeat('x', 100);
        $out = Picture::fromPng($png)->withProtocol(Protocol::ITerm2)->view();
        $this->assertSame(
            "\x1b]1337;File=inline=1;size=" . strlen($png) . ':' . base64_encode($png) . "\x07",
            $out,
            'single-chunk transfer must keep the historic one-frame bytes',
        );
    }

    public function testMultiKbPayloadSplitsIntoFrames(): void
    {
        $png = str_repeat('Zk9\x00\xff', 4096);
        $out = Picture::fromPng($png)->withProtocol(Protocol::ITerm2)->view();

        $this->assertGreaterThanOrEqual(3, substr_count($out, "\x07"), 'expected ≥3 OSC frames');
        $this->assertMatchesRegularExpression(self::FRAME_RE, $out);
        preg_match_all(self::FRAME_RE, $out, $m);

        // Reassembly equals the payload.
        $this->assertSame(base64_encode($png), implode('', $m[2]));
        // Every frame announces the same total.
        $this->assertSame([strlen($png)], array_values(array_unique(array_map('intval', $m[1]))));
        // No frame is anywhere near multi-megabyte.
        foreach (explode("\x07", $out) as $frame) {
            if ($frame === '') {
                continue;
            }
            $this->assertLessThan(4200, strlen($frame), 'frame exceeded the envelope budget');
        }
    }

    public function testFramePayloadsAreValidBase64Continuations(): void
    {
        $png = random_bytes(9000);
        $out = Picture::fromPng($png)->withProtocol(Protocol::ITerm2)->view();
        preg_match_all(self::FRAME_RE, $out, $m);
        $joined = implode('', $m[2]);
        $this->assertIsString($joined);
        $this->assertSame($png, base64_decode($joined, true), 'frame boundaries must not corrupt the stream');
    }
}
