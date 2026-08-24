<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixPtyPair;

/**
 * {@see \SugarCraft\Flip\Renderer::withAdaptiveSize()} asks about STDOUT.
 *
 * ## The defect this file exists for
 *
 * The body was `SizeIoctl::query((int) STDOUT)`. An `(int)` cast of a PHP
 * stream yields its RESOURCE ID, not its descriptor. MEASURED, PHP 8.3.6,
 * three takes, identical every time: `(int) STDOUT` is **2**, and descriptor
 * 2 is STDERR. So the method sized the frame to STDERR's window while its own
 * `@throws` line promised an answer about STDOUT.
 *
 * That went unnoticed because stdout and stderr are normally the same
 * terminal, which is the same reason the rest of this cast family looked
 * fine. This one needed no unusual process state to misbehave though — only
 * `2>err.log`. MEASURED under a real tty, PHP 8.3.6, three takes, identical
 * each time: with stderr redirected to a file and stdout left on the
 * terminal, `posix_isatty(1)` is true, `posix_isatty(2)` is false, and
 * `withAdaptiveSize()` threw `RuntimeException: Cannot query size of non-tty
 * fd` — on a live terminal, with a diagnostic naming STDOUT.
 *
 * ## The fixture
 *
 * A child is spawned with descriptors 1 and 2 attached to TWO DIFFERENT
 * pseudo-terminals of DIFFERENT sizes. Both are terminals, so nothing throws
 * either way and the observation is purely which size came back: the pty on
 * descriptor 1 or the pty on descriptor 2. That is a positive discrimination
 * rather than an absence, which is what makes it immune to the shape where a
 * gutted implementation satisfies the expectation.
 *
 * The reported real-world arrangement (`2>file`) and the negative
 * (`@throws` when stdout genuinely is not a terminal) are covered by the two
 * tests after it.
 */
final class RendererAdaptiveSizeDescriptorTest extends TestCase
{
    private const PROBE = __DIR__ . '/adaptive_size_probe.php';

    /** Distinct on purpose: no dimension of one pty appears in the other. */
    private const STDOUT_COLS = 137;
    private const STDOUT_ROWS = 43;
    private const STDERR_COLS = 61;
    private const STDERR_ROWS = 19;

    /** @var list<PosixPtyPair> */
    private array $ptys = [];

