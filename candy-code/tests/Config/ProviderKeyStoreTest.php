<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\ProviderKeyStore;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-TUI-5.d: the persist half of `candy-code login` as its own door.
 *
 * The three claims the design names are pinned HERE, plus the branches the
 * extraction must not lose on the way out: the map merge keeps every other
 * provider (and every other config key), control characters are refused, and
 * a write that did not settle is never promised — the no-fabricated-success
 * law the login verb already obeys. The config path rides the sanctioned
 * `Bootstrap::useConfigPath()` override seam, so reads and the write door
 * resolve to the same temp file and the real HOME is never touched.
 */
final class ProviderKeyStoreTest extends TestCase
{
    use HomeSandboxTrait;

    private string $root = '';

    private string $configPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/candy_code_keystore_' . \uniqid('', true);
        mkdir($this->root . '/home', 0700, true);
        $this->useHomeSandbox($this->root . '/home');
        $this->configPath = $this->root . '/config.json';
        Bootstrap::useConfigPath($this->configPath);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();

        if ($this->root !== '' && is_dir($this->root)) {
            @chmod($this->root, 0700);
            $this->removeTree($this->root);
        }

        parent::tearDown();
    }

    public function testStoresTheKeyAndKeepsEveryOtherProviderAndConfigKey(): void
    {
        $seed = [
            'theme' => 'dark',
            'providers' => [
                'anthropic' => ['type' => 'anthropic', 'model' => 'claude-x', 'apiKey' => 'sk-ant-old'],
                'sglang' => ['type' => 'sglang', 'baseUrl' => 'http://127.0.0.1:30000/v1', 'modelPrices' => ['in' => 0.1]],
            ],
        ];
        file_put_contents($this->configPath, (string) json_encode($seed, JSON_PRETTY_PRINT));

        self::assertNull(ProviderKeyStore::store('openai', 'sk-mine-123'));

        $settled = json_decode((string) file_get_contents($this->configPath), true);
        self::assertIsArray($settled);
        self::assertSame('dark', $settled['theme'] ?? null, 'non-provider config keys survive the door');
        self::assertSame($seed['providers']['anthropic'], $settled['providers']['anthropic'] ?? null, 'other providers keep every field');
        self::assertSame($seed['providers']['sglang'], $settled['providers']['sglang'] ?? null, 'model/thinkingBudget/baseUrl fields keep too (C-PRV-1.c shape)');
        self::assertSame(
            ['type' => 'openai', 'apiKey' => 'sk-mine-123'],
            $settled['providers']['openai'] ?? null,
        );
    }

    public function testReTypingAnExistingProviderKeepsItsExtraFields(): void
    {
        $seed = ['providers' => ['openai' => ['type' => 'openai', 'apiKey' => 'sk-old', 'model' => 'gpt-x']]];
        file_put_contents($this->configPath, (string) json_encode($seed, JSON_PRETTY_PRINT));

        self::assertNull(ProviderKeyStore::store('openai', 'sk-new'));

        $settled = json_decode((string) file_get_contents($this->configPath), true);
        self::assertSame(
            ['type' => 'openai', 'apiKey' => 'sk-new', 'model' => 'gpt-x'],
            $settled['providers']['openai'] ?? null,
        );
    }

    public function testRefusesControlCharactersWithoutWriting(): void
    {
        file_put_contents($this->configPath, (string) json_encode(['theme' => 'dark']));
        $before = (string) file_get_contents($this->configPath);

        self::assertSame(
            Lang::t('cli.sub.login.control_chars'),
            ProviderKeyStore::store('openai', "sk\x01evil"),
        );
        self::assertSame($before, (string) file_get_contents($this->configPath), 'a refused key must not touch the file');
    }

    public function testRefusesTheEmptyKey(): void
    {
        self::assertSame(Lang::t('cli.sub.login.empty_key'), ProviderKeyStore::store('openai', ''));
        self::assertFalse(is_file($this->configPath), 'nothing is written for an empty key');
    }

    public function testRefusesABlockAlreadyTypedAsAnotherProvider(): void
    {
        file_put_contents(
            $this->configPath,
            (string) json_encode(['providers' => ['custom' => ['type' => 'anthropic', 'apiKey' => 'sk-ant']]]),
        );
        $before = (string) file_get_contents($this->configPath);

        self::assertSame(
            Lang::t('cli.sub.login.type_mismatch', [
                'path' => $this->configPath,
                'provider' => 'custom',
                'actual' => 'anthropic',
            ]),
            ProviderKeyStore::store('custom', 'sk-x'),
        );
        self::assertSame($before, (string) file_get_contents($this->configPath));
    }

    public function testRefusesACorruptConfigAndNeverRepairsIt(): void
    {
        file_put_contents($this->configPath, '{ not json');

        self::assertSame(
            Lang::t('cli.sub.login.corrupt_config', ['path' => $this->configPath]),
            ProviderKeyStore::store('openai', 'sk-x'),
        );
        self::assertSame('{ not json', (string) file_get_contents($this->configPath));
    }

    public function testADoorThatBailedUnwrittenIsReportedAsNotStored(): void
    {
        // writeUserConfig() can bail silently (lock contention, an
        // unresolvable target); the verify re-read is what stops the door
        // from promising a persist that never happened. A dangling symlink
        // is refused by userConfigWriteTarget() for EVERY uid — a chmod
        // fixture would silently "pass" the write under a privileged runner.
        $dir = $this->root . '/frozen';
        mkdir($dir, 0700, true);
        $path = $dir . '/config.json';
        symlink($dir . '/gone-target.json', $path);
        Bootstrap::useConfigPath($path);

        $verdict = ProviderKeyStore::store('openai', 'sk-never-stored');

        self::assertSame(Lang::t('cli.sub.login.stored_failed', ['path' => $path]), $verdict);
        self::assertTrue(is_link($path));
        self::assertFalse(is_file($dir . '/gone-target.json'), 'the bail left nothing behind');
    }

    private function removeTree(string $path): void
    {
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
