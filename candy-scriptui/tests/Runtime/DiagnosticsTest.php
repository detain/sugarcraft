<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Pins for the diagnostics funnel (finding candy-scriptui-100): bytes reach
 * the error stream verbatim, the data stream stays clean, and nothing on
 * the path depends on the CLI-only STDOUT/STDERR constants — under an
 * embedded SAPI the old constant sites fatal at resolution before a single
 * diagnostic byte is written.
 */
final class DiagnosticsTest extends TestCase
{
    private const BUDGET = '20';

    /** @return array{stdout:string,stderr:string} */
    private function runChild(string $scriptBody): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // F13-W (CI-watch 2026-11-13): the wall-clock bound uses coreutils
            // timeout(1); the Windows runner's own timeout.exe speaks a
            // different grammar and the probe child exits 1 instead of running.
            // Skip rather than risk an unbounded child — same gate as
            // BinExitCodeTest's bounded child probes.
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }

        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($autoload, 'the suite must run against the library autoloader');

        // Tempdir-native join (F13-W): sys_get_temp_dir() answers 'C:\...\Temp'
        // on Windows, where a hand-typed '/' mixed separators into the
        // proc_open argv. DIRECTORY_SEPARATOR is '/' on POSIX — byte-identical.
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . sprintf('scriptui-diagnostics-%s.php', bin2hex(random_bytes(6)));
        file_put_contents($script, "<?php\nrequire '" . $autoload . "';\n" . $scriptBody);

        try {
            $proc = proc_open(
                ['timeout', '-s', 'KILL', self::BUDGET, PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($proc);

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), 'the probe child must exit cleanly');
        } finally {
            @unlink($script);
        }

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testEmitWritesBytesVerbatimAndLeavesTheDataStreamClean(): void
    {
        $result = $this->runChild(
            "\\SugarCraft\\ScriptUi\\Runtime\\Diagnostics::emit(\"alpha\\nbeta\");\necho 'OUT';\n"
        );

        // No newline added, none stripped: the child-stderr passthrough in
        // `spin` depends on byte-exact writing.
        $this->assertSame("alpha\nbeta", $result['stderr']);
        $this->assertSame('OUT', $result['stdout']);
    }

    public function testRepeatedEmitsPreserveTheirOwnByteStreams(): void
    {
        $result = $this->runChild(
            "\\SugarCraft\\ScriptUi\\Runtime\\Diagnostics::emit(\"one\\n\");\n"
            . "\\SugarCraft\\ScriptUi\\Runtime\\Diagnostics::emit(\"\\n\");\n"
            . "\\SugarCraft\\ScriptUi\\Runtime\\Diagnostics::emit(\"two\");\n"
        );

        // Callers own their line endings; the funnel concatenates untouched.
        $this->assertSame("one\n\ntwo", $result['stderr']);
    }
}
