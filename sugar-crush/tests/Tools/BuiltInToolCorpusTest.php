<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\Tool;

/**
 * The scanner three test files trust, tested itself.
 *
 * {@see BuiltInToolCorpus} is not collected by PHPUnit (no `Test` suffix) and had
 * no tests of its own, so both of its defects were invisible to the suite that
 * depends on it: it saw one flat directory, and its interface/trait guard sat
 * behind a `class_exists()` that throws first.
 *
 * TWO KINDS OF TEST HERE, kept apart on purpose:
 *  - against the REAL `src/` — the figures the corpus doc-block quotes, derived
 *    so they cannot drift from the tree they describe;
 *  - against a SYNTHETIC tree under `sys_get_temp_dir()`, for every escape and
 *    edge shape. Probe files are NOT written into `src/`: two other suites read
 *    that tree concurrently, and a probe that fatals leaves residue behind in it.
 */
final class BuiltInToolCorpusTest extends TestCase
{
    private const PROBE_PREFIX = 'CorpusProbe\\';

    private string $srcDir;

    /** The synthetic `src/` the shape tests scan. */
    private string $probeDir;

    /** @var callable|null */
    private $probeAutoloader = null;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
        $this->probeDir = sys_get_temp_dir() . '/builtin_tool_corpus_probe_' . uniqid();
        mkdir($this->probeDir, 0o777, true);

