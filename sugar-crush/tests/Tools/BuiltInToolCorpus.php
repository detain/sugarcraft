<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\Tool;

/**
 * The built-in tool set, DERIVED from `src/` rather than listed.
 *
 * Every corpus of built-in tools in this suite used to be a literal array, and
 * that pins only one of the two directions. MEASURED: adding an eleventh `Tool`
 * implementor (`src/Tools/BuiltIn/Notify.php`) and NOT listing it in
 * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} left
 * `BinSugarcrushWiringTest` at `OK (298 tests, 1692 assertions)` and the whole
 * Integration tier green — while `tests/Tools/BuiltInToolTest.php`'s "all
 * built-in tools" provider listed only NINE of the ten that existed, `SkillTool`
 * absent. Nothing scanned the directory. The omitted-from-the-array direction is
 * exactly what happened to `Write`: written, tested, named in the README, and
 * unreachable from any real run.
 *
 * So the SOURCE TREE is the corpus — `src/`, not one directory inside it. It was
 * one flat directory for two rounds, which put the same recurrence back in front
 * of `src/LSP/LspTool.php`; see {@see classNames()} for the measurement and for
 * why that widening changes nothing on today's tree. A new tool class is in every
 * one of these tests the moment it exists, and it fails them until it is wired.
 *
 * NOT a trait, and not a static in one of the test classes: three test files in
 * three namespaces need the same list, and a copy per file is the shape being
 * removed. It carries no `Test` suffix, so PHPUnit does not collect it.
 */
final class BuiltInToolCorpus
{
    /** The PSR-4 prefix `src/` is rooted at — `composer.json`'s `autoload`. */
    private const NAMESPACE_PREFIX = 'SugarCraft\\Crush\\';

    /**
     * The directory whose contents MUST every one of them declare their PSR-4
     * symbol: the tools a real run dispatches, where a namespace/directory
     * disagreement is a defect rather than an exemption.
     */
    private const WIRED_TOOL_DIR = 'Tools/BuiltIn';

    /**
     * Every concrete {@see Tool} implementor anywhere under `src/`.
     *
     * ANYWHERE, and the widening is the fix for a LATENT trap rather than a live
     * false green. This globbed the flat `src/Tools/BuiltIn/*.php` and hard-coded
     * that namespace, so a `Tool` implementor one directory away was invisible to
     * all three consumers ({@see \SugarCraft\Crush\Tests\Tools\BuiltInToolTest},
     * {@see \SugarCraft\Crush\Tests\Providers\ToolSchemaEncodingTest},
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest}).
     * MEASURED on this tree: the flat glob and this full-`src/` sweep return the
     * SAME TEN classes, so nothing is being caught today — 267 `.php` files under
     * `src/`, 10 concrete `Tool` implementors, all 10 in `src/Tools/BuiltIn/`.
     * What changes is `src/LSP/LspTool.php`, the next planned tool (plan step
     * #17): under the flat glob it would arrive unwired and unseen, which is
     * verbatim the recurrence this corpus was written to prevent.
     *
     * THE GUARD FIX BELOW IS A PREREQUISITE FOR THAT WIDENING, not a nicety
     * beside it. Symbol kinds measured across the same 267 files: 220 concrete
     * classes, 25 enums, 16 interfaces, 6 traits, **0 abstract classes**. So the
     * one shape the old `class_exists()`-only guard classified correctly is the
     * one shape that does not occur, and the 22 files it would have thrown on are
     * already in the tree — `src/LSP/` alone ships `LspCacheInterface` and
     * `LspConnectionInterface`. Widening the scan without fixing the guard would
     * have aborted suite construction on this very checkout.
     *
     * Sorted, so a provider keyed off it names its cases the same way on every
     * machine — directory-iteration order is not a contract.
     *
     * INJECTABLE $srcDir/$namespacePrefix, and only tests of this scanner pass
     * them: they exist so the interface/trait/mis-namespaced cases can be driven
     * against a SYNTHETIC tree. Driving them by writing probe files into the real
     * `src/` is what the first attempt did, and a probe that fatals leaves residue
     * in a tree other suites are reading concurrently.
     *
     * @return list<class-string<Tool>>
     */
    public static function classNames(?string $srcDir = null, string $namespacePrefix = self::NAMESPACE_PREFIX): array
    {
        $srcDir ??= self::srcDir();

        $classes = [];
        foreach (self::sourceFiles($srcDir) as $relative) {
            $class = $namespacePrefix . str_replace('/', '\\', substr($relative, 0, -4));

            // `class_exists()` was the ONLY guard here, and the filter below
            // carried a comment claiming it covered "interfaces, traits and
            // abstract bases". MEASURED directly on PHP 8.3.6:
            //
            //     class_exists(interface) = false
            //     class_exists(trait)     = false
            //     class_exists(abstract)  = true
            //
            // so only `abstract` ever reached that filter, and an ordinary
            // `NotifierInterface.php` next to the tools made the throw below fire
            // during corpus CONSTRUCTION — `phpunit --list-tests` aborted before
            // enumerating a single test.
            //
            // WHAT WAS BROKEN WAS THE THROW, NOT THE FILTER, and the difference
            // matters because the old comment claimed the reverse. Measured on
            // PHP 8.3.6, `ReflectionClass` reflects interfaces and traits
            // perfectly well, and the filter below already rejects every one of
            // them without a clause of its own:
            //
            //     interface with >=1 method (own or inherited)  isAbstract = true
            //     interface extending Tool (even if empty)      isAbstract = true
            //     empty interface extending nothing             implementsInterface(Tool) = false
            //     any trait                                     implementsInterface(Tool) = false
            //
            // So no "not a class" clause is added here: it would be unreachable,
            // which is what the sentence it replaced already was. The three
            // shapes are handled — one by this guard no longer throwing on them,
            // two by the filter — and each is driven in
            // {@see BuiltInToolCorpusTest}.
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                if (str_starts_with($relative, self::WIRED_TOOL_DIR . '/')) {
                    throw new \RuntimeException(
                        "src/{$relative} does not declare {$class}; the built-in tool namespace and "
                        . 'directory must agree',
                    );
                }

                // Elsewhere under `src/` this is a PSR-4 exemption, not a tool
                // defect, and it CANNOT be a dispatchable Tool either way. It is
                // skipped rather than thrown on, precisely so the arrival of a
                // functions-only or multi-symbol file cannot abort suite
                // construction — and {@see nonClassSources()} keeps the skip
                // visible instead of silent, asserted by
                // {@see BuiltInToolCorpusTest}.
                continue;
            }

            $reflection = new \ReflectionClass($class);
            // The filter that does the classifying, for all four non-tool shapes:
            // abstract bases, interfaces (abstract, per the measurement above),
            // traits and enums (`class_exists()` answers true for an enum, and it
            // is rejected here on the interface clause).
            if ($reflection->isAbstract() || !$reflection->implementsInterface(Tool::class)) {
                continue;
            }

            /** @var class-string<Tool> $class */
            $classes[] = $class;
        }

