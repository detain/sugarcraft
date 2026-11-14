<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\MultiSelectPrompt;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\TextareaPrompt;

/**
 * S-04 (finding 003): painted height must be bounded by the terminal's
 * row count. Selection/MultiSelect shrink their page to fit; the textarea
 * windows its lines with scroll indicators; run() adopts the row budget
 * (LINES first, then the TIOCGWINSZ probe) exactly like the width seam.
 */
final class PromptHeightClampTest extends TestCase
{
    /** @return list<string> */
    private static function choices(int $n): array
    {
        return array_map(static fn(int $i): string => "choice-number-{$i}-with-a-ridiculously-long-label-to-stress-it", range(1, $n));
    }

    /** @return array{0:resource,1:resource,2:resource,3:resource} */
    private function makePairs(): array
    {
        $in = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $out = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        return [$in[1], $in[0], $out[1], $out[0]];
    }

    private function frameRows(string $frame): array
    {
        $rows = [];
        foreach (explode("\n", $frame) as $row) {
            $row = rtrim($row, "\r");
            if ($row !== '') {
                $rows[] = $row;
            }
        }
        // The tail after the final "\n" is the NEXT repaint's control
        // preamble (\r + CSI nA climb), not a painted row.
        if ($rows !== [] && preg_match('/^[\r\x1b\[\d;A-J]*$/', $rows[count($rows) - 1]) === 1) {
            array_pop($rows);
        }
        return $rows;
    }

    public function testSelectionFrameWithinRows(): void
    {
        $p = SelectionPrompt::new('> ', self::choices(12))->withDisplayWidth(60)->withViewportRows(8);
        $rows = explode("\n", $p->view());
        $this->assertLessThanOrEqual(8, count($rows), 'label + bar + page + footer must fit the budget');
        $this->assertSame(3, $p->totalPages(), '12 choices at rows=8 paginate 5+5+2');
        $this->assertSame(2, $p->withViewportRows(null)->totalPages(), 'unbounded page stays 10');
        $this->assertSame(8, $p->viewportRows());
    }

    public function testSelectionPagingUnderClampStaysInsideRows(): void
    {
        $p = SelectionPrompt::new('> ', self::choices(12))->withDisplayWidth(60)->withViewportRows(8);
        for ($i = 0; $i < 7; $i++) {
            $p = $p->handleKey('down');
        }
        $this->assertSame(7, $p->cursor());
        $this->assertSame(1, $p->currentPage());
        $rows = explode("\n", $p->view());
        $this->assertLessThanOrEqual(8, count($rows));
        $this->assertStringContainsString('Page 2/3', $rows[count($rows) - 1]);
    }

    public function testMultiSelectFrameWithinRows(): void
    {
        $p = MultiSelectPrompt::new('> ', self::choices(12))->withDisplayWidth(60)->withViewportRows(8);
        $rows = explode("\n", $p->view());
        $this->assertLessThanOrEqual(8, count($rows), 'label + hint + bar + page + footer must fit');
        $this->assertStringContainsString('[multi-select] selected: 0', $rows[1]);
    }

