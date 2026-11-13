<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Player;
use SugarCraft\Video\Reel;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Subtitle\WebVtt;
use SugarCraft\Video\Synthetic;
use SugarCraft\Video\Tests\Concerns\CapturesErrorLog;
use SugarCraft\Video\Tests\Concerns\HidesPathBinaries;

final class ReelTest extends TestCase
{
    use CapturesErrorLog;
    use HidesPathBinaries;

    /**
     * @testdox SV-C1: withAutoMode() was dead-on-arrival — now it builds through the auto ladder
     *
     * Load-bearing: revert withAutoMode() to `new AutoMode()` (private ctor)
     * and this test fails with the original Error; drop the ladder resolution
     * in toPlayer() and the mode assertion reddens.
     */
    public function testWithAutoModeBuildsAPlayerThroughTheAutoLadder(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $gif = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-c1-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            4,
            4,
        );

        try {
            $reel = Reel::open($gif)->withAutoMode();
            $this->assertNull($reel->mode(), 'the AutoMode sentinel stores the auto (null) selection');

            // The Mode|AutoMode union read: an explicit Mode keeps itself.
            $this->assertSame(Mode::Ascii, Reel::open($gif)->withMode(Mode::Ascii)->mode());

            // Live-against-live keeps the pin environment-proof while still
            // reddening a build path that never consults the ladder.
            $expected = null;
            $player = $this->withoutPathBinaries(function () use ($reel, &$expected): Player {
                $expected = RendererFactory::autoMode();

                return $reel->toPlayer();
            });

            try {
                $this->assertSame($expected, $player->mode, 'toPlayer() must resolve the auto selection through the same ladder as Player::open()');
            } finally {
                $player->stop();
            }
        } finally {
            @unlink($gif);
        }
    }
    public function testOpenRecordsThePath(): void
    {
        $this->assertSame('/tmp/x.mp4', Reel::open('/tmp/x.mp4')->path());
    }

    public function testOpenUrlRecordsTheUrlAsThePath(): void
    {
        $url = 'https://srv.example/media/m1/stream?exp=1&sig=abc';
        $this->assertSame($url, Reel::openUrl($url)->path());
    }

    public function testOpenUrlAcceptsPlainHttp(): void
    {
        $this->assertSame('http://box:8096/s.mkv', Reel::openUrl('http://box:8096/s.mkv')->path());
    }

    /**
     * @testdox openUrl() rejects a non-http(s) argument so a path typo fails fast
     */
    public function testOpenUrlRejectsNonHttpScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not an http(s) URL');
        Reel::openUrl('/tmp/local.mp4');
    }

    // -------------------------------------------------------------------------
    // SSRF: openUrl() host allowlist (openUrl second arg + withAllowedHosts())
    // -------------------------------------------------------------------------

    /**
     * @testdox openUrl() with an allowlist REJECTS a non-allowlisted host (SSRF guard)
     *
     * Load-bearing: revert the allowlist branch and the non-allowlisted host is
     * accepted instead of rejected — this test then fails.
     */
    public function testOpenUrlWithAllowlistRejectsNonAllowlistedHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote host not in allowlist');
        Reel::openUrl('https://evil.example/media/stream', allowedHosts: ['trusted.example']);
    }

    /**
     * @testdox openUrl() with an allowlist ACCEPTS an allowlisted host and records it
     */
    public function testOpenUrlWithAllowlistAcceptsAllowlistedHost(): void
    {
        $reel = Reel::openUrl('https://trusted.example/media/stream', allowedHosts: ['trusted.example']);
        $this->assertSame('https://trusted.example/media/stream', $reel->path());
        $this->assertSame(['trusted.example'], $reel->allowedHosts());
    }

    /**
     * @testdox openUrl() host allowlist matching is case-insensitive
     */
    public function testOpenUrlAllowlistIsCaseInsensitive(): void
    {
        $reel = Reel::openUrl('https://TRUSTED.Example/s.mkv', allowedHosts: ['trusted.example']);
        $this->assertSame('https://TRUSTED.Example/s.mkv', $reel->path());
    }

    /**
     * @testdox openUrl() without an allowlist stays unrestricted (allowedHosts() null)
     */
    public function testOpenUrlWithoutAllowlistIsUnrestricted(): void
    {
        // Unset allowlist preserves the prior behavior: any http(s) host accepted.
        $reel = Reel::openUrl('https://anything.example/s.mkv');
        $this->assertSame('https://anything.example/s.mkv', $reel->path());
        $this->assertNull($reel->allowedHosts());
    }

    /**
     * @testdox withAllowedHosts() re-validates an already-bound remote URL and rejects a bad host
     *
     * Load-bearing: revert assertHostAllowed in withAllowedHosts() and the bound
     * bad-host URL is accepted — this test then fails.
     */
    public function testWithAllowedHostsRejectsBoundRemoteBadHost(): void
    {
        $reel = Reel::openUrl('https://evil.example/s.mkv');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote host not in allowlist');
        $reel->withAllowedHosts(['trusted.example']);
    }

    /**
     * @testdox withAllowedHosts() accepts a bound remote URL whose host is allowlisted
     */
    public function testWithAllowedHostsAcceptsBoundRemoteGoodHost(): void
    {
        $reel = Reel::openUrl('https://trusted.example/s.mkv')->withAllowedHosts(['trusted.example']);
        $this->assertSame(['trusted.example'], $reel->allowedHosts());
        $this->assertSame('https://trusted.example/s.mkv', $reel->path());
    }

    /**
     * @testdox withAllowedHosts() on a local (non-remote) source just records the list
     */
    public function testWithAllowedHostsOnLocalSourceRecordsList(): void
    {
        // No remote URL bound → nothing to validate, the list is simply stored.
        $reel = Reel::open('/tmp/x.mp4')->withAllowedHosts(['trusted.example']);
        $this->assertSame(['trusted.example'], $reel->allowedHosts());
        $this->assertNotSame($reel, Reel::open('/tmp/x.mp4'));
    }

    /**
     * @testdox allowedHosts() defaults to null
     */
    public function testAllowedHostsDefaultsToNull(): void
    {
        $this->assertNull(Reel::new()->allowedHosts());
        $this->assertNull(Reel::open('/tmp/x.mp4')->allowedHosts());
    }

    /**
     * @testdox an openUrl() source is configurable just like open() (immutable with*)
     */
    public function testOpenUrlSourceIsConfigurable(): void
    {
        $reel = Reel::openUrl('https://srv/v.mkv')->withSize(120, 40)->withFps(30.0);
        $this->assertSame('https://srv/v.mkv', $reel->path());
        $this->assertSame(120, $reel->cols());
        $this->assertSame(40, $reel->rows());
        $this->assertSame(30.0, $reel->fps());
    }

    public function testNewConstructsWithEmptyPath(): void
    {
        $this->assertSame('', Reel::new()->path());
    }

    // -------------------------------------------------------------------------
    // fps() and withFps()
    // -------------------------------------------------------------------------

    /**
     * @testdox Reel::new()->fps() returns null (default is null)
     */
    public function testFpsDefaultsToNull(): void
    {
        $this->assertNull(Reel::new()->fps());
    }

    /**
     * @testdox Reel::new()->withFps(60.0)->fps() returns 60.0
     */
    public function testWithFpsSetsFps(): void
    {
        $reel = Reel::new()->withFps(60.0);
        $this->assertSame(60.0, $reel->fps());
    }

    /**
     * @testdox Reel::new()->withFps(30.0)->withFps(24.0)->fps() returns 24.0 (override works)
     */
    public function testWithFpsOverrideIsImmutable(): void
    {
        $reel = Reel::new()->withFps(30.0)->withFps(24.0);
        $this->assertSame(24.0, $reel->fps());
    }

    /**
     * @testdox Reel::new()->withFps() returns a new Reel instance (immutable)
     */
    public function testWithFpsReturnsNewInstance(): void
    {
        $original = Reel::new();
        $modified = $original->withFps(60.0);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->fps());
        $this->assertSame(60.0, $modified->fps());
    }

    // -------------------------------------------------------------------------
    // mode() / withMode()
    // -------------------------------------------------------------------------

    /**
     * @testdox Reel::open()->mode() defaults to null (auto-detect)
     */
    public function testModeDefaultsToHalfBlock(): void
    {
        // null mode means auto-detect at play() time (F3).
        $this->assertNull(Reel::open('/tmp/x.mp4')->mode());
    }

    /**
     * @testdox withMode() sets the mode and is immutable
     */
    public function testWithModeSetsModeImmutably(): void
    {
        $original = Reel::open('/tmp/x.mp4');
        $modified = $original->withMode(Mode::Ascii);

        $this->assertNotSame($original, $modified);
        $this->assertNull($original->mode(), 'original keeps null (auto-detect)');
        $this->assertSame(Mode::Ascii, $modified->mode());
        // Other fields are preserved across the with*() call.
        $this->assertSame('/tmp/x.mp4', $modified->path());
    }

    // -------------------------------------------------------------------------
    // cols() / rows() / withSize()
    // -------------------------------------------------------------------------

    /**
     * @testdox cols()/rows() default to 80x24
     */
    public function testSizeDefaults(): void
    {
        $reel = Reel::new();
        $this->assertSame(80, $reel->cols());
        $this->assertSame(24, $reel->rows());
    }

    /**
     * @testdox withSize() sets cols/rows and is immutable
     */
    public function testWithSizeSetsDimensionsImmutably(): void
    {
        $original = Reel::new();
        $modified = $original->withSize(120, 40);

        $this->assertNotSame($original, $modified);
        $this->assertSame(80, $original->cols());
        $this->assertSame(24, $original->rows());
        $this->assertSame(120, $modified->cols());
        $this->assertSame(40, $modified->rows());
    }

    /**
     * @testdox SV-H7: withSize clamps to the documented pane window, and so
     *           does Player::open (the window binds at every size entry)
     */
    public function testSizeEntriesClampToThePaneWindow(): void
    {
        $huge = Reel::new()->withSize(9999, 9999);
        $this->assertSame(200, $huge->cols(), 'the request clamps to MAX_COLS');
        $this->assertSame(80, $huge->rows(), 'the request clamps to MAX_ROWS');

        $tiny = Reel::new()->withSize(0, 0);
        $this->assertSame(10, $tiny->cols(), 'the floor side matters too: cells-0 decodes zero-byte frames');
        $this->assertSame(5, $tiny->rows());

        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }
        $gif = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-h7-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            2,
            4,
        );
        try {
            $player = Player::open($gif, 9999, 9999, mode: Mode::Ascii);
            $this->assertSame(200, $player->cellsW, 'open() clamps before the decoder is minted');
            $this->assertSame(80, $player->cellsH);
            $player->stop();
        } finally {
            @unlink($gif);
        }
    }

    /**
     * @testdox builders chain and each returns a distinct Reel
     */
    public function testBuildersChain(): void
    {
        $reel = Reel::open('/tmp/clip.mp4')
            ->withMode(Mode::TrueColor)
            ->withSize(100, 30)
            ->withFps(25.0);

        $this->assertSame('/tmp/clip.mp4', $reel->path());
        $this->assertSame(Mode::TrueColor, $reel->mode());
        $this->assertSame(100, $reel->cols());
        $this->assertSame(30, $reel->rows());
        $this->assertSame(25.0, $reel->fps());
    }

    // -------------------------------------------------------------------------
    // loop() / withLoop()
    // -------------------------------------------------------------------------

    /**
     * @testdox loop() defaults to false
     */
    public function testLoopDefaultsToFalse(): void
    {
        $this->assertFalse(Reel::new()->loop());
        $this->assertFalse(Reel::open('/tmp/x.mp4')->loop());
    }

    /**
     * @testdox withLoop() enables looping (defaults to true) immutably
     */
    public function testWithLoopEnablesLoopImmutably(): void
    {
        $original = Reel::open('/tmp/x.mp4');
        $looping = $original->withLoop();

        $this->assertNotSame($original, $looping);
        $this->assertFalse($original->loop());
        $this->assertTrue($looping->loop());
        // Other fields are preserved across the with*() call.
        $this->assertSame('/tmp/x.mp4', $looping->path());
    }

    /**
     * @testdox withLoop(false) explicitly disables looping
     */
    public function testWithLoopFalseDisablesLoop(): void
    {
        $reel = Reel::open('/tmp/x.mp4')->withLoop()->withLoop(false);
        $this->assertFalse($reel->loop());
    }

    // -------------------------------------------------------------------------
    // ramp() / withRamp()
    // -------------------------------------------------------------------------

    /**
     * @testdox ramp() defaults to 'standard'
     */
    public function testRampDefaultsToStandard(): void
    {
        $this->assertSame('standard', Reel::new()->ramp());
        $this->assertSame('standard', Reel::open('/tmp/x.mp4')->ramp());
    }

    /**
     * @testdox withRamp() sets the ramp immutably
     */
    public function testWithRampSetsRampImmutably(): void
    {
        $original = Reel::new();
        $dense = $original->withRamp('dense');

        $this->assertNotSame($original, $dense);
        $this->assertSame('standard', $original->ramp());
        $this->assertSame('dense', $dense->ramp());
    }

    /**
     * @testdox withRamp('unknown') throws InvalidArgumentException
     */
    public function testWithRampUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Reel::new()->withRamp('nonexistent');
    }

    /**
     * @testdox withRamp('minimal') and withRamp('dense') set different ramps
     */
    public function testWithRampMinimalAndDenseAreDistinct(): void
    {
        $minimal = Reel::open('/tmp/x.mp4')->withRamp('minimal');
        $dense = Reel::open('/tmp/x.mp4')->withRamp('dense');

        $this->assertNotSame($minimal->ramp(), $dense->ramp());
        $this->assertSame('minimal', $minimal->ramp());
        $this->assertSame('dense', $dense->ramp());
    }

    /**
     * @testdox SV-H2: a bound allowlist translates into the zero-redirect transport pin
     *
     * Load-bearing: drop the hostRestricted threading in Reel/Player::open and
     * the restricted assertion reddens (the policy must reach the transport,
     * not just the first-hop string check).
     */
    public function testABoundAllowlistPutsThePlayerInRestrictedTransportMode(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $gif = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-h2-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            4,
            4,
        );

        $read = static fn (Player $p): bool => (new \ReflectionProperty(Player::class, 'hostRestricted'))->getValue($p);

        try {
            $restricted = $this->withoutPathBinaries(
                static fn (): Player => Reel::open($gif)->withAllowedHosts(['cdn.example'])->toPlayer(),
            );
            try {
                $this->assertTrue($read($restricted), 'a bound allowlist must restrict the transport of every child');
            } finally {
                $restricted->stop();
            }

            $unrestricted = $this->withoutPathBinaries(static fn (): Player => Reel::open($gif)->toPlayer());
            try {
                $this->assertFalse($read($unrestricted), 'no allowlist keeps the historical transport defaults');
            } finally {
                $unrestricted->stop();
            }
        } finally {
            @unlink($gif);
        }
    }

    /**
     * @testdox SV-H2 (076): parser-divergent raw authorities are refused, not trusted
     *
     * Each shape is one that two URL parsers can read as two different hosts;
     * under a host allowlist sugar-video refuses the shape outright instead of
     * betting on one parser's verdict.
     *
     * @return array<string, array{0: string}>
     */
    public static function divergentAuthorityUrls(): array
    {
        return [
            'backslash in the authority' => ['https://evil\\.example/clip.mp4'],
            'semicolon parameter split' => ['https://trusted.example;evil.test/clip.mp4'],
            'userinfo host shift' => ['https://user@trusted.example/clip.mp4'],
            'percent-encoded delimiter' => ['https://trusted.example%40evil.test/clip.mp4'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('divergentAuthorityUrls')]
    public function testAuthoritySpecialsAreRefusedBeforeTheHostIsTrusted(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote host not in allowlist');
        Reel::openUrl($url, allowedHosts: ['trusted.example']);
    }

    /**
     * @testdox M10/M16: the allowlist is asserted at every build site, not just the setters
     */
    public function testTheAllowlistGatesEveryRemoteBuildPath(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Reel.php');
        // SV-M16 collapsed play()/toPlayer() into ONE build choke
        // (buildPlayer), so the shape is: openUrl() + withAllowedHosts()
        // setters + exactly one build-site gate. A second Player::open
        // call site — a re-duplicated build that could dodge the gate —
        // reddens the companion assert.
        $this->assertSame(
            3,
            substr_count($src, 'self::assertHostAllowed('),
            'M10: two setter gates + one build choke, no un-gated build path',
        );
        $this->assertSame(
            1,
            substr_count($src, '$player = Player::open('),
            'M16: the build sequence must stay single-sourced behind buildPlayer()',
        );
    }

    /**
     * @testdox m11: withFps refuses a non-positive override
     */
    public function testWithFpsRefusesNonPositiveValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fps must be greater than zero');
        Reel::open('/tmp/x.mp4')->withFps(0.0);
    }

    public function testWithFpsNullStillMeansAutoDetect(): void
    {
        $reel = Reel::open('/tmp/x.mp4')->withFps(30.0)->withFps(null);
        $this->assertNull($reel->fps());
    }

    /**
     * @testdox M25: a missing subtitle file is announced on the error log, never silent
     */
    public function testAMissingSubtitleFileIsAnnouncedNotSilent(): void
    {
        $missing = sys_get_temp_dir() . '/no-such-' . uniqid('', true) . '.vtt';
        $reader = new \ReflectionMethod(Reel::class, 'readSubtitleFile');
        $result = null;
        $log = $this->captureErrorLog(function () use ($reader, $missing, &$result): void {
            $result = $reader->invoke(null, $missing);
        });
        $this->assertNull($result);
        $this->assertStringContainsString('could not be read', $log);
        $this->assertStringContainsString('.vtt', $log);
    }

    /**
     * @testdox M25: an oversized subtitle file is refused with an announcement
     */
    public function testAnOversizedSubtitleFileIsRefusedWithALog(): void
    {
        $path = sys_get_temp_dir() . '/fat-' . uniqid('', true) . '.vtt';
        $h = fopen($path, 'wb');
        fseek($h, Reel::MAX_SUBTITLE_BYTES);
        fwrite($h, 'x');
        fclose($h);

        try {
            $reader = new \ReflectionMethod(Reel::class, 'readSubtitleFile');
            $result = null;
            $log = $this->captureErrorLog(function () use ($reader, $path, &$result): void {
                $result = $reader->invoke(null, $path);
            });
            $this->assertNull($result, 'the over-cap file must not be read into memory');
            $this->assertStringContainsString('exceeds the', $log);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @testdox M25: a normal subtitle file reads through without any announcement
     */
    public function testAGoodSubtitleFileReadsThroughQuietly(): void
    {
        $path = sys_get_temp_dir() . '/good-' . uniqid('', true) . '.vtt';
        $bytes = "WEBVTT\n\n00:00.000 --> 00:01.000\nhi\n";
        file_put_contents($path, $bytes);

        try {
            $reader = new \ReflectionMethod(Reel::class, 'readSubtitleFile');
            $result = null;
            $log = $this->captureErrorLog(function () use ($reader, $path, &$result): void {
                $result = $reader->invoke(null, $path);
            });
            $this->assertSame($bytes, $result);
            $this->assertSame('', $log, 'a good read must not cry wolf');
        } finally {
            @unlink($path);
        }
    }

    /**
     * @testdox M25: a track truncated at the cue cap announces the drop
     */
    public function testHittingTheCueCapAnnounces(): void
    {
        $blocks = [];
        for ($i = 1; $i <= 10005; $i++) {
            $minutes = intdiv($i, 60);
            $seconds = $i % 60;
            $blocks[] = sprintf(
                '%02d:%02d:%02d.000 --> %02d:%02d:%02d.500' . "\ncue %d\n\n",
                intdiv($minutes, 60), $minutes % 60, $seconds,
                intdiv($minutes, 60), $minutes % 60, $seconds,
                $i
            );
        }
        $huge = "WEBVTT\n\n" . implode('', $blocks);

        $doc = null;
        $log = $this->captureErrorLog(function () use ($huge, &$doc): void {
            $doc = WebVtt::parse($huge);
        });
        $this->assertNotNull($doc);
        $this->assertStringContainsString('truncated at 10000', $log);

        $small = "WEBVTT\n\n00:00.000 --> 00:01.000\na\n\n00:01.000 --> 00:02.000\nb\n\n00:02.000 --> 00:03.000\nc\n";
        $log2 = $this->captureErrorLog(static fn () => WebVtt::parse($small));
        $this->assertStringNotContainsString('truncated', $log2);
    }


    /**
     * @testdox M24: withAudio(false) sticks through every fluent builder
     */
    public function testTheAudioOptOutSurvivesEverySetter(): void
    {
        $read = static fn (Reel $r): bool => (new \ReflectionProperty(Reel::class, 'audioEnabled'))->getValue($r);

        $this->assertTrue($read(Reel::open('/tmp/clip.mp4')), 'audio is on by default');

        $off = Reel::open('/tmp/clip.mp4')->withAudio(false);
        $this->assertFalse($read($off));
        $this->assertFalse(
            $read($off->withLoop(true)->withFps(30.0)->withRamp('dense')),
            'a later setter must not resurrect the audio companion',
        );
        $this->assertTrue($read($off->withAudio(true)));

        $src = (string) file_get_contents(\dirname(__DIR__) . '/src/Reel.php');
        $this->assertSame(1, substr_count($src, '$player->withAudio(false)'),
            'M16: the opt-out rides the single build choke (buildPlayer), one application covers both exits');
    }

    public function testTheNonHttpRefusalNeverEchoesUrlCredentials(): void
    {
        try {
            Reel::openUrl('ftp://user:pass@example.invalid/clip.mp4');
            self::fail('the refusal was expected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('user:pass', $e->getMessage());
            self::assertStringContainsString('ftp://***@example.invalid/clip.mp4', $e->getMessage());
        }
    }

    /**
     * @testdox an explicit withSize() seeds the program window; an unsized Reel never does
     */
    public function testTheExplicitSizeSeedsTheProgramWindow(): void
    {
        $read = static fn (Reel $r): bool => (new \ReflectionProperty(Reel::class, 'sizeSet'))->getValue($r);

        $this->assertFalse($read(Reel::open('/tmp/clip.mp4')), 'a Reel that never sized must not seed');

        $sized = Reel::open('/tmp/clip.mp4')->withSize(120, 40);
        $this->assertTrue($read($sized), 'withSize() arms the seed');
        $this->assertTrue(
            $read($sized->withLoop(true)->withFps(30.0)->withRamp('dense')),
            'a later setter must not drop the seed',
        );

        $src = (string) file_get_contents(\dirname(__DIR__) . '/src/Reel.php');
        $this->assertStringContainsString('sizeSet: true', $src, 'withSize() passes the sentinel through the update helper');
        $this->assertStringContainsString("['cols' => \$this->cols, 'rows' => \$this->rows]", $src,
            'play() builds the seed from the explicit geometry');
        $this->assertSame(1, substr_count($src, 'windowSize: $windowSeed'),
            'the single Program construction in play() carries the seed');
    }
    /**
     * @testdox SV-M19: the frame budget threads through the single build choke
     *
     * Load-bearing: drop the frameBudgetMs arm in buildPlayer and the 120 ms
     * pin reddens; remove the set-sentinel and the 0.0 opt-out pin reddens
     * (null would mask it as "unset"). Unset derives Player's own default,
     * and a negative value never reaches a Player.
     */
    public function testTheFrameBudgetThreadsThroughTheBuildChoke(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $budget = static fn (Player $p): float => (new \ReflectionProperty(Player::class, 'frameBudgetMs'))->getValue($p);
        $fpsOf = static fn (Player $p): float => (new \ReflectionProperty(Player::class, 'fps'))->getValue($p);

        $gif = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-m19-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            4,
            4,
        );

        try {
            $explicit = $this->withoutPathBinaries(
                static fn (): Player => Reel::open($gif)->withFrameBudget(120.0)->toPlayer(),
            );
            try {
                $this->assertSame(120.0, $budget($explicit), 'an explicit budget reaches the built Player');
            } finally {
                $explicit->stop();
            }

            $optOut = $this->withoutPathBinaries(
                static fn (): Player => Reel::open($gif)->withFrameBudget(0.0)->toPlayer(),
            );
            try {
                $this->assertSame(0.0, $budget($optOut), '0.0 threads as the unbounded opt-out, not as unset');
            } finally {
                $optOut->stop();
            }

            $defaulted = $this->withoutPathBinaries(static fn (): Player => Reel::open($gif)->toPlayer());
            try {
                $this->assertSame(
                    max(Player::DEFAULT_FRAME_BUDGET_FLOOR_MS, 2000.0 / $fpsOf($defaulted)),
                    $budget($defaulted),
                    'an unset budget derives Player::open\'s max(50ms, 2 x frame interval)'
                );
            } finally {
                $defaulted->stop();
            }

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('frame budget must not be negative');
            Reel::open($gif)->withFrameBudget(-1.0);
        } finally {
            @unlink($gif);
        }
    }

    /**
     * @testdox SV-M21: the subtitle track rides storage → build → PAINTED caption (e2e)
     *
     * Load-bearing: comment out the subtitlePath leg inside buildPlayer() and
     * the caption assert reddens — the M25 arms read the private reader, this
     * one proves the PUBLIC API path end to end. The play()-leg pin guards the
     * M16 single-build-path law: play() must keep routing through buildPlayer,
     * never re-grow its own copy of the threading.
     */
    public function testTheSubtitleTrackRidesTheBuildToThePaintedCaption(): void
    {
        $dir = sys_get_temp_dir();
        $gif = Synthetic::generate(
            $dir . '/sugar-video-m21-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            4,
            4,
        );
        $vtt = $dir . '/sugar-video-m21-' . getmypid() . '.vtt';
        file_put_contents($vtt, "WEBVTT\n\n00:00.000 --> 00:02.000\nHello caption world\n");

        try {
            $player = Reel::open($gif)->withMode(Mode::Ascii)->withSubtitles($vtt)->toPlayer();

            try {
                $track = (new \ReflectionProperty($player, 'subtitles'))->getValue($player);
                $this->assertInstanceOf(WebVtt::class, $track, 'withSubtitles() must land a parsed track on the built Player');
                $this->assertStringContainsString('Hello caption world', (string) $track->cueAt(0.5));

                $view = $player->view();
                $this->assertStringContainsString(
                    'Hello caption world',
                    $view,
                    'the caption rides the reserved row out of view() — not just storage',
                );
            } finally {
                $player->stop();
            }

            // The play() build leg: both public exits share the one choke
            // (SV-M16), so threading pinned here covers the program path too.
            $src = (string) file_get_contents(dirname(__DIR__) . '/src/Reel.php');
            $this->assertSame(3, substr_count($src, 'buildPlayer('), 'definition + toPlayer + play, one build path');
            $this->assertStringContainsString('$player = $this->buildPlayer($path, $loop);', $src, 'play() must not re-grow private threading');

            // The mode-flow matrix leg (completes SV-C1's DOA smoke): an
            // explicit mode survives the same build choke untouched.
            $direct = Reel::open($gif)->withMode(Mode::TrueColor)->toPlayer();

            try {
                $this->assertSame(Mode::TrueColor, $direct->mode, 'withMode threads to the Player unchanged');
            } finally {
                $direct->stop();
            }
        } finally {
            @unlink($gif);
            @unlink($vtt);
        }
    }

    /**
     * @testdox The removal doors detach what the setters attached (SV-L50)
     */
    public function testTheRemoversDetachWhatTheWithersAttached(): void
    {
        $base = Reel::open('/fake.mp4');

        $subs = $base->withSubtitles('/tmp/sv-l50-a.vtt')->withoutSubtitles();
        self::assertNull($this->reelField($subs, 'subtitlePath'));

        $headers = $base->withHeaders(['X-Test' => 'yes'])->withoutHeaders();
        self::assertSame([], $this->reelField($headers, 'headers'));

        $ramp = $base->withRamp('dense')->withoutRamp();
        self::assertSame('standard', $this->reelField($ramp, 'ramp'));

        // A setter-less base is stable through a remover (no-op clear).
        self::assertNull($this->reelField($base->withoutSubtitles(), 'subtitlePath'));
        self::assertSame('standard', $this->reelField($base->withoutRamp(), 'ramp'));
    }

    /**
     * @testdox A detached subtitle track leaves the BUILT player uncaptioned (SV-L50)
     */
    public function testDetachedSubtitlesLeaveTheBuiltPlayerUncaptioned(): void
    {
        $dir = sys_get_temp_dir();
        $gif = Synthetic::generate(
            $dir . '/sugar-video-l50-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gif',
            16,
            8,
            4,
            4,
        );
        $vtt = $dir . '/sugar-video-l50-' . getmypid() . '.vtt';
        file_put_contents($vtt, "WEBVTT\n\n00:00.000 --> 00:02.000\nDetached caption\n");

        try {
            $player = Reel::open($gif)
                ->withMode(Mode::Ascii)
                ->withSubtitles($vtt)
                ->withoutSubtitles()
                ->toPlayer();

            $track = (new \ReflectionProperty(Player::class, 'subtitles'))->getValue($player);
            self::assertNull($track, 'withoutSubtitles() left the built player captioned');
        } finally {
            @unlink($gif);
            @unlink($vtt);
        }
    }

    /**
     * @testdox The private builder is mutate() — AGENTS naming, zero legacy ->with( (SV-L50/052)
     */
    public function testTheBuilderIsNamedMutate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Reel.php');

        self::assertSame(0, substr_count($src, '->with('), 'a legacy ->with( call survived the rename');
        self::assertSame(1, substr_count($src, 'private function mutate('));
        self::assertGreaterThan(6, substr_count($src, '$this->mutate('));
    }

    /**
     * @testdox The security allowlist carries no remover; re-validation keeps it moot (SV-L50)
     */
    public function testTheAllowlistHasNoRemoverDoor(): void
    {
        self::assertFalse(method_exists(Reel::class, 'withoutAllowedHosts'));

        $reel = Reel::openUrl('https://example.com/clip.mp4', [], ['example.com']);
        $this->expectException(\InvalidArgumentException::class);
        $reel->withAllowedHosts(['other.example']);
    }

    private function reelField(Reel $reel, string $field): mixed
    {
        return (new \ReflectionProperty(Reel::class, $field))->getValue($reel);
    }

    /**
     * @testdox A throwing Program still reaps the Player — stop() rides finally (SV-L70)
     */
    public function testAThrowingRunStillStopsThePlayer(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('synthetic GIF playback needs ext-gd');
        }

        $captured = null;
        Reel::setProgramRunForTesting(static function (Player $player) use (&$captured): void {
            $captured = $player;
            throw new \RuntimeException('boom mid-loop');
        });

        try {
            Reel::open('')->withMode(Mode::Ascii)->play();
            self::fail('the throwing runner must propagate through play()');
        } catch (\RuntimeException $boom) {
            self::assertSame('boom mid-loop', $boom->getMessage());
        } finally {
            Reel::setProgramRunForTesting(null);
        }

        self::assertNotNull($captured);
        self::assertTrue($captured->isStopped(), 'play() skipped the teardown after a throwing run()');
    }

    /**
     * @testdox A clean return still reaps (the n5 contract survives the finally move)
     */
    public function testACleanRunStopsThePlayerToo(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('synthetic GIF playback needs ext-gd');
        }

        $captured = null;
        Reel::setProgramRunForTesting(static function (Player $player) use (&$captured): void {
            $captured = $player;
        });

        try {
            Reel::open('')->withMode(Mode::Ascii)->play();
        } finally {
            Reel::setProgramRunForTesting(null);
        }

        self::assertNotNull($captured);
        self::assertTrue($captured->isStopped());
    }

    /**
     * @testdox the Program is constructed in exactly one place — the seam default (SV-L70 structural)
     */
    public function testTheProgramRunsThroughOneDoor(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Reel.php');

        self::assertSame(1, substr_count($src, '->run();'), 'a second, unguarded run() call survived');
        self::assertSame(1, substr_count($src, '$player->stop();'), 'the finally stop() must be the only reap');
        self::assertStringContainsString('} finally {', $src);
    }

    /**
     * SV-L79: the size stat is advisory — a filesystem that lies (or a swap
     * between stat and read) must still meet the ceiling, because the ceiling
     * binds the READ itself.  A userland wrapper reports a tiny stat while
     * serving megabytes; the reader must deliver cap+1 bytes at most and
     * refuse, announcing the too-large key.
     */
    public function testAGrownAfterStatSubtitleRefusesAtTheReadCeiling(): void
    {
        if (!in_array('svl79', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('svl79', LyingStatSubtitleWrapper::class));
        }
        LyingStatSubtitleWrapper::$reportedSize = 64;
        LyingStatSubtitleWrapper::$delivered = 0;
        // A runaway that dwarfs the cap: an unbounded slurp would deliver
        // every byte, while the bounded read stops within one block of cap+1.
        LyingStatSubtitleWrapper::$payload = "WEBVTT\n\n" . str_repeat('x', 2 * Reel::MAX_SUBTITLE_BYTES);

        $reader = new \ReflectionMethod(Reel::class, 'readSubtitleFile');

        $result = null;
        $log = $this->captureErrorLog(static function () use ($reader, &$result): void {
            $result = $reader->invoke(null, 'svl79://host/subs.vtt');
        });

        $this->assertNull($result, 'a stat-undersized runaway must refuse, not be capped silently');
        $this->assertLessThanOrEqual(
            Reel::MAX_SUBTITLE_BYTES + 1 + 8192,
            LyingStatSubtitleWrapper::$delivered,
            'the read itself was not bounded — cap plus one stream block at most'
        );
        $this->assertStringContainsString('exceeds the ' . Reel::MAX_SUBTITLE_BYTES . '-byte ceiling', $log);
        $this->assertStringContainsString('subs.vtt', $log);

        LyingStatSubtitleWrapper::$payload = '';
    }

    /** An honestly small track still arrives whole and unannounced. */
    public function testABoundedSubtitleStillReadsWhole(): void
    {
        if (!in_array('svl79', stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register('svl79', LyingStatSubtitleWrapper::class));
        }
        $text = "WEBVTT\n\n00:00.000 --> 00:01.000\nbounded ok\n";
        LyingStatSubtitleWrapper::$reportedSize = 8;
        LyingStatSubtitleWrapper::$delivered = 0;
        LyingStatSubtitleWrapper::$payload = $text;

        $reader = new \ReflectionMethod(Reel::class, 'readSubtitleFile');

        $result = null;
        $log = $this->captureErrorLog(static function () use ($reader, &$result): void {
            $result = $reader->invoke(null, 'svl79://host/small.vtt');
        });

        $this->assertSame($text, $result);
        $this->assertSame('', $log);
        $this->assertSame(strlen($text), LyingStatSubtitleWrapper::$delivered);

        LyingStatSubtitleWrapper::$payload = '';
    }

    /** SV-L79 structural: the ceiling rides the read length, not a guess. */
    public function testTheSubtitleCeilingBindsTheReadItself(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Reel.php');

        $this->assertSame(1, substr_count($src, 'self::MAX_SUBTITLE_BYTES + 1'), 'the cap+1 read length must appear exactly once');
        $this->assertSame(0, substr_count($src, 'ile_get_contents($path)'), 'the unbounded single-argument form must be gone');
    }
}

/**
 * Test-only stream wrapper (SV-L79): url_stat lies about the size while the
 * byte source serves arbitrarily more — the FUSE/proc race made deterministic.
 */
final class LyingStatSubtitleWrapper
{
    public mixed $context = null;

    public static int $reportedSize = 64;

    /** @var int */
    public static int $delivered = 0;

    public static string $payload = '';

    private int $pos = 0;

    /** @return array<string,int> */
    private function fakeStat(): array
    {
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1,
            'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => self::$reportedSize,
            'atime' => 0, 'mtime' => 0, 'ctime' => 0,
            'blksize' => 4096, 'blocks' => 0,
        ];
    }

    /** @return array<string,int> */
    public function url_stat(string $url, int $flags): array
    {
        return $this->fakeStat();
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$payload, $this->pos, $count);
        $this->pos += strlen($chunk);
        self::$delivered += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$payload);
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($whence !== SEEK_SET || $offset < 0 || $offset > strlen(self::$payload)) {
            return false;
        }
        $this->pos = $offset;

        return true;
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return $this->fakeStat();
    }
}