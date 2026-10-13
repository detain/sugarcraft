<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\BuiltIn\PtyRun;

/**
 * The PtyRun tool: a real pseudo-terminal a model can TYPE at.
 *
 * Every pty-touching row carries BOTH polarities in the house shape (see
 * {@see InteractivePromptContainmentTest}): on a host without the pty
 * mechanism the refusal text is the assertion, so no row skips and the
 * SuiteSkipRoster stays untouched. The program under drive is
 * `tests/fixtures/pty-mini-tui.php` — the same CONTRACT sugar-crush's own TUI
 * offers (born-with winsize, arrows move a selection, `q` quits); a second
 * sugar-crush session would need provider config and network, so the fixture
 * stands in and these pins are the parity proof for the real thing.
 *
 * @see PtyRun
 */
final class PtyRunToolTest extends TestCase
{
    private const MINI_TUI_FIXTURE = __DIR__ . '/../fixtures/pty-mini-tui.php';

    public function testSchemaAdvertisesTheDrivingSurface(): void
    {
        $schema = (new PtyRun())->inputSchema();

        self::assertSame(['command', 'description'], $schema['required']);
        foreach (['cols', 'rows', 'timeout', 'maxBytes', 'harvest', 'steps'] as $key) {
            self::assertArrayHasKey($key, $schema['properties'], $key . ' is advertised');
            self::assertNotContains($key, $schema['required'], $key . ' stays optional');
        }

        self::assertSame(PtyRun::MAX_COLS, $schema['properties']['cols']['maximum']);
        self::assertSame(PtyRun::MAX_TIMEOUT_SECONDS, $schema['properties']['timeout']['maximum']);
        self::assertSame(PtyRun::MAX_CAPTURE_BYTES, $schema['properties']['maxBytes']['maximum']);
        self::assertSame(['transcript', 'screen'], $schema['properties']['harvest']['enum']);
        self::assertSame(PtyRun::MAX_STEPS, $schema['properties']['steps']['maxItems']);
        self::assertSame(['send', 'wait', 'expect', 'timeout'], array_keys($schema['properties']['steps']['items']['properties']));
    }

    public function testDecodeTurnsNamedKeysIntoRealTerminalBytes(): void
    {
        self::assertSame("\x1b[B", PtyRun::decode('<Down>'));
        self::assertSame("\x1b[A\x1b[C", PtyRun::decode('<Up><Right>'));
        self::assertSame("\r", PtyRun::decode('<Enter>'));
        self::assertSame("\x03", PtyRun::decode('<C-c>'));
        self::assertSame("\x1b", PtyRun::decode('\e'));
        self::assertSame('aAb', PtyRun::decode('a\x41b'));
        self::assertSame("\x1b[1;31mred", PtyRun::decode('\e[1;31mred'));
        self::assertSame('<', PtyRun::decode('\<'));
        // Unrecognised shapes stay literal text BY CONTRACT — a silent refusal
        // would eat legitimate payloads like regex-ish keystroke strings.
        self::assertSame('<Nope>', PtyRun::decode('<Nope>'));
        self::assertSame("\\q", PtyRun::decode('\\q'));
        // \t is a KNOWN escape, so it decodes to a real TAB — the grammar is
        // opt-out literal, not opt-in.
        self::assertSame("tab\there", PtyRun::decode('tab\there'));
    }

    public function testMalformedPlansAreRefusedBeforeAnythingStarts(): void
    {
        $plans = [
            'not-a-list' => ['steps' => ['send' => 'x']],
            'empty-object' => ['steps' => [[]]],
            'unknown-key' => ['steps' => [['snd' => 'x']]],
            'wait-not-an-int' => ['steps' => [['wait' => 'soon']]],
            'timeout-without-expect' => ['steps' => [['send' => 'x', 'timeout' => 100]]],
            'oversized-send' => ['steps' => [['send' => str_repeat('x', PtyRun::MAX_SEND_BYTES_PER_STEP + 1)]]],
            'too-many-steps' => ['steps' => array_fill(0, PtyRun::MAX_STEPS + 1, ['wait' => 0])],
            'bad-regex-expect' => ['steps' => [['expect' => '/([/']]],
        ];

        foreach ($plans as $label => $extra) {
            $started = microtime(true);
            $result = (new PtyRun())->execute($extra + [
                'command' => 'php ' . escapeshellarg(self::MINI_TUI_FIXTURE),
                'description' => 'Refused plan',
                'timeout' => 1,
            ]);
            self::assertTrue($result->isError(), $label . ': refused');
            self::assertStringContainsString('nothing was started', $result->content(), $label . ': nothing spawned');
            self::assertLessThan(2.0, microtime(true) - $started, $label . ': refusal is immediate');
        }
    }

    public function testRefusesWithoutASpawnWhenThePtyMechanismIsAbsent(): void
    {
        $memo = new ReflectionProperty(ProcessContainment::class, 'interactiveAvailableMemo');
        $memo->setValue(null, false);

        try {
            $result = (new PtyRun())->execute([
                'command' => 'php ' . escapeshellarg(self::MINI_TUI_FIXTURE),
                'description' => 'Driven with no pty',
            ]);
        } finally {
            ProcessContainment::resetInteractiveAvailabilityForTesting();
        }

        self::assertTrue($result->isError());
        self::assertStringContainsString('pty mechanism unavailable', $result->content());
        self::assertStringContainsString('never started', $result->content());
    }

