<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard (docs-fix-1023 markdown lane, quickstart-fence row).
 *
 * Every fenced php block in README.md and every file under examples/ is
 * linted AND executed in a child `php` process with a wall-clock ceiling,
 * asserting exit code 0 and empty stderr. The markdown quickstart once
 * shipped an inner ``` fence that legally closed the outer one on GitHub,
 * leaking `MD);` as a paragraph and swallowing the next heading in a
 * runaway fence; a doc that runs is a doc that stays honest.
 *
 * Fences are read in both flavours the README legitimately uses: ```php
 * blocks, and ~~~~php blocks whose body embeds backtick fences (the
 * quickstart's heredoc sample). Opening and closing markers must be the
 * same character run, so the inner ``` can never terminate the outer ~~~.
 *
 * README blocks are self-contained: each runs against the lib autoloader
 * plus `declare(strict_types=1)` prepended, so snippet-local imports are
 * part of what this test pins. Child stdin is /dev/null — the pipe demo
 * example reads STDIN and must terminate, not block.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock ceiling per snippet, seconds. */
    private const RUN_TIMEOUT_SECONDS = 15;

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/markdown-readme-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir) && !mkdir($this->scratchDir, 0700) && !is_dir($this->scratchDir)) {
            $this->fail('could not create scratch dir ' . $this->scratchDir);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/' . chr(42)) ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->scratchDir);
    }

    /**
     * @return iterable<string, array{0:string, 1:string}> [kind, script body or absolute path]
     */
    public static function scriptProvider(): iterable
    {
        $blocks = self::phpBlocks((string) file_get_contents(dirname(__DIR__) . '/README.md'));
        if (count($blocks) < 8) {
            self::fail(sprintf(
                'expected at least eight fenced php blocks in README.md, found %d — the extractor broke',
                count($blocks),
            ));
        }
        foreach ($blocks as $index => $body) {
            yield 'README block ' . ($index + 1) => ['snippet', $body];
        }

        foreach (scandir(dirname(__DIR__) . '/examples') ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                yield 'example ' . $entry => ['example', dirname(__DIR__) . '/examples/' . $entry];
            }
        }
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetCompiles(string $kind, string $source): void
    {
        $script = $this->materialise($kind, $source);

        [$status, $stderr] = $this->runPhp(['-l', $script]);
        $this->assertSame(0, $status, "php -l failed for {$kind} snippet:\n" . $stderr);
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetRunsToCompletion(string $kind, string $source): void
    {
        $script = $this->materialise($kind, $source);

        [$status, $stderr] = $this->runPhp([
            '-d', 'max_execution_time=' . self::RUN_TIMEOUT_SECONDS,
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL',
            $script,
        ]);
        $this->assertSame(0, $status, "running the {$kind} snippet exited {$status}:\n" . $stderr);
        $this->assertSame('', trim($stderr), "the {$kind} snippet wrote to stderr:\n" . $stderr);
    }

    /** README bodies become child-process scripts; example files run in place. */
    private function materialise(string $kind, string $source): string
    {
        if ($kind === 'example') {
            return $source;
        }

        $header = "<?php\ndeclare(strict_types=1);\nrequire " . var_export(realpath(self::AUTOLOAD), true) . ";\n";
        $path   = $this->scratchDir . '/snippet-' . substr(md5($source), 0, 12) . '.php';
        file_put_contents($path, $header . $source . "\n");

        return $path;
    }

    /**
     * Every php-fenced block body, reading both fence characters. A block
     * opened by a run of N backticks (or N tildes) is closed only by a
     * following run of >= N of the SAME character with nothing after it —
     * an embedded fence of the other character is body text.
     *
     * @return list<string>
     */
    private static function phpBlocks(string $readme): array
    {
        $lines  = explode("\n", $readme);
        $count  = count($lines);
        $blocks = [];
        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^(`{3,}|~{3,})php[ \t]*$/', $lines[$i], $open) !== 1) {
                continue;
            }
            $char   = $open[1][0];
            $isTilde = $char === '~';
            $body   = [];
            for ($j = $i + 1; $j < $count; $j++) {
                $closer = $isTilde ? '/^~{3,}[ \t]*$/' : '/^`{3,}[ \t]*$/';
                if (preg_match($closer, $lines[$j]) === 1) {
                    $blocks[] = implode("\n", $body);
                    $i        = $j;
                    continue 2;
                }
                $body[] = $lines[$j];
            }
            self::fail('unclosed php fence in README.md starting at line ' . ($i + 1));
        }

        return $blocks;
    }

    /**
     * The README's table cite must stay canonical: the phantom Sprinkles
     * namespace the 2026-10 docs review caught must never regrow, and the
     * class the README names must actually exist.
     */
    public function testTheTableNamespaceCiteIsCanonicalNotPhantom(): void
    {
        self::assertFalse(class_exists('SugarCraft\Markdown\Sprinkles\Table'));
        self::assertFalse(class_exists('SugarCraft\Sprinkles\Table\Table'));
        self::assertTrue(class_exists('SugarCraft\Style\Table\Table'));
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string} [exit status, captured stderr]
     */
    private function runPhp(array $args): array
    {
        $errorFile = $this->scratchDir . '/stderr-' . substr(md5(implode('|', $args)), 0, 12);
        $command   = escapeshellarg(PHP_BINARY);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' </dev/null 2>' . escapeshellarg($errorFile);

        $lines = [];
        exec($command, $lines, $status);

        return [$status, (string) file_get_contents($errorFile)];
    }
}
