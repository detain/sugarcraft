<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;

/**
 * Pins for the picker chrome rework (docs-fix-1023 lane E4): the localized
 * frame (Lang::t chrome with byte-identical 'en' values), the U1 count row,
 * the T1 abort-only screen cleanup, the T2 width clamp seam, and the T3
 * sort-stability contract. Behavior tests only — the byte-level sanitize
 * and UTF-8 integrity pins live in PickerSanitizeTest /
 * PickerInputIntegrityTest and must stay green untouched.
 */
final class PickerChromeTest extends TestCase
{
    /**
     * @param string $keys keystrokes fed to the picker
     * @param array<int,Endpoint> $endpoints
     * @param int|null $cols forced detectSize() width (null = probe)
     * @param int|null $rows forced detectSize() height (null = probe)
     * @return array{?Endpoint,string} [picked, output]
     */
    private function drive(string $keys, array $endpoints, ?int $cols = null, ?int $rows = null): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out, $cols, $rows) extends Picker {
            public function __construct(
                $in,
                $out,
                private readonly ?int $forcedCols,
                private readonly ?int $forcedRows,
            ) {
                parent::__construct($in, $out);
            }

            protected function setRawMode(bool $on): void
            {
            }

            protected function detectSize(): ?array
            {
                if ($this->forcedCols === null && $this->forcedRows === null) {
                    return parent::detectSize();
                }
                return ['cols' => $this->forcedCols, 'rows' => $this->forcedRows];
            }
        };

        $picked = $p->pick($endpoints);
        rewind($out);

        return [$picked, (string) stream_get_contents($out)];
    }

    /**
     * @return list<Endpoint>
     */
    private function threeHosts(): array
    {
        return [
            new Endpoint(name: 'prod', host: 'prod.example.com'),
            new Endpoint(name: 'dev', host: 'dev.example.com'),
            new Endpoint(name: 'stage', host: 'stage.example.com'),
        ];
    }

    public function testAbortClearsTheTransientFrameAndPickDoesNot(): void
    {
        // T1: Esc (and ^C) must erase the picker frame — a cancelled
        // session should not leave dead chrome on the terminal. A
        // successful pick deliberately keeps the frame (PickerSanitizeTest
        // pins that the Enter path never emits eraseScreen).
        [, $abortOut] = $this->drive("\x1b", $this->threeHosts());
        self::assertStringContainsString("\x1b[2J", $abortOut);
        self::assertStringContainsString("\x1b[1;1H", $abortOut);

        [$picked, $pickOut] = $this->drive("\r", $this->threeHosts());
        self::assertNotNull($picked);
        self::assertStringNotContainsString("\x1b[2J", $pickOut);
    }

    public function testCountRowAppearsOnlyWhileFiltering(): void
    {
        // U1: typing a filter shows how many of the loaded hosts remain.
        // SmithWaterman is a GAPPED LOCAL aligner: any shared high-value
        // substring scores, so 'p' (present via 'example' in all three)
        // keeps 3 of 3, while 't' (only 'stage' carries it) drops to 1 of 3.
        [, $one] = $this->drive('t' . "\r", $this->threeHosts());
        self::assertStringContainsString('  (1 of 3)', $one);

        [, $all] = $this->drive('p' . "\r", $this->threeHosts());
        self::assertStringContainsString('  (3 of 3)', $all);

        [, $unfiltered] = $this->drive("\r", $this->threeHosts());
        self::assertStringNotContainsString('of 3', $unfiltered);

        // No match must be honest too: 0 shown. ESC (not Enter) ends the
        // session — Enter on an empty match set is a deliberate no-op.
        [, $gone] = $this->drive('zzzz' . "\x1b", $this->threeHosts());
        self::assertStringContainsString('  (0 of 3)', $gone);
        self::assertStringContainsString('  (no matches)', $gone);
    }

    public function testEqualScoresPreserveConfigOrder(): void
    {
        // T3: usort() has been stable since PHP 8.0; equal-match rows keep
        // config order. 'x' matches the host label at the same offset with
        // the same score in both rows, so the FIRST config entry must be
        // the Enter target and the second must sit one j below it.
        $endpoints = [
            new Endpoint(name: 'alpha', host: 'x.test', port: 22),
            new Endpoint(name: 'beta', host: 'x.test', port: 23),
        ];

        [$picked] = $this->drive("x\r", $endpoints);
        self::assertSame(22, $picked?->port, 'first of the equal-score pair wins');

        // F-8 (Step 12): once 'x' is typed, 'j' is a LETTER, not navigation —
        // the arrows (unconditional) express "move down" from here on.
        [$down] = $this->drive("x\x1b[B\r", $endpoints);
        self::assertSame(23, $down?->port);
    }

    public function testJJoinsTheFilterOnceTypingStarted(): void
    {
        $endpoints = [
            new Endpoint(name: 'alpha', host: 'x.test', port: 22),
            new Endpoint(name: 'beta', host: 'x.test', port: 23),
        ];
        // The letter stays in the query: 'xj' still ranks both hosts (gapped
        // local alignment bridges 'j' via 'beta'), proving 'j' was intake,
        // not navigation. Backspace removes it and Enter picks the first
        // equal-score row.
        [$picked, $output] = $this->drive("xj\x7f\r", $endpoints);
        self::assertStringContainsString("\x1b[36mxj\x1b[0m", $output);
        self::assertStringContainsString('(2 of 2)', $output);
        self::assertSame(22, $picked?->port);
    }

    public function testRowsClampToOneLessThanTerminalWidth(): void
    {
        // T2: with a 40-column terminal, data rows never exceed 39 cells
        // (soft-wrap leftovers would survive eraseToEnd redraws). Chrome
        // rows are clamped too since -014 (the old exemption note lived
        // here); non-terminals pass through unclamped (the other tests in
        // this file pin that by never seeing an ellipsis).
        $endpoints = [
            new Endpoint(name: 'tiny', host: 'a.test'),
            new Endpoint(
                name: 'verylonghostname',
                host: 'this-host-name-is-deliberately-longer-than-any-pickable-terminal.example.org',
            ),
        ];

        [, $output] = $this->drive("\r", $endpoints, cols: 40);

        $rows = explode("\r\n", $output);
        foreach ($rows as $row) {
            self::assertLessThanOrEqual(39, Width::of($row), 'over-wide row at cols=40: ' . $row);
        }
        $clamped = array_values(array_filter(
            $rows,
            static fn(string $r): bool => str_contains($r, 'this-host-name')
        ));
        self::assertNotEmpty($clamped);
        foreach ($clamped as $row) {
            self::assertLessThanOrEqual(39, Width::of($row), 'row must fit 39 cells: ' . $row);
        }
        self::assertStringContainsString('…', $output, 'the truncated row carries an ellipsis');

        [, $wide] = $this->drive("\r", $endpoints, cols: 200);
        self::assertStringNotContainsString('…', $wide);
        self::assertStringContainsString(
            'this-host-name-is-deliberately-longer',
            preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $wide)
        );
    }

    public function testChromeFollowsTheLocale(): void
    {
        // M4 (picker side): the frame strings are Lang::t lookups, so a
        // non-English locale redraws in that language. Restores 'en'
        // unconditionally — the suite shares one process-global locale.
        /** @var array<string,string> $de */
        $de = require \dirname(__DIR__) . '/lang/de.php';
        try {
            T::setLocale('de');
            [, $output] = $this->drive("\r", $this->threeHosts());
            self::assertStringContainsString($de['picker.filter'], $output);
            self::assertStringContainsString($de['picker.help'], $output);
            self::assertStringNotContainsString('↑/↓ select', $output);
        } finally {
            T::setLocale('en');
        }
    }

    /**
     * @return list<Endpoint>
     */
    private function twelveHosts(): array
    {
        $hosts = [];
        foreach (range(1, 12) as $i) {
            $hosts[] = new Endpoint(name: 'host' . $i, host: 'h' . $i . '.test');
        }
        return $hosts;
    }

    public function testShortTerminalCutsTheWindowToRowsMinusChrome(): void
    {
        // -002: rows is read now. A 200×8 pane carries title+filter+blank+
        // help (4 chrome rows), so the window is 4 — not the old fixed 10
        // that scrolled the picker's own title and filter off-screen and
        // made type-to-search blind.
        [, $output] = $this->drive("\x1b", $this->twelveHosts(), rows: 8);

        $dataRows = array_filter(
            explode("\r\n", $output),
            static fn(string $r): bool => str_contains($r, '.test')
        );
        self::assertCount(4, $dataRows, 'a 200x8 pane shows exactly rows minus chrome, never the full 10');
        self::assertStringNotContainsString('host5', $output);
    }

    public function testPageSpanShrinksWithTheVisibleWindow(): void
    {
        // -002 navigation leg: PgDn pages by what fits (worst-case chrome),
        // not by a blind 10 that would scroll the short frame past itself.
        [, $output] = $this->drive("\x1b[6~\x1b", $this->twelveHosts(), rows: 8);
        // 8 rows minus the 6-row worst-case chrome = page span 2: the
        // window slides to hosts 3-6 — host12 (nine rows down) never shows.
        self::assertStringContainsString('host3', $output);
        self::assertStringNotContainsString('host12', $output);
    }

    public function testNarrowTerminalIsRefusedWithoutDrawing(): void
    {
        // -061: below MIN_COLS no honest frame exists — the refusal is a
        // null return (bin's cancel line fires) with zero bytes drawn,
        // raw mode never entered. 12 is the floor and renders.
        [$picked, $output] = $this->drive('', $this->threeHosts(), cols: 11);
        self::assertNull($picked);
        self::assertSame('', $output, 'the too-narrow refusal must not draw a frame');

        [$ok] = $this->drive("\r", $this->threeHosts(), cols: 12);
        self::assertNotNull($ok, '12 columns is renderable and must not be refused');
    }

    public function testClampFloorNeverExceedsTheTerminalWidth(): void
    {
        // -061: the old max(8, cols-1) floor kept clamped rows 8 cells
        // wide on narrower panes — the clamp defeating its own invariant
        // in exactly the band it existed for. Probe clampRow directly;
        // the sub-MIN_COLS band is unreachable through pick() now.
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $p = new class($in, $out) extends Picker {
            public function __construct($in, $out)
            {
                parent::__construct($in, $out);
            }

            protected function setRawMode(bool $on): void
            {
            }

            protected function detectSize(): ?array
            {
                return ['cols' => 6, 'rows' => null];
            }
        };
        $clamp = new \ReflectionMethod(Picker::class, 'clampRow');
        $clamp->setAccessible(true);
        $row = (string) $clamp->invoke($p, str_repeat('x', 40));
        self::assertLessThanOrEqual(5, Width::of($row), 'clamped row must fit cols-1 at 6 columns');
    }

    public function testGeometryIsReprobedEveryFrame(): void
    {
        // -013: the colsProbed latch held the first probed size forever —
        // a resize narrower mid-pick kept emitting 200-col rows onto a
        // 40-col pane (QA measured the stranded frame live). Queue: the
        // refusal probe and first frame are wide, the second frame is 40.
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "p\x1b");
        rewind($in);
        $p = new class($in, $out) extends Picker {
            /** @var list<array{cols:?int, rows:?int}> */
            public array $sizes = [
                ['cols' => 200, 'rows' => 24],
                ['cols' => 200, 'rows' => 24],
                ['cols' => 40, 'rows' => 24],
            ];

            public function __construct($in, $out)
            {
                parent::__construct($in, $out);
            }

            protected function setRawMode(bool $on): void
            {
            }

            protected function detectSize(): ?array
            {
                return count($this->sizes) > 1 ? array_shift($this->sizes) : $this->sizes[0];
            }
        };
        $endpoints = [
            new Endpoint(
                name: 'verylonghostname',
                host: 'this-host-name-is-deliberately-longer-than-any-pickable-terminal.example.org',
            ),
        ];
        $p->pick($endpoints);
        rewind($out);
        $output = (string) stream_get_contents($out);

        // Frames are delimited by the redraw cursor-home + erase-to-end prefix.
        $frames = explode("\x1b[1;1H\x1b[0J", $output);
        self::assertCount(3, $frames, 'expected an empty head plus two drawn frames');
        self::assertStringContainsString(
            'this-host-name-is-deliberately-longer-than-any-pickable-terminal.example.org',
            $frames[1],
            'the wide frame renders the row unclamped',
        );
        foreach (explode("\r\n", $frames[2]) as $row) {
            self::assertLessThanOrEqual(39, Width::of($row), 'the resized frame must clamp at 40 cols: ' . $row);
        }
        self::assertStringContainsString('…', $frames[2], 'the second frame carries the clamp ellipsis');
    }

    public function testResizeWakeFlagReturnsARepaintSentinelFromTheRead(): void
    {
        // -013 wake leg, deterministic without a PTY: the armed SIGWINCH
        // handler flags the picker, and readFirstByte turns the flag into
        // an unbound wake byte (one 200 ms select slice) so draw() reruns
        // while the user types nothing.
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_dispatch') || !function_exists('posix_kill')) {
            $this->markTestSkipped('the resize-wake pin needs ext-pcntl and ext-posix');
        }
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair unavailable in this build');
        }
        [$a, $b] = $pair;
        $p = new class($a, $b) extends Picker {
            public function __construct($in, $out)
            {
                parent::__construct($in, $out);
            }

            protected function setRawMode(bool $on): void
            {
            }
        };
        try {
            $arm = new \ReflectionMethod(Picker::class, 'armResizeSignal');
            $arm->setAccessible(true);
            $disarm = new \ReflectionMethod(Picker::class, 'disarmResizeSignal');
            $disarm->setAccessible(true);
            $pending = new \ReflectionProperty(Picker::class, 'resizePending');
            $pending->setAccessible(true);
            $read = new \ReflectionMethod(Picker::class, 'readFirstByte');
            $read->setAccessible(true);

            $arm->invoke($p);
            try {
                $this->assertTrue(posix_kill(posix_getpid(), SIGWINCH));
                pcntl_signal_dispatch();
                $this->assertTrue($pending->getValue($p), 'the armed handler must mark a pending repaint');
                $this->assertSame("\x0c", $read->invoke($p), 'the wake byte is unbound and switch-dropped');
                $this->assertFalse($pending->getValue($p), 'the wake consumes the flag');
            } finally {
                $disarm->invoke($p);
            }
            $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(SIGWINCH), 'disarm returns WINCH to default');
        } finally {
            fclose($a);
            fclose($b);
        }
    }
}
