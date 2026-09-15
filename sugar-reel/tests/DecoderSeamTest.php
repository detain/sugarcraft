<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Reel;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Tests\Concerns\CapturesErrorLog;

/**
 * The public decoder-injection seam (Player::fromDecoder) and the embed-without-
 * the-loop accessor (Reel::toPlayer) — the two seams that let a host drive sugar-reel
 * over a NON-file frame source (a server-transcoded RGB24 stream) and mount the
 * resulting Model inside its own program.
 *
 * Everything here runs on test doubles only — no ffmpeg, no network, no audio
 * device — because the point of the seam is that the Player no longer assumes a
 * real file behind the decoder.
 *
 * @covers \SugarCraft\Reel\Player::fromDecoder
 * @covers \SugarCraft\Reel\Reel::toPlayer
 */
final class DecoderSeamTest extends TestCase
{
    use CapturesErrorLog;

    /**
     * @testdox fromDecoder() builds a Player bound to the injected decoder
     */
    public function testFromDecoderBindsInjectedSource(): void
    {
        $decoder = new FakeDecoder($this->blackFrames(5));
        $player = Player::fromDecoder($decoder, 80, 24, 30.0);

        $this->assertInstanceOf(Player::class, $player);
        $this->assertSame($decoder, $player->decoder, 'the public decoder prop hands back the same instance');
        $this->assertSame(Mode::HalfBlock, $player->mode, 'default mode is HalfBlock');
        $this->assertSame(80, $player->cellsW);
        $this->assertSame(24, $player->cellsH);
        $this->assertSame(30.0, $player->fps);
    }

    /**
     * @testdox fromDecoder() honours an explicit render mode
     */
    public function testFromDecoderHonoursMode(): void
    {
        $decoder = new GeometryFakeDecoder(5);
        $player = Player::fromDecoder($decoder, 40, 20, 25.0, Mode::TrueColor);

        $this->assertSame(Mode::TrueColor, $player->mode);
    }

    /**
     * @testdox openForTest() is the deprecated alias — same observable result as fromDecoder
     */
    public function testOpenForTestDelegatesToFromDecoder(): void
    {
        $decoder = new FakeDecoder($this->blackFrames(5));
        $viaAlias = Player::openForTest($decoder, 30.0, 5, 80, 24, videoPath: 'clip.gif');
        $viaSeam = Player::fromDecoder($decoder, 80, 24, 30.0, Mode::HalfBlock, 5, 'clip.gif');

        $this->assertSame($viaAlias->fps, $viaSeam->fps);
        $this->assertSame($viaAlias->cellsW, $viaSeam->cellsW);
        $this->assertSame($viaAlias->cellsH, $viaSeam->cellsH);
        $this->assertSame($viaAlias->mode, $viaSeam->mode);
        $this->assertSame($viaAlias->totalFrames, $viaSeam->totalFrames);
        $path = static fn (Player $p): string => (new \ReflectionProperty(Player::class, 'videoPath'))->getValue($p);
        $this->assertSame('clip.gif', $path($viaAlias), 'the deprecated alias must forward videoPath verbatim');
        $this->assertSame($path($viaAlias), $path($viaSeam));
    }

    /**
     * @testdox an injected reopensInPlace() decoder SURVIVES a backward seek (reopen, not rebuild)
     *
     * Load-bearing for the capability: the old code keyed this off `instanceof
     * FakeDecoder`; now it keys off the decoder's own answer, so a custom
     * (non-Fake) decoder that reopens in place is likewise preserved.
     */
    public function testSeekReusesInPlaceDecoderInstance(): void
    {
        $decoder = new ReopenOnlyDecoder($this->blackFrames(20));
        $player = Player::fromDecoder($decoder, 80, 24, 30.0, totalFrames: 20);
        // Advance to frame 12 so a 10-frame backward seek is genuinely backward.
        $player = $this->setFrameIndex($player, 12);

        [$advanced] = $player->update(new KeyMsg(KeyType::Left));

        $this->assertSame($decoder, $advanced->decoder, 'the in-place decoder is reopened, not replaced');
        $this->assertSame(1, $decoder->reopenCount, 'reopen() was the rebuild mechanism');
    }

