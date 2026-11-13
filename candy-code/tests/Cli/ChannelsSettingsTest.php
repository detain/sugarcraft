<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\ChannelsSettings;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class ChannelsSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/channels_settings_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->rm($this->tempDir);
    }

    private function rm(string $p): void
    {
        foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) {
                continue;
            }
            is_dir($f) ? $this->rm($f) : @unlink($f);
        }
        @rmdir($p);
    }

    public function testAnAbsentKeyIsAnEmptyMap(): void
    {
        self::assertSame([], ChannelsSettings::userTierMap('channels'));
    }

    public function testAnObjectKeyComesBackAsItsMap(): void
    {
        Bootstrap::writeUserConfig(['channels' => ['slack' => ['enabled' => true]]]);

        self::assertSame(['slack' => ['enabled' => true]], ChannelsSettings::userTierMap('channels'));
    }

    public function testANonArrayValueIsAnEmptyMap(): void
    {
        Bootstrap::writeUserConfig(['channels' => 'nope']);

        self::assertSame([], ChannelsSettings::userTierMap('channels'));
    }

    public function testOtherKeysAreNotLeaked(): void
    {
        Bootstrap::writeUserConfig(['channels' => ['a' => 1], 'theme' => 'dracula']);

        self::assertSame([], ChannelsSettings::userTierMap('missing'));
    }
}