        // The scanner resolves a file to a class name and asks PHP whether that
        // symbol exists, so a synthetic tree needs its own autoloader or every
        // probe would read as "declares no symbol".
        $this->probeAutoloader = function (string $class): void {
            if (!str_starts_with($class, self::PROBE_PREFIX)) {
                return;
            }

            $file = $this->probeDir . '/'
                . str_replace('\\', '/', substr($class, \strlen(self::PROBE_PREFIX))) . '.php';

            // require_ONCE, and it is load-bearing: the mis-namespaced probe
            // declares a symbol OTHER than the one asked for, so `class_exists()`
            // comes back false and the scanner then asks `interface_exists()` and
            // `trait_exists()` for the same name — three autoload attempts on one
            // file, which a plain `require` turns into a redeclaration fatal.
            if (is_file($file)) {
                require_once $file;
            }
        };
        spl_autoload_register($this->probeAutoloader);
    }

    protected function tearDown(): void
    {
        if ($this->probeAutoloader !== null) {
            spl_autoload_unregister($this->probeAutoloader);
        }

        $this->removeTree($this->probeDir);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeProbe(string $relative, string $code): void
    {
        $path = $this->probeDir . '/' . $relative;
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $code);
    }

    /** @return list<string> */
    private function scanProbe(): array
    {
        return BuiltInToolCorpus::classNames($this->probeDir, self::PROBE_PREFIX);
    }

    /**
     * A dispatchable tool, so the synthetic tree is never empty — `classNames()`
     * throws on an empty result, which is a contract of its own and would
     * otherwise mask every assertion below.
     */
    private function writeOneRealTool(): void
    {
        $this->writeProbe('Tools/BuiltIn/Anchor.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class Anchor implements Tool
            {
                public function name(): string
                {
                    return 'anchor';
                }

                public function description(): string
                {
                    return 'anchor';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('anchor');
                }
            }
            PHP);
    }

    // ─── against the real src/ ──────────────────────────────────────

    /**
     * The claim in {@see BuiltInToolCorpus::classNames()}'s doc-block, asserted
     * rather than stated: widening the scan from `src/Tools/BuiltIn/*.php` to all
     * of `src/` returns the same set on THIS tree. When it fails, a `Tool`
     * implementor has been added outside the wired directory — which is the case
     * the widening exists for, and the failure names it.
     */
    public function testTheWideSweepAndTheOldFlatGlobAgreeOnThisTree(): void
    {
        $flat = [];
        foreach ((array) glob($this->srcDir . '/Tools/BuiltIn/*.php') as $file) {
            $class = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . basename((string) $file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->isAbstract() && $reflection->implementsInterface(Tool::class)) {
                $flat[] = $class;
            }
        }
        sort($flat);

        $this->assertSame($flat, BuiltInToolCorpus::classNames());
        $this->assertCount(10, $flat, 'ten wired built-in tools on this tree');
    }

    /**
     * The doc-block's symbol-kind census, derived. The load-bearing number is the
     * LAST one: `abstract` is the only shape the old `class_exists()`-only guard
     * classified correctly, and there are none — while the 16 interfaces and 6
     * traits it would have thrown on are already here.
     */
    public function testTheSymbolKindCensusTheDocBlockQuotes(): void
    {
        $counts = ['concrete' => 0, 'enum' => 0, 'abstract' => 0, 'interface' => 0, 'trait' => 0, 'none' => 0];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = 0;
        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$files;

            $relative = substr($file->getPathname(), \strlen($this->srcDir) + 1);
            $class = 'SugarCraft\\Crush\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                $counts[$reflection->isEnum() ? 'enum' : ($reflection->isAbstract() ? 'abstract' : 'concrete')]++;
            } elseif (interface_exists($class)) {
                ++$counts['interface'];
            } elseif (trait_exists($class)) {
                ++$counts['trait'];
            } else {
                ++$counts['none'];
            }
        }

        $this->assertSame(267, $files, 'php files under src/');
        $this->assertSame(
            ['concrete' => 220, 'enum' => 25, 'abstract' => 0, 'interface' => 16, 'trait' => 6, 'none' => 0],
            $counts,
        );
    }

    /**
     * Pinned at zero on this tree. Derived, so the figure cannot drift; and it is
     * ONE test rather than a throw inside corpus construction, which is the whole
     * difference between a PSR-4 exemption arriving and the suite refusing to
     * enumerate.
     */
    public function testEverySourceFileDeclaresItsPsr4Symbol(): void
    {
        $exempt = BuiltInToolCorpus::nonClassSources();

        $this->assertSame(
            [],
            $exempt,
            'PSR-4-exempt src/ files (skipped by the corpus scan): ' . implode(', ', $exempt),
        );
    }

    public function testEveryCorpusInstanceIsATool(): void
    {
        $instances = BuiltInToolCorpus::instances();

        $this->assertCount(\count(BuiltInToolCorpus::classNames()), $instances);
        foreach ($instances as $tool) {
            $this->assertInstanceOf(Tool::class, $tool);
        }
    }

    // ─── against the synthetic tree ─────────────────────────────────

    /**
     * Finding #90(a), driven with the shape plan step #17 will create: a `Tool`
     * implementor OUTSIDE the wired tool directory. The flat glob returned the
     * anchor alone; the sweep returns both.
     */
    public function testAToolImplementorOutsideTheWiredDirectoryIsSeen(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('LSP/LspTool.php', <<<'PHP'
            namespace CorpusProbe\LSP;

            use SugarCraft\Crush\Tools\Tool;
            use SugarCraft\Crush\Tools\ToolResult;

            final class LspTool implements Tool
            {
                public function name(): string
                {
                    return 'lsp';
                }

                public function description(): string
                {
                    return 'lsp';
                }

                public function inputSchema(): array
                {
                    return [];
                }

                public function execute(array $args): ToolResult
                {
                    return ToolResult::error('lsp');
                }
            }
            PHP);

        $this->assertSame(
            ['CorpusProbe\\LSP\\LspTool', 'CorpusProbe\\Tools\\BuiltIn\\Anchor'],
            $this->scanProbe(),
        );
    }

    /**
     * Finding #90(b): the guard's comment claimed three shapes and covered one. An
     * INTERFACE in the wired tool directory used to make `classNames()` throw
     * inside corpus CONSTRUCTION, which took `phpunit --list-tests` down with it —
     * the suite could not enumerate, let alone run.
     */
    public function testAnInterfaceInTheWiredToolDirectoryIsSkippedNotThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/NotifierInterface.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            interface NotifierInterface
            {
                public function notify(string $message): void;
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The nastiest of the three shapes, because it satisfies HALF the filter:
     * `implementsInterface(Tool::class)` is TRUE for an interface that extends
     * `Tool`. What keeps it out is the other half — measured on PHP 8.3.6, an
     * interface with at least one method (own OR inherited) reports
     * `isAbstract() === true`, so `interface ProgressTool extends Tool` is
     * abstract and rejected. Were it to get through,
     * {@see BuiltInToolCorpus::instances()} would fatal trying to `new` it.
     *
     * WHAT THIS TEST DOES NOT SHOW, stated because an earlier draft of this
     * doc-block claimed the opposite: it does not demonstrate a need for a
     * separate "not a class" clause. Measured, no such clause is reachable —
     * every interface and trait is caught by the two filters — which is why
     * {@see BuiltInToolCorpus::classNames()} does not carry one.
     */
    public function testAnInterfaceExtendingToolDoesNotEnterTheCorpus(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/ProgressTool.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;

            interface ProgressTool extends Tool
            {
                public function progress(): int;
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    public function testATraitInTheWiredToolDirectoryIsSkippedNotThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Notifies.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            trait Notifies
            {
                public function notify(string $message): void
                {
                }
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The one shape the OLD guard already handled, kept handled: an abstract base
     * reaches the filter and is rejected there. There are none in `src/` today —
     * see {@see testTheSymbolKindCensusTheDocBlockQuotes()} — which is exactly why
     * the two shapes above went unnoticed.
     */
    public function testAnAbstractToolBaseIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/AbstractTool.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            use SugarCraft\Crush\Tools\Tool;

            abstract class AbstractTool implements Tool
            {
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /** An enum in the tree is neither a tool nor a reason to abort. */
    public function testAnEnumIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Mode.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn;

            enum Mode: string
            {
                case Fast = 'fast';
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /** A plain class that is not a Tool is not a tool. */
    public function testANonToolClassIsSkipped(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Support/Helper.php', <<<'PHP'
            namespace CorpusProbe\Support;

            final class Helper
            {
            }
            PHP);

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
    }

    /**
     * The strictness that is KEPT, and kept narrow: inside the wired tool
     * directory a file whose namespace disagrees with its path is still a throw,
     * because a tool nobody can autoload is a tool nobody can dispatch.
     */
    public function testAMisnamespacedFileInTheWiredToolDirectoryStillThrows(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Tools/BuiltIn/Misnamespaced.php', <<<'PHP'
            namespace CorpusProbe\Tools\BuiltIn\Elsewhere;

            final class Misnamespaced
            {
            }
            PHP);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/namespace and directory must agree/');

        $this->scanProbe();
    }

    /**
     * The other half of that decision: OUTSIDE the wired directory the same file
     * is skipped rather than thrown on, so a PSR-4-exempt file arriving cannot
     * abort suite construction — and the skip is REPORTED rather than swallowed,
     * which is what stops the fix being a new blindness.
     */
    public function testAnExemptFileElsewhereIsReportedRatherThanThrownOn(): void
    {
        $this->writeOneRealTool();
        $this->writeProbe('Support/functions.php', "namespace CorpusProbe\\Support;\n\nreturn 1;\n");

        $this->assertSame(['CorpusProbe\\Tools\\BuiltIn\\Anchor'], $this->scanProbe());
        $this->assertSame(
            ['Support/functions.php'],
            BuiltInToolCorpus::nonClassSources($this->probeDir, self::PROBE_PREFIX),
        );
    }

    public function testAnEmptyTreeThrowsRatherThanHandingBackAnEmptyCorpus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No built-in tool sources found/');

        $this->scanProbe();
    }

    /** Deterministic order is a contract three providers name their cases from. */
    public function testTheCorpusIsSortedRegardlessOfDirectoryOrder(): void
    {
        $this->writeOneRealTool();
        foreach (['Zebra', 'Alpha', 'Middle'] as $name) {
            $this->writeProbe("Tools/BuiltIn/{$name}.php", <<<PHP
                namespace CorpusProbe\\Tools\\BuiltIn;

                use SugarCraft\\Crush\\Tools\\Tool;
                use SugarCraft\\Crush\\Tools\\ToolResult;

                final class {$name} implements Tool
                {
                    public function name(): string
                    {
                        return '{$name}';
                    }

                    public function description(): string
                    {
                        return '{$name}';
                    }

                    public function inputSchema(): array
                    {
                        return [];
                    }

                    public function execute(array \$args): ToolResult
                    {
                        return ToolResult::error('{$name}');
                    }
                }
                PHP);
        }

        $names = $this->scanProbe();

        $this->assertSame($names, array_values($names));
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertCount(4, $names);
    }

    /**
     * The one input on which sorting FILE PATHS and sorting CLASS NAMES disagree,
     * so `classNames()`'s own `sort()` is pinned rather than shadowed by the sort
     * `sourceFiles()` already does. `/` is 0x2F and `\` is 0x5C, so any character
     * between them — a digit, an uppercase letter — inverts the two orders:
     *
     *     path order : Tools/A/B.php, Tools/A0.php
     *     class order: …\Tools\A0,    …\Tools\A\B
     *
     * Three providers name their cases off this list, so the order is a contract
     * and not a nicety.
     */
    public function testTheSortIsOnClassNamesNotOnFilePaths(): void
    {
        foreach (['A/B' => 'CorpusProbe\\Tools\\A', 'A0' => 'CorpusProbe\\Tools'] as $relative => $namespace) {
            $short = basename($relative);
            $this->writeProbe("Tools/{$relative}.php", <<<PHP
                namespace {$namespace};

                use SugarCraft\\Crush\\Tools\\Tool;
                use SugarCraft\\Crush\\Tools\\ToolResult;

                final class {$short} implements Tool
                {
                    public function name(): string
                    {
                        return '{$short}';
                    }

                    public function description(): string
                    {
                        return '{$short}';
                    }

                    public function inputSchema(): array
                    {
                        return [];
                    }

                    public function execute(array \$args): ToolResult
                    {
                        return ToolResult::error('{$short}');
                    }
                }
                PHP);
        }

        $this->assertSame(
            ['CorpusProbe\\Tools\\A0', 'CorpusProbe\\Tools\\A\\B'],
            $this->scanProbe(),
        );
    }
}