        if ($classes === []) {
            throw new \RuntimeException("No built-in tool sources found under {$srcDir}");
        }

        sort($classes);

        return $classes;
    }

    /**
     * The `src/` files that declare no symbol at their PSR-4 name.
     *
     * The visible half of {@see classNames()}'s one non-throwing skip. MEASURED
     * on this tree: 0 of 267. Pinned at zero by
     * {@see BuiltInToolCorpusTest::testEverySourceFileDeclaresItsPsr4Symbol()},
     * so a file that becomes exempt turns ONE test red with its own name in the
     * message rather than aborting the whole suite's construction.
     *
     * @return list<string> paths relative to `src/`
     */
    public static function nonClassSources(?string $srcDir = null, string $namespacePrefix = self::NAMESPACE_PREFIX): array
    {
        $srcDir ??= self::srcDir();

        $skipped = [];
        foreach (self::sourceFiles($srcDir) as $relative) {
            $class = $namespacePrefix . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                $skipped[] = $relative;
            }
        }

        return $skipped;
    }

    /** @return list<string> every `.php` file under $src, relative to it, sorted */
    private static function sourceFiles(string $src): array
    {
        if (!is_dir($src)) {
            throw new \RuntimeException("No source tree found at {$src}");
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), \strlen($src) + 1);
            }
        }

        // Sorted here rather than only in classNames(), so nonClassSources()
        // reports in a stable order too.
        sort($files);

        return $files;
    }

    private static function srcDir(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }

    /**
     * Every built-in tool, constructed with its default (standalone) wiring.
     *
     * A tool whose constructor has REQUIRED arguments has to be named here.
     * Deliberately a throw rather than a skip: a new tool that this cannot build
     * is a new tool nobody has said how to test, and a silent skip would hand
     * back the empty-corpus problem this class exists to remove.
     *
     * @return list<Tool>
     */
    public static function instances(): array
    {
        $tools = [];
        foreach (self::classNames() as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();

            if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                $tools[] = new $class();

                continue;
            }

            $tools[] = match ($class) {
                SkillTool::class => new SkillTool(new SkillRegistry()),
                default => throw new \RuntimeException(sprintf(
                    '%s needs constructor arguments; add it to %s::instances() so every built-in-tool test '
                    . 'covers it rather than silently skipping it.',
                    $class,
                    self::class,
                )),
            };
        }

        return $tools;
    }
}
