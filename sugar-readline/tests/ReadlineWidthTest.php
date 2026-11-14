<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * S-01 (finding 001): width auto-adoption must reach past the COLUMNS env
 * var. When COLUMNS is unset or unusable and the pump paints to a real TTY,
 * run() adopts the TIOCGWINSZ column count; headless paths keep the old
 * env-only contract byte-for-byte.
 *
 * @see \SugarCraft\Readline\Readline::resolveColumns()
 */
final class ReadlineWidthTest extends TestCase
{
    /**
     * Drive run() over a socket pair; returns [finalPrompt, capturedBytes].
     *
     * @param resource $inWrite  write end feeding the input driver
     * @param resource $inRead   read end owned by the driver
     * @param resource $outWrite paint sink for run()
     * @param resource $outRead  capture side of the paint sink
     */
    private function drive($inWrite, $inRead, $outWrite, $outRead, TextPrompt $prompt, string $input): array
    {
        fwrite($inWrite, $input);
        $driver = new StreamInputDriver($inRead);
        $final = (new Readline($driver))->run($prompt, $outWrite);

        stream_set_blocking($outRead, false);
        $captured = '';
        while (false !== ($chunk = fread($outRead, 65536))) {
            $captured .= $chunk;
            if ($chunk === '') {
                break;
            }
        }

        return [$final, $captured];
    }

    /** @return array{0:resource,1:resource,2:resource,3:resource} */
    private function makePairs(): array
    {
        $in = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $out = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        return [$in[1], $in[0], $out[1], $out[0]];
    }

    public function testMalformedColumnsTreatedAsUnset(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=abc');
        try {
            [$final] = $this->drive($iw, $ir, $ow, $or, new TextPrompt('> '), "\r");
            $this->assertNull($final->displayWidth(), 'non-numeric COLUMNS must not parse to a bound');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    public function testColumnsWithTrailingGarbageTreatedAsUnset(): void
    {
        // The legacy (int) cast read '40x' as 40; strict parsing refuses it.
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=40x');
        try {
            [$final] = $this->drive($iw, $ir, $ow, $or, new TextPrompt('> '), "\r");
            $this->assertNull($final->displayWidth(), 'garbage-suffixed COLUMNS is unusable, not a width');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    public function testZeroColumnsTreatedAsUnsetOnNonTty(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=0');
        try {
            [$final] = $this->drive($iw, $ir, $ow, $or, new TextPrompt('> '), "\r");
            $this->assertNull($final->displayWidth(), 'COLUMNS=0 adopts nothing on a non-TTY sink');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    public function testUnsetColumnsOnNonTtySinkAdoptsNothing(): void
    {
        // The probe arm must stay shut for non-TTY resources: a socket-pair
        // output is a resource but stream_isatty() is false there.
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS');
        try {
            [$final] = $this->drive($iw, $ir, $ow, $or, new TextPrompt('> '), "\r");
            $this->assertNull($final->displayWidth(), 'no COLUMNS + non-TTY sink = render unbounded (legacy shape)');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    public function testExplicitWidthSurvivesColumnsEnv(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=40');
        try {
            [$final] = $this->drive($iw, $ir, $ow, $or, (new TextPrompt('> '))->withDisplayWidth(80), "\r");
            $this->assertSame(80, $final->displayWidth(), 'an explicit bound is never overwritten by adoption');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    /**
     * Staircase regression: with a 60-cell buffer adopted into a 40-cell
     * viewport, the painted row must carry the clip marks instead of
     * running the repaint into a wrapped, desynced frame.
     */
    public function testLongBufferRepaintIsClippedNotStaircased(): void
    {
        [$iw, $ir, $ow, $or] = $this->makePairs();
        $previous = getenv('COLUMNS');
        putenv('COLUMNS=40');
        try {
            $prompt = new TextPrompt('> ');
            foreach (str_split(str_repeat('abcdefghij', 6)) as $ch) { // 60 cells
                $prompt = $prompt->handleChar($ch);
            }
            [$final, $captured] = $this->drive($iw, $ir, $ow, $or, $prompt, "\r");
            $this->assertSame(40, $final->displayWidth());
            $this->assertStringContainsString('…', $captured, 'adopted bound must clip the over-long row (no raw 60-cell row on paint)');
            $this->assertStringNotContainsString(str_repeat('abcdefghij', 6), $captured, 'the full 60-cell run must never reach the wire');
        } finally {
            $this->restoreColumns($previous);
            foreach ([$iw, $ir, $ow, $or] as $stream) {
                @fclose($stream);
            }
        }
    }

    /**
     * The interactive case bash leaves broken: COLUMNS unset, output is a
     * real TTY. A util-linux `script` child gets a pts; stty sizes it to
     * 37 columns; run() must adopt 37 from the TIOCGWINSZ probe.
     */
    public function testTtyProbeAdoptsWinsizeColumnsWhenUnset(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('util-linux script(1) harness is Linux-shaped here');
        }
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            $this->markTestSkipped('script(1) unavailable');
        }

        $child = __DIR__ . '/Support/width_probe_child.php';
        $session = tempnam(sys_get_temp_dir(), 'srw');
        self::assertIsString($session);

        $inner = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child);
        $command = 'printf \'\\r\' | script -q -e -c ' . escapeshellarg('env -u COLUMNS ' . $inner)
            . ' ' . escapeshellarg($session) . ' >/dev/null 2>&1';

        try {
            $handle = popen($command, 'r');
            self::assertIsResource($handle);
            while (!feof($handle)) {
                fgets($handle);
            }
            pclose($handle);

            $output = (string) file_get_contents($session);

            if (str_contains($output, 'NOTTY') || str_contains($output, 'NOCOLS')) {
                $this->markTestSkipped('pts harness degraded on this box: ' . trim($output));
            }

            if (preg_match('/WIDTH=(\S+)/', $output, $m) !== 1) {
                $this->fail('probe child produced no verdict: ' . trim($output));
            }

            $this->assertSame('37', $m[1], 'unset COLUMNS on a TTY must adopt the TIOCGWINSZ columns');
        } finally {
            @unlink($session);
        }
    }

    private function restoreColumns(string|false $previous): void
    {
        if ($previous === false) {
            putenv('COLUMNS');
        } else {
            putenv('COLUMNS=' . $previous);
        }
    }
}
