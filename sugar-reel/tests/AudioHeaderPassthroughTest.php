<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Reel\AudioPlayer;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\HttpHeaders;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Tests\Concerns\CapturesErrorLog;
use SugarCraft\Reel\Support\FfmpegCommandBuilder;

/**
 * Finding #52 (audio half): the request headers that authenticate a signed media
 * URL must reach the AUDIO child as well as the video child.
 *
 * Before this change a host could hand `Player::open()` an `Authorization`
 * header, watch the video play, and hear nothing at all: ffmpeg was re-presenting
 * the token while ffplay/mpv was built without it and silently 403'd. These tests
 * pin the three links of that chain — the shared builder emits the fields, the
 * audio wrapper carries them per source kind, and the Player re-presents them on
 * every rebuild of the companion.
 *
 * No ffmpeg/ffplay/mpv binary is required: the argv is assembled purely from the
 * source string, and the one test that needs the real probed binary skips instead
 * of shelling out.
 *
 * @covers \SugarCraft\Reel\AudioPlayer
 * @covers \SugarCraft\Reel\Support\FfmpegCommandBuilder
 */
final class AudioHeaderPassthroughTest extends TestCase
{
    use CapturesErrorLog;

    private const SOURCE = 'https://cdn.example.test/clip.mp4?sig=a1b2';

    /**
     * @return array<string, string>
     */
    private static function signedHeaders(): array
    {
        return [
            'Authorization' => 'Bearer signed-token-9000',
            'Referer' => 'https://player.example.test/',
        ];
    }

    /**
     * Expose the protected argv builder without spawning anything.
     *
     * @return list<string>|null
     */
    private static function argvOf(AudioPlayer $audio): ?array
    {
        $method = new ReflectionMethod(AudioPlayer::class, 'buildCommand');
        $method->setAccessible(true);

        /** @var list<string>|null $cmd */
        $cmd = $method->invoke($audio);

        return $cmd;
    }

    // -------------------------------------------------------------------------
    // The shared builder emits the fields
    // -------------------------------------------------------------------------

    public function testFfplayBuilderEmitsHeadersAsSingleArguments(): void
    {
        $argv = FfmpegCommandBuilder::ffplayAudioCommand(
            '/usr/bin/ffplay',
            self::SOURCE,
            0,
            HttpHeaders::parse(self::signedHeaders()),
        );

        $headersIndex = array_search('-headers', $argv, true);
        self::assertIsInt($headersIndex, 'the ffplay argv must carry -headers');

        // One argv element holds the WHOLE blob: the array proc_open form means no
        // shell re-splitting, so a value with a space must survive intact.
        $blob = $argv[$headersIndex + 1];
        self::assertIsString($blob);
        self::assertStringContainsString('Authorization: Bearer signed-token-9000', $blob);
        self::assertStringContainsString('Referer: https://player.example.test/', $blob);
        self::assertStringEndsWith("\r\n", $blob, 'ffmpeg wants each field CRLF-terminated');
        self::assertSame(self::SOURCE, $argv[count($argv) - 1], 'the URL must stay the last argument');
    }

