<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\SystemClipboard;

/**
 * Which host clipboard tool a copy reaches, and the one real spawn.
 *
 * Every binary is a stub on a temp PATH, so no test touches the developer's
 * real clipboard (tests/bootstrap.php pins the seam off for everything else).
 */
final class SystemClipboardTest extends TestCase
{
    private const VARS = ['PATH', 'TMUX', 'DISPLAY', 'WAYLAND_DISPLAY'];

    /** @var array<string, string|false> */
    private array $saved = [];

    private string $bin = '';

    protected function setUp(): void
    {
        // This file pins the REAL discovery gates, so the discovery override
        // must start clear no matter what ran before it in this process.
        SystemClipboard::useCandidatesForTesting(null);

        foreach (self::VARS as $var) {
            $this->saved[$var] = getenv($var);
            putenv($var);
        }

        $this->bin = sys_get_temp_dir() . '/sc_clipboard_bin_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->bin, 0o700);
        putenv('PATH=' . $this->bin);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $var => $value) {
            putenv($value === false ? $var : $var . '=' . $value);
        }

        foreach (glob($this->bin . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->bin);

        SystemClipboard::useRunnerForTesting(static fn (): bool => false);
    }

    public function testInsideTmuxOnlyTmuxIsTriedWithTheOuterTerminalFormFirst(): void
    {
        $this->stub('tmux');
        $this->stub('xclip');
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        putenv('DISPLAY=:0');

        self::assertSame(
            [
                [$this->bin . '/tmux', 'load-buffer', '-w', '-'],
                [$this->bin . '/tmux', 'load-buffer', '-'],
            ],
            SystemClipboard::candidates(),
            'tmux ignores an app\'s OSC 52 under its default set-clipboard, so tmux is the route that works',
        );
    }

    public function testOutsideTmuxTheDisplayServersToolsAreTriedInOrder(): void
    {
        $this->stub('wl-copy');
        $this->stub('xclip');
        $this->stub('xsel');
        putenv('WAYLAND_DISPLAY=wayland-0');
        putenv('DISPLAY=:0');

        $binaries = array_map(static fn (array $argv): string => basename($argv[0]), SystemClipboard::candidates());
        // pbcopy is not stubbed, so it is absent from this PATH on every OS.
        self::assertSame(['wl-copy', 'xclip', 'xsel'], $binaries);
    }

    public function testAToolThatIsNotOnPathIsNeverOffered(): void
    {
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        putenv('DISPLAY=:0');

        self::assertSame([], SystemClipboard::candidates(), 'a spawn is never aimed at a missing binary');
        self::assertFalse(SystemClipboard::copy('text'));
    }

    /**
     * The headless polarity of the discovery gate: with no TMUX, no DISPLAY
     * and no WAYLAND_DISPLAY (setUp cleared them all) and PATH pointing at an
     * empty stub dir, the real discovery finds nothing on any OS — pbcopy,
     * wl-copy, xclip and xsel all still demand a located binary. This is why
     * a flow test on CI must stub discovery
     * ({@see SystemClipboard::useCandidatesForTesting()}) rather than only
     * the spawn, and why the tmux/display tests above setting the env hints
     * are the proof the gate works in the positive direction.
     */
    public function testAHeadlessHostDiscoversNothingForTheRealGate(): void
    {
        self::assertSame([], SystemClipboard::candidates(), 'no env hint, so no candidate to aim a spawn at');
        self::assertFalse(SystemClipboard::copy('text'), 'a headless copy is simply not served by a host tool');
    }

    public function testCopyStopsAtTheFirstToolThatAcceptsAndSkipsEmptyText(): void
    {
        $this->stub('tmux');
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        $calls = [];
        SystemClipboard::useRunnerForTesting(static function (array $argv, string $text) use (&$calls): bool {
            $calls[] = implode(' ', array_slice($argv, 1));

            return true;
        });

        self::assertFalse(SystemClipboard::copy(''), 'nothing to copy');
        self::assertSame([], $calls);

        self::assertTrue(SystemClipboard::copy('hello'));
        self::assertSame(['load-buffer -w -'], $calls, 'the fallback form is not run once the first succeeded');
    }

    public function testCopyFallsBackWhenTheFirstFormFails(): void
    {
        $this->stub('tmux');
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        $calls = [];
        SystemClipboard::useRunnerForTesting(static function (array $argv) use (&$calls): bool {
            $calls[] = implode(' ', array_slice($argv, 1));

            return !in_array('-w', $argv, true);
        });

        self::assertTrue(SystemClipboard::copy('hello'));
        self::assertSame(['load-buffer -w -', 'load-buffer -'], $calls, 'an older tmux without -w still gets the buffer');
    }

    public function testTheRealSpawnFeedsTheTextOnStdinAndReportsTheExitCode(): void
    {
        SystemClipboard::useRunnerForTesting(null);
        $out = $this->bin . '/received';
        $this->stub('tmux', "/bin/cat > " . escapeshellarg($out) . "\nexit 0");
        putenv('TMUX=/tmp/tmux-1000/default,1,0');

        $text = "line one\n    indented — ünïcode\n" . str_repeat('x', 200_000);

        self::assertTrue(SystemClipboard::copy($text));
        self::assertSame($text, file_get_contents($out), 'every byte, including more than one pipe buffer\'s worth');
    }

    public function testAFailingToolReportsFailureAndIsNotRetriedForever(): void
    {
        SystemClipboard::useRunnerForTesting(null);
        $this->stub('tmux', "/bin/cat > /dev/null\nexit 1");
        putenv('TMUX=/tmp/tmux-1000/default,1,0');

        self::assertFalse(SystemClipboard::copy('hello'), 'both tmux forms exited non-zero');
    }

    private function stub(string $name, string $body = 'exit 0'): void
    {
        $path = $this->bin . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0o700);
    }
}
