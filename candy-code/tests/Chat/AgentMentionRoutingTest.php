<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * The submitDraft wiring of the '@name ...' agent address (RS-AGENT-C2):
 * a line-initial address to a preset this run holds enters the model's
 * history as the reserved '@agent:<name>' rewrite carrying the framed
 * delegation directive as an attachment (the EXISTING mention leg does the
 * attaching — this pins that the rewrite lands in ITS channel, which is how
 * the delegation reaches the Task machinery); an address to a name the
 * roster does not hold is refused with the roster named and no turn sent;
 * and every non-address travels byte-identical, attachments and all, so
 * prose that merely mentions somebody keeps being prose.
 *
 * The TUI key path is deliberately not re-covered here — the same
 * submitDraft funnel is driven directly (its own public method), which is
 * what makes the attachment assertion possible without a scripted
 * provider; the end-to-end behavior of the '@agent:' spelling this rewrite
 * lands in is held by {@see \SugarCraft\Code\Tests\Cli\NonInteractiveMentionsTest}.
 */
final class AgentMentionRoutingTest extends TestCase
{
    use HomeSandboxTrait;

    private string $project;

    protected function setUp(): void
    {
        $sandbox = \sys_get_temp_dir().'/crush-mention-'.\bin2hex(\random_bytes(6));
        $this->project = $sandbox.'/project';
        \mkdir($this->project.'/.candy-code', 0700, true);
        $this->useHomeSandbox($sandbox.'/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        \exec('rm -rf '.\escapeshellarg(\dirname($this->project)).' 2>&1');
    }

    private function manager(string ...$names): AgentManager
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());

