<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
use PHPUnit\Framework\TestCase;

/**
 * Keyboard-input integrity pins for the audit findings (lane A):
 * ESC-bearing sequences, C0/C1 controls and multibyte codepoints must
 * never reach the filter text, never be echoed raw to the terminal, and
 * backspace/delete must work at codepoint granularity.
 */
final class PickerInputIntegrityTest extends TestCase
{
    /**
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function makePicker(string $keys): array
    {
        $in  = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            /** @param unused */
            protected function setRawMode(bool $_on): void { /* noop in tests */ }
        };
        return [$in, $out, $p];
    }

    /**
     * @param list<Endpoint> $endpoints
     * @return array{0:resource,1:resource,2:Picker}
     */
    private function drive(string $keys, ?array $endpoints = null): array
    {
        [$in, $out, $p] = $this->makePicker($keys);
        $p->pick($endpoints ?? [
            new Endpoint(name: 'production', host: 'prod.example.com'),
            new Endpoint(name: 'staging',    host: 'stage.example.com'),
            new Endpoint(name: 'dev',        host: 'dev.example.com'),
        ]);
        return [$in, $out, $p];
    }

    private function filterOf(Picker $p): string
    {
        $prop = new \ReflectionProperty(Picker::class, 'filter');
        $prop->setAccessible(true);
        return (string) $prop->getValue($p);
    }

    /**
     * Every echoed "filter: …" line must carry exactly the two ESC bytes of
     * our own sgr(36)/reset pair — any additional ESC is a raw control leak.
     *
     * @param resource $out
     */
    private function assertFilterLinesCarryNoRawEsc($out): void
    {
        rewind($out);
        $output = (string) stream_get_contents($out);
        $lines = explode("\r\n", $output);
        $seen = 0;
        foreach ($lines as $line) {
            if (!str_starts_with($line, 'filter: ')) {
                continue;
            }
            $seen++;
            $this->assertSame(
                2,
                substr_count($line, "\x1b"),
                'filter echo line carries a raw ESC byte: ' . bin2hex($line)
            );
        }
        $this->assertGreaterThan(0, $seen, 'no filter line was ever drawn');
    }

    public function testRisSequenceIsAbsorbedAndNeverTyped(): void
    {
        // ESC c (RIS, full-terminal-reset) is a key event, not text: only
        // the following "p" may reach the filter.
        [, $out, $p] = $this->drive("\x1bcp\r");
        $this->assertSame('p', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testMetaAltChordIsAbsorbedAndNeverTyped(): void
    {
        // ESC p (Alt+p) arrives as the 2-byte chord "\x1bp" — dropped whole,
        // the 'p' inside it must not leak into the filter either.
        [, $out, $p] = $this->drive("\x1bp\x1bxc\r");
        $this->assertSame('c', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testSs3ArrowIsAbsorbedAndNeverTyped(): void
    {
        // ESC O A (application-cursor-mode up) is one key event. Before SS3
        // was parsed, the final byte 'A' leaked into the filter as text —
        // here only the deliberately-typed 'r' may survive. (-020 mapped
        // the SS3 arrows: the chord still evaporates as navigation — at
        // row 0 Up is a no-op — and never types.)
        [, $out, $p] = $this->drive("\x1bOAr\r");
        $this->assertSame('r', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    /**
     * -020 probe inverted: SS3 Down (\x1bOB) must move the cursor like its
     * CSI sibling. Pre-fix, the switch had no \x1bOB case, the chord
     * evaporated, and Enter picked row 0 while CSI-Down picked row 1.
     */
    public function testSs3DownMovesSelectionLikeCsiDown(): void
    {
        [, , $p] = $this->makePicker("\x1bOB\r");
        $picked = $p->pick($this->threeEndpoints());
        $this->assertNotNull($picked);
        $this->assertSame('stage.example.com', $picked->host, 'SS3 Down must move to row 1 before Enter');

        [, , $q] = $this->makePicker("\x1bOB\x1bOA\r");
        $up = $q->pick($this->threeEndpoints());
        $this->assertNotNull($up);
        $this->assertSame('prod.example.com', $up->host, 'SS3 Up must undo SS3 Down at row 1');
    }

    public function testUnmappedCsiIsConsumedNotTyped(): void
    {
        // ESC [ C (right arrow, unmapped) must be consumed as a key event,
        // never accumulate, and never echo raw.
        [, $out, $p] = $this->drive("\x1b[C\r");
        $this->assertSame('', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testLoneEscStillCancels(): void
    {
        // The genuine ESC keypress path must survive the sequence parser.
        [, , $p] = $this->makePicker("\x1b");
        $this->assertNull($p->pick([new Endpoint(name: 'a', host: 'a.test')]));
    }

    public function testRawAndEncodedC1ControlsAreDropped(): void
    {
        // A lone 0x9B byte (raw 8-bit CSI) is invalid UTF-8 → dropped.
        // "\xc2\x9b" (encoded U+009B) is well-formed but a control → dropped.
        // Only "prod" may type.
        [, $out, $p] = $this->drive("\x9b\xc2\x9bprod\r");
        $this->assertSame('prod', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testBackspaceDeletesWholeMultibyteCodepoint(): void
    {
        // 日 = three bytes. One backspace must clear the whole codepoint,
        // and no intermediate frame may echo invalid UTF-8.
        $endpoints = [
            new Endpoint(name: '日本語', host: 'jp.example.com'),
            new Endpoint(name: 'prod',  host: 'prod.example.com'),
        ];
        [, $out, $p] = $this->drive("\xe6\x97\xa5\x7fprod\r", $endpoints);
        // After 日 + one backspace the filter is empty, then "prod" types
        // clean — reaching filter 'prod' proves the CJK char was cleared in
        // a SINGLE press (byte-wise trim would need three and strand junk).
        $this->assertSame('prod', $this->filterOf($p));
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testMultibyteBackspaceNeverEmitsInvalidUtf8(): void
    {
        [, $out, $p] = $this->drive("\xe6\x97\xa5\x7f\r");
        rewind($out);
        $output = (string) stream_get_contents($out);
        // Whole stream must decode as UTF-8: before the fix the frame
        // between lead bytes (and after one byte-wise backspace) echoed
        // "\xe6\x97" — invalid UTF-8.
        $this->assertSame(1, preg_match('//u', $output), 'output stream is not valid UTF-8');
        $this->assertSame('', $this->filterOf($p), 'one backspace must clear one whole codepoint');
        $this->assertFilterLinesCarryNoRawEsc($out);
    }

    public function testFilterEchoIsSanitizedAtRenderAsBeltAndBraces(): void
    {
        // Even if a hostile string were placed in the filter by any future
        // path, draw() must not echo its control bytes.
        [, $out, $p] = $this->makePicker('');
        $filter = new \ReflectionProperty(Picker::class, 'filter');
        $filter->setAccessible(true);
        $filter->setValue($p, "\x1b[2Jevil\x07");

        $draw = new \ReflectionMethod(Picker::class, 'draw');
        $draw->setAccessible(true);
        $draw->invoke($p, []);

        rewind($out);
        $output = (string) stream_get_contents($out);
        $this->assertStringNotContainsString("\x1b[2J", $output);
        $this->assertStringNotContainsString("\x07", $output);
        $this->assertStringContainsString('filter: ' . "\x1b[36m" . 'evil' . "\x1b[0m", $output);
    }

    public function testHighlightWalksCodepointsNotGraphemes(): void
    {
        // "é" written as e + U+0301 is TWO codepoints but ONE grapheme.
        // sugar-fuzzy indexes the haystack by codepoint, so the match on 'f'
        // arrives as index 2 — the grapheme walk used to wrap index 2's
        // WRONG cluster (none) and desync every later highlight.
        [$in, $out, $p] = $this->makePicker('');
        $haystack = "e\u{0301}f";
        $result = new MatchResult(
            needle: 'f',
            haystack: $haystack,
            score: 5,
            matchedIndices: [2],
        );
        $hl = new \ReflectionMethod(Picker::class, 'highlightLine');
        $hl->setAccessible(true);
        $rendered = (string) $hl->invoke($p, $haystack, $result);
        $this->assertStringContainsString("\x1b[1;36mf\x1b[0m", $rendered);
        // The combining sequence stays unhighlighted and intact.
        $this->assertStringStartsWith("e\u{0301}", $rendered);
    }

    public function testLettersAreQueryFromTheFirstKeystroke(): void
    {
        // -060 invert model: j/k never navigate — every letter, from the
        // first keystroke on, belongs to the query.
        [, , $p] = $this->drive('proj');
        $this->assertSame('proj', $this->filterOf($p));

        [, , $q] = $this->drive('jj');
        $this->assertSame('jj', $this->filterOf($q), 'j/k are letters on an empty filter too');
    }

    public function testCtrlUClearsTheFilter(): void
    {
        // -064: a mistyped query is one chord away from clean, not a
        // backspace marathon.
        [, , $p] = $this->drive("stag\x15prod\r");
        $this->assertSame('prod', $this->filterOf($p));
    }

    public function testCtrlWStripsOneTrailingWord(): void
    {
        // -064: 'foo bar baz' + two Ctrl+W lands on 'foo'; trailing spaces
        // ride out with the word they follow.
        [, , $p] = $this->drive("foo bar baz\x17\x17j\x7f\r");
        $this->assertSame('foo', $this->filterOf($p));
    }

    /**
     * -062: pick() re-enters clean. Call pick() twice on the SAME picker
     * with a typed filter left over from the first session and prove the
     * second call starts empty (the historical one-shot leak: filter and
     * cursor persisted across calls).
     */
    public function testPickResetsFilterAndCursorAcrossCalls(): void
    {
        [$in, , $p] = $this->makePicker('stag');
        // First session: type 'stag', EOF maps to the cancel byte, pick()
        // returns null with the query still in place.
        $this->assertNull($p->pick($this->threeEndpoints()));
        $this->assertSame('stag', $this->filterOf($p));

        // Second session on the SAME object: a bare Enter must pick row 0
        // fresh — not the filtered match, not a stale cursor.
        rewind($in);
        fwrite($in, "\r");
        rewind($in);
        $picked = $p->pick($this->threeEndpoints());
        $this->assertNotNull($picked);
        $this->assertSame('production', $picked->name, 'a reused picker must start from an empty filter');
    }

    public function testNoMatchesLineCarriesTheRescueHint(): void
    {
        // -063: the dead end now names its two exits.
        [, $out, $p] = $this->drive('zzzz' . "\x1b", $this->threeEndpoints());
        rewind($out);
        $output = (string) stream_get_contents($out);
        $this->assertStringContainsString('no matches', $output);
        $this->assertStringContainsString('backspace or Esc', $output);
    }

    public function testSigintRestoresTheTerminalAndExits130(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('the SIGINT arm needs ext-pcntl and ext-posix');
        }

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertIsString($autoload);

        $child = <<<'PHP'
<?php
declare(strict_types=1);
require '__AUTOLOAD__';
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
$c = new class (STDIN, STDOUT) extends Picker {
    public function setRawMode(bool $on): void
    {
        fwrite(STDOUT, ($on ? 'RAW:ON' : 'RAW:OFF') . "\n");
    }
};
$c->pick([new Endpoint(name: 'a', host: 'a.test')]);
fwrite(STDOUT, "RETURNED\n");
PHP;
        $child = str_replace('__AUTOLOAD__', $autoload, $child);
        $script = tempnam(sys_get_temp_dir(), 'sshlaunch-sigint-') . '.php';
        file_put_contents($script, $child);

        $proc = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()],
        );
        $this->assertIsResource($proc);

        try {
            // Feed one letter so the picker is mid-loop (blocking fread),
            // then wait until the child announced raw mode — the SIGINT arm
            // lives inside pick(), so killing before this marker proves
            // nothing.
            fwrite($pipes[0], 'a');
            fflush($pipes[0]);

            $seen = '';
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 5.0;
            while (!str_contains($seen, 'RAW:ON') && microtime(true) < $deadline) {
                $chunk = fread($pipes[1], 64);
                if ($chunk === false || $chunk === '') {
                    usleep(20000);
                    continue;
                }
                $seen .= $chunk;
            }
            $this->assertStringContainsString('RAW:ON', $seen, 'child never entered raw mode');

            $pid = proc_get_status($proc)['pid'];
            $this->assertTrue(posix_kill($pid, \SIGINT));

            // Reap-first: close stdin, drain both pipes to EOF, then close.
            fclose($pipes[0]);
            $pipes[0] = null;
            stream_set_blocking($pipes[1], true);
            $rest = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $pipes[1] = null;
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $pipes[2] = null;
            $code = proc_close($proc);
            $proc = null;

            $this->assertSame(130, $code, 'stdout: ' . $seen . $rest . ' stderr: ' . $err);
            $this->assertStringContainsString('RAW:OFF', $seen . $rest, 'SIGINT path restored the terminal');
            $this->assertStringNotContainsString('RETURNED', $seen . $rest, 'the handler exits; pick() never returns');
            $this->assertStringContainsString(
                \SugarCraft\SshLauncher\Lang::t('cli.cancelled'),
                $err,
            );
        } finally {
            foreach ((array) $pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if ($proc !== null) {
                proc_terminate($proc);
                proc_close($proc);
            }
            @unlink($script);
        }
    }

    public function testPagingKeysNeverEnterTheFilter(): void
    {
        // F-25 belt: Home/End are navigation, not intake (they were dropped
        // before, so this stays green across the change by design).
        [, , $p] = $this->drive("\x1b[H\x1b[F");
        $this->assertSame('', $this->filterOf($p));
    }

    /**
     * The -001 critical regression: SIGINT must break a genuinely blocked
     * read — with stdin OPEN and no byte ever typed — restoring the
     * terminal and exiting 130. The historical SIGINT test closed stdin
     * right after the kill, so it only proved the deferred EOF→cancel
     * mapping, never signal interruptibility. Also pins -018's order: by
     * the moment setRawMode(true) runs, the SIGINT handler must already be
     * armed (child reports pcntl_signal_get_handler at raw-entry).
     */
    public function testSigintInterruptsABlockedReadWithStdinStillOpen(): void
    {
        if (
            !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_dispatch') || !function_exists('pcntl_signal_get_handler')
            || !function_exists('posix_kill')
        ) {
            $this->markTestSkipped('the SIGINT interruptibility pin needs ext-pcntl and ext-posix');
        }

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertIsString($autoload);

        $child = <<<'PHP'
<?php
declare(strict_types=1);
require '__AUTOLOAD__';
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
$c = new class (STDIN, STDOUT) extends Picker {
    public function setRawMode(bool $on): void
    {
        if ($on) {
            // -018 order probe: raw entry must happen AFTER the arm.
            fwrite(STDOUT, 'ARM:' . (pcntl_signal_get_handler(\SIGINT) === \SIG_DFL ? 'DFL' : 'HANDLED') . "\n");
        }
        fwrite(STDOUT, ($on ? 'RAW:ON' : 'RAW:OFF') . "\n");
    }
};
$c->pick([new Endpoint(name: 'a', host: 'a.test')]);
fwrite(STDOUT, "RETURNED\n");
PHP;
        $child = str_replace('__AUTOLOAD__', $autoload, $child);
        $script = tempnam(sys_get_temp_dir(), 'sshlaunch-sigint-block-') . '.php';
        file_put_contents($script, $child);

        $proc = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()],
        );
        $this->assertIsResource($proc);

        try {
            // Wait for the child to enter raw mode WITHOUT typing anything:
            // it is now blocked inside readKey's first leg.
            $seen = '';
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 5.0;
            while (!str_contains($seen, 'RAW:ON') && microtime(true) < $deadline) {
                $chunk = fread($pipes[1], 128);
                if ($chunk === false || $chunk === '') {
                    usleep(20000);
                    continue;
                }
                $seen .= $chunk;
            }
            $this->assertStringContainsString('RAW:ON', $seen, 'child never entered raw mode');
            $this->assertStringContainsString(
                'ARM:HANDLED',
                $seen,
                '-018: SIGINT handler must be armed before setRawMode(true)',
            );

            $pid = proc_get_status($proc)['pid'];
            $this->assertTrue(posix_kill($pid, \SIGINT));

            // The decisive leg: the child must exit WHILE stdin is still
            // open. The pre-fix bare fread kept the process wedged here
            // (QA's 20 s watch → SIGKILL).
            $exited = false;
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($proc)['running']) {
                    $exited = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue(
                $exited,
                '-001: SIGINT did not interrupt the blocked read while stdin stayed open',
            );

            // Now release stdin and reap; drain the announced restore.
            fclose($pipes[0]);
            $pipes[0] = null;
            stream_set_blocking($pipes[1], true);
            $rest = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $pipes[1] = null;
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $pipes[2] = null;
            $code = proc_close($proc);
            $proc = null;

            $this->assertSame(130, $code, 'stdout: ' . $seen . $rest . ' stderr: ' . $err);
            $this->assertStringContainsString('RAW:OFF', $seen . $rest, 'SIGINT path restored the terminal');
            $this->assertStringNotContainsString('RETURNED', $seen . $rest, 'the handler exits; pick() never returns');
            $this->assertStringContainsString(
                \SugarCraft\SshLauncher\Lang::t('cli.cancelled'),
                $err,
            );
        } finally {
            foreach ((array) $pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if ($proc !== null) {
                proc_terminate($proc);
                proc_close($proc);
            }
            @unlink($script);
        }
    }

    /**
     * -010 unit arm: arming covers the full four-signal set (SIGINT plus
     * SIGTERM/SIGQUIT/SIGHUP) and disarming returns every one of them to
     * SIG_DFL — a leaked restore handler outside pick() would silently
     * convert a later plain `kill` into a frame-erasing exit.
     */
    public function testSignalArmingCoversAndDisarmsAllFourTerminalSignals(): void
    {
        if (
            !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('posix_kill') || !function_exists('posix_getpid')
        ) {
            $this->markTestSkipped('the armed-set pin needs ext-pcntl and ext-posix');
        }

        [, , $p] = $this->makePicker('');
        $arm = new \ReflectionMethod(Picker::class, 'armCancelSignal');
        $arm->setAccessible(true);
        $disarm = new \ReflectionMethod(Picker::class, 'disarmCancelSignal');
        $disarm->setAccessible(true);

        $signals = [\SIGINT, \SIGTERM, \SIGQUIT, \SIGHUP];
        try {
            $arm->invoke($p);
            foreach ($signals as $sig) {
                $this->assertIsNotInt(
                    pcntl_signal_get_handler($sig),
                    'signal ' . $sig . ' must be a picker handler while armed',
                );
            }
        } finally {
            $disarm->invoke($p);
        }
        foreach ($signals as $sig) {
            $this->assertSame(
                \SIG_DFL,
                pcntl_signal_get_handler($sig),
                'signal ' . $sig . ' must return to SIG_DFL after disarm',
            );
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function terminatingSignalProvider(): array
    {
        return [
            'SIGTERM' => [\SIGTERM],
            'SIGQUIT' => [\SIGQUIT],
            'SIGHUP'  => [\SIGHUP],
        ];
    }

    /**
     * -010 subprocess arm: killing a blocked picker mid-pick with a
     * default-disposition terminating signal must restore the terminal
     * (the pick() finally never runs for these kills) while keeping the
     * signal-death exit status — the re-raise, not the handler exit, is
     * what the shell sees. Pre-fix empirical: rc 15-ish with a RAW-STRANDED
     * tty and no restore bytes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('terminatingSignalProvider')]
    public function testTerminatingSignalsRestoreTheTerminalBeforeDying(int $sig): void
    {
        if (
            !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')
            || !function_exists('posix_kill')
        ) {
            $this->markTestSkipped('the terminating-signal pin needs ext-pcntl and ext-posix');
        }

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertIsString($autoload);

        $child = <<<'PHP'
<?php
declare(strict_types=1);
require '__AUTOLOAD__';
use SugarCraft\SshLauncher\Endpoint;
use SugarCraft\SshLauncher\Picker;
if (function_exists('posix_setrlimit')) {
    // SIGQUIT's default disposition dumps core; keep the suite's /proc
    // tidy and the death cheap.
    @posix_setrlimit(\POSIX_RLIMIT_CORE, 0, 0);
}
$c = new class (STDIN, STDOUT) extends Picker {
    public function setRawMode(bool $on): void
    {
        fwrite(STDOUT, ($on ? 'RAW:ON' : 'RAW:OFF') . "\n");
    }
};
$c->pick([new Endpoint(name: 'a', host: 'a.test')]);
fwrite(STDOUT, "RETURNED\n");
PHP;
        $child = str_replace('__AUTOLOAD__', $autoload, $child);
        $script = tempnam(sys_get_temp_dir(), 'sshlaunch-term-') . '.php';
        file_put_contents($script, $child);

        $proc = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            ['PATH' => '/usr/bin:/bin', 'HOME' => \sys_get_temp_dir()],
        );
        $this->assertIsResource($proc);

        try {
            $seen = '';
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 5.0;
            while (!str_contains($seen, 'RAW:ON') && microtime(true) < $deadline) {
                $chunk = fread($pipes[1], 128);
                if ($chunk === false || $chunk === '') {
                    usleep(20000);
                    continue;
                }
                $seen .= $chunk;
            }
            $this->assertStringContainsString('RAW:ON', $seen, 'child never entered raw mode');

            $pid = proc_get_status($proc)['pid'];
            $this->assertTrue(posix_kill($pid, $sig));

            // Deterministic reap shape: do NOT poll proc_get_status after
            // the kill (that path caches the reap and proc_close then
            // reports a flat -1 for any signal death). Closing stdin
            // lets the child either take the signal through the restore
            // + re-raise (the arm under test) or wake into the EOF cancel
            // path — both end in a signal death here — and the EOF drain
            // keeps proc_close on the raw-WTERMSIG convention.
            fclose($pipes[0]);
            $pipes[0] = null;
            stream_set_blocking($pipes[1], true);
            $rest = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $pipes[1] = null;
            fclose($pipes[2]);
            $pipes[2] = null;
            $code = proc_close($proc);
            $proc = null;

            // PHP reports signal death through this drain path as the raw
            // wait status: low bits are the termination signal, bit 0x80
            // is the core-dump flag (set for SIGQUIT's disposition). The
            // handler must re-raise, never exit() the death away — an
            // exit($sig) would land as $sig<<8 and fail this mask.
            $this->assertSame($sig, $code & 0x7f, 'stdout: ' . $seen . $rest);
            $this->assertStringContainsString(
                'RAW:OFF',
                $seen . $rest,
                'signal ' . $sig . ' path restored the terminal (pre-fix: RAW-STRANDED)',
            );
            $this->assertStringNotContainsString('RETURNED', $seen . $rest, 'the process dies; pick() never returns');
        } finally {
            foreach ((array) $pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if ($proc !== null) {
                proc_terminate($proc);
                proc_close($proc);
            }
            @unlink($script);
        }
    }

    /**
     * Real-stream picker seam (-017/-097): the memory streams every other
     * leg uses bypass stream_select entirely, so the burst window never
     * executed in-suite. A socketpair input exercises the select branch
     * deterministically — pre-buffered bursts fire with zero sleeps, and
     * fork-paced writes probe the window's width itself.
     *
     * @return array{0:Picker,1:resource,2:resource}
     */
    private function makeSocketPicker(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($out);
        $p = new class ($pair[0], $out) extends Picker {
            /** @param unused */
            protected function setRawMode(bool $_on): void { /* noop in tests */ }
        };
        return [$p, $pair[0], $pair[1]];
    }

    /**
     * @return list<Endpoint>
     */
    private function threeEndpoints(): array
    {
        return [
            new Endpoint(name: 'production', host: 'prod.example.com'),
            new Endpoint(name: 'staging',    host: 'stage.example.com'),
            new Endpoint(name: 'dev',        host: 'dev.example.com'),
        ];
    }

    /**
     * -097 execution pin: a pre-buffered `ESC [ A CR` burst on a REAL
     * stream must wake the select branch immediately (no sleeps), decode
     * as Down, and Enter-pick row 1 — never type the orphaned letters.
     */
    public function testRealStreamBurstDecodesWithoutSleeps(): void
    {
        [$p, $reader, $writer] = $this->makeSocketPicker();
        try {
            $this->assertGreaterThan(0, fwrite($writer, "\x1b[B\r"));
            $picked = $p->pick($this->threeEndpoints());
            $this->assertNotNull($picked, 'pre-buffered burst was not promoted to Down+Enter');
            $this->assertSame('stage.example.com', $picked->host, 'Down must land on row 1 before Enter');
        } finally {
            fclose($reader);
            fclose($writer);
        }
    }

    /**
     * -017 width pin: an `ESC [ B` (Down) burst fragmented by 120 ms sits inside
     * the 150 ms follow-up window, so it still promotes to Down. Restoring
     * the old 50 ms window reddens this leg (the lone-ESC cancel path
     * returns null and the `[A` orphan never moves the cursor).
     */
    public function testFragmentedBurstWithinWindowPromotesToDown(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('the fragmented-burst pin needs pcntl_fork');
        }
        [$p, $reader, $writer] = $this->makeSocketPicker();
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($reader);
            fwrite($writer, "\x1b");
            usleep(120_000);
            fwrite($writer, "[B\r");
            fclose($writer);
            exit(0);
        }
        try {
            fclose($writer);
            $picked = $p->pick($this->threeEndpoints());
            $this->assertNotNull($picked, 'fragmented burst must promote within the window, not cancel');
            $this->assertSame('stage.example.com', $picked->host);
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($reader);
        }
    }

    /**
     * ESCDELAY arm (-017's named escape hatch): the same fragmented burst
     * must fall out of a deliberately narrowed 40 ms window — the lone-ESC
     * cancel fires (pick returns null, the picker never dispatches).
     */
    public function testEscDelayNarrowsTheWindow(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('the ESCDELAY pin needs pcntl_fork');
        }
        [$p, $reader, $writer] = $this->makeSocketPicker();
        $before = getenv('ESCDELAY');
        putenv('ESCDELAY=40');
        try {
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                fclose($reader);
                fwrite($writer, "\x1b");
                usleep(120_000);
                fwrite($writer, "[B\r");
                fclose($writer);
                exit(0);
            }
            fclose($writer);
            $this->assertNull($p->pick($this->threeEndpoints()), 'ESCDELAY=40 must expire the 120 ms gap');
            pcntl_waitpid($pid, $status);
        } finally {
            putenv($before === false ? 'ESCDELAY' : 'ESCDELAY=' . $before);
            fclose($reader);
        }
    }

    /**
     * -097 expiry leg on a real stream: ESC with the peer kept open and no
     * follow-on bytes is the genuine Esc keypress — the picker cancels
     * (returns null) after the window instead of hanging.
     */
    public function testLoneEscOnOpenRealStreamExpiresToCancel(): void
    {
        [$p, $reader, $writer] = $this->makeSocketPicker();
        try {
            $this->assertGreaterThan(0, fwrite($writer, "\x1b"));
            $this->assertNull($p->pick($this->threeEndpoints()));
        } finally {
            fclose($reader);
            fclose($writer);
        }
    }
}