    // -------------------------------------------------------------------------
    // Reel::toPlayer() — embed-without-owning-the-loop
    // -------------------------------------------------------------------------

    /**
     * @testdox toPlayer() on an unbound Reel fails loud (no silent synthetic pattern)
     */
    public function testToPlayerThrowsWithoutSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reel::new()->toPlayer();
    }

    /**
     * @testdox toPlayer() returns a Player Model the host can mount, not a running program
     */
    public function testToPlayerReturnsMountableModel(): void
    {
        if (!Probe::hasFFmpeg() || !extension_loaded('gd')) {
            $this->markTestSkipped('ffmpeg + GD required to build a real clip for toPlayer()');
        }
        $gif = $this->tempGif();

        $player = Reel::open($gif)->withSize(12, 8)->toPlayer();

        $this->assertInstanceOf(Player::class, $player);
        // A host drives it: it starts paused and answers the TEA contract.
        $this->assertTrue($player->paused, 'embedded player starts paused for the host to start');
        $this->assertInstanceOf(Decoder::class, $player->decoder);
    }

    /**
     * @testdox Reel::openUrl records request headers retrievable via headers()
     */
    public function testOpenUrlRecordsHeaders(): void
    {
        $reel = Reel::openUrl(
            'https://cdn.example/stream?sig=x',
            ['Authorization' => 'Bearer abc', 'User-Agent' => 'reel/1.0'],
            allowedHosts: ['cdn.example'],
        );

        $this->assertSame(
            ['Authorization' => 'Bearer abc', 'User-Agent' => 'reel/1.0'],
            $reel->headers(),
        );
    }

    /**
     * @testdox Reel::openUrl() rejects a positional LIST as headers (old allowedHosts slot)
     *
     * BC guard for the argument reorder: `openUrl($u, ['cdn.example:8443'])` —
     * the pre-headers positional allowlist — would otherwise parse 'cdn.example'
     * as a legal header NAME and silently drop the SSRF allowlist. The list shape
     * must fail loud with the migration message instead.
     */
    public function testOpenUrlRejectsPositionalHostList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name => value map');
        Reel::openUrl('https://cdn.example/stream.mp4', ['cdn.example:8443']);
    }

    /**
     * @testdox Reel::openUrl() still accepts a bare host list via the named allowedHosts arg
     */
    public function testOpenUrlNamedAllowedHostsUnaffected(): void
    {
        $reel = Reel::openUrl(
            'https://cdn.example/stream.mp4',
            ['Authorization' => 'Bearer abc'],
            ['cdn.example', 'origin.example:8443'],
        );
        $this->assertSame(['Authorization' => 'Bearer abc'], $reel->headers());
        // The third positional is still the allowlist — a refactor that quietly
        // dropped it would open the SSRF surface this parameter exists to close.
        $this->assertSame(['cdn.example', 'origin.example:8443'], $reel->allowedHosts());
    }

    /**
     * @testdox withHeaders() rejects a smuggling attempt at the boundary
     */
    public function testWithHeadersRejectsInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reel::open('/tmp/x.mkv')->withHeaders(['X-Evil' => "a\r\nb"]);
    }

    /**
     * @testdox withHeaders() is immutable and threads through the value chain
     */
    public function testWithHeadersIsImmutable(): void
    {
        $base = Reel::open('/tmp/x.mkv');
        $withH = $base->withHeaders(['Cookie' => 'sid=1']);

        $this->assertSame([], $base->headers(), 'the original is untouched');
        $this->assertSame(['Cookie' => 'sid=1'], $withH->headers());
        // A subsequent withSize() must PRESERVE the headers (the with() helper carries them).
        $this->assertSame(['Cookie' => 'sid=1'], $withH->withSize(100, 40)->headers());
    }

    // -------------------------------------------------------------------------
    // SSRF advisory (warnRemoteSsrf)
    // -------------------------------------------------------------------------

    /**
     * @testdox openUrl() WITHOUT an allowlist logs an advisory naming only the host — never the signed query
     *
     * The advisory is the last line of defence against the silent-SSRF surface
     * (ffmpeg resolves DNS and follows redirects). This pins three properties:
     * it fires without an allowlist, it logs the host, and a `?sig=` token in
     * the URL does NOT reach the error log. openUrl() is pure recording — it
     * spawns nothing — so no binary guard is needed.
     */
    public function testOpenUrlWithoutAllowlistLogsSsrfAdvisoryWithoutLeakingQuery(): void
    {
        $logged = $this->captureErrorLog(static function (): void {
            $reel = Reel::openUrl('https://cdn.example/stream.m3u8?sig=SUPERSECRET');
            // Pinning laziness: merely binding the source must not spawn a decoder.
            // (If it did, a missing ffmpeg would throw before the log could be read.)
            self::assertNull($reel->allowedHosts());
        });

        $this->assertStringContainsString('cdn.example', $logged);
        $this->assertStringContainsString('without a host allowlist', $logged);
        $this->assertStringNotContainsString('SUPERSECRET', $logged, 'the signed query must not leak to the log');
        $this->assertStringNotContainsString('stream.m3u8', $logged, 'even the path is withheld — host only');
    }

    /**
     * @testdox openUrl() WITH an allowlist stays silent — the operator made the decision
     */
    public function testOpenUrlWithAllowlistDoesNotLogSsrfAdvisory(): void
    {
        $logged = $this->captureErrorLog(static function (): void {
            Reel::openUrl('https://cdn.example/stream.m3u8', [], ['cdn.example']);
        });

        $this->assertStringNotContainsString('without a host allowlist', $logged);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /**
     * @return list<RgbFrame>
     */
    private function blackFrames(int $n): array
    {
        return array_fill(0, $n, new RgbFrame("\x00\x00\x00", 1, 1));
    }

    /**
     * Set the private frameIndex via a rebuilt Player (mirrors PlayerTest's helper,
     * kept local so this file is self-contained).
     *
     * Uses NAMED ctor args: a ctor param insert/reorder then fails loudly at the
     * call site instead of silently mis-binding 24 positional values.
     */
    private function setFrameIndex(Player $player, int $index): Player
    {
        $build = \Closure::bind(
            static fn (Player $p, int $i): Player => new Player(
                decoder: $p->decoder,
                mode: $p->mode,
                speed: $p->speed,
                paused: $p->paused,
                videoTime: $p->videoTime,
                frameIndex: $i,
                currentFrame: $p->currentFrame,
                lastTickTime: $p->lastTickTime,
                fps: $p->fps,
                totalFrames: $p->totalFrames,
                cellsW: $p->cellsW,
                cellsH: $p->cellsH,
                videoPath: $p->videoPath,
                audioPlayer: $p->audioPlayer,
                ended: $p->ended,
                loop: $p->loop,
                ramp: $p->ramp,
                audioFactory: $p->audioFactory,
                cellPxW: $p->cellPxW,
                cellPxH: $p->cellPxH,
                subtitles: $p->subtitles,
                renderer: $p->renderer,
                headers: $p->headers,
                frameBudgetMs: $p->frameBudgetMs,
            ),
            null,
            Player::class,
        );

        return $build($player, $index);
    }

    private function tempGif(): string
    {
        $img = imagecreatetruecolor(8, 6);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 20, 30));
        $path = tempnam(sys_get_temp_dir(), 'reelseam') . '.gif';
        imagegif($img, $path);
        imagedestroy($img);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}

/**
 * A decoder that reopens in place (answers the capability true) and counts reopens —
 * deliberately NOT a FakeDecoder, to prove the Player now keys the decision off the
 * capability rather than an `instanceof` a concrete test class.
 */
final class ReopenOnlyDecoder implements Decoder
{
    public int $reopenCount = 0;

    private int $index = 0;

    /**
     * @param list<RgbFrame> $frames
     */
    public function __construct(private readonly array $frames)
    {
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->index = 0;
    }

    public function next(): ?RgbFrame
    {
        return $this->frames[$this->index++] ?? null;
    }

    public function close(): void
    {
    }

    public function getIterator(): \Generator
    {
        while (($frame = $this->next()) !== null) {
            yield $frame;
        }
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->reopenCount++;
        $this->index = 0;
    }

    public function reopensInPlace(): bool
    {
        return true;
    }
}
