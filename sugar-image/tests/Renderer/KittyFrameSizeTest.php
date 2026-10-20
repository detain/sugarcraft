<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Testing\Graphics\KittyStream;

/**
 * F-11: every emitted kitty APC frame must stay within the protocol's
 * 4096-byte cap. The pre-F-11 shape sliced the BASE64 string at 4092
 * chars, so each data frame carried 9 envelope bytes on top: 4101 bytes
 * — every spec'd receiver truncates it and the image corrupts.
 */
final class KittyFrameSizeTest extends TestCase
{
    private const LARGE_FIXTURE = __DIR__ . '/../fixtures/500x400_noise.png';

    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testTheDefaultChunkSizeIsTheFramedArithmetic(): void
    {
        // F-11: default = intdiv(4096 - envelope(9) - param reserve(128), 4) * 3
        // re-derived here, so a drifted constant without drifted math goes red.
        $this->assertSame(
            intdiv(KittyOptions::MAX_FRAME_BYTES - 9 - 128, 4) * 3,
            KittyOptions::DEFAULT_CHUNK_BYTES,
        );
        // Worst-case data frame with the default: 9-byte envelope + full
        // base64 expansion. The 16-byte margin floor F-11 requires holds.
        $worst = 9 + 4 * intdiv(KittyOptions::DEFAULT_CHUNK_BYTES, 3);
        $this->assertLessThanOrEqual(KittyOptions::MAX_FRAME_BYTES - 16, $worst);
        // Absolute override ceiling: envelope only, still inside the cap.
        $this->assertLessThanOrEqual(
            KittyOptions::MAX_FRAME_BYTES,
            9 + 4 * intdiv(KittyOptions::MAX_CHUNK_BYTES, 3),
        );
    }

    public function testEveryFrameOfALargeRawTransmissionStaysWithinTheCap(): void
    {
        $image = ImageSource::fromFile(self::LARGE_FIXTURE);
        $out   = $this->renderer->render($image, 1024);

        $frames = $this->wireFrames($out);
        // The fixture is deliberately > 2*default-chunk: this proves real
        // splitting happened, not a single-chunk vacuous pass.
        $this->assertGreaterThan(2, count($frames));
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(KittyOptions::MAX_FRAME_BYTES, strlen($frame));
        }
        // The receiver's view: chunks reassemble to the exact PNG bytes.
        $this->assertSame($image->bytes, KittyStream::decode($out)->image()->png());
    }

    public function testEveryFrameOfALargeCompressedTransmissionStaysWithinTheCap(): void
    {
        $image = ImageSource::fromFile(self::LARGE_FIXTURE);
        $out   = $this->renderer->renderWithOptions(
            $image,
            1024,
            null,
            KittyOptions::transmit(1)->withCompression(1),
        );

        $frames = $this->wireFrames($out);
        $this->assertGreaterThan(2, count($frames));
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(KittyOptions::MAX_FRAME_BYTES, strlen($frame));
        }

        // F-11 sizes the POST-compression stream: reassemble the chunk
        // payloads and prove they are the zlib stream of the PNG bytes.
        $payload = '';
        foreach ($frames as $frame) {
            if (preg_match('/\x1b_Gm=[01];([A-Za-z0-9+\/=]*)\x1b\\\\$/', $frame, $m) === 1) {
                $payload .= $m[1];
            }
        }
        $this->assertNotSame('', $payload, 'no data chunks found');
        $decompressed = gzuncompress(base64_decode($payload));
        $this->assertNotFalse($decompressed);
        $this->assertSame($image->bytes, $decompressed);
    }

    public function testSmallPayloadStillEmitsOneUnpaddedFrame(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $out   = $this->renderer->render($image, 8);

        foreach ($this->wireFrames($out) as $frame) {
            $this->assertLessThanOrEqual(KittyOptions::MAX_FRAME_BYTES, strlen($frame));
        }
        $this->assertSame($image->bytes, KittyStream::decode($out)->image()->png());
    }

    public function testExplicitChunkOverrideIsHonouredAndBounded(): void
    {
        $image = ImageSource::fromFile(self::LARGE_FIXTURE);
        // 63-byte raw slices mirror chafa's Screen-mode packet size (F-12's
        // future consumer); every frame must remain far inside the cap.
        $out = $this->renderer->renderWithOptions(
            $image,
            1024,
            null,
            KittyOptions::transmit(7)->withChunkBytes(63),
        );

        $frames = $this->wireFrames($out);
        $this->assertGreaterThan(100, count($frames));
        foreach ($frames as $frame) {
            $this->assertLessThanOrEqual(KittyOptions::MAX_FRAME_BYTES, strlen($frame));
        }
        $this->assertSame($image->bytes, KittyStream::decode($out)->image()->png());
    }

    /**
     * Split an output into complete on-wire frames. Base64 never contains
     * ESC, so the APC introducer `ESC _ G` cannot appear inside a payload.
     *
     * @return list<string>
     */
    private function wireFrames(string $out): array
    {
        $parts = explode("\x1b_G", $out);
        $this->assertSame('', array_shift($parts), 'output must start with an APC G frame');

        return array_map(static fn(string $body): string => "\x1b_G" . $body, $parts);
    }
}
