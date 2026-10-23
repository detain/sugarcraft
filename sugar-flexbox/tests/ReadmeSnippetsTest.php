<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard (docs-fix-1023 flexbox B1 genre).
 *
 * Every fenced ```php block in README.md and every file under examples/ is
 * linted AND executed in a child `php` process with a wall-clock ceiling,
 * asserting exit code 0 and empty stderr. The sugar-flexbox README once
 * shipped a Table quickstart whose immutable builder returns were all
 * discarded — copy-pasting it rendered an empty table; a doc that runs is a
 * doc that stays honest.
 *
 * README blocks must be self-contained: each runs against the lib autoloader
 * plus `declare(strict_types=1)` prepended, so snippet-local imports are
 * part of what this test pins.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock ceiling per snippet, seconds. */
    private const RUN_TIMEOUT_SECONDS = 15;

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/flexbox-readme-' . getmypid() . '-' . spl_object_id($this);
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
        while (preg_match('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = $block[1][0];
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        if (count($blocks) < 3) {
            self::fail(sprintf(
                'expected at least three fenced php blocks in README.md, found %d — the extractor broke',
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
