<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * Audit F-E5: {@see ProcessContainment::interactiveSpawnCommand()} kept the
 * `cd DIR && COMMAND` prefix F-E3 retired from Bash. `&&` binds tighter than
 * `;`, so with DIR gone `cd DIR && true; pwd` ran `pwd` in the PHP process's
 * own directory. The argv is run here through plain proc_open (no pty needed:
 * the defect is in the shell script, not the terminal).
 */
final class InteractiveSpawnCdGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sugarcrush_fe5_' . uniqid((string) getmypid(), true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testAVanishedCwdRunsNothingAfterASemicolon(): void
    {
        rmdir($this->dir);

        [$exit, $stdout, $stderr] = self::spawn(
            ProcessContainment::interactiveSpawnCommand('true; pwd; echo ESCAPED', $this->dir),
        );

        self::assertNotSame(0, $exit, 'a vanished cwd must fail the run');
        self::assertStringNotContainsString('ESCAPED', $stdout);
        self::assertStringNotContainsString((string) getcwd(), $stdout, 'pwd ran in the PHP process cwd');
        self::assertStringContainsString($this->dir, $stderr, "the shell's cd error names the missing directory");
    }

    public function testALiveCwdRunsEveryListElementInsideIt(): void
    {
        [$exit, $stdout] = self::spawn(
            ProcessContainment::interactiveSpawnCommand('false; pwd; echo REACHED', $this->dir),
        );

        self::assertSame(0, $exit, 'the last command decides the exit status');
        self::assertSame(realpath($this->dir) . "\nREACHED", trim($stdout));
    }

    public function testNoCwdLeavesTheCommandUnprefixed(): void
    {
        self::assertSame(['/bin/sh', '-c', 'echo hi'], ProcessContainment::interactiveSpawnCommand('echo hi'));
        self::assertSame(['/bin/sh', '-c', 'echo hi'], ProcessContainment::interactiveSpawnCommand('echo hi', ''));
    }

    /**
     * ONE spelling for both shell entry points, so they cannot drift apart
     * again the way F-E5 did after F-E3.
     */
    public function testThePrefixIsTheSharedGuardBashUsesToo(): void
    {
        $argv = ProcessContainment::interactiveSpawnCommand('echo hi', '/some dir');

        self::assertSame(ProcessContainment::cdGuard('/some dir') . 'echo hi', $argv[2]);
        self::assertSame("cd '/some dir' || exit 1\n", ProcessContainment::cdGuard('/some dir'));

        $bash = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Bash.php');
        self::assertStringContainsString('ProcessContainment::cdGuard($cwd)', $bash, 'Bash stopped using the shared guard');
        self::assertStringNotContainsString(' || exit 1\\n"', $bash, 'Bash spells its own copy of the guard again');
    }

    /**
     * @param list<string> $argv
     * @return array{int, string, string}
     */
    private static function spawn(array $argv): array
    {
        $pipes = [];
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
