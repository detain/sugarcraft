<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\Terminal;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;

final class TerminalTest extends TestCase
{
    use SandboxTerminalColumns;

    /** Wall-clock budget for the pty child; a wedged probe dies rather than hangs. */
    private const WALL_CLOCK_BUDGET_SECONDS = 20;

    private const SCRIPT_BINARY = '/usr/bin/script';

    protected function setUp(): void
    {
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

    public function testNonTtyStreamWithoutExportedColumnsIsNull(): void
    {
        $stream = fopen('php://memory', 'r+');

        self::assertNull(Terminal::columns($stream));
        fclose($stream);
    }

    public function testExportedColumnsAnswerForAnyStream(): void
    {
        $this->pinTerminalColumns(132);
        $stream = fopen('php://memory', 'r+');

        self::assertSame(132, Terminal::columns($stream));
        fclose($stream);
    }

    public function testJunkColumnsIsIgnored(): void
    {
        $this->pinTerminalColumns('wide');
        $stream = fopen('php://memory', 'r+');

        self::assertNull(Terminal::columns($stream));
        fclose($stream);
    }

    public function testClosedStreamIsNull(): void
    {
        $stream = fopen('php://memory', 'r+');
        fclose($stream);

        self::assertNull(Terminal::columns($stream));
    }

    public function testNonStreamArgumentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Terminal::columns() expects a stream resource or null; got string.');

        Terminal::columns('php://stdout');
    }

    public function testTerminalStreamReportsItsWindowWidth(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable(self::SCRIPT_BINARY)) {
            self::markTestSkipped('needs util-linux script(1) to allocate a pseudo-terminal');
        }

        $probe = __DIR__ . '/Support/terminal-columns-probe.php';
        $command = 'stty cols 123 rows 40 && ' . implode(' ', array_map('escapeshellarg', [\PHP_BINARY, $probe]));
        $env = getenv();
        unset($env['COLUMNS'], $env['LINES']);

        $process = proc_open(
            [
                'timeout', '-s', 'KILL', (string) self::WALL_CLOCK_BUDGET_SECONDS,
                self::SCRIPT_BINARY, '-qec', $command, '/dev/null',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($process, 'could not start script(1)');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, "pty probe failed: {$stderr}");
        self::assertIsString($stdout);
        self::assertSame(['stdout' => 123, 'memory' => null], json_decode(trim($stdout), true), $stdout);
    }
}
