<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * {@see HomeDirectory::path()}'s "which readers are still on it" list, DERIVED.
 *
 * That list is a security argument — `path()` falls back to
 * `sys_get_temp_dir()`, mode 1777, so naming every reader still on it is how
 * the residual stays visible — and it was hand-maintained. It went wrong in
 * three directions at once, in the sentence claiming it was "every remaining
 * reader named by grep":
 *
 *  - NAMED BUT NOT A CALLER: `Workflows\WorkflowEngine`. Its only mention of
 *    `HomeDirectory::path()` is a `{@see}` cross-reference; the real caller is
 *    `Workflows\WorkflowRegistry::expandPath()`.
 *  - CALLERS NOT NAMED: `Cli\Bootstrap::homePath()`,
 *    `Workflows\WorkflowRegistry::expandPath()`, and — the one that mattered —
 *    `Memory\ForeignMemoryImporter`, whose user tier put foreign memory bodies
 *    into the model's context off this resolution. That one is now on
 *    {@see HomeDirectory::owned()}.
 *  - THE COUNT: the doc said nine where `grep` found eleven.
 *
 * So it is asserted in BOTH directions here. A name in the doc-block that
 * stops calling `path()` reds; a new caller in `src/` that nobody names reds.
 *
 * THE BOUND, since this is an instrument: it matches the literal text
 * `HomeDirectory::path()` in executable tokens, so a call reached through a
 * variable class name (`$c = HomeDirectory::class; $c::path()`) or an alias
 * would be missed. There is none in `src/` today —
 * {@see testNoCallReachesTheResolutionThroughAVariableClassName()} pins that
 * rather than assuming it.
 */
