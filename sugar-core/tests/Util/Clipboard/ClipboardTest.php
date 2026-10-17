<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Clipboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Clipboard\Clipboard;

/**
 * Which host clipboard tool a copy reaches, and the bounded real spawn.
 *
 * The pure {@see Clipboard::plan()} arms pin the whole environment matrix —
 * Darwin included — on any host, because the OS family, the env snapshot and
 * the locator are all arguments there. The {@see Clipboard::candidates()} and
 * real-spawn arms additionally stub every binary into a temp directory on a
 * private PATH (restored verbatim in tearDown), so no test can touch the
 * developer's real clipboard or leak a PATH into a sibling test.
 */
final class ClipboardTest extends TestCase
{
    private const VARS = ['PATH', 'TMUX', 'TERM_PROGRAM', 'DISPLAY', 'WAYLAND_DISPLAY'];

    /** @var array<string, string|false> */
    private array $saved = [];

    private string $bin = '';

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            $this->saved[$var] = getenv($var);
            putenv($var);
        }

        $this->bin = \sys_get_temp_dir() . '/cc_clipboard_bin_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->bin, 0o700);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $var => $value) {
            \putenv($value === false ? $var : $var . '=' . $value);
        }

        foreach (\glob($this->bin . '/*') ?: [] as $file) {
            \unlink($file);
        }
        @\rmdir($this->bin);

        Clipboard::useRunnerForTesting(null);
    }

    public function testInsideTmuxOnlyTmuxIsOfferedWithTheOuterTerminalFormFirst(): void
    {
        $plan = Clipboard::plan('Linux', ['TMUX' => '/tmp/tmux-1000/default,1,0', 'DISPLAY' => ':0'], $this->knows('tmux', 'xclip'));

        self::assertSame(
            [
                ['/bin/tmux', 'load-buffer', '-w', '-'],
                ['/bin/tmux', 'load-buffer', '-'],
            ],
            $plan,
            'tmux ignores an app\'s OSC 52 under its default set-clipboard, so tmux is the route that works — and DISPLAY must not add X tools inside it',
        );
    }

    public function testTermProgramTmuxIsRecognisedWhenTmuxItselfIsUnset(): void
    {
        $viaProgram = Clipboard::plan('Linux', ['TERM_PROGRAM' => 'tmux'], $this->knows('tmux'));
        $viaSocket = Clipboard::plan('Linux', ['TMUX' => '/tmp/tmux.sock'], $this->knows('tmux'));

        self::assertSame($viaSocket, $viaProgram, 'both spellings of "inside tmux" take the same route');

        self::assertSame(
            [],
            Clipboard::plan('Linux', ['TERM_PROGRAM' => 'Apple_Terminal'], $this->knows('tmux')),
            'an unrelated TERM_PROGRAM value never opens the tmux branch',
        );
    }

    public function testDarwinOffersPbcopy(): void
    {
        self::assertSame(
            [['/bin/pbcopy']],
            Clipboard::plan('Darwin', [], $this->knows('pbcopy')),
        );
    }

    public function testOutsideTmuxTheDisplayServersToolsAreOrderedWaylandThenX11(): void
    {
        $plan = Clipboard::plan(
            'Linux',
            ['WAYLAND_DISPLAY' => 'wayland-0', 'DISPLAY' => ':0'],
            $this->knows('wl-copy', 'xclip', 'xsel'),
        );

        self::assertSame(
            [
                ['/bin/wl-copy'],
                ['/bin/xclip', '-selection', 'clipboard'],
                ['/bin/xsel', '--clipboard', '--input'],
            ],
            $plan,
        );
    }

    public function testAToolThatIsNotLocatedIsNeverOffered(): void
    {
        self::assertSame(
            [],
            Clipboard::plan('Linux', ['DISPLAY' => ':0'], static fn (string $binary): ?string => null),
            'a spawn is never aimed at a missing binary — PHP warns on that, and suites fail on warnings',
        );
    }

    public function testOsc52BytesAreIdenticalToTheAnsiChannelTheyDegradeTo(): void
    {
        // The degradation law: with no host tool the caller still writes the
        // OSC 52 sequence, so the two spellings must never drift apart.
        self::assertSame(Ansi::setClipboard('hello'), Clipboard::osc52('hello'));
        self::assertSame(Ansi::setClipboard('hello', 'p'), Clipboard::osc52('hello', 'p'));
        self::assertStringContainsString(']52;c;', Clipboard::osc52('x'));
    }

    public function testEmptyTextIsAnEmptyAttemptListWithoutTouchingTheRunner(): void
    {
        $calls = 0;
        Clipboard::useRunnerForTesting(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        self::assertSame([], Clipboard::copy(''));
        self::assertSame(0, $calls);
    }

    public function testCopyStopsAtTheFirstToolThatAccepts(): void
    {
        \putenv('PATH=' . $this->bin);
        $this->stubExitZero('tmux');
        \putenv('TMUX=/tmp/tmux.sock');

        $seen = [];
        Clipboard::useRunnerForTesting(static function (array $argv, string $text) use (&$seen): bool {
            $seen[] = [\implode(' ', \array_slice($argv, 1)), $text];

            return true;
        });

        $results = Clipboard::copy('payload');

        self::assertSame([['load-buffer -w -', 'payload']], $seen, 'first match wins; the fallback rides only if -w is refused');
        self::assertCount(1, $results);
        self::assertSame('tmux', $results[0]['mechanism']);
        self::assertTrue($results[0]['succeeded']);
        self::assertTrue(Clipboard::succeeded($results));
    }

    public function testCopyRecordsEveryAttemptWhenAllToolsRefuse(): void
    {
        \putenv('PATH=' . $this->bin);
        $this->stubExitZero('xclip');
        $this->stubExitZero('xsel');
        \putenv('DISPLAY=:0');

        Clipboard::useRunnerForTesting(static fn (): bool => false);

        $results = Clipboard::copy('payload');

        self::assertCount(2, $results);
        self::assertSame(['xclip', 'xsel'], array_column($results, 'mechanism'));
        self::assertSame([false, false], array_column($results, 'succeeded'));
        self::assertFalse(Clipboard::succeeded($results));
        self::assertFalse(Clipboard::succeeded([]), 'no tool on the host honestly reads as no copy');
    }

    public function testRealSpawnFeedsTheTextToStdinAndReportsSuccess(): void
    {
        $this->requirePosixHost();
        $capture = $this->bin . '/captured.txt';

        \putenv('PATH=' . $this->bin);
        \putenv('DISPLAY=:0');
        $this->writeStub('xclip', "#!/bin/sh\n/bin/cat > " . \escapeshellarg($capture) . "\nexit 0\n");

        $results = Clipboard::copy('hello clipboard', 2.0);

        self::assertSame(['xclip'], array_column($results, 'mechanism'));
        self::assertTrue($results[0]['succeeded']);
        self::assertSame('hello clipboard', \file_get_contents($capture), 'the text must arrive whole on the tool\'s stdin');
    }

    public function testRealSpawnReportsANonZeroExitAsAFailedAttemptWithoutSignalling(): void
    {
        $this->requirePosixHost();

        \putenv('PATH=' . $this->bin);
        \putenv('DISPLAY=:0');
        $this->writeStub('xsel', "#!/bin/sh\nexit 3\n");

        $started = \hrtime(true);
        $results = Clipboard::copy('payload', 2.0);

        self::assertSame([false], array_column($results, 'succeeded'));
        self::assertLessThan(1.0, (\hrtime(true) - $started) / 1_000_000_000, 'an already-exited tool must not pay the escalation rungs');
    }

    public function testAWedgedToolIsKilledInsideTheDeadlineInsteadOfWedgingTheCaller(): void
    {
        $this->requirePosixHost();
        $pidFile = $this->bin . '/wedged.pid';

        \putenv('PATH=' . $this->bin);
        \putenv('WAYLAND_DISPLAY=wayland-0');
        // trap '' TERM IGNORES (survives exec); the loop is the wedge. The
        // child records its own pid so the test can confirm the KILL landed.
        $this->writeStub(
            'wl-copy',
            "#!/bin/sh\necho $$ > " . \escapeshellarg($pidFile) . "\ntrap '' TERM\nwhile :; do /bin/sleep 0.2; done\n",
        );

        $started = \hrtime(true);
        $results = Clipboard::copy('payload', 0.2);
        $elapsed = (\hrtime(true) - $started) / 1_000_000_000;

        self::assertSame([false], array_column($results, 'succeeded'));
        self::assertLessThan(
            2.0,
            $elapsed,
            'deadline (0.2) + TERM rung (0.25) + KILL confirmation rung (0.25) plus slack — the TERM→KILL ladder is what keeps this bounded',
        );

        $wedgedPid = (int) \trim((string) \file_get_contents($pidFile));
        self::assertGreaterThan(0, $wedgedPid);
        self::assertFalse(
            @\posix_kill($wedgedPid, 0),
            'a deadline-expired child must be dead, not orphaned with our descriptors',
        );
    }

    public function testCandidatesReadTheRealEnvironmentThroughTheRealLocator(): void
    {
        \putenv('PATH=' . $this->bin);
        $this->stubExitZero('xclip');
        \putenv('DISPLAY=:0');

        self::assertSame(
            [[$this->bin . '/xclip', '-selection', 'clipboard']],
            Clipboard::candidates(),
            'the composed candidates() must agree with the pure plan() it delegates to',
        );
    }

    /** @param list<string> $names */
    private function knows(string ...$names): callable
    {
        $allow = array_fill_keys($names, true);

        return static fn (string $binary): ?string => isset($allow[$binary]) ? '/bin/' . $binary : null;
    }

    private function stubExitZero(string $name): void
    {
        $this->writeStub($name, "#!/bin/sh\n/bin/cat >/dev/null 2>&1\nexit 0\n");
    }

    private function writeStub(string $name, string $body): void
    {
        \file_put_contents($this->bin . '/' . $name, $body);
        \chmod($this->bin . '/' . $name, 0o755);
    }

    private function requirePosixHost(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('the shell stubs and the pid confirmation need a POSIX host');
        }
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('confirming the KILL landed needs ext-posix');
        }
    }
}
