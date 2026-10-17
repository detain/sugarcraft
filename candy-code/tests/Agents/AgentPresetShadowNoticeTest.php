<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentPresetRegistry;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * ISS-031: native agent presets resolve user > project — the operator's own
 * `~/.candy-code/agents/` outranks a cloned checkout's `.candy-code/agents/`,
 * the direction audit 15d-03(b) already gave skills — and every file the
 * same-name walk DISPLACES is a launch notice naming both paths. Precedence
 * that cannot be seen is silence wearing a rule's clothes; this file pins
 * both halves, at the registry and on the real launch path.
 *
 * @see AgentPresetRegistry::shadowedFiles()
 * @see Bootstrap::AGENT_PRESET_SHADOW_NOTICE_FORMAT
 */
final class AgentPresetShadowNoticeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_preset_shadow_' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testTheTierOrderIsUserFirstAndTheUserPresetKeepsTheName(): void
    {
        $user = $this->tempDir . '/home/.candy-code/agents';
        $project = $this->tempDir . '/repo/.candy-code/agents';
        $userFile = $this->writePreset($user, 'reviewer', "The user's reviewer");
        $projectFile = $this->writePreset($project, 'reviewer', 'The project reviewer');

        $registry = new AgentPresetRegistry([$user, $project]);
        $presets = $registry->list();

        $this->assertSame("The user's reviewer", $presets['reviewer']->description);
        $this->assertSame($userFile, $presets['reviewer']->sourcePath, 'the winner carries the file it was read from (ISS-031)');
        $this->assertSame([$projectFile => $userFile], $registry->shadowedFiles(), 'the loser is recorded with the winner as its displacer');
    }

    /**
     * A SKIPPED file does not claim its name: the lower tier's same-named file
     * still loads, and a THIRD tier down is shadowed by the file that actually
     * won — not by the broken one that never became a preset.
     */
    public function testShadowRecordingSurvivesABrokenHigherTierAndNamesTheRealWinner(): void
    {
        $top = $this->tempDir . '/top';
        $middle = $this->tempDir . '/middle';
        $bottom = $this->tempDir . '/bottom';
        $brokenFile = $this->writePreset($top, 'shared', 'top', frontmatter: "name: 123\ndescription: d");
        $middleFile = $this->writePreset($middle, 'shared', 'middle');
        $bottomFile = $this->writePreset($bottom, 'shared', 'bottom');

        $registry = new AgentPresetRegistry([$top, $middle, $bottom]);
        $presets = $registry->list();

        $this->assertSame('middle', $presets['shared']->description);
        $this->assertSame([$bottomFile => $middleFile], $registry->shadowedFiles(), 'the bottom file lost to the middle one — the broken top file is a SKIP, never a winner');
        $this->assertArrayHasKey($brokenFile, $registry->skippedFiles(), 'the two rosters stay separate: the failure belongs to skippedFiles()');
    }

    /** Recomputed per call, so a shadow never outlives the walk that caused it. */
    public function testShadowedFilesIsRecomputedByEachList(): void
    {
        $dir = $this->tempDir . '/agents';
        $this->writePreset($dir, 'shared', 'only');

        $registry = new AgentPresetRegistry([$dir]);
        $registry->list();
        $this->assertSame([], $registry->shadowedFiles());

        $second = $this->tempDir . '/agents2';
        $loser = $this->writePreset($second, 'shared', 'loser');
        $registry = new AgentPresetRegistry([$dir, $second]);
        $registry->list();
        $this->assertSame([$loser => $dir . '/shared.md'], $registry->shadowedFiles());

        unlink($loser);
        $registry->list();
        $this->assertSame([], $registry->shadowedFiles(), 'with the loser gone the same registry reports no displacement');
    }

    /**
     * THE LAUNCH PATH: project and user tiers both ship `reviewer.md`, the
     * user copy wins, and the displaced project file reaches the operator by
     * path — through the real {@see Bootstrap::agentPresets()} search-path
     * derivation, not a hand-passed order. HOME is sandboxed for the window,
     * in both spellings, exactly as {@see \SugarCraft\Code\Tests\Cli\AgentManagerWiringTest}
     * does it, so `trustedConfigDirPath()` resolves to the fixture home.
     */
    public function testALaunchLetsTheUserPresetWinAndNoticesTheDisplacedProjectFileByPath(): void
    {
        $home = $this->tempDir . '/home';
        $repo = $this->tempDir . '/repo';
        mkdir($home, 0700, true);
        mkdir($repo, 0755, true);
        $originalHome = getenv('HOME') ?: '';
        $originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home;

        try {
            $userFile = $this->writePreset($home . '/.candy-code/agents', 'reviewer', "The user's reviewer");
            $projectFile = $this->writePreset($repo . '/.candy-code/agents', 'reviewer', 'The project reviewer');

            $presets = Bootstrap::agentPresets($repo);

            $this->assertSame($userFile, $presets['reviewer']->sourcePath, 'the operator\'s own preset must win the name');
            $this->assertStringNotContainsString('The project reviewer', $presets['reviewer']->description ?? '');

            $notices = implode("\n", [...Bootstrap::launchNotices(), ...Bootstrap::launchNoticesDropped()]);
            $this->assertStringContainsString(
                sprintf(Bootstrap::AGENT_PRESET_SHADOW_NOTICE_FORMAT, 1, '', $projectFile . ' — kept ' . $userFile),
                $notices,
                'the displaced file is reported with BOTH paths on one row',
            );
        } finally {
            if ($originalHome !== '') {
                putenv('HOME=' . $originalHome);
            } else {
                putenv('HOME');
            }

            if ($originalServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $originalServerHome;
            }
        }
    }

    /**
     * THE SOURCE PATH TRAVELS: `Agent::fromPreset()` copies the field, so the
     * rostered agent behind `/agent <name>` can name its file, and the launch
     * notice above is able to say WHERE, not just WHICH.
     */
    public function testTheRosteredAgentCarriesThePresetSourcePath(): void
    {
        $dir = $this->tempDir . '/agents';
        $file = $this->writePreset($dir, 'my-agent', 'described');

        $preset = (new AgentPresetRegistry([$dir]))->list()['my-agent'];
        $agent = \SugarCraft\Code\Agents\Agent::fromPreset($preset, 'anthropic', 'm');

        $this->assertSame($file, $agent->sourcePath);
        $this->assertArrayNotHasKey('source_path', $agent->toArray(), 'the path is display plumbing for this launch, not a persisted frame field');
        $this->assertArrayNotHasKey('sourcePath', $agent->toArray());
    }

    private function writePreset(string $dir, string $name, string $description, ?string $frontmatter = null): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $name . '.md';
        $frontmatter ??= "name: {$name}\ndescription: {$description}";
        file_put_contents($file, "---\n{$frontmatter}\n---\n");

        return $file;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }

        rmdir($dir);
    }
}
