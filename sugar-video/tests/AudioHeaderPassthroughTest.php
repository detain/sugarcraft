<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\HttpHeaders;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Tests\Concerns\CapturesErrorLog;
use SugarCraft\Video\Tests\Concerns\StubbedPathBinaries;
use SugarCraft\Video\Support\FfmpegCommandBuilder;

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
 * @covers \SugarCraft\Video\AudioPlayer
 * @covers \SugarCraft\Video\Support\FfmpegCommandBuilder
 */
final class AudioHeaderPassthroughTest extends TestCase
{
    use CapturesErrorLog;
    use StubbedPathBinaries;

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
            HttpHeaders::parse(array_merge(self::signedHeaders(), ['User-Agent' => 'SugarVideo/1'])),
        );

        self::assertContains('--http-header-fields=Authorization: Bearer signed-token-9000', $argv);
        self::assertContains('--http-header-fields=Referer: https://player.example.test/', $argv);
        self::assertContains('--http-header-fields=User-Agent: SugarVideo/1', $argv);
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
    // SV-H2: the allowlist's zero-redirect transport pin, audio side
    // -------------------------------------------------------------------------

    public function testRestrictedFfplayPinsZeroRedirectsForNetworkSources(): void
    {
        $argv = FfmpegCommandBuilder::ffplayAudioCommand('/usr/bin/ffplay', self::SOURCE, 0, HttpHeaders::none(), true);

        $idx = array_search('-max_redirects', $argv, true);
        self::assertIsInt($idx, 'a bound allowlist must pin redirects on the audio child too');
        self::assertSame('0', $argv[$idx + 1]);
        self::assertLessThan(count($argv) - 1, $idx, 'input options precede the source');
    }

    public function testRestrictedMpvPinsZeroRedirectsThroughTheLavfOption(): void
    {
        $argv = FfmpegCommandBuilder::mpvAudioCommand('/usr/bin/mpv', self::SOURCE, 0, HttpHeaders::none(), true);
        self::assertContains('--stream-lavf-o=max_redirects=0', $argv);
    }

    public function testRestrictingALocalAudioSourceChangesNoBytes(): void
    {
        // The pin is a network-transport policy; a local file argv must stay
        // byte-identical whether or not a (vacuous) allowlist is bound.
        self::assertSame(
            FfmpegCommandBuilder::ffplayAudioCommand('/usr/bin/ffplay', '/tmp/local.mp4', 0, HttpHeaders::none()),
            FfmpegCommandBuilder::ffplayAudioCommand('/usr/bin/ffplay', '/tmp/local.mp4', 0, HttpHeaders::none(), true),
        );
        self::assertSame(
            FfmpegCommandBuilder::mpvAudioCommand('/usr/bin/mpv', '/tmp/local.mp4', 0, HttpHeaders::none()),
            FfmpegCommandBuilder::mpvAudioCommand('/usr/bin/mpv', '/tmp/local.mp4', 0, HttpHeaders::none(), true),
        );
    }

    public function testRestrictedAudioPlayerCarriesThePinThroughItsWiring(): void
    {
        // Proves the AudioPlayer constructor → buildCommand thread, not just
        // the builder: revert the ctor param and the stubbed argv goes bare.
        $argv = null;
        $this->withStubbedBinaries(['ffplay'], static function () use (&$argv): void {
            $audio = new AudioPlayer(self::SOURCE, null, null, [], false, true);
            $argv = self::argvOf($audio);
        });

        self::assertNotNull($argv);
        self::assertContains('-max_redirects', $argv);
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
        // The argv leg needs a resolvable binary, so it is pinned to a stubbed
        // ffplay/mpv on PATH (round-2 NEW-1): this security assertion must run on
        // hosts without any media tooling — and still never invokes a real binary.
        $audio = null;
        $argv = null;
        $logged = $this->captureErrorLog(function () use (&$audio, &$argv): void {
            $this->withStubbedBinaries(['ffplay', 'mpv'], function () use (&$audio, &$argv): void {
                $audio = new AudioPlayer('/tmp/local-clip.mp4', null, null, self::signedHeaders());
                $argv = self::argvOf($audio);
            });
        });

        self::assertNotNull($argv);
        self::assertNotContains('-headers', $argv, 'a local source must not get -headers');
        self::assertNotContains('-user_agent', $argv);
        self::assertStringContainsString('/tmp/local-clip.mp4', $logged, 'the drop must be reported with the source');
        // The security invariant, pinned in the NEGATIVE direction too (round-1 m4):
        // the whole point of refusing to send credentials to a local path is that
        // neither the values NOR any other bearer material reach stderr. A future
        // "useful for debugging" edit that appends the header list to this notice
        // must fail here, not in someone's log file.
        self::assertStringNotContainsString('Bearer signed-token-9000', $logged, 'never log header VALUES');
        self::assertStringNotContainsString('https://player.example.test/', $logged, 'never log header VALUES');
    }

    /**
     * Round-1 review m5: `isNetworkSource()` only matches http(s), so an
     * rtsp/ftp URL with embedded credentials takes the header-DROP branch and the
     * notice used to interpolate the source verbatim — password included. The drop
     * must name the stream WITHOUT leaking the userinfo it refused to authenticate with.
     */
    public function testDropNoticeRedactsCredentialsInNonHttpSourceUrl(): void
    {
        // Assembled, not literal: an email-shaped URL in source control invites
        // editor/PII scrubbers to rewrite it (which would silently gut this very
        // test), and concatenation keeps the runtime string byte-exact either way.
        $source = 'rtsp://viewer:hunter2' . '@' . 'media.example.test/clip.mp4';
        $audio = null;
        $logged = $this->captureErrorLog(function () use (&$audio, $source): void {
            $audio = new AudioPlayer(
                $source,
                null,
                null,
                self::signedHeaders(),
            );
        });

        self::assertNotNull($audio);
        self::assertStringNotContainsString('hunter2', $logged, 'never log the source password');
        self::assertStringNotContainsString('viewer:', $logged, 'never log the source username either');
        self::assertStringNotContainsString($source, $logged, 'the credential-bearing URL must never reach error_log whole');
        self::assertStringNotContainsString('Bearer signed-token-9000', $logged, 'never log header VALUES');
        self::assertStringContainsString(
            'rtsp://***@media.example.test/clip.mp4',
            $logged,
            'the redacted source must still identify WHICH stream dropped its headers',
        );
        self::assertStringContainsString(
            'audio companion',
            $logged,
            'the audio emitter must name itself, not send the operator hunting for a decoder that does not exist',
        );
    }

    /**
     * Round-2 review NEW-3: the mask covers the WHOLE authority userinfo, not just
     * the run before the first `@` — a non-conforming URL carrying several `@`
     * signs in its authority must not leave a second credential fragment visible.
     * Behaviour on every conforming URL is unchanged.
     */
    public function testRedactCredentialsMasksWholeAuthorityUserinfo(): void
    {
        $redact = new ReflectionMethod(AudioPlayer::class, 'redactCredentials');
        $redact->setAccessible(true);

        self::assertSame(
            'rtsp://***@media.example.test/clip.mp4',
            (string) $redact->invoke(null, 'rtsp://viewer:hunter2' . '@' . 'media.example.test/clip.mp4'),
        );
        self::assertSame(
            'rtsp://***@host.example.test/x',
            (string) $redact->invoke(null, 'rtsp://a' . '@' . 'b:c' . '@' . 'host.example.test/x'),
            'a multi-@ authority must not leak its second fragment',
        );
        self::assertSame(
            'https://cdn.example.test/clip.mp4?start=10&quality=2',
            (string) $redact->invoke(null, 'https://cdn.example.test/clip.mp4?start=10&quality=2'),
            'a credential-free query passes through untouched (L75 recast: this arm used to cite `?sig=` as the benign example — `sig` IS a presigned secret; see testPresignedQuerySecretsAreScrubbedForDisplay)',
        );
        self::assertSame(
            '/tmp/local-clip.mp4',
            (string) $redact->invoke(null, '/tmp/local-clip.mp4'),
            'a bare path passes through untouched',
        );
        self::assertSame(
            'https://host/a' . '@' . 'b.png',
            (string) $redact->invoke(null, 'https://host/a' . '@' . 'b.png'),
            'an @ inside the PATH is not userinfo and must survive',
        );
    }

    /**
     * @testdox L75: presigned query secrets are scrubbed for display, benign parameters survive
     *
     * The signed-stream feature carries its credential in the QUERY
     * (X-Amz-Signature / sig / token / SAS pairs), where the userinfo mask
     * never reaches — so every display surface that echoes a source must run
     * the deny-list scrub too. Display-only law: the scrubbed text exists to
     * be LOGGED; the spawned child keeps the real URL, which the argv-built
     * FfmpegDecoderTest and AudioPlayer header-passthrough arms already pin
     * from the other side.
     */
    public function testPresignedQuerySecretsAreScrubbedForDisplay(): void
    {
        $redact = new ReflectionMethod(AudioPlayer::class, 'redactCredentials');
        $redact->setAccessible(true);

        $signed = 'https://cdn.example.test/clip.mp4'
            . '?X-Amz-Signature=ABC123secret&X-Amz-Credential=KEYID%2F2026&size=10'
            . '&token=tok-456&sig=zzz9&se=2026-01-01T00%3A00%3A00Z&api_key=k7#frag-keeps-anchor';

        $masked = (string) $redact->invoke(null, $signed);

        foreach (['ABC123secret', 'KEYID%2F2026', 'tok-456', 'zzz9', '2026-01-01T00%3A00%3A00Z', 'k7'] as $secret) {
            self::assertStringNotContainsString($secret, $masked, "the value {$secret} must never reach a display surface");
        }
        self::assertStringContainsString('X-Amz-Signature=(redacted)', $masked);
        self::assertStringContainsString('size=10', $masked, 'layout parameters are not secrets and must survive for context');
        self::assertStringContainsString('#frag-keeps-anchor', $masked, 'the fragment rides untouched');

        // Both masks compose: userinfo AND query.
        $both = (string) $redact->invoke(null, 'http://user:pw@example.test/a.mp4?token=xyz&x=1');
        self::assertSame('http://***@example.test/a.mp4?token=(redacted)&x=1', $both);

        // A keyless parameter and an empty-value secret param are both honest.
        self::assertSame(
            'https://h/p?bareflag&token=(redacted)',
            (string) $redact->invoke(null, 'https://h/p?bareflag&token='),
            'an empty secret value still lands as (redacted); a flagless name survives',
        );

        // Case-insensitivity without case destruction: the NAME is echoed as sent.
        self::assertSame(
            'https://h/p?SiG=(redacted)&Expires=(redacted)',
            (string) $redact->invoke(null, 'https://h/p?SiG=abc&Expires=99'),
        );
    }

    /**
     * @testdox L75: the header-drop log surface never echoes a presigned value
     *
     * The finding's second half names two surfaces: decoder.ffmpeg_exit and
     * the header-drop log, both of which quote the SOURCE into display text.
     * (The open-time ffmpeg-missing refusal deliberately names no source at
     * all — no leak surface there by design.) The drop-log arm is driven
     * exactly like the sibling userinfo round-2 case above it: local source
     * + headers ⇒ logged-and-dropped, and the presigned query in the path
     * must arrive scrubbed.
     */
    public function testTheHeaderDropLogSurfaceScrubbsTheSignature(): void
    {
        $logged = $this->captureErrorLog(function (): void {
            $decoder = new \SugarCraft\Video\Decode\FfmpegDecoder();
            try {
                $decoder->open(
                    '/tmp/sv-l75-missing.mp4?X-Amz-Signature=LEAKCANARY42&size=10',
                    8, 4, 10.0,
                    null, 0.0,
                    ['Authorization' => 'Bearer x'],
                );
            } catch (\RuntimeException) {
                // the missing-file refusal after the drop-log is expected —
                // this arm pins WHAT THE LOG SAID, not the refusal path
            } finally {
                $decoder->close();
            }
        });

        self::assertStringNotContainsString('LEAKCANARY42', $logged, 'no presigned value may ride the log text');
        self::assertStringContainsString('X-Amz-Signature=(redacted)', $logged, 'the log still identifies which stream dropped its headers');
        self::assertStringContainsString('size=10', $logged, 'layout parameters survive for context');
    }

    /**
     * Round-1 review m6: `Player::fromDecoder()` is a public boundary like
     * `Reel::openUrl()`, so a malformed header map must throw THERE — not survive
     * construction and explode from inside `update()` when the seek/loop rebuild
     * finally constructs the audio companion mid-tick.
     */
    public function testFromDecoderRejectsMalformedHeadersAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Player::fromDecoder(
            new FakeDecoder([new RgbFrame("\x10\x20\x30", 1, 1)]),
            videoPath: self::SOURCE,
            headers: ['Authorization' => "Bearer x\r\nX-Evil: 1"],
        );
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
        // Round-3 review R3-2: a stubbed ffplay/mpv PATH runs the default-factory
        // chain on every host — nothing executes these binaries; every assertion
        // reads only the argv buildCommand() assembles.
        $this->withStubbedBinaries(['ffplay', 'mpv'], function (): void {
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
        });
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