final class HomeDirectoryPathReaderInventoryTest extends TestCase
{
    /** Where the list being checked lives. */
    private const CLASS_FILE = 'src/Support/HomeDirectory.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
    }

    /**
     * EIGHT callers, in eight files, one call each — the figure the doc-block's
     * paragraph rests on. Named per-file so a dropped or added call says which
     * file it was in.
     *
     * IT WAS TEN, and the two departures are a security change rather than a
     * tidy-up. `Commands\CommandLoader` derived its USER tier's directory from
     * `path()` and passed no anchor at all, so a
     * `~/.sugar-crush/commands -> <outside>` link put outside file bodies into
     * `CommandSpec::$template` — the prompt. `Skills\ForeignSkillDiscovery`
     * derived its user tier's directory AND the anchor it was held inside from
     * `path()`, i.e. it anchored a tree to a resolution that establishes nothing;
     * its sibling `Agents\ForeignAgentPresetRegistry`, changed in the same
     * earlier commit, had used `owned()` for the same tier. Both are now on
     * {@see HomeDirectory::owned()} and both refuse the tier outright when it
     * answers null.
     */
    public function testTheCallerInventory(): void
    {
        $this->assertSame(
            [
                'src/Agents/Team.php' => 1,
                'src/Agents/TeamManager.php' => 1,
                'src/Agents/Teammate.php' => 1,
                'src/Agents/WorktreeManager.php' => 1,
                'src/Cli/Bootstrap.php' => 1,
                'src/Skills/SkillDiscovery.php' => 1,
                'src/Skills/SkillLoader.php' => 1,
                'src/Workflows/WorkflowRegistry.php' => 1,
            ],
            $this->callersPerFile(),
        );
    }

    /**
     * THE TWO READERS THAT MOVED THIS ROUND, pinned by name for the reason
     * {@see testTheForeignMemoryImporterIsOffTheStandInResolution()} pins the
     * previous one: an inventory that only counts cannot say a migration happened
     * for the right reason, and both of these were ANCHORING a containment check
     * on the stand-in resolution.
     *
     * @return array<string, array{0: string}>
     */
    public static function migratedReaders(): array
    {
        return [
            'the user commands directory, whose bodies become prompts' => ['src/Commands/CommandLoader.php'],
            'the foreign user skill trees, and their anchor' => ['src/Skills/ForeignSkillDiscovery.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('migratedReaders')]
    public function testTheMigratedReadersAreOffTheStandInResolution(string $file): void
    {
        $source = (string) file_get_contents($this->root . '/' . $file);

        $this->assertSame(0, $this->countCalls($source, 'HomeDirectory::path()'));
        $this->assertGreaterThan(0, $this->countCalls($source, 'HomeDirectory::owned()'));
    }

    /**
     * THE DERIVATION, both directions. The doc-block's `{@see}` names and the
     * files that actually call `path()` must be the SAME SET.
     *
     * `HomeDirectory` itself is excluded from both sides: it declares the
     * method, and its doc-block naturally mentions its own name.
     */
    public function testTheDocBlockNamesExactlyTheFilesThatCallIt(): void
    {
        $named = $this->namedInTheDocBlock();
        $calling = array_keys($this->callersPerFile());

        $this->assertSame(
            [],
            array_values(array_diff($named, $calling)),
            'named in the doc-block but does not call HomeDirectory::path() — this is how WorkflowEngine got in',
        );

        $this->assertSame(
            [],
            array_values(array_diff($calling, $named)),
            'calls HomeDirectory::path() and is not named in the doc-block',
        );
    }

    /**
     * The reader that MOVED, pinned by name. `Memory\ForeignMemoryImporter`
     * derived `~/.claude` from `path()`, so a `HOME` at a mode-1777 directory
     * had another local user's memory bodies imported into the store tagged
     * `source:claude` — measured `imported=1, refusedDirectories=[]`, while
     * `owned()` returned NULL for that same home.
     */
    public function testTheForeignMemoryImporterIsOffTheStandInResolution(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Memory/ForeignMemoryImporter.php');

        $this->assertSame(0, $this->countCalls($source, 'HomeDirectory::path()'));
        $this->assertGreaterThan(0, $this->countCalls($source, 'HomeDirectory::owned()'));
    }

    /**
     * THE INSTRUMENT'S OWN BLIND SPOT, measured rather than assumed: this
     * matches a literal `HomeDirectory::path()`, so a call through a variable
     * class expression would be invisible. There is none — asserted by counting
     * every `::path(` in `src/` whose subject is not the class name.
     *
     * WHAT THIS SAID, AND IT WAS TRUE OF A WALK THAT COULD NOT DO IT.
     * {@see indirectPathCalls()} reads the neighbours of a `::` by index, and
     * until 2026-08-24 the stream it read still carried `T_WHITESPACE`: this
     * copy of {@see significantTokens()} dropped comments only, while the twin
     * it was copied from — `ContainedPathInventoryTest`'s — dropped whitespace
     * as well. So `$class :: path()` had a whitespace token where the subject
     * and the method were looked for, and the scan walked past it before it
     * ever reached the `T_VARIABLE` test. The absence this test asserts was an
     * absence of the ZERO-WHITESPACE spelling alone.
     *
     * WHY THE CLAIM STILL EARNS ITS PLACE: the blind spot the paragraph names
     * is the right one to state, and the walk now really has only that one.
     * Nothing found the divergence for a full round — both files were green,
     * both helpers were private — until the drift guard's bound was widened to
     * two tokens and reported the pair. It is pinned below rather than
     * re-argued.
     */
    public function testNoCallReachesTheResolutionThroughAVariableClassName(): void
    {
        $indirect = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            $code = file_get_contents($path);
            $this->assertIsString($code, $path . ' is unreadable, so the scan over it is void');

            foreach ($this->indirectPathCalls($code) as $line) {
                $indirect[] = $relative . ':' . $line;
            }
        }

        $this->assertSame([], $indirect, 'a variable class expression this inventory cannot attribute');
    }

    /**
     * KNOWN-POSITIVE CONTROL for the absence above, and the regression fixture
     * for the whitespace divergence.
     *
     * Rule 15: `assertSame([], …)` over `src/` is satisfied perfectly by a walk
     * that has stopped working, and this one HAD stopped working for every
     * spelling with a space in it. The spaced arm is the load-bearing half —
     * restore the whitespace-keeping copy of {@see significantTokens()} and it
     * is the assertion that reds.
     */
    public function testTheIndirectCallScanSeesBothSpellingsAndSparesTheDirectOne(): void
    {
        $this->assertSame(
            [2],
            $this->indirectPathCalls("<?php
\$c::path();
"),
            'the scan misses the ordinary spelling of an indirect call',
        );

        $this->assertSame(
            [2],
            $this->indirectPathCalls("<?php
\$c :: path ();
"),
            'the scan misses an indirect call written with whitespace around the operator. That '
                . 'is what it did for a full round: this copy of significantTokens() dropped '
                . 'comments but not whitespace, so the neighbour lookup around `::` landed on a '
                . 'T_WHITESPACE and the site was never examined. Every "no indirect call" answer '
                . 'this file gives is void while that is true.',
        );

        $this->assertSame(
            [],
            $this->indirectPathCalls("<?php
HomeDirectory::path();
"),
            'the direct call this inventory is built to count was reported as an indirect one',
        );

        $this->assertSame(
            [],
            $this->indirectPathCalls("<?php
// \$c::path();
/** {@see \$c::path()} */
"),
            'a call inside a comment was counted, so the inventory counts cross-references',
        );
    }

    /**
     * The 1-indexed lines of every `$var::path()` in $code.
     *
     * @return list<int>
     */
    private function indirectPathCalls(string $code): array
    {
        $tokens = $this->significantTokens($code);
        $found = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_DOUBLE_COLON) {
                continue;
            }

            $method = $tokens[$i + 1] ?? null;
            $subject = $tokens[$i - 1] ?? null;

            if (!\is_array($method) || $method[0] !== \T_STRING || strtolower($method[1]) !== 'path') {
                continue;
            }

            if (\is_array($subject) && $subject[0] === \T_VARIABLE) {
                $found[] = $method[2];
            }
        }

        return $found;
    }

    // ─── the instrument ─────────────────────────────────────────────

    /** @return array<string, int> path relative to the lib root => calls, sorted */
    private function callersPerFile(): array
    {
        $counts = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            if ($relative === self::CLASS_FILE) {
                continue;
            }

            $n = $this->countCalls((string) file_get_contents($path), 'HomeDirectory::path()');
            if ($n > 0) {
                $counts[$relative] = $n;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The `{@see \SugarCraft\Crush\…}` class names in the doc-block of
     * {@see HomeDirectory::path()}, as `src/`-relative file paths.
     *
     * SCOPED TWICE, and the second narrowing is not fussiness. Scoping to the
     * doc-block alone still swept up the names the SAME doc-block cites as
     * counter-examples — `WorkflowEngine` ("named but does not call it"),
     * `ForeignMemoryImporter` ("now on owned()") and this test class itself — so
     * the derivation reported them as claims and failed on its own explanation.
     * A drift instrument that cannot tell a claim from a description of a
     * previous claim's failure is the false-green shape
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest} had to
     * narrow away from, one level up.
     *
     * So the LISTS are what is read: the region from the `PROMPT-BEARING`
     * heading to the paragraph that closes them. Both headings are asserted
     * present, because a rename that silently emptied the region would make this
     * test pass by finding nothing.
     *
     * @return list<string>
     */
    private function namedInTheDocBlock(): array
    {
        $method = new \ReflectionMethod(HomeDirectory::class, 'path');
        $doc = (string) $method->getDocComment();

        $this->assertNotSame('', $doc, 'path() must keep the doc-block this test checks');

        $start = strpos($doc, 'PROMPT-BEARING');
        $end = strpos($doc, 'The first group');

        $this->assertIsInt($start, 'the PROMPT-BEARING list heading');
        $this->assertIsInt($end, 'the paragraph that closes the lists');
        $this->assertStringContainsString('STORE LOCATION', $doc, 'the second list heading');

        // THE THIRD HEADING IS ASSERTED FOR THE SAME REASON THE SECOND IS, and it
        // is new: `WorkflowRegistry` was filed under STORE LOCATION with the note
        // "the fallback is a convenience, not a trust decision", which is exactly
        // false of the one directory in this package whose `.php` files are
        // `require`d. A heading that silently disappeared would take its
        // classification with it and leave the reader with the count.
        $this->assertStringContainsString('EXECUTED CONTENT', $doc, 'the third list heading');

        $lists = substr($doc, (int) $start, (int) $end - (int) $start);

        preg_match_all('#\\\\SugarCraft\\\\Crush\\\\([A-Za-z0-9_\\\\]+)#', $lists, $matches);

        $files = [];
        foreach ($matches[1] as $relativeClass) {
            $file = 'src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if ($file !== self::CLASS_FILE) {
                $files[$file] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * Occurrences of $needle in EXECUTABLE tokens only.
     *
     * Comments are dropped for the reason
     * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest} drops
     * them: a `{@see HomeDirectory::path()}` is a cross-reference and not a
     * call, and counting those is precisely how `WorkflowEngine` came to be
     * listed as a caller.
     */
    private function countCalls(string $code, string $needle): int
    {
        $executable = '';
        foreach ($this->significantTokens($code) as $token) {
            $executable .= \is_array($token) ? $token[1] : $token;
        }

        return substr_count($executable, $needle);
    }

    /** @return array<string, string> path relative to the lib root => absolute, sorted */
    private function sourceFiles(): array
    {
        $src = $this->root . '/src';
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files['src/' . substr($file->getPathname(), \strlen($src) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /** @return list<array{0: int, 1: string, 2: int}|string> */
    private function significantTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }
}
