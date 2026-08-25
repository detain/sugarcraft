<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTool;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\McpToolBridge;
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
 * of the then-unwritten `LspTool`; see {@see classNames()} for the measurement,
 * for why the widening changes nothing on today's tree, and for where that tool
 * actually landed. A new tool class is in every one of these tests the moment it
 * exists, and it fails them until it is wired.
 *
 * "THE SOURCE TREE IS THE CORPUS" USED TO MEAN "ONE TYPE PER FILE", and `src/`
 * already ships nineteen counterexamples. The scan derived exactly one class per
 * FILENAME, so a `Tool` implementor declared as a SECOND top-level symbol in a
 * file was invisible to all four consumers while {@see nonClassSources()} still
 * returned `[]` — silently, because the file's PRIMARY symbol does exist.
 * MEASURED with `token_get_all()` rather than `class_exists()`: 278 `.php` files
 * under `src/` declare 297 top-level types, 19 of them secondary, in 8 files.
 * `src/App/App.php` alone declares twelve (`Msg`, `Cmd`, `UserInputMsg`, …), and
 * `src/ToolRegistry.php` declares `SugarCraft\Crush\Tool` — one `use` away from
 * colliding with the tool interface, and `tests/ToolRegistryTest.php` already
 * imports it. {@see declaredTypes()} is what the scan reads now.
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
     * Concrete {@see Tool} implementors under `src/` that
     * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} constructs DYNAMICALLY,
     * one per runtime-discovered thing, and therefore CANNOT appear as a literal
     * in that array.
     *
     * A RECORDED EXEMPTION, not a hole, and it is deliberately narrow: these
     * classes stay in {@see classNames()} and {@see instances()} — so every
     * interface-conformance, naming and schema test still covers them — and are
     * subtracted only by the ONE assertion whose subject is "the literal in
     * `Bootstrap::tools()` and the `src/Tools/BuiltIn/` directory agree"
     * ({@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()}).
     *
     * WHY IT HAD TO EXIST. That assertion is `assertSame(classNames(), wired)` in
     * both directions, which is exactly what caught the `Write` defect and must
     * keep doing so. But {@see McpToolBridge} wraps ONE MCP tool descriptor
     * discovered from a project's `.mcp.json`: on a tree with no such file there
     * are zero of them in `Bootstrap::tools()`, and on a tree with one there are as
     * many as the servers advertise. Neither number is a literal anybody could
     * write. Without this list the bidirectional assertion would have had to be
     * weakened to `assertContains`, which loses the direction that matters.
     *
     * KEEP IT AT THE SHORTEST LIST THAT IS TRUE. Anything in here is a tool no
     * scanned assertion can prove is reachable, so each entry needs its own
     * end-to-end reachability test naming it — for this one,
     * {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest}, which drives
     * a real call through `Runtime`.
     *
     * AND THAT RULE IS NOW THE SHAPE OF THIS CONSTANT RATHER THAN A SENTENCE
     * ABOVE IT. It was a flat list, and the rule was asserted nowhere: MEASURED,
     * adding one line here for a `Tool` that nothing wires and nothing can
     * dispatch made it pass `BuiltInToolCorpusTest`, `BinSugarcrushWiringTest`,
     * `BuiltInToolTest` and `ToolSchemaEncodingTest`, all rc=0 — an exemption that
     * granted itself. So each entry is a `class => reachability test method` pair,
     * and {@see BuiltInToolCorpusTest::testEveryDynamicToolExemptionNamesAReachabilityTestThatExists()}
     * resolves the named method by reflection. A new entry cannot be written
     * without naming the evidence, and naming evidence that does not exist reds.
     * The flat list every other consumer wants is {@see dynamicToolClasses()}.
     *
     * @var array<class-string<Tool>, string> class => `Test\Class::testMethod`
     *      that drives it end to end
     */
    public const DYNAMIC_TOOL_CLASSES = [
        McpToolBridge::class => \SugarCraft\Crush\Tests\Integration\McpToolWiringTest::class
            . '::testAModelToolCallReachesTheMcpServerAndItsAnswerReachesTheModel',
    ];

    /**
     * The exempted classes alone — {@see DYNAMIC_TOOL_CLASSES}' keys.
     *
     * Every consumer that subtracts or compares the exemption wants this and not
     * the map: `array_diff()` and `assertSame()` against a map would compare the
     * TEST METHOD NAMES, which is silently the wrong question rather than a type
     * error.
     *
     * @return list<class-string<Tool>>
     */
    public static function dynamicToolClasses(): array
    {
        return array_keys(self::DYNAMIC_TOOL_CLASSES);
    }

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
     * IT IS NO LONGER LATENT, and that sentence used to say it was: MEASURED on
     * this tree, 278 `.php` files under `src/` hold TWELVE concrete `Tool`
     * implementors, eleven in `src/Tools/BuiltIn/` and one — {@see McpToolBridge},
     * the adapter that makes a project's MCP tools dispatchable — in
     * `src/Tools/`. Under the flat glob that twelfth would have been invisible to
     * all four consumers, which is verbatim the recurrence this corpus was written
     * to prevent.
     *
     * AND THE PREDICTION THAT USED TO SIT HERE WAS WRONG, which is worth leaving
     * on the record rather than deleting. It read: "The next planned tool,
     * `src/LSP/LspTool.php` (plan step #17), lands the same way" — i.e. outside
     * the wired directory, needing the widening. It did not.
     * {@see \SugarCraft\Crush\Tools\BuiltIn\LspTool} is the ELEVENTH entry in
     * `src/Tools/BuiltIn/`, because it is a literal in `Bootstrap::tools()` and
     * {@see BuiltInToolCorpusTest::testTheWideSweepFindsTheFlatGlobPlusExactlyTheRecordedDynamicTools()}
     * asserts `assertSame(flat-glob + DYNAMIC_TOOL_CLASSES, classNames())` — so a
     * wired tool placed in `src/Tools/` and not exempted reds that assertion, and
     * exempting it is impossible because
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest::testBootstrapToolsShipsAWriteToolAndTheWholeBuiltInSet()}
     * requires every exempted class to be ABSENT from the array. Measured, not
     * reasoned: with the file at `src/Tools/LspTool.php` that first assertion
     * failed `actual size 11 matches expected size 10`. The widening is therefore
     * still guarding a LATENT case, for some future tool that a real run
     * dispatches without `Bootstrap::tools()` naming it, and `McpToolBridge`
     * remains its only live instance.
     *
     * The counterpart is {@see DYNAMIC_TOOL_CLASSES}: the widening is what puts a
     * tool outside the wired directory INTO every test, and that list is what
     * says which of them `Bootstrap::tools()` cannot name as a literal.
     *
     * THE GUARD FIX BELOW IS A PREREQUISITE FOR THAT WIDENING, not a nicety
     * beside it. Symbol kinds measured across the PRIMARY (PSR-4-named) symbol of
     * each of 267 files — the tree AS IT WAS WHEN THIS PARAGRAPH WAS MEASURED,
     * one symbol per file, which is the census's stated domain and NOT the same as
     * the 286 top-level types those 267 files declared: 220 concrete classes, 25
     * enums, 16 interfaces, 6 traits, **0 abstract classes**. The live figures
     * move with the tree and are pinned in
     * {@see BuiltInToolCorpusTest::testTheSymbolKindCensusTheDocBlockQuotes()};
     * what this paragraph is about is the ZERO, which is the whole argument and
     * has held at every size since. So the
     * one shape the old `class_exists()`-only guard classified correctly is the
     * one shape that does not occur, and the files it would have thrown on are
     * already in the tree: every interface and every trait the scan reaches. That
     * is 16 + 6 = 22 in the historical figures above and it MOVES WITH THE TREE
     * exactly as they do — it read 22 as a live count for one round after the
     * interface count had gone to 17, which is this project's standing defect in
     * miniature, so the live number is deliberately not written here;
     * {@see BuiltInToolCorpusTest::testTheSymbolKindCensusTheDocBlockQuotes()}
     * prints today's. `src/LSP/` alone ships `LspCacheInterface` and
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

            // EVERY top-level type the file declares, not just the one its
            // FILENAME names. The primary symbol is loaded by the guard above, so
            // by this line PHP has executed the file and its secondary symbols
            // are defined too — which is what makes them reflectable without a
            // second `require`.
            //
            // WHY IT IS NOT ENOUGH TO SCAN FILENAMES: the miss is SILENT. A
            // `Tool` implementor declared as a second type in a file whose
            // primary type exists is invisible to all four consumers while
            // {@see nonClassSources()} still reports `[]`, because nothing was
            // exempt — the primary really is there. `src/` ships 19 such
            // secondary declarations in 8 files today.
            foreach (self::declaredTypes($srcDir . '/' . $relative, $class) as $declared) {
                if (!self::isDispatchableTool($declared)) {
                    continue;
                }

                /** @var class-string<Tool> $declared */
                $classes[] = $declared;
            }
        }

        if ($classes === []) {
            throw new \RuntimeException("No built-in tool sources found under {$srcDir}");
        }

        sort($classes);

        return $classes;
    }

    /**
     * Is $symbol a class a real run could DISPATCH as a tool?
     *
     * The filter, and the enum clause is the fix for a doc-block that asserted
     * the OPPOSITE of what the code did. It read: "all four non-tool shapes:
     * abstract bases, interfaces (abstract, per the measurement above), traits
     * and enums (`class_exists()` answers true for an enum, and it is rejected
     * here on the interface clause)". MEASURED on PHP 8.3.6, for
     * `enum E: string implements Tool`:
     *
     *     class_exists = true   isAbstract = false   implementsInterface(Tool) = true
     *
     * — so an enum implementing `Tool` passed BOTH clauses, entered the corpus,
     * and `instances()` then died with `Error: Cannot instantiate enum E`. Inside
     * a data provider that is an uncatchable abort during suite construction,
     * which is the exact failure mode this class was refactored to remove. The
     * one dangerous shape was also the one shape untested: `testAnEnumIsSkipped`
     * probed a PLAIN enum, which the interface clause rejects for the same reason
     * it rejects any unrelated class.
     *
     * `isEnum()` rather than `enum_exists()` because the reflection object is
     * already in hand and the two answer the same question here.
     *
     * THE ENUM CLAUSE WAS ONE INSTANCE OF A CLASS OF DEFECT, not the class, and
     * the sibling it left behind is THIS REPO'S OWN MANDATED SHAPE. The filter
     * asked what a class IS and never whether it can be BUILT, while
     * {@see instances()}'s `getNumberOfRequiredParameters() === 0` arm is true of
     * a zero-argument PRIVATE constructor — so the `match` fallback never fired
     * and `new $class()` ran anyway. `CLAUDE.md` and `.claude/rules/model-pattern.md`
     * both MANDATE `final` + `private function __construct()` + a `::new()`
     * factory, so a tool written the way this codebase says to write one entered
     * the corpus and produced:
     *
     *     Error: Call to private CorpusProbe\…\ProbePrivateCtor::__construct()
     *     tests/Tools/BuiltInToolTest.php            -> Tests: 80, Errors: 33
     *     tests/Providers/ToolSchemaEncodingTest.php -> abort inside TestSuiteBuilder->build()
     *
     * — the last line verbatim the failure mode the enum clause was added to
     * remove.
     *
     * AND THE FIX FOR THAT WAS ITSELF THE NEXT INSTANCE OF THE CLASS, which is why
     * constructibility is NO LONGER ASKED HERE. The clause added was
     * `&& self::isConstructible($reflection)`, and a `false` from it SILENTLY
     * DROPPED the class: it left the corpus, so {@see instances()}'s throw — whose
     * own message says "add it … rather than silently skipping it" — could never
     * fire for the shape `CLAUDE.md` MANDATES. MEASURED on a synthetic tree, two
     * shapes vanishing with no exception and no diagnostic of any kind:
     *
     *     final class + private __construct + public static new(\stdClass $dep)
     *     final class + private __construct + public function new()   (not static)
     *
     * Both are real authoring mistakes — a factory that grew a dependency, a
     * missing `static` — and both used to mean "this tool disappears from every
     * built-in-tool test and BinSugarcrushWiringTest stays green". A filter that
     * removes a would-be tool for a reason nobody is told is worse than an abort.
     *
     * So this method asks only what a class IS: concrete, not an enum, implements
     * {@see Tool}. Whether it can be BUILT is asked by {@see instances()}, where
     * the answer is a named `RuntimeException` naming the class — see
     * {@see isConstructible()}, which still exists and is still the predicate, now
     * consulted where a `false` is LOUD.
     *
     * The cost of moving it: a class that cannot be built now reaches the corpus,
     * so any consumer that walks {@see classNames()} without building (the schema
     * and naming tests) will include it, and the failure surfaces as this
     * scanner's own throw rather than as a shorter list.
     */
    private static function isDispatchableTool(string $symbol): bool
    {
        if (!class_exists($symbol)) {
            return false;
        }

        $reflection = new \ReflectionClass($symbol);

        return !$reflection->isAbstract()
            && !$reflection->isEnum()
            && $reflection->implementsInterface(Tool::class);
    }

    /**
     * Can this class be built at all, by `new` or by the project's `::new()`
     * factory convention?
     *
     * Reflection can answer VISIBILITY and it cannot answer whether a
     * constructor THROWS — that residual is handled where it surfaces, in
     * {@see instances()}, which turns it into a named failure rather than an
     * opaque abort during suite construction.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private static function isConstructible(\ReflectionClass $reflection): bool
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->isPublic()) {
            return true;
        }

        return self::zeroArgumentFactory($reflection) !== null;
    }

    /**
     * The class's public static zero-argument `new()`, or null.
     *
     * @param  \ReflectionClass<object> $reflection
     * @return \ReflectionMethod|null
     */
    private static function zeroArgumentFactory(\ReflectionClass $reflection): ?\ReflectionMethod
    {
        if (!$reflection->hasMethod('new')) {
            return null;
        }

        $factory = $reflection->getMethod('new');

        return $factory->isPublic() && $factory->isStatic() && $factory->getNumberOfRequiredParameters() === 0
            ? $factory
            : null;
    }

    /**
     * Every top-level class/interface/trait/enum $file declares, as fully
     * qualified names, primary first.
     *
     * DERIVED FROM TOKENS, not from the filename and not from `get_declared_*()`:
     * the filename gives one name per file (the blindness this exists to remove)
     * and the declared-symbol lists are process-global and would attribute every
     * previously-loaded class to whichever file happened to be scanned first.
     *
     * $primary is the file's PSR-4 name; it is placed first so a wired tool's own
     * class keeps its position, and it is included even when the token scan
     * cannot see it (a file whose declaration is inside a conditional).
     *
     * THE BOUND, because this is an instrument and instruments here have to carry
     * their domain: a secondary type is only REFLECTABLE once its file has been
     * loaded, which happens as a side effect of the primary symbol autoloading.
     * A file whose primary symbol does NOT exist is a PSR-4 exemption, is
     * reported by {@see nonClassSources()}, and its secondary declarations are
     * named here but will not reflect. `src/` has zero such files.
     *
     * @return list<string>
     */
    public static function declaredTypes(string $file, string $primary = ''): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $namespace = '';
        $names = [];
        $depth = 0;

        for ($i = 0, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (\is_string($token)) {
                $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

                continue;
            }

            // An interpolation brace opens a scope the closing `}` will decrement.
            if (\in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                ++$depth;

                continue;
            }

            if ($token[0] === \T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $n; ++$j) {
                    if (\is_string($tokens[$j]) && \in_array($tokens[$j], [';', '{'], true)) {
                        break;
                    }
                    if (\is_array($tokens[$j])
                        && \in_array($tokens[$j][0], [\T_STRING, \T_NAME_QUALIFIED], true)
                    ) {
                        $namespace .= $tokens[$j][1];
                    }
                }

                continue;
            }

            if ($depth !== 0
                || !\in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)
            ) {
                continue;
            }

            // `Foo::class` is a constant expression, not a declaration.
            $previous = $i - 1;
            while ($previous >= 0 && \is_array($tokens[$previous])
                && \in_array($tokens[$previous][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                --$previous;
            }
            if ($previous >= 0 && \is_array($tokens[$previous]) && $tokens[$previous][0] === \T_DOUBLE_COLON) {
                continue;
            }

            $next = $i + 1;
            while ($next < $n && \is_array($tokens[$next])
                && \in_array($tokens[$next][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                ++$next;
            }
            // An anonymous class has no name token here.
            if ($next < $n && \is_array($tokens[$next]) && $tokens[$next][0] === \T_STRING) {
                $names[] = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$next][1];
            }
        }

        if ($primary !== '') {
            array_unshift($names, $primary);
        }

        return array_values(array_unique($names));
    }

    /**
     * The `src/` files that declare no symbol at their PSR-4 name.
     *
     * The visible half of {@see classNames()}'s one non-throwing skip. MEASURED
     * on this tree: 0 of 278. Pinned at zero by
     * {@see BuiltInToolCorpusTest::testEverySourceFileDeclaresItsPsr4Symbol()},
     * so a file that becomes exempt turns ONE test red with its own name in the
     * message rather than aborting the whole suite's construction.
     *
     * The "of 278" half is now load-bearing rather than decorative:
     * {@see sourceFiles()} throws on an empty tree, so an empty result here can
     * no longer mean "nothing was scanned".
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

    /**
     * Every `.php` file under $src, relative to it, sorted.
     *
     * THROWS ON AN EMPTY-BUT-EXISTING TREE, and that guard is what stops
     * {@see nonClassSources()} being vacuous. `classNames()` has always thrown on
     * an empty RESULT; `nonClassSources()` had no such guard, so pointed at an
     * existing-but-empty injected root it returned `[]` and
     * `assertSame([], $exempt)` passed having scanned nothing. "0 of N" was
     * never asserted to have looked at N files. The guard lives here rather
     * than in each caller because both of them need it and there is one place a
     * scan can come back empty.
     *
     * @return list<string>
     */
    public static function sourceFiles(?string $src = null): array
    {
        $src ??= self::srcDir();

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

        if ($files === []) {
            throw new \RuntimeException("No PHP sources found under {$src}");
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
     * THE CANONICAL SHAPE IS BUILT THROUGH ITS FACTORY. `final` + `private
     * function __construct()` + `::new()` is what `CLAUDE.md` and
     * `.claude/rules/model-pattern.md` mandate, and `new $class()` on one of
     * those is a fatal `Error` that no `catch` in a data provider can reach —
     * see {@see isDispatchableTool()} for the measurement.
     *
     * AND A CONSTRUCTOR THAT THROWS is caught, because reflection cannot see it
     * coming. It produced the same uncatchable abort inside
     * `TestSuiteBuilder->build()`; rethrown here it is a named `RuntimeException`
     * that says which class and what to do about it. It is still a HARD failure —
     * a tool that cannot be built standalone is one nobody has said how to test —
     * but a legible one.
     *
     * $srcDir/$namespacePrefix are injectable for the reason
     * {@see classNames()}'s are, and only this scanner's own tests pass them:
     * driving the private-constructor and throwing-constructor shapes needs a
     * synthetic tree, and writing probe files into the real `src/` is what left
     * residue for concurrently-running suites to read.
     *
     * @return list<Tool>
     */
    public static function instances(?string $srcDir = null, string $namespacePrefix = self::NAMESPACE_PREFIX): array
    {
        $tools = [];
        foreach (self::classNames($srcDir, $namespacePrefix) as $class) {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            try {
                // THE LOUD ARM, and it is the whole of finding F5. This question
                // used to be asked in {@see isDispatchableTool()}, where a `false`
                // removed the class from the corpus and therefore from this loop —
                // so the throw below could not fire for the shape this repo
                // mandates. A dispatchable `Tool` implementor nobody can build is
                // a defect in the tool or a gap in this fixture, and either way it
                // is something to say out loud.
                if (!self::isConstructible($reflection)) {
                    throw new \RuntimeException(sprintf(
                        '%s implements Tool but cannot be built: its constructor is not public and it offers no '
                        . 'public static zero-argument new(). Give it the `final` + `private __construct()` + '
                        . '`::new()` shape CLAUDE.md mandates, or add it to %s::instances() with the wiring it '
                        . 'needs — it must not be silently skipped.',
                        $class,
                        self::class,
                    ));
                }

                if ($constructor !== null && !$constructor->isPublic()) {
                    // Non-null by the guard above: isConstructible() reached its
                    // factory arm for this class, which is what makes it
                    // constructible at all.
                    $built = self::zeroArgumentFactory($reflection)?->invoke(null);
                } elseif ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
                    $built = new $class();
                } else {
                    $built = match ($class) {
                        SkillTool::class => new SkillTool(new SkillRegistry()),
                        // A bridge over a client with NO config file and therefore
                        // no servers: every consumer of this corpus asks about
                        // name/description/schema shape, which come from the
                        // descriptor, and the one that calls `execute([])`
                        // ({@see \SugarCraft\Crush\Tests\Tools\BuiltInToolTest})
                        // gets the error result an unreachable tool must produce
                        // rather than a spawned process. The descriptor is
                        // synthetic on purpose: a corpus probe must not depend on
                        // any MCP server being installed.
                        McpToolBridge::class => new McpToolBridge(
                            new McpClient('/nonexistent/corpus-probe/.mcp.json'),
                            new McpTool(
                                name: 'ping',
                                description: 'corpus probe descriptor',
                                inputSchema: [],
                                serverName: 'corpus',
                            ),
                        ),
                        default => throw new \RuntimeException(sprintf(
                            '%s needs constructor arguments; add it to %s::instances() so every built-in-tool test '
                            . 'covers it rather than silently skipping it.',
                            $class,
                            self::class,
                        )),
                    };
                }

                // WHAT THE FACTORY ARM ACTUALLY RETURNED, checked rather than
                // assumed. `new $class()` is guaranteed to be a `$class`; a
                // `::new()` is an ordinary static method, and reflection can see
                // that it is public, static and zero-argument without seeing its
                // return type. A factory that returns a builder, `null`, or `self`
                // of a different class used to enter this list unchallenged and
                // fail later inside whichever consumer first called a `Tool`
                // method on it — a defect reported far from its cause.
                if (!$built instanceof Tool) {
                    throw new \RuntimeException(sprintf(
                        '%s::new() returned %s rather than a Tool; %s builds the corpus through that factory, so '
                        . 'it must return the tool itself.',
                        $class,
                        get_debug_type($built),
                        self::class,
                    ));
                }

                $tools[] = $built;
            } catch (\Throwable $e) {
                if ($e instanceof \RuntimeException && str_contains($e->getMessage(), self::class)) {
                    throw $e;
                }

                throw new \RuntimeException(sprintf(
                    '%s could not be constructed standalone (%s: %s); add it to %s::instances() with the '
                    . 'wiring it needs, so every built-in-tool test covers it rather than aborting suite '
                    . 'construction.',
                    $class,
                    $e::class,
                    $e->getMessage(),
                    self::class,
                ), 0, $e);
            }
        }

        return $tools;
    }
}
