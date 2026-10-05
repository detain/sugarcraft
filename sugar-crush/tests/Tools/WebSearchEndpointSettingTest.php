<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\WebSearch;
use SugarCraft\Crush\Tools\ToolLimits;

/**
 * Roadmap N-P4e: WebSearch's endpoint is a settings key, `webSearchEndpoint`,
 * under `SUGARCRUSH_SEARCH_ENDPOINT`.
 *
 * Still no default host (audit F-W3(b)): with neither set every call is
 * refused, and the refusal names both. The key is Egress, so only the
 * operator's own tier may set it; a checked-out repository cannot choose where
 * the model's queries go. Precedence is argument, then the variable, then the
 * setting, and a setting that is not an http(s) URL is never dialled.
 */
final class WebSearchEndpointSettingTest extends TestCase
{
    use HomeSandboxTrait;

    private const ENV = 'SUGARCRUSH_SEARCH_ENDPOINT';

    private string $dir;

    private string|false $savedEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_search_endpoint_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
        $this->savedEnv = getenv(self::ENV);
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        putenv($this->savedEnv === false ? self::ENV : self::ENV . '=' . $this->savedEnv);
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheKeyIsAUserOnlyEgressUrlWithNoDefaultUnderTheVariable(): void
    {
        $definition = SettingsSchema::byKey(ToolLimits::WEB_SEARCH_ENDPOINT_KEY);

        self::assertNotNull($definition);
        self::assertNull($definition->default, 'there is no default endpoint, by design');
        self::assertSame(RiskClass::Egress, $definition->riskClass);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable);
        self::assertSame(self::ENV, $definition->envVar);
        self::assertSame(ApplyMode::Restart, $definition->applyMode);
        self::assertContains(ToolLimits::WEB_SEARCH_ENDPOINT_KEY, LayeredSettings::userTierOnlyKeys());
    }

    public function testNothingConfiguredStillRefusesAndNamesBothRoutes(): void
    {
        $search = new WebSearch();

        self::assertNull($this->endpoint($search));
        $result = $search->execute(['query' => 'php', 'description' => 'test']);
        self::assertTrue($result->isError());
        self::assertStringContainsString(self::ENV, $result->content());
        self::assertStringContainsString(ToolLimits::WEB_SEARCH_ENDPOINT_KEY, $result->content());
    }

    public function testTheSettingIsUsedWhenTheVariableIsUnsetOrEmpty(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://searx.example.org/search']);

        self::assertSame('https://searx.example.org/search', $this->endpoint(new WebSearch()));

        putenv(self::ENV . '=');
        self::assertSame('https://searx.example.org/search', $this->endpoint(new WebSearch()), 'an empty variable counts as unset');
    }

    public function testTheVariableOutranksTheSettingAndAnArgumentOutranksBoth(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://setting.example/search']);
        putenv(self::ENV . '=https://env.example/search');

        self::assertSame('https://env.example/search', $this->endpoint(new WebSearch()));
        self::assertSame('https://arg.example/search', $this->endpoint(new WebSearch('https://arg.example/search')));
        self::assertNull($this->endpoint(new WebSearch('')), 'an explicit empty argument is still "none", as before');
    }

    public function testASettingThatIsNotAnHttpUrlIsNeverDialled(): void
    {
        foreach (['ftp://searx.example.org/search', 'searx.example.org/search', 42] as $value) {
            Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_ENDPOINT_KEY => $value]);
            self::assertNull($this->endpoint(new WebSearch()), var_export($value, true));
        }
    }

    public function testATrustedProjectFileCannotChooseTheEndpoint(): void
    {
        $root = $this->dir . '/project';
        mkdir($root . '/.sugar-crush', 0700, true);
        file_put_contents($root . '/.sugar-crush/settings.json', json_encode([
            ToolLimits::WEB_SEARCH_ENDPOINT_KEY => 'https://attacker.example/search',
            ToolLimits::WEB_SEARCH_TIMEOUT_KEY => 12,
        ]));

        $layer = LayeredSettings::projectLayer($root, true);

        self::assertArrayNotHasKey(ToolLimits::WEB_SEARCH_ENDPOINT_KEY, $layer);
        self::assertSame(12, $layer[ToolLimits::WEB_SEARCH_TIMEOUT_KEY] ?? null, 'the timeout beside it is project-settable');
    }

    private function endpoint(WebSearch $search): ?string
    {
        return (new \ReflectionProperty(WebSearch::class, 'endpoint'))->getValue($search);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
