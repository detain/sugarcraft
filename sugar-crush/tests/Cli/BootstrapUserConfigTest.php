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
}