    public function testWindowSizeReachesTheChildBeforeItsFirstPaint(): void
    {
        $result = $this->driveFixture(120, 10, [
            ['expect' => 'ROWS=10', 'timeout' => 9000],
            ['send' => 'q', 'expect' => 'BYE'],
        ], 20);

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertStringContainsString('COLS=120 ROWS=10', $result->content());
        // A 120-wide border is PROOF of the winsize, not of a default: the
        // fixture pads its frame to the ctty columns it reads.
        self::assertStringContainsString('+' . str_repeat('-', 118) . "+\r\n", $result->content());
        self::assertStringContainsString('exited code=0', $result->content());
        self::assertFalse($result->isError());
    }

    public function testArrowKeysDriveStateAndQuitExitsCleanly(): void
    {
        $result = $this->driveFixture(40, 10, [
            ['send' => '<Down>', 'expect' => 'SEL=1'],
            ['send' => '<Down>', 'expect' => 'SEL=2'],
            ['send' => '<Up>', 'expect' => 'SEL=1'],
            ['send' => 'q', 'expect' => 'BYE'],
        ], 30);

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertStringNotContainsString('NO-MATCH', $result->content());
        self::assertStringContainsString('exited code=0', $result->content());
        self::assertFalse($result->isError());
    }

    public function testExpectMissIsLoudAndTheScriptStillContinues(): void
    {
        $started = microtime(true);
        $result = $this->driveFixture(60, 12, [
            ['expect' => 'ZZZ-NEVER-PAINTED', 'timeout' => 700],
            ['send' => 'q', 'expect' => 'BYE'],
        ], 30);
        $elapsed = microtime(true) - $started;

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertTrue($result->isError(), 'a miss must surface as an error even when the exit is clean');
        self::assertStringContainsString('NO-MATCH', $result->content());
        self::assertStringContainsString('BYE', $result->content(), 'step two still ran after the miss');
        self::assertStringContainsString('exited code=0', $result->content());
        self::assertLessThan(15.0, $elapsed, 'the miss is bounded by its own expect timeout');
    }

    public function testWallDeadlineTerminatesAndReapsASilentSleeper(): void
    {
        $started = microtime(true);
        // `trap '' HUP` ignores SIGHUP, and a disposition set to ignore SURVIVES
        // `exec`, so the `sleep` that replaces the shell still shrugs off the HUP
        // the master close sends. That leaves the TERM→KILL ladder as the ONLY
        // thing that can end the child — with escalate() neutered the post-
        // teardown alive() check below goes red instead of passing vacuously.
        $result = (new PtyRun())->execute([
            'command' => "trap '' HUP; exec sleep 60",
            'description' => 'Prove the ceiling',
            'timeout' => 2,
        ]);
        $elapsed = microtime(true) - $started;

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertTrue($result->isError());
        self::assertLessThan(12.0, $elapsed, 'the wall deadline plus the reaper ladder bound the whole call');
        self::assertStringContainsString('still running at the wall deadline', $result->content());
        self::assertStringContainsString('terminated and reaped', $result->content());

        self::assertSame(1, preg_match('/pid=(\d+)/', $result->content(), $m), 'header names the pid');
        self::assertFalse(self::alive((int) $m[1]), 'the driven process is gone, not orphaned');
    }

    public function testMaxBytesBoundsTheWholeTranscript(): void
    {
        $result = (new PtyRun())->execute([
            'command' => 'yes AAAAAAAAAA | head -c 30000',
            'description' => 'Flood the pty',
            'maxBytes' => 4096,
            'timeout' => 15,
        ]);

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertStringContainsString('4096B captured', $result->content());
        self::assertStringContainsString('dropped past maxBytes', $result->content());
        self::assertLessThanOrEqual(4096 + 4096, strlen($result->content()), 'header and step chrome aside, the payload obeys the budget');
    }

    public function testHarvestScreenReturnsOnlyTheFinalFrame(): void
    {
        $result = $this->driveFixture(60, 12, [
            ['expect' => 'COLS='],
            ['send' => 'q', 'expect' => 'BYE'],
        ], 30, harvest: 'screen');

        if (!$this->ptyWorks($result)) {
            return;
        }

        self::assertStringContainsString('BYE', $result->content());
        self::assertStringNotContainsString('step 1', $result->content(), 'screen harvest collapses the per-step chrome');
        self::assertStringNotContainsString('COLS=60', $result->content(), 'the startup frame is history, not the screen');
        self::assertFalse($result->isError());
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function driveFixture(int $cols, int $rows, array $steps, int $timeout, string $harvest = 'transcript'): \SugarCraft\Crush\Tools\ToolResult
    {
        return (new PtyRun(root: dirname(__DIR__, 2)))->execute([
            'command' => 'php ' . escapeshellarg(self::MINI_TUI_FIXTURE),
            'description' => 'Drive the mini-TUI',
            'cols' => $cols,
            'rows' => $rows,
            'steps' => $steps,
            'timeout' => $timeout,
            'harvest' => $harvest,
        ]);
    }

    /**
     * Both-polarities gate: without the mechanism the refusal is the whole
     * answer, and the row passes by asserting it. Never a skip (house law).
     */
    private function ptyWorks(\SugarCraft\Crush\Tools\ToolResult $result): bool
    {
        if (ProcessContainment::interactiveAvailable()) {
            return true;
        }

        self::assertStringContainsString('pty mechanism unavailable', $result->content());

        return false;
    }

    private static function alive(int $pid): bool
    {
        if (!is_dir('/proc/' . $pid)) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat)) {
            return false;
        }
        $state = substr($stat, strrpos($stat, ')') + 2, 1);

        return $state !== 'Z' && $state !== 'X';
    }
}
