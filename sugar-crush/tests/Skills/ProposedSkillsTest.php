<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\ProposedSkills;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillProposal;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.4-3's propose mode: the draft store the dream pass writes to and
 * `/skills accept|reject` reads from — never a live skills directory, every
 * field of the model's proposal checked on the way in, and a name that can
 * never be a path.
 */
final class ProposedSkillsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-proposed-' . bin2hex(random_bytes(6));
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

    public function testAProposalIsWrittenAsAnOwnerOnlyDraftOutsideTheLiveTree(): void
    {
        $store = ProposedSkills::new();

        $name = $store->propose(SkillProposal::new('Release Checklist', 'Use when cutting a release: tag, notes, publish.', "1. Tag.\n2. Write notes.\n3. Publish."));

        self::assertSame('release-checklist', $name);
        self::assertSame($this->home . '/.sugar-crush/skills-proposed', $store->root());
        $file = $store->root() . '/release-checklist/SKILL.md';
        self::assertFileExists($file);
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills', 'a proposal never touches the live tree');
        self::assertSame(0700, fileperms($store->root()) & 0777);
        self::assertSame(0700, fileperms(\dirname($file)) & 0777);
        self::assertSame(0600, fileperms($file) & 0777);

        $skill = Skill::fromFile($file);
        self::assertSame('Use when cutting a release: tag, notes, publish.', $skill->description);
        self::assertStringContainsString('2. Write notes.', $skill->content);
    }

    public function testTheNameIsSanitisedSoItCanNeverBeAPath(): void
    {
        self::assertSame('skills-evil', ProposedSkills::sanitizeName('../../skills/evil'));
        self::assertSame('etc-passwd', ProposedSkills::sanitizeName('/etc/passwd'));
        self::assertNull(ProposedSkills::sanitizeName('../..'));
        self::assertSame(ProposedSkills::MAX_NAME_LENGTH, \strlen((string) ProposedSkills::sanitizeName(str_repeat('a', 200))));

        $store = ProposedSkills::new();
        $name = $store->propose(SkillProposal::new('../../.sugar-crush/skills/evil', 'd', 'b'));

        self::assertSame('sugar-crush-skills-evil', $name);
        self::assertFileExists($store->root() . '/sugar-crush-skills-evil/SKILL.md');
        self::assertFileDoesNotExist($this->home . '/.sugar-crush/skills/evil/SKILL.md');
    }

    public function testSecretsAreRedactedInEveryField(): void
    {
        $store = ProposedSkills::new();
        $token = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

        $name = $store->propose(SkillProposal::new('deploy', "Deploy with {$token}", "Run `deploy --token {$token}` then check.\napi_key = 9fK2xQ7pL0mZ4vB8"));

        $text = (string) file_get_contents($store->root() . '/' . $name . '/SKILL.md');
        self::assertStringNotContainsString($token, $text);
        self::assertStringNotContainsString('9fK2xQ7pL0mZ4vB8', $text);
        self::assertStringContainsString('[REDACTED]', $text);
    }

    public function testOversizedEmptyOrUnparseableProposalsAreRefused(): void
    {
        $store = ProposedSkills::new();

        foreach ([
            'empty name' => SkillProposal::new('---', 'd', 'b'),
            'empty description' => SkillProposal::new('a', '  ', 'b'),
            'empty body' => SkillProposal::new('a', 'd', "\n "),
            'long description' => SkillProposal::new('a', str_repeat('x', ProposedSkills::MAX_DESCRIPTION_CHARS + 1), 'b'),
            'large body' => SkillProposal::new('a', 'd', str_repeat('x', ProposedSkills::MAX_BODY_BYTES + 1)),
        ] as $case => $proposal) {
            try {
                $store->propose($proposal);
                self::fail("{$case} was written");
            } catch (\InvalidArgumentException) {
                self::assertDirectoryDoesNotExist((string) $store->root() . '/a', $case);
            }
        }

        // A description that would break the YAML is one quoted value.
        $name = $store->propose(SkillProposal::new('yaml', "when: \"quoted\" # not a comment\nsecond line", 'body'));
        self::assertSame('when: "quoted" # not a comment second line', Skill::fromFile($store->root() . '/' . $name . '/SKILL.md')->description);
    }

    public function testAWaitingDraftIsNeverOverwritten(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'first', 'one'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already waiting');
        try {
            $store->propose(SkillProposal::new('triage', 'second', 'two'));
        } finally {
            self::assertSame('first', Skill::fromFile($store->root() . '/triage/SKILL.md')->description);
        }
    }

    public function testASymlinkedDraftsTreeIsRefused(): void
    {
        mkdir($this->home . '/.sugar-crush/skills', 0700, true);
        symlink($this->home . '/.sugar-crush/skills', $this->home . '/.sugar-crush/skills-proposed');

        try {
            ProposedSkills::new()->propose(SkillProposal::new('x', 'd', 'b'));
            self::fail('a draft was written through a symlink into the live tree');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }
        self::assertSame([], array_diff(scandir($this->home . '/.sugar-crush/skills') ?: [], ['.', '..']));
    }

    public function testWithNoOwnedHomeThereIsNoStore(): void
    {
        chmod($this->home, 0777);
        try {
            $store = ProposedSkills::new();
            self::assertNull($store->root());
            self::assertSame([], $store->drafts());
            $this->expectException(\RuntimeException::class);
            $store->propose(SkillProposal::new('x', 'd', 'b'));
        } finally {
            chmod($this->home, 0700);
        }
    }

    public function testDraftsListsEachWaitingDraft(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('zeta', 'last', 'z'));
        $store->propose(SkillProposal::new('alpha', 'first', 'a'));
        mkdir($store->root() . '/broken', 0700);
        file_put_contents($store->root() . '/broken/SKILL.md', "no frontmatter\n");

        $drafts = $store->drafts();

        self::assertSame(['alpha', 'broken', 'zeta'], array_column($drafts, 'name'));
        self::assertSame('first', $drafts[0]['description']);
        self::assertNull($drafts[1]['description']);
        self::assertStringContainsString('frontmatter', (string) $drafts[1]['error']);
    }

    public function testAcceptPromotesTheDraftIntoTheUserTierAndDeletesIt(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'Use when triaging a bug.', 'Steps.'));

        $path = $store->accept('triage', false, $this->project);

        self::assertSame($this->home . '/.sugar-crush/skills/triage/SKILL.md', $path);
        self::assertSame('Use when triaging a bug.', Skill::fromFile($path)->description);
        self::assertDirectoryDoesNotExist($store->root() . '/triage');
        self::assertSame([], $store->drafts());
    }

    public function testAcceptRefusesAnExistingLiveSkillUnlessReplace(): void
    {
        $live = $this->home . '/.sugar-crush/skills/triage';
        mkdir($live, 0700, true);
        file_put_contents($live . '/SKILL.md', "---\ndescription: mine\n---\n\nMy own steps.\n");
        file_put_contents($live . '/notes.txt', 'kept');
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'proposed', 'Proposed steps.'));

        try {
            $store->accept('triage', false, $this->project);
            self::fail('a live skill was replaced without --replace');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exists already', $e->getMessage());
            self::assertStringContainsString('--replace', $e->getMessage());
        }
        self::assertSame('mine', Skill::fromFile($live . '/SKILL.md')->description);
        self::assertFileExists($store->root() . '/triage/SKILL.md', 'the refused draft stays');

        $store->accept('triage', true, $this->project);

        self::assertSame('proposed', Skill::fromFile($live . '/SKILL.md')->description);
        self::assertSame('kept', file_get_contents($live . '/notes.txt'), 'a replace rewrites SKILL.md only');
        self::assertDirectoryDoesNotExist($store->root() . '/triage');
    }

    public function testAcceptRefusesANameAnotherTierAlreadyServes(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('security-audit', 'a dream copy', 'x'));
        $project = $this->project . '/.sugar-crush/skills/deploy';
        mkdir($project, 0700, true);
        file_put_contents($project . '/SKILL.md', "---\ndescription: the repo's\n---\n\nbody\n");
        $store->propose(SkillProposal::new('deploy', 'a dream copy', 'x'));

        foreach (['security-audit' => 'built-in', 'deploy' => 'project'] as $name => $tier) {
            try {
                $store->accept($name, false, $this->project);
                self::fail("{$name} shadowed the {$tier} skill without --replace");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString("a {$tier} skill", $e->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills');
    }

    public function testAcceptAndRejectRefusePathTraversalAndMissingDrafts(): void
    {
        $store = ProposedSkills::new();
        mkdir($this->home . '/.sugar-crush/skills/victim', 0700, true);
        file_put_contents($this->home . '/.sugar-crush/skills/victim/SKILL.md', "---\ndescription: v\n---\n\nv\n");

        foreach (['../skills/victim', '..', '/etc', 'Upper', 'a/b', '.hidden', ''] as $name) {
            foreach (['accept', 'reject'] as $action) {
                try {
                    $action === 'accept' ? $store->accept($name) : $store->reject($name);
                    self::fail("{$action} {$name} was not refused");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('not a draft name', $e->getMessage());
                }
            }
        }
        self::assertFileExists($this->home . '/.sugar-crush/skills/victim/SKILL.md');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no draft named "ghost"');
        $store->reject('ghost');
    }

    public function testAcceptRefusesADraftTheLoaderRejects(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('broken', 'd', 'b'));
        file_put_contents($store->root() . '/broken/SKILL.md', "no frontmatter at all\n");

        try {
            $store->accept('broken', false, $this->project);
            self::fail('a draft without frontmatter was promoted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('frontmatter', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->home . '/.sugar-crush/skills/broken');
    }

    public function testRejectDeletesTheDraftWithoutFollowingLinks(): void
    {
        $store = ProposedSkills::new();
        $store->propose(SkillProposal::new('triage', 'd', 'b'));
        $outside = $this->sandbox . '/outside';
        mkdir($outside, 0700);
        file_put_contents($outside . '/keep.txt', 'keep');
        symlink($outside, $store->root() . '/triage/link');

        $store->reject('triage');

        self::assertDirectoryDoesNotExist($store->root() . '/triage');
        self::assertFileExists($outside . '/keep.txt');
    }
}
