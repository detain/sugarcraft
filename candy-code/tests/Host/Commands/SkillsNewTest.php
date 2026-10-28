<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\ProposedSkills;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillProposal;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `/skills new <name> [description]` (C-EXT-3.c): the hand-typed scaffold
 * door into the review queue. Every pin here guards the same law the accept
 * pins guard from the other side — the verb WRITES A DRAFT AND NOTHING ELSE:
 * the file lands under `skills-proposed/<slug>/`, the name arrives sanitised,
 * a refused scaffold writes nothing, and no scaffold ever reaches the live
 * user tier without `/skills accept`.
 */
final class SkillsNewTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-skills-new-' . bin2hex(random_bytes(6));
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

    /** A scaffolded draft parses back with the typed name and description. */
    public function testNewWritesAScaffoldDraftThatParsesBack(): void
    {
        $notice = $this->draftedText('/skills new triage-bug Use when triaging a bug report.');

        self::assertStringContainsString('Drafted the skill `triage-bug`', $notice);
        self::assertStringContainsString('triage-bug/SKILL.md', $notice, 'the reply prints the draft path');

        $file = $this->home . '/.candy-code/skills-proposed/triage-bug/SKILL.md';
        self::assertFileExists($file);
        $skill = Skill::fromFile($file);
        self::assertSame('triage-bug', $skill->name);
        self::assertSame('Use when triaging a bug report.', $skill->description);
        self::assertStringContainsString('When to use this skill', (string) file_get_contents($file));

        self::assertDirectoryDoesNotExist(
            $this->home . '/.candy-code/skills/triage-bug',
            'a scaffold is a draft, never a live skill',
        );
    }

    /** The name is sanitised by the store's own door before anything is written. */
    public function testTheNameIsSanitisedBeforeWriting(): void
    {
        $notice = $this->draftedText('/skills new MySkill Uppercase arrives lower.');
        self::assertStringContainsString('`myskill`', $notice);
        self::assertFileExists($this->home . '/.candy-code/skills-proposed/myskill/SKILL.md');

        $escaped = $this->draftedText('/skills new ../Hidden traversal stays put.');
        self::assertStringContainsString('`hidden`', $escaped);
        self::assertFileExists($this->home . '/.candy-code/skills-proposed/hidden/SKILL.md');
        self::assertFileDoesNotExist($this->sandbox . '/home/.candy-code/skills-proposed/Hidden/SKILL.md');
    }

    /** A second scaffold under a waiting name refuses whole; the draft stands. */
    public function testASecondDraftWithTheSameNameRefusesWhole(): void
    {
        ProposedSkills::new()->propose(SkillProposal::new('dup', 'First seed note.', 'Original body.'));

        $notice = $this->systemNotice('/skills new dup second tries.');
        self::assertStringContainsString('already waiting', $notice);

        $file = $this->home . '/.candy-code/skills-proposed/dup/SKILL.md';
        self::assertSame('First seed note.', Skill::fromFile($file)->description);
        self::assertStringContainsString('Original body.', (string) file_get_contents($file));
    }

    /** A name-less or unholy scaffold is a notice with nothing written. */
    public function testRefusedScaffoldsWriteNothing(): void
    {
        foreach (['/skills new', '/skills new .. dots'] as $text) {
            $notice = $this->systemNotice($text);
            if ($text === '/skills new') {
                self::assertStringContainsString('Usage: /skills', $notice);
                self::assertStringContainsString('new <name> [description]', $notice, 'the usage line teaches the verb');
            } else {
                self::assertStringContainsString('Skills:', $notice);
            }
        }

        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/skills-proposed');
    }

    /**
     * The no-home arm from the command surface: with the home unowned (here:
     * world-writable, the same lever ProposedSkillsTest's store-level pin
     * pulls), the scaffold refuses through run()'s catch as a system notice
     * naming the missing home — and the draft tree is never created.
     */
    public function testNoOwnedHomeRefusesTheScaffoldAtTheCommandSurface(): void
    {
        chmod($this->home, 0777);
        try {
            $notice = $this->systemNotice('/skills new stranded-skill Draft cannot land.');
            self::assertStringContainsString('no home directory', $notice, 'the refusal names the missing home, not a generic error');
        } finally {
            chmod($this->home, 0700);
        }

        self::assertDirectoryDoesNotExist($this->home . '/.candy-code/skills-proposed');
    }

    /** The description defaults to the honest placeholder sentence. */
    public function testTheDescriptionDefaultsWhenLeftOff(): void
    {
        $this->draftedText('/skills new quiet-skill');

        $file = $this->home . '/.candy-code/skills-proposed/quiet-skill/SKILL.md';
        self::assertSame(
            Lang::t('host.skills.new.default_description'),
            Skill::fromFile($file)->description,
        );
    }

    /** Runs a scaffold form that must answer with the assistant's draft notice. */
    private function draftedText(string $text): string
    {
        $result = (new SkillsHostCommand())->run(CommandContext::new(root: $this->project), $text);
        self::assertFalse($result->isRefused());
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $last->content);

        return $last->content;
    }

    /** Runs a form that must answer with a system notice, never a reply. */
    private function systemNotice(string $text): string
    {
        $result = (new SkillsHostCommand())->run(CommandContext::new(root: $this->project), $text);
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::System, $last->role, $last->content);

        return $last->content;
    }
}
