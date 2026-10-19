<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Format\FormatReport;
use SugarCraft\Code\Format\FormatRunner;

/**
 * EDT-1: `FormatRunner` is `LintRunner`'s byte-twin for the map, the
 * placeholder and the bounds — plus the mutate guard, because this command
 * WRITES. The guard half is the interesting surface: kept rewrites, restored
 * corruptions (removed / emptied / failed-after-change / overrun mid-rewrite),
 * the size wall that refuses to run at all, and the jail.
 */
final class FormatRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc-format-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
    }

    // ── the map, tolerant exactly like the lint's ──────────────────────

    public function testTheMapStartsEmptySoNothingFormatsUntilConfigured(): void
    {
        $runner = FormatRunner::new();

        $this->assertSame([], $runner->commands());
        $this->assertNull($runner->commandFor('src/a.php'));
        $this->assertSame(FormatRunner::DEFAULT_TIMEOUT_SECONDS, $runner->timeoutSeconds());
    }

    public function testUnusableMapShapesAreIgnoredNotRefused(): void
    {
        $runner = FormatRunner::new();

        foreach ([null, 'string', 42, ['a list', 'of strings']] as $raw) {
            $this->assertSame([], $runner->withCommands($raw)->commands(), gettype($raw));
        }

        // The empty map is a legal (no-op) layer-over.
        $this->assertSame([], $runner->withCommands([])->commands());
    }

    public function testTheMapKeyIsTrimmedLowerCasedAndDotStripped(): void
    {
        $runner = FormatRunner::new()->withCommands([
            ' .PHP ' => 'one',
            'ts' => 'two',
            '' => 'no key',
            'js' => '  spaced command  ',
        ]);

        $this->assertSame(['php' => 'one', 'ts' => 'two', 'js' => 'spaced command'], $runner->commands());
        $this->assertSame('one', $runner->commandFor('src/A.PHP'));
        $this->assertNull($runner->commandFor('Makefile'));
    }

    public function testFalseNullOrBlankSwitchAnExtensionOff(): void
    {
        $runner = FormatRunner::new()
            ->withCommands(['php' => 'fixer', 'ts' => 'prettier', 'py' => 'black']);

        $runner = $runner->withCommands(['php' => false, 'ts' => null, 'py' => '   ']);

        $this->assertSame([], $runner->commands());
    }

    public function testWithTimeoutFloorsAndRejectsNonsense(): void
    {
        $this->assertSame(0.01, FormatRunner::new()->withTimeout(0.0)->timeoutSeconds());
        $this->assertSame(5.0, FormatRunner::new()->withTimeout(5.0)->timeoutSeconds());
        $this->assertSame(
            FormatRunner::DEFAULT_TIMEOUT_SECONDS,
            FormatRunner::new()->withTimeout(INF)->timeoutSeconds(),
        );
    }

    // ── format(): when nothing runs ────────────────────────────────────

    public function testNothingRunsWhenThereIsNoCommandNoFileOrNoRoot(): void
    {
        $this->put('src/a.php', "<?php\n\$x = 1;\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> {file}']);

        $this->assertNull($formatter->format('src/a.txt', $this->root), 'no command for the extension');
        $this->assertNull($formatter->format('src/Missing.php', $this->root), 'no file');
        $this->assertNull($formatter->format('src/a.php', ''), 'no root');
    }

    public function testTheJailHoldsTheFormatterExactlyAsItHeldTheEdit(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/Secret.php', "<?php\n\$x = 1;\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> {file}']);

        try {
            foreach ([$outside . '/Secret.php', '../Secret.php'] as $path) {
                $this->assertNull($formatter->format($path, $this->root . '/src'), $path);
                $this->assertSame("<?php\n\$x = 1;\n", file_get_contents($outside . '/Secret.php'), "{$path} must not be written");
            }
        } finally {
            @unlink($outside . '/Secret.php');
            @rmdir($outside);
        }
    }

    public function testAFilePastTheSizeWallIsNotFormattedAtAll(): void
    {
        // The guard holds the pre-run bytes in memory; past the wall it cannot
        // promise an un-run, so the formatter does not run at all.
        $this->put('src/big.php', str_repeat('a', FormatRunner::MAX_SOURCE_BYTES + 1));
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> {file}']);

        $this->assertNull($formatter->format('src/big.php', $this->root));
        $this->assertSame(FormatRunner::MAX_SOURCE_BYTES + 1, filesize($this->root . '/src/big.php'));
    }

    // ── the mutate guard ───────────────────────────────────────────────

    public function testAWholeSuccessfulRewriteStands(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf "two\\n" >> {file}']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertTrue($report->changed);
        $this->assertNull($report->restored);
        $this->assertTrue($report->passed());
        $this->assertSame("one\ntwo\n", file_get_contents($this->root . '/src/a.php'));
        $this->assertSame('src/a.php', $report->file);
        $this->assertSame($this->root . '/src/a.php', $report->absolute);
    }

    public function testAFormatterThatLeavesTheBytesAloneIsSilent(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'cat {file} > /dev/null']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertFalse($report->changed);
        $this->assertNull($report->restored);
        $this->assertFalse($report->needsNote());
        $this->assertSame('', $report->render());
    }

    public function testAFormatterThatRemovesTheFileIsUndone(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'rm {file}']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertSame('the command removed the file', $report->restored);
        $this->assertFalse($report->changed);
        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'), 'the bytes came back');
        $this->assertStringContainsString('was put back unchanged', $report->render());
    }

    public function testAFormatterThatEmptiesTheFileIsUndoneEvenOnExitZero(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => ': > {file}']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertSame('the command emptied the file', $report->restored);
        $this->assertTrue($report->passed(), 'the run itself succeeded — the guard is about the bytes');
        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'));
    }

    public function testAFailedRunThatAlreadyRewroteTheFileIsUndone(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> {file}; exit 3']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertSame('the command exited 3 after already rewriting the file', $report->restored);
        $this->assertFalse($report->passed());
        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'));
    }

    public function testAFailedRunThatLeftTheBytesAloneNeedsNoRestore(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'exit 3']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertNull($report->restored);
        $this->assertFalse($report->changed);
        $this->assertTrue($report->needsNote());
        $this->assertStringContainsString('did not run cleanly', $report->render());
        $this->assertStringContainsString('left as the edit wrote it', $report->render());
    }

    public function testARewriteOverrunningTheWallIsUndone(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()
            ->withCommands(['php' => 'printf X >> {file}; sleep 5'])
            ->withTimeout(0.2);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertTrue($report->timedOut);
        $this->assertNotNull($report->restored);
        $this->assertStringContainsString('mid-rewrite', $report->restored);
        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'));
    }

    public function testARewritePastTheSizeWallIsUndone(): void
    {
        $this->put('src/a.php', "one\n");
        $grow = FormatRunner::MAX_SOURCE_BYTES + 1;
        $formatter = FormatRunner::new()->withCommands([
            'php' => 'head -c ' . $grow . ' /dev/zero | tr "\\0" "b" >> {file}',
        ]);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertSame('the rewrite grew the file past the format size wall', $report->restored);
        $this->assertSame("one\n", file_get_contents($this->root . '/src/a.php'));
    }

    // ── the command line ───────────────────────────────────────────────

    public function testTheFileGoesLastQuotedOrAtThePlaceholder(): void
    {
        $this->put('src/a.php', "one\n");
        $report = FormatRunner::new()->withCommands(['php' => 'printf X >>'])
            ->format('src/a.php', $this->root);
        $this->assertNotNull($report);
        $this->assertSame('printf X >> ' . escapeshellarg('src/a.php'), $report->command);

        $this->put('src/b.php', "one\n");
        $report = FormatRunner::new()->withCommands(['php' => 'printf X >> {file}'])
            ->format('src/b.php', $this->root);
        $this->assertNotNull($report);
        $this->assertSame('printf X >> ' . escapeshellarg('src/b.php'), $report->command);
    }

    /**
     * ISS-011's argument, inherited: a placeholder the settings author wrapped
     * in quotes is collapsed to bare `{file}` before the path is re-quoted —
     * otherwise the wrapper stays open around a model-influenced name, and
     * THIS path is written, not just read.
     */
    public function testAQuotedPlaceholderIsCollapsedBeforeThePathIsQuotedIn(): void
    {
        $this->put('src/a.php', "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> "{file}"']);

        $report = $formatter->format('src/a.php', $this->root);

        $this->assertNotNull($report);
        $this->assertSame('printf X >> ' . escapeshellarg('src/a.php'), $report->command);
        $this->assertTrue($report->changed);
    }

    public function testAFileNamedLikeACommandSubstitutionStillDoesNotRunIt(): void
    {
        $marker = $this->root . '/pwned';
        $name = "evil\$(touch " . $marker . ').php';
        $this->put($name, "one\n");
        $formatter = FormatRunner::new()->withCommands(['php' => 'printf X >> "{file}"']);

        try {
            $report = $formatter->format($name, $this->root);
        } finally {
            @unlink($this->root . '/' . $name);
        }

        $this->assertNotNull($report);
        $this->assertTrue($report->changed);
        $this->assertFileDoesNotExist($marker, 'the name rode the command line as data, not code');
    }

    // ── FormatReport rendering ─────────────────────────────────────────

    public function testTheKeptRewriteNoteTellsTheModelToReRead(): void
    {
        $report = new FormatReport('src/a.php', '/abs/src/a.php', 'fixer {file}', 0, '', false, 30.0, true, null);

        $note = $report->render();

        $this->assertStringContainsString('## Post-edit format', $note);
        $this->assertStringContainsString('rewrote src/a.php', $note);
        $this->assertStringContainsString('re-read before editing against line numbers', $note);
    }

    public function testTheRestoreNoteNamesTheReasonAndKeepsTheFormattersOutput(): void
    {
        $report = new FormatReport('src/a.php', '/abs/src/a.php', 'evil', 0, 'boom', false, 30.0, false, 'the command emptied the file');

        $note = $report->render();

        $this->assertStringContainsString('was put back unchanged', $note);
        $this->assertStringContainsString('the command emptied the file', $note);
        $this->assertStringContainsString('boom', $note);
    }

    public function testTheFormatterOutputIsCutBeforeTheNoteIs(): void
    {
        $report = new FormatReport('a.php', '/abs/a.php', 'x', 0, str_repeat('y', FormatReport::MAX_OUTPUT_BYTES + 10), false, 30.0, false, 'the command emptied the file');

        $note = $report->render();

        $this->assertStringEndsWith('…', $note);
        $this->assertLessThanOrEqual(
            FormatReport::MAX_OUTPUT_BYTES + 400,
            strlen($note),
            'the note stays a bounded fragment of a hook budget',
        );
    }

    public function testImmutabilityOfTheBuilder(): void
    {
        $base = FormatRunner::new();
        $withMap = $base->withCommands(['php' => 'fixer']);

        $this->assertSame([], $base->commands());
        $this->assertNotSame($base, $withMap);
        $this->assertNotSame($withMap, $withMap->withTimeout(2.0));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o700, true);
        file_put_contents($path, $contents);
    }
}
