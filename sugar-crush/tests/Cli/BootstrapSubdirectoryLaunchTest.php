<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Support\ProjectRoot;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\McpLaunchEnabledTrait;

/**
 * Audit 15d-13 (b): `cd repo/src && sugarcrush` reads the repository's
 * `.sugar-crush/*` — settings, skills, rules, commands, agent presets, memory
 * and `.mcp.json` — while the working directory the tools run in stays the
 * launch directory. Before the fix every one of these looked under
 * `repo/src/.sugar-crush` and found nothing.
 */
final class BootstrapSubdirectoryLaunchTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private string $tmp;
    private string $repo;
    private string $sub;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_subdir_launch_' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repo';
        $this->sub = $this->repo . '/src/deep';
        mkdir($this->sub, 0o700, true);
        exec('git -C ' . escapeshellarg($this->repo) . ' init -q 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        $this->useHomeSandbox($this->tmp . '/home');
        mkdir($this->tmp . '/home/.sugar-crush', 0o700, true);
        $this->clearBackendSelectionEnv();
        $this->armMcpLaunchEnabled();
        ProjectRoot::forget();
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        ProjectRoot::forget();
        $this->restoreMcpLaunchEnabled();
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testATrustedRepositorysSettingsApplyToASubdirectoryLaunch(): void
    {
        $this->put('.sugar-crush/settings.json', json_encode(['theme' => 'from-the-repo-root'], JSON_THROW_ON_ERROR));
        file_put_contents($this->tmp . '/home/.sugar-crush/config.json', json_encode([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$this->repo],
        ], JSON_THROW_ON_ERROR));

        $backend = Bootstrap::backend($this->sub);

        self::assertSame('from-the-repo-root', Bootstrap::readUserConfig()['theme'] ?? null);

        // ...and the working directory is still the launch directory.
        self::assertInstanceOf(EngineBackend::class, $backend);
        $bash = null;
        foreach ($backend->tools() as $tool) {
            if ($tool instanceof Bash) {
                $bash = $tool;
            }
        }
        self::assertNotNull($bash);
        self::assertSame($this->sub, (new \ReflectionProperty(Bash::class, 'root'))->getValue($bash));
    }

    public function testSkillsRulesCommandsAndPresetsComeFromTheRepositoryRoot(): void
    {
        $this->put('.sugar-crush/skills/rooted/SKILL.md', "---\ndescription: Found from below\n---\nbody\n");
        $this->put('.sugar-crush/rules/house.md', "---\nname: house\n---\nHouse rule.\n");
        $this->put('.sugar-crush/commands/ship.md', "project body\n");
        $this->put('.sugar-crush/agents/scout.md', "---\nname: scout\ndescription: Looks around\n---\n\nScout.\n");

        self::assertArrayHasKey('rooted', (new SkillLoader())->loadProjectSkills($this->sub));
        self::assertSame(['house'], array_map(
            static fn ($rule): string => $rule->name,
            (new RuleLoader($this->sub))->loadProjectRules(),
        ));
        self::assertArrayHasKey('ship', (new CommandLoader())->loadProjectCommands($this->sub));
        self::assertArrayHasKey('scout', Bootstrap::agentPresets($this->sub));
    }

    public function testProjectMemoryAndMcpConfigAreTheRepositorys(): void
    {
        mkdir($this->repo . '/.sugar-crush/memory', 0o700, true);
        $this->put('.mcp.json', '{"mcpServers":{}}');

        self::assertSame(
            $this->repo . '/' . ProjectMemoryWriter::RELATIVE_DIRECTORY,
            ProjectMemoryWriter::forRoot($this->sub)?->directory(),
        );

        $decision = Bootstrap::mcpConfigDecision($this->sub);
        self::assertSame($this->repo . '/' . Bootstrap::MCP_CONFIG_FILENAME, $decision['path']);
        self::assertSame(Bootstrap::MCP_UNTRUSTED, $decision['status']);
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->repo . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
