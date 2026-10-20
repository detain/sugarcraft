<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\TmuxPassthrough;

/**
 * F-05: transactional tmux allow-passthrough ensure/restore.
 *
 * A fake `tmux` planted on PATH records every invocation to a sidecar log
 * and emulates the option state in a sidecar file, so the pins below prove
 * the SEQUENCE and the ARMS — show → (set) → restore, and restore via the
 * `-u` clear ONLY when the option was previously unset, explicit re-set
 * when it was set to something else. Mutation the review loop demands:
 * drop the iff-unset arm in TmuxPassthrough::retire() and
 * testTheUnsetPolarityClearsWithDashUAndNeverALiteral goes red.
 *
 * Same fake-binary discipline as ChafaRendererChildTest (the lib's existing
 * idiom): no real tmux is ever contacted; env knobs are snapshotted before
 * mutation and restored whole in tearDown (one-sided sandbox law).
 */
final class TmuxPassthroughTest extends TestCase
{
    /** One show-options query, as the product emits it. */
    private const SHOW_CALL = 'show-options -pqv allow-passthrough';

    /** One pane-scoped flip-on, as the product emits it. */
    private const SET_CALL = 'set-option -p allow-passthrough on';

    /** Restore of a previously UNSET option: the clear arm, not a literal. */
    private const CLEAR_CALL = 'set-option -pu allow-passthrough';

    private string $dir;
    private string $log;
    private string $state;
    private ?string $pathBackup = null;
    private ?string $tmuxBackup = null;
    private ?string $kittyBackup = null;

    protected function setUp(): void
    {
        // Snapshot FIRST, mutate second (lane-cf law): the backups must hold
        // the pre-test environment, never the unset form of this test's own loop.
        $this->pathBackup = self::envOrNull('PATH');
        $this->tmuxBackup = self::envOrNull('TMUX');
        $this->kittyBackup = self::envOrNull('KITTY_WINDOW_ID');

        $this->dir = sys_get_temp_dir() . '/s15-fake-tmux-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700);
        $this->log = $this->dir . '/calls.log';
        $this->state = $this->dir . '/option';

        $this->plantFakeTmux($this->quietFakeScript());
        putenv('TMUX=/tmp/fake.sock,1.0');
        putenv('FAKE_TMUX_SHOW_EXIT=0');
        putenv('FAKE_TMUX_SET_EXIT=0');
        Detect::reset();
        Detect::setAutoPassthrough(true);
        $this->assertFalse(TmuxPassthrough::isApplied(), 'state leaked from an earlier test into ' . __METHOD__);
    }

