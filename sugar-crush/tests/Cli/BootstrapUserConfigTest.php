<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;

/**
 * Isolates ~/.sugar-crush/config.json under a temp HOME, same convention as
 * BinSugarcrushWiringTest/SessionTest, so this never touches the real
 * per-user config file on the machine running the suite.
 */
final class BootstrapUserConfigTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_user_config_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

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
        file_put_contents(Bootstrap::userConfigPath(), '{not valid json');
        $this->assertSame([], Bootstrap::readUserConfig());
    }

    public function testChatSeedsThemeNameFromPersistedConfig(): void
    {
        Bootstrap::writeUserConfig(['theme' => 'tokyoNight']);
        $chat = Bootstrap::chat($this->tempDir);

        $this->assertSame('tokyoNight', $chat->theme()->name);
    }

    public function testBackendFallsBackToPersistedProviderBeforeEcho(): void
    {
        // No SUGARCRUSH_PROVIDER/SUGARCRUSH_BACKEND_CMD env set - only a
        // persisted choice. 'sglang' builds without a reachable server
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
