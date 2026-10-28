<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SkillsHostCommand;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\LearnedSkills;
use SugarCraft\Code\Skills\SkillProposal;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `/skills history <name>` and `/skills undo <name>` (C-EXT-3.e) — the two
 * human-side doors of owner ruling R-EXT-2. The workshop lands a learned
 * skill live; these verbs are what makes that landing reversible: history
 * lists the saved window the undo walks, and undo restores the previous
 * revision (or removes the skill whole when revision 1 was its only
 * landing). A skill that arrived by hand or through `/skills accept` has no
 * saved revisions — both doors say so plainly instead of pretending a
 * history, and both stay silent about bytes they never wrote.
 */
final class SkillsHistoryUndoTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/cc-cmp-3e-cmd-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        $this->root = $this->home . '/.candy-code/skills';
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /** History lists every saved revision of a learned skill, oldest first. */
    public function testHistoryListsTheSavedRevisions(): void
    {
        $this->learn('hist-alpha', 'First landing.', 'Body one.');
        $this->learnPatch('hist-alpha', 'Second landing.', 'Body two.');

        $reply = $this->assistantText('/skills history hist-alpha');
        self::assertStringContainsString('Saved revisions of the learned skill `hist-alpha`', $reply);
        self::assertStringContainsString('1.', $reply);
        self::assertStringContainsString('2.', $reply);
        self::assertStringContainsString('bytes, saved', $reply);
    }

    /** A name with nothing saved is answered, not refused — and says so. */
    public function testHistoryOfAnUnlearnedNameSaysThereIsNothingSaved(): void
    {
        $reply = $this->assistantText('/skills history never-taught-here');
        self::assertStringContainsString('no saved revisions', $reply);
    }

    /** Undo steps the head back: the previous revision's bytes are live again. */
    public function testUndoRestoresThePreviousRevision(): void
    {
        $this->learn('undo-two', 'Revision one.', 'First body.');
        $firstBytes = (string) file_get_contents($this->root . '/undo-two/SKILL.md');
        $this->learnPatch('undo-two', 'Revision two.', 'Second body.');
        self::assertNotSame($firstBytes, (string) file_get_contents($this->root . '/undo-two/SKILL.md'));

        $reply = $this->assistantText('/skills undo undo-two');
        self::assertStringContainsString('revision 1 is live again', $reply);
        self::assertStringContainsString('undo-two/SKILL.md', $reply, 'the reply names the live path it restored');
        self::assertSame($firstBytes, (string) file_get_contents($this->root . '/undo-two/SKILL.md'));
    }

    /** Undo of the sole revision removes the learned skill and stays honest about residue. */
    public function testUndoRemovesASoleRevisionAndNamesLeftoverFiles(): void
    {
        $this->learn('undo-alone', 'Its only landing.', 'Body.');
        $reply = $this->assistantText('/skills undo undo-alone');
        self::assertStringContainsString('were removed', $reply);
        self::assertStringNotContainsString('directory stayed', $reply, 'nothing stayed, so nothing is claimed');
        self::assertDirectoryDoesNotExist($this->root . '/undo-alone');

        $this->learn('undo-stays', 'Only revision again.', 'Body.');
        mkdir($this->root . '/undo-stays/assets', 0700, true);
        file_put_contents($this->root . '/undo-stays/assets/mine.md', 'A user file.');

        $staying = $this->assistantText('/skills undo undo-stays');
        self::assertStringContainsString('were removed', $staying);
        self::assertStringContainsString('directory stayed', $staying);
        self::assertStringContainsString('undo-stays', $staying);
        self::assertFileExists($this->root . '/undo-stays/assets/mine.md');
    }

    /** Undoing a skill with no saved history is the run() catch's notice, by name. */
    public function testUndoOfAnUnlearnedSkillIsARefusalNotice(): void
    {
        mkdir($this->root . '/hand-rolled', 0700, true);
        file_put_contents($this->root . '/hand-rolled/SKILL.md', "---\nname: hand-rolled\ndescription: \"Typed.\"  \n---\n\nBody.\n");

        $notice = $this->noticeText('/skills undo hand-rolled');
        self::assertStringContainsString('no saved revision to undo', $notice);
        self::assertFileExists($this->root . '/hand-rolled/SKILL.md', 'the refusal touches nothing');
    }

    /** Wrong arity is the extended usage line, teaching both new verbs. */
    public function testWrongArityAnswersWithTheUsageLine(): void
    {
        foreach (['/skills history', '/skills history a b', '/skills undo', '/skills undo a b'] as $text) {
            $notice = $this->noticeText($text);
            self::assertStringContainsString('Usage: /skills', $notice, $text);
            self::assertStringContainsString('/skills history <name>', $notice, $text . ' teaches the history verb');
            self::assertStringContainsString('/skills undo <name>', $notice, $text . ' teaches the undo verb');
        }
    }

    /** With an unowned home both doors refuse through run()'s named error. */
    public function testNoOwnedHomeRefusesBothDoors(): void
    {
        chmod($this->home, 0777);
        clearstatcache(true, $this->home);
        try {
            self::assertStringContainsString('no home directory', $this->noticeText('/skills history anywhere-at-all'));
            self::assertStringContainsString('no home directory', $this->noticeText('/skills undo anywhere-at-all'));
        } finally {
            chmod($this->home, 0700);
        }

        self::assertDirectoryDoesNotExist($this->root);
    }

    /** Lands a learned skill through the store the workshop itself uses. */
    private function learn(string $name, string $description, string $body): void
    {
        LearnedSkills::new()->create(SkillProposal::new($name, $description, $body));
    }

    /** Patches a learned skill the same first-class way. */
    private function learnPatch(string $name, string $description, string $body): void
    {
        LearnedSkills::new()->patch($name, $description, $body);
    }

    /** Runs a form that must answer with the assistant's reply text. */
    private function assistantText(string $text): string
    {
        $result = (new SkillsHostCommand())->run(CommandContext::new(root: $this->project), $text);
        self::assertFalse($result->isRefused());
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $last->content);

        return $last->content;
    }

    /** Runs a form that must answer with a system notice, never a reply. */
    private function noticeText(string $text): string
    {
        $result = (new SkillsHostCommand())->run(CommandContext::new(root: $this->project), $text);
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::System, $last->role, $last->content);

        return $last->content;
    }
}
