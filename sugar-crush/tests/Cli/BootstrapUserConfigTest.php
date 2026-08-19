<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Isolates ~/.sugar-crush/config.json under a temp HOME, same convention as
 * BinSugarcrushWiringTest/SessionTest, so this never touches the real
 * per-user config file on the machine running the suite.
 *
 * The backend-selection chain is cleared too
 * ({@see BackendSelectionEnvSandboxTrait}). A persisted `provider` key is TIER 4
 * of that chain, so a test about it is only testing anything while tiers 1-3 are
 * absent — and
 * {@see testBackendFallsBackToPersistedProviderBeforeEcho()} carried a comment
 * ASSERTING that precondition with no `putenv()` behind it, which is why either
 * shell-out variable exported in the developer's shell reddened it. Measured:
 * one failure before the clearing was added.
 */
final class BootstrapUserConfigTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_user_config_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);

        // BOTH spellings of HOME, not just the env var: putenv() alone moved
        // the config dir while every skill tree kept reading the developer's
        // real ~/.claude/skills -- see HomeSandboxTrait.
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->clearBackendSelectionEnv();
    }

    protected function tearDown(): void
    {
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();

        parent::tearDown();
    }

    public function testReadUserConfigReturnsEmptyArrayWhenFileIsMissing(): void
    {
        $this->assertSame([], Bootstrap::readUserConfig());
    }

    public function testWriteThenReadRoundTrips(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'dracula']);
        $this->assertSame(['theme' => 'dracula'], Bootstrap::readUserConfig());
    }

    public function testWriteUserConfigMergesRatherThanOverwrites(): void
    {
        Bootstrap::writeUserConfig(['provider' => 'dev-sglang']);
        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        $config = Bootstrap::readUserConfig();
        $this->assertSame('dev-sglang', $config['provider']);
        $this->assertSame('dracula', $config['theme']);
    }

    public function testReadUserConfigToleratesInvalidJson(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'dracula']);
        file_put_contents(Bootstrap::userConfigPath(), '{not valid json');

        $this->assertSame([], Bootstrap::readUserConfig());
    }

    public function testReadUserConfigToleratesAFileItCannotRead(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads a 0000 file regardless of its mode.');
        }

        Bootstrap::writeUserConfig(['theme' => 'dracula']);
        chmod(Bootstrap::userConfigPath(), 0o000);

        try {
            // Silently, too: the warning a bare file_get_contents() emits here
            // would land in the middle of the TUI's own output.
            $this->assertSame([], Bootstrap::readUserConfig());
        } finally {
            chmod(Bootstrap::userConfigPath(), 0o600);
        }
    }

    /**
     * Naming the config file must not create ~/.sugar-crush. Every read goes
     * through {@see Bootstrap::userConfigPath()} — including the per-turn
     * dispatch settings {@see \SugarCraft\Crush\Backend\EngineBackend} resolves
     * — and a directory appearing on disk because something asked a question
     * is a side effect nobody asked for.
     */
    public function testReadingTheConfigDoesNotCreateTheConfigDirectory(): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush';

        $this->assertSame($dir . '/config.json', Bootstrap::userConfigPath());
        $this->assertSame([], Bootstrap::readUserConfig());

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testWritingTheConfigCreatesTheDirectoryItNeeds(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'dracula']);

        $this->assertDirectoryExists($this->tempDir . '/home/.sugar-crush');
    }

    public function testChatSeedsThemeNameFromPersistedConfig(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'tokyoNight']);
        $chat = Bootstrap::chat($this->tempDir);

        $this->assertSame('tokyoNight', $chat->theme()->name);
    }

    public function testBackendFallsBackToPersistedProviderBeforeEcho(): void
    {
        // Tiers 1-3 of the selection chain are absent — SUGARCRUSH_PROVIDER,
        // SUGARCRUSH_BACKEND_CMD and SUGARCRUSH_BACKEND_CMD_STREAM are all
        // cleared in setUp() by BackendSelectionEnvSandboxTrait, which is what
        // makes this test about tier 4. It previously said the same thing as a
        // bare comment with no putenv() behind it, so a developer with either
        // shell-out variable exported got a CommandBackend and a red. Only a
        // persisted choice remains; 'sglang' builds without a reachable server
        // (see BootstrapTest's own comment on the same point).
        Bootstrap::writeUserConfig(['provider' => 'sglang']);
        $backend = Bootstrap::backend($this->tempDir);

        $this->assertInstanceOf(\SugarCraft\Crush\Backend\EngineBackend::class, $backend);
    }

    public function testForcedInstructionsIsEmptyWithoutConfiguredKey(): void
    {
        $this->assertSame([], Bootstrap::forcedInstructions());
    }

    public function testForcedInstructionsReadsTheInstructionsKey(): void
    {
        Bootstrap::writeUserConfig(['instructions' => ['docs/*.md', 'RULES.md']]);

        $this->assertSame(['docs/*.md', 'RULES.md'], Bootstrap::forcedInstructions());
    }

    public function testForcedInstructionsDropsNonStringAndBlankEntries(): void
    {
        Bootstrap::writeUserConfig(['instructions' => ['docs/*.md', 42, '', '   ', null, ['nested'], 'RULES.md']]);

        $this->assertSame(['docs/*.md', 'RULES.md'], Bootstrap::forcedInstructions());
    }

    public function testForcedInstructionsIgnoresANonArrayInstructionsValue(): void
    {
        Bootstrap::writeUserConfig(['instructions' => 'docs/*.md']);

        $this->assertSame([], Bootstrap::forcedInstructions());
    }

    /**
     * The regression this step exists for: before W1.B4, instructionLoader()
     * built InstructionFileLoader with no patterns at all, so loadForced()
     * was reachable from Runtime::buildSystemPrompt() but returned [] on
     * every real run no matter what the user configured.
     */
    public function testInstructionLoaderResolvesConfiguredGlobsAgainstTheRepoRoot(): void
    {
        mkdir($this->tempDir . '/docs', 0700, true);
        file_put_contents($this->tempDir . '/docs/house-style.md', 'Prefer tabs to spaces.');
        Bootstrap::writeUserConfig(['instructions' => ['docs/*.md']]);

        $forced = Bootstrap::instructionLoader($this->tempDir)->loadForced();

        $this->assertSame(['Prefer tabs to spaces.'], $forced);
    }

    public function testInstructionLoaderLoadsNothingForcedWhenConfigIsEmpty(): void
    {
        mkdir($this->tempDir . '/docs', 0700, true);
        file_put_contents($this->tempDir . '/docs/house-style.md', 'Prefer tabs to spaces.');

        $this->assertSame([], Bootstrap::instructionLoader($this->tempDir)->loadForced());
    }

    /**
     * loadForced()'s own containment guard still applies to config-sourced
     * patterns - promoting them to real user config must not turn the
     * config file into a read-anything primitive.
     */
    public function testConfiguredPatternsCannotEscapeTheRepoRoot(): void
    {
        file_put_contents($this->tempDir . '/outside.md', 'secret');
        mkdir($this->tempDir . '/repo', 0700, true);
        Bootstrap::writeUserConfig(['instructions' => ['../outside.md', '/etc/hostname']]);

        $this->assertSame([], Bootstrap::instructionLoader($this->tempDir . '/repo')->loadForced());
    }
}
