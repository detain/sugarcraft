<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\AudioPlayer;
use SugarCraft\Reel\Decode\FfmpegDecoder;
use SugarCraft\Reel\Source\HttpHeaders;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Support\FfmpegCommandBuilder;
use SugarCraft\Reel\Tests\Concerns\StubbedPathBinaries;

/**
 * Pins findings #45: extracting {@see FfmpegCommandBuilder} must not change one
 * byte of any argv sugar-reel hands to `proc_open()`.
 *
 * `argv-golden-pre-refactor.json` was captured from the master 48fd4f819 source
 * itself — before the builder existed — by
 * `/home/sites/sc-briefs/tools/w6-reel-capture-argv-golden.php`, which called the
 * real `FfmpegDecoder::buildCommand()` and the real `AudioPlayer::buildCommand()`
 * (and, for the mpv branch, that branch's verbatim transcription, since mpv is
 * absent on the capture host). Every case here therefore asserts the post-refactor
 * code against the pre-refactor output, not against a hand-written expectation.
 *
 * Binary paths are normalised to literals inside the fixture so the assertion does
 * not depend on what the CI host happens to have installed; the `ffplay` case adds
 * a host-real check on top when ffplay IS installed, so the Probe-resolved path
 * cannot silently migrate into the wrong argv slot.
 *
 * No test in this file spawns anything: argv assembly is pure.
 *
 * @covers \SugarCraft\Reel\Support\FfmpegCommandBuilder
 */
final class ArgvGoldenTest extends TestCase
{
    use StubbedPathBinaries;

