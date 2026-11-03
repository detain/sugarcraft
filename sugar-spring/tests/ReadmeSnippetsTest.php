<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard.
 *
 * Every fenced ```php block in README.md and every file under examples/ is
 * linted AND executed in a child `php` process with a wall-clock ceiling,
 * asserting exit code 0 and empty stderr. The spring README once shipped a
 * SpringChain snippet that infinite-looped as written and a Projectile
 * snippet that fatals on an unimported `Spring` (both PROVEN by this shape's
 * ancestor probe at review time); a doc that runs is a doc that stays honest.
 *
 * README blocks must be self-contained: each runs against the lib autoloader
 * plus `declare(strict_types=1)` prepended, so snippet-local imports are
 * part of what this test pins.
 *
 * Fleet lesson (lib-reviews-1102 minor 5): the original extractor anchored
 * its fence match at column 0, so the two INDENTED frame-loop fences under
 * the driving section were invisible to the guard — snippets that never ran
 * could rot silently inside the exact guard genre that was supposed to
 * prevent that. `^[ \t]*` + dedent closes the hole, and the block count is
 * pinned EXACT (a bare floor stays green while snippets go missing).
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock ceiling per snippet, seconds. */
    private const RUN_TIMEOUT_SECONDS = 15;

    /**
     * Exact number of php fences in README.md. Add a snippet, bump the
     * count — the guard fails CLOSED both ways, so no fence can be silently
     * lost to a regex change or silently skipped for being indented.
     */
    private const README_PHP_BLOCK_COUNT = 13;

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/spring-readme-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir) && !mkdir($this->scratchDir, 0700) && !is_dir($this->scratchDir)) {
            $this->fail('could not create scratch dir ' . $this->scratchDir);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/' . chr(42)) ?: [] as $leftover) {
            unlink($leftover);
        }
        @rmdir($this->scratchDir);
    }

    /**
     * @return iterable<string, array{0:string, 1:string}> [label, absolute script path]
     */
    public static function scriptProvider(): iterable
    {
        $readmePath = dirname(__DIR__) . '/README.md';
        $readme     = (string) file_get_contents($readmePath);

        $blocks  = [];
        $offset  = 0;
        while (preg_match('/^[ \t]*```php[ \t]*\n(.*?)\n[ \t]*```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = self::dedent($block[1][0]);
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        if (count($blocks) !== self::README_PHP_BLOCK_COUNT) {
            self::fail(sprintf(
                'expected exactly %d fenced php blocks in README.md, found %d — the extractor broke or a snippet went missing',
                self::README_PHP_BLOCK_COUNT,
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

    /**
     * Strip the leading whitespace shared by every non-blank line so an
     * indented fence (a snippet living inside a list or narrative section)
     * executes exactly like its column-zero siblings.
     */
    public static function dedent(string $block): string
    {
        $lines   = explode("\n", $block);
        $minimum = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent  = strlen($line) - strlen(ltrim($line, " \t"));
            $minimum = $minimum === null ? $indent : min($minimum, $indent);
        }
        if ($minimum === null || $minimum === 0) {
            return $block;
        }

        return implode("\n", array_map(
            static fn (string $line): string => trim($line) === '' ? $line : substr($line, $minimum),
            $lines,
        ));
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
        $command .= ' 2>' . escapeshellarg($errorFile);

        $lines = [];
        exec($command, $lines, $status);

        return [$status, (string) file_get_contents($errorFile)];
    }
}
