<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * ONE resolution of `~` for the whole package.
 *
 * The migration this class finishes was worse HALF-DONE than not done at all:
 * while every consumer read `$_SERVER['HOME'] ?? '/root'` they were
 * consistently wrong together, but once the skill trees moved to `getenv()` a
 * single `putenv('HOME', …)` — the exact call the move was motivated by —
 * pointed `~/.claude/skills` and `~/.claude/agents` at two different homes,
 * with two different fallbacks (`/tmp` and `/root`) behind them.
 */
final class HomeDirectoryTest extends TestCase
{
    use HomeSandboxTrait;

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    public function testTheEnvironmentVariableIsWhatDecides(): void
    {
        $dir = sys_get_temp_dir() . '/home_directory_env_' . uniqid('', true);
        $this->useHomeSandbox($dir);
        $_SERVER['HOME'] = '/definitely/not/this/one';

        $this->assertSame($dir, HomeDirectory::path());
        $this->assertSame($dir, HomeDirectory::resolved());

        @rmdir($dir);
    }

    /**
     * EVERY consumer goes through this one resolution. Listed by name rather
     * than asserted in aggregate because the failure being prevented is one
     * file drifting back, and a grep-shaped test names the file that did.
     *
     * @return array<string, array{0: string}>
     */
    public static function migratedFiles(): array
    {
        $files = [
            'src/Agents/ForeignAgentPresetRegistry.php',
            'src/Agents/Team.php',
            'src/Agents/TeamManager.php',
            'src/Agents/Teammate.php',
            'src/Agents/WorktreeManager.php',
            'src/Commands/CommandLoader.php',
            'src/Memory/ForeignMemoryImporter.php',
            'src/Skills/ForeignSkillDiscovery.php',
            'src/Skills/SkillDiscovery.php',
            'src/Skills/SkillLoader.php',
            'src/Workflows/WorkflowEngine.php',
            'src/Workflows/WorkflowRegistry.php',
            'src/Cli/Bootstrap.php',
        ];

        return array_combine($files, array_map(static fn(string $f): array => [$f], $files));
    }

    /**
     * @dataProvider migratedFiles
     */
    public function testNoConsumerReadsTheServerSuperglobalDirectly(string $relative): void
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $source = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            '$_SERVER[\'HOME\']',
            $source,
            $relative . ' must resolve ~ through HomeDirectory, not its own copy of the rule',
        );
    }

    /**
     * The passwd database is asked before any stand-in: the environment not
     * SAYING where home is does not mean nobody knows, and that is the case
     * cron, systemd, `env -i` and `sudo` without `-E` all produce.
     */
    public function testAnUnsetHomeFallsBackToThePasswdEntryNotToTheTempDirectory(): void
    {
        if (!\function_exists('posix_getpwuid') || !\function_exists('posix_geteuid')) {
            $this->markTestSkipped('ext-posix is not available');
        }

        $expected = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        if (!is_string($expected) || $expected === '') {
            $this->markTestSkipped('this uid has no passwd home directory');
        }

        $this->useHomeSandbox(sys_get_temp_dir() . '/home_directory_unset_' . uniqid('', true));
        putenv('HOME');
        putenv('USERPROFILE');

        $this->assertSame($expected, HomeDirectory::resolved());
    }
}
