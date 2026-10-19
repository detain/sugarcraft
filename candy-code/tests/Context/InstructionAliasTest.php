<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Tests\Support\FramedOnTouchTrait;

/**
 * Roadmap 5.14j: other agents' instruction-file spellings — Gemini CLI's
 * `GEMINI.md`, Cursor's `.cursorrules`, Cline's `.clinerules` — are read at
 * every level CLAUDE.md/AGENTS.md are, through the same gates.
 */
final class InstructionAliasTest extends TestCase
{
    use FramedOnTouchTrait;

    private string $tempDir;

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/instruction_alias_test_' . bin2hex(random_bytes(6));
        $this->repoRoot = $this->tempDir . '/repo';
        mkdir($this->repoRoot . '/pkg/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tempDir);
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            self::removeTree($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    public function testTheAliasRosterIsPrecedenceOrdered(): void
    {
        self::assertSame(
            ['CLAUDE.md', 'CLAUDE.local.md', 'AGENTS.md', 'GEMINI.md', '.cursorrules', '.clinerules', '.github/copilot-instructions.md'],
            InstructionFileLoader::FILENAMES,
        );
    }

    public function testARootShippingOnlyGeminiMdIsLoaded(): void
    {
        file_put_contents($this->repoRoot . '/GEMINI.md', '# GEMINICANARY');

        $documents = (new InstructionFileLoader($this->repoRoot))->loadDocuments();

        self::assertCount(1, $documents);
        self::assertSame($this->repoRoot . '/GEMINI.md', $documents[0]['path']);
        self::assertStringContainsString('GEMINICANARY', (string) $documents[0]['body']);
    }

    public function testEveryRootAliasLoadsInPrecedenceOrderAfterTheNativeFiles(): void
    {
        file_put_contents($this->repoRoot . '/.clinerules', 'CLINECANARY');
        file_put_contents($this->repoRoot . '/.cursorrules', 'CURSORCANARY');
        file_put_contents($this->repoRoot . '/GEMINI.md', 'GEMINICANARY');
        file_put_contents($this->repoRoot . '/AGENTS.md', 'AGENTSCANARY');
        file_put_contents($this->repoRoot . '/CLAUDE.local.md', 'LOCALCANARY');
        file_put_contents($this->repoRoot . '/CLAUDE.md', 'CLAUDECANARY');
        mkdir($this->repoRoot . '/.github');
        file_put_contents($this->repoRoot . '/.github/copilot-instructions.md', 'COPILOTCANARY');

        $contents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        self::assertSame(['CLAUDECANARY', 'LOCALCANARY', 'AGENTSCANARY', 'GEMINICANARY', 'CURSORCANARY', 'CLINECANARY', 'COPILOTCANARY'], $contents);
    }

    public function testCLAUDELocalMdAloneIsReadAtTheRoot(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.local.md', '# LOCALONLY');

        $documents = (new InstructionFileLoader($this->repoRoot))->loadDocuments();

        self::assertCount(1, $documents);
        self::assertSame($this->repoRoot . '/CLAUDE.local.md', $documents[0]['path']);
        self::assertStringContainsString('LOCALONLY', (string) $documents[0]['body']);
    }

    public function testTheLocalOverlayLosesTheLevelToCLAUDEMdButOutranksTheOtherNames(): void
    {
        // Per-level precedence, the level's ONE on-touch slot: CLAUDE.md first,
        // the uncommitted overlay second, everything else behind it.
        file_put_contents($this->repoRoot . '/pkg/CLAUDE.local.md', 'LOCALCANARY');
        file_put_contents($this->repoRoot . '/pkg/AGENTS.md', 'AGENTSCANARY');
        file_put_contents($this->repoRoot . '/pkg/src/a.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/CLAUDE.local.md', 'LOCALCANARY'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/AGENTS.md', 'AGENTSCANARY'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
    }

    public function testAClinerulesDirectoryIsNotRead(): void
    {
        mkdir($this->repoRoot . '/.clinerules');
        file_put_contents($this->repoRoot . '/.clinerules/01-style.md', 'FOLDERCANARY');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame([], $loader->loadRoot());
        self::assertSame([], $loader->refusedPaths());
    }

    public function testAnAliasIsImportExpandedAndDedupedLikeClaudeMd(): void
    {
        file_put_contents($this->repoRoot . '/.cursorrules', "Rules.\n@./AGENTS.md\n");
        file_put_contents($this->repoRoot . '/AGENTS.md', 'AGENTSCANARY');

        $contents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        // AGENTS.md precedes .cursorrules, so the alias's import of it is the
        // already-included note, not a second copy.
        self::assertCount(2, $contents);
        self::assertSame('AGENTSCANARY', $contents[0]);
        self::assertSame(1, substr_count(implode("\n", $contents), 'AGENTSCANARY'));
    }

    public function testANestedAliasIsInjectedOnTouch(): void
    {
        file_put_contents($this->repoRoot . '/pkg/GEMINI.md', 'NESTEDGEMINI');
        file_put_contents($this->repoRoot . '/pkg/src/a.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/GEMINI.md', 'NESTEDGEMINI'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertNull($loader->loadForPath($this->repoRoot . '/pkg/src/a.php'), 'injected at most once');
    }

    public function testANestedClaudeMdStillWinsItsLevelOverAnAlias(): void
    {
        file_put_contents($this->repoRoot . '/pkg/.cursorrules', 'NESTEDCURSOR');
        file_put_contents($this->repoRoot . '/pkg/CLAUDE.md', 'NESTEDCLAUDE');
        file_put_contents($this->repoRoot . '/pkg/src/a.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/CLAUDE.md', 'NESTEDCLAUDE'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/.cursorrules', 'NESTEDCURSOR'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
    }

    public function testACopilotInstructionsFileIsFoundThroughItsDirectory(): void
    {
        // The tail alias is a PATH, not a bare name: a nested level's `.github`
        // candidate is its own file, read on touch like every other alias.
        mkdir($this->repoRoot . '/pkg/.github');
        file_put_contents($this->repoRoot . '/pkg/.github/copilot-instructions.md', 'NESTEDCOPILOT');
        file_put_contents($this->repoRoot . '/pkg/src/a.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame(self::framedOnTouch($this->repoRoot . '/pkg/.github/copilot-instructions.md', 'NESTEDCOPILOT'), $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertNull($loader->loadForPath($this->repoRoot . '/pkg/src/a.php'), 'injected at most once');
    }

    public function testAnExcludedRootFileIsSkippedAndRecorded(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', 'KEEPME');
        file_put_contents($this->repoRoot . '/CLAUDE.local.md', 'DROPMEE');

        $loader = new InstructionFileLoader($this->repoRoot, excludedGlobs: ['CLAUDE.local.md']);
        $contents = $loader->loadRoot();

        self::assertSame(['KEEPME'], $contents);
        self::assertSame(
            'excluded by instructionsExclude',
            $loader->refusedPaths()[$this->repoRoot . '/CLAUDE.local.md'],
        );
    }

    public function testExclusionMatchesTheCheckoutRelativePathAtEveryRepositoryTier(): void
    {
        // Patterns read like .gitignore entries: relative to the checkout, at
        // every level. The wildcard is assembled from char code so this file
        // contributes no glob-shaped literal to the corpus census.
        $pattern = 'vendor/' . \chr(42) . '/CLAUDE.md';
        mkdir($this->repoRoot . '/vendor/dep', 0o700, true);
        file_put_contents($this->repoRoot . '/vendor/dep/CLAUDE.md', 'VENDORCANARY');
        file_put_contents($this->repoRoot . '/vendor/dep/x.php', '<?php');
        // The not-excluded twin must be a REAL candidate: an instruction file
        // at a level loadForPath() governs, spelled so the pattern does not
        // name it. Asserting null on a path with no candidate proves nothing
        // about exclusion — it stays null even when excluded() matches
        // everything.
        file_put_contents($this->repoRoot . '/pkg/CLAUDE.md', 'PKGCANARY');
        file_put_contents($this->repoRoot . '/pkg/src/b.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot, excludedGlobs: [$pattern]);

        self::assertNull($loader->loadForPath($this->repoRoot . '/vendor/dep/x.php'));
        self::assertArrayHasKey($this->repoRoot . '/vendor/dep/CLAUDE.md', $loader->refusedPaths());
        self::assertSame(
            'excluded by instructionsExclude',
            $loader->refusedPaths()[$this->repoRoot . '/vendor/dep/CLAUDE.md'],
        );
        // Polarity twin of the legs above: a real file the pattern does not
        // name keeps loading normally.
        self::assertSame(
            self::framedOnTouch($this->repoRoot . '/pkg/CLAUDE.md', 'PKGCANARY'),
            $loader->loadForPath($this->repoRoot . '/pkg/src/b.php'),
        );
    }

    public function testExclusionNeverSilencesThePersonalTier(): void
    {
        $home = $this->tempDir . '/home/.candy-code';
        mkdir($home, 0o700, true);
        file_put_contents($home . '/AGENTS.md', 'PERSONALCANARY');
        file_put_contents($this->repoRoot . '/CLAUDE.md', 'ROOTCANARY');

        // A catch-all pattern is the operator silencing REPOSITORY context;
        // the personal file's channel stays open — the key is user-tier only
        // precisely so it can mute everything except the user's own voice.
        $loader = new InstructionFileLoader(
            $this->repoRoot,
            personalDir: $home,
            excludedGlobs: [\chr(42)],
        );

        $personal = $loader->loadPersonal();
        self::assertCount(1, $personal);
        self::assertStringContainsString('PERSONALCANARY', (string) $personal[0]['body']);
        self::assertSame([], $loader->loadRoot());
        self::assertSame(
            'excluded by instructionsExclude',
            $loader->refusedPaths()[$this->repoRoot . '/CLAUDE.md'],
        );
    }

    public function testAPatternThatWillNotCompileMatchesNothing(): void
    {
        // Exclusion FAILS OPEN: a hand-typo'd pattern must never silently drop
        // context it only appeared to name.
        mkdir($this->repoRoot . '/unclosedb', 0o700, true);
        file_put_contents($this->repoRoot . '/unclosedb/CLAUDE.md', 'BRKCANDRY_CANARY');
        file_put_contents($this->repoRoot . '/unclosedb/x.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot, excludedGlobs: ['unclosed[b/CLAUDE.md']);

        self::assertSame(
            self::framedOnTouch($this->repoRoot . '/unclosedb/CLAUDE.md', 'BRKCANDRY_CANARY'),
            $loader->loadForPath($this->repoRoot . '/unclosedb/x.php'),
        );
    }

    public function testAnAncestorAliasIsReadFromTheEnclosingCheckout(): void
    {
        mkdir($this->tempDir . '/repo/.git');
        file_put_contents($this->repoRoot . '/GEMINI.md', 'MONOGEMINI');

        $documents = (new InstructionFileLoader($this->repoRoot . '/pkg'))->loadDocuments();

        self::assertCount(1, $documents);
        self::assertSame('MONOGEMINI', $documents[0]['body']);
    }

    public function testAnAliasLinkedOutOfTheCheckoutIsRefusedAndRecorded(): void
    {
        mkdir($this->tempDir . '/outside');
        file_put_contents($this->tempDir . '/outside/secret.txt', 'SECRETCANARY');
        symlink($this->tempDir . '/outside/secret.txt', $this->repoRoot . '/.cursorrules');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame([], $loader->loadRoot());
        self::assertArrayHasKey($this->repoRoot . '/.cursorrules', $loader->refusedPaths());
    }
}
