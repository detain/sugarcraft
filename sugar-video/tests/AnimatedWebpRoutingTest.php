<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\AnimatedWebpDecoder;
use SugarCraft\Video\Decode\DecoderFactory;
use SugarCraft\Video\Decode\FfmpegDecoder;
use SugarCraft\Video\Lang;
use SugarCraft\Video\Player;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Tests\Concerns\HidesPathBinaries;
use SugarCraft\Video\Tests\Concerns\StubbedPathBinaries;
use SugarCraft\Video\Tests\Support\AnimatedWebpBytes;

/**
 * E-05.2 + F-17: factory routing for animated WebP, and the TTL memo on the
 * binary probe that makes per-reopen presence checks cheap.
 */
final class AnimatedWebpRoutingTest extends TestCase
{
    use HidesPathBinaries;
    use StubbedPathBinaries;

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        AnimatedWebpDecoder::setWebpCapableForTesting(null);
        Probe::clearBinaryCacheForTesting();
        foreach ($this->dirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    private function animFile(): string
    {
        $bytes = AnimatedWebpBytes::animContainer(32, 32, [
            ['still' => hex2bin(AnimatedWebpBytes::RED_32), 'duration' => 100, 'blend' => true],
            ['still' => hex2bin(AnimatedWebpBytes::BLUE_32), 'duration' => 100, 'blend' => true],
        ]);
        $path = AnimatedWebpBytes::write($bytes, 'route.webp');
        $this->dirs[] = dirname($path);

        return $path;
    }

    public function testLocalAnimatedWebpRoutesToTheNewDecoderEvenWithoutFfmpeg(): void
    {
        // THE flip: pre-E-05.2 this exact file with ffmpeg present rode
        // FfmpegDecoder (which shows one base frame) and with ffmpeg absent
        // rode the GIF fallback (loud "not a GIF"). Now it decodes on either
        // host — the ffmpeg requirement is gone for this format.
        $path = $this->animFile();
        $decoder = $this->withoutPathBinaries(
            static fn () => DecoderFactory::new($path, 8, 4, 10.0),
        );
        self::assertInstanceOf(AnimatedWebpDecoder::class, $decoder);
        self::assertNotNull($decoder->next());
    }

    public function testAnimatedWebpWithFfmpegPresentAlsoRoutesToTheGdDecoder(): void
    {
        // The routing does NOT depend on ffmpeg at all: even with a stubbed
        // (present-but-inert) ffmpeg on PATH an animated .webp never reaches
        // it — ffmpeg cannot read ANMF, handing it there would be the bug.
        $path = $this->animFile();
        $decoder = null;
        $this->withStubbedBinaries(['ffmpeg'], function () use ($path, &$decoder): void {
            self::assertTrue(Probe::hasFFmpeg(), 'stub makes the presence probe true');
            $decoder = DecoderFactory::new($path, 8, 4, 10.0);
        });
        self::assertInstanceOf(AnimatedWebpDecoder::class, $decoder);
    }

    public function testStillWebpKeepsItsOldRouteByteUnchanged(): void
    {
        // No ANMF: the sniff declines and the old ladder runs — ffmpeg when
        // present (the stub exits instantly, frames or no frames), GIF
        // fallback when not. The .gif route itself stays pinned by the
        // pre-existing DecoderFactoryTest rows.
        $still = AnimatedWebpBytes::write(hex2bin(AnimatedWebpBytes::RED_32), 'still.webp');
        $this->dirs[] = dirname($still);

        $this->withStubbedBinaries(['ffmpeg'], function () use ($still): void {
            $decoder = DecoderFactory::new($still, 8, 4, 10.0);
            self::assertInstanceOf(FfmpegDecoder::class, $decoder);
            $decoder->close();
        });
    }

    public function testNetworkWebpUrlStillRoutesToFfmpegDespiteTheSuffix(): void
    {
        // The local-file gate must not leak into URLs: an https .webp keeps
        // the ffmpeg transport route (documented decoder limit). The loud
        // missing-ffmpeg error is FfmpegDecoder's own — a webp-route refusal
        // would read differently.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('decoder.ffmpeg_missing'));
        $this->withoutPathBinaries(
            static fn () => DecoderFactory::new('https://example.invalid/a.webp', 8, 4, 10.0),
        );
    }

    public function testIsLocalAnimatedWebpPredicateDirectly(): void
    {
        $path = $this->animFile();
        self::assertTrue(DecoderFactory::isLocalAnimatedWebp($path));
        self::assertFalse(DecoderFactory::isLocalAnimatedWebp('https://x/y.webp'));
        self::assertFalse(DecoderFactory::isLocalAnimatedWebp('/no/such/file.webp'));
        self::assertFalse(DecoderFactory::isLocalAnimatedWebp('/tmp/whatever.mp4'));
    }

    public function testPlayerOpenWiresTheAnimatedWebpDecoderEndToEnd(): void
    {
        $path = $this->animFile();
        // ffprobe is absent in the harness host's hidden PATH → VideoSource
        // zero-degrades to the 24 fps default (pre-existing GIF-path shape);
        // the DECIDER pin is the decoder identity the Player reports.
        $player = $this->withoutPathBinaries(
            static fn () => Player::open($path, 8, 4, fpsOverride: 10.0),
        );
        self::assertInstanceOf(AnimatedWebpDecoder::class, $player->decoder);
    }
}
