<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\Tool;

/**
 * Step 0.3: the Bash `<git_commits>` guidance is generic git discipline, not
 * the SugarCraft monorepo's PR cadence, and two layered settings shape it -
 * `includeGitInstructions` (drops the block; a trusted project may set it)
 * and `attribution` (commit trailer + PR closing line; user tier only).
 */
final class BashGitGuidanceSettingTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $configDir = '';
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/bash_git_guidance_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        $this->configDir = $home . '/.sugar-crush';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($home);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ─── the fragment itself ─────────────────────────────────────────────

    public function testDefaultFragmentCarriesNoRepositorysCadence(): void
    {
        $fragment = (new Bash())->promptGuidance();

        foreach (['gh pr create', 'gh pr merge', 'GITHUB_TOKEN', 'ai/<slug>', '<lib>:', '## Test plan', 'composer validate', 'master', 'Bundle'] as $sugarcraftOnly) {
            self::assertStringNotContainsString($sugarcraftOnly, $fragment, "the generic block still carries '{$sugarcraftOnly}'");
        }

        // The generic safety rules survive the cut.
        foreach (['--amend', '--no-verify', 'force-push to the default branch', 'git add -A', 'AGENTS.md'] as $kept) {
            self::assertStringContainsString($kept, $fragment);
        }
    }

    public function testDisabledGuidanceIsTheEmptyFragment(): void
    {
        self::assertSame('', (new Bash())->withGitGuidance(false)->promptGuidance());
        self::assertSame('', (new Bash())->withGitGuidance(false, 'Co-Authored-By: X <x@example.com>', 'by X')->promptGuidance());
    }

    public function testAttributionIsRenderedIntoTheTemplateAndTheClosing(): void
    {
        $fragment = (new Bash())->withGitGuidance(true, 'Co-Authored-By: Bot <bot@example.com>', 'Generated with Bot')->promptGuidance();

        self::assertStringContainsString("What changed, and why.\n\nCo-Authored-By: Bot <bot@example.com>\nMSG", $fragment);
        self::assertStringContainsString("description you write with this line, exactly as written:\nGenerated with Bot\n</git_commits>", $fragment);
    }

    public function testNoAttributionAddsNoTrailerOrClosing(): void
    {
        $fragment = (new Bash())->withGitGuidance(true, '  ', '')->promptGuidance();

        self::assertStringContainsString("What changed, and why.\nMSG\n</git_commits>", $fragment);
        self::assertSame((new Bash())->promptGuidance(), $fragment);
    }

    public function testWitherKeepsEveryOtherField(): void
    {
        $bash = (new Bash('/srv/repo', maxOutputBytes: 1234))->withGitGuidance(false);

        self::assertStringContainsString('1,234 bytes', $bash->description());
    }

    // ─── the settings reach Bootstrap::tools() ───────────────────────────

    public function testUserSettingsShapeTheWiredBash(): void
    {
        $this->writeUserSettings(['attribution' => ['commit' => 'Signed-off-by: Op <op@example.com>', 'pr' => 'Made by op']]);

        $fragment = $this->wiredBash()->promptGuidance();

        self::assertStringContainsString('Signed-off-by: Op <op@example.com>', $fragment);
        self::assertStringContainsString('Made by op', $fragment);

        $this->writeUserSettings(['includeGitInstructions' => false]);
        self::assertSame('', $this->wiredBash()->promptGuidance());
    }

    public function testMalformedValuesAreIgnoredAsIfUnset(): void
    {
        $this->writeUserSettings(['includeGitInstructions' => 'no', 'attribution' => ['commit' => ['x'], 'pr' => 7]]);

        self::assertSame((new Bash())->promptGuidance(), $this->wiredBash()->promptGuidance());
    }

    public function testATrustedProjectMayDropTheBlockButNotSetAttribution(): void
    {
        $this->trustTheProject();
        file_put_contents($this->projectRoot . '/' . LayeredSettings::SHARED_PATH, (string) json_encode([
            'includeGitInstructions' => false,
            'attribution' => ['commit' => 'Injected-By: repo'],
        ]));
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame('', $this->wiredBash()->promptGuidance());

        // With the block back on, the project's attribution still never lands.
        file_put_contents($this->projectRoot . '/' . LayeredSettings::SHARED_PATH, (string) json_encode([
            'attribution' => ['commit' => 'Injected-By: repo'],
        ]));
        self::assertStringNotContainsString('Injected-By', $this->wiredBash()->promptGuidance());
    }

    public function testAnUntrustedProjectCannotDropTheBlock(): void
    {
        file_put_contents($this->projectRoot . '/' . LayeredSettings::SHARED_PATH, (string) json_encode([
            'includeGitInstructions' => false,
        ]));
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertNotSame('', $this->wiredBash()->promptGuidance());
    }

    private function wiredBash(): Bash
    {
        $bash = array_values(array_filter(
            Bootstrap::tools($this->projectRoot),
            static fn (Tool $tool): bool => $tool instanceof Bash,
        ));
        self::assertCount(1, $bash);

        return $bash[0];
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettings(array $data): void
    {
        $path = $this->configDir . '/' . LayeredSettings::USER_FILE;
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }

    private function trustTheProject(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);

        file_put_contents($this->configDir . '/config.json', (string) json_encode([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]));
        chmod($this->configDir . '/config.json', 0o600);
    }
}
