<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Bundles;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Skills\SkillSource;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * PLG-1: a bundle's three accepted surfaces arrive through the three
 * existing loaders, badged `[bundle]`, and a plain foreign directory of the
 * SAME tier beats them everywhere — the MVP's precedence seat is "below the
 * trees the user installed on purpose", and this file is the behavioral
 * proof, one test per loader, measured end-to-end from a real bundle
 * directory rather than from a hand-built array wherever a loader is
 * involved.
 *
 * The badges come free with `SkillSource::Bundle`; what is NOT free and what
 * each test therefore exists for:
 *
 *  - commands: the walk order inside ForeignCommandDiscovery (bundle band
 *    FIRST per tier = loses under last-write-wins);
 *  - skills: the interleave inside SkillManager::loadAll() — the bundle tier
 *    is registered before agents/claude/opencode of the same pass;
 *  - agents: the union bias in ForeignAgentPresetRegistry::discover() —
 *    scanBundles() sits RIGHT of the `+`, the losing side.
 */
final class BundleSurfaceWiringTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir;

    private string $home;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-bundle-wiring-test-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
        $this->project = $this->tempDir . '/project';
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /** Build a real bundle directory (manifest + surfaces) and read it back. */
    private function bundleAt(string $dir, string $name, string $tier, ?string $ownedBy): Bundle
    {
        mkdir($dir . '/.claude-plugin', 0777, true);
        file_put_contents($dir . '/.claude-plugin/plugin.json', json_encode(['name' => $name]));

        $bundle = Bundle::fromDir($dir, $tier, $ownedBy);
        self::assertNotNull($bundle, "the fixture at {$dir} must be a valid bundle");

        return $bundle;
    }

    /** @param array<string, string> $rows command name => body */
    private function commandRows(string $dir, array $rows): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach ($rows as $name => $body) {
            file_put_contents($dir . '/' . $name . '.md', "---\ndescription: a row\n---\n" . $body);
        }
    }

    private function skillAt(string $container, string $slug, string $description): void
    {
        $dir = $container . '/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($container . '/' . $slug . '/SKILL.md', "---\ndescription: $description\n---\n\nBody.");
    }

    private function agentAt(string $dir, string $name): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $name . '.md', "---\nname: $name\ndescription: A preset\n---\n\nBody.");
    }

    public function testBundleCommandsRideTheLoaderBadgedAndTierStamped(): void
    {
        $bundle = $this->bundleAt($this->home . '/dev/revkit', 'revkit', 'user', $this->home);
        $this->commandRows($bundle->dir . '/commands', ['rev' => 'from the bundle']);

        $loader = new CommandLoader();
        $loader->useBundles([$bundle]);
        $commands = $loader->loadAll($this->project);

        self::assertArrayHasKey('rev', $commands);
        self::assertSame(SkillSource::Bundle, $commands['rev']->source);
        self::assertSame('[bundle]', $commands['rev']->sourceBadge());
        self::assertSame('user', $commands['rev']->tier, 'the tier stamp rides the bundle, not the loader call');
    }

    public function testAPlainForeignCommandTreeBeatsTheBundleRowOfItsOwnTier(): void
    {
        $bundle = $this->bundleAt($this->project . '/bundled', 'bundled', 'project', null);
        $this->commandRows($bundle->dir . '/commands', ['rev' => 'from the bundle', 'only-bundle' => 'kept']);
        $this->commandRows($this->project . '/.claude/commands', ['rev' => 'from .claude']);

        $loader = new CommandLoader();
        $loader->useBundles([$bundle]);
        $commands = $loader->loadAll($this->project);

        self::assertSame(SkillSource::Claude, $commands['rev']->source, 'the precedence seat is BELOW plain foreign dirs');
        self::assertStringContainsString('from .claude', (string) $commands['rev']->template, 'the winning row carries the winning file');
        self::assertSame(SkillSource::Bundle, $commands['only-bundle']->source, 'and losing a name does not evict the rows that own none');
    }

    public function testBundleSkillsInterleaveBelowThePlainSkillTrees(): void
    {
        $bundle = $this->bundleAt($this->project . '/skillkit', 'skillkit', 'project', null);
        $this->skillAt($bundle->dir . '/skills', 'alpha', 'A bundled skill');
        $this->skillAt($bundle->dir . '/skills', 'beta', 'The bundled beta');
        $this->skillAt($this->project . '/.claude/skills', 'beta', 'The claude beta');

        $registry = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $registry))->loadAll($this->project, [$bundle]);

        self::assertSame(SkillSource::Bundle, $registry->get('alpha')?->source);
        self::assertSame(SkillSource::Claude, $registry->get('beta')?->source, 'per the last-write-wins interleave, the plain tree reclaims the name');
    }

    public function testBundleAgentsJoinThePresetRosterAndLoseToPlainClaudeAgents(): void
    {
        $bundle = $this->bundleAt($this->project . '/agentkit', 'agentkit', 'project', null);
        $this->agentAt($bundle->dir . '/agents', 'bundled-only');
        $this->agentAt($bundle->dir . '/agents', 'reviewer');
        $this->agentAt($this->project . '/.claude/agents', 'reviewer');

        $presets = (new ForeignAgentPresetRegistry())->discover($this->project, [$bundle]);

        self::assertArrayHasKey('bundled-only', $presets);
        self::assertSame(SkillSource::Bundle, $presets['bundled-only']->source);
        self::assertSame(SkillSource::Claude, $presets['reviewer']->source, 'scanBundles sits right of the union plus — the losing side');
    }
}