    /** @var list<string> */
    private array $artifacts = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || \DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX pseudo-terminals are required.');
        }
        if (!\extension_loaded('ffi') || !\function_exists('posix_isatty')) {
            self::markTestSkipped('ext-ffi and ext-posix are required to build a pty.');
        }
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ptys as $pair) {
            $pair->master()->close();
        }
        $this->ptys = [];

        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * THE GUARD. Two terminals, two sizes, and the answer must be the one on
     * descriptor 1.
     */
    public function testTheSizeComesFromTheTerminalOnDescriptorOneAndNotDescriptorTwo(): void
    {
        $stdout = $this->pty(self::STDOUT_COLS, self::STDOUT_ROWS);
        $stderr = $this->pty(self::STDERR_COLS, self::STDERR_ROWS);

        $result = $this->runProbe([
            0 => ['pipe', 'r'],
            1 => ['file', $stdout->slave()->path(), 'w'],
            2 => ['file', $stderr->slave()->path(), 'w'],
        ]);

        // The arrangement. Both are terminals, so neither reading can be
        // excused as "the other one was not queryable".
        self::assertTrue($result['isatty_1'], 'descriptor 1 was not a terminal; the fixture cannot discriminate');
        self::assertTrue($result['isatty_2'], 'descriptor 2 was not a terminal; the fixture cannot discriminate');

        self::assertSame('ok', $result['result'], 'withAdaptiveSize() threw: ' . ($result['message'] ?? ''));
        self::assertSame(
            [self::STDOUT_ROWS, self::STDOUT_COLS],
            [$result['rows'], $result['cols']],
            'withAdaptiveSize() sized the renderer to '
                . $result['cols'] . 'x' . $result['rows'] . '; descriptor 1 is '
                . self::STDOUT_COLS . 'x' . self::STDOUT_ROWS . ' and descriptor 2 is '
                . self::STDERR_COLS . 'x' . self::STDERR_ROWS,
        );
    }

    /**
     * The reported symptom, reproduced: `php demo.php 2>err.log` on a live
     * terminal. The old body threw here; the fixed one answers.
     */
    public function testItStillAnswersWhenStderrIsRedirectedToAFile(): void
    {
        $stdout = $this->pty(self::STDOUT_COLS, self::STDOUT_ROWS);

        $errLog = tempnam(sys_get_temp_dir(), 'sc_flip_r53a_errlog_');
        self::assertIsString($errLog);
        $this->artifacts[] = $errLog;

        $result = $this->runProbe([
            0 => ['pipe', 'r'],
            1 => ['file', $stdout->slave()->path(), 'w'],
            2 => ['file', $errLog, 'w'],
        ]);

        self::assertTrue($result['isatty_1'], 'descriptor 1 was not a terminal; the fixture cannot discriminate');
        self::assertFalse($result['isatty_2'], 'descriptor 2 was a terminal; the fixture cannot discriminate');

        self::assertSame(
            'ok',
            $result['result'],
            'withAdaptiveSize() threw on a live terminal merely because stderr was redirected: '
                . ($result['message'] ?? ''),
        );
        self::assertSame([self::STDOUT_ROWS, self::STDOUT_COLS], [$result['rows'], $result['cols']]);
    }

    /**
     * The `@throws` line, pinned. Without this the two tests above would also
     * be satisfied by a body that stopped consulting the terminal at all and
     * returned a hard-coded size — the doc-block's promise is the half that
     * such a body would break.
     */
    public function testItThrowsWhenDescriptorOneIsNotATerminalEvenThoughDescriptorTwoIs(): void
    {
        $stderr = $this->pty(self::STDERR_COLS, self::STDERR_ROWS);

        $result = $this->runProbe([
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $stderr->slave()->path(), 'w'],
        ]);

        self::assertFalse($result['isatty_1'], 'descriptor 1 was a terminal; the fixture cannot discriminate');
        self::assertTrue($result['isatty_2'], 'descriptor 2 was not a terminal; the fixture cannot discriminate');

        self::assertSame(
            'throw',
            $result['result'],
            'withAdaptiveSize() answered ' . ($result['cols'] ?? '?') . 'x' . ($result['rows'] ?? '?')
                . ' in a process whose descriptor 1 is a pipe; descriptor 2 is the terminal here',
        );
        self::assertSame(\RuntimeException::class, $result['class']);
    }

    private function pty(int $cols, int $rows): PosixPtyPair
    {
        $pair = (new PosixPtySystem())->open();
        $this->ptys[] = $pair;
        $pair->master()->resize($cols, $rows);

        // Assert the arrangement at the source too: `open()` does not apply
        // its own $cols/$rows arguments (measured, PHP 8.3.6 — a freshly
        // opened pair reports 0x0), so the resize above is load-bearing and
        // a silent failure of it would make every size assertion below
        // compare 0 against 0.
        self::assertSame(
            ['rows' => $rows, 'cols' => $cols, 'xpix' => 0, 'ypix' => 0],
            $pair->master()->size(),
            'the pty did not take the size the fixture asked for',
        );

        return $pair;
    }

    /**
     * @param array<int, mixed> $spec
     * @return array<string, mixed>
     */
    private function runProbe(array $spec): array
    {
        self::assertFileExists(self::PROBE);

        $resultFile = tempnam(sys_get_temp_dir(), 'sc_flip_r53a_adaptive_');
        self::assertIsString($resultFile);
        $this->artifacts[] = $resultFile;

        $process = proc_open([\PHP_BINARY, self::PROBE, $resultFile], $spec, $pipes);
        self::assertIsResource($process, 'could not start the probe child');

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($process);
        self::assertSame(0, $exit, 'the probe child did not finish');

        $raw = file_get_contents($resultFile);
        self::assertIsString($raw);
        self::assertNotSame('', $raw, 'the probe child wrote no results');

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'the probe child wrote something that is not JSON: ' . $raw);
        self::assertSame('PROBE-RAN', $decoded['control'] ?? null, 'the probe body did not run to the end');

        return $decoded;
    }
}
