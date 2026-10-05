<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\SkillsHostCommand;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Skills\ProposedSkills;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillProposal;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * `/skills proposed|accept|reject` (roadmap 5.4-3's propose mode): the user's
 * review of the dream pass's skill drafts — and the guarantee that promotion
 * is the user's action only, never a tool's or an agent's.
 */
final class SkillsHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-skills-cmd-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testProposedListsTheDraftsAndBareSkillsDoesTheSame(): void
    {
        self::assertStringContainsString('No skill drafts are waiting', $this->reply('/skills proposed'));

        ProposedSkills::new()->propose(SkillProposal::new('triage', 'Use when triaging a bug.', 'Steps.'));

        foreach (['/skills', '/skills proposed'] as $text) {
            $reply = $this->reply($text);
            self::assertStringContainsString('1 skill draft waiting', $reply);
            self::assertStringContainsString('`triage`', $reply);
            self::assertStringContainsString('Use when triaging a bug.', $reply);
        }
    }

    public function testAcceptPromotesAndRejectDeletes(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'Use when triaging.', 'Steps.'));
        $store->propose(SkillProposal::new('noise', 'Not useful.', 'x'));

        self::assertStringContainsString('Accepted the skill draft `triage`', $this->reply('/skills accept triage'));
        self::assertSame('Use when triaging.', Skill::fromFile($this->home . '/.sugar-crush/skills/triage/SKILL.md')->description);

        self::assertStringContainsString('Rejected and deleted the skill draft `noise`', $this->reply('/skills reject noise'));
        self::assertSame([], $store->drafts());
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills/noise');
    }

    public function testRefusalsAreSystemNoticesNotReplies(): void
    {
        mkdir($this->home . '/.sugar-crush/skills/triage', 0700, true);
        file_put_contents($this->home . '/.sugar-crush/skills/triage/SKILL.md', "---\ndescription: mine\n---\n\nmine\n");
        ProposedSkills::new()->propose(SkillProposal::new('triage', 'proposed', 'x'));

        foreach ([
            '/skills accept triage' => 'exists already',
            '/skills accept ../skills/triage' => 'not a draft name',
            '/skills reject ghost' => 'no draft named "ghost"',
            '/skills accept' => SkillsHostCommand::USAGE,
            '/skills promote triage' => SkillsHostCommand::USAGE,
        ] as $text => $expected) {
            $result = (new SkillsHostCommand())->run($this->context(), $text);
            $last = $result->rows[\count($result->rows) - 1];
            self::assertSame(Role::System, $last->role, $text);
            self::assertStringContainsString($expected, $last->content, $text);
        }
        self::assertSame('mine', Skill::fromFile($this->home . '/.sugar-crush/skills/triage/SKILL.md')->description);

        self::assertStringContainsString('Accepted', $this->reply('/skills accept triage --replace'));
        self::assertSame('proposed', Skill::fromFile($this->home . '/.sugar-crush/skills/triage/SKILL.md')->description);
    }

    public function testTheTuiDispatchesSkillsToTheHostCommand(): void
    {
        ProposedSkills::new()->propose(SkillProposal::new('triage', 'Use when triaging.', 'Steps.'));

        [$chat] = (new Chat(backend: new EchoBackend()))->runCommand('/skills accept triage');

        $last = $chat->history[\count($chat->history) - 1];
        self::assertStringContainsString('Accepted the skill draft `triage`', $last->content);
        self::assertTrue($last->uiOnly, 'the report is never shown to the model');
        self::assertFileExists($this->home . '/.sugar-crush/skills/triage/SKILL.md');
    }

    /**
     * The agent cannot promote: nothing a tool runs reaches
     * {@see ProposedSkills::accept()} — its one caller is the slash command —
     * and an agent's write into the drafts or the live tree is asked about in
     * every permission mode, so it can neither move a draft live nor plant
     * one that passes for a dream proposal.
     */
    public function testAnAgentCannotPromoteADraft(): void
    {
        $src = \dirname(__DIR__, 3) . '/src';
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $relative = substr($file->getPathname(), \strlen($src) + 1);
            if (str_contains($code, 'ProposedSkills') && preg_match('/->accept\(/', $code) === 1) {
                $callers[] = $relative;
            }
            if (str_starts_with($relative, 'Tools/')) {
                self::assertStringNotContainsString('ProposedSkills', $code, "{$relative}: a tool reaches the draft store");
                self::assertStringNotContainsString('SkillsHostCommand', $code, "{$relative}: a tool reaches /skills");
            }
        }
        sort($callers);
        self::assertSame(['Host/Commands/SkillsHostCommand.php'], $callers, 'the slash command is the one caller of ProposedSkills::accept()');

        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'd', 'b'));
        $draft = $this->home . '/.sugar-crush/skills-proposed/triage/SKILL.md';
        foreach ([
            ['Bash', ['command' => 'mv ~/.sugar-crush/skills-proposed/triage ~/.sugar-crush/skills/triage']],
            ['Bash', ['command' => 'cp ' . $draft . ' /tmp/x']],
            ['Write', ['file_path' => $this->home . '/.sugar-crush/skills/triage/SKILL.md', 'content' => 'x']],
            ['Write', ['file_path' => $this->home . '/.sugar-crush/skills-proposed/planted/SKILL.md', 'content' => 'x']],
            ['Edit', ['file_path' => $draft, 'old_string' => 'b', 'new_string' => 'c']],
        ] as [$tool, $args]) {
            foreach (PermissionMode::cases() as $mode) {
                $verdict = self::chainVerdict($tool, $args, $mode);
                self::assertFalse($verdict->permitsExecution(), "{$mode->value}: {$tool} " . json_encode($args) . ' ran unprompted');
            }
        }
        self::assertFileDoesNotExist($this->home . '/.sugar-crush/skills/triage/SKILL.md');

        // A lookalike name is another directory, as for `skills-archive`.
        self::assertTrue(self::chainVerdict('Write', ['file_path' => '.sugar-crush/skills-proposed-old/x.md', 'content' => 'x'], PermissionMode::BypassPermissions)->isAllowed());
        self::assertTrue(self::chainVerdict('Read', ['file_path' => $draft], PermissionMode::BypassPermissions)->isAllowed(), 'reading a draft grants nothing');
    }

    private function context(): CommandContext
    {
        return CommandContext::new(root: $this->project);
    }

    private function reply(string $text): string
    {
        $result = (new SkillsHostCommand())->run($this->context(), $text);
        self::assertFalse($result->isRefused());
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $last->content);

        return $last->content;
    }

    /** @param array<string, mixed> $args */
    private static function chainVerdict(string $tool, array $args, PermissionMode $mode): \SugarCraft\Crush\Hooks\HookResult
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new PermissionGateHook(new PermissionGate($mode)));

        return $manager->preToolUse(new HookContext(
            sessionId: 'test-session',
            toolName: $tool,
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        ));
    }
}
