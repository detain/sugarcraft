<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\Commands\BtwHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\RecapHostCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Usage;

/**
 * C-TUI-3.f: `/recap` is a tool-less side call on the title backend over the
 * `/btw` seam with a fixed question, landing as UI-only rows — no new message
 * type, no Chat arm, nothing sent to the agent.
 *
 * @see RecapHostCommand
 */
final class RecapHostCommandTest extends TestCase
{
    public function testWithoutATitleBackendTheRecapRefusesAndHoldsTheTurn(): void
    {
        $result = (new RecapHostCommand())->run(CommandContext::new(history: [Message::user('ship it')]), '/recap');

        self::assertTrue($result->holdsTurn);
        self::assertSame([], $result->effects);
        self::assertCount(2, $result->rows);
        [$echo, $refusal] = $result->rows;
        self::assertSame(Role::User, $echo->role);
        self::assertSame('/recap', $echo->content);
        self::assertTrue($echo->uiOnly);
        self::assertFalse($echo->error);
        self::assertSame(Role::Assistant, $refusal->role);
        self::assertTrue($refusal->uiOnly);
        self::assertTrue($refusal->error, 'the recap did not happen: the row lands flagged (ISS-097)');
        self::assertStringContainsString('needs a title model', $refusal->content);
    }

    public function testTheRecapIsAToolLessAsyncSideCallOnTheTitleBackend(): void
    {
        $title = $this->recapBackend('Left off mid-refactor in Chat.php; next the tests.');
        $context = CommandContext::new(
            history: [
                Message::user('fix the bug'),
                Message::assistant('Fixed; run the tests next.'),
            ],
            titleBackend: $title,
            sessionId: 'sess-1',
        );

        $result = (new RecapHostCommand())->run($context, '/recap extra words are no argument');

        self::assertTrue($result->holdsTurn);
        self::assertCount(1, $result->rows, 'the echo carries the whole draft, the answer comes as an effect');
        self::assertSame('/recap extra words are no argument', $result->rows[0]->content);
        self::assertTrue($result->rows[0]->uiOnly);

        $effect = $result->effect(CommandEffectKind::Async);
        self::assertNotNull($effect);
        $describe = $effect->describe();
        self::assertNotNull($describe, 'the headless driver gets its row mapper too');

        $settled = null;
        $effect->run()()->then(static function (mixed $msg) use (&$settled): void {
            $settled = $msg;
        });
        self::assertInstanceOf(SideQuestionAnsweredMsg::class, $settled);
        self::assertSame('sess-1', $settled->sessionId);
        self::assertSame('Left off mid-refactor in Chat.php; next the tests.', $settled->answer);
        self::assertNotNull($settled->usage, 'the side call is accounted like every other');

        self::assertCount(1, $title->asked, 'one call, on the title backend only');
        self::assertSame(Role::System, $title->asked[0][0]->role);
        self::assertSame(RecapHostCommand::FRAMING, $title->asked[0][0]->content, 'the recap voice, not the btw one');
        $ask = $title->asked[0][1]->content;
        self::assertStringContainsString(RecapHostCommand::PROMPT, $ask, 'the fixed question rides the envelope');
        self::assertStringContainsString('<transcript>', $ask);
        self::assertStringContainsString('fix the bug', $ask);
        self::assertStringContainsString('Fixed; run the tests next.', $ask);
        self::assertStringContainsString('do not call any tool', $ask, 'tool-less by the same IMPORTANT block');
        self::assertStringNotContainsString('extra words are no argument', $ask);

        $row = $describe($settled);
        self::assertInstanceOf(Message::class, $row);
        self::assertStringContainsString("**recap** — not sent to the agent\n\nLeft off mid-refactor", $row->content);
    }

    public function testTheServedRowCarriesTheRecapVoice(): void
    {
        $row = RecapHostCommand::rowMessage(new SideQuestionAnsweredMsg(null, 'We left off mid-refactor; next the tests.'));

        self::assertInstanceOf(Message::class, $row);
        self::assertSame(Role::Assistant, $row->role);
        self::assertTrue($row->uiOnly, 'the recap is shown, never sent');
        // Disclosed boundary mirrored from /btw: a failed recap is woven INTO
        // the answer text with no verdict field on the DTO, so the row cannot
        // be flagged and stays plain either way.
        self::assertFalse($row->error);
        self::assertSame("**recap** — not sent to the agent\n\nWe left off mid-refactor; next the tests.", $row->content);

        $empty = RecapHostCommand::rowMessage(new SideQuestionAnsweredMsg(null, "  \n "));
        self::assertStringContainsString('_No answer._', $empty->content);

        $failed = RecapHostCommand::rowMessage(new SideQuestionAnsweredMsg(null, '_The recap failed: boom_'));
        self::assertStringContainsString("_The recap failed: boom_", $failed->content);

        self::assertNull(RecapHostCommand::rowMessage(new \stdClass()));
    }

    public function testALongRecapIsBoundedByTheSharedAnswerCeiling(): void
    {
        $row = RecapHostCommand::rowMessage(new SideQuestionAnsweredMsg(null, str_repeat('a', 5000)));

        self::assertInstanceOf(Message::class, $row);
        self::assertStringStartsWith("**recap** — not sent to the agent\n\na", $row->content);
        self::assertStringEndsWith('…', $row->content);
        self::assertSame(
            mb_strlen("**recap** — not sent to the agent\n\n") + BtwHostCommand::ANSWER_MAX_CHARS,
            mb_strlen($row->content),
            'the ceiling is the shared seam keeps, not a recap copy',
        );
    }

    private function recapBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \LogicException('a recap is a side call: the async door only, never the sync one');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }
}
