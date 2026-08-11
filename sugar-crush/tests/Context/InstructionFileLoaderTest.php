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
        $this->tempDir = sys_get_temp_dir() . '/instruction_file_loader_test_' . uniqid();
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
}
