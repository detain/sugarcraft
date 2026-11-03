<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Command\ConfirmCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * m-a / cluster 4 (2026-11-03): a malformed --timeout (and, for spin, a
 * malformed --align) is a refusal, never a silent coercion. The in-process
 * leg asserts the command exits FAILURE with an EMPTY display (the refusal
 * goes to STDERR before the UI is even constructed); the child legs drive
 * the real bin so the stderr wording itself is pinned.
 */
final class TimeoutRefusalTest extends TestCase
{
    private const BIN    = __DIR__ . '/../../bin/candy-scriptui';
    private const BUDGET = 20;

    public function testGarbageTimeoutRefusesWithoutPainting(): void
    {
        $tester = new CommandTester(new ConfirmCommand());

        $status = $tester->execute(['--timeout' => 'abc'], ['decorated' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('', $tester->getDisplay(),
            'a refused flag must never start the interactive UI');
    }

    /**
     * Deliberately distinct from BinExitCodeTest::runBin (DuplicatedTestHelper
     * discipline): this copy pipes /dev/null into stdin so interactive
     * commands see a clean non-tty.
     *
     * @return array{0:int,1:string,2:string}
     */
    private static function runScriptUiBinChild(array $args): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $cmd = array_merge(
            ['timeout', '-s', 'KILL', (string) self::BUDGET, PHP_BINARY, self::BIN],
            $args,
        );
        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ];
        $proc = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, \dirname(self::BIN), $env);

        self::assertIsResource($proc, 'the bin child must spawn');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    public function testBinRefusesGarbageTimeoutOnStderr(): void
    {
        [$status, $stdout, $stderr] = self::runScriptUiBinChild(['confirm', '--timeout=abc']);

        self::assertNotSame(0, $status, 'exit 0 would resurrect the swallowed-code defect');
        self::assertSame('', $stdout);
        self::assertStringContainsString('timeout', $stderr,
            'the refusal must name the flag that was refused');
    }

    public function testBinRefusesUnknownAlignWithAllowedList(): void
    {
        [$status, , $stderr] = self::runScriptUiBinChild(['spin', '--align=middle', '--timeout=0', '--', 'true']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString("Allowed: left|right", $stderr,
            'M24: an unknown-choice refusal must enumerate the legal values');
    }
}
