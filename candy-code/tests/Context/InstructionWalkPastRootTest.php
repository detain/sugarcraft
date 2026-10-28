<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Context\InstructionFileLoader;

/**
 * C-CTX-4.c — two widening laws, each bounded as hard as what it widens.
 *
 *  (a) `instructions.walkToRoot` lets the ancestor walk (ancestorRoot() /
 *      loadAncestorRoots()) continue ABOVE the nearest `.git` marker — up to
 *      but never INCLUDING the home directory — under the same containment
 *      gate and the same size budget. Off (the default), the answer is
 *      byte-identical to before the flag existed.
 *  (b) `loadForced()` honours absolute and `~`-prefixed patterns ONLY from
 *      the user tier; the project tier's copy of the same spellings is
 *      refused by name. A user-tier match is anchored to the home directory
 *      the same way a checkout pattern is anchored to the checkout —
 *      `ContainedPath` judges it, so a symlink out of home is refused by path.
 *
 * Bootstrap's tier split (forcedInstructions() reading the project-free
 * config view, projectForcedInstructions() returning the residue) is pinned
 * here too: on today's settings the split can never move a pattern across.
 */
final class InstructionWalkPastRootTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/instruction_walk_past_root_test_' . uniqid((string) getmypid(), true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_link($path) ? unlink($path) : (is_dir($path) ? $this->removeDir($path) : unlink($path));
        }
        rmdir($dir);
    }

    private function touch(string $path, string $content = ''): void
    {
        file_put_contents($path, $content);
    }

    // ─── (a) the walk past the nearest marker ─────────────────────

    public function testTheWalkStopsAtTheNearestMarkerByDefault(): void
    {
        $home = $this->tempDir . '/home';
        $box = $home . '/box';
        $mono = $box . '/mono';
        $lib = $mono . '/lib';
        mkdir($lib, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($mono . '/CLAUDE.md', "MARKED-TIER\n");
        $this->touch($box . '/CLAUDE.md', "BOX-TIER\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($lib);

            $this->assertSame(realpath($mono), $loader->ancestorRoot(), 'off, the answer is unchanged — the nearest marker is the whole walk');
            $this->assertSame(["MARKED-TIER\n"], $loader->loadRoot(), 'off, no bytes arrive from above the marker');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheWalkPastTheMarkerIsHonouredAndCappedBeforeHome(): void
    {
        $home = $this->tempDir . '/home';
        $box = $home . '/box';
        $mono = $box . '/mono';
        $lib = $mono . '/lib';
        mkdir($lib, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', "BOX-TIER\n");
        $this->touch($mono . '/CLAUDE.md', "MARKED-TIER\n");
        $this->touch($home . '/CLAUDE.md', "PERSONAL-TIER-NOT-PROJECT-TIER\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($lib, walkToRoot: true);

            $this->assertSame(realpath($box), $loader->ancestorRoot(), 'the walk passes the marker and lands on the topmost level below home');
            $contents = $loader->loadRoot();
            $joined = implode('', $contents);
            $this->assertStringContainsString('BOX-TIER', $joined, 'the stray parent-workspace file is exactly what the opt-in buys');
            $this->assertStringContainsString('MARKED-TIER', $joined);
            $this->assertStringNotContainsString('PERSONAL-TIER', $joined, 'home itself is NEVER part of the walk');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheCappedWalkStartsStrictlyBelowHome(): void
    {
        $home = $this->tempDir . '/home';
        $proj = $home . '/proj';
        mkdir($proj, 0777, true);
        $this->touch($home . '/CLAUDE.md', "PERSONAL-TIER\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($proj, walkToRoot: true);

            $this->assertNull($loader->ancestorRoot(), 'with nothing between the checkout and home there is no walk to take');
            $this->assertSame([], $loader->loadRoot());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheExtendedWalkNeverLeavesTheHomeSubtree(): void
    {
        // A `.git` marker OUTSIDE home (box is a sibling of home) plus no
        // marker inside: the widened walk climbs to the topmost directory
        // strictly below home — the marker above home is never reached, and
        // home's own document is never read.
        $box = $this->tempDir . '/box';
        $home = $this->tempDir . '/home';
        $lib = $home . '/proj/lib';
        mkdir($lib, 0777, true);
        mkdir($box . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', "ENCLOSING-CHECKOUT\n");
        $this->touch($home . '/CLAUDE.md', "PERSONAL-TIER\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($lib, walkToRoot: true);

            $this->assertSame(realpath($home . '/proj'), $loader->ancestorRoot(), 'the walk ends at the topmost level below home');
            $this->assertSame([], $loader->loadRoot(), 'neither home nor the outside marker contributes a byte');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testAMarkerAlreadyAtTheTopmostHomeLevelMakesTheFlagANoOp(): void
    {
        $home = $this->tempDir . '/home';
        $mono = $home . '/mono';
        $lib = $mono . '/lib';
        mkdir($lib, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($mono . '/CLAUDE.md', "MARKED-TIER\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $default = new InstructionFileLoader($lib);
            $widened = new InstructionFileLoader($lib, walkToRoot: true);

            $this->assertSame(realpath($mono), $default->ancestorRoot(), 'default: nearest marker');
            $this->assertSame(realpath($mono), $widened->ancestorRoot(), 'widened: the topmost level below home IS the marker here — same answer');
            $this->assertSame($default->loadRoot(), $widened->loadRoot(), 'and the same bytes');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testARootThatIsHomeItselfDoesNotUnlockTheClimb(): void
    {
        // The raw `$root !== $realHome` half of the widened branch: within()
        // admits the resolve-ONTO case, so a checkout AT home would otherwise
        // start its climb from home's own parent and end ABOVE the boundary.
        $box = $this->tempDir . '/box';
        $home = $box . '/home';
        mkdir($home, 0777, true);
        mkdir($box . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', "ABOVE-HOME\n");
        $this->touch($this->tempDir . '/CLAUDE.md', "TWO-LEVELS-UP\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($home, walkToRoot: true);

            $this->assertSame(realpath($box), $loader->ancestorRoot(), 'home as the checkout falls through to the default walk — the widened climb starts strictly inside home');
            $this->assertStringNotContainsString('TWO-LEVELS-UP', implode('', $loader->loadRoot()));
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheSizeBudgetRidesTheExtendedWalkUnchanged(): void
    {
        $home = $this->tempDir . '/home';
        $box = $home . '/box';
        $mono = $box . '/mono';
        $lib = $mono . '/lib';
        mkdir($lib, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', str_repeat('x', 70_000));

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($lib, walkToRoot: true);
            $contents = $loader->loadRoot();
            $this->assertCount(1, $contents, 'the over-budget ancestor arrives as a pointer row, never as its bytes');
            $this->assertStringContainsString('Instruction file deferred: budget (70,000 bytes', $contents[0]);
            $reasons = $loader->refusedPaths();
            $this->assertArrayHasKey($box . '/CLAUDE.md', $reasons);
            $this->assertStringContainsString('61,440-byte instruction-document ceiling', $reasons[$box . '/CLAUDE.md']);
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheWalkNeverEmitsTheSameBytesTwice(): void
    {
        // The ancestor walk marks every file it emits in the shared set. A
        // symlinked alias of an ancestor file is read under its own spelled
        // path and resolves into the walk — the second emission is the one
        // that must be dropped, proving the dedup rides the extended walk.
        $home = $this->tempDir . '/home';
        $box = $home . '/box';
        $mono = $box . '/mono';
        $lib = $mono . '/lib';
        mkdir($lib, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', "BOX-TIER\n");
        symlink($box . '/CLAUDE.md', $mono . '/CLAUDE.md');

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($lib, walkToRoot: true);
            $joined = implode('', $loader->loadRoot());
            $this->assertSame(1, substr_count($joined, 'BOX-TIER'), 'the same bytes through two spellings occupy the prompt once');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    // ─── (b) forced patterns by tier ──────────────────────────────

    public function testAProjectTierAbsolutePatternIsRefusedByName(): void
    {
        // Refused whether or not the walk flag is on: the split exists so a
        // checked-out repo can never name /etc/... at all.
        $loader = new InstructionFileLoader($this->tempDir . '/repo', projectForcedInstructions: ['/etc/passwd']);
        $this->assertSame([], $loader->loadForced());
        $this->assertArrayHasKey('/etc/passwd', $loader->refusedPaths());
        $this->assertStringContainsString('absolute', $loader->refusedPaths()['/etc/passwd']);

        $flagged = new InstructionFileLoader($this->tempDir . '/repo', projectForcedInstructions: ['/etc/passwd'], walkToRoot: true);
        $this->assertSame([], $flagged->loadForced());
        $this->assertArrayHasKey('/etc/passwd', $flagged->refusedPaths(), 'walkToRoot does not touch the forced tier split');
    }

    public function testAProjectTierTildePatternIsRefusedByName(): void
    {
        $loader = new InstructionFileLoader($this->tempDir . '/repo', projectForcedInstructions: ['~/notes.md']);
        $this->assertSame([], $loader->loadForced());
        $this->assertArrayHasKey('~/notes.md', $loader->refusedPaths());
        $this->assertStringContainsString('~-prefixed', $loader->refusedPaths()['~/notes.md']);
    }

    public function testAUserTierAbsolutePatternIsHonouredUnderTheHomeAnchor(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/notes', 0777, true);
        $this->touch($home . '/notes/plain.md', "USER-ABSOLUTE\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($this->tempDir . '/repo', [$home . '/notes/plain.md']);
            $this->assertSame(["USER-ABSOLUTE\n"], $loader->loadForced(), 'an absolute pattern from the user tier loads its contained match');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testAUserTierTildePatternExpandsAgainstTheHomeAnchor(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/notes', 0777, true);
        $this->touch($home . '/notes/rules.md', "USER-TILDE\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($this->tempDir . '/repo', ['~/notes/rules.md']);
            $this->assertSame(["USER-TILDE\n"], $loader->loadForced());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testAWidcardUserTildePatternExpandsInsideHome(): void
    {
        // Wildcard concatenated (the `.`'.'` idiom): a glob-shaped string
        // literal would join the GlobDialect corpus and drift PathGlob's census.
        $home = $this->tempDir . '/home';
        mkdir($home . '/notes', 0777, true);
        $this->touch($home . '/notes/a.md', "WILD-A\n");
        $this->touch($home . '/notes/b.md', "WILD-B\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            // The `.`'.'` idiom is NOT glob-safe (the dot is an escape char
            // inside a bracket section) — chr(42) keeps the literal
            // glob-shaped-free, as InstructionFileLoaderTest's pins do.
            $loader = new InstructionFileLoader($this->tempDir . '/repo', ['~/notes/' . chr(42) . '.md']);
            $joined = implode('', $loader->loadForced());
            $this->assertStringContainsString('WILD-A', $joined);
            $this->assertStringContainsString('WILD-B', $joined);
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testAUserTierBareTildeMatchesOnlyTheAnchorRootWhichIsNotADocument(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home, 0777, true);
        $this->touch($home . '/notes.md', "NOTES-FILE-NOT-MATCHED\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($this->tempDir . '/repo', ['~']);
            $this->assertSame([], $loader->loadForced());
            $this->assertArrayNotHasKey('~', $loader->refusedPaths(), 'the home directory itself is a directory, not an instruction document — the pattern retires quietly, like any is_file miss');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testASymlinkedUserTierMatchEscapingHomeIsRefusedByPath(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home, 0777, true);
        $this->touch($this->tempDir . '/outside.md', "OUTSIDE-HOME\n");
        symlink($this->tempDir . '/outside.md', $home . '/link.md');

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $loader = new InstructionFileLoader($this->tempDir . '/repo', [$home . '/link.md']);
            $this->assertSame([], $loader->loadForced());
            $reason = $loader->refusedPaths()[$home . '/link.md'] ?? '';
            $this->assertStringContainsString('outside the user home directory', $reason, 'the home anchor is a real ContainedPath gate, not a string prefix');
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testTheForcedListKeepsUserPatternsBeforeProjectPatterns(): void
    {
        mkdir($this->tempDir . '/repo', 0777, true);
        $this->touch($this->tempDir . '/repo/user-a.md', "USER-A\n");
        $this->touch($this->tempDir . '/repo/user-b.md', "USER-B\n");
        $this->touch($this->tempDir . '/repo/proj-c.md', "PROJ-C\n");

        $loader = new InstructionFileLoader(
            $this->tempDir . '/repo',
            ['user-a.md', 'user-b.md'],
            projectForcedInstructions: ['proj-c.md'],
        );
        $this->assertSame(["USER-A\n", "USER-B\n", "PROJ-C\n"], $loader->loadForced(), 'order within each tier is preserved and the user tier is listed first — the whole forced list stays deterministic');
    }

    // ─── Bootstrap's tier split ───────────────────────────────────

    public function testTheSplitCannotMoveAPatternAcrossTiersOnTodaySConfiguration(): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home . '/.candy-code', 0777, true);
        $this->touch($home . '/.candy-code/config.json', json_encode([
            'instructions' => ['docs/*.md', 'RULES.md'],
        ]));

        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        try {
            $this->assertSame(['docs/*.md', 'RULES.md'], Bootstrap::forcedInstructions());
            $this->assertSame([], Bootstrap::projectForcedInstructions());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }
}
