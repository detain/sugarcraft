<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * {@see ContainedPath}'s class doc-block inventory, DERIVED instead of trusted.
 *
 * The inventory is a security argument — "one implementation, and here is every
 * place that does not use it" — and it was hand-maintained across three rounds,
 * drifting each time. Every figure it quotes is measured here, per file, so the
 * prose cannot outlive the tree.
 *
 * THE BOUND ON WHAT THIS BUYS, because overstating it is the exact defect that let
 * finding #89 through six reviews: this counts the compares that are WRITTEN. It
 * catches a routed check being DELETED (a per-file count drops) and it catches a
 * hand-spelling being ADDED (a per-file count rises). It cannot catch a read path
 * that never had a compare, which is what `InstructionFileLoader::loadRoot()` and
 * `loadForPath()` were — reported as audited on this file's own inventory while
 * returning the body of a file symlinked out of the checkout. Only reading the
 * read paths finds those; see
 * {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest}.
 */
final class ContainedPathInventoryTest extends TestCase
{
    /**
     * Executable lines only — a doc-comment `{@see ContainedPath::within()}` is a
     * cross-reference, not a call site, and counting those is what inflated an
     * earlier hand count.
     */
    private const ROUTED = '#ContainedPath::(within|below)\(#';

    /**
     * A path-against-boundary prefix compare, and the ` . '/'` is what makes it
     * one: `str_starts_with($path, '/')` is an absolute-path test, not a
     * containment test, and there are many of those. `.*` rather than `[^)]*`
     * because this class's OWN compare has a nested call in its first argument
     * (`rtrim($realBoundary, '/')`), and a pattern that could not match the one
     * implementation would be an odd instrument for counting the others.
     */
    private const HAND_SPELLED = "#str_starts_with\\(.*\\. '/'\\)#";

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
    }

    /**
     * "FIFTEEN call sites in FIVE files", per file. Each count is one read
     * decision, so a dropped gate shows up as the file's number falling — which
     * is the half of #89 an instrument like this genuinely covers.
     */
    public function testTheRoutedCallSiteInventory(): void
    {
        $this->assertSame(
            [
                'Agents/AgentPresetRegistry.php' => 3,
                'Commands/CommandLoader.php' => 2,
                'Context/InstructionFileLoader.php' => 5,
                'Skills/SkillLoader.php' => 3,
                'Workflows/WorkflowRegistry.php' => 2,
            ],
            $this->countPerFile(self::ROUTED, skip: 'Support/ContainedPath.php'),
        );
    }

    /**
     * "EIGHT spellings remain by hand, in FOUR files" — plus the two the
     * inventory deliberately EXCLUDES, named here so the exclusion is a recorded
     * decision rather than a hole. `WorktreeManager`'s pair matches relative paths
     * against a glob directory; it is not a boundary compare.
     */
    public function testTheHandSpelledInventoryIncludingItsStatedExclusion(): void
    {
        $counts = $this->countPerFile(self::HAND_SPELLED, skip: 'Support/ContainedPath.php');

        $this->assertSame(
            [
                'Agents/WorktreeManager.php' => 2,
                'Hooks/BuiltIn/BashEscapeDenyHook.php' => 1,
                'Tools/BuiltIn/Glob.php' => 1,
                'Tools/IgnoreRules.php' => 1,
                'Tools/PathJail.php' => 5,
            ],
            $counts,
        );

        unset($counts['Agents/WorktreeManager.php']);
        $this->assertSame(8, array_sum($counts), 'containment spellings still by hand');
        $this->assertCount(4, $counts, 'files still holding one');
    }

    /**
     * `ContainedPath` itself holds exactly ONE prefix compare. That is the whole
     * point of the class, and it is the number that must never rise.
     */
    public function testTheClassItselfHoldsExactlyOneCompare(): void
    {
        $this->assertSame(
            1,
            $this->countIn($this->srcDir . '/Support/ContainedPath.php', self::HAND_SPELLED),
        );
    }

    /**
     * The instrument, checked against a case it must NOT count: an absolute-path
     * test is not a containment test. Without this, a regex that quietly stopped
     * matching would make both inventories read as zero-drift.
     */
    public function testTheInstrumentDistinguishesContainmentFromAnAbsolutePathTest(): void
    {
        $this->assertSame(1, preg_match_all(self::HAND_SPELLED, "str_starts_with(\$real, \$rootReal . '/')"));
        $this->assertSame(1, preg_match_all(self::HAND_SPELLED, "str_starts_with(\$p, \$this->root . '/')"));
        $this->assertSame(0, preg_match_all(self::HAND_SPELLED, "str_starts_with(\$path, '/')"));
        $this->assertSame(0, preg_match_all(self::HAND_SPELLED, "str_starts_with(\$token, '-')"));

        $this->assertSame(1, preg_match_all(self::ROUTED, 'if (!ContainedPath::within($a, $b)) {'));
        $this->assertSame(1, preg_match_all(self::ROUTED, 'ContainedPath::below($dir, $anchor)'));
    }

    /**
     * The measured divergence behind the corrected `BashEscapeDenyHook` entry:
     * this class refuses a path it cannot resolve, and a file about to be CREATED
     * does not resolve. `false` therefore fails CLOSED at a `!within(...)` deny
     * site — the opposite of what the old entry claimed — and the real cost of
     * consolidating that hook is over-denial.
     */
    public function testAnUnresolvablePathIsRefusedWhichIsWhyTheDenyHookStaysHandSpelled(): void
    {
        $root = sys_get_temp_dir() . '/contained_path_inventory_' . uniqid();
        mkdir($root . '/sub', 0o777, true);

        try {
            $real = (string) realpath($root);

            // The case the old entry cited as inverting into an allow.
            $this->assertFalse(ContainedPath::within('/nonexistent', $real));

            // The case that actually diverges: in-root, lexically fine, not there yet.
            $this->assertFalse(ContainedPath::within($real . '/newfile.txt', $real));

            // The control: the same path once it exists.
            file_put_contents($real . '/newfile.txt', '');
            $this->assertTrue(ContainedPath::within($real . '/newfile.txt', $real));
        } finally {
            @unlink($root . '/newfile.txt');
            @rmdir($root . '/sub');
            @rmdir($root);
        }
    }

    /**
     * @return array<string, int> path relative to `src/` => matches, sorted by key
     */
    private function countPerFile(string $pattern, string $skip): array
    {
        $counts = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($this->srcDir) + 1);
            if ($relative === $skip) {
                continue;
            }

            $n = $this->countIn($file->getPathname(), $pattern);
            if ($n > 0) {
                $counts[$relative] = $n;
            }
        }

        ksort($counts);

        return $counts;
    }

    private function countIn(string $path, string $pattern): int
    {
        $n = 0;
        foreach ((array) file($path) as $line) {
            $trimmed = ltrim((string) $line);
            // Doc-comment and comment lines are cross-references, not decisions.
            if ($trimmed === '' || $trimmed[0] === '*' || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*')
            ) {
                continue;
            }

            $n += preg_match_all($pattern, (string) $line);
        }

        return $n;
    }
}
