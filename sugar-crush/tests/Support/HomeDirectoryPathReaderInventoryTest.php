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
     * TEN callers, in ten files, one call each — the figure the doc-block's
     * paragraph rests on. Named per-file so a dropped or added call says which
     * file it was in.
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
                'src/Commands/CommandLoader.php' => 1,
                'src/Skills/ForeignSkillDiscovery.php' => 1,
                'src/Skills/SkillDiscovery.php' => 1,
                'src/Skills/SkillLoader.php' => 1,
                'src/Workflows/WorkflowRegistry.php' => 1,
            ],
            $this->callersPerFile(),
        );
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
     */
    public function testNoCallReachesTheResolutionThroughAVariableClassName(): void
    {
        $indirect = [];

        foreach ($this->sourceFiles() as $relative => $path) {
            $tokens = $this->significantTokens((string) file_get_contents($path));

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
                    $indirect[] = "{$relative}:{$method[2]}";
                }
            }
        }

        $this->assertSame([], $indirect, 'a variable class expression this inventory cannot attribute');
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
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }
}
