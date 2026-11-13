<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * m-d: `filter` failed and `table` succeeded on empty stdin, both in
 * total silence — opposite exit codes for one cause, zero explanation.
 * The exits keep their gum-compatible polarity; both now announce on
 * STDERR. m-c: a message carrying a newline forged EXTRA log lines
 * (CWE-117) in the text and logfmt sinks — real newlines fold to
 * literal \n there; the JSON leg escapes natively.
 */
final class EmptyInputAndLogForgeTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/candy-scriptui';

    public function testFilterEmptyStdinFailsWithAnExplanation(): void
    {
        [$status, $stdout, $stderr] = $this->runForgeChild(['filter']);
        self::assertNotSame(0, $status, 'filter keeps its FAILURE polarity');
        self::assertSame('', $stdout);
        self::assertStringContainsString('no lines', $stderr);
    }

    public function testTableEmptyStdinSucceedsWithAnExplanation(): void
    {
        [$status, $stdout, $stderr] = $this->runForgeChild(['table']);
        self::assertSame(0, $status, 'table keeps its SUCCESS polarity');
        self::assertSame('', $stdout);
        self::assertStringContainsString('no lines', $stderr);
    }

    public function testLogTextLegCannotForgeExtraLines(): void
    {
        $tester = new CommandTester((new Application())->find('log'));
        $status = $tester->execute(['message' => ["a\nb"]], ['decorated' => false]);
        self::assertSame(0, $status);
        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, "\n"), 'one entry stays one physical line');
        self::assertStringContainsString('a\\nb', $display);
    }

    public function testLogLogfmtLegKeepsASingleLine(): void
    {
        $tester = new CommandTester((new Application())->find('log'));
        $status = $tester->execute(
            ['message' => ["k=v\nINJECTED"], '--formatter' => 'logfmt'],
            ['decorated' => false],
        );
        self::assertSame(0, $status);
        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, "\n"));
        self::assertStringNotContainsString("INJECTED\n", $display . 'END');
        self::assertStringContainsString('INJECTED', $display, 'the bytes survive, folded');
    }

    public function testTheNotesAndFoldLiveAtTheSharedBoundaries(): void
    {
        // scriptui-53: key choice and folding are now OBSERVABLE, not
        // grepped. filter and table answer the same empty-stdin shape with
        // their own distinct documented sentences (the scriptui-33 split
        // survives: each command must carry its own wording, verbatim from
        // the catalog), and the log sinks fold CRLF into one physical line.
        [$filterStatus, $filterStdout, $filterStderr] = $this->runForgeChild(['filter']);
        self::assertSame(1, $filterStatus);
        self::assertSame('', $filterStdout);
        self::assertStringContainsString(
            'Nothing to filter: standard input produced no lines.',
            $filterStderr,
            'filter must answer with the filter-specific sentence',
        );

        [$tableStatus, $tableStdout, $tableStderr] = $this->runForgeChild(['table']);
        self::assertSame(0, $tableStatus);
        self::assertSame('', $tableStdout);
        self::assertStringContainsString(
            'Input produced no lines; nothing to do.',
            $tableStderr,
            'table must answer with the shared sentence, not filter\'s',
        );
        self::assertStringNotContainsString('Nothing to filter', $tableStderr);

        $tester = new CommandTester((new Application())->find('log'));
        $status = $tester->execute(['message' => ["a\r\nb"], '--formatter' => 'logfmt'], ['decorated' => false]);
        self::assertSame(0, $status);
        $display = $tester->getDisplay();
        self::assertSame(1, substr_count($display, "\n"), 'a CRLF message folds into one physical line');
        self::assertStringNotContainsString("\r", $display, 'the carriage return must not survive the fold');
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function runForgeChild(array $args): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX timeout wrapper required.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, self::BIN, ...$args],
            $descriptors,
            $pipes,
            dirname(self::BIN),
            ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);  // immediate EOF: the empty-stdin shape under test
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, $stdout, $stderr];
    }
}
