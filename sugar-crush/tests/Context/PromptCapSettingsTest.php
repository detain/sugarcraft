<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4d remainder: the prompt-shaping constants that had no setting
 * yet — the standing-rule budget, the repo map's switch and section budget,
 * the `<env>` git diffs' switch and cap, the skill path nudge, the
 * launch-notice shelf and the project-note cap — each read at its real site,
 * each default the constant it replaced.
 */
final class PromptCapSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/crush_promptcaps_' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/repo', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->sandbox));

        parent::tearDown();
    }

    // ── rules.standingMaxBytes ──────────────────────────────────────────

    public function testTheStandingRuleBudgetIsASetting(): void
    {
        $packs = $this->sandbox . '/home/.sugar-crush/rulebooks';
        mkdir($packs, 0o700, true);
        file_put_contents($packs . '/big.md', "---\nname: Big\n---\n" . str_repeat('H', 6_000));

        self::assertSame(1, substr_count($this->renderPrompt(), str_repeat('H', 6_000)), 'the default budget renders it whole');

        Bootstrap::writeUserConfig([Runtime::SETTING_STANDING_MAX_BYTES => 4_000]);
        $rendered = $this->renderPrompt();
        self::assertSame(0, substr_count($rendered, 'HHHH'), 'over the configured budget it renders in no form');
        self::assertStringContainsString("' deferred: budget. Read ", $rendered, 'it is still named, by its pointer line');

        Bootstrap::writeUserConfig([Runtime::SETTING_STANDING_MAX_BYTES => 'lots']);
        self::assertSame(1, substr_count($this->renderPrompt(), str_repeat('H', 6_000)), 'nonsense keeps the default');
    }

    // ── repoMap.enabled / repoMap.maxBytes ──────────────────────────────

    public function testTheRepoMapsSectionBudgetIsASetting(): void
    {
        $block = RepoMapBlock::capture($this->workspace(30));

        self::assertSame(RepoMapBlock::MAX_SECTION_BYTES, $block->maxSectionBytes());
        self::assertSame($block, $block->withSettings([]), 'no settings is the same block');
        self::assertStringNotContainsString('omitted by the size limit', $block->render());

        $small = $block->withSettings([RepoMapBlock::SETTING_MAX_BYTES => 400]);
        self::assertSame(400, $small->maxSectionBytes());
        self::assertStringContainsString('omitted by the size limit', $small->render());
        self::assertStringContainsString('at most 400 bytes of entries', $small->render(), 'the header states what applies');

        self::assertSame($block, $block->withSettings([RepoMapBlock::SETTING_MAX_BYTES => RepoMapBlock::MAX_ENTRY_BYTES - 1]), 'below one entry is ignored');
    }

    public function testTheRepoMapCanBeSwitchedOff(): void
    {
        self::assertTrue(RepoMapBlock::enabledBySettings([]));
        self::assertTrue(RepoMapBlock::enabledBySettings([RepoMapBlock::SETTING_ENABLED => 'no']), 'only an explicit false');
        self::assertFalse(RepoMapBlock::enabledBySettings([RepoMapBlock::SETTING_ENABLED => false]));

        $root = $this->workspace(3);
        self::assertNotSame('', $this->repoMapOf($root)->render());

        Bootstrap::writeUserConfig([RepoMapBlock::SETTING_ENABLED => false]);
        self::assertSame('', $this->repoMapOf($root)->render(), 'the turn\'s Runtime leaves it out');

        Bootstrap::writeUserConfig([RepoMapBlock::SETTING_MAX_BYTES => 512], [RepoMapBlock::SETTING_ENABLED]);
        self::assertSame(512, $this->repoMapOf($root)->maxSectionBytes(), 'and lays the budget over the capture');
    }

    // ── env.gitDiffAfterWrites / env.diffMaxBytes ───────────────────────

    public function testTheEnvDiffSettingsAreReadOverTheDefaults(): void
    {
        $block = new EnvironmentBlock('/nowhere', 'm');

        self::assertTrue($block->diffsAfterWrites());
        self::assertSame(EnvironmentBlock::DIFF_MAX_BYTES, $block->diffMaxBytes());
        self::assertSame($block, $block->withSettings([]));

        $set = $block->withSettings([EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES => false, EnvironmentBlock::SETTING_DIFF_MAX_BYTES => 1_000]);
        self::assertFalse($set->diffsAfterWrites());
        self::assertSame(1_000, $set->diffMaxBytes());

        $copied = $set->withWriteSinceLastRender(false)->withVolatile(false);
        self::assertSame([false, 1_000], [$copied->diffsAfterWrites(), $copied->diffMaxBytes()], 'the derived copies carry them');

        $ignored = $block->withSettings([EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES => 'no', EnvironmentBlock::SETTING_DIFF_MAX_BYTES => EnvironmentBlock::MIN_DIFF_MAX_BYTES - 1]);
        self::assertSame($block, $ignored, 'nonsense keeps the defaults');
    }

    public function testTheDiffSectionsFollowTheSettings(): void
    {
        $repo = $this->gitRepoWithAnUnstagedEdit();

        $default = new EnvironmentBlock($repo, 'm');
        self::assertStringContainsString('Unstaged changes', $default->renderVolatile());
        self::assertStringContainsString(str_repeat('x', 900), $default->renderVolatile());

        $capped = $default->withSettings([EnvironmentBlock::SETTING_DIFF_MAX_BYTES => 300]);
        self::assertStringContainsString('Unstaged changes', $capped->renderVolatile());
        self::assertStringNotContainsString(str_repeat('x', 900), $capped->renderVolatile(), 'the section is held to the configured cap');

        $off = $default->withSettings([EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES => false]);
        self::assertStringNotContainsString('Unstaged changes', $off->renderVolatile(), 'no diff sections, even after a write');
        self::assertStringContainsString('Current branch', $off->renderVolatile(), 'the rest of the git section stays');
    }

    public function testTheTurnsRuntimeLaysTheEnvSettingsOverTheCapture(): void
    {
        Bootstrap::writeUserConfig([EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES => false]);

        $runtime = new Runtime($this->provider(), new HookManager(new HookRegistry()));
        $method = new \ReflectionMethod(Runtime::class, 'environmentSnapshot');
        $app = App::new($this->provider(), 'm')->withRoot($this->sandbox . '/repo');
        $block = $method->invoke($runtime, $app);

        self::assertFalse($block->diffsAfterWrites());
        self::assertSame($block, $method->invoke($runtime, $app), 'still one held block per Runtime');
    }

    // ── skills.pathNudges ───────────────────────────────────────────────

    public function testSkillPathNudgesCanBeTurnedOffWithoutRetiringTheSkill(): void
    {
        self::assertTrue(SkillPathNudge::enabled([]));
        self::assertTrue(SkillPathNudge::enabled([SkillPathNudge::SETTING_PATH_NUDGES => 'off']), 'only an explicit false');
        self::assertFalse(SkillPathNudge::enabled([SkillPathNudge::SETTING_PATH_NUDGES => false]));

        $registry = new SkillRegistry();
        $registry->register([
            'php-audit' => Skill::parse("---\ndescription: Security audit for PHP code\npaths:\n  - /src/**/*.php\n---\nbody", 'php-audit'),
        ]);
        $nudge = SkillPathNudge::new($registry);

        Bootstrap::writeUserConfig([SkillPathNudge::SETTING_PATH_NUDGES => false]);
        self::assertNull($nudge->forPath('/src/Foo.php'));
        self::assertSame([], $nudge->announced(), 'nothing is marked while it is off');

        Bootstrap::writeUserConfig([], [SkillPathNudge::SETTING_PATH_NUDGES]);
        self::assertStringContainsString('php-audit', (string) $nudge->forPath('/src/Foo.php'), 'switched back on, the skill is announced');
    }

    // ── memory.projectNoteMaxBytes ──────────────────────────────────────

    public function testTheProjectNoteCapIsASetting(): void
    {
        self::assertSame(ProjectMemoryWriter::MAX_CONTENT_BYTES, ProjectMemoryWriter::maxContentBytes([]));
        self::assertSame(100, ProjectMemoryWriter::maxContentBytes([ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES => 100]));
        self::assertSame(ProjectMemoryWriter::MAX_CONTENT_BYTES, ProjectMemoryWriter::maxContentBytes([ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES => 0]));

        $writer = ProjectMemoryWriter::createForRoot($this->sandbox . '/repo');
        self::assertNotNull($writer);
        Bootstrap::writeUserConfig([ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES => 100]);

        self::assertNotSame('', $writer->write(str_repeat('a', 100)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds 100 bytes');
        $writer->write(str_repeat('a', 101));
    }

    // ── notices.transcriptLimit ─────────────────────────────────────────

    public function testTheLaunchNoticeShelfIsASetting(): void
    {
        self::assertSame(Bootstrap::LAUNCH_NOTICE_LIMIT, Bootstrap::launchNoticeLimit());

        Bootstrap::writeUserConfig([Bootstrap::SETTING_LAUNCH_NOTICE_LIMIT => 2]);
        self::assertSame(2, Bootstrap::launchNoticeLimit());

        $this->resetLaunchNotices();
        try {
            $record = new \ReflectionMethod(Bootstrap::class, 'warnPermissionConfigInTranscript');
            foreach (['first notice', 'second notice', 'third notice'] as $notice) {
                $record->invoke(null, 'promptcaps: ' . $notice . ' ' . bin2hex(random_bytes(4)));
            }

            $notices = Bootstrap::launchNotices();
            self::assertCount(3, $notices, 'two seated, then the one "and N more" row');
            self::assertCount(1, Bootstrap::launchNoticesDropped());
        } finally {
            $this->resetLaunchNotices();
        }

        Bootstrap::writeUserConfig([Bootstrap::SETTING_LAUNCH_NOTICE_LIMIT => -1]);
        self::assertSame(Bootstrap::LAUNCH_NOTICE_LIMIT, Bootstrap::launchNoticeLimit(), 'nonsense keeps the default');
    }

    // ── the schema ──────────────────────────────────────────────────────

    public function testTheSchemaRowsCarryTheConstantsAndTheirTiers(): void
    {
        $rows = [
            Runtime::SETTING_STANDING_MAX_BYTES => [Runtime::MAX_STANDING_RULE_BYTES, false, false, RiskClass::Prompt, ApplyMode::NextTurn],
            RepoMapBlock::SETTING_ENABLED => [true, true, true, RiskClass::Narrowing, ApplyMode::NextTurn],
            RepoMapBlock::SETTING_MAX_BYTES => [RepoMapBlock::MAX_SECTION_BYTES, true, false, RiskClass::Spend, ApplyMode::NextTurn],
            EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES => [true, true, true, RiskClass::Narrowing, ApplyMode::NextTurn],
            EnvironmentBlock::SETTING_DIFF_MAX_BYTES => [EnvironmentBlock::DIFF_MAX_BYTES, true, false, RiskClass::Spend, ApplyMode::NextTurn],
            SkillPathNudge::SETTING_PATH_NUDGES => [true, true, true, RiskClass::Narrowing, ApplyMode::NextTurn],
            Bootstrap::SETTING_LAUNCH_NOTICE_LIMIT => [Bootstrap::LAUNCH_NOTICE_LIMIT, false, false, RiskClass::Tuning, ApplyMode::Restart],
            ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES => [ProjectMemoryWriter::MAX_CONTENT_BYTES, false, false, RiskClass::Tuning, ApplyMode::Live],
        ];

        foreach ($rows as $key => [$default, $layered, $project, $risk, $apply]) {
            $definition = SettingsSchema::byKey($key);
            self::assertNotNull($definition, "{$key} has a schema row");
            self::assertSame($default, $definition->default, "{$key}'s default is the constant it replaced");
            self::assertSame($layered, $definition->layered, "{$key} layered");
            self::assertSame($project, $definition->projectSettable, "{$key} project-settable");
            self::assertSame($risk, $definition->riskClass, "{$key} risk");
            self::assertSame($apply, $definition->applyMode, "{$key} applies");
        }
    }

    // =====================================================================

    private function provider(): ProviderInterface
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        return $provider;
    }

    /** A fresh Runtime's system prompt (memoised per Runtime, so one per call). */
    private function renderPrompt(): string
    {
        $runtime = new Runtime($this->provider(), new HookManager(new HookRegistry()));
        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');

        return (string) $method->invoke(
            $runtime,
            App::new($this->provider(), 'gpt-4')->withRoot($this->sandbox . '/repo')->withRulesState(RulesState::new()),
        );
    }

    private function repoMapOf(string $root): RepoMapBlock
    {
        $runtime = new Runtime($this->provider(), new HookManager(new HookRegistry()));

        return (new \ReflectionMethod(Runtime::class, 'repoMapSnapshot'))->invoke(
            $runtime,
            App::new($this->provider(), 'm')->withRoot($root)->withSessionId('promptcaps-' . bin2hex(random_bytes(4))),
        );
    }

    /** A root with $count sub-packages, each with a long description. */
    private function workspace(int $count): string
    {
        $root = $this->sandbox . '/ws' . $count;
        mkdir($root, 0o700, true);
        file_put_contents($root . '/composer.json', json_encode(['name' => 'acme/root']));
        for ($i = 0; $i < $count; $i++) {
            $dir = sprintf('%s/pkg%02d', $root, $i);
            mkdir($dir);
            file_put_contents($dir . '/composer.json', json_encode([
                'name' => sprintf('acme/pkg%02d', $i),
                'description' => 'A package that exists to fill the repo map ' . str_repeat('.', 40),
                'autoload' => ['psr-4' => [sprintf('Acme\\Pkg%02d\\', $i) => 'src/']],
            ]));
        }

        return $root;
    }

    private function gitRepoWithAnUnstagedEdit(): string
    {
        exec('command -v git', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('git is not installed');
        }

        $repo = $this->sandbox . '/gitrepo';
        mkdir($repo);
        $git = 'git -C ' . escapeshellarg($repo) . ' -c user.email=t@example.com -c user.name=t -c commit.gpgsign=false ';
        exec($git . 'init -q 2>&1');
        file_put_contents($repo . '/a.txt', "one\n");
        exec($git . 'add a.txt 2>&1');
        exec($git . 'commit -q -m init 2>&1');
        file_put_contents($repo . '/a.txt', "one\n" . str_repeat('x', 1_200) . "\n");

        return $repo;
    }

    private function resetLaunchNotices(): void
    {
        foreach (['launchNotices', 'launchNoticesDropped'] as $property) {
            (new \ReflectionProperty(Bootstrap::class, $property))->setValue(null, []);
        }
    }
}
