<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Goal\GoalJudge;
use SugarCraft\Crush\Goal\GoalMode;
use SugarCraft\Crush\Goal\GoalState;
use SugarCraft\Crush\Goal\GoalVerdict;
use SugarCraft\Crush\GoalJudgedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 3.D-3: `/goal <condition>` (and `/grind`) start a turn toward the
 * goal, and after every turn the title model judges the transcript in strict
 * JSON — a claimed-but-unverified requirement is not met — sending the agent
 * back to work until the goal is met or the follow-up budget is spent.
 *
 * @see Chat::handleGoalCommand()
 * @see GoalJudge
 */
final class GoalCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string|false $suggestions = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-goal-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        // One Cmd per settle, so the test can resolve it.
        $this->suggestions = getenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS');
        putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false
            ? putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS')
            : putenv('SUGARCRUSH_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    public function testGoalStartsATurnTowardTheGoalAndRecordsIt(): void
    {
        $next = $this->submit($this->chat($this->judge('{}')), '/goal all tests pass');

        self::assertTrue($next->inFlight, '/goal starts its first turn at once');
        self::assertSame('', $next->inputBuf);
        $prompts = self::userPrompts($next);
        self::assertCount(1, $prompts);
        self::assertStringContainsString('**Goal:** all tests pass', $prompts[0]);
        self::assertStringContainsString('saying it is done is not enough', $prompts[0]);

        $goal = GoalState::activeIn($next->history);
        self::assertNotNull($goal);
        self::assertSame(GoalMode::Goal, $goal->mode);
        self::assertSame('all tests pass', $goal->condition);
        self::assertSame(0, $goal->round);
        self::assertStringStartsWith('Goal set: all tests pass.', self::notices($next)[0]);
    }

    public function testTheGoalMarkerIsNeitherSentNorPainted(): void
    {
        $next = $this->submit($this->chat($this->judge('{}')), '/goal ship it');
        $markers = array_values(array_filter($next->history, GoalState::isMarker(...)));

        self::assertCount(1, $markers);
        self::assertTrue($markers[0]->uiOnly, 'the model never reads the marker');
        self::assertFalse($markers[0]->userVisible, 'the transcript never paints the marker');
        self::assertNotContains($markers[0], Message::agentVisible($next->history));
        self::assertStringNotContainsString(GoalState::MARKER_PREFIX, $next->view());
    }

    public function testWithNoTitleModelNoGoalIsSet(): void
    {
        $next = $this->submit($this->chat(null), '/goal all tests pass');

        self::assertFalse($next->inFlight);
        self::assertNull(GoalState::fromHistory($next->history));
        $last = $next->history[\count($next->history) - 1];
        self::assertTrue($last->uiOnly);
        self::assertStringContainsString('needs a title model', $last->content);
        self::assertStringContainsString('SUGARCRUSH_TITLE_MODEL', $last->content);
    }

    public function testASettledTurnIsJudgedOnTheTitleModelHoldingTheTurn(): void
    {
        $judge = $this->judge('{"score": 100, "complete": true, "missing": []}');
        $started = $this->submit($this->chat($judge), '/goal all tests pass');
        [$judging, $cmd] = $this->settle($started, 'Done: 12 tests, 0 failures.');
        self::assertTrue($judging->inFlight, 'the judging holds the turn slot');
        self::assertGreaterThan(self::generation($started), self::generation($judging));

        $msg = $this->resolve($cmd);
        self::assertInstanceOf(GoalJudgedMsg::class, $msg);
        self::assertSame(self::generation($judging), $msg->generation);
        self::assertCount(1, $judge->asked);
        self::assertSame(Role::System, $judge->asked[0][0]->role);
        self::assertSame(GoalJudge::JUDGE_PROMPT, $judge->asked[0][0]->content);
        self::assertStringContainsString('claimed-but-unverified requirement as NOT satisfied', $judge->asked[0][0]->content);
        self::assertStringContainsString("<goal>\nall tests pass\n</goal>", $judge->asked[0][1]->content);
        self::assertStringContainsString('Assistant: Done: 12 tests, 0 failures.', $judge->asked[0][1]->content);
    }

    public function testAMetGoalEndsTheLoop(): void
    {
        $judge = $this->judge('{"score": 95, "complete": true, "missing": []}');
        [$judging, $cmd] = $this->settle($this->submit($this->chat($judge), '/goal all tests pass'), 'All green.');

        [$done, $after] = $judging->update($this->resolve($cmd));

        self::assertNull($after);
        self::assertFalse($done->inFlight);
        self::assertSame(GoalState::MET, GoalState::fromHistory($done->history)?->status);
        self::assertSame('Goal met (score 95/100): all tests pass', self::notices($done)[\count(self::notices($done)) - 1]);
        self::assertEqualsWithDelta(0.001, $done->spentUsd(), 1e-9, 'the judge call is accounted');
    }

    public function testAnUnmetGoalSendsTheAgentBackWithWhatIsMissing(): void
    {
        $judge = $this->judge('{"score": 40, "complete": false, "missing": ["the test run is not shown", "README not updated"]}');
        [$judging, $cmd] = $this->settle($this->submit($this->chat($judge), '/goal all tests pass'), 'I fixed it, all tests pass.');

        [$next, $sent] = $judging->update($this->resolve($cmd));

        self::assertTrue($next->inFlight, 'the follow-up is a new turn');
        self::assertNotNull($sent);
        $prompts = self::userPrompts($next);
        $followup = $prompts[\count($prompts) - 1];
        self::assertStringStartsWith('The goal is not met yet (follow-up 1 of 10, score 40/100).', $followup);
        self::assertStringContainsString("- the test run is not shown\n- README not updated", $followup);
        self::assertSame(1, GoalState::activeIn($next->history)?->round);
    }

    public function testTheLoopStopsOnceTheFollowUpBudgetIsSpent(): void
    {
        $judge = $this->judge('{"score": 70, "complete": false, "missing": ["coverage"]}');
        $chat = $this->chat($judge, [
            Message::user('the goal prompt'),
            GoalState::new(GoalMode::Goal, 'all tests pass')->withRound(GoalMode::GOAL_ROUNDS)->toMessage(),
        ]);
        [$judging, $cmd] = $this->settle($chat, 'Still working.');

        [$done] = $judging->update($this->resolve($cmd));

        self::assertFalse($done->inFlight);
        self::assertSame(GoalState::STOPPED, GoalState::fromHistory($done->history)?->status);
        $notices = self::notices($done);
        self::assertStringStartsWith('Goal not met after 10 follow-up rounds (score 70/100): all tests pass. Still missing: coverage.', $notices[\count($notices) - 1]);
    }

    public function testGrindHasTheLongBudget(): void
    {
        $next = $this->submit($this->chat($this->judge('{}')), '/grind refactor the parser');

        self::assertSame(GoalMode::Grind, GoalState::activeIn($next->history)?->mode);
        self::assertSame(50, GoalMode::Grind->maxRounds());
        self::assertStringContainsString('up to 50 follow-up rounds', self::notices($next)[0]);
    }

    public function testAnAnswerThatIsNotTheJsonVerdictStopsTheLoop(): void
    {
        [$judging, $cmd] = $this->settle($this->submit($this->chat($this->judge('Looks done to me!')), '/goal x'), 'ok');

        [$done] = $judging->update($this->resolve($cmd));

        self::assertFalse($done->inFlight);
        self::assertSame(GoalState::STOPPED, GoalState::fromHistory($done->history)?->status);
        $notices = self::notices($done);
        self::assertStringStartsWith('Goal check stopped: the judge did not answer with the JSON verdict', $notices[\count($notices) - 1]);
    }

    public function testAVerdictAfterACancelIsDropped(): void
    {
        [$judging, $cmd] = $this->settle($this->submit($this->chat($this->judge('{"complete": true}')), '/goal x'), 'ok');
        $msg = $this->resolve($cmd);
        $cancelled = $judging->update(new KeyMsg(KeyType::Escape))[0]->update(new KeyMsg(KeyType::Escape))[0];
        self::assertFalse($cancelled->inFlight);

        [$after, $none] = $cancelled->update($msg);

        self::assertNull($none);
        self::assertSame($cancelled->history, $after->history, 'a stranded verdict changes nothing');
        self::assertTrue(GoalState::fromHistory($after->history)?->isActive(), 'the goal stays set');
    }

    public function testAPromptQueuedDuringTheJudgingGoesFirst(): void
    {
        $judge = $this->judge('{"complete": false, "missing": ["more"]}');
        [$judging, $cmd] = $this->settle($this->submit($this->chat($judge), '/goal x'), 'ok');
        $msg = $this->resolve($cmd);
        $queued = self::drafted($judging, 'my own question')->update(new KeyMsg(KeyType::Tab))[0];

        [$next] = $queued->update($msg);

        $prompts = self::userPrompts($next);
        self::assertSame('my own question', $prompts[\count($prompts) - 1]);
        self::assertSame(0, GoalState::activeIn($next->history)?->round, 'no follow-up round was spent');
    }

    public function testClearEndsTheGoalAndABareGoalReportsIt(): void
    {
        $chat = $this->chat($this->judge('{}'), [GoalState::new(GoalMode::Goal, 'ship it')->withRound(3)->toMessage()]);

        $status = $this->submit($chat, '/goal');
        self::assertFalse($status->inFlight);
        self::assertSame('Goal (/goal, 3 of 10 follow-up rounds used): ship it. `/goal clear` stops it.', $status->history[\count($status->history) - 1]->content);

        $cleared = $this->submit($chat, '/goal clear');
        self::assertSame(GoalState::CLEARED, GoalState::fromHistory($cleared->history)?->status);
        self::assertContains('Goal cleared: ship it', self::notices($cleared));

        // Nothing is judged once the goal is cleared.
        [$settled, $cmd] = $this->settle($cleared, 'ok');
        self::assertNull($cmd);
        self::assertFalse($settled->inFlight);
    }

    public function testNoGoalNoJudge(): void
    {
        $judge = $this->judge('{}');
        [$settled, $cmd] = $this->settle($this->chat($judge, [Message::user('hi')]), 'hello');

        self::assertNull($cmd);
        self::assertFalse($settled->inFlight);
        self::assertSame([], $judge->asked);
    }

    public function testTheVerdictParserIsStrictAboutShapeAndConservativeAboutMeaning(): void
    {
        self::assertNull(GoalVerdict::parse('yes'));
        self::assertNull(GoalVerdict::parse('{"score": 90}'), 'no boolean complete, no verdict');
        self::assertNull(GoalVerdict::parse('[true]'));

        $fenced = GoalVerdict::parse("<think>hmm</think>\n```json\n{\"score\": 88.6, \"complete\": true, \"missing\": []}\n```");
        self::assertNotNull($fenced);
        self::assertTrue($fenced->complete);
        self::assertSame(89, $fenced->score);

        $contradiction = GoalVerdict::parse('{"score": 100, "complete": true, "missing": ["docs"]}');
        self::assertNotNull($contradiction);
        self::assertFalse($contradiction->complete, 'complete with something missing is not complete');
        self::assertSame(['docs'], $contradiction->missing);
    }

    public function testTheJudgeSeesToolOutputAsEvidence(): void
    {
        $history = [
            Message::user('run the tests'),
            Message::system('Bash')->withToolResults([ToolResult::ok('Bash', 'OK (12 tests, 30 assertions)', 'c1')]),
            Message::assistant('All tests pass.'),
            Message::notice('a UI-only row the judge must not read'),
        ];
        $request = GoalJudge::new()->request('all tests pass', $history);

        self::assertStringContainsString('Tool result (Bash): OK (12 tests, 30 assertions)', $request[1]->content);
        self::assertStringNotContainsString('UI-only row', $request[1]->content);
    }

    public function testBothCommandsAreAdvertised(): void
    {
        $names = array_map(static fn($s): string => $s->name, CommandRegistry::slashCommands());

        self::assertContains('goal', $names);
        self::assertContains('grind', $names);
    }

    /** @param list<Message> $history */
    private function chat(?Backend $title, array $history = []): Chat
    {
        return (new Chat(history: $history, backend: new EchoBackend(), titleBackend: $title))->withSize(100, 30);
    }

    private function submit(Chat $chat, string $draft): Chat
    {
        return self::drafted($chat, $draft)->update(new KeyMsg(KeyType::Enter))[0];
    }

    private static function drafted(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    private static function generation(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'generation'))->getValue($chat);
    }

    /**
     * Settle the running turn with $reply, as the backend's AssistantMsg does.
     *
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function settle(Chat $chat, string $reply): array
    {
        return $chat->update(new AssistantMsg(Message::assistant($reply)));
    }

    private function resolve(?\Closure $cmd): mixed
    {
        self::assertNotNull($cmd);
        $async = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $async);
        $resolved = null;
        $async->promise->then(static function (mixed $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /** @return list<string> */
    private static function userPrompts(Chat $chat): array
    {
        return array_values(array_map(
            static fn(Message $m): string => $m->content,
            array_filter($chat->history, static fn(Message $m): bool => $m->role === Role::User && !$m->uiOnly),
        ));
    }

    /** @return list<string> */
    private static function notices(Chat $chat): array
    {
        return array_values(array_map(
            static fn(Message $m): string => $m->content,
            array_filter(
                $chat->history,
                static fn(Message $m): bool => $m->role === Role::System && $m->uiOnly && $m->userVisible,
            ),
        ));
    }

    private function judge(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            /** @var list<list<Message>> */
            public array $asked = [];

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->asked[] = $history;

                return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(20, 0.001)));
            }
        };
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
