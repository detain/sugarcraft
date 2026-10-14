<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * rv-enduser-1 (fix 5): the endpoint lockout. A user whose only conforming
 * path to an API is an HTTPS proxy could set `providers` in
 * ~/.sugar-crush/config.json and watch it be ignored — the palette listed
 * built-in types, and every resolution funnel called
 * `ProviderFactory::defaultConfig($name)`, which knows only its own arms.
 *
 * What shipped: a `providers` settings key (user tier, Egress risk), read
 * through {@see Bootstrap::providerOverrides()}, consumed by
 * {@see Bootstrap::providerConfigFor()} — the funnel every name→config site
 * now walks — plus `OPENAI_BASE_URL` honoured for the openai/sglang/custom
 * arms the same way `ANTHROPIC_BASE_URL` already was for anthropic.
 *
 * HOME is a per-test temp dir (BootstrapToolAndPermissionSettingsTest
 * convention), so no case here reads or writes the developer's config, and
 * `OPENAI_API_KEY` / `OPENAI_BASE_URL` are pinned out of the ambient
 * environment because `defaultConfig()` reads both via getenv.
 */
final class ProviderOverridesTest extends TestCase
{
    private string $home = '';
    private string $project = '';
    private string $originalHome = '';
    private mixed $originalServerHome = null;
    private string|false $originalApiKey = false;
    private string|false $originalBaseUrl = false;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/provider_overrides_home_' . uniqid('', true);
        $this->project = sys_get_temp_dir() . '/provider_overrides_project_' . uniqid('', true);
        mkdir($this->home . '/.sugar-crush', 0700, true);
        mkdir($this->project, 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        $this->originalApiKey = getenv('OPENAI_API_KEY');
        $this->originalBaseUrl = getenv('OPENAI_BASE_URL');
        putenv('OPENAI_API_KEY');
        putenv('OPENAI_BASE_URL');

        Bootstrap::useProjectRootForSettings(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);

        $this->originalApiKey === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $this->originalApiKey);
        $this->originalBaseUrl === false ? putenv('OPENAI_BASE_URL') : putenv('OPENAI_BASE_URL=' . $this->originalBaseUrl);

        putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeTree($this->home);
        $this->removeTree($this->project);

