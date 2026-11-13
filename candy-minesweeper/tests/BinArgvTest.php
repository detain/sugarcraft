<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Minesweeper\Tests\Support\PtyLaunch;

/**
 * E3 (docs-fix-1023) MINOR-13: `--expert 10 10` used to silently start the
 * PRESET and drop the positionals. The binary must refuse the combination
 * loudly. Each refusal case is a bounded, pipe-stdin child — no ctty
 * involvement — and the refusals exit BEFORE any Program starts, so the
 * runs are cheap and deterministic. Since the no-TTY boot gate
 * (candy-minesweeper-002) refuses piped PLAY launches outright (piped stdin
 * used to spin the input pump until killed), the two FIX-2 legs that must
 * actually BOOT (stats-file echo, resume notice) run under a pty via
 * {@see PtyLaunch}; the pipe legs below pin the refusal itself. The
 * diagnostics (--version/--help) answer on a pipe by design.
 */
final class BinArgvTest extends TestCase
{
    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    /** @param array<string,string> $extraEnv */
    private static function runBinary(array $args, array $extraEnv = []): array
    {
        $bin = realpath(__DIR__ . '/../bin/candy-minesweeper');
        self::assertIsString($bin, 'sanity: binary exists');

        $proc = proc_open(
            [PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            // HOME without stats env: the refusal path never reads either, but
            // pinning the env keeps the child hermetic against the dev HOME.
            ['HOME' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH')] + $extraEnv,
        );
        self::assertIsResource($proc);

        // The child must not wait on us: close stdin immediately.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        if (proc_get_status($proc)['running']) {
            proc_terminate($proc);
            self::fail('binary did not exit within 10s for args: ' . implode(' ', $args));
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    public function testPresetPlusPositionalIsRefused(): void
    {
        [$code, , $err] = self::runBinary(['--expert', '10', '10']);

        $this->assertSame(1, $code, 'the silent-drop combination must exit non-zero');
        $this->assertStringContainsString('mutually exclusive', $err);
    }

    public function testMediumPlusPositionalIsRefusedToo(): void
    {
        [$code, , $err] = self::runBinary(['--medium', '5']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('mutually exclusive', $err);
    }

    public function testTwoPresetsAreRefusedNotLastWins(): void
    {
        [$code, , $err] = self::runBinary(['--easy', '--expert']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('more than one preset flag', $err);
    }

    public function testUnknownOptionStillRefused(): void
    {
        [$code, , $err] = self::runBinary(['--frobnicate']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('unknown option', $err);
    }

    public function testUsageNamesBothInvocationShapes(): void
    {
        [$code, , $err] = self::runBinary(['--nope']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('candy-minesweeper [width] [height] [mines]', $err);
        $this->assertStringContainsString('--easy | --standard | --medium | --expert', $err);
    }

    public function testCustomBoardWithBadDimensionsStillRefused(): void
    {
        // Guard against the new early-refusal shadowing the existing
        // CustomDifficulty validation: 3×3 boards cannot carry the 3×3
        // safe-area contract, and must still exit 1 loudly.
        [$code, , $err] = self::runBinary(['3', '3', '1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('candy-minesweeper:', $err);
    }

    public function testNonIntegerWidthIsRefusedNotSilentlyCast(): void
    {
        // M08: the (int) cast booted '10x10' as width 10; the parse now
        // requires plain digit strings.
        [$code, , $err] = self::runBinary(['10x10', '10', '12']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("width must be a plain integer", $err);
    }

    public function testFractionalAndWordHeightsAreRefused(): void
    {
        foreach (['9.5', '10abc', ' 10'] as $junk) {
            [$code, , $err] = self::runBinary(['10', $junk, '12']);
            $this->assertSame(1, $code, 'junk height ' . $junk . ' must not boot');
            $this->assertStringContainsString('height must be a plain integer', $err);
        }
    }

    public function testFourthPositionalIsRefusedNotIgnored(): void
    {
        [$code, , $err] = self::runBinary(['10', '10', '12', '99']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString("unexpected extra argument '99'", $err);
    }

    public function testUsageTeachesColumnRowOrderAndSlotPath(): void
    {
        // M19/m03: the error surface itself must say which axis is which,
        // name the save slot, and teach the stats env.
        [$code, , $err] = self::runBinary(['nope-not-a-number-at-all', '10']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('columns (width) then rows (height)', $err);
        $this->assertStringContainsString('savegame.json', $err);
        $this->assertStringContainsString('CANDY_MINESWEEPER_STATS', $err);
    }

    public function testUnknownOptionEchoIsControlByteSafe(): void
    {
        // m21: an argv fragment carrying ESC reformatting the very error that
        // reports it is an injection of the terminal, not a message.
        [$code, , $err] = self::runBinary(["--\x1b[31mred\x07"]);
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString("\x1b", $err, 'raw ESC must never reach stderr');
        $this->assertStringNotContainsString("\x07", $err);
        $this->assertStringContainsString('unknown option --?', $err, 'control bytes become ? marks');

        // Positive control that the child CAN emit raw bytes when it means to
        // (locale-independent). msw-35 (finding -090): non-emptiness was the
        // whole claim — a half-version or a random banner passed. Byte-pin
        // the child against the single source of truth, Version::line().
        [$vcode, $vout] = self::runBinary(['--version']);
        $this->assertSame(0, $vcode);
        $this->assertSame(\SugarCraft\Minesweeper\Support\Version::line(), $vout, 'the --version child speaks Version::line() verbatim');
    }

    public function testLegacyEnvRemedyNamesTheVarsOwnReplacement(): void
    {
        // m22: every pre-rename var used to be told to set _STATS.
        [$code, , $err] = self::runBinary(['--version'], ['CANDY_MINES_DEBUG' => '1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('CANDY_MINES_DEBUG is deprecated', $err);
        $this->assertStringContainsString('set CANDY_MINESWEEPER_DEBUG instead', $err);
        $this->assertStringNotContainsString('CANDY_MINESWEEPER_STATS instead', $err);
    }

    public function testUnreadableStatsFileEchoCarriesNoControlBytes(): void
    {
        // m1 (review R1): the load-failure notice interpolates the stats file's
        // own refused version value. Control bytes planted in that file must be
        // scrubbed on the way to stderr, exactly like the argv echoes above.
        // Since candy-minesweeper-002 the stats seed runs at board build —
        // BEFORE the no-TTY gate — so a pipe leg sees the warning and then the
        // refusal: the warning must not be a fatal, and rc 1 is the gate's
        // honest answer, not a stats failure.
        $dir = sys_get_temp_dir() . '/mines-stats-' . uniqid((string) getmypid(), true);
        mkdir($dir, 0700);
        $stats = $dir . '/stats.json';
        // \u001b keeps the JSON itself valid; the decoded value carries raw ESC,
        // and a string version fails the strict version===1 guard.
        file_put_contents($stats, '{"version":"\u001b[31m2","data":{}}');

        [$code, $out, $err] = self::runBinary(['--easy'], ['CANDY_MINESWEEPER_STATS' => $stats]);
        unlink($stats);
        rmdir($dir);

        $this->assertSame(1, $code, 'a refused stats file stays a warning; exit 1 is the no-TTY gate');
        $this->assertStringContainsString('ignoring unreadable stats file', $err);
        $this->assertStringContainsString('Unsupported persistence version', $err);
        $this->assertStringNotContainsString('Fatal error', $err);
        $this->assertStringNotContainsString("\x1b", $err, 'raw ESC from the stats file must never reach stderr');
        $this->assertStringContainsString('?[31m2', $err, 'the planted version must arrive with control bytes as ?');
        $this->assertStringContainsString('stdin is not a terminal', $err);
        $this->assertSame('', $out, 'a piped play boot paints nothing on stdout');
    }

    public function testBootWithSavegameInSlotAnnouncesResumeInsteadOfFataling(): void
    {
        // FIX-2 lane incident: the boot resume notice (f5ba952aa) called
        // Lang::t while the class was neither imported nor FQN-qualified in
        // the binary — every player holding a savegame got an immediate
        // 'Class "Lang" not found' fatal (exit 255) before frame one.
        // Frame-one assertions need a terminal since candy-minesweeper-002,
        // so this leg boots on a real pty.
        $dir = sys_get_temp_dir() . '/mines-slot-' . uniqid((string) getmypid(), true);
        mkdir($dir . '/candy-minesweeper', 0700, true);
        $slot = $dir . '/candy-minesweeper/savegame.json';
        file_put_contents($slot, '{}');

        [$code, $output] = PtyLaunch::run(['--easy'], 30, 100, 'q', ['XDG_CONFIG_HOME' => $dir]);
        unlink($slot);
        rmdir($dir . '/candy-minesweeper');
        rmdir($dir);

        $this->assertSame(0, $code, 'savegame in the slot must not fatal the boot — pty: ' . PtyLaunch::printable($output));
        $this->assertStringNotContainsString('Class "Lang" not found', $output);
        $en = require __DIR__ . '/../lang/en.php';
        $this->assertStringContainsString($en['ui.resume'], $output, 'frame one must announce the resume option');
    }

    public function testGiantDigitArgumentsRefuseWithoutFatalError(): void
    {
        // Beyond every legal board, digit argv must land on the translated
        // CustomDifficulty::fromInput refusal — never a float-overflow fatal.
        foreach ([
            ['99999999999999999999', '99999999999999999999'],
            ['99999999999999999999'],
            ['20', '20', '99999999999999999999'],
        ] as $argv) {
            [$code, , $err] = self::runBinary($argv);
            $this->assertSame(1, $code, 'giant argv must be refused with exit 1: ' . $err);
            $this->assertStringNotContainsString('TypeError', $err);
            $this->assertStringNotContainsString('Fatal error', $err);
            $this->assertStringContainsString('candy-minesweeper:', $err);
        }
    }

    public function testLegacyEnvNameWithControlBytesIsEchoedScrubbed(): void
    {
        // The deprecation echo interpolates the raw env NAME; an
        // ESC-bearing name must reach stderr scrubbed, never as bytes.
        [$code, , $err] = self::runBinary(['--version'], ["CANDY_MINES_\x1bH" => '1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('is deprecated', $err, 'vacuity guard: the planted name must reach the loop');
        $this->assertStringNotContainsString("\x1b", $err, 'raw ESC from an env name must never reach stderr');
        $this->assertStringContainsString('?', $err, 'the scrubbed byte must arrive as ?');
    }

    public function testCliVisibleTruncationIsClusterSafe(): void
    {
        // The 40-char clip must not split a UTF-8 codepoint in two.
        [$code, , $err] = self::runBinary(['--' . str_repeat('é', 45)]);
        $this->assertSame(1, $code);
        $this->assertTrue(mb_check_encoding($err), 'stderr must stay valid UTF-8 through the clip');
        $this->assertStringNotContainsString("\u{FFFD}", $err, 'no replacement glyph may appear');
        $this->assertStringContainsString('candy-minesweeper:', $err);
    }

    public function testPlayBootOnAPipeIsRefusedBeforePainting(): void
    {
        // candy-minesweeper-002 (critical): `timeout 5 php bin --easy
        // </dev/null` used to run until killed (rc 124) after writing 1001
        // bytes of alt-screen/mouse setup into the pipe. A play boot with no
        // terminal on stdin is now refused with exit 1, plain text on
        // stderr, and NOTHING painted anywhere.
        [$code, $out, $err] = self::runBinary(['--easy']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out, 'the refusal must not touch stdout — no escape bytes');
        $this->assertStringContainsString('stdin is not a terminal', $err);
    }

    public function testDiagnosticFlagsStillAnswerOnAPipe(): void
    {
        // The gate protects PLAY; asking the binary for help or its version
        // over a pipe (curl | php style, CI probes) must keep working.
        [$code, $out, $err] = self::runBinary(['--help']);
        $this->assertSame(0, $code, 'stderr was: ' . $err);
        $this->assertStringContainsString('usage: candy-minesweeper', $out);

        [$code, $out, $err] = self::runBinary(['--version']);
        $this->assertSame(0, $code, 'stderr was: ' . $err);
        // msw-35 (finding -090): not-empty was a dud gate — any stray byte
        // passed. The pipe leg must pin the SAME single source of truth.
        $this->assertSame(\SugarCraft\Minesweeper\Support\Version::line(), $out, 'the piped child speaks Version::line() verbatim');
    }

    // ---- msw-31: env-derived echoes obey the cliVisible law (finding -069) ----

    public function testTheHelpScreenScrubbsControlBytesFromTheEnvSlotPath(): void
    {
        // The save-slot line is the help screen's only environment-derived
        // token; a self-set XDG_CONFIG_HOME carrying ESC must not be able to
        // reformat the terminal while claiming to tell the player where
        // their saves live. Kept under the 40-byte cap so the scrub — not
        // the clip — is what lands on screen.
        [$code, $out, $err] = self::runBinary(['--help'], ['XDG_CONFIG_HOME' => "/t/\x1bx"]);
        $this->assertSame(0, $code, 'stderr was: ' . $err);
        $this->assertStringContainsString('save slot: /t/?x/candy-minesweeper/savegame.json', $out);
        $this->assertStringNotContainsString("\x1b", $out, 'raw ESC must never reach the help screen');
    }

    public function testHelpMirrorsEveryKeyTheGameAnswers(): void
    {
        // msw-36 (findings -109/-111): --help is the ONE text a player reads
        // BEFORE launch, with no in-app legend behind it — so it must mirror
        // the full binding table. The letters are derived from the same
        // update()-source regex msw-35 installed for the in-game footer:
        // a future binding that skips the legend reddens this leg too.
        $game = (string) file_get_contents(\dirname(__DIR__) . '/src/Game.php');
        preg_match('/public function update\(.*?\n    \}\n/s', $game, $slice);
        self::assertNotSame([], $slice, 'sanity: the update() slice is findable');
        preg_match_all("/char === '([A-Za-z])'/", $slice[0], $letters);
        self::assertGreaterThan(5, count($letters[1]), 'sanity: the roster derives the bound keys');

        [$code, $out, $err] = self::runBinary(['--help']);
        $this->assertSame(0, $code, 'stderr was: ' . $err);
        foreach (array_unique($letters[1]) as $letter) {
            $this->assertStringContainsString($letter, $out, "the legend must print bound key '{$letter}'");
        }
        // The companions the old text omitted (MA-007, DOC-END-007): Enter,
        // q's aliases, and every mouse gesture.
        foreach (['space/enter', 'q/Esc', 'Ctrl+C', 'left click', 'right flags', 'middle chords', 'double-click'] as $token) {
            $this->assertStringContainsString($token, $out, "--help must teach '{$token}'");
        }
        // -109's orphan is now a labelled notes line with -30's honesty.
        $this->assertStringContainsString('notes:  there is no pause', $out);
        $this->assertStringContainsString('wall-clock', $out);
        // msw-40 (finding -113): the reduced-motion position must survive
        // next to the pause truth it belongs to.
        $this->assertStringContainsString('only motion is the 1 Hz', $out);
        $this->assertStringContainsString('no way to freeze it', $out);
    }

    // ---- msw-32: POSIX end-of-options and the -v alias (finding -070) ----

    public function testEndOfOptionsMarkerIsAcceptedInsteadOfRefused(): void
    {
        // The old answer to a bare '--' was 'unknown option --'. It now ends
        // option parsing; with nothing behind the door the boot marches on
        // to the no-TTY gate — the play grammar for pipes is unchanged.
        [$code, , $err] = self::runBinary(['--']);
        $this->assertSame(1, $code, 'stderr was: ' . $err);
        $this->assertStringNotContainsString('unknown option', $err);
        $this->assertStringContainsString('stdin is not a terminal', $err, 'the parse must pass the door and reach the boot gate');
    }

    public function testPositionalsAfterEndOfOptionsStayPositional(): void
    {
        [$code, , $err] = self::runBinary(['--', '16', '10']);
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('unknown option', $err);
        $this->assertStringNotContainsString('must be a plain integer', $err);
        $this->assertStringContainsString('stdin is not a terminal', $err, '16 10 must parse as the board shape behind the door');
    }

    public function testTokensBehindEndOfOptionsAreNeverFlags(): void
    {
        // The POSIX symmetry leg: --version behind the door is a WORD, and
        // words in the width slot refuse as non-numeric positionals — the
        // diagnostic must NOT fire.
        [$code, $out, $err] = self::runBinary(['--', '--version']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out, 'the word must not trigger the version diagnostic');
        $this->assertStringContainsString("must be a plain integer (got '--version')", $err);
    }

    public function testShortVersionAliasAnswersWithTheLongFlagVerbatim(): void
    {
        [$vcode, $vout] = self::runBinary(['-v']);
        $this->assertSame(0, $vcode, '-v beside -h: the version must answer on a pipe');
        [$lcode, $lout] = self::runBinary(['--version']);
        $this->assertSame(0, $lcode);
        $this->assertSame($lout, $vout, '-v is the alias of --version, byte for byte');
    }

    // ---- msw-07: the ACCEPTED boot grammar (finding -009) -----------------
    //
    // Every older fixture that refuses is symmetric, so a transposition
    // between fromInput($h, $w) and start($w, $h) kept the whole suite
    // green. These legs make the CLI's documented "16 10 = 16 wide by 10
    // tall" contract executable: the refusal probes name the axis that
    // actually crossed the 2..50 ladder, and one asymmetric accepted boot
    // pins the painted frame's geometry.

    public function testAsymmetricRefusalProbesNameTheAxisThatCrossed(): void
    {
        // `60 5` is width 60 — the COLUMNS side is over the ladder; the
        // refusal must say so, not blame rows.
        [$code, , $err] = self::runBinary(['60', '5']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('columns must be at most 50', $err);
        $this->assertStringNotContainsString('rows must be at most 50', $err, 'a $w/$h swap would blame the wrong axis');

        // Mirror leg: `5 60` is height 60 — rows crossed.
        [$code, , $err] = self::runBinary(['5', '60']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('rows must be at most 50', $err);
        $this->assertStringNotContainsString('columns must be at most 50', $err);
    }

    public function testAsymmetricAcceptedBootPaintsWidthThenHeight(): void
    {
        // 16 wide × 10 tall: the border spans 16 + 2 padding cells and the
        // interior carries 10 rows. A transposed boot would paint 16 rows
        // across a 12-wide border — both counts redden together.
        [$code, $out] = PtyLaunch::run(['16', '10'], rows: 40, cols: 100, quitKey: 'q');
        $this->assertSame(0, $code, PtyLaunch::printable($out));
        $printable = PtyLaunch::printable($out);
        $horizontalMax = 0;
        $verticalRows = 0;
        foreach (preg_split('/[\r\n]+/', $printable) ?: [] as $line) {
            if (($run = substr_count($line, '─')) > $horizontalMax) {
                $horizontalMax = $run;
            }
            if (str_contains($line, '│')) {
                $verticalRows++;
            }
        }
        $this->assertSame(18, $horizontalMax, 'border spans width 16 + 2 padding');
        $this->assertSame(10, $verticalRows, 'interior carries height 10 rows');
        // Default-mines law runs on the accepted path: intdiv(16×10, 8) = 20.
        $this->assertMatchesRegularExpression('/\b20\b/', $printable, 'derived mine count 20 rides the status line');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function presetProvider(): array
    {
        return [
            'easy'     => ['--easy'],
            'standard' => ['--standard'],
            'medium'   => ['--medium'],
            'expert'   => ['--expert'],
        ];
    }

    /**
     * @dataProvider presetProvider
     */
    public function testEveryPresetBootsUnderAPtyAndQuitsCleanly(string $flag): void
    {
        [$code, $out] = PtyLaunch::run([$flag], rows: 40, cols: 100, quitKey: 'q');
        $this->assertSame(0, $code, $flag . ' must boot and answer q cleanly: ' . PtyLaunch::printable($out));
        $this->assertStringContainsString("\x1b[?1049h", $out, $flag . ' takes the alt screen');
    }
}