    public function testTextareaWindowsLinesWithIndicators(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => "line {$i}", range(0, 19)));
        $p = TextareaPrompt::new('> ')->withDefault($lines)->withDisplayWidth(50)->withViewportRows(8);
        $view = $p->view();
        $rows = explode("\n", $view);
        $this->assertLessThanOrEqual(8, count($rows));
        $this->assertStringContainsString('line 19', $view, 'cursor line must be inside the window');
        $this->assertStringContainsString('…', $view, 'truncated head must be indicated');
        $this->assertStringNotContainsString('line 0', $view, 'scrolled-away lines are not painted');
    }

    public function testTinyGateSuppressesIndicatorsBelowFiveRows(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => "line {$i}", range(0, 19)));
        $p = TextareaPrompt::new('> ')->withDefault($lines)->withViewportRows(4)->withDisplayWidth(50);
        $rows = explode("\n", $p->view());
        $this->assertCount(2, $rows, 'label + exactly one body line on a 4-row terminal');
        $this->assertStringNotContainsString('…', $rows[1]);
        $this->assertStringContainsString('line 19', $rows[1]);
    }

    public function testExplicitZeroRowsUnbounded(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => "line {$i}", range(0, 19)));
        $p = TextareaPrompt::new('> ')->withDefault($lines)->withViewportRows(0);
        $this->assertSame(0, $p->viewportRows());
        $this->assertCount(21, explode("\n", $p->view()), 'explicit 0 paints every line (legacy shape)');
    }

    public function testRunAdoptsRowsFromLinesEnv(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $prevCols = getenv('COLUMNS');
        $prevLines = getenv('LINES');
        putenv('COLUMNS');
        putenv('LINES=8');
        try {
            fwrite($iw, "\r");
            fclose($iw);
            $driver = new StreamInputDriver($ir);
            $final = (new Readline($driver))->run(SelectionPrompt::new('> ', self::choices(12)), $ow);
            $this->assertSame(8, $final->viewportRows(), 'unset budget adopts LINES on the height path');
            $this->assertSame(3, $final->totalPages());
        } finally {
            foreach ([['COLUMNS', $prevCols], ['LINES', $prevLines]] as [$k, $v]) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
            foreach ([$ir, $ow, $or] as $s) {
                @fclose($s);
            }
        }
    }

    /**
     * Repaint desync regression: with the height adopted from LINES, no
     * frame may paint more rows than the terminal has, and the cursor-up
     * climb must never exceed that either.
     */
    public function testRepaintClimbNeverExceedsRows(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $prevCols = getenv('COLUMNS');
        $prevLines = getenv('LINES');
        putenv('COLUMNS=40');
        putenv('LINES=8');
        try {
            $input = str_repeat("\x1b[B", 7) . "\r";
            fwrite($iw, $input);
            fclose($iw);
            $driver = new StreamInputDriver($ir);
            $final = (new Readline($driver))->run(SelectionPrompt::new('> ', self::choices(12)), $ow);
            $this->assertSame(8, $final->viewportRows());

            fclose($ow);
            $captured = '';
            while (false !== ($chunk = fread($or, 65536)) && $chunk !== '') {
                $captured .= $chunk;
            }
            $this->assertGreaterThan(3, substr_count($captured, "\x1b[0J"), 'one repaint per key expected');

            foreach (array_slice(explode("\x1b[0J", $captured), 1) as $frameNo => $frame) {
                $rows = $this->frameRows($frame);
                $this->assertLessThanOrEqual(8, count($rows), "frame #{$frameNo} exceeds the 8-row terminal");
                foreach ($rows as $row) {
                    $this->assertLessThanOrEqual(40, Width::of($row), "frame #{$frameNo} row exceeds width");
                }
            }
            preg_match_all("/\x1b\[(\d+)A/", $captured, $climbs);
            foreach ($climbs[1] as $climb) {
                $this->assertLessThanOrEqual(8, (int) $climb, 'CSI nA climb cannot exceed the terminal rows');
            }
        } finally {
            foreach ([['COLUMNS', $prevCols], ['LINES', $prevLines]] as [$k, $v]) {
                if ($v === false) {
                    putenv($k);
                } else {
                    putenv("{$k}={$v}");
                }
            }
            foreach ([$ir, $or] as $s) {
                @fclose($s);
            }
        }
    }

    /**
     * The interactive leg: no LINES in the environment, output is a real
     * pts — run() must adopt the TIOCGWINSZ row count (12 in the harness).
     */
    public function testTtyRowsProbeAdoptsTerminalRowsWhenUnset(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('util-linux script(1) harness is Linux-shaped here');
        }
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            $this->markTestSkipped('script(1) unavailable');
        }

        $child = __DIR__ . '/Support/rows_probe_child.php';
        $session = tempnam(sys_get_temp_dir(), 'srr');
        self::assertIsString($session);

        $inner = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child);
        $command = 'printf \'\\r\' | script -q -e -c ' . escapeshellarg('env -u COLUMNS -u LINES ' . $inner)
            . ' ' . escapeshellarg($session) . ' >/dev/null 2>&1';

        try {
            $handle = popen($command, 'r');
            self::assertIsResource($handle);
            while (!feof($handle)) {
                fgets($handle);
            }
            pclose($handle);

            $output = (string) file_get_contents($session);

            if (str_contains($output, 'NOTTY') || str_contains($output, 'NOROWS')) {
                $this->markTestSkipped('pts harness degraded on this box: ' . trim($output));
            }

            if (preg_match('/ROWS=(\S+)/', $output, $m) !== 1) {
                $this->fail('probe child produced no verdict: ' . trim($output));
            }

            $this->assertSame('12', $m[1], 'unset LINES on a TTY must adopt the TIOCGWINSZ rows');
        } finally {
            @unlink($session);
        }
    }
}
