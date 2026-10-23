<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard (docs-fix-1023 spring B1/B2 genre, minted for candy-sql).
 *
 * Every fenced ```php block in README.md and every file under examples/ is
 * linted in a child `php` process; blocks that are self-contained — no dial,
 * no undefined fixture variables (the gate is the marker list below) — are
 * additionally EXECUTED with a wall-clock ceiling, asserting exit code 0 and
 * empty stderr. The candy-sql README once shipped sections describing classes
 * that never existed; a doc that runs is a doc that stays honest.
 *
 * README blocks run against the lib autoloader plus `declare(strict_types=1)`
 * prepended, so snippet-local imports are part of what this test pins. The
 * Query History snippet cleans up its own temp files — this test asserts no
 * `candy-sql-doc-history` residue survives it.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock ceiling per snippet, seconds. */
    private const RUN_TIMEOUT_SECONDS = 15;

    /**
     * A php block is lint-only when its body contains one of these: it dials a
     * server, needs an undefined fixture variable, or is a method-body fragment.
     */
    private const EXECUTE_BLOCKED_MARKERS = [
        'fromConfig',      // dials
        'fromArgv',        // dials, needs real argv
        '$argv',           // harness-dependent
        '$path',           // undefined in-snippet
        '($db',            // call sites needing an existing connection
        '$rows',           // undefined fixture
        '$context',        // undefined fixture
        '$counters',       // undefined fixture
        '$notifier',       // toast-path fixture
        '$this->',         // method-body fragment
        '$pdo',            // undefined fixture
        'exportCsv',       // dials via exporter
    ];

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/sql-readme-' . getmypid() . '-' . spl_object_id($this);
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
     * @return iterable<string, array{0:string, 1:string}> [label, source body or absolute script path]
     */
    public static function scriptProvider(): iterable
    {
        foreach (self::readmeBlocks() as $index => $body) {
            yield 'README block ' . ($index + 1) => ['snippet', $body];
        }

        foreach (scandir(dirname(__DIR__) . '/examples') ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                yield 'example ' . $entry => ['example', dirname(__DIR__) . '/examples/' . $entry];
            }
        }
    }

    /**
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function executableProvider(): iterable
    {
        $count = 0;
        foreach (self::readmeBlocks() as $index => $body) {
            if (self::isSelfContained($body)) {
                yield 'executable README block ' . ($index + 1) => ['snippet', $body];
                $count++;
            }
        }
        if ($count < 3) {
            self::fail(sprintf(
                'the execute-gate let through only %d README blocks (floor 3) — either the marker list or the docs drifted',
                $count,
            ));
        }
    }

    /**
     * The History snippet is the behaviour-carrying one (store + recorder + query
     * round trip against a real SQLite file). Pin that it is in the executed set,
     * so the gate can never quietly reduce "runs" to "function definitions only".
     */
    public function testTheQueryHistorySnippetIsExecutedAndLeavesNoResidue(): void
    {
        $history = null;
        foreach (self::readmeBlocks() as $body) {
            if (str_contains($body, 'SqliteHistoryStore')) {
                $history = $body;
                break;
            }
        }
        self::assertNotNull($history, 'the README no longer carries a SqliteHistoryStore snippet');
        self::assertTrue(
            self::isSelfContained((string) $history),
            'the Query History snippet must be self-contained so this test executes it',
        );

        $script = $this->materialise('snippet', (string) $history);
        [$status, $stderr] = $this->runPhp([$script]);
        self::assertSame(0, $status, "the history snippet exited {$status}:\n" . $stderr);
        self::assertSame('', trim($stderr), "the history snippet wrote to stderr:\n" . $stderr);

        $residue = glob(sys_get_temp_dir() . '/candy-sql-doc-histor' . chr(121) . '-*') ?: [];
        self::assertSame([], $residue, 'the README history snippet must clean up its temp files');
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
     * @dataProvider executableProvider
     */
    public function testSelfContainedSnippetsRunToCompletion(string $kind, string $source): void
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

    private static function isSelfContained(string $body): bool
    {
        foreach (self::EXECUTE_BLOCKED_MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> every fenced php block body, in document order
     */
    private static function readmeBlocks(): array
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $blocks = [];
        $offset = 0;
        while (preg_match('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = $block[1][0];
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        if (count($blocks) < 15) {
            self::fail(sprintf(
                'expected at least fifteen fenced php blocks in README.md, found %d — the extractor broke',
                count($blocks),
            ));
        }

        return $blocks;
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
     *
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