        foreach ($names as $name) {
            $manager->register(new Agent(
                name: $name,
                description: 'a probe preset',
                prompt: 'You are a probe.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ));
        }

        return $manager;
    }

    /** The backend double: records the FULL wire history it was dialed with. */
    private function recorder(): Backend
    {
        return new class implements Backend {
            /** @var list<list<Message>> */
            public array $wires = [];

            public function complete(
                array $history,
                ?callable $onToken = null,
                ?callable $onEvent = null,
            ): Message {
                $this->wires[] = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): \React\Promise\PromiseInterface {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }

    /**
     * Drive a typed line through the idle submit funnel.
     *
     * @return array{0: Chat, 1: mixed, 2: Backend}
     */
    private function submit(string $text, ?AgentManager $manager): array
    {
        $backend = $this->recorder();
        $chat = new Chat(
            history: [],
            backend: $backend,
            projectRoot: $this->project,
            agentManager: $manager,
        );

        // The route a user has (BudgetCommandTest's idiom): type the draft,
        // press Enter — the idle submit funnel on a real key path, not a
        // private call.
        foreach (\mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$after, $effect] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return [$after, $effect, $backend];
    }

    /** The user row the turn carries — history first, any recorded wire second. */
    private function userRow(Chat $chat, Backend $backend): Message
    {
        foreach ($chat->history as $message) {
            if ($message->role === Role::User && $message->content !== '') {
                return $message;
            }
        }

        foreach ($backend->wires as $wire) {
            foreach ($wire as $message) {
                if ($message->role === Role::User && $message->content !== '') {
                    return $message;
                }
            }
        }

        self::fail('no user row reached the history or the wire');
    }

    /** @return list<string> */
    private function keywordPaths(Message $row): array
    {
        $paths = [];

        foreach ($row->attachments as $attachment) {
            if ($attachment->type === AttachmentType::File) {
                $paths[] = $attachment->path;
            }
        }

        return $paths;
    }

    public function testAddressToAHeldPresetRewritesAndCarriesTheDelegationDirective(): void
    {
        [$after, $effect, $backend] = $this->submit('@researcher fix the flaky parser', $this->manager('researcher'));

        self::assertNotNull($effect, 'the address opens a turn, it is not a refusal');
        self::assertSame('', $after->inputBuf, 'a scheduled turn consumes the draft');

        $row = $this->userRow($after, $backend);
        self::assertSame('@agent:researcher fix the flaky parser', $row->content);

        // The delegation proof: the mention leg attached the framed
        // directive naming EXACTLY this preset — this is the text that
        // makes the model call Task with agent: "researcher".
        self::assertContains('@agent:researcher', $this->keywordPaths($row));
        $directive = '';

        foreach ($row->attachments as $attachment) {
            if ($attachment->path === '@agent:researcher') {
                $directive = (string) $attachment->data;
            }
        }

        self::assertStringContainsString('delegate this to agent `researcher`', $directive);
        self::assertStringContainsString('via the task tool', \strtolower($directive));
        self::assertStringContainsString('do not substitute another agent', \strtolower($directive));
    }

    public function testAddressToANameTheRosterDoesNotHoldIsRefusedWithTheRosterNamed(): void
    {
        [$after, $effect, $backend] = $this->submit('@nosuchagent do the thing', $this->manager('coder', 'planner'));

        self::assertNull($effect, 'the refusal costs no turn');
        self::assertSame([], $backend->wires, 'the model was never dialed');
        self::assertSame(
            '@nosuchagent do the thing',
            $after->inputBuf,
            'the draft is kept, exactly like the unknown-slash arm',
        );

        $notices = [];

        foreach ($after->history as $message) {
            if ($message->role === Role::System) {
                $notices[] = $message->content;
            }
        }

        self::assertCount(1, $notices);
        self::assertStringContainsString('"nosuchagent"', $notices[0]);
        self::assertStringContainsString('agents here are: coder, planner', $notices[0]);
        self::assertStringNotContainsString('was not attached', $notices[0]);
    }

    public function testMidLineMentionStaysByteIdenticalUnattachedProse(): void
    {
        $text = 'ask @researcher about the parser later';
        [$after, $effect, $backend] = $this->submit($text, $this->manager('researcher'));

        self::assertNotNull($effect, 'it is still the ordinary prose turn it always was');
        $row = $this->userRow($after, $backend);
        self::assertSame($text, $row->content, 'not a byte moved');
        // Vacuity leg for the attachment claim: an address-shaped name
        // mid-line must NOT have pulled the delegation directive along.
        self::assertNotContains('@agent:researcher', $this->keywordPaths($row));
    }

    public function testDomainAndEmailShapesAtAnyPositionStayProse(): void
    {
        foreach (['mail @example.com about it', 'contact a@b.com now', 'file @coder.md next to it'] as $text) {
            [$after, $effect, $backend] = $this->submit($text, $this->manager('coder', 'example'));

            self::assertNotNull($effect);
            self::assertSame($text, $this->userRow($after, $backend)->content);
        }
    }

    public function testTheKeywordColonSpellingsAreNeverRewrittenByTheAddressDoor(): void
    {
        // '@agent:researcher ...' arrives at the model AS TYPED (the
        // directive is the mention leg's job, not a double-rewrite), and a
        // bare '@diff' keeps its keyword meaning whatever the roster holds.
        [$after, $effect, $backend] = $this->submit('@agent:researcher fix it', $this->manager('researcher'));
        self::assertNotNull($effect);
        self::assertSame('@agent:researcher fix it', $this->userRow($after, $backend)->content);

        [$after, $effect, $backend] = $this->submit('@diff HEAD~3', $this->manager('diff'));
        self::assertNotNull($effect);
        self::assertSame('@diff HEAD~3', $this->userRow($after, $backend)->content);
    }

    public function testARunWithNoAgentManagerKeepsEveryAtLineAsProse(): void
    {
        // No roster is open: the door is closed and an address to nobody is
        // not refused — it is the plain text it always was.
        [$after, $effect, $backend] = $this->submit('@coder fix it', null);

        self::assertNotNull($effect);
        self::assertSame('@coder fix it', $this->userRow($after, $backend)->content);
        self::assertStringNotContainsString('agent address', $this->noticeText($after));
    }

    public function testTheEmptyRosterStillRefusesANameWithNobodyToDelegateTo(): void
    {
        // The manager exists but holds zero presets: the channel is OPEN
        // (roster-wise empty, not absent) and the honest answer names that.
        [$after, $effect, $backend] = $this->submit('@coder fix it', $this->manager());

        self::assertNull($effect);
        self::assertStringContainsString('no agents to delegate to', $this->noticeText($after));
    }

    public function testABackgroundAnnouncementIsUntouchedByTheDoor(): void
    {
        // C-WEB-2.c's law for this new arm: relayed text quoting an '@name'
        // is never an address. An announcement can never BE line-initial
        // '@name' anyway (its own header opens the line — the shape gate is
        // the containment), so this pins the law against a future move of
        // where the door is consulted: the relayed bytes travel identical.
        $text = "[Background session 'abc123' (coder) settled]\n\nplease ask @coder about the parser";
        [$after, $effect, $backend] = $this->submit($text, $this->manager('coder'));

        self::assertNotNull($effect);
        self::assertSame($text, $this->userRow($after, $backend)->content);
    }

    public function testBareAddressWithoutATaskRewritesLikeTheSkillDoorRewritesBare(): void
    {
        [$after, $effect, $backend] = $this->submit('@coder', $this->manager('coder'));

        self::assertNotNull($effect);
        self::assertSame('@agent:coder', $this->userRow($after, $backend)->content);
    }

    private function noticeText(Chat $chat): string
    {
        $text = '';

        foreach ($chat->history as $message) {
            if ($message->role === Role::System) {
                $text .= $message->content;
            }
        }

        return $text;
    }
}