    public function testMpvBuilderEmitsOneHeaderFieldFlagPerPair(): void
    {
        $argv = FfmpegCommandBuilder::mpvAudioCommand(
            '/usr/bin/mpv',
            self::SOURCE,
            2_500,
            HttpHeaders::parse(array_merge(self::signedHeaders(), ['User-Agent' => 'SugarReel/1'])),
        );

        self::assertContains('--http-header-fields=Authorization: Bearer signed-token-9000', $argv);
        self::assertContains('--http-header-fields=Referer: https://player.example.test/', $argv);
        self::assertContains('--http-header-fields=User-Agent: SugarReel/1', $argv);
        // mpv takes the UA as a field line, never as a separate --user-agent, so
        // there must be exactly one User-Agent on the wire.
        self::assertSame(
            1,
            count(array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--http-header-fields=User-Agent:'))),
        );
        self::assertContains('--start=2.500s', $argv);
        self::assertNotContains('--user-agent', $argv);
    }

    // -------------------------------------------------------------------------
    // The audio wrapper carries them, per source kind
    // -------------------------------------------------------------------------

    public function testRemoteAudioPlayerPassesHeadersIntoItsArgv(): void
    {
        $ffplay = Probe::ffplay();
        if ($ffplay === null) {
            self::markTestSkipped('ffplay not installed; the argv is covered by the builder tests');
        }

        $audio = new AudioPlayer(self::SOURCE, 1_500, null, self::signedHeaders());
        $argv = self::argvOf($audio);

        self::assertNotNull($argv);
        self::assertContains('-headers', $argv);
        self::assertContains('-ss', $argv);
        self::assertSame(self::SOURCE, $argv[count($argv) - 1]);
        $blob = $argv[(int) array_search('-headers', $argv, true) + 1];
        self::assertStringContainsString('Bearer signed-token-9000', $blob);
    }

    public function testLocalAudioPlayerDropsHeadersAndSaysSo(): void
    {
        // A local path cannot carry HTTP headers: ffmpeg would reject the option,
        // and silently forwarding them would hide a host misconfiguration.
        $audio = null;
        $argv = null;
        $logged = $this->captureErrorLog(function () use (&$audio, &$argv): void {
            $audio = new AudioPlayer('/tmp/local-clip.mp4', null, null, self::signedHeaders());
            $argv = self::argvOf($audio);
        });

        self::assertNotNull($argv);
        self::assertNotContains('-headers', $argv, 'a local source must not get -headers');
        self::assertNotContains('-user_agent', $argv);
        self::assertStringContainsString('/tmp/local-clip.mp4', $logged, 'the drop must be reported with the source');
    }

    public function testLineBreakInHeaderValueIsRejectedAtTheBoundary(): void
    {
        $smuggled = ['Authorization' => "Bearer x\r\nX-Evil: 1"];

        $this->expectException(\InvalidArgumentException::class);
        new AudioPlayer(self::SOURCE, null, null, $smuggled);
    }

    // -------------------------------------------------------------------------
    // The Player re-presents them on every companion rebuild
    // -------------------------------------------------------------------------

    public function testSeekRebuildsAudioWithTheSameHeaders(): void
    {
        $captured = [];
        $factory = static function (string $path, ?int $ms, array $headers = []) use (&$captured): ?AudioPlayer {
            $captured[] = ['path' => $path, 'ms' => $ms, 'headers' => $headers];

            return null;
        };

        $player = Player::fromDecoder(
            new FakeDecoder(array_fill(0, 4, new RgbFrame("\x10\x20\x30", 1, 1))),
            fps: 24.0,
            totalFrames: 4,
            videoPath: self::SOURCE,
            audioFactory: $factory,
            audioPlayer: new HeaderSpyAudioPlayer(self::SOURCE),
            headers: self::signedHeaders(),
        );

        $seeked = $player->withSeek(2);

        self::assertNotCount(0, $captured, 'a seek must rebuild the audio companion');
        foreach ($captured as $call) {
            self::assertSame(self::signedHeaders(), $call['headers'], 'the rebuild must re-present the source headers');
            self::assertSame(self::SOURCE, $call['path']);
        }
        self::assertSame(2, $seeked->frameIndex);
    }

    public function testDefaultAudioFactoryCarriesHeadersThroughToTheChild(): void
    {
        $ffplay = Probe::ffplay();
        if ($ffplay === null) {
            self::markTestSkipped('ffplay not installed; cannot build the default audio command');
        }

        // No audioFactory injected → Player's own default closure runs on rebuild.
        $player = Player::fromDecoder(
            new FakeDecoder(array_fill(0, 4, new RgbFrame("\x10\x20\x30", 1, 1))),
            fps: 24.0,
            totalFrames: 4,
            videoPath: self::SOURCE,
            audioPlayer: new HeaderSpyAudioPlayer(self::SOURCE),
            paused: true,
            headers: self::signedHeaders(),
        );

        $rebuilt = $player->withSeek(1);

        $playerProp = new ReflectionProperty(Player::class, 'audioPlayer');
        $playerProp->setAccessible(true);
        $audio = $playerProp->getValue($rebuilt);
        self::assertInstanceOf(AudioPlayer::class, $audio, 'the seek must have respawned a real companion');

        $prop = new ReflectionProperty(AudioPlayer::class, 'headers');
        $prop->setAccessible(true);
        /** @var HttpHeaders $headers */
        $headers = $prop->getValue($audio);
        self::assertSame(
            [
                ['name' => 'Authorization', 'value' => 'Bearer signed-token-9000'],
                ['name' => 'Referer', 'value' => 'https://player.example.test/'],
            ],
            $headers->pairs(),
            'the default factory must hand the headers to the respawned AudioPlayer',
        );

        $argv = self::argvOf($audio);
        self::assertNotNull($argv);
        self::assertContains('-headers', $argv);
    }

    public function testModeCycleKeepsHeadersOnTheRebuiltDecoder(): void
    {
        // The half-block cycle re-opens in place, forwarding the header map so a
        // signed stream survives the geometry change.
        $decoder = new HeaderCapturingDecoder();
        $player = Player::fromDecoder(
            $decoder,
            cellsW: 20,
            cellsH: 10,
            fps: 24.0,
            mode: Mode::HalfBlock,
            totalFrames: 2,
            videoPath: self::SOURCE,
            headers: self::signedHeaders(),
        );

        $m = new KeyMsg(KeyType::Char, 'm');
        for ($i = 0; $i < 5; $i++) {
            [$player] = $player->update($m);
        }

        self::assertGreaterThan(0, $decoder->lastHeaderCount(), 'a geometry rebuild must forward the header map');
        self::assertSame(self::signedHeaders(), $decoder->lastHeaders());
    }

}

/**
 * An AudioPlayer that never spawns: `buildCommand()` answers null, which
 * AudioPlayer::start() treats as "no binary available". Lets a test put a real
 * companion inside a Player and drive the seek/mode rebuild seams without an
 * ffplay on the machine.
 *
 * @internal
 */
final class HeaderSpyAudioPlayer extends AudioPlayer
{
    public function buildCommand(): ?array
    {
        return null;
    }
}

/**
 * A reopen-in-place decoder that remembers the header map it was last given, so
 * a test can prove the Player re-presents authentication across a rebuild.
 *
 * @internal
 */
final class HeaderCapturingDecoder extends FakeDecoder
{
    /** @var array<array-key, string> */
    private array $lastHeaders = [];

    public function __construct()
    {
        parent::__construct([new RgbFrame("\x10\x20\x30", 1, 1)]);
    }

    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->lastHeaders = $headers;
        parent::open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    public function reopen(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, array $headers = []): void
    {
        $this->lastHeaders = $headers;
        parent::reopen($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
    }

    /**
     * @return array<array-key, string>
     */
    public function lastHeaders(): array
    {
        return $this->lastHeaders;
    }

    public function lastHeaderCount(): int
    {
        return count($this->lastHeaders);
    }
}
