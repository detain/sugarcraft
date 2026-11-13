<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * scriptui-41 (candy-scriptui-059/-108): gum's canonical install line is
 * POSITIONAL (`gum completion bash`); candy took the shell only via --shell
 * so that exact shape died on unexpected-argument rc=1. The positional now
 * wins over the flag, the flag still works, and the bare run keeps the bash
 * default — which the README now states in one sentence.
 */
final class CompletionPositionalTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/candy-scriptui';

    /** @return array{0:int,1:string,2:string} */
    private function runBin(array $args): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX bin launcher only.');
        }
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, ...$args],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    public function testPositionalShellMatchesTheFlagLegByteForByte(): void
    {
        [$rcPos, $pos, $errPos] = $this->runBin(['completion', 'zsh']);
        [$rcFlag, $flag] = $this->runBin(['completion', '--shell=zsh']);

        self::assertSame(0, $rcPos, $errPos);
        self::assertSame(0, $rcFlag);
        self::assertSame($flag, $pos, 'both spellings emit the same script');
    }

    public function testPositionalWinsOverTheFlag(): void
    {
        [$rc, $out, $err] = $this->runBin(['completion', 'fish', '--shell=bash']);
        [, $bash] = $this->runBin(['completion', '--shell=bash']);
        [, $fish] = $this->runBin(['completion', 'fish']);

        self::assertSame(0, $rc, $err);
        self::assertSame($fish, $out, 'the positional must beat the flag');
        self::assertNotSame($bash, $out);
    }

    public function testBareRunKeepsTheDocumentedBashDefault(): void
    {
        [$rc, $bare] = $this->runBin(['completion']);
        [, $flagged] = $this->runBin(['completion', '--shell=bash']);

        self::assertSame(0, $rc);
        self::assertSame($flagged, $bare);
    }

    public function testUnknownPositionalRefusesWithTheEscaperDoor(): void
    {
        [$rc, $stdout, $stderr] = $this->runBin(['completion', 'tcsh']);

        self::assertSame(1, $rc);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Allowed: bash|zsh|fish', $stderr);
        self::assertStringContainsString('Invalid value for shell:', $stderr, 'the refusal names the positional source');

        [, , $flagErr] = $this->runBin(['completion', '--shell=tcsh']);
        self::assertStringContainsString('Invalid value for --shell:', $flagErr, 'the flag leg keeps its own origin');
    }

    public function testForgedPositionalCannotForgeOutputLines(): void
    {
        $forged = "bash\ncomplete -p candy-scriptui #injected";
        [$rc, $stdout, $stderr] = $this->runBin(['completion', $forged]);

        self::assertSame(1, $rc);
        self::assertSame('', $stdout);
        self::assertStringNotContainsString('injected', $stdout . $stderr);
        self::assertLessThanOrEqual(1, substr_count(trim($stderr), "\n"), 'one refusal line only');
    }
}