    protected function tearDown(): void
    {
        // Close any transaction this test opened BEFORE the fake tree dies,
        // then restore every env knob the test moved.
        TmuxPassthrough::retire();
        Detect::setAutoPassthrough(true);
        Detect::reset();

        $this->restoreEnv('PATH', $this->pathBackup);
        $this->restoreEnv('TMUX', $this->tmuxBackup);
        $this->restoreEnv('KITTY_WINDOW_ID', $this->kittyBackup);
        putenv('FAKE_TMUX_LOG');
        putenv('FAKE_TMUX_STATE');
        putenv('FAKE_TMUX_DIR');
        putenv('FAKE_TMUX_SHOW_EXIT');
        putenv('FAKE_TMUX_SET_EXIT');

        foreach ((array) scandir($this->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->dir . '/' . $entry)) {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testTheUnsetPolarityClearsWithDashUAndNeverALiteral(): void
    {
        // Option never set: show answers empty, so the restore must CLEAR
        // (-u), not write a value tmux would keep as an explicit setting.
        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertSame(['show-options', 'set-option'], $this->verbs(), 'show must precede set');

        $this->assertTrue(TmuxPassthrough::retire());
        $calls = $this->calls();
        $this->assertCount(3, $calls);
        $this->assertSame(self::SHOW_CALL, $calls[0]);
        $this->assertSame(self::SET_CALL, $calls[1]);
        $this->assertSame(self::CLEAR_CALL, $calls[2], 'previously-unset restores via the -u clear arm');
        $this->assertFileDoesNotExist($this->state, 'the fake state file stands in for the pane option');
        $this->assertFalse(TmuxPassthrough::isApplied());
    }

    public function testARememberedOffValueIsRestoredExplicitlyNotCleared(): void
    {
        // The other polarity of the same law: the option WAS set (to off),
        // so retire writes it back — never a bare -u.
        file_put_contents($this->state, 'off');

        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertTrue(TmuxPassthrough::retire());

        $calls = $this->calls();
        $this->assertCount(3, $calls);
        $this->assertSame('set-option -p allow-passthrough off', $calls[2]);
        $this->assertStringNotContainsString('-u', implode("\n", $calls));
        $this->assertSame('off', file_get_contents($this->state));
    }

    public function testAnAlreadyPassingValueIsLeftAloneAndNeedsNoRestore(): void
    {
        // chicle-options.c:1811-1814 "don't mess with it if we don't have to".
        file_put_contents($this->state, 'all');

        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertSame([self::SHOW_CALL], $this->calls(), 'on/all must never be touched');

        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertSame([self::SHOW_CALL], $this->calls(), 'nothing changed, so nothing restores');
        $this->assertSame('all', file_get_contents($this->state), 'the value stands exactly as found');
    }

    public function testEnsureAndRetireAreIdempotent(): void
    {
        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertTrue(TmuxPassthrough::ensure(), 'second ensure reports the first outcome');
        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertCount(2, $this->calls(), 'one show and one set for the whole latch');

        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertCount(3, $this->calls(), 'exactly one restore line lands');

        // A fresh transaction after the retired one re-runs the show.
        $this->assertTrue(TmuxPassthrough::ensure());
        $this->assertCount(5, $this->calls());
    }

    public function testRetireWithoutEnsureIsANoOpThatNeverSpawns(): void
    {
        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertFileDoesNotExist($this->log);
    }

    public function testOutsideTmuxNothingIsAskedAndNothingIsSet(): void
    {
        putenv('TMUX'); // unset form — the fail-soft door, not an error
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertFalse(TmuxPassthrough::isApplied());
        $this->assertFileDoesNotExist($this->log, 'the TMUX gate must short-circuit before any exec');
    }

    public function testTheDetectOptOutStopsEveryCall(): void
    {
        Detect::setAutoPassthrough(false);
        $this->assertFalse(Detect::autoPassthrough());
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertFileDoesNotExist($this->log, 'opt-out means zero tmux invocations');
    }

    public function testAnUnparseableAnswerHandsOffWithoutTouching(): void
    {
        file_put_contents($this->state, 'sideways');
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertSame([self::SHOW_CALL], $this->calls(), 'a value we could not restore must never be overwritten');
        $this->assertFalse(TmuxPassthrough::isApplied());
        $this->assertTrue(TmuxPassthrough::retire(), 'nothing was applied, so nothing restores — and that is success');
    }

    public function testAMissingTmuxBinaryFailsSoft(): void
    {
        putenv('PATH=' . $this->dir . '/nowhere');
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertFalse(TmuxPassthrough::isApplied());
        $this->assertFileDoesNotExist($this->log);
    }

    public function testADeadServerAttemptsTheSetThenStopsWithoutHoldingState(): void
    {
        // show answers exit!=0 with EMPTY output (no current pane / dead
        // server): the transaction may still try the set — when THAT also
        // answers failure, the fail-soft law ends the story with no state.
        putenv('FAKE_TMUX_SHOW_EXIT=1');
        putenv('FAKE_TMUX_SET_EXIT=1');
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertFalse(TmuxPassthrough::isApplied());
        $this->assertCount(2, $this->calls(), 'show, then one rejected set — and no retry storm');
    }

    public function testAHangingChildIsKilledAndTheEnsureFailsSoftWithinBudget(): void
    {
        $this->plantFakeTmux($this->stubbornHangFakeScript());

        $started = hrtime(true);
        $this->assertFalse(TmuxPassthrough::ensure());
        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertLessThan(5.0, $elapsedSeconds, 'deadline + TERM grace + KILL grace bound one call');
        $this->assertSame([self::SHOW_CALL], $this->calls());
        $this->assertFalse(TmuxPassthrough::isApplied());

        $pid = (int) trim((string) @file_get_contents($this->dir . '/hang.pid'));
        $this->assertGreaterThan(0, $pid);
        if (function_exists('posix_kill')) {
            // KILL is uncatchable and runTmux ends every path in proc_close,
            // so the pid must be fully gone — not a zombie — for good.
            $this->assertFalse(@posix_kill($pid, 0), 'the escalated child must not outlive ensure()');
        }
    }

    public function testAFloodPastTheByteCapIsKilledAndRefused(): void
    {
        $this->plantFakeTmux($this->floodFakeScript());
        $this->assertFalse(TmuxPassthrough::ensure());
        $this->assertSame([self::SHOW_CALL], $this->calls(), 'no set follows a refused flood');
        $this->assertFalse(TmuxPassthrough::isApplied());
    }

    public function testMosaicProbeArmsTheTransactionForAPixelCapabilityUnderTmux(): void
    {
        // The S1.5 wire: Mosaic::probe() → Detect::probe() sees KITTY_WINDOW_ID
        // + TMUX → fromCapability → ensure(). Public entry, no reflection.
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();

        $this->assertInstanceOf(Mosaic::class, Mosaic::probe());
        $this->assertSame([self::SHOW_CALL, self::SET_CALL], $this->calls(),
            'a tmux pane with a graphics protocol must get the ensure bracket');

        $this->assertTrue(TmuxPassthrough::retire());
        $this->assertSame(self::CLEAR_CALL, $this->calls()[2]);
    }

    public function testMosaicProbeStaysQuietOutsideTmuxDespitePixelCapability(): void
    {
        putenv('TMUX');
        putenv('KITTY_WINDOW_ID=1');
        Detect::reset();

        $this->assertInstanceOf(Mosaic::class, Mosaic::probe());
        $this->assertFileDoesNotExist($this->log, 'outside tmux the gate never execs at all');
    }

    // ------------------------------------------------------------- harness

    private function quietFakeScript(): string
    {
        return <<<'SH'
#!/bin/sh
echo "$*" >> "$FAKE_TMUX_LOG"
case "$1" in
  show-options)
    if [ -f "$FAKE_TMUX_STATE" ]; then cat "$FAKE_TMUX_STATE"; fi
    exit ${FAKE_TMUX_SHOW_EXIT:-0}
    ;;
  set-option)
    shift
    cleared=0
    while [ "${1#-}" != "$1" ]; do
      case "$1" in
        *-u*) cleared=1 ;;
      esac
      shift
    done
    if [ "$cleared" = 1 ] || [ -z "$2" ]; then
      rm -f "$FAKE_TMUX_STATE"
    else
      printf '%s' "$2" > "$FAKE_TMUX_STATE"
    fi
    exit ${FAKE_TMUX_SET_EXIT:-0}
    ;;
esac
exit 0
SH;
    }

    private function stubbornHangFakeScript(): string
    {
        // Ignores SIGTERM — the ignored disposition rides through exec —
        // and BECOMES the sleep (exec, no shell child left orphaned by the
        // KILL). Only the ladder's last rung ends it. That is the point.
        return <<<'SH'
#!/bin/sh
echo "$*" >> "$FAKE_TMUX_LOG"
echo $$ > "$FAKE_TMUX_DIR/hang.pid"
trap '' TERM
exec sleep 30
SH;
    }

    private function floodFakeScript(): string
    {
        // Answers any query with 128 KiB of 'a' — past MAX_OUTPUT_BYTES.
        return <<<'SH'
#!/bin/sh
echo "$*" >> "$FAKE_TMUX_LOG"
dd if=/dev/zero bs=1024 count=128 2>/dev/null | tr '\0' 'a'
exit 0
SH;
    }

    private function plantFakeTmux(string $script): void
    {
        file_put_contents($this->dir . '/tmux', $script);
        chmod($this->dir . '/tmux', 0700);
        putenv('FAKE_TMUX_DIR=' . $this->dir);
        putenv('FAKE_TMUX_LOG=' . $this->log);
        putenv('FAKE_TMUX_STATE=' . $this->state);
        putenv('PATH=' . $this->dir . ($this->pathBackup === null ? '' : ':' . $this->pathBackup));
    }

    /** @return list<string> every recorded invocation, in order */
    private function calls(): array
    {
        $this->assertFileExists($this->log);
        $raw = (string) file_get_contents($this->log);
        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    /** @return list<string> the tmux verb of every recorded invocation */
    private function verbs(): array
    {
        return array_map(static fn (string $call): string => explode(' ', $call)[0], $this->calls());
    }

    private static function envOrNull(string $key): ?string
    {
        $value = getenv($key);
        return $value === false ? null : $value;
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        // null means "was not set" — restore with the unset form, never ''.
        if ($value === null) {
            putenv($key);
            return;
        }
        putenv($key . '=' . $value);
    }
}
