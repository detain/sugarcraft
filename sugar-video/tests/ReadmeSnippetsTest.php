<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard (docs-fix-1023 video M3 genre mint).
 *
 * Every fenced ```php block in README.md is linted in a child `php -l`
 * process, and every class reference it names must resolve against the lib
 * autoloader (or its own imports) with the methods it calls actually
 * existing. Snippets here drive a real terminal and open real media, so
 * EXECUTION stays out of scope — but a documented call that fatals on
 * `class not found` or an unresolvable symbol is exactly what this catches:
 * the README once promised capability auto-detect the code never had, and
 * the API drifted past the docs in silence for a whole subtitles feature.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Classes instance-method calls fall back to when the block names none. */
    private const AMBIENT_CLASSES = [
        'SugarCraft\\Video\\Reel',
        'SugarCraft\\Video\\Player',
    ];

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/video-readme-' . getmypid() . '-' . spl_object_id($this);
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
     * @return iterable<string, array{0:string}> [label, block body]
     */
    public static function scriptProvider(): iterable
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $blocks = [];
        $offset = 0;
        while (preg_match('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = $block[1][0];
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        if (count($blocks) < 6) {
            self::fail(sprintf(
                'expected at least six fenced php blocks in README.md, found %d — the extractor broke',
                count($blocks),
            ));
        }

        foreach ($blocks as $index => $body) {
            yield 'README block ' . ($index + 1) => [$body];
        }
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetCompiles(string $body): void
    {
        $script = $this->materialise($body);

        [$status, $stderr] = $this->runPhp(['-l', $script]);
        $this->assertSame(0, $status, "php -l failed for the README snippet:\n" . $body . "\n" . $stderr);
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetResolvesItsSymbols(string $body): void
    {
        // Comments are prose, not documented API calls: "Detect::probe()" in
        // an explanatory line must not be held to the standard of a call a
        // reader would copy. The lint leg still runs against the raw block.
        $body = self::stripComments($body);

        $imports = self::importsOf($body);

        foreach (self::staticCallsOf($body) as [$class, $method]) {
            $fqcn = $imports[$class] ?? ('SugarCraft\\Video\\' . $class);
            $this->assertTrue(
                class_exists($fqcn),
                "README cites {$class}::{$method}() but {$fqcn} does not resolve",
            );
            $this->assertTrue(
                method_exists($fqcn, $method),
                "README cites {$fqcn}::{$method}() but the class has no such method",
            );
        }

        foreach (self::instanceCallsOf($body) as $method) {
            $owners = array_merge(
                array_values($imports),
                self::instantiatedClassesOf($body),
                self::AMBIENT_CLASSES,
            );
            $found = false;
            foreach ($owners as $fqcn) {
                if (class_exists($fqcn) && method_exists($fqcn, $method)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "README calls ->{$method}() but none of the block's classes ("
                    . implode(', ', $owners) . ' or the ambient Reel/Player) define it',
            );
        }

        foreach (self::instantiatedClassesOf($body) as $fqcn) {
            $this->assertTrue(class_exists($fqcn), "README instantiates {$fqcn} but the class does not exist");
        }
    }

    /** Remove block and line comments so only real calls are scanned. */
    private static function stripComments(string $body): string
    {
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', $body) ?? $body;

        return preg_replace('#//.*#', '', $withoutBlocks) ?? $withoutBlocks;
    }

    /**
     * @return array<string,string> short alias => fully-qualified name
     */
    private static function importsOf(string $body): array
    {
        $imports = [];
        if (preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?;/m', $body, $uses, PREG_SET_ORDER) > 0) {
            foreach ($uses as $use) {
                $fqcn    = ltrim(str_replace('\\\\', '\\', $use[1]), '\\');
                $alias   = $use[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
                $imports[$alias] = $fqcn;
            }
        }

        return $imports;
    }

    /**
     * @return list<array{0:string, 1:string}> [class, method] pairs
     */
    private static function staticCallsOf(string $body): array
    {
        $calls = [];
        preg_match_all('/(?<![\\\\\w>])(\w+)::([a-zA-Z]\w*)\(/', $body, $hits, PREG_SET_ORDER);
        foreach ($hits as $hit) {
            $calls[] = [$hit[1], $hit[2]];
        }

        return $calls;
    }

    /**
     * @return list<string> method names called via any `->method(` arrow,
     *                      including chains that hang off a static call
     */
    private static function instanceCallsOf(string $body): array
    {
        preg_match_all('/->([a-zA-Z]\w*)\(/', $body, $hits);

        return $hits[1] ?? [];
    }

    /**
     * @return list<string> fully-qualified names behind `new \X(` expressions
     */
    private static function instantiatedClassesOf(string $body): array
    {
        $classes = [];
        preg_match_all('/new\s+([\\\\\w]+)\s*\(/', $body, $hits);
        foreach ($hits[1] ?? [] as $raw) {
            $fqcn = ltrim(str_replace('\\\\', '\\', $raw), '\\');
            if ($fqcn !== '') {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    private function materialise(string $body): string
    {
        $header = "<?php\ndeclare(strict_types=1);\nrequire " . var_export(realpath(self::AUTOLOAD), true) . ";\n";
        $path   = $this->scratchDir . '/snippet-' . substr(md5($body), 0, 12) . '.php';
        file_put_contents($path, $header . $body . "\n");

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
