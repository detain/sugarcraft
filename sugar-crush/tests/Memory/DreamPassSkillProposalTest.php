<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\DreamPassCompletedMsg;
use SugarCraft\Crush\Memory\AutoMemoryConsolidator;
use SugarCraft\Crush\Memory\CompactionJournal;
use SugarCraft\Crush\Memory\DreamPass;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\ProposedSkills;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

use function React\Promise\resolve;

/**
 * Roadmap 5.4-3, the user decision of 2026-10-10: the dream pass stays limited
 * to memory notes and never edits a live skill; with the user-tier
 * `memory.dreamProposeSkills` on it may PROPOSE skills, each written as a draft
 * under `~/.sugar-crush/skills-proposed/` and announced in its one notice.
 */
final class DreamPassSkillProposalTest extends TestCase
{
    use HomeSandboxTrait;

    private const ANSWER = '{"operations":[],"skills":[{"name":"Release Checklist","description":"Use when cutting a release.","body":"1. Tag.\n2. Publish."}]}';

    private string $sandbox = '';
    private string $home = '';
    private string $root = '';
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-dream-skills-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/user-home');
        $this->root = $this->sandbox . '/root';
        mkdir($this->sandbox . '/memory', 0700, true);
        mkdir($this->root, 0700, true);
        $this->store = new MemoryStore($this->sandbox . '/memory');
        putenv(AutoMemoryConsolidator::ENV_DISABLE);
        self::assertTrue(CompactionJournal::forStore($this->store)->append('c1', ['r1' => 'Released again: tag, notes, publish.'], ['manual']));
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testTheSettingIsOffByDefaultAndOnlyExactlyTrueTurnsItOn(): void
    {
        $pass = DreamPass::new(MemoryWriter::new($this->store, ''));

        self::assertFalse($pass->proposesSkills(), 'no config: off');
        self::assertFalse($pass->proposesSkills([]));
        self::assertFalse($pass->proposesSkills([DreamPass::SETTING_PROPOSE_SKILLS => 'true']));
        self::assertFalse($pass->proposesSkills([DreamPass::SETTING_PROPOSE_SKILLS => 1]));
        self::assertTrue($pass->proposesSkills([DreamPass::SETTING_PROPOSE_SKILLS => true]));
        self::assertTrue($pass->withProposeSkills(true)->proposesSkills([]));

        mkdir($this->home . '/.sugar-crush', 0700, true);
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode([DreamPass::SETTING_PROPOSE_SKILLS => true]));
        self::assertTrue($pass->proposesSkills(), 'read from the user\'s config.json');
    }

    public function testWithTheSettingOffNoDraftIsWrittenAndNoProposalIsInvited(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: self::ANSWER)]);

        $msg = $this->settled($this->pass()->call($this->engine($provider), false, 's'));

        self::assertSame([], $msg->proposed);
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills-proposed', 'a skills list is ignored while the setting is off');
        self::assertStringNotContainsString('Skill proposals are switched on', self::prompt($provider->requests[0]));
        self::assertNull(DreamPass::notice($msg), 'a pass that changed and proposed nothing says nothing');
    }

    public function testWithTheSettingOnTheProposalIsADraftAndNeverALiveSkill(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: self::ANSWER)]);

        $msg = $this->settled($this->pass()->withProposeSkills(true)->call($this->engine($provider), false, 's'));

        self::assertStringContainsString('Skill proposals are switched on', self::prompt($provider->requests[0]));
        self::assertSame(['release-checklist'], $msg->proposed);
        $draft = $this->home . '/.sugar-crush/skills-proposed/release-checklist/SKILL.md';
        self::assertSame('Use when cutting a release.', Skill::fromFile($draft)->description);
        self::assertSame(0600, fileperms($draft) & 0777);
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills', 'the dream never writes a live skill');
        self::assertTrue($msg->advanced);

        $notice = (string) DreamPass::notice($msg);
        self::assertStringContainsString('proposed 1 skill for review (`release-checklist`)', $notice);
        self::assertStringContainsString('/skills accept <name>', $notice);
    }

    public function testAHostileProposalCannotReachALiveSkillAndIsCountedAsRefused(): void
    {
        $live = $this->home . '/.sugar-crush/skills/deploy';
        mkdir($live, 0700, true);
        file_put_contents($live . '/SKILL.md', "---\ndescription: mine\n---\n\nmine\n");
        $answer = json_encode(['operations' => [], 'skills' => [
            ['name' => '../skills/deploy', 'description' => 'overwrite yours', 'body' => 'evil'],
            ['name' => 'deploy', 'description' => 'same name as yours', 'body' => 'still only a draft'],
            ['name' => 'huge', 'description' => 'too big', 'body' => str_repeat('x', ProposedSkills::MAX_BODY_BYTES + 1)],
            ['name' => 'fourth', 'description' => 'over the per-pass cap', 'body' => 'x'],
        ]]);
        $provider = new ScriptedProvider([new CompleteResponse(content: (string) $answer)]);

        $msg = $this->settled($this->pass()->withProposeSkills(true)->call($this->engine($provider), false, 's'));

        self::assertSame('mine', Skill::fromFile($live . '/SKILL.md')->description, 'the live skill is untouched');
        self::assertSame(['skills-deploy', 'deploy'], $msg->proposed);
        self::assertSame(1, $msg->skipped[DreamPass::SKIP_SKILL_PROPOSAL] ?? 0, 'the oversized one');
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills-proposed/fourth', 'at most three per pass');
        self::assertSame(['deploy'], array_values(array_diff(scandir($this->home . '/.sugar-crush/skills') ?: [], ['.', '..'])));
    }

    private function pass(): DreamPass
    {
        return DreamPass::new(MemoryWriter::new($this->store, ''))
            ->withRunner(static fn (EngineBackend $backend, array $prompt): PromiseInterface => resolve($backend->complete($prompt)));
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        return EngineBackend::new($provider, 'm')->withRoot($this->root);
    }

    private function settled(?\Closure $factory): DreamPassCompletedMsg
    {
        self::assertNotNull($factory);
        $resolved = null;
        $factory()->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });
        self::assertInstanceOf(DreamPassCompletedMsg::class, $resolved);

        return $resolved;
    }

    private static function prompt(CompleteRequest $request): string
    {
        $text = '';
        foreach (\SugarCraft\Crush\Context\TurnContextBlock::strip($request->messages) as $message) {
            if ($message->role() === 'user') {
                $text = $message->content();
            }
        }

        return $text;
    }
}
