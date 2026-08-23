<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;

/**
 * Tests for InstructionFileLoader, covering root loading, forced patterns,
 * and nested per-path instruction file injection.
 */
final class InstructionFileLoaderTest extends TestCase
{
    private string $tempDir;
    private string $repoRoot;

    protected function setUp(): void
    {
        // Create a temporary directory structure for testing
        $this->tempDir = sys_get_temp_dir() . '/instruction_file_loader_test_' . uniqid((string) getmypid(), true);
        $this->repoRoot = $this->tempDir . '/repo';
        mkdir($this->repoRoot . '/candy-shine/src', 0777, true);
        mkdir($this->repoRoot . '/candy-shine/lang', 0777, true);
        mkdir($this->repoRoot . '/sugar-bits/src', 0777, true);
        mkdir($this->repoRoot . '/docs', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function touch(string $path, string $content = ''): void
    {
        file_put_contents($path, $content);
    }

    // ─── loadRoot() tests ─────────────────────────────────────────

    public function testRootFileAlwaysLoaded(): void
    {
        // Create root CLAUDE.md and AGENTS.md
        $this->touch($this->repoRoot . '/CLAUDE.md', '# Root CLAUDE');
        $this->touch($this->repoRoot . '/AGENTS.md', '# Root AGENTS');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertCount(2, $contents);
        $this->assertStringContainsString('Root CLAUDE', $contents[0]);
        $this->assertStringContainsString('Root AGENTS', $contents[1]);
    }

    public function testLoadRootSkipsMissingFiles(): void
    {
        // Create only CLAUDE.md, not AGENTS.md
        $this->touch($this->repoRoot . '/CLAUDE.md', '# Root CLAUDE only');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertCount(1, $contents);
        $this->assertStringContainsString('Root CLAUDE only', $contents[0]);
    }

    public function testLoadRootReturnsEmptyWhenNoFiles(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertCount(0, $contents);
    }

    // ─── loadForced() tests ────────────────────────────────────────

    public function testForcedInstructionsResolveGlobs(): void
    {
        // Create CALIBER_LEARNINGS.md files in multiple candy-* libs
        mkdir($this->repoRoot . '/candy-sprinkles', 0777, true);
        $this->touch($this->repoRoot . '/candy-shine/CALIBER_LEARNINGS.md', '# Candy Shine Learnings');
        $this->touch($this->repoRoot . '/candy-sprinkles/CALIBER_LEARNINGS.md', '# Candy Sprinkles Learnings');

        $loader = new InstructionFileLoader($this->repoRoot, ['candy-*/CALIBER_LEARNINGS.md']);
        $contents = $loader->loadForced();

        $this->assertCount(2, $contents);
        $this->assertStringContainsString('Candy Shine Learnings', $contents[0]);
        $this->assertStringContainsString('Candy Sprinkles Learnings', $contents[1]);
    }

    public function testForcedInstructionsRejectsAbsolutePaths(): void
    {
        // Absolute paths should be ignored for security
        $this->touch('/tmp/should_not_load.md', 'should not load');

        $loader = new InstructionFileLoader($this->repoRoot, ['/should_not_load.md']);
        $contents = $loader->loadForced();

        $this->assertCount(0, $contents);
    }

    public function testForcedInstructionsReturnsEmptyWhenNoMatches(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot, ['nonexistent/**/*.md']);
        $contents = $loader->loadForced();

        $this->assertCount(0, $contents);
    }

    public function testForcedInstructionsRejectsRelativePatternTraversingOutOfRepo(): void
    {
        // "/"-prefixed patterns are rejected by an early str_starts_with()
        // check, but a RELATIVE pattern full of ".." concatenates onto
        // repoRoot and globs outside the repository -- and loadForced()
        // output goes verbatim into the model's system prompt.
        $this->touch($this->tempDir . '/secret.md', 'TOP SECRET FORCED CONTENT');

        $loader = new InstructionFileLoader($this->repoRoot, ['../secret.md']);
        $contents = $loader->loadForced();

        $this->assertSame([], $contents);
    }

    // ─── memoization ───────────────────────────────────────────────

    public function testLoadRootIsMemoizedForTheLifetimeOfTheLoader(): void
    {
        // buildSystemPrompt() calls loadRoot() once per agentic step, so the
        // second call must not touch disk. Rewriting the file underneath and
        // asserting the FIRST content comes back both proves memoization and
        // pins the deliberate mid-session-staleness trade-off.
        $this->touch($this->repoRoot . '/AGENTS.md', '# FIRST READ');

        $loader = new InstructionFileLoader($this->repoRoot);
        $first = $loader->loadRoot();

        $this->touch($this->repoRoot . '/AGENTS.md', '# SECOND READ');
        $second = $loader->loadRoot();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('FIRST READ', $second[0]);
        $this->assertStringNotContainsString('SECOND READ', $second[0]);
    }

    public function testLoadRootCachesTheEmptyResultInsteadOfRescanning(): void
    {
        // The no-root-files case caches [] -- a null-vs-empty confusion here
        // would silently re-scan disk every step.
        $loader = new InstructionFileLoader($this->repoRoot);
        $this->assertSame([], $loader->loadRoot());

        $this->touch($this->repoRoot . '/CLAUDE.md', '# APPEARED LATER');

        $this->assertSame([], $loader->loadRoot());
    }

    public function testLoadForcedIsMemoizedForTheLifetimeOfTheLoader(): void
    {
        $this->touch($this->repoRoot . '/candy-shine/CALIBER_LEARNINGS.md', '# FIRST FORCED');

        $loader = new InstructionFileLoader($this->repoRoot, ['candy-*/CALIBER_LEARNINGS.md']);
        $first = $loader->loadForced();

        $this->touch($this->repoRoot . '/candy-shine/CALIBER_LEARNINGS.md', '# SECOND FORCED');
        $second = $loader->loadForced();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('FIRST FORCED', $second[0]);
        $this->assertStringNotContainsString('SECOND FORCED', $second[0]);
    }

    public function testLoadForcedCachesTheEmptyPatternListResult(): void
    {
        // Exercises the `return $this->forcedCache = []` early-out branch --
        // the shape Bootstrap builds today, since forcedInstructions is not
        // sourced from user config until W1.B4.
        $this->touch($this->repoRoot . '/candy-shine/CALIBER_LEARNINGS.md', '# NEVER FORCED');

        $loader = new InstructionFileLoader($this->repoRoot);

        $this->assertSame([], $loader->loadForced());
        $this->assertSame([], $loader->loadForced());
    }

    // ─── loadForPath() tests ───────────────────────────────────────

    public function testNestedFileInjectedOnFirstTouch(): void
    {
        // Create a nested CLAUDE.md in candy-shine/
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# Candy Shine Instructions');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);

        // Touch a file in candy-shine/src/
        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Candy Shine Instructions', $result);
    }

    public function testNestedFilePrefersClaudeOverAgents(): void
    {
        // Create both CLAUDE.md and AGENTS.md at same level
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# CLAUDE wins');
        $this->touch($this->repoRoot . '/candy-shine/AGENTS.md', '# AGENTS loses');

        $loader = new InstructionFileLoader($this->repoRoot);

        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNotNull($result);
        $this->assertStringContainsString('CLAUDE wins', $result);
        $this->assertStringNotContainsString('AGENTS loses', $result);
    }

    public function testNestedFileNotReinjected(): void
    {
        // Create nested CLAUDE.md
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# Candy Shine Instructions');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);

        // First touch - should load the file
        $result1 = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');
        $this->assertNotNull($result1);
        $this->assertStringContainsString('Candy Shine Instructions', $result1);

        // Second touch of different file in same lib - should return null (already injected)
        $this->touch($this->repoRoot . '/candy-shine/src/Another.php', '<?php // another');
        $result2 = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Another.php');
        $this->assertNull($result2);
    }

    public function testLoadForPathStopsAtRepoRoot(): void
    {
        // Create root CLAUDE.md and AGENTS.md
        $this->touch($this->repoRoot . '/CLAUDE.md', '# Root CLAUDE');
        $this->touch($this->repoRoot . '/AGENTS.md', '# Root AGENTS');
        // Create a nested file
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# Nested');

        $loader = new InstructionFileLoader($this->repoRoot);

        // Touch a deeply nested file
        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        // Should find the nested CLAUDE.md, NOT the root one
        $this->assertNotNull($result);
        $this->assertStringContainsString('Nested', $result);
        $this->assertStringNotContainsString('Root CLAUDE', $result);
    }

    public function testLoadForPathReturnsNullWhenNoNestedFile(): void
    {
        // No nested CLAUDE.md or AGENTS.md anywhere
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);

        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNull($result);
    }

