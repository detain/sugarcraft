<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use SugarCraft\Pty\PtyException;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Process\RealProcess;
use PHPUnit\Framework\TestCase;

/**
 * scriptui-33 (candy-scriptui-051): a missing child command must surface as
 * ONE plain localized sentence naming the command — never a Symfony
 * exception box leaking `In PosixProcess.php line 92:` plus the option
 * synopsis, against the README's plain-sentences contract.
 */
final class SpawnFailurePlainTest extends TestCase
{
    private const MISSING = 'scriptui-no-such-binary-xyz';
    private const BIN     = __DIR__ . '/../../bin/candy-scriptui';

    public function testSpawnOfMissingBinaryThrowsPlainLocalizedSentence(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX missing-binary leg; Windows has its own refusal arm.');
        }

        try {
            RealProcess::spawn([self::MISSING]);
            self::fail('spawn of a missing binary must throw');
        } catch (PtyException $e) {
            $message = $e->getMessage();
            self::assertSame(
                Lang::t('process.spawn_failed') . ': ' . self::MISSING,
                $message,
                'plain localized sentence naming the attempted command',
            );
            self::assertStringNotContainsString("\n", $message, 'one line only');
            self::assertStringNotContainsString('In ', $message);
            self::assertStringNotContainsString('setsid', $message, 'the detach wrapper must stay invisible');
        }
    }

    public function testBinSpinReportsMissingChildAsSingleLine(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX missing-binary leg; Windows has its own refusal arm.');
        }

        [$rc, $stdout, $stderr] = $this->runBin(['spin', '--', self::MISSING]);

        $en = require __DIR__ . '/../../lang/en.php';
        self::assertSame(1, $rc);
        self::assertSame($en['process.spawn_failed'] . ': ' . self::MISSING . "\n", $stderr);
        self::assertSame('', $stdout);
        self::assertStringNotContainsString('line 9', $stderr);
        self::assertStringNotContainsString('PosixProcess', $stderr);
    }

    public function testBinFilterRefusalNamesTheFilterNotTheInputSibling(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX empty-stdin leg uses /dev/null.');
        }

        [$rc, $stdout, $stderr] = $this->runBin(['filter'], '/dev/null');

        $en = require __DIR__ . '/../../lang/en.php';
        self::assertSame(1, $rc);
        self::assertSame($en['runtime.empty_input_filter'] . "\n", $stderr);
        self::assertStringNotContainsString('nothing to do', $stderr, 'sibling copy must not leak into filter');
        self::assertStringNotContainsString('In ', $stderr);
        self::assertSame('', $stdout);
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function runBin(array $args, ?string $stdinFrom = null): array
    {
        $descriptors = [
            0 => ['file', $stdinFrom ?? '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @\proc_open(
            [PHP_BINARY, self::BIN, ...$args],
            $descriptors,
            $pipes,
            null,
            ['LANG' => 'C', 'LC_ALL' => 'C', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        self::assertIsResource($process, 'the bin child must launch');

        $stdout = self::drain($pipes[1], $process);
        $stderr = self::drain($pipes[2], $process);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $rc = \proc_close($process);

        return [$rc, $stdout, $stderr];
    }

    /**
     * Read a pipe to EOF under a wall-clock budget; a refusal arm that hangs
     * is a red test, never a green suite waiting forever.
     *
     * @param resource $pipe
     */
    private static function drain($pipe, $process): string
    {
        \stream_set_blocking($pipe, false);
        $deadline = \microtime(true) + 30.0;
        $buffer = '';
        while (true) {
            $chunk = \fread($pipe, 8192);
            if ($chunk === '' || $chunk === false) {
                $status = \proc_get_status($process);
                if (!$status['running']) {
                    return $buffer;
                }
                if (\microtime(true) > $deadline) {
                    \proc_terminate($process, 9);
                    self::fail('bin child exceeded the 30s budget while draining');
                }
                \usleep(2000);
                continue;
            }
            $buffer .= $chunk;
        }
    }
}
