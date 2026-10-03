<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;

/**
 * Roadmap 5.14j: other agents' instruction-file spellings — Gemini CLI's
 * `GEMINI.md`, Cursor's `.cursorrules`, Cline's `.clinerules` — are read at
 * every level CLAUDE.md/AGENTS.md are, through the same gates.
 */
final class InstructionAliasTest extends TestCase
{
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
            ['CLAUDE.md', 'AGENTS.md', 'GEMINI.md', '.cursorrules', '.clinerules'],
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
        file_put_contents($this->repoRoot . '/CLAUDE.md', 'CLAUDECANARY');

        $contents = (new InstructionFileLoader($this->repoRoot))->loadRoot();

        self::assertSame(['CLAUDECANARY', 'AGENTSCANARY', 'GEMINICANARY', 'CURSORCANARY', 'CLINECANARY'], $contents);
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

        self::assertSame('NESTEDGEMINI', $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertNull($loader->loadForPath($this->repoRoot . '/pkg/src/a.php'), 'injected at most once');
    }

    public function testANestedClaudeMdStillWinsItsLevelOverAnAlias(): void
    {
        file_put_contents($this->repoRoot . '/pkg/.cursorrules', 'NESTEDCURSOR');
        file_put_contents($this->repoRoot . '/pkg/CLAUDE.md', 'NESTEDCLAUDE');
        file_put_contents($this->repoRoot . '/pkg/src/a.php', '<?php');

        $loader = new InstructionFileLoader($this->repoRoot);

        self::assertSame('NESTEDCLAUDE', $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
        self::assertSame('NESTEDCURSOR', $loader->loadForPath($this->repoRoot . '/pkg/src/a.php'));
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