    private const FIXTURE = __DIR__ . '/argv-golden-pre-refactor.json';

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function decoderCases(): array
    {
        return self::caseMap('ffmpegDecoder');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ffplayCases(): array
    {
        return self::caseMap('audioFfplay');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mpvCases(): array
    {
        return self::caseMap('audioMpv');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    private static function caseMap(string $section): array
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture[$section] as $case) {
            $cases[$case['name']] = [$case];
        }

        self::assertNotEmpty($cases, "golden fixture section {$section} is empty");

        return $cases;
    }

    #[DataProvider('decoderCases')]
    public function testFfmpegDecoderArgvIsUnchangedByTheExtraction(array $case): void
    {
        $argv = FfmpegDecoder::buildCommand(
            $case['binary'],
            $case['source'],
            $case['frameW'],
            $case['frameH'],
            (float) $case['fps'],
            (float) $case['startSec'],
            $case['graphics'],
            $case['padW'],
            $case['padH'],
            $case['headers'] === [] ? null : HttpHeaders::parse($case['headers']),
        );

        self::assertSame(
            $case['argv'],
            $argv,
            "FfmpegDecoder::buildCommand() argv drifted for case {$case['name']}",
        );
    }

    #[DataProvider('ffplayCases')]
    public function testFfplayAudioArgvIsUnchangedByTheExtraction(array $case): void
    {
        $argv = FfmpegCommandBuilder::ffplayAudioCommand(
            $case['binary'],
            $case['source'],
            $case['seekMs'],
            HttpHeaders::none(),
        );

        // Slot pins (round-1 review m2): the builder tests alone would stay green if
        // the CALL SITE transposed its two adjacent string arguments (binary/source
        // are both `string`), because the builder faithfully emits whatever it is
        // handed. Pinning slot 0 and the last slot here keeps the fixture honest about
        // WHICH value belongs WHERE.
        self::assertStringEndsWith('ffplay', $argv[0], 'slot 0 must be the ffplay binary');
        self::assertSame($case['source'], $argv[count($argv) - 1], 'the source must stay the last argument');

        self::assertSame(
            $case['argv'],
            $argv,
            "the ffplay audio argv drifted for case {$case['name']}",
        );
    }

    #[DataProvider('mpvCases')]
    public function testMpvAudioArgvIsUnchangedByTheExtraction(array $case): void
    {
        $argv = FfmpegCommandBuilder::mpvAudioCommand(
            $case['binary'],
            $case['source'],
            $case['seekMs'],
            HttpHeaders::none(),
        );

        self::assertStringEndsWith('mpv', $argv[0], 'slot 0 must be the mpv binary');
        self::assertSame($case['source'], $argv[count($argv) - 1], 'the source must stay the last argument');

        self::assertSame(
            $case['argv'],
            $argv,
            "the mpv audio argv drifted for case {$case['name']}",
        );
    }

    /**
     * Call-site checks that NEVER skip (round-1 review m2): PATH points at a
     * private directory of inert ffplay/mpv stubs, so `Probe` resolves
     * deterministically on any host — including one without either binary, where
     * `testAudioPlayerStillProducesTheGoldenArgv` would markTestSkipped and leave
     * `AudioPlayer::buildCommand()`'s argv tied to nothing. The stub PATH lands in
     * slot 0; the tail must equal the pre-refactor golden byte for byte.
     */
    #[DataProvider('ffplayCases')]
    public function testAudioPlayerFfplayCallSiteProducesTheGoldenArgv(array $case): void
    {
        $this->withStubbedBinaries(['ffplay', 'mpv'], function (string $dir) use ($case): void {
            $argv = self::audioArgvOf($case);

            self::assertIsArray($argv, 'the stubbed ffplay must make the command buildable');
            self::assertSame($dir . '/ffplay', $argv[0], 'slot 0 is the probed binary path');
            self::assertSame(
                array_slice($case['argv'], 1),
                array_slice($argv, 1),
                "AudioPlayer::buildCommand() ffplay tail drifted for case {$case['name']}",
            );
        });
    }

    #[DataProvider('mpvCases')]
    public function testAudioPlayerMpvCallSiteProducesTheGoldenArgv(array $case): void
    {
        // Only mpv stubbed: Probe::ffplay() finds nothing, so the mpv fallback
        // branch of buildCommand() — the one no host-independent test reached — runs.
        $this->withStubbedBinaries(['mpv'], function (string $dir) use ($case): void {
            $argv = self::audioArgvOf($case);

            self::assertIsArray($argv, 'the stubbed mpv must make the command buildable');
            self::assertSame($dir . '/mpv', $argv[0], 'slot 0 is the probed mpv path');
            self::assertSame(
                array_slice($case['argv'], 1),
                array_slice($argv, 1),
                "AudioPlayer::buildCommand() mpv tail drifted for case {$case['name']}",
            );
        });
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return list<string>|null
     */
    private static function audioArgvOf(array $case): ?array
    {
        $invoke = new \ReflectionMethod(AudioPlayer::class, 'buildCommand');
        $invoke->setAccessible(true);

        /** @var list<string>|null $argv */
        $argv = $invoke->invoke(new AudioPlayer($case['source'], $case['startMs']));

        return $argv;
    }

    /**
     * The real spawn path, not just the builder: with ffplay installed, what
     * `AudioPlayer::buildCommand()` produces must equal the golden with only the
     * host's own binary path in slot 0. This is what catches an extraction that
     * reorders argv only when it goes through the class that actually spawns.
     */
    public function testAudioPlayerStillProducesTheGoldenArgv(): void
    {
        $ffplay = Probe::ffplay();
        if ($ffplay === null) {
            $this->markTestSkipped('ffplay not available on this host');
        }

        $invoke = new \ReflectionMethod(AudioPlayer::class, 'buildCommand');
        $invoke->setAccessible(true);

        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['audioFfplay'] as $case) {
            $argv = $invoke->invoke(new AudioPlayer($case['source'], $case['startMs']));
            self::assertIsArray($argv);
            self::assertSame($ffplay, $argv[0], 'slot 0 is the probed binary path');
            self::assertSame(
                array_slice($case['argv'], 1),
                array_slice($argv, 1),
                "AudioPlayer argv tail drifted for case {$case['name']}",
            );
        }
    }

    /**
     * The header passthrough added under findings #52 must be strictly ADDITIVE:
     * with no headers — the only case the pre-refactor code could ever produce —
     * both audio builders emit exactly the pre-refactor argv. Pinned here so a
     * later edit cannot make the empty set contribute a stray flag.
     */
    public function testEmptyHeaderSetContributesNoArguments(): void
    {
        self::assertSame([], FfmpegCommandBuilder::headerInputFlags(HttpHeaders::none()));
        self::assertSame([], FfmpegCommandBuilder::mpvHeaderFieldFlags(HttpHeaders::none()));
        self::assertSame([], FfmpegCommandBuilder::inputSeekFlag(0.0));

        $withoutHeaders = FfmpegCommandBuilder::ffplayAudioCommand('/usr/bin/ffplay', '/tmp/c.mp4', 0, HttpHeaders::none());
        self::assertSame(['/usr/bin/ffplay', '-nodisp', '-autoexit', '/tmp/c.mp4'], $withoutHeaders);
    }
}
