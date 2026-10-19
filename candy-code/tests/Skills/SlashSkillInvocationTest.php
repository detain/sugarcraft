<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * VIS-5: a typed `/name args` whose name a user-invocable skill claims is
 * rewritten into the live `$name` mention channel instead of refused
 * (`Chat::skillInvocationDraft()`), so both spellings share one gate, one
 * `$ARGUMENTS` rule, and one set of notices.
 *
 * Driven through the real `submit()` path, per this family's standing
 * convention (`Commands\UnknownSlashCommandTest`): precedence — built-in
 * spellings and command-file names win before the rewrite ever runs — is a
 * behavior of the whole chain, and a test that called the rewrite directly
 * could stay green while the call site moved above `dispatchCommand()`.
 */
final class SlashSkillInvocationTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-slash-skill-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/.candy-code/commands', 0700, true);
        mkdir($this->project . '/.candy-code/skills', 0700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    /**
     * A registry over real SKILL.md files under the project's skills dir, so
     * the disk walk behind the refusal notice's namesake half and the live
     * registry agree about what exists.
     *
     * @param array<string, string>                    $bodies       name => body
     * @param array<string, bool>                      $userInvocable names forced non-invocable
     * @param list<string>                             $disabled
     */
    private function registry(array $bodies, array $userInvocable = [], array $disabled = []): SkillRegistry
    {
        $skills = [];
        foreach ($bodies as $name => $body) {
            mkdir($this->project . '/.candy-code/skills/' . $name, 0700, true);
            $path = $this->project . '/.candy-code/skills/' . $name . '/SKILL.md';
            $front = "---\nname: {$name}\ndescription: the {$name} skill\n"
                . (($userInvocable[$name] ?? true) ? '' : "user-invocable: false\n")
                . "---\n";
            file_put_contents($path, $front . $body);
            $skills[$name] = Skill::fromFile($path);
        }

        $registry = new SkillRegistry();
        $registry->register($skills);
        foreach ($disabled as $name) {
            $registry->disable($name);
        }

        return $registry;
    }

    private function pressEnter(string $draft, SkillRegistry $registry): Chat
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            projectRoot: $this->project,
            commandLoader: new CommandLoader(),
            workspace: WorkspaceContext::new(root: $this->project, skills: $registry),
        );

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** The messages $draft appended to the two-message fixture history. */
    private function added(Chat $next): array
    {
        return array_slice($next->history, 2);
    }

    public function testATypedSlashNameDispatchesThroughTheMentionChannel(): void
    {
        $next = $this->pressEnter('/shipit review the diff', $this->registry(['shipit' => 'Ship the checked-in work.']));

        $this->assertTrue($next->inFlight, 'the slash spelling starts the turn the mention spelling would');
        $added = $this->added($next);
        $this->assertSame('$shipit review the diff', $added[0]->content, 'the user row carries the rewritten mention draft');
        $this->assertCount(1, $added[0]->attachments);
        $this->assertSame(
            $this->project . '/.candy-code/skills/shipit/SKILL.md',
            $added[0]->attachments[0]->path,
            'the body snapshot rides the row exactly as a typed mention would',
        );
        $this->assertSame('Ship the checked-in work.', $added[0]->attachments[0]->data);
        $this->assertSame('shipit', $added[0]->attachments[0]->skill);
        foreach ($added as $row) {
            $this->assertStringNotContainsString('Nothing is named', $row->content ?? '', 'the rewrite skipped the refusal');
        }
    }

    public function testTheColonFormRidesTheSameChannel(): void
    {
        $next = $this->pressEnter('/shipit:review the diff', $this->registry(['shipit' => 'x']));

        $this->assertTrue($next->inFlight);
        $this->assertSame('$shipit review the diff', $this->added($next)[0]->content, 'the colon is a separator, not part of the argument');
    }

    public function testArgumentsArriveWhereTheBodyAsksForThem(): void
    {
        $next = $this->pressEnter('/fix the login timeout', $this->registry(['fix' => 'Fix this: $ARGUMENTS']));

        $this->assertSame('Fix this: the login timeout', $this->added($next)[0]->attachments[0]->data);
    }

    public function testACaseFoldedSkillNameDispatchesAndCanonicalises(): void
    {
        $next = $this->pressEnter('/SHIPIT now', $this->registry(['shipit' => 'x']));

        $this->assertTrue($next->inFlight);
        $this->assertSame('$shipit now', $this->added($next)[0]->content, 'the rewritten name is the registered one');
    }

    public function testABuiltInSpellingWinsTheNameBeforeTheSkillDoes(): void
    {
        $next = $this->pressEnter('/keys', $this->registry(['keys' => 'shadowing body']));

        $this->assertFalse($next->inFlight, 'the built-in /keys command is dispatched, not a shadowing skill');
        $this->assertSame([], $this->added($next), 'a command row is not a user turn');
    }

    /**
     * The guard's own leg: a claimed built-in name whose `accepts()` gate
     * DECLINED the argument stays prose even when a skill shares the name —
     * the rewrite may not reopen a door the built-in spelling closed (the
     * prose-ness of `/exit now` is pinned behaviour, not a typo).
     */
    public function testAClaimedNameThatDeclinedItsArgumentDoesNotDispatchAsASkill(): void
    {
        $next = $this->pressEnter('/exit now', $this->registry(['exit' => 'shadowing body']));

        $this->assertTrue($next->inFlight, 'it is still the pinned prose turn');
        $this->assertSame('/exit now', $this->added($next)[0]->content, 'sent as typed, not rewritten to a mention');
        $this->assertSame([], $this->added($next)[0]->attachments, 'the shadowing skill stays out of it');
    }

    public function testADisabledSkillDoesNotDispatchByTheSlashDoor(): void
    {
        $next = $this->pressEnter('/off', $this->registry(['off' => 'x'], disabled: ['off']));

        $this->assertFalse($next->inFlight, 'the registry gate that refuses $off refuses /off');
        $this->assertStringContainsString('Nothing is named', $this->added($next)[0]->content);
    }

    public function testANonUserInvocableSkillDoesNotDispatchEither(): void
    {
        $next = $this->pressEnter('/hidden', $this->registry(['hidden' => 'x'], userInvocable: ['hidden' => false]));

        $this->assertFalse($next->inFlight);
        $this->assertStringContainsString('Nothing is named', $this->added($next)[0]->content);
    }

    public function testAWordNoSkillClaimsIsStillRefused(): void
    {
        $next = $this->pressEnter('/nope', $this->registry(['shipit' => 'x']));

        $this->assertFalse($next->inFlight);
        $this->assertStringContainsString('Nothing is named `/nope`', $this->added($next)[0]->content);
    }

    /**
     * The fail-closed half: the namesake roster is a DISK walk, the dispatch
     * gate is the REGISTRY — when the two disagree (a skill on disk the
     * session never registered), the user gets the refusal, and the notice's
     * skill half now points at the mention spelling instead of the retired
     * "not typed as slash commands" sentence.
     */
    public function testTheNamesakeNoticeNowPointsAtTheMentionSpelling(): void
    {
        mkdir($this->project . '/.candy-code/skills/diskonly', 0700, true);
        file_put_contents(
            $this->project . '/.candy-code/skills/diskonly/SKILL.md',
            "---\ndescription: Disk only\n---\nBody.",
        );

        $added = $this->added($this->pressEnter('/diskonly', new SkillRegistry()));

        $this->assertStringContainsString('Nothing is named', $added[0]->content);
        $this->assertStringContainsString('`diskonly` names a skill', $added[0]->content);
        $this->assertStringContainsString('`$diskonly` mention', $added[0]->content);
    }

    /**
     * w3c review m-2 fold: a skill whose own name carries a colon — the
     * `ns:sub` spelling the loader uses for namespaced skills — was
     * unreachable from the slash door: the head-truncated lookup matched
     * `ns` (no such skill) and the colon half read as arguments. The full
     * colon form is now tried first, so both doors reach it.
     */
    public function testAColonNamespacedSkillIsInvokedByItsFullName(): void
    {
        $next = $this->pressEnter('/w4cns:w4csub do the thing', $this->registry(['w4cns:w4csub' => 'Namespaced body: $ARGUMENTS']));

        $this->assertTrue($next->inFlight, 'the colon name resolves instead of dying in the refusal');
        $added = $this->added($next);
        $this->assertSame('$w4cns:w4csub do the thing', $added[0]->content, 'the mention keeps the full colon spelling');
        $this->assertCount(1, $added[0]->attachments);
        $this->assertSame('w4cns:w4csub', $added[0]->attachments[0]->skill);
    }

    public function testTheColonNamespacedFormStillAcceptsTheColonArgumentSeparator(): void
    {
        $next = $this->pressEnter('/w4cns:w4csub:quickly', $this->registry(['w4cns:w4csub' => 'x']));

        $this->assertTrue($next->inFlight);
        $this->assertSame('$w4cns:w4csub quickly', $this->added($next)[0]->content, 'a second colon is the separator, not part of the name');
    }

    public function testTheHeadKeepsWinningWhenItIsTheSkillThatExists(): void
    {
        // Regression fence for the reordering: the full colon form is tried
        // first, but a miss there must fall back to the head exactly as
        // before, so /shipit:review still arguments shipit.
        $next = $this->pressEnter('/shipit:review the diff', $this->registry(['shipit' => 'x']));

        $this->assertTrue($next->inFlight);
        $this->assertSame('$shipit review the diff', $this->added($next)[0]->content);
    }
}
