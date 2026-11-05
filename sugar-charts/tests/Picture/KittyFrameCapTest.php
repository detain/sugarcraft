<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;
use PHPUnit\Framework\TestCase;

/**
 * CH-2 (M18): the kitty graphics protocol caps every single APC frame at
 * 4 KiB measured on the WHOLE frame — opener, transport parameters, the
 * base64 payload and the string terminator. Splitting the base64 stream
 * at a raw 4096 characters produced frames of 4114-4115 bytes, which a
 * spec-faithful terminal truncates mid-payload. These pins sweep
 * GD-generated PNGs of several sizes and refuse any emitted frame above
 * the cap, and prove the chunked payload still reassembles byte-exactly.
 */
final class KittyFrameCapTest extends TestCase
{
    /** The protocol cap mirrored in Picture; sugar-image states the same. */
    private const MAX_FRAME_BYTES = 4096;

    /**
     * Base64 payload bytes per chunk as configured in Picture; a payload
     * of exactly one chunk plus the worst-case 19-byte opening envelope
     * must stay a single legal frame.
     */
    private const CHUNK_PAYLOAD_BYTES = 4072;

    /** @return list<string> every whole `\x1b_G...\x1b\\` frame in $view */
    private static function frames(string $view): array
    {
        if (preg_match_all('/\x1b_G[^\x1b]+\x1b\\\\/s', $view, $m) === false) {
            self::fail('frame capture regex failed');
        }

        return $m[0];
    }

    /** @return list<string> the base64 payloads, in order */
    private static function payloads(string $view): array
    {
        if (preg_match_all('/\x1b_G[^;\x1b]+;([^\x1b]*)\x1b\\\\/s', $view, $m) === false) {
            self::fail('payload capture regex failed');
        }

        return $m[1];
    }

    /** Deterministic noisy truecolor PNG via GD — worst-case compression. */
    private static function noisyPng(int $edge): string
    {
        $image = imagecreatetruecolor($edge, $edge);
        mt_srand(42);
        for ($y = 0; $y < $edge; $y++) {
            for ($x = 0; $x < $edge; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private static function kitty(string $png): string
    {
        return Picture::fromPng($png)->withProtocol(Protocol::Kitty)->view();
    }

    public function testEveryFrameOfGeneratedPngsStaysWithinTheProtocolCap(): void
    {
        foreach ([8, 40, 120] as $edge) {
            $png = self::noisyPng($edge);
            $view = self::kitty($png);
            $frames = self::frames($view);

            $this->assertNotEmpty($frames, "no kitty frames emitted for {$edge}px PNG");
            foreach ($frames as $i => $frame) {
                $this->assertLessThanOrEqual(
                    self::MAX_FRAME_BYTES,
                    strlen($frame),
                    "frame {$i} of a {$edge}px PNG is " . strlen($frame) . " bytes, above the 4 KiB protocol cap",
                );
            }
        }
    }

    public function testLargePngChunksAndReassemblesByteExactly(): void
    {
        $png = self::noisyPng(120);
        $this->assertGreaterThan(
            3054,
            strlen($png),
            'premise: a 120px noise PNG must exceed one chunk payload',
        );

        $view = self::kitty($png);
        $frames = self::frames($view);
        $this->assertGreaterThan(1, count($frames), 'large PNG must produce multiple frames');

        // Only the opening frame carries the transport parameters; every
        // frame is flagged continue (m=1) except the last (m=0).
        $this->assertStringStartsWith("\x1b_Gf=100,a=T,m=1;", $frames[0]);
        $this->assertStringStartsWith("\x1b_Gm=1;", $frames[1]);
        $this->assertStringStartsWith("\x1b_Gm=0;", $frames[count($frames) - 1]);

        $this->assertSame($png, base64_decode(implode('', self::payloads($view)), true));
    }

    public function testPayloadOfExactlyOneChunkStaysASingleFrame(): void
    {
        // 3054 raw bytes = exactly 4072 base64 characters: one full chunk,
        // frame total 4091 bytes (envelope 19) — under the cap, not split.
        $png = str_repeat('B', 3054);
        $view = self::kitty($png);

        $this->assertCount(1, self::frames($view));
        $this->assertSame(
            self::CHUNK_PAYLOAD_BYTES,
            strlen(self::payloads($view)[0]),
        );
        $this->assertStringStartsWith("\x1b_Gf=100,a=T,m=0;", $view);
    }

    public function testOneBytePastOneChunkSplitsAtTheEnvelopeAwareBoundary(): void
    {
        // 3055 raw bytes = 4076 base64 characters: payload splits 4072 + 4,
        // so the second frame exists even though a raw-4096 split would
        // have emitted one oversized frame.
        $png = str_repeat('B', 3055);
        $view = self::kitty($png);
        $payloads = self::payloads($view);

        $this->assertCount(2, $payloads);
        $this->assertSame(self::CHUNK_PAYLOAD_BYTES, strlen($payloads[0]));
        $this->assertSame($png, base64_decode(implode('', $payloads), true));
    }
}
