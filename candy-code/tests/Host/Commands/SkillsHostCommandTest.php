<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\ProposedSkills;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillProposal;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

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

    /**
     * ISS-089 split the two questions the command answers: the bare form now
     * lists what is INSTALLED (the question with an answer in every session),
     * and the drafts keep their explicit word. A pending draft must not leak
     * into the table listing, and the table must not swallow the drafts.
     */
    public function testBareSkillsListsTheTableAndProposedKeepsTheDrafts(): void
    {
        self::assertStringContainsString('No skill drafts are waiting', $this->reply('/skills proposed'));

        ProposedSkills::new()->propose(SkillProposal::new('triage', 'Use when triaging a bug.', 'Steps.'));

        $drafts = $this->reply('/skills proposed');
        self::assertStringContainsString('1 skill draft waiting', $drafts);
        self::assertStringContainsString('`triage`', $drafts);
        self::assertStringContainsString('Use when triaging a bug.', $drafts);

        $table = $this->reply('/skills');
        self::assertStringContainsString('invocable skills:', $table);
        self::assertStringContainsString('Skill', $table);
        self::assertStringNotContainsString('skill draft waiting', $table, 'the table is not the draft reviewer any more');

        $installed = $this->reply('/skills installed');
        self::assertSame($table, $installed, 'the explicit word is the bare form');
    }

    /** A project skill loads into the table with its tier, and a disabled row says `no`. */
    public function testTheTableCarriesTiersAndTheDisabledFlag(): void
    {
        $this->writeShipLineSkill($this->project . '/.candy-code/skills', description: 'Ship  notes  for  a  release.');

        $row = self::row($this->reply('/skills'), 'shipline');
        self::assertSame('project', $row[2], 'the tier badge is the origin value');
        self::assertSame('yes', $row[3]);
        self::assertSame('Ship notes for a release.', $row[5], 'whitespace runs collapse to one space per cell');

        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents($this->home . '/.candy-code/config.json', '{"disabledSkills":["shipline"]}');
        $row = self::row($this->reply('/skills'), 'shipline');
        self::assertSame('no', $row[3], 'the On column answers for disabledSkills');
    }

    /** The shadow count: the loser files a same-name collision records, on the winner's row. */
    public function testTheSkipColumnCountsTheFilesThisSkillShadowed(): void
    {
        $this->writeShipLineSkill($this->home . '/.candy-code/skills', body: 'User body.');
        $this->writeShipLineSkill($this->project . '/.candy-code/skills', body: 'Project body.');

        // Different bytes (the writer calls alternate the body), so the loser
        // is NOT the byte-identical linked-copy case the loader stays silent
        // about — the shadow must be recorded. The registration order that
        // decides WHICH file loses is SkillOrigin::precedence()'s last-write-
        // wins walk (BuiltIn, Project, User), so the USER copy holds the name
        // and the project copy is the recorded loser.
        $row = self::row($this->reply('/skills'), 'shipline');
        self::assertSame('user', $row[2], 'the later-registered tier holds the name');
        self::assertSame('1', $row[4], 'one same-name file lost to this row');
    }

    /** `/skills installed extra` is a usage notice, matching the drafts arm's rule. */
    public function testTheInstalledWordTakesNoArgument(): void
    {
        foreach (['/skills installed extra', '/skills proposed extra'] as $text) {
            $result = (new SkillsHostCommand())->run($this->context(), $text);
            $last = $result->rows[\count($result->rows) - 1];
            self::assertSame(Role::System, $last->role, $text);
            self::assertStringContainsString('Usage: /skills', $last->content, $text);
        }
    }

    /** Lay one `shipline/SKILL.md` into the given skills root (tier directory). */
    private function writeShipLineSkill(string $skillsRoot, string $body = 'Body.', string $description = 'Ship notes for a release.'): void
    {
        $dir = $skillsRoot . '/shipline';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: shipline\ndescription: {$description}\n---\n\n{$body}\n");
    }

    /**
     * The shipline row of a rendered table, split into cells: the box borders
     * and the cell trim are mechanical, so index 2 is Tier, 3 On, 4 Skip, and
     * 5 the description — asserted by position because position IS the column
     * the test is about.
     *
     * @return list<string>
     */
    private static function row(string $table, string $name): array
    {
        foreach (\explode("\n", $table) as $line) {
            if (str_contains($line, $name)) {
                return array_map(\trim(...), \explode('│', $line));
            }
        }
        self::fail("no row named {$name} in:\n{$table}");
    }

    public function testAcceptPromotesAndRejectDeletes(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'Use when triaging.', 'Steps.'));
        $store->propose(SkillProposal::new('noise', 'Not useful.', 'x'));

        self::assertStringContainsString('Accepted the skill draft `triage`', $this->reply('/skills accept triage'));
        self::assertSame('Use when triaging.', Skill::fromFile($this->home . '/.candy-code/skills/triage/SKILL.md')->description);

        self::assertStringContainsString('Rejected and deleted the skill draft `noise`', $this->reply('/skills reject noise'));
        self::assertSame([], $store->drafts());
        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/skills/noise');
    }

    public function testRefusalsAreSystemNoticesNotReplies(): void
    {
        mkdir($this->home . '/.candy-code/skills/triage', 0700, true);
        file_put_contents($this->home . '/.candy-code/skills/triage/SKILL.md', "---\ndescription: mine\n---\n\nmine\n");
        ProposedSkills::new()->propose(SkillProposal::new('triage', 'proposed', 'x'));

        foreach ([
            '/skills accept triage' => 'exists already',
            '/skills accept ../skills/triage' => 'not a draft name',
            '/skills reject ghost' => 'no draft named "ghost"',
            '/skills accept' => SkillsHostCommand::usage(),
            '/skills promote triage' => SkillsHostCommand::usage(),
        ] as $text => $expected) {
            $result = (new SkillsHostCommand())->run($this->context(), $text);
            $last = $result->rows[\count($result->rows) - 1];
            self::assertSame(Role::System, $last->role, $text);
            self::assertStringContainsString($expected, $last->content, $text);
        }
        self::assertSame('mine', Skill::fromFile($this->home . '/.candy-code/skills/triage/SKILL.md')->description);

        self::assertStringContainsString('Accepted', $this->reply('/skills accept triage --replace'));
        self::assertSame('proposed', Skill::fromFile($this->home . '/.candy-code/skills/triage/SKILL.md')->description);
    }

    public function testTheTuiDispatchesSkillsToTheHostCommand(): void
    {
        ProposedSkills::new()->propose(SkillProposal::new('triage', 'Use when triaging.', 'Steps.'));

        [$chat] = (new Chat(backend: new EchoBackend()))->runCommand('/skills accept triage');

        $last = $chat->history[\count($chat->history) - 1];
        self::assertStringContainsString('Accepted the skill draft `triage`', $last->content);
        self::assertTrue($last->uiOnly, 'the report is never shown to the model');
        self::assertFileExists($this->home . '/.candy-code/skills/triage/SKILL.md');
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
        $draft = $this->home . '/.candy-code/skills-proposed/triage/SKILL.md';
        foreach ([
            ['Bash', ['command' => 'mv ~/.candy-code/skills-proposed/triage ~/.candy-code/skills/triage']],
            ['Bash', ['command' => 'cp ' . $draft . ' /tmp/x']],
            ['Write', ['file_path' => $this->home . '/.candy-code/skills/triage/SKILL.md', 'content' => 'x']],
            ['Write', ['file_path' => $this->home . '/.candy-code/skills-proposed/planted/SKILL.md', 'content' => 'x']],
            ['Edit', ['file_path' => $draft, 'old_string' => 'b', 'new_string' => 'c']],
        ] as [$tool, $args]) {
            foreach (PermissionMode::cases() as $mode) {
                $verdict = self::chainVerdict($tool, $args, $mode);
                self::assertFalse($verdict->permitsExecution(), "{$mode->value}: {$tool} " . json_encode($args) . ' ran unprompted');
            }
        }
        self::assertFileDoesNotExist($this->home . '/.candy-code/skills/triage/SKILL.md');

        // A lookalike name is another directory, as for `skills-archive`.
        self::assertTrue(self::chainVerdict('Write', ['file_path' => '.candy-code/skills-proposed-old/x.md', 'content' => 'x'], PermissionMode::BypassPermissions)->isAllowed());
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
    private static function chainVerdict(string $tool, array $args, PermissionMode $mode): \SugarCraft\Code\Hooks\HookResult
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