    public function testLoadForPathWalksUpDirectoryTree(): void
    {
        // Create CLAUDE.md only at candy-shine/ level (not in src/)
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# Candy Shine at lib level');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);

        // Touch a deeply nested file - should find CLAUDE.md by walking up
        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Candy Shine at lib level', $result);
    }

    public function testLoadForPathWithFreshLoader(): void
    {
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', '# Instructions');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        // First loader instance - should load
        $loader1 = new InstructionFileLoader($this->repoRoot);
        $result1 = $loader1->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');
        $this->assertNotNull($result1);

        // Second loader instance (simulating fresh session) - should load again
        $loader2 = new InstructionFileLoader($this->repoRoot);
        $result2 = $loader2->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');
        $this->assertNotNull($result2);
    }

    public function testLoadForPathHandlesFilesystemErrorsGracefully(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);

        // Pass a path that doesn't exist - should return null, not throw
        $result = $loader->loadForPath('/nonexistent/path/file.php');

        $this->assertNull($result);
    }

    // ─── @-import wiring (ImportResolver) ──────────────────────────

    public function testLoadRootExpandsAtImportReference(): void
    {
        // Mirrors this repo's own root CLAUDE.md, which uses "@./AGENTS.md".
        // Against the pre-wiring code this reference is never expanded, so
        // asserting the imported body is present fails on the old loader.
        $this->touch($this->repoRoot . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        $this->touch($this->repoRoot . '/AGENTS.md', 'AGENTS BODY TEXT');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertStringContainsString('AGENTS BODY TEXT', $contents[0]);
        $this->assertStringNotContainsString('@./AGENTS.md', $contents[0]);
    }

    public function testLoadRootBlocksImportEscapingRepoRoot(): void
    {
        // A reference that resolves outside repoRoot must never be followed
        // silently -- a naive "just call ImportResolver::expand()" wiring
        // would leak the secret file's content into the system prompt.
        $this->touch($this->tempDir . '/secret.md', 'TOP SECRET CONTENT');
        $this->touch($this->repoRoot . '/CLAUDE.md', 'See @../secret.md here.');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertStringNotContainsString('TOP SECRET CONTENT', $contents[0]);
        $this->assertStringContainsString('import-blocked', $contents[0]);
        $this->assertStringContainsString('outside the repository root', $contents[0]);
    }

    public function testLoadRootDoesNotBlockOrExpandFencedImportReference(): void
    {
        // A reference sitting in an example code fence must stay untouched --
        // neither expanded nor rewritten into a warning tag.
        $this->touch($this->tempDir . '/secret.md', 'TOP SECRET CONTENT');
        $this->touch(
            $this->repoRoot . '/CLAUDE.md',
            "Example:\n```\nSee @../secret.md here.\n```\nEnd.",
        );

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertStringContainsString('@../secret.md', $contents[0]);
        $this->assertStringNotContainsString('import-blocked', $contents[0]);
        $this->assertStringNotContainsString('TOP SECRET CONTENT', $contents[0]);
    }

    public function testLoadForPathExpandsNestedImportReference(): void
    {
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', 'Nested @./LOCAL.md');
        $this->touch($this->repoRoot . '/candy-shine/LOCAL.md', 'LOCAL IMPORTED BODY');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNotNull($result);
        $this->assertStringContainsString('LOCAL IMPORTED BODY', $result);
        $this->assertStringNotContainsString('@./LOCAL.md', $result);
    }

    public function testLoadRootBlocksTwoHopImportEscapingRepoRoot(): void
    {
        // First hop (@local.md) resolves INSIDE repoRoot and passes the
        // boundary check; the escape only appears once ImportResolver
        // recurses into local.md's own content. A boundary check that only
        // scans the outermost $content (rather than being threaded through
        // ImportResolver's recursion) would miss this and leak the secret.
        $this->touch($this->tempDir . '/secret.md', 'TOP SECRET CONTENT LEAK');
        $this->touch($this->repoRoot . '/local.md', 'Nested ref: @../secret.md');
        $this->touch($this->repoRoot . '/CLAUDE.md', 'Root: @local.md');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertStringNotContainsString('TOP SECRET CONTENT LEAK', $contents[0]);
        $this->assertStringContainsString('import-blocked', $contents[0]);
        $this->assertStringContainsString('outside the repository root', $contents[0]);
    }

    // ─── de-duplication across emission routes ─────────────────────

    public function testLoadRootDoesNotAlsoEmitAnAgentsMdAlreadyInlinedByClaudeMd(): void
    {
        // This repo's own root shape. CLAUDE.md inlines AGENTS.md via the
        // import, so emitting AGENTS.md as a second document would put the
        // same bytes in the context window twice on every turn.
        $this->touch($this->repoRoot . '/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        $this->touch($this->repoRoot . '/AGENTS.md', 'AGENTS BODY MARKER');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertCount(1, $contents);
        $this->assertSame(1, substr_count(implode("\n", $contents), 'AGENTS BODY MARKER'));
    }

    public function testLoadRootReplacesARepeatedImportWithASkipNote(): void
    {
        // Two references to the same file inside one document: the first is
        // expanded, the second collapses to a note rather than a second copy.
        $this->touch($this->repoRoot . '/shared.md', 'SHARED BODY MARKER');
        $this->touch($this->repoRoot . '/CLAUDE.md', "One @shared.md\nTwo @shared.md\n");

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertSame(1, substr_count($contents[0], 'SHARED BODY MARKER'));
        $this->assertStringContainsString('import-skipped', $contents[0]);
        $this->assertStringContainsString('already', $contents[0]);
    }

    public function testLoadForcedSkipsAMatchAlreadyEmittedByLoadRoot(): void
    {
        // Runtime::buildSystemPrompt() drains loadRoot() before loadForced(),
        // so root keeps the slot and the forced glob adds nothing.
        $this->touch($this->repoRoot . '/AGENTS.md', 'ROOT AND FORCED MARKER');

        $loader = new InstructionFileLoader($this->repoRoot, ['AGENTS.md']);

        $this->assertCount(1, $loader->loadRoot());
        $this->assertSame([], $loader->loadForced());
    }

    public function testLoadForPathSkipsANestedFileTheRootImportAlreadyInlined(): void
    {
        // Root CLAUDE.md pulls the nested file in up front; touching a file in
        // that subtree later must not re-inject the identical content.
        $this->touch($this->repoRoot . '/CLAUDE.md', 'Root @candy-shine/CLAUDE.md');
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', 'NESTED BODY MARKER');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();
        $this->assertStringContainsString('NESTED BODY MARKER', $contents[0]);

        $this->assertNull($loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php'));
    }

    public function testLoadRootHandlesSelfImportingFileWithoutRepeatingIt(): void
    {
        // A file importing itself would otherwise recurse to ImportResolver's
        // depth cap, stamping four nested copies of its own body.
        $this->touch($this->repoRoot . '/CLAUDE.md', "SELF BODY MARKER\n@./CLAUDE.md\n");

        $loader = new InstructionFileLoader($this->repoRoot);
        $contents = $loader->loadRoot();

        $this->assertSame(1, substr_count($contents[0], 'SELF BODY MARKER'));
        $this->assertStringContainsString('import-skipped', $contents[0]);
    }

    public function testLoadForPathBlocksNestedImportEscapingRepoRoot(): void
    {
        // From candy-shine/CLAUDE.md, "../../secret.md" resolves two levels
        // up (past repoRoot) into $tempDir -- outside the repo boundary.
        $this->touch($this->tempDir . '/secret.md', 'TOP SECRET CONTENT');
        $this->touch($this->repoRoot . '/candy-shine/CLAUDE.md', 'Ref @../../secret.md');
        $this->touch($this->repoRoot . '/candy-shine/src/Component.php', '<?php // component');

        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadForPath($this->repoRoot . '/candy-shine/src/Component.php');

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('TOP SECRET CONTENT', $result);
        $this->assertStringContainsString('import-blocked', $result);
    }

    /**
     * The emit-once mark crosses a fork as
     * {@see InstructionFileLoader::emittedPaths()} out and
     * {@see InstructionFileLoader::markEmitted()} back in, so it has to
     * round-trip a key PHP would coerce to `int` — the same defect
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge} really does suffer.
     * Unreachable here (these are realpaths, so they start with `/`), asserted
     * so the two halves of one interface cannot drift apart.
     */
    public function testTheEmittedSetRoundTripsANumericKey(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->markEmitted(['123']);

        $this->assertSame(['123'], $loader->emittedPaths());

        $other = new InstructionFileLoader($this->repoRoot);
        $other->markEmitted($loader->emittedPaths());

        $this->assertSame(['123'], $other->emittedPaths());
    }

    public function testMarkEmittedAcceptsAnIntegerFromAnOlderPayload(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $loader->markEmitted([123]);

        $this->assertSame(['123'], $loader->emittedPaths());
    }

    // ─── P8.11: monorepo-parent awareness ───────────────────────────

    /**
     * Build `<temp>/mono` as a checkout with `<temp>/mono/<lib>` inside it.
     *
     * `$this->repoRoot` from setUp() is deliberately NOT reused: it has no
     * `.git` and nothing above it does either, which is exactly the shape every
     * pre-existing test in this class depends on staying inert.
     *
     * @return array{0: string, 1: string} [monorepo root, library dir]
     */
    private function makeMonorepo(string $lib = 'candy-lib', bool $libIsOwnCheckout = false): array
    {
        $mono = $this->tempDir . '/mono';
        $libDir = $mono . '/' . $lib;
        mkdir($libDir . '/src', 0777, true);
        // A `.git` DIRECTORY, the shape a plain clone has. ancestorRoot() uses
        // file_exists() so a worktree/submodule `.git` FILE stops it too; that
        // variant is covered by testALibraryWhoseGitIsAFile...().
        mkdir($mono . '/.git', 0777, true);
        if ($libIsOwnCheckout) {
            mkdir($libDir . '/.git', 0777, true);
        }

        return [$mono, $libDir];
    }

    /**
     * THE HEADLINE. Before this change `loadRoot()` consulted `$repoRoot` and
     * nothing else, so `--root <mono>/<lib>` returned an EMPTY document list and
     * recorded nothing anywhere — not even a refusal, because a file that is
     * never a candidate is never refused.
     */
    public function testPointingTheRootAtOneLibraryStillDeliversTheMonorepoInstructions(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "MONOREPO-CONVENTIONS\n");
        $this->touch($mono . '/AGENTS.md', "MONOREPO-PLAYBOOK\n");

        $contents = (new InstructionFileLoader($libDir))->loadRoot();

        $this->assertSame(["MONOREPO-CONVENTIONS\n", "MONOREPO-PLAYBOOK\n"], $contents);
    }

    /**
     * RULE 2, and a TRUTH test rather than a presence one: an implementation
     * that simply walked up to the nearest `.git` above `$repoRoot` would pass
     * every assertion in the test above and FAIL here, because a library that is
     * its own working tree has no monorepo parent and its parent directory is
     * somebody else's filesystem.
     */
    public function testALibraryThatIsItsOwnCheckoutReadsNothingAboveItself(): void
    {
        [$mono, $libDir] = $this->makeMonorepo(libIsOwnCheckout: true);
        $this->touch($mono . '/CLAUDE.md', "MONOREPO-CONVENTIONS\n");

        $loader = new InstructionFileLoader($libDir);

        $this->assertNull($loader->ancestorRoot());
        $this->assertSame([], $loader->loadRoot());
    }

    /** A worktree and a submodule both spell `.git` as a FILE, not a directory. */
    public function testALibraryWhoseGitIsAFileIsAlsoItsOwnCheckout(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "MONOREPO-CONVENTIONS\n");
        $this->touch($libDir . '/.git', "gitdir: /elsewhere/.git/worktrees/lib\n");

        $loader = new InstructionFileLoader($libDir);

        $this->assertNull($loader->ancestorRoot());
        $this->assertSame([], $loader->loadRoot());
    }

    /**
     * RULE 3 — the containment guarantee. With no VCS marker above, the walk
     * must yield nothing rather than climb toward `/` reading whatever
     * `CLAUDE.md` it passes. The ancestor file here is deliberately present and
     * deliberately not read.
     */
    public function testWithNoGitMarkerAboveNothingIsReadHoweverManyAncestorsCarryInstructions(): void
    {
        $plainLib = $this->tempDir . '/plain/lib';
        mkdir($plainLib, 0777, true);
        $this->touch($this->tempDir . '/plain/CLAUDE.md', "SHOULD-NEVER-BE-READ\n");
        $this->touch($this->tempDir . '/CLAUDE.md', "ALSO-NEVER\n");

        $loader = new InstructionFileLoader($plainLib);

        $this->assertNull($loader->ancestorRoot());
        $this->assertSame([], $loader->loadRoot());
        $this->assertSame([], $loader->refusedPaths());
    }

    /**
     * RULE 4. A home directory under version control is a dotfiles repo, and
     * adopting its `CLAUDE.md` as the PROJECT tier would import one repository's
     * instructions into every unrelated project living inside it.
     */
    public function testAHomeDirectoryUnderVersionControlIsNotAMonorepoRoot(): void
    {
        $home = $this->tempDir . '/home';
        $project = $home . '/projects/thing';
        mkdir($project, 0777, true);
        mkdir($home . '/.git', 0777, true);
        $this->touch($home . '/CLAUDE.md', "DOTFILES-NOT-A-PROJECT\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $loader = new InstructionFileLoader($project);

            $this->assertNull($loader->ancestorRoot());
            $this->assertSame([], $loader->loadRoot());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    /**
     * The same fixture with the marker one level DOWN from home is read — which
     * is what makes the test above a statement about home specifically and not
     * about the walk being broken.
     */
    public function testAProjectCheckoutInsideHomeIsStillAMonorepoRoot(): void
    {
        $home = $this->tempDir . '/home';
        $mono = $home . '/projects/mono';
        $libDir = $mono . '/lib';
        mkdir($libDir, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($mono . '/CLAUDE.md', "REAL-PROJECT\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $loader = new InstructionFileLoader($libDir);

            $this->assertSame(realpath($mono), $loader->ancestorRoot());
            $this->assertSame(["REAL-PROJECT\n"], $loader->loadRoot());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    /**
     * The INTERMEDIATE directory is the one most likely to matter — a
     * `packages/web/CLAUDE.md` between the monorepo root and the app — and
     * reading only the two ends would skip exactly it.
     *
     * Order is asserted as a whole list, not with three contains-checks: general
     * before specific is the property, and a contains-check cannot see order.
     */
    public function testEveryDirectoryBetweenTheMonorepoRootAndTheRootIsReadOutermostFirst(): void
    {
        $mono = $this->tempDir . '/mono';
        $app = $mono . '/packages/web/app';
        mkdir($app, 0777, true);
        mkdir($mono . '/.git', 0777, true);
        $this->touch($mono . '/CLAUDE.md', "L0-MONO\n");
        $this->touch($mono . '/packages/CLAUDE.md', "L1-PACKAGES\n");
        $this->touch($mono . '/packages/web/CLAUDE.md', "L2-WEB\n");
        $this->touch($app . '/CLAUDE.md', "L3-APP-THE-ROOT\n");

        $this->assertSame(
            ["L0-MONO\n", "L1-PACKAGES\n", "L2-WEB\n", "L3-APP-THE-ROOT\n"],
            (new InstructionFileLoader($app))->loadRoot(),
        );
    }

    /**
     * A per-library `CLAUDE.md` symlinked to the monorepo's shared one is the
     * layout the class docblock calls natural. The link is still refused by the
     * `$repoRoot` gate — which is unchanged and deliberately so — but the bytes
     * now arrive ONCE from the ancestor pass instead of not at all, and the
     * realpath dedup is what stops them arriving twice.
     */
    public function testASymlinkedPerLibraryFileIsDedupedAgainstTheAncestorCopy(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "SHARED-BODY\n");
        if (!@symlink($mono . '/CLAUDE.md', $libDir . '/CLAUDE.md')) {
            $this->markTestSkipped('symlinks are unavailable in this environment');
        }

        $loader = new InstructionFileLoader($libDir);

        $this->assertSame(["SHARED-BODY\n"], $loader->loadRoot());
        $this->assertArrayHasKey($libDir . '/CLAUDE.md', $loader->refusedPaths());
    }

    /**
     * The new gate is a real gate: an ancestor file that resolves OUT of the
     * enclosing checkout is refused and recorded, so the ancestor pass does not
     * reintroduce the silent-escape shape the rest of this class closed.
     */
    public function testAnAncestorFileResolvingOutsideTheEnclosingCheckoutIsRefusedAndRecorded(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0777, true);
        $this->touch($outside . '/secret.md', "TOP-SECRET\n");
        if (!@symlink($outside . '/secret.md', $mono . '/CLAUDE.md')) {
            $this->markTestSkipped('symlinks are unavailable in this environment');
        }

        $loader = new InstructionFileLoader($libDir);

        $this->assertSame([], $loader->loadRoot());
        $this->assertStringContainsString(
            'outside the enclosing checkout',
            $loader->refusedPaths()[$mono . '/CLAUDE.md'] ?? '',
        );
    }

    /**
     * The bug the FIRST cut of this feature shipped, kept as a regression test.
     * The gate handed to ImportResolver was bounded by `$repoRoot`
     * unconditionally, so an ancestor file's own siblings were refused: measured
     * on this repository, `--root <mono>/sugar-crush` returned 18,496 B with two
     * `<import-blocked>` notes against 25,110 B for the same file read from
     * `<mono>`, and `CONTRIBUTING.md` (6,992 B on disk) was lost outright.
     */
    public function testAnAncestorFilesOwnImportsAreBoundedByItsOwnCheckoutNotByTheRoot(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "HEAD\n@./shared.md\nTAIL\n");
        $this->touch($mono . '/shared.md', "INLINED-SIBLING\n");

        $contents = (new InstructionFileLoader($libDir))->loadRoot();

        $this->assertCount(1, $contents);
        $this->assertStringContainsString('INLINED-SIBLING', $contents[0]);
        $this->assertStringNotContainsString('import-blocked', $contents[0]);
    }

    /**
     * ...and the widened boundary is still a BOUNDARY. An ancestor import that
     * leaves the enclosing checkout is blocked and recorded, which is the
     * assertion that stops the test above from having simply removed the gate.
     */
    public function testAnAncestorImportLeavingTheEnclosingCheckoutIsStillBlocked(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($this->tempDir . '/escaped.md', "SHOULD-NOT-BE-INLINED\n");
        $this->touch($mono . '/CLAUDE.md', "HEAD\n@../escaped.md\nTAIL\n");

        $loader = new InstructionFileLoader($libDir);
        $contents = $loader->loadRoot();

        $this->assertCount(1, $contents);
        $this->assertStringNotContainsString('SHOULD-NOT-BE-INLINED', $contents[0]);
        $this->assertStringContainsString('import-blocked', $contents[0]);
        $this->assertNotSame([], $loader->refusedPaths());
    }

    /**
     * `loadForPath()` is deliberately UNCHANGED, and this is the assertion that
     * says so on purpose rather than by omission.
     *
     * Its GATE 1 refuses a walk that would START outside `$repoRoot`, and that
     * gate closed a MEASURED escape: a touched path outside the checkout made
     * the walk climb to `/` reading every `CLAUDE.md` it passed. The ancestor
     * pass above reads ancestor files too, but by a different mechanism with a
     * different bound — a positive VCS marker, decided once at session start,
     * independent of any path the agent touches. Reopening the walk would put
     * the choice of what gets read back in the hands of an arbitrary touched
     * path, which is the thing GATE 1 exists to prevent, and it would also add
     * a route that can never emit anything new: every candidate it could find
     * above `$repoRoot` is one of the same two filenames the ancestor pass has
     * already marked emitted.
     */
    public function testLoadForPathStillRefusesAWalkStartingOutsideTheCheckoutDespiteTheGitAncestor(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $sibling = $mono . '/other-lib';
        mkdir($sibling, 0777, true);
        $this->touch($sibling . '/CLAUDE.md', "SIBLING-ONLY\n");
        $this->touch($sibling . '/thing.php', '<?php');

        $loader = new InstructionFileLoader($libDir);

        $this->assertNull($loader->loadForPath($sibling . '/thing.php'));
        $this->assertArrayHasKey($sibling . '/thing.php', $loader->refusedPaths());
    }

    /**
     * And the second half of that claim, measured rather than asserted: after
     * the ancestor pass has run, every file an extended `loadForPath()` walk
     * could reach above `$repoRoot` is already in the emitted set, so extending
     * it would emit nothing.
     */
    public function testTheAncestorPassAlreadyClaimsEveryFileAnExtendedWalkCouldFind(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "MONO\n");
        $this->touch($mono . '/AGENTS.md', "MONO-AGENTS\n");

        $loader = new InstructionFileLoader($libDir);
        $loader->loadRoot();

        $emitted = $loader->emittedPaths();
        foreach (['CLAUDE.md', 'AGENTS.md'] as $filename) {
            $this->assertContains(realpath($mono . '/' . $filename), $emitted);
        }
    }

    public function testAncestorRootNamesTheEnclosingCheckout(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();

        $this->assertSame(realpath($mono), (new InstructionFileLoader($libDir))->ancestorRoot());
    }

    /**
     * MEMOIZATION, tested as a TRUTH rather than a presence. The assertion this
     * replaced was `assertSame($l->ancestorRoot(), $l->ancestorRoot())`, which
     * cannot fail for a pure function of the filesystem — disabling the
     * memoization guard outright (`if ($this->ancestorRootResolved)` =>
     * `if (false)`) left it green.
     *
     * The walk's inputs are files a build step can create or delete mid-session,
     * so the observable property is that the ANSWER SURVIVES ITS INPUTS
     * CHANGING: take the answer, remove the `.git` marker that produced it, ask
     * again. An unmemoized second call walks up, finds no marker anywhere, and
     * returns null — which is the two-different-answers-per-session outcome the
     * docblock says memoization exists to prevent.
     */
    public function testTheAncestorRootIsMemoizedAgainstAMarkerThatDisappearsMidSession(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $loader = new InstructionFileLoader($libDir);

        $first = $loader->ancestorRoot();
        $this->assertSame(realpath($mono), $first);

        rmdir($mono . '/.git');
        $this->assertFalse(file_exists($mono . '/.git'), 'the fixture must really have lost its marker');

        $this->assertSame($first, $loader->ancestorRoot(), 'the answer must not be re-derived per call');
    }

    /**
     * DEDUP ACROSS THE ANCESTOR PASS, which had no test at all: disabling the
     * `isset($this->emittedPaths[...])` skip inside loadAncestorRoots()
     * (`if (isset(...))` => `if (false)`) survived all 248 tests under
     * tests/Context, and against the REAL repository it double-injected
     * AGENTS.md — 25,110 B becoming 35,180 B with "SugarCraft contributor
     * playbook" appearing twice.
     *
     * The shape the pre-existing fixtures were missing is this repository's own:
     * an ancestor `CLAUDE.md` that `@import`s the ancestor `AGENTS.md` sitting
     * beside it. The import marks AGENTS.md emitted, and the very next iteration
     * of the filename loop is the one that would emit it again as a second
     * top-level document. The other ancestor-import test uses `shared.md`, a
     * name the loop never visits, so it exercises the boundary and not the
     * dedup.
     */
    public function testAnAncestorFileInlinedByAnImportIsNotAlsoEmittedAsASecondDocument(): void
    {
        [$mono, $libDir] = $this->makeMonorepo();
        $this->touch($mono . '/CLAUDE.md', "MONO-HEAD\n@./AGENTS.md\nMONO-TAIL\n");
        $this->touch($mono . '/AGENTS.md', "PLAYBOOK-BODY\n");

        $contents = (new InstructionFileLoader($libDir))->loadRoot();

        $this->assertCount(1, $contents, 'AGENTS.md was inlined, so it must not also be its own document');
        // The property is ONCE, not present: a second document would still
        // contain the body, so counting occurrences is what pins it.
        $this->assertSame(1, substr_count($contents[0], 'PLAYBOOK-BODY'));
        $this->assertStringContainsString('MONO-TAIL', $contents[0]);
    }

    /**
     * RULE 4, in the direction the first cut left open. The rule compared only
     * the MARKER against `$HOME`, so a walk that passed straight THROUGH `$HOME`
     * to a checkout above it adopted that checkout — and emitted
     * `$HOME/CLAUDE.md` as a PROJECT-tier document, which is the exact outcome
     * rule 4's own rationale says must not happen. MEASURED on that shape before
     * the fix: `ancestorRoot()` returned the enclosing checkout and `loadRoot()`
     * returned 3 documents including the home-tier one.
     *
     * The fixture is not exotic: a container image or a chroot whose whole
     * filesystem is under version control puts every user's home inside a
     * checkout.
     */
    public function testTheWalkStopsAtHomeEvenWhenACheckoutEnclosesIt(): void
    {
        $box = $this->tempDir . '/box';
        $home = $box . '/home';
        $lib = $home . '/proj/lib';
        mkdir($lib, 0777, true);
        mkdir($box . '/.git', 0777, true);
        $this->touch($box . '/CLAUDE.md', "ENCLOSING-CHECKOUT\n");
        $this->touch($home . '/CLAUDE.md', "PERSONAL-TIER\n");
        $this->touch($home . '/proj/CLAUDE.md', "PROJECT\n");

        $previous = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $loader = new InstructionFileLoader($lib);

            $this->assertNull($loader->ancestorRoot(), 'the walk must not cross $HOME');
            $this->assertSame([], $loader->loadRoot());
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
        }
    }

    public function testAncestorRootIsNullForARootThatDoesNotResolve(): void
    {
        $loader = new InstructionFileLoader($this->tempDir . '/no/such/dir');

        $this->assertNull($loader->ancestorRoot());
        $this->assertSame([], $loader->loadRoot());
    }

    /**
     * The pre-existing fixtures in this class must stay inert: `$this->repoRoot`
     * has no `.git` and nothing above it does, so the ancestor pass is a no-op
     * for every other test here. Asserted rather than assumed, because a stray
     * `.git` anywhere under the system temp directory would silently make dozens
     * of assertions in this file mean something else.
     */
    public function testTheExistingFixturesHaveNoMonorepoParentSoTheOtherTestsAreUnaffected(): void
    {
        $this->assertNull((new InstructionFileLoader($this->repoRoot))->ancestorRoot());
    }
}