        parent::tearDown();
    }

    // =========================================================================
    // providerOverrides() — validation at the boundary (parse, don't validate)
    // =========================================================================

    public function testOnlyWellFormedEntriesSurviveTheBoundary(): void
    {
        $this->writeUserConfig(['providers' => [
            'proxy' => ['type' => 'openai', 'baseUrl' => 'https://gw.internal/v1'],
            'missing-type' => ['baseUrl' => 'https://nope'],
            'empty-type' => ['type' => ''],
            'not-an-array' => 'openai',
            3 => ['type' => 'openai'],
        ]]);

        $overrides = Bootstrap::providerOverrides();

        $this->assertSame(['proxy'], array_keys($overrides), 'an entry without a usable string type is not a provider override');
        $this->assertSame('https://gw.internal/v1', $overrides['proxy']['baseUrl']);
    }

    public function testAMissingOrGarbageMapDegradesToNoOverrides(): void
    {
        $this->assertSame([], Bootstrap::providerOverrides(), 'no providers key at all is the normal state');

        $this->writeUserConfig(['providers' => 'not-even-an-array']);
        $this->assertSame([], Bootstrap::providerOverrides());

        $this->writeUserConfig(['providers' => []]);
        $this->assertSame([], Bootstrap::providerOverrides());
    }

    // =========================================================================
    // providerConfigFor() — the single funnel
    // =========================================================================

    public function testAnOverrideLayersOverItsTypeDefaultsInsteadOfReplacingThem(): void
    {
        $this->writeUserConfig(['providers' => [
            'proxy' => ['type' => 'openai', 'baseUrl' => 'https://gw.internal/v1', 'model' => 'gpt-5-mini'],
        ]]);
        putenv('OPENAI_API_KEY=k-from-env');

        $config = Bootstrap::providerConfigFor('proxy', new ProviderFactory());

        $this->assertSame('https://gw.internal/v1', $config['baseUrl'], 'the endpoint on the entry wins');
        $this->assertSame('gpt-5-mini', $config['model']);
        $this->assertSame('k-from-env', $config['apiKey'], 'what the entry omits still comes from the type arm');
        $this->assertSame('openai', $config['type'], 'the declared type rides through');
    }

    public function testWithoutAnOverrideTheFunnelIsByteIdenticalToDefaultConfig(): void
    {
        $this->writeUserConfig(['providers' => ['other' => ['type' => 'openai']]]);
        $factory = new ProviderFactory();

        $this->assertSame(
            $factory->defaultConfig('sglang'),
            Bootstrap::providerConfigFor('sglang', $factory),
            'a name the map does not carry must resolve exactly as before the fix'
        );
    }

    public function testAnOverrideNamingAnUnknownTypeSurfacesTheListingsError(): void
    {
        $this->writeUserConfig(['providers' => ['typo' => ['type' => 'opnai']]]);

        try {
            Bootstrap::providerConfigFor('typo', new ProviderFactory());
            $this->fail('an unknown declared type must not silently resolve');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Unknown provider type: opnai', $e->getMessage());
            $this->assertStringContainsString('closest match: openai', $e->getMessage());
        }
    }

    // =========================================================================
    // availableProviders() — the palette sees the map too
    // =========================================================================

    public function testAWellFormedOverrideBecomesSelectable(): void
    {
        $this->writeUserConfig(['providers' => [
            'lab-gw' => ['type' => 'sglang', 'baseUrl' => 'http://lab:30000', 'model' => 'Qwen/Qwen3'],
        ]]);

        $names = array_keys(Bootstrap::availableProviders());

        $this->assertContains('lab-gw', $names, 'the map exists to make new names selectable');
        $this->assertSame(
            'http://lab:30000',
            Bootstrap::availableProviders()['lab-gw']['baseUrl']
        );
    }

    public function testABogusOverrideIsNotSelectableButSaysSoThroughTheNoticeChannel(): void
    {
        $this->writeUserConfig(['providers' => [
            'doomed' => ['type' => 'totally-not-a-type'],
        ]]);
        $before = \count(Bootstrap::launchNotices());

        $names = array_keys(Bootstrap::availableProviders());

        $this->assertNotContains('doomed', $names);
        $slice = \array_slice(Bootstrap::launchNotices(), $before);
        $joined = \implode("\n", $slice);
        $this->assertStringContainsString("provider override 'doomed' is not selectable", $joined);
        $this->assertStringContainsString('totally-not-a-type', $joined, 'the notice must name what was rejected');
    }

    // =========================================================================
    // OPENAI_BASE_URL — env honour for the openai-compatible family
    // =========================================================================

    public function testOpenaiBaseUrlEnvAppliesToAllThreeCompatibleArms(): void
    {
        putenv('OPENAI_BASE_URL=https://gw.internal/v1');
        $factory = new ProviderFactory();

        $this->assertSame('https://gw.internal/v1', $factory->defaultConfig('openai')['baseUrl']);
        $this->assertSame('https://gw.internal/v1', $factory->defaultConfig('sglang')['baseUrl']);
        $this->assertSame('https://gw.internal/v1', $factory->defaultConfig('custom')['baseUrl']);
    }

    public function testWithoutTheEnvTheArmsKeepTheirHistoricalEndpoints(): void
    {
        $factory = new ProviderFactory();

        $this->assertNull($factory->defaultConfig('openai')['baseUrl'] ?? null, 'no env, no baseUrl — the SDK default stays in force');
        $this->assertSame('http://localhost:30000', $factory->defaultConfig('sglang')['baseUrl']);
        $this->assertSame('http://localhost:8080', $factory->defaultConfig('custom')['baseUrl']);
    }

    public function testTheConfigMapBeatsTheEnvironmentForTheSameName(): void
    {
        putenv('OPENAI_BASE_URL=https://env-side/v1');
        $this->writeUserConfig(['providers' => [
            'sglang' => ['type' => 'sglang', 'baseUrl' => 'http://map-side:30001'],
        ]]);

        $config = Bootstrap::providerConfigFor('sglang', new ProviderFactory());

        $this->assertSame('http://map-side:30001', $config['baseUrl'], 'layers: env builds the default, the settings entry overrides it');
    }

    // =========================================================================
    // The settings key itself
    // =========================================================================

    public function testProvidersIsALayeredUserTierKeyAndNotProjectSettable(): void
    {
        $this->assertContains('providers', \SugarCraft\Crush\Config\LayeredSettings::LAYERED_KEYS);
        $this->assertNotContains(
            'providers',
            \SugarCraft\Crush\Config\LayeredSettings::PROJECT_TIER_KEYS,
            'an Egress-risk key carries its own endpoint and credentials — the project tier is refused by doctrine (RiskClass::Egress)'
        );
    }

    private function writeUserConfig(array $config): void
    {
        file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            (string) json_encode($config, \JSON_PRETTY_PRINT),
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
