<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use React\Promise\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\SessionTitledMsg;
use SugarCraft\Crush\BackgroundSessionSpawnedMsg;
use SugarCraft\Crush\BackgroundTickMsg;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\PermissionReplyMsg;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

final class ChatTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;

    use HomeSandboxTrait;

    /**
     * Temp files this test asked for by name, unlinked in tearDown().
     *
     * @var list<string>
     */
    private array $tempPaths = [];

    /**
     * The `sc_chat_tool_*` payloads already in the temp dir when this class
     * started, so {@see tearDownAfterClass()} can tell what this run stranded
     * from what was already lying around.
     *
     * @var list<string>
     */
    private static array $toolIpcFilesAtStart = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$toolIpcFilesAtStart = self::toolIpcFiles();
    }

    /**
     * Chat hands each forked tool child a temp file to write its result into,
     * and the ONLY thing that unlinks one is the parent collecting it — so a
     * test that drops the Cmd doing the collecting strands a payload in the
     * developer's real /tmp. {@see ToolIpcFiles::sweep()} reclaims them after
     * an hour, which makes the leak self-limiting rather than acceptable:
     * running this suite is not a request to litter.
     *
     * Checked once for the whole class rather than per test, because the
     * matching glob has to scan the entire temp directory and a developer's
     * /tmp is routinely tens of thousands of entries — 50ms a call, which per
     * test would cost more than the rest of this file put together.
     */
    public static function tearDownAfterClass(): void
    {
        $stranded = array_values(array_diff(self::toolIpcFiles(), self::$toolIpcFilesAtStart));

        parent::tearDownAfterClass();

        self::assertSame([], $stranded, 'a forked tool child was abandoned with its IPC payload uncollected');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPaths = [];

        // Chat's own construction paths reach the skill trees under HOME, so
        // every test here runs against an empty sandbox rather than whatever
        // the developer has in ~/.claude/skills -- see HomeSandboxTrait.
        $this->useHomeSandbox(sys_get_temp_dir() . '/chat_test_home_' . uniqid('', true));
    }

    public function testTypingAccumulatesCharsInInputBuffer(): void
    {
        $chat = new Chat();
        [$h] = $chat->update(new KeyMsg(KeyType::Char, 'h'));
        [$he] = $h->update(new KeyMsg(KeyType::Char, 'e'));
        [$hel] = $he->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('hel', $hel->inputBuf);
    }

    /**
     * `$inputBuf` is DERIVED from the draft's editor (crush_code.md Phase 3
     * item 1 replaced the hand-rolled string with `candy-forms`' TextArea), so
     * the two can never be allowed to disagree about what is in the box. Every
     * write route is exercised here: the widget's own editing, the "replace
     * the whole draft" string key, and a mutation that touches neither.
     *
     * The cursor's own behaviour lives in `ChatInputCursorTest`; what is
     * pinned here is only that this class's public string stays the widget's
     * value through mutate().
     */
    public function testInputBufAndTheDraftEditorCannotDisagree(): void
    {
        $states = [];
        $states['seeded'] = new Chat(inputBuf: 'seed');
        [$states['typed']] = $states['seeded']->update(new KeyMsg(KeyType::Char, 'x'));
        [$states['backspaced']] = $states['typed']->update(new KeyMsg(KeyType::Backspace));
        [$states['newline']] = $states['backspaced']->update(new KeyMsg(KeyType::Enter, alt: true));
        [$states['resized']] = $states['newline']->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $states['restring'] = $states['resized']->withThemeName('light');

        foreach ($states as $label => $chat) {
            $this->assertSame($chat->input->value(), $chat->inputBuf, "{$label}: the two drifted apart");
        }

        $this->assertSame("seed\n", $states['restring']->inputBuf, 'fixture: and the edits really happened');
    }

    public function testSpaceKeyAppendsSpace(): void
    {
        $chat = new Chat();
        [$a]  = $chat->update(new KeyMsg(KeyType::Char,  'a'));
        [$ab] = $a->update(new KeyMsg(KeyType::Space, ''));
        [$abc] = $ab->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('a b', $abc->inputBuf);
    }

    public function testBackspaceDropsLastChar(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hell', $next->inputBuf);
    }

    public function testBackspaceDropsLastUtf8Codepoint(): void
    {
        $chat = new Chat(inputBuf: 'hi 🚀');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hi ', $next->inputBuf);
    }

    /**
     * W1.G2/E2 fix (reviewer-reported): crush_feat.md section 9's E2
     * literally specifies `new Chat(..., mosaic: $mosaic)` exposing the
     * probe-once candy-mosaic capability instance through Chat's
     * constructor, rather than leaving it a standalone static cache only
     * SugarCraft\Crush\ToolResult can reach.
     */
    public function testMosaicDefaultsToNullWhenNotWired(): void
    {
        $chat = new Chat();

        $this->assertNull($chat->mosaic());
    }

    public function testMosaicIsExposedWhenWiredThroughTheConstructor(): void
    {
        $mosaic = \SugarCraft\Mosaic\Mosaic::halfBlock();
        $chat = new Chat(mosaic: $mosaic);

        $this->assertSame($mosaic, $chat->mosaic());
    }

    public function testMosaicSurvivesMutateViaWithInputBuf(): void
    {
        $mosaic = \SugarCraft\Mosaic\Mosaic::halfBlock();
        $chat = new Chat(mosaic: $mosaic);

        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertSame($mosaic, $next->mosaic());
    }

    public function testEnterSubmitsAndSchedulesBackend(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(1, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('hello', $next->history[0]->content);
        $this->assertSame('', $next->inputBuf, 'input cleared after submit');
        $this->assertTrue($next->inFlight, 'inFlight set while waiting');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testEmptySubmitIsNoop(): void
    {
        $chat = new Chat(inputBuf: '   ');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testAssistantMsgAppendsAndClearsInFlight(): void
    {
        $chat = new Chat(history: [Message::user('hi')], inFlight: true);
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertSame('hello!', $next->history[1]->content);
        $this->assertFalse($next->inFlight);
    }

    /**
     * The INVERSION of a test that used to stand here.
     * `testKeystrokesIgnoredWhileInFlight()` asserted `''` after a mid-turn
     * keystroke, and it passed for as long as `update()` opened its mid-turn
     * block with a blanket `return [$this, null];`. That swallow was a
     * user-reported bug ("when i send a chat message and its processing the
     * request im unable to type new text into the chat"), so the test that pinned
     * it is replaced by one that pins the fix rather than deleted: the assertion
     * is on the same property, with the opposite expected value.
     *
     * Driven through the real `update()` entry point with a real `KeyMsg`, not by
     * calling the widget: the defect was in ROUTING, so a test that reached
     * `TextArea` directly would have passed against the bug.
     */
    public function testTypingReachesTheDraftWhileATurnIsInFlight(): void
    {
        $chat = new Chat(inputBuf: '', inFlight: true);

        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('a', $next->inputBuf, 'a mid-turn keystroke must reach the draft');

        [$more] = $next->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('ab', $more->inputBuf, 'and keep reaching it');
        $this->assertTrue($more->inFlight, 'without ending the turn that is running');
    }

    public function testEscDoesNotQuitWhenIdle(): void
    {
        $chat = new Chat();
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cmd);
        $this->assertInstanceOf(Chat::class, $next);
    }

    public function testSingleEscWhileInFlightDoesNotAbort(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        $this->assertTrue($chat->inFlight);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertTrue($next->inFlight);
        $this->assertNull($cmd);
    }

    public function testDoubleEscWithinWindowAbortsInFlightRequest(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        $this->assertTrue($chat->inFlight);

        [$afterFirst] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$afterSecond, $cmd] = $afterFirst->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertFalse($afterSecond->inFlight);
        $this->assertNull($cmd);
        $history = $afterSecond->history;
        $this->assertStringContainsString('cancelled', end($history)->content);
    }

    public function testStaleAssistantMsgAfterAbortIsDropped(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))->update(new KeyMsg(KeyType::Enter, ''))[0];
        [$afterFirst] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$aborted] = $afterFirst->update(new KeyMsg(KeyType::Escape, ''));

        $historyBeforeStaleReply = $aborted->history;

        // A reply for the aborted turn arrives late, stamped with the
        // generation it was scheduled under - a fresh Chat starts at
        // generation 0, and submit() bumps to 1 for this turn's Cmd (the
        // subsequent abort bumps again to 2, which is why this is stale).
        [$afterStaleReply] = $aborted->update(new AssistantMsg(Message::assistant('late reply'), 1));

        $this->assertSame($historyBeforeStaleReply, $afterStaleReply->history);
    }

    public function testCtrlWDeletesLastWordFromInput(): void
    {
        $chat = new Chat(inputBuf: 'hello there world');
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'w', ctrl: true));
        $this->assertSame('hello there ', $next->inputBuf);
    }

    /**
     * crush_feat.md §1 E5: tool output starts collapsed and Ctrl+O is the
     * only way to open it, so the keystroke must NOT fall through to the
     * generic Char arm and type a literal "o" into the input buffer.
     */
    public function testCtrlOTogglesTheLatestToolCallOutput(): void
    {
        $toolMsg = Message::assistant('')->withToolResults([
            ToolResult::ok('grep', "alpha\nbeta", 'call_1'),
        ]);
        $chat = new Chat(history: [$toolMsg]);

        $this->assertFalse($chat->isToolOutputExpanded('call_1'));

        [$expanded, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        $this->assertNull($cmd);
        $this->assertTrue($expanded->isToolOutputExpanded('call_1'));
        $this->assertSame('', $expanded->inputBuf);
        // Immutability: the original Chat is untouched.
        $this->assertSame([], $chat->expanded());

        [$collapsed] = $expanded->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        $this->assertSame([], $collapsed->expanded());
    }

    /**
     * A batch of parallel tool calls opens and closes as one unit - see
     * Chat::toggleLatestToolOutput()'s docblock.
     */
    public function testCtrlOTogglesEveryResultInTheLatestToolBatch(): void
    {
        $toolMsg = Message::assistant('')->withToolResults([
            ToolResult::ok('grep', 'a', 'call_1'),
            ToolResult::ok('bash', 'b', 'call_2'),
        ]);
        $chat = new Chat(history: [Message::user('go'), $toolMsg]);

        [$expanded] = $chat->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        $this->assertSame(['call_1' => true, 'call_2' => true], $expanded->expanded());
    }

    public function testCtrlOIsANoOpWhenNoToolCallHasRunYet(): void
    {
        $chat = new Chat(history: [Message::user('hi')]);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame([], $next->expanded());
        $this->assertSame('', $next->inputBuf);
    }

    public function testToggleToolOutputReturnsANewChatAndFlipsBothWays(): void
    {
        $chat = new Chat();

        $expanded = $chat->toggleToolOutput('call_9');

        $this->assertNotSame($chat, $expanded);
        $this->assertSame(['call_9' => true], $expanded->expanded());
        $this->assertTrue($expanded->isToolOutputExpanded('call_9'));
        $this->assertSame([], $expanded->toggleToolOutput('call_9')->expanded());
    }

    /**
     * The expansion map must survive every other with*()/mutate() call -
     * a field missing from mutate()'s constructorProps silently resets on
     * the next unrelated state change.
     */
    public function testExpandedMapSurvivesUnrelatedMutations(): void
    {
        $chat = (new Chat())->toggleToolOutput('call_1')->withStreaming(true)->withThemeName('light');

        $this->assertSame(['call_1' => true], $chat->expanded());
    }

    public function testAltBackspaceDeletesLastWordFromInput(): void
    {
        $chat = new Chat(inputBuf: 'hello there world');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, '', alt: true));
        $this->assertSame('hello there ', $next->inputBuf);
    }

    public function testPlainBackspaceStillDeletesOneCharacter(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        $this->assertSame('hell', $next->inputBuf);
    }

    public function testAltEnterInsertsNewlineInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: 'line one');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, '', alt: true));
        $this->assertNull($cmd);
        $this->assertSame("line one\n", $next->inputBuf);
        $this->assertFalse($next->inFlight);
    }

    public function testShiftEnterInsertsNewlineInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: 'line one');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, '', shift: true));
        $this->assertNull($cmd);
        $this->assertSame("line one\n", $next->inputBuf);
    }

    public function testUpArrowRecallsLastSentUserMessageWhenInputEmpty(): void
    {
        $chat = new Chat(history: [
            Message::user('first message'),
            Message::assistant('a reply'),
            Message::user('second message'),
        ]);

        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));

        $this->assertSame('second message', $next->inputBuf);
    }

    public function testUpArrowDoesNothingWhenNoUserMessageInHistory(): void
    {
        $chat = new Chat();
        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame('', $next->inputBuf);
    }

    public function testUpArrowNavigatesSlashMenuInsteadOfRecallingWhenPopupShowing(): void
    {
        $chat = new Chat(
            history: [Message::user('should not be recalled')],
            inputBuf: '/th',
        );

        [$next] = $chat->update(new KeyMsg(KeyType::Up, ''));

        // The "/" popup claimed this Up press - inputBuf is untouched
        // (only slashMenuIndex moves), not overwritten with history recall.
        $this->assertSame('/th', $next->inputBuf);
    }

    public function testExitCommandQuits(): void
    {
        $chat = new Chat(inputBuf: '/exit');
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $cmd());
    }

    public function testEchoBackendRoundTrip(): void
    {
        $chat = new Chat(backend: new EchoBackend(), inputBuf: 'ping');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Run the Cmd → AsyncCmd (async), not AssistantMsg (sync)
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        // Wait for the promise to resolve (EchoBackend resolves synchronously)
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved) {
            $resolved = $msg;
        });

        // Dispatch the resolved AssistantMsg
        $this->assertInstanceOf(AssistantMsg::class, $resolved);
        [$final] = $next->update($resolved);
        $this->assertCount(2, $final->history);
        $this->assertSame(Role::Assistant, $final->history[1]->role);
        $this->assertStringContainsString('ping', $final->history[1]->content);
    }

    public function testInitReturnsNoCmd(): void
    {
        $this->assertNull((new Chat())->init());
    }

    public function testNonKeyMessageIgnored(): void
    {
        $chat = new Chat(inputBuf: 'x');
        $msg = new \SugarCraft\Core\Msg\EnvMsg([]);
        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    /**
     * Regression: Renderer must lay out against the REAL terminal size, and
     * the only place that size can come from without silently disagreeing
     * with what candy-core's Program itself detected (or missing a live
     * resize) is the WindowSizeMsg Program dispatches at startup and on
     * every SIGWINCH - see rows()/cols()'s docblock.
     */
    public function testWindowSizeMsgUpdatesRowsAndCols(): void
    {
        $chat = new Chat();
        [$next, $cmd] = $chat->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));

        $this->assertNull($cmd);
        $this->assertSame(24, $next->rows());
        $this->assertSame(80, $next->cols());
    }

    public function testRowsAndColsFallBackToTerminalDetectionBeforeAnyWindowSizeMsg(): void
    {
        $chat = new Chat();
        $this->assertSame(
            \SugarCraft\Crush\Tui\Renderer::getTerminalSize()['rows'],
            $chat->rows(),
        );
    }

    public function testWithStreamingEnablesFlag(): void
    {
        $chat = new Chat();
        $this->assertFalse($chat->isStreaming());
        $chat2 = $chat->withStreaming(true);
        $this->assertNotSame($chat, $chat2);
        $this->assertTrue($chat2->isStreaming());
    }

    public function testWithStreamingCanDisable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->withStreaming(true);
        $chat3 = $chat2->withStreaming(false);
        $this->assertFalse($chat3->isStreaming());
    }

    public function testOnTokenSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToken(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
    }

    public function testStreamingStatePreservedOnInput(): void
    {
        $chat = new Chat(inputBuf: '', streaming: true, onToken: static fn() => null);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingStatePreservedOnAssistantMsg(): void
    {
        $chat = new Chat(
            history: [Message::user('hi')],
            inFlight: true,
            streaming: true,
            onToken: static fn() => null,
        );
        $reply = Message::assistant('hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertTrue($next->isStreaming());
    }

    public function testStreamingCallbackPassedToBackend(): void
    {
        $tokens = [];
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
                {
                    if ($onToken !== null) {
                        $onToken('token1');
                        $onToken('token2');
                        $onToken('token3');
                    }
                    return \SugarCraft\Crush\Message::assistant('streaming reply');
                }
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
                {
                    return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
                        try {
                            $resolve($this->complete($history, $onToken));
                        } catch (\Throwable $e) {
                            $reject($e);
                        }
                    });
                }
            },
            inputBuf: 'hello',
            streaming: true,
            onToken: static function (string $token) use (&$tokens) {
                $tokens[] = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        // Execute the command to trigger the backend
        $msg = $cmd();
        $this->assertCount(3, $tokens);
        $this->assertSame(['token1', 'token2', 'token3'], $tokens);
    }

    public function testStreamingCallbackNotPassedWhenDisabled(): void
    {
        $callbackReceived = null;
        $chat = new Chat(
            backend: new class implements \SugarCraft\Crush\Backend {
                public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
                {
                    return \SugarCraft\Crush\Message::assistant('reply');
                }
                public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
                {
                    return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
                        try {
                            $resolve($this->complete($history, $onToken));
                        } catch (\Throwable $e) {
                            $reject($e);
                        }
                    });
                }
            },
            inputBuf: 'hello',
            streaming: false,
            onToken: static function (string $token) use (&$callbackReceived) {
                $callbackReceived = $token;
            },
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $cmd();
        // When streaming is disabled, onToken is null (not passed)
        $this->assertNull($callbackReceived);
    }

    public function testRegisterToolAddsTool(): void
    {
        $chat = new Chat();
        $this->assertEmpty($chat->getTools());
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertNotSame($chat, $chat2);
        $this->assertCount(1, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
    }

    public function testRegisterToolIsImmutable(): void
    {
        $chat = new Chat();
        $chat2 = $chat->registerTool('bash', static fn(array $args) => 'result');
        $this->assertEmpty($chat->getTools());
        $this->assertCount(1, $chat2->getTools());
    }

    public function testMultipleToolsCanBeRegistered(): void
    {
        $chat = new Chat();
        $chat2 = $chat
            ->registerTool('bash', static fn(array $args) => 'bash result')
            ->registerTool('read', static fn(array $args) => 'file content');
        $this->assertCount(2, $chat2->getTools());
        $this->assertArrayHasKey('bash', $chat2->getTools());
        $this->assertArrayHasKey('read', $chat2->getTools());
    }

    public function testOnToolCallSetsCallback(): void
    {
        $chat = new Chat();
        $called = false;
        $chat2 = $chat->onToolCall(function () use (&$called) {
            $called = true;
        });
        $this->assertNotSame($chat, $chat2);
        // Callback is stored (we trust it's set correctly by immutability)
        $this->assertNotSame($chat, $chat2);
    }

    public function testToolExecutionOnAssistantMsg(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls -la']);
        $message = Message::assistant('Running command...')->withToolCalls([$toolCall]);

        $executedArgs = null;
        $chat = (new Chat(
            history: [Message::user('list files')],
            inFlight: true,
        ))->registerTool('bash', static function (array $args) use (&$executedArgs) {
            $executedArgs = $args;
            return 'total 0' . "\n" . 'drwxr-xr-x 2 user user 4096 May 10 00:00 .';
        });

        [$afterPlaceholders, $next] = $this->runToolCallsToCompletion($chat, $message);

        // "running" placeholder was visible before the result arrived.
        $this->assertCount(3, $afterPlaceholders->history);
        $this->assertNotNull($afterPlaceholders->history[2]->pendingToolCallId);

        // Tool executed in a forked child (see runToolCallsToCompletion()) -
        // $executedArgs, captured by reference, can't cross that boundary
        // (same reasoning documented on forkToolCalls()); the real args
        // ARE observable via the finished result's own content instead.
        $this->assertNull($executedArgs);

        // A follow-up backend call should be scheduled
        $this->assertTrue($next->inFlight);
        // History: user msg + assistant msg + tool result msg
        $this->assertCount(3, $next->history);
        $this->assertStringContainsString('total 0', $next->history[2]->content);
        // crush_feat.md §3 E2: the placeholder's describeToolCall() one-liner
        // rides onto the finished result so a collapsed row still says WHAT
        // ran, not just which tool ran.
        $this->assertSame('bash(cmd: "ls -la")', $next->history[2]->toolResults[0]->description);
    }

    /**
     * Regression for the same bug as EngineBackendTest::
     * testCompleteAsyncDoesNotResetTheRealTerminalsRawMode() - forkToolCalls()
     * forks a real child per tool call (see that method's docblock). If the
     * child ends with a plain exit(), its inherited Tty's destructor fires
     * during PHP's shutdown sequence and restores the ORIGINAL (cooked/echo)
     * termios onto the REAL, shared terminal device. Drives a real tool call
     * through the real fork path against a real PTY and asserts the
     * terminal is still in raw mode afterwards.
     */
    public function testToolExecutionDoesNotResetTheRealTerminalsRawMode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for termios FFI.');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required to exercise the real fork path.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }

        $pair = (new \SugarCraft\Pty\Posix\PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = \SugarCraft\Pty\Libc::lib();
        $slaveFd = $libc->open($slavePath, 0x0002 /* O_RDWR */);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $isRaw = static function (string $path): bool {
            // BSD/macOS stty takes the device flag lowercase (-f); GNU/Linux
            // coreutils uses uppercase (-F).
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            $out = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($path) . ' -a 2>/dev/null'));

            return str_contains($out, '-icanon') && str_contains($out, '-echo');
        };

        // Injected Termios test seam - see EngineBackendTest's matching test
        // for why this bypasses candy-core's (int)-cast fd resolution.
        $tty = new \SugarCraft\Core\Util\Tty(null, new \SugarCraft\Pty\Posix\PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertTrue($isRaw($slavePath), 'setup: raw mode must be active before running the tool');

            $toolCall = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls -la']);
            $message = Message::assistant('Running command...')->withToolCalls([$toolCall]);
            $chat = (new Chat(history: [Message::user('list files')], inFlight: true))
                ->registerTool('bash', static fn(array $args) => 'total 0');

            [, $next] = $this->runToolCallsToCompletion($chat, $message);

            $this->assertTrue($next->inFlight, 'sanity: the tool call must actually have run');
            $this->assertTrue(
                $isRaw($slavePath),
                'the real terminal was knocked out of raw mode by forkToolCalls()\'s forked child exiting',
            );
        } finally {
            $tty->restore();
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }

    public function testToolResultAddedToHistoryAfterExecution(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('echo', ['text' => 'hello']);
        $message = Message::assistant('Echoing...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('say hello')],
            inFlight: true,
        ))->registerTool('echo', static fn(array $args) => $args['text'] ?? '');

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // After tool execution, history should have 3 items:
        // user msg, assistant msg with tool call, tool result
        $this->assertCount(3, $next->history);
        // Message::withToolResults() used to discard both the passed-in
        // ToolResult array AND the message's own content, so every tool
        // result rendered as a blank assistant bubble - the actual bug
        // behind "tool calls are silent in the chat window". Fixed to
        // preserve content and actually carry the results.
        $this->assertSame('hello', $next->history[2]->content);
        $this->assertCount(1, $next->history[2]->toolResults);
        $this->assertSame('echo', $next->history[2]->toolResults[0]->name);
    }

    /**
     * W2.S1b: ToolResult's reconciled $diff/$durationMs (the fields that
     * previously existed only on the engine-side Tools\ToolResult) have to
     * survive Chat's pcntl_fork()/JSON-temp-file seam, or a diff computed by
     * a tool would be silently dropped before Renderer ever sees it - the
     * same class of loss the base64 image fix (W1.G2) closed.
     */
    public function testDiffAndDurationSurviveTheForkedToolResultSeam(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('edit', ['path' => 'a.php'], 'call_diff_1');
        $message = Message::assistant('Editing...')->withToolCalls([$toolCall]);
        $diff = "--- a/a.php\n+++ b/a.php\n@@ -1 +1 @@\n-x\n+y\n";

        $chat = (new Chat(
            history: [Message::user('edit a.php')],
            inFlight: true,
        ))->registerTool('edit', static fn(array $args) => new \SugarCraft\Crush\ToolResult(
            name: 'edit',
            result: 'File updated: a.php',
            id: 'call_diff_1',
            diff: $diff,
            durationMs: 5,
        ));

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        $result = $next->history[2]->toolResults[0];
        $this->assertSame($diff, $result->diff);
        $this->assertTrue($result->hasDiff());
        $this->assertSame(5, $result->durationMs);
    }

    public function testUnknownToolReturnsError(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('unknown_tool', []);
        $message = Message::assistant('Calling unknown...')->withToolCalls([$toolCall]);

        // Chat with no tools registered - tool calls should be ignored
        // and message should just be added to history
        $chat = new Chat(
            history: [Message::user('do something')],
            inFlight: true,
        );

        [$next] = $chat->update(new AssistantMsg($message));

        // Without tools registered, tool calls are ignored
        // History: user msg + assistant msg with tool calls (no execution)
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }

    public function testToolExceptionReturnsErrorResult(): void
    {
        $toolCall = new \SugarCraft\Crush\ToolCall('failing', []);
        $message = Message::assistant('Calling failing tool...')->withToolCalls([$toolCall]);

        $chat = (new Chat(
            history: [Message::user('test')],
            inFlight: true,
        ))->registerTool('failing', static function (array $args): void {
            throw new \RuntimeException('Tool failed intentionally');
        });

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // History should have user msg, assistant msg, and error result
        $this->assertCount(3, $next->history);
        $this->assertStringContainsString('Tool failed intentionally', $next->history[2]->content);
        $this->assertTrue($next->history[2]->toolResults[0]->isError());
    }

    public function testMultipleToolCallsWithPoolConfiguredExecuteRealTools(): void
    {
        // R14b.fix: with 2+ tool calls AND a non-null pool configured,
        // execution now goes through executeToolsParallel()'s direct
        // pcntl_fork() fan-out (see its docblock) rather than the old
        // AgentWorkerPool/SubAgent/ProcessExecutor detour that could only
        // fabricate output. A stub AgentWorkerPool executor is still wired
        // here (unused by the new code path) purely to prove that: even
        // configuring one has no bearing on tool output correctness anymore -
        // real registered-tool closures run and their real return values are
        // what the onToolCall listener observes, never anything the executor
        // would have fabricated.
        $executor = new class implements ExecutorInterface {
            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'FABRICATED-NOT-REAL-OUTPUT');
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'FABRICATED-NOT-REAL-OUTPUT');
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };
        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $executor);

        $toolCallA = new \SugarCraft\Crush\ToolCall('toolA', ['x' => 1]);
        $toolCallB = new \SugarCraft\Crush\ToolCall('toolB', ['y' => 2]);
        $message = Message::assistant('Calling two tools...')->withToolCalls([$toolCallA, $toolCallB]);

        $observed = [];
        $chat = (new Chat(
            history: [Message::user('do two things')],
            inFlight: true,
        ))
            ->withWorkerPool($pool)
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observed): void {
                $observed[$name] = $result;
            })
            ->registerTool('toolA', static fn(array $args) => 'REAL-TOOL-A-OUTPUT')
            ->registerTool('toolB', static fn(array $args) => 'REAL-TOOL-B-OUTPUT');

        [, $next] = $this->runToolCallsToCompletion($chat, $message);

        // Both real tool closures ran (whether via a forked child or the
        // pcntl-unavailable sequential fallback) and their real output was
        // observed, keyed correctly by name despite running concurrently.
        $this->assertSame(
            ['toolA' => 'REAL-TOOL-A-OUTPUT', 'toolB' => 'REAL-TOOL-B-OUTPUT'],
            $observed
        );
        $this->assertNotContains('FABRICATED-NOT-REAL-OUTPUT', $observed);

        // History: user msg + assistant msg + tool result A + tool result B
        $this->assertCount(4, $next->history);
        $this->assertTrue($next->inFlight);
    }

    public function testParallelToolCallsActuallyRunInSeparateForkedProcesses(): void
    {
        // Deterministic proxy for "this really is parallel, not just
        // sequential-with-extra-steps": each tool closure reports its own
        // getmypid(). If executeToolsParallel() forked one child per call (as
        // designed), every reported PID differs both from each other and
        // from this test's own process - the only way that's possible given
        // invokeTool() is a pure, synchronous call with no other source of a
        // different PID. A single shared PID (or this test's own PID) would
        // mean execution silently fell back to running in-process.
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment.');
        }

        $toolCallA = new \SugarCraft\Crush\ToolCall('pidA', []);
        $toolCallB = new \SugarCraft\Crush\ToolCall('pidB', []);
        $message = Message::assistant('Calling two tools...')->withToolCalls([$toolCallA, $toolCallB]);

        $pool = new AgentWorkerPool(maxConcurrent: 2);
        $observedPids = [];
        $chat = (new Chat(history: [Message::user('report pids')], inFlight: true))
            ->withWorkerPool($pool)
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observedPids): void {
                $observedPids[$name] = $result;
            })
            ->registerTool('pidA', static fn(array $args) => getmypid())
            ->registerTool('pidB', static fn(array $args) => getmypid());

        $this->runToolCallsToCompletion($chat, $message);

        $this->assertCount(2, $observedPids);
        $this->assertNotSame($observedPids['pidA'], getmypid());
        $this->assertNotSame($observedPids['pidB'], getmypid());
        $this->assertNotSame($observedPids['pidA'], $observedPids['pidB']);
    }

    public function testSingleToolCallAlsoRunsInAForkedChildForLiveVisibility(): void
    {
        // Every tool call forks now, single or not - beginToolCalls()'s
        // "running" placeholder (shown the instant the call is dispatched,
        // before it finishes - see Message::toolRunning()) needs the render
        // loop to keep ticking while even a lone, potentially slow tool call
        // runs, the same reason EngineBackend::completeAsync() forks the
        // provider call. A shared PID here would mean it silently fell back
        // to blocking in-process, defeating that visibility.
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension not available in this environment.');
        }

        $toolCall = new \SugarCraft\Crush\ToolCall('solo', []);
        $message = Message::assistant('Calling one tool...')->withToolCalls([$toolCall]);

        $observedPid = null;
        $chat = (new Chat(history: [Message::user('report pid')], inFlight: true))
            ->onToolCall(function (string $name, array $args, mixed $result) use (&$observedPid): void {
                $observedPid = $result;
            })
            ->registerTool('solo', static fn(array $args) => getmypid());

        $this->runToolCallsToCompletion($chat, $message);

        $this->assertNotSame(getmypid(), $observedPid);
    }

    public function testSlashMenuFiltersAsUserTypes(): void
    {
        $chat = new Chat(inputBuf: '/');
        $this->assertGreaterThan(1, count($chat->slashMenuMatches()));

        [$narrowed] = $chat->update(new KeyMsg(KeyType::Char, 'b'));
        $names = array_map(static fn($spec) => $spec->name, $narrowed->slashMenuMatches());
        // Every slash-visible row whose name contains a `b`, fuzzy-ranked. `budget`
        // joined the registry in crush_code.md Phase 5 item 7; the list is spelled
        // out rather than derived on purpose, because the thing under test is that
        // the popup NARROWS as characters arrive, and a derived expectation would
        // pass against a popup that never filtered at all.
        $this->assertSame(['bg', 'branch', 'budget'], $names);
    }

    public function testSlashMenuHiddenOnceArgumentsStart(): void
    {
        $chat = new Chat(inputBuf: '/rename');
        [$next] = $chat->update(new KeyMsg(KeyType::Space, ''));
        $this->assertSame([], $next->slashMenuMatches());
    }

    public function testSlashMenuUpDownWrapsSelection(): void
    {
        $chat = new Chat(inputBuf: '/re'); // matches: rename, rewind
        $this->assertSame(0, $chat->slashMenuIndex());

        [$down] = $chat->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $down->slashMenuIndex());

        [$wrapped] = $down->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(0, $wrapped->slashMenuIndex());

        [$up] = $wrapped->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame(1, $up->slashMenuIndex());
    }

    public function testEnterCompletesAmbiguousMatchInsteadOfSubmitting(): void
    {
        $chat = new Chat(inputBuf: '/re'); // matches: rename, rewind — ambiguous
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('/rename ', $next->inputBuf);
        $this->assertSame([], $next->history); // not submitted
    }

    public function testEnterSubmitsExactCommandMatchInsteadOfRefilling(): void
    {
        // Only one command starts with "sessions" and the typed text is an
        // exact match for it — Enter must submit, not re-fill the same text.
        $chat = new Chat(inputBuf: '/sessions');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotSame('/sessions ', $next->inputBuf);
        $this->assertNotSame([], $next->history);
    }

    public function testContextUsagePercentIsZeroForEmptyHistory(): void
    {
        $chat = new Chat();
        $this->assertSame(0.0, $chat->contextUsagePercent());
    }

    public function testContextUsagePercentGrowsWithHistorySize(): void
    {
        $short = new Chat(history: [Message::user('hi')]);
        $long = new Chat(history: [Message::user(str_repeat('a', 40000))]);

        $this->assertGreaterThan($short->contextUsagePercent(), $long->contextUsagePercent());
    }

    public function testDownArrowIsNoOpOutsideSlashMenu(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        [$next] = $chat->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame($chat, $next);
    }

    public function testThemeCommandWithNoArgsShowsCurrentAndAvailable(): void
    {
        $chat = new Chat(inputBuf: '/theme');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dark', $next->theme()->name);
        $this->assertStringContainsString('dark', $next->history[1]->content);
        $this->assertStringContainsString('dracula', $next->history[1]->content);
    }

    public function testThemeCommandSwitchesTheme(): void
    {
        $chat = new Chat(inputBuf: '/theme dracula');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dracula', $next->theme()->name);
        $this->assertSame('', $next->inputBuf);
    }

    public function testThemeCommandFiresOnConfigChangeForPersistence(): void
    {
        $observed = [];
        $chat = (new Chat(inputBuf: '/theme dracula'))
            ->withOnConfigChange(function (string $key, string $value) use (&$observed): void {
                $observed[] = [$key, $value];
            });

        $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([['theme', 'dracula']], $observed);
    }

    public function testPaletteSwitchThemeFiresOnConfigChangeForPersistence(): void
    {
        $observed = [];
        $chat = (new Chat())->withOnConfigChange(function (string $key, string $value) use (&$observed): void {
            $observed[] = [$key, $value];
        });

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $current = $opened;
        foreach (str_split('switch theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        [$inThemes] = $current->update(new KeyMsg(KeyType::Enter, ''));
        [$inThemes] = $inThemes->update(new KeyMsg(KeyType::Enter, '')); // selects the top match ('dark')

        $this->assertSame([['theme', 'dark']], $observed);
    }

    public function testThemeCommandWithUnknownNameLeavesThemeUnchanged(): void
    {
        $chat = new Chat(inputBuf: '/theme nonexistent');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dark', $next->theme()->name);
        $this->assertStringContainsString('Unknown theme', $next->history[1]->content);
    }

    public function testCtrlPOpensPaletteAndEscapeCloses(): void
    {
        $chat = new Chat();
        $this->assertNull($chat->palette());

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($opened->palette());
        $this->assertSame('root', $opened->palette()->mode);

        [$closed] = $opened->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($closed->palette());
    }

    public function testSecondCtrlPClosesAnOpenPalette(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        [$closed] = $opened->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($closed->palette());
    }

    public function testPaletteQueryFiltersActionsAndResetsSelection(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        [$down] = $opened->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $down->palette()->selectedIndex);

        $current = $down;
        foreach (str_split('theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        $this->assertSame(0, $current->palette()->selectedIndex);
        $this->assertSame('Switch theme', $current->paletteMatches()[0]);
    }

    public function testPaletteUpDownWraps(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $count = count($opened->paletteMatches());

        [$up] = $opened->update(new KeyMsg(KeyType::Up, ''));
        $this->assertSame($count - 1, $up->palette()->selectedIndex);
    }

    public function testPaletteEnterOnExitQuits(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $exitIndex = array_search('Exit', $opened->paletteMatches(), true);
        $current = $opened;
        for ($i = 0; $i < $exitIndex; $i++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }

        [$next, $cmd] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertNotNull($cmd);
    }

    public function testPaletteEnterOnShareSessionDispatchesRealHandlerAndCloses(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('share') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }
        $this->assertSame('Share session', $current->paletteMatches()[0]);

        // A bare Chat() has no session store, so the real ShareCommand this
        // dispatches through legitimately has nothing to share and exits
        // non-zero. The claim here is unchanged -- dispatch reached the REAL
        // handler and closed the palette, not that it succeeded -- but the
        // evidence for it moved, because the thing it used to cite was a bug.
        //
        // This asserted `assertNotNull($cmd)` and its comment named "the
        // print-closure path", so it was pinning `fn() => print $output` as the
        // proof of dispatch. That closure was a Cmd evaluating to `int 1`, which
        // Program::dispatch() rejects with a TypeError -- a user hit it on a bare
        // `/websearch` and the app died. A failing command now reports in the
        // transcript and returns NO Cmd, so the evidence that the handler ran is
        // the message it left behind.
        [$next, $cmd] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertNull($cmd, 'a failing command must not hand the program a Cmd');

        $added = array_slice($next->history, \count($chat->history));
        $this->assertNotSame([], $added, 'the real handler ran, so it must have reported something');
        $this->assertSame(Role::System, $added[\count($added) - 1]->role);
        $this->assertStringContainsString('not yet implemented', $added[\count($added) - 1]->content);
    }

    public function testPaletteSwitchModelTransitionsToProviderListWithoutClosing(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('switch model') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        $this->assertSame('Switch model', $current->paletteMatches()[0]);

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($next->palette());
        $this->assertSame('providers', $next->palette()->mode);
        $this->assertContains('sglang', $next->paletteMatches());
    }

    public function testPaletteSwitchThemeTransitionsAndSelectingAThemeAppliesIt(): void
    {
        $chat = new Chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $current = $opened;
        foreach (str_split('switch theme') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch === ' ' ? ' ' : $ch));
        }
        [$inThemes] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('themes', $inThemes->palette()->mode);
        // `adaptive` (crush_code.md Phase 8 item 5) joined the roster; it is
        // last because it is the newest, not because order carries meaning.
        $this->assertSame(
            ['dark', 'light', 'dracula', 'tokyoNight', 'ansi', 'adaptive'],
            $inThemes->paletteMatches(),
        );

        $draculaIndex = array_search('dracula', $inThemes->paletteMatches(), true);
        $current = $inThemes;
        for ($i = 0; $i < $draculaIndex; $i++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }

        [$next] = $current->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($next->palette());
        $this->assertSame('dracula', $next->theme()->name);
    }

    public function testToolsAndCallbacksPreservedOnInput(): void
    {
        $chat = new Chat(tools: ['test' => static fn() => 'result']);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertArrayHasKey('test', $next->getTools());
    }

    public function testAssistantMsgWithoutToolCallsNoOpOnTools(): void
    {
        $chat = (new Chat(
            history: [Message::user('hello')],
            inFlight: true,
        ))->registerTool('bash', static fn(array $args) => 'result');
        $reply = Message::assistant('Hello!');
        [$next] = $chat->update(new AssistantMsg($reply));
        $this->assertCount(2, $next->history);
        $this->assertFalse($next->inFlight);
    }

    public function testBackendAccessor(): void
    {
        $backend = new EchoBackend();
        $chat = new Chat(backend: $backend);
        $this->assertSame($backend, $chat->backend());
    }

    public function testBackendAccessorWithDefaultBackend(): void
    {
        $chat = new Chat();
        // Default backend is EchoBackend
        $this->assertInstanceOf(\SugarCraft\Crush\Backend\EchoBackend::class, $chat->backend());
    }

    public function testBackendPreservedOnInput(): void
    {
        $backend = new EchoBackend();
        $chat = new Chat(backend: $backend);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($backend, $next->backend());
    }

    /**
     * Benchmark: diff-based view() emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Frame 1: full output (baseline)
     * Frame 2: delta output (smaller than full 80x24 re-emit)
     * Frame 3: delta output (smaller than full 80x24 re-emit)
     */
    /**
     * view() used to compute its own cell-level diff and return only the
     * changed bytes for a repeat frame - but Program's own Renderer ALSO
     * diffs whatever a Model's view() returns, so that pre-diffed byte
     * soup was being diffed a second time against unrelated bytes from the
     * previous call, producing wrong cursor placement (visible as typed
     * text/replies landing in the wrong row - e.g. the status bar - once a
     * conversation grew past a single frame). view() now always returns
     * the full literal frame; the real diffing happens once, correctly, in
     * candy-core's Program/Renderer.
     */
    public function testViewReturnsTheFullFrameOnEveryCall(): void
    {
        $chat = new Chat(history: [Message::user('hello'), Message::assistant('Hi there!')]);

        $out1 = $chat->view();
        [$chat2] = $chat->update(new KeyMsg(KeyType::Char, '!'));
        $out2 = $chat2->view();

        $this->assertStringContainsString('hello', $out1);
        $this->assertStringContainsString('hello', $out2);
        $this->assertStringContainsString('!', $out2);
        // Both are full frames of the same conversation at the same terminal
        // size - comparable magnitude, not a shrinking delta.
        $this->assertGreaterThan(0.5 * \strlen($out1), \strlen($out2));
    }

    // ─── AgentWorkerPool wiring tests (P1.S10) ─────────────────────────────────

    public function testWithWorkerPoolReturnsNewInstance(): void
    {
        $chat = new Chat();
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat2 = $chat->withWorkerPool($pool);
        $this->assertNotSame($chat, $chat2);
        $this->assertSame($pool, $chat2->pool());
    }

    public function testWithAgentPoolConfigReturnsNewInstance(): void
    {
        $chat = new Chat();
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat2 = $chat->withAgentPoolConfig($config);
        $this->assertNotSame($chat, $chat2);
        $this->assertSame($config, $chat2->agentPoolConfig());
    }

    public function testWorkerPoolPreservedOnInput(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat = (new Chat())->withWorkerPool($pool);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($pool, $next->pool());
    }

    public function testAgentPoolConfigPreservedOnInput(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat = (new Chat())->withAgentPoolConfig($config);
        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame($config, $next->agentPoolConfig());
    }

    public function testWorkerPoolPreservedOnStreamingToggle(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 3);
        $chat = (new Chat())->withWorkerPool($pool);
        $chat2 = $chat->withStreaming(true);
        $this->assertSame($pool, $chat2->pool());
    }

    public function testAgentPoolConfigPreservedOnToolRegistration(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 3);
        $chat = (new Chat())->withAgentPoolConfig($config);
        $chat2 = $chat->registerTool('echo', static fn(array $args) => 'result');
        $this->assertSame($config, $chat2->agentPoolConfig());
    }

    public function testExecuteAgentsThrowsWithoutPoolOrConfig(): void
    {
        $chat = new Chat();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no AgentWorkerPool or AgentPoolConfig available');
        // Suppress iterator consumption warning
        @$chat->executeAgents([], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
    }

    public function testExecuteAgentsUsesExplicitPool(): void
    {
        // Use a custom executor that immediately returns a result. AgentResult
        // has no ok() factory — use the real constructor with an explicit
        // AgentStatus, mirroring the pattern in
        // testMultipleToolCallsWithPoolConfiguredExecuteRealToolsSequentially().
        $executor = new class implements ExecutorInterface {
            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'done');
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield new AgentResult($agent->id, \SugarCraft\Crush\Agents\AgentStatus::Completed, 'done');
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);
        $chat = (new Chat())->withWorkerPool($pool);

        // Non-empty agents array so this actually dispatches through the pool's
        // happy path rather than short-circuiting on executeAll()'s `$agents === []`
        // early return.
        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'explicit-pool-agent',
            agent: new Agent(
                name: 'ExplicitPoolAgent',
                description: 'Agent for explicit-pool dispatch test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the thing',
        );

        $results = $chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);

        $collected = iterator_to_array($results, false);
        $this->assertCount(1, $collected);
        $this->assertSame('explicit-pool-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
        $this->assertSame('done', $collected[0]->output);
    }

    public function testExecuteAgentsBuildsPoolFromConfig(): void
    {
        $config = new AgentPoolConfig(maxConcurrent: 2);
        $chat = (new Chat())->withAgentPoolConfig($config);

        // Accessor confirms config is set
        $this->assertSame($config, $chat->agentPoolConfig());

        // Pool is built from config when executeAgents is called with no pool set.
        // Use a non-empty agents array so this actually exercises the built pool's
        // default ProcessExecutor happy path (a self-contained inline worker
        // simulation with no real network calls — see ProcessExecutorTest) rather
        // than short-circuiting on an empty array.
        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'config-built-pool-agent',
            agent: new Agent(
                name: 'ConfigBuiltPoolAgent',
                description: 'Agent for config-built-pool dispatch test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say hello',
        );

        $results = $chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test-model',
            messages: [],
        ));
        $this->assertInstanceOf(\Generator::class, $results);

        $collected = iterator_to_array($results, false);
        $this->assertCount(1, $collected);
        $this->assertSame('config-built-pool-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
        $this->assertSame(
            '[ConfigBuiltPoolAgent] Task finished: Say hello',
            $collected[0]->output,
        );
    }

    // ─── /workflow command wiring tests (P4.S15) ─────────────────────────────────

    /**
     * Standalone fake WorkflowEngine for testing.
     *
     * Provides stub implementations of the 5 public methods that Chat's
     * workflow handlers call: run, pause, resume, getStatus, listWorkflows.
     * This is a simple test double — NOT extending WorkflowEngine — so that
     * WorkflowEngine can remain final without scope creep into Chat.php's test.
     */
    private function createFakeWorkflowEngine(array $stubs = []): \SugarCraft\Crush\Workflows\WorkflowEngineInterface
    {
        return new class($stubs) implements \SugarCraft\Crush\Workflows\WorkflowEngineInterface {
            private array $stubs;

            public function __construct(array $stubs = []) {
                $this->stubs = $stubs;
            }

            public function listWorkflows(): array
            {
                return $this->stubs['listWorkflows'] ?? [];
            }

            public function run(string $workflowPath, array $context = []): \SugarCraft\Crush\Workflows\WorkflowResult
            {
                if (isset($this->stubs['run'])) {
                    return $this->stubs['run'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotFoundException('Not found');
            }

            public function pause(string $workflowId): void
            {
                if (isset($this->stubs['pause'])) {
                    call_user_func($this->stubs['pause'], $workflowId);
                }
            }

            public function resume(string $workflowId): \SugarCraft\Crush\Workflows\WorkflowResult
            {
                if (isset($this->stubs['resume'])) {
                    return $this->stubs['resume'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotFoundException('Not found');
            }

            public function getStatus(string $workflowId): \SugarCraft\Crush\Workflows\WorkflowStatus
            {
                if (isset($this->stubs['getStatus'])) {
                    return $this->stubs['getStatus'];
                }
                throw new \SugarCraft\Crush\Workflows\WorkflowNotRunningException('Not found');
            }
        };
    }

    public function testWorkflowWithNullEngineShowsError(): void
    {
        // Chat with no workflow engine set
        $chat = new Chat(inputBuf: '/workflow');
        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Should have user command and error response in history
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/workflow', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('not configured', $next->history[1]->content);
    }

    public function testWorkflowHelpShowsAllCommands(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow help', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('/workflow run', $response);
        $this->assertStringContainsString('/workflow pause', $response);
        $this->assertStringContainsString('/workflow resume', $response);
        $this->assertStringContainsString('/workflow status', $response);
        $this->assertStringContainsString('/workflow list', $response);
    }

    public function testWorkflowHelpHonestlyDescribesPauseResumeLimitation(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow help', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $response = $next->history[1]->content;
        $this->assertStringContainsString('per-whole-stage only', $response);
        $this->assertStringContainsString('parallel', $response);
        $this->assertStringContainsString('partial-credit resume', $response);
    }

    public function testWorkflowListWithNoWorkflowsShowsEmptyMessage(): void
    {
        $engine = $this->createFakeWorkflowEngine(['listWorkflows' => []]);
        $chat = new Chat(inputBuf: '/workflow list', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('No workflows found', $next->history[1]->content);
    }

    public function testWorkflowListWithWorkflowsShowsNumberedList(): void
    {
        $engine = $this->createFakeWorkflowEngine(['listWorkflows' => ['build', 'test', 'deploy']]);
        $chat = new Chat(inputBuf: '/workflow list', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('build', $response);
        $this->assertStringContainsString('test', $response);
        $this->assertStringContainsString('deploy', $response);
        $this->assertStringContainsString('1.', $response);
        $this->assertStringContainsString('2.', $response);
        $this->assertStringContainsString('3.', $response);
    }

    public function testWorkflowRunWithEmptyNameShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow run', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowRunWithValidNameCallsEngine(): void
    {
        $result = new \SugarCraft\Crush\Workflows\WorkflowResult(
            workflowId: 'test-wf-1234',
            status: \SugarCraft\Crush\Workflows\WorkflowStatus::Completed,
            stageResults: [],
            totalTokens: 100,
            totalCost: 0.01,
        );

        $engine = $this->createFakeWorkflowEngine(['run' => $result]);
        $chat = new Chat(inputBuf: '/workflow run myworkflow', workflowEngine: $engine);

        // The submit tick echoes the command and nothing else: the engine has
        // not been called yet, because the run is a fiber the loop has not
        // stepped. Asserted rather than skipped past — "history has one entry
        // here" IS the not-frozen contract.
        [$next, $cmd] = $this->submitWorkflowCommand($chat);
        $this->assertCount(1, $next->history);
        $this->assertTrue($next->inFlight);

        [$after] = $next->update($this->settleWorkflowCmd($cmd));

        $this->assertCount(2, $after->history);
        $this->assertSame(Role::Assistant, $after->history[1]->role);
        $this->assertStringContainsString('myworkflow', $after->history[1]->content);
        $this->assertStringContainsString('completed', $after->history[1]->content);
        $this->assertFalse($after->inFlight, 'the settled reply must release the turn');
    }

    public function testWorkflowPauseWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow pause', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowResumeWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow resume', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    public function testWorkflowStatusWithEmptyIdShowsUsageError(): void
    {
        $engine = $this->createFakeWorkflowEngine();
        $chat = new Chat(inputBuf: '/workflow status', workflowEngine: $engine);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Error', $response);
        $this->assertStringContainsString('Usage:', $response);
    }

    // ─── /agents command parsing tests (R13) ─────────────────────────────────

    public function testBareAgentsCommandListsAgentsWhenNoneActive(): void
    {
        // Regression: "/agents" (no trailing space, no args) used to be
        // sliced at a fixed offset of 7, which for the 7-char string
        // "/agents" itself lands one character past its own end and
        // mis-parses into a single-char agent-name lookup for "s" instead
        // of routing to listAgents().
        $agentManager = $this->createAgentManagerWithAgents([]);
        $chat = new Chat(inputBuf: '/agents', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('No active agents configured', $response);
        $this->assertStringNotContainsString('Unknown agent', $response);
    }

    public function testBareAgentsCommandListsActiveAgents(): void
    {
        $agents = [
            new Agent(
                name: 'reviewer',
                description: 'Reviews code for bugs',
                prompt: 'You are a reviewer.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $chat = new Chat(inputBuf: '/agents', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $response = $next->history[1]->content;
        $this->assertStringContainsString('Active Agents', $response);
        $this->assertStringContainsString('reviewer', $response);
        $this->assertStringNotContainsString('Unknown agent', $response);
    }

    public function testAgentCommandWithNameStillLooksUpThatAgent(): void
    {
        // Guard against the fix over-correcting: "/agent <name>" (singular,
        // with a real argument) must still resolve to that specific agent.
        $agent = new Agent(
            name: 'coder',
            description: 'Writes and reviews code',
            prompt: 'You are a coder agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
        $agentManager = $this->createAgentManagerWithAgents([$agent]);
        $chat = new Chat(inputBuf: '/agent coder', agentManager: $agentManager);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Agent: coder', $next->history[1]->content);
    }

    // ─── Ctrl+A keyboard shortcut (R20) ──────────────────────────────────────

    /**
     * R20: Ctrl+A re-runs the exact real /agents dispatch (handleAgentsCommand())
     * that a typed "/agents" already uses, rather than a separate/duplicated
     * implementation — see Chat::update()'s KeyMsg match arm docblock.
     */
    public function testCtrlAKeyRunsRealAgentsCommandDispatch(): void
    {
        $agents = [
            new Agent(
                name: 'reviewer',
                description: 'Reviews code for bugs',
                prompt: 'You are a reviewer.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
        ];
        $agentManager = $this->createAgentManagerWithAgents($agents);
        $chat = new Chat(inputBuf: 'unsubmitted draft', agentManager: $agentManager);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Active Agents', $next->history[1]->content);
        $this->assertStringContainsString('reviewer', $next->history[1]->content);
    }

    /**
     * Guard against the fix over-reaching: plain "a" (no ctrl) must still be
     * typed into the input buffer like any other character.
     */
    public function testPlainAKeyIsTypedNotDispatched(): void
    {
        $chat = new Chat(inputBuf: 'h');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: false));

        $this->assertNull($cmd);
        $this->assertSame('ha', $next->inputBuf);
        $this->assertCount(0, $next->history);
    }

    /**
     * R20.fix regression: `Bootstrap::chat()` (the construction path
     * `bin/sugarcrush` actually runs) never passes an `agentManager:`, so a
     * typed "/agents" with no `agentManager` configured used to throw an
     * uncaught `RuntimeException('AgentManager not set')` straight out of
     * `Chat::update()` — candy-core's `Program` has no try/catch around its
     * synchronous update() dispatch, so this crashed the whole live CLI
     * instead of degrading like every other optional collaborator here
     * (workflow engine / session store / memory store).
     */
    public function testAgentsCommandDegradesGracefullyWithoutAgentManagerInsteadOfThrowing(): void
    {
        $chat = new Chat(inputBuf: '/agents');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('Agent manager not configured', $next->history[1]->content);
    }

    /**
     * Same regression as above, but via the new Ctrl+A shortcut this item
     * added — a single accidental keystroke used to trigger the crash
     * rather than requiring a user to type out "/agents" by hand.
     */
    public function testCtrlAKeyDegradesGracefullyWithoutAgentManagerInsteadOfThrowing(): void
    {
        $chat = new Chat(inputBuf: 'unsubmitted draft');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, rune: 'a', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        $this->assertSame('/agents', $next->history[0]->content);
        $this->assertStringContainsString('Agent manager not configured', $next->history[1]->content);
    }

    // =========================================================================
    // Idle-compaction wiring (R-idle-compaction): shouldPromptIdleCompaction()
    // must be exercised through the real submit() dispatch path, not only
    // called directly as a standalone predicate.
    // =========================================================================

    public function testSubmitPromptsIdleCompactionInsteadOfCallingBackendWhenIdleAndOversized(): void
    {
        // ~500,000 chars ≈ 125,010 estimated tokens (1 token ≈ 4 chars + 10
        // overhead), comfortably over the whole window this Chat's default
        // EchoBackend resolves to — 100,000, ContextWindow::FALLBACK_TOKENS,
        // because an echo backend reports no window. The idle tier is the only
        // one measured against the WHOLE window rather than a percentage of it.
        $bigMessage = Message::user(str_repeat('x', 500_000));
        $chat = (new Chat(history: [$bigMessage], inputBuf: 'hello again'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Short-circuited locally, same shape as a slash command: no Cmd
        // dispatched to the backend for this turn.
        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight);
        $this->assertSame('', $next->inputBuf);

        $this->assertCount(3, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello again', $next->history[1]->content);
        $this->assertSame(Role::Assistant, $next->history[2]->role);
        $this->assertStringContainsString('/compact', $next->history[2]->content);

        // The nudge itself counts as fresh activity, resetting the idle
        // clock so it doesn't repeat on the very next message.
        $this->assertNotNull($next->lastActivityAt());
        $this->assertGreaterThan(
            (new \DateTimeImmutable('5 seconds ago'))->getTimestamp(),
            $next->lastActivityAt()->getTimestamp(),
        );
    }

    /**
     * The other half of the idle gate: a session far past its window but a
     * user who is right here does NOT get the idle prompt.
     *
     * What it gets instead changed in crush_code.md Phase 5 item 5, and this
     * test was rewritten rather than relaxed. It used to assert the turn was
     * dispatched, which was only ever true because nothing enforced the
     * window: 325,286 estimated tokens (26 messages of ~50,000 chars at
     * chars/4 + 10 per message) against the default EchoBackend's
     * 100,000-token fallback is over the 95% blocking tier, and automatic
     * compaction cannot get back under it — it preserves the most recent 10
     * exchanges in full and those alone are ~250,220 tokens. So the turn is
     * now refused instead of being sent at a provider entitled to reject it.
     *
     * The claim the original test existed for still holds and is still pinned:
     * this is the BLOCKING tier, not the idle prompt. The distinguisher is
     * structural, not wording — the idle path leaves history untouched, so a
     * shrunken history[0] is reachable only through the compaction tier, and
     * the message count (23 compacted + 2) could not come from the idle path
     * either (26 + 2).
     */
    public function testSubmitRefusesTheTurnAtTheBlockingTierWhenActiveAndPastTheWindow(): void
    {
        $chat = (new Chat(history: self::oversizedHistory(), inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('5 minutes ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Refused: nothing scheduled, and the shell is left accepting input.
        $this->assertNull($cmd);
        $this->assertFalse($next->inFlight);

        // 3 summaries + the 10 preserved pairs (20 messages) + the notice for
        // the rewrite + the user turn + the refusal. The notice is the part
        // that arrived after the reviewer's B3: refusing the turn does not mean
        // leaving history alone, and this path used to adopt the rewrite in
        // silence while the DISPATCHING path announced it.
        $this->assertCount(26, $next->history);
        $this->assertSame(Role::System, $next->history[23]->role);
        $this->assertSame('hello', $next->history[24]->content);
        $this->assertSame(Role::Assistant, $next->history[25]->role);

        // Compaction ran before the refusal — only reachable via the tier
        // under test, never via the idle prompt — and the notice reports the
        // real before/after counts rather than a literal.
        $this->assertLessThan(500, mb_strlen($next->history[0]->content));
        $this->assertSame(50_003, mb_strlen($chat->history[0]->content), 'fixture: originals are large');
        $this->assertStringContainsString(
            count($chat->history) . ' messages -> 23 messages',
            $next->history[23]->content,
        );
    }

    /**
     * The 95% tier may not rewrite history in silence — the asymmetry the
     * reviewer's B3 found ran the wrong way round.
     *
     * The 85% tier deliberately suppresses its notice when compaction freed
     * nothing (announcing "saved 0%" every turn would be noise); the blocking
     * tier used to adopt the rewrite UNCONDITIONALLY and say nothing at all,
     * which made the destructive outcome the quiet one. Both now report the
     * same rewrite through the same message, and the pin is structural: the
     * refused turn carries a Role::System entry whose before/after counts match
     * the fixture and the result, sitting between the surviving history and the
     * user turn.
     */
    public function testTheBlockingTierReportsTheRewriteItCommitted(): void
    {
        $history = self::oversizedHistory();
        $chat = new Chat(history: $history, inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'fixture: the turn must be refused');

        $notice = $next->history[count($next->history) - 3];
        $this->assertSame(Role::System, $notice->role);
        $this->assertStringContainsString(
            count($history) . ' messages -> ' . (count($next->history) - 3) . ' messages',
            $notice->content,
        );
        $this->assertSame(Role::User, $next->history[count($next->history) - 2]->role);
        $this->assertSame(Role::Assistant, $next->history[count($next->history) - 1]->role);

        // And the rewrite really happened underneath it.
        $this->assertLessThan(500, mb_strlen($next->history[0]->content));
        $this->assertGreaterThan(50_000, mb_strlen($history[0]->content));
    }

    /**
     * The automatic 85% tier must not destroy the metadata on the exchanges it
     * PRESERVES — the reviewer's B2, and a functionality bug rather than
     * hardening because `/compact` used to be the only way to trigger it and
     * the user had typed it.
     *
     * `Message::toWire()` emits role, content, attachments and tool_calls, so a
     * pure wire round-trip has no representation at all for `$createdAt`,
     * `$toolResults`, `$reasoning`, `$imageBytes` or `$imageProtocol` — and
     * {@see \SugarCraft\Crush\Renderer} renders tool results, reasoning and
     * images. Measured before the fix, on the very last assistant turn of a
     * history the notice claims to preserve in full: `createdAt 1234567890 ->
     * now, toolCalls 1 -> 0, toolResults 1 -> 0, reasoning 'I thought hard' ->
     * null, imageBytes 'PNGDATA' -> null`.
     *
     * Pinned on the AUTOMATIC path (a plain Enter on an over-tier history), not
     * on `/compact`, and field by field rather than by object identity — the
     * fix happens to hand back the same instance, but what must hold is that
     * the renderable state survives, however it is carried.
     */
    public function testTheBackgroundTierPreservesMetadataOnTheTurnsItKeeps(): void
    {
        $history = self::compactableHistory();
        array_pop($history);
        $rich = new Message(
            Role::Assistant,
            'done',
            1_234_567_890,
            toolCalls: [new ToolCall('bash', ['command' => 'ls'], 'tc1')],
            toolResults: [new ToolResult('bash', 'listing', null, 'tc1')],
            reasoning: 'I thought hard',
            imageBytes: 'PNGDATA',
            imageProtocol: 'kitty',
        );
        $history[] = $rich;

        $chat = new Chat(history: $history, inputBuf: 'hello');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the turn must still go out');
        $this->assertLessThan(500, mb_strlen($next->history[0]->content), 'fixture: compaction must have run');

        // 3 summaries + 20 preserved: the rich turn is the last preserved one.
        $kept = $next->history[22];
        $this->assertSame('done', $kept->content);
        $this->assertSame(1_234_567_890, $kept->createdAt, 'the original timestamp, not a fresh time()');
        $this->assertCount(1, $kept->toolCalls);
        $this->assertSame('bash', $kept->toolCalls[0]->name);
        $this->assertCount(1, $kept->toolResults);
        $this->assertSame('listing', $kept->toolResults[0]->result);
        $this->assertSame('I thought hard', $kept->reasoning);
        $this->assertSame('PNGDATA', $kept->imageBytes);
        $this->assertSame('kitty', $kept->imageProtocol);
    }

    /**
     * The idle prompt's follow-up, driven — the reviewer's B4.
     *
     * The message used to end "or send another message to proceed anyway". That
     * survived the diff textually and stopped being true: this tier fires only
     * when the estimate is past the WHOLE window, which is necessarily past the
     * 85% and 95% tiers too, so the invited follow-up always lands on the
     * newly-live compaction block. Measured on this fixture: turn 1 gets the
     * advisory with history untouched, turn 2 is REFUSED and history[0] has gone
     * from 50,003 chars to a summary line.
     *
     * The positive claim is pinned structurally (not dispatched, and history
     * rewritten — the exact opposite of "proceed anyway"); the single string
     * assertion is a regression pin on the retired promise itself, which has no
     * structural form.
     */
    public function testTheIdlePromptsFollowUpLandsOnTheCompactionTiers(): void
    {
        $history = self::oversizedHistory();
        $chat = (new Chat(history: $history, inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$prompted, $promptCmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($promptCmd);
        $advisory = $prompted->history[count($prompted->history) - 1]->content;
        $this->assertStringContainsString('/compact', $advisory);
        $this->assertStringNotContainsString(
            'proceed anyway',
            $advisory,
            'the tiers below make an unconditional "send it anyway" promise false',
        );
        $this->assertSame(
            mb_strlen($history[0]->content),
            mb_strlen($prompted->history[0]->content),
            'the idle prompt itself must not compact',
        );

        // The follow-up the advisory is about.
        $followUp = new Chat(history: $prompted->history, inputBuf: 'again');
        [$next, $cmd] = $followUp->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'it did NOT proceed');
        $this->assertFalse($next->inFlight);
        $this->assertLessThan(
            mb_strlen($history[0]->content),
            mb_strlen($next->history[0]->content),
            'and history was rewritten underneath it, which "anyway" also denies',
        );
    }

    /**
     * Tier ORDER: an idle session that is ALSO over the blocking tier gets the
     * idle prompt, and its history is left alone.
     *
     * Both short-circuit the turn, so the observable difference is what
     * happened to history: the idle prompt appends to it untouched, the
     * blocking tier replaces it with the compacted form. Pinning that pins the
     * ordering in submit() without asserting on any wording.
     */
    public function testIdlePromptWinsOverTheCompactionTiersAndLeavesHistoryIntact(): void
    {
        $history = self::oversizedHistory();
        $chat = (new Chat(history: $history, inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertCount(count($history) + 2, $next->history);
        $this->assertSame(
            mb_strlen($history[0]->content),
            mb_strlen($next->history[0]->content),
            'the idle prompt must not compact',
        );
        $this->assertSame('hello', $next->history[26]->content);
        $this->assertStringContainsString('/compact', $next->history[27]->content);
    }

    /**
     * The 85% tier that does NOT block: compaction frees enough, so the turn
     * goes out with a Role::System notice recording what was thrown away.
     *
     * Fixture is 3 enormous exchanges followed by 10 trivial ones — 90,286
     * estimated tokens, over the 85,000 background tier of the EchoBackend's
     * 100,000-token fallback window. Compaction summarizes the 3 (the 10
     * preserved pairs are the trivial ones) and lands at ~337, well under the
     * 95,000 blocking tier, so this is the path where the tier acts and the
     * user's prompt still reaches the backend.
     */
    public function testSubmitCompactsAtTheBackgroundTierAndStillDispatchesTheTurn(): void
    {
        $history = self::compactableHistory();
        $chat = new Chat(history: $history, inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);

        // 3 summaries + 20 preserved + the notice + the user turn. No reminder
        // rides along: the compacted history is ~337 estimated tokens, nowhere
        // near the 70,000-token reminder tier.
        $this->assertCount(25, $next->history);
        $this->assertSame(Role::System, $next->history[23]->role);
        $this->assertSame(Role::User, $next->history[24]->role);
        $this->assertSame('hello', $next->history[24]->content);

        // The notice's figures are the real before/after counts, read back
        // from the fixture and the result rather than written as literals.
        $notice = $next->history[23]->content;
        $this->assertStringContainsString(
            count($history) . ' messages -> ' . (count($next->history) - 2) . ' messages',
            $notice,
        );

        // History really was rewritten: the enormous first exchange is a
        // one-line summary now.
        $this->assertLessThan(500, mb_strlen($next->history[0]->content));
    }

    /**
     * The 85% tier fires but compaction buys NOTHING, so it must stay silent.
     *
     * Ten exchanges is exactly recentPreserveCount, so ContextCompactor::compact()
     * returns the history untouched no matter how large it is — and a history
     * between the 85% and 95% tiers is one this app will see on every single turn
     * from there on. Announcing "saved 0%" each time would report work that did
     * not happen. The reminder still rides along (90,000 estimated tokens is over
     * the 70,000 reminder tier), which is what makes the assertion sharp: the
     * turn carries exactly ONE added system message and it sits AFTER the user
     * turn, where a reminder goes, not before it, where a compaction notice would.
     */
    public function testTheBackgroundTierStaysSilentWhenCompactionFreesNothing(): void
    {
        $history = [];
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("u{$i} " . str_repeat('x', 17_956));
            $history[] = Message::assistant("a{$i} " . str_repeat('y', 17_956));
        }
        $chat = new Chat(history: $history, inputBuf: 'hello');

        // 90,000 estimated tokens: over the 85,000 background tier of the
        // 100,000-token fallback window, under the 95,000 blocking tier.
        $this->assertSame(90_000, $chat->contextTokens());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertCount(22, $next->history);
        $this->assertSame(Role::User, $next->history[20]->role);
        $this->assertSame('hello', $next->history[20]->content);
        $this->assertSame(Role::System, $next->history[21]->role);
    }

    /**
     * 13 exchanges of ~50,000 chars each: 26 messages, 325,286 estimated
     * tokens at ContextCompactor's chars/4 + 10-per-message rate. Past the 95%
     * blocking tier of the 100,000-token fallback window, and unshrinkable —
     * compaction preserves 10 whole exchanges and those are ~250,220 tokens on
     * their own. Prefixes differ so the three summaries stay distinct;
     * identical ones would be collapsed into a single "[3x] …" entry by
     * ContextCompactor's stage 3 and the counts below would shift.
     *
     * @return list<Message>
     */
    private static function oversizedHistory(): array
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user("u{$i} " . str_repeat('x', 50_000));
            $history[] = Message::assistant("a{$i} " . str_repeat('y', 50_000));
        }

        return $history;
    }

    /**
     * 3 enormous exchanges then 10 trivial ones: 26 messages, 90,286 estimated
     * tokens — over the 85% background tier of the 100,000-token fallback
     * window, and compactable to ~337 because the 10 exchanges compaction
     * preserves in full are the trivial ones.
     *
     * @return list<Message>
     */
    private static function compactableHistory(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user("u{$i} " . str_repeat('x', 60_000));
            $history[] = Message::assistant("a{$i} " . str_repeat('y', 60_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    public function testSubmitDispatchesToBackendNormallyWhenIdleButHistorySmall(): void
    {
        $chat = (new Chat(history: [Message::user('hi')], inputBuf: 'hello'))
            ->withLastActivity(new \DateTimeImmutable('2 hours ago'));

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        $this->assertCount(2, $next->history);
    }

    public function testFreshChatHasNoLastActivityAndSubmitRecordsOneOnRealPrompt(): void
    {
        $chat = new Chat(inputBuf: 'hello');
        $this->assertNull($chat->lastActivityAt());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // No lastActivityAt yet means shouldPromptIdleCompaction() can never
        // fire regardless of token count, so this still dispatches normally.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertNotNull($next->lastActivityAt());
    }

    public function testWithLastActivityIsFluentAndImmutable(): void
    {
        $chat = new Chat();
        $t = new \DateTimeImmutable('1 hour ago');
        $next = $chat->withLastActivity($t);

        $this->assertNull($chat->lastActivityAt());
        $this->assertSame($t, $next->lastActivityAt());
        $this->assertNotSame($chat, $next);
    }

    // =========================================================================
    // Reminder-tier wiring (R-reminder-consumer): ContextCompactor's 70%
    // shouldSendReminder() (added by R21) must be exercised through the real
    // submit() dispatch path, not only called directly as a standalone
    // predicate — and it must surface as a soft, non-blocking notice distinct
    // from the hard idle-compaction prompt above (which short-circuits the
    // turn instead of calling the backend).
    // =========================================================================

    public function testSubmitSurfacesReminderMessageAlongsideRealPromptWhenOverReminderThreshold(): void
    {
        // ~280,000 chars ≈ 70,010 estimated tokens (1 token≈4 chars + 10
        // overhead) — over ContextCompactor's default 70% reminder tier of the
        // 100,000-token window the default EchoBackend resolves to
        // (ContextWindow::FALLBACK_TOKENS, so 70,000), but comfortably under
        // that window itself, which is where the idle tier sits. The budget is
        // no longer a constant on Chat: on a real provider both numbers move.
        $bigMessage = Message::user(str_repeat('x', 280_000));
        $chat = new Chat(history: [$bigMessage], inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // Unlike the hard idle-compaction prompt, this does NOT short-circuit
        // the turn: a real backend Cmd is still scheduled.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        $this->assertSame('', $next->inputBuf);

        $this->assertCount(3, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello', $next->history[1]->content);

        // Distinct from the hard prompt's Role::Assistant bubble: the
        // reminder rides along as a Role::System notice.
        $this->assertSame(Role::System, $next->history[2]->role);
        $this->assertStringContainsString('/compact', $next->history[2]->content);
        $this->assertStringContainsString('70010', $next->history[2]->content);
    }

    public function testSubmitOmitsReminderMessageWhenUnderReminderThreshold(): void
    {
        $chat = new Chat(history: [Message::user('hi')], inputBuf: 'hello');

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertCount(2, $next->history);
        $this->assertSame(Role::User, $next->history[1]->role);
        $this->assertSame('hello', $next->history[1]->content);
    }

    public function testReminderMessageResolvesThroughEchoBackendWithoutBreakingTheTurn(): void
    {
        $bigMessage = Message::user(str_repeat('x', 280_000));
        $chat = new Chat(history: [$bigMessage], inputBuf: 'hello', backend: new EchoBackend());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Drive the scheduled Cmd exactly like testEchoBackendRoundTrip: the
        // AsyncCmd wraps a promise that resolves synchronously for EchoBackend.
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolvedMsg = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolvedMsg): void {
            $resolvedMsg = $msg;
        });
        $this->assertInstanceOf(AssistantMsg::class, $resolvedMsg);

        [$final] = $next->update($resolvedMsg);
        $this->assertCount(4, $final->history);
        $this->assertSame(Role::System, $final->history[2]->role);
        $this->assertSame(Role::Assistant, $final->history[3]->role);
        $this->assertFalse($final->inFlight);
    }

    /**
     * Create an AgentManager stub with predefined agents (mirrors the helper
     * in AgentsCommandTest — AgentManager's real constructor needs a
     * ProviderInterface + SkillRegistry that aren't relevant here).
     *
     * @param Agent[] $agents
     */
    private function createAgentManagerWithAgents(array $agents): AgentManager
    {
        $reflection = new \ReflectionClass(AgentManager::class);
        $agentManager = $reflection->newInstanceWithoutConstructor();

        $agentsProperty = $reflection->getProperty('agents');
        $agentsProperty->setAccessible(true);
        $indexedAgents = [];
        foreach ($agents as $agent) {
            $indexedAgents[$agent->name] = $agent;
        }
        $agentsProperty->setValue($agentManager, $indexedAgents);

        return $agentManager;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }

        $sandbox = getenv('HOME');
        $this->restoreHomeSandbox();
        if (is_string($sandbox) && str_contains($sandbox, '/chat_test_home_')) {
            @rmdir($sandbox);
        }

        parent::tearDown();
        \Mockery::close();
    }

    /**
     * A temp path this test owns: handed out unique, registered for cleanup,
     * and never created here — callers use it as a sentinel a forked child
     * writes to prove it ran.
     */
    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * Every `sc_chat_tool_*` payload currently in the temp dir.
     *
     * `sys_get_temp_dir()` deliberately, not the suite's TMPDIR sandbox: PHP
     * resolves and caches the temp dir once per process, so tests/bootstrap.php
     * setting TMPDIR moves it for the CHILDREN this suite spawns and not for
     * this process — {@see \SugarCraft\Crush\Support\ToolIpcFiles::reserve()}
     * running in-process still lands in the real one.
     *
     * @return list<string>
     */
    private static function toolIpcFiles(): array
    {
        $found = glob(sys_get_temp_dir() . '/' . ToolIpcFiles::CHAT_PREFIX . '*') ?: [];
        sort($found);

        return $found;
    }

    /**
     * Drive an assistant message with tool calls through the full async
     * two-step flow: update(AssistantMsg) shows "running" placeholders and
     * returns a Cmd; running that Cmd forks the tool calls and returns a
     * Promise (AsyncCmd) that only settles once every forked child has
     * exited (see Chat::waitForToolChildrenAsync()) - so, unlike the old
     * fully-synchronous handleToolCalls(), the real results (and any
     * onToolCall side effects) aren't observable until the resulting
     * ToolResultsMsg is actually dispatched back into update(). Returns
     * [$afterPlaceholders, $final].
     *
     * @return array{0: Chat, 1: Chat}
     */
    private function runToolCallsToCompletion(Chat $chat, Message $assistantMessage): array
    {
        [$afterPlaceholders, $cmd] = $chat->update(new AssistantMsg($assistantMessage));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        // A single run()/stop() pair - see EngineBackendTest::awaitPromise()'s
        // docblock for why a repeated add-short-timer-then-run() polling
        // dance is fragile against real fork/WNOHANG timing.
        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(\SugarCraft\Crush\ToolResultsMsg::class, $resolved, 'tool execution did not complete within the test timeout');

        [$final] = $afterPlaceholders->update($resolved);

        return [$afterPlaceholders, $final];
    }

    // ---------------------------------------------------------------
    // Backend tool-lifecycle events (crush_feat.md §1 E1, W2.S1c)
    // ---------------------------------------------------------------

    public function testBackendToolEventsSurviveAsAQueuedMsgInsteadOfBeingDropped(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        // Against the pre-W2.S1c Chat this resolved to a plain AssistantMsg:
        // the backend's $onEvent was never passed, so both tool events were
        // dropped and the turn rendered as a bare "thinking…" spinner.
        $resolved = $this->resolveBackendCmd($chat);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $resolved);
        $this->assertCount(2, $resolved->events);
        $this->assertSame('there is one file', $resolved->message->content);
    }

    public function testToolStartedRendersARunningPlaceholderBeforeTheResultArrives(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('done')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        [$running, $cmd] = $afterSubmit->update($this->resolveBackendCmd($chat));

        $placeholder = $running->history[count($running->history) - 1];
        $this->assertSame(Role::System, $placeholder->role);
        $this->assertSame('call_1', $placeholder->pendingToolCallId);
        $this->assertSame('bash(command: "ls")', $placeholder->content);
        // The running state is a real, rendered frame - not folded away in the
        // same update() as its result.
        $this->assertStringContainsString('running: bash(command: "ls")', \SugarCraft\Crush\Renderer::render($running));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $cmd());
    }

    public function testToolFinishedReplacesThePlaceholderAndThenHandsOffTheReply(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt', durationMs: 12, diff: '--- a\n+++ b\n')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $pending = array_filter($final->history, static fn(Message $m): bool => $m->pendingToolCallId !== null);
        $this->assertSame([], $pending, 'the running placeholder was never replaced');

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $result = $withResults[0]->toolResults[0];
        $this->assertSame('bash', $result->name);
        $this->assertSame('a.txt', $result->result);
        $this->assertSame(12, $result->durationMs);
        $this->assertSame('--- a\n+++ b\n', $result->diff);
        $this->assertSame('a.txt', $withResults[0]->content);

        // The turn's own reply still lands through the ordinary AssistantMsg
        // arm, after the tool calls that produced it.
        $last = $final->history[count($final->history) - 1];
        $this->assertSame('there is one file', $last->content);
        $this->assertFalse($final->inFlight);
    }

    /**
     * The user-reported gap behind crush_feat.md §3 E2: on the live
     * (EngineBackend) path the only carrier of a call's arguments is the
     * running placeholder, and replacing it used to throw them away - so the
     * finished row could name the TOOL but never the command. ToolFinished
     * carries no arguments, so this is the one point the description can be
     * preserved at; against the old Chat $result->description is null.
     */
    public function testToolFinishedCarriesThePlaceholdersDescriptionOntoTheResult(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls -la']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $this->assertSame('bash(command: "ls -la")', $withResults[0]->toolResults[0]->description);
    }

    /**
     * An unmatched result is still appended rather than dropped, and honestly
     * carries no description - there was no placeholder to read one off.
     */
    public function testAnUnmatchedToolResultIsAppendedWithoutADescription(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls -la']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')),
            ], Message::assistant('there is one file')),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $this->assertSame('a.txt', $withResults[0]->toolResults[0]->result);
        $this->assertNull($withResults[0]->toolResults[0]->description);
    }

    public function testToolFinishedCorrelatesOnTheEventIdNotTheToolsInventedResultId(): void
    {
        // A tool never sees its own call id, so built-ins routinely return an
        // invented one; only ToolFinished::$toolCallId carries the id the
        // placeholder was keyed with.
        $call = new EngineToolCall('call_1', 'read', ['path' => 'x']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('', 'contents')),
            ], Message::assistant('read it')),
            inputBuf: 'read x',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $this->assertSame(
            [],
            array_filter($final->history, static fn(Message $m): bool => $m->pendingToolCallId !== null),
        );
        // user turn + the replaced placeholder + the reply. A 4th entry would
        // mean the result was appended alongside an orphaned placeholder.
        $this->assertCount(3, $final->history, 'the result was appended instead of replacing the placeholder');
    }

    public function testFailedToolCallRendersAsAnErrorResult(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'boom']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend([
                ToolStarted::fromCall($call),
                ToolFinished::fromResult($call, new EngineToolResult('call_1', 'command not found', isError: true)),
            ], Message::assistant('that failed')),
            inputBuf: 'run boom',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $final = $this->drainBackendEvents($afterSubmit, $this->resolveBackendCmd($chat));

        $withResults = array_values(array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $this->assertTrue($withResults[0]->toolResults[0]->isError());
        $this->assertSame('command not found', $withResults[0]->toolResults[0]->error);
        $this->assertSame('Tool error: command not found', $withResults[0]->content);
    }

    public function testTurnWithoutToolEventsStillResolvesToAPlainAssistantMsg(): void
    {
        $chat = new Chat(backend: new EchoBackend(), inputBuf: 'hello');
        $this->assertInstanceOf(AssistantMsg::class, $this->resolveBackendCmd($chat));
    }

    public function testStaleBackendToolEventsAreDropped(): void
    {
        $chat = new Chat(history: [Message::user('hi')], generation: 7);
        $msg = new BackendToolEventsMsg(
            [ToolStarted::fromCall(new EngineToolCall('call_1', 'bash', []))],
            Message::assistant('late'),
            3,
        );

        [$next, $cmd] = $chat->update($msg);
        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testBackendFailureAfterToolCallsStillShowsWhatTheToolsDid(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(
            backend: $this->eventEmittingBackend(
                [ToolStarted::fromCall($call), ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt'))],
                null,
                new \RuntimeException('provider exploded'),
            ),
            inputBuf: 'list files',
        );

        [$afterSubmit] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $resolved = $this->resolveBackendCmd($chat);
        $this->assertInstanceOf(BackendToolEventsMsg::class, $resolved);

        $final = $this->drainBackendEvents($afterSubmit, $resolved);
        $this->assertNotSame([], array_filter($final->history, static fn(Message $m): bool => $m->toolResults !== []));
        $this->assertStringContainsString('provider exploded', $final->history[count($final->history) - 1]->content);
    }

    // ---------------------------------------------------------------
    // Live tool-event pump (crush_feat.md §1 E1, F.PROGRESS)
    // ---------------------------------------------------------------

    public function testToolEventsReachTheTranscriptWhileTheTurnIsStillRunning(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $backend = $this->pendingEventBackend();
        $chat = new Chat(backend: $backend, inputBuf: 'list files');

        [$afterSubmit, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $cmd();
        $this->assertNotNull($backend->emit, 'the backend never received the $onEvent seam');

        // The provider has NOT answered yet: before the live pump, an event
        // reported here sat in a closure-local array that nothing could reach
        // until the turn's promise settled, so the user watched a bare
        // "thinking…" spinner for the whole of a multi-round tool turn.
        ($backend->emit)(ToolStarted::fromCall($call));
        $this->assertTrue($afterSubmit->inFlight);

        [$running, $more] = $afterSubmit->update(new \SugarCraft\Crush\ToolEventPumpMsg());
        $placeholder = $running->history[count($running->history) - 1];
        $this->assertSame('call_1', $placeholder->pendingToolCallId);
        $this->assertStringContainsString('running: bash(command: "ls")', \SugarCraft\Crush\Renderer::render($running));
        $this->assertNull($more, 'nothing else was queued, so the pump should not re-schedule');

        ($backend->emit)(ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')));
        [$done] = $running->update(new \SugarCraft\Crush\ToolEventPumpMsg());

        $this->assertSame([], array_filter($done->history, static fn (Message $m): bool => $m->pendingToolCallId !== null));
        $withResults = array_values(array_filter($done->history, static fn (Message $m): bool => $m->toolResults !== []));
        $this->assertCount(1, $withResults);
        $this->assertSame('a.txt', $withResults[0]->toolResults[0]->result);
        // Still mid-turn: the reply has not arrived and must not be faked.
        $this->assertTrue($done->inFlight);
    }

    public function testSubscriptionsDeclareTheToolEventPumpWhileATurnIsInFlight(): void
    {
        $subs = (new Chat(inFlight: true))->subscriptions();

        $this->assertNotNull($subs);
        $this->assertTrue($subs->has('crush.tool-event-poll'));

        $sub = $subs->all()[0];
        $this->assertSame(\SugarCraft\Core\Kind::Tick, $sub->kind);
        $this->assertSame(0.1, $sub->params['seconds']);
        $this->assertInstanceOf(\SugarCraft\Crush\ToolEventPumpMsg::class, ($sub->produce)());
    }

    public function testToolEventPumpSubscriptionIsDroppedOnceTheInboxIsEmptyAndTheTurnIsOver(): void
    {
        // An unconditional 10Hz timer would repaint an idle chat forever.
        $this->assertNull((new Chat())->subscriptions());

        // A leftover event with no turn behind it still gets a wake-up -
        // otherwise it would sit in the inbox until the next submit.
        $idle = new Chat();
        $idle->enqueueToolEvent(ToolStarted::fromCall(new EngineToolCall('call_1', 'bash', [])));
        $subs = $idle->subscriptions();
        $this->assertNotNull($subs);
        $this->assertTrue($subs->has('crush.tool-event-poll'));
    }

    public function testToolEventPumpAppliesOneEventPerUpdateAndReSchedulesForTheRest(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(history: [Message::user('list files')]);
        $chat->enqueueToolEvent(ToolStarted::fromCall($call));
        $chat->enqueueToolEvent(ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')));

        [$running, $cmd] = $chat->update(new \SugarCraft\Crush\ToolEventPumpMsg());
        // One event per update() is what makes the running state a rendered
        // frame rather than a state the transcript skips straight past.
        $this->assertSame('call_1', $running->history[count($running->history) - 1]->pendingToolCallId);
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(\SugarCraft\Crush\ToolEventPumpMsg::class, $cmd());

        [$done, $noMore] = $running->update($cmd());
        $this->assertSame([], array_filter($done->history, static fn (Message $m): bool => $m->pendingToolCallId !== null));
        $this->assertNull($noMore);
        $this->assertSame([], $done->liveToolEvents());
    }

    public function testToolEventInboxSurvivesMutateSoALaterCloneSeesTheEvent(): void
    {
        $chat = new Chat();
        $chat->enqueueToolEvent(ToolStarted::fromCall(new EngineToolCall('call_1', 'bash', ['command' => 'ls'])));

        // Every field must be threaded through mutate()'s constructorProps map
        // or it is silently dropped - for the inbox that means the event the
        // backend appended vanishes the moment the user presses a key.
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertCount(1, $typed->liveToolEvents());

        [$running] = $typed->update(new \SugarCraft\Crush\ToolEventPumpMsg());
        $this->assertSame('call_1', $running->history[count($running->history) - 1]->pendingToolCallId);
    }

    public function testStaleQueuedToolEventsAreDroppedButStillDrained(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $chat = new Chat(generation: 7);
        // An aborted turn's backend can keep reporting for a while.
        $chat->enqueueToolEvent(ToolStarted::fromCall($call), 3);
        $chat->enqueueToolEvent(ToolStarted::fromCall($call), 7);

        [$skipped, $cmd] = $chat->update(new \SugarCraft\Crush\ToolEventPumpMsg());
        $this->assertSame([], $skipped->history, 'a stale event was applied to the live transcript');
        $this->assertInstanceOf(\Closure::class, $cmd, 'skipping must not strand the events behind it');

        [$applied] = $skipped->update($cmd());
        $this->assertCount(1, $applied->history);
    }

    public function testLivePumpedEventsAreNotReplayedWhenTheTurnResolves(): void
    {
        $call = new EngineToolCall('call_1', 'bash', ['command' => 'ls']);
        $backend = $this->pendingEventBackend();
        $chat = new Chat(backend: $backend, inputBuf: 'list files');

        [$afterSubmit, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        ($backend->emit)(ToolStarted::fromCall($call));
        ($backend->emit)(ToolFinished::fromResult($call, new EngineToolResult('call_1', 'a.txt')));
        $drained = $this->drainBackendEvents($afterSubmit, new \SugarCraft\Crush\ToolEventPumpMsg());

        $backend->deferred->resolve(Message::assistant('there is one file'));

        // Both consumers share ONE inbox and drain it destructively, so a
        // turn whose events were already shown resolves to a plain
        // AssistantMsg instead of replaying them under the reply.
        $this->assertInstanceOf(AssistantMsg::class, $resolved);

        [$final] = $drained->update($resolved);
        $this->assertCount(
            1,
            array_filter($final->history, static fn (Message $m): bool => $m->toolResults !== []),
            'the tool call was rendered twice',
        );
        $this->assertSame('there is one file', $final->history[count($final->history) - 1]->content);
    }

    /**
     * A Backend that hands its `$onEvent` seam back to the test (as `$emit`)
     * and does not settle until the test resolves `$deferred` - the shape of a
     * real provider turn, where tool events fire long before the reply.
     */
    private function pendingEventBackend(): Backend
    {
        return new class implements Backend {
            public ?\Closure $emit = null;

            public \React\Promise\Deferred $deferred;

            public function __construct()
            {
                $this->deferred = new \React\Promise\Deferred();
            }

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->emit = $onEvent === null ? null : \Closure::fromCallable($onEvent);

                return $this->deferred->promise();
            }
        };
    }

    /**
     * A Backend that reports $events through the $onEvent seam and then either
     * resolves with $reply or rejects with $failure.
     *
     * @param list<ToolStarted|ToolFinished> $events
     */
    private function eventEmittingBackend(array $events, ?Message $reply, ?\Throwable $failure = null): \SugarCraft\Crush\Backend
    {
        return new class ($events, $reply, $failure) implements \SugarCraft\Crush\Backend {
            /** @param list<ToolStarted|ToolFinished> $events */
            public function __construct(
                private array $events,
                private ?Message $reply,
                private ?\Throwable $failure,
            ) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                foreach ($this->events as $event) {
                    if ($onEvent !== null) {
                        $onEvent($event);
                    }
                }
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return $this->reply ?? Message::assistant('');
            }

            public function completeAsync(array $history, callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return new Promise(function (callable $resolve, callable $reject) use ($history, $onToken, $onEvent): void {
                    try {
                        $resolve($this->complete($history, $onToken, $onEvent));
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                });
            }
        };
    }

    /**
     * Submit $chat's input buffer and run the scheduled backend Cmd to the Msg
     * it resolves to (synchronous - every backend used here settles inline).
     */
    private function resolveBackendCmd(Chat $chat): mixed
    {
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /**
     * Feed $msg into $chat and keep following the Cmd it returns until the
     * event queue drains and the final AssistantMsg has been applied.
     */
    private function drainBackendEvents(Chat $chat, mixed $msg): Chat
    {
        $steps = 0;
        while ($msg !== null) {
            [$chat, $cmd] = $chat->update($msg);
            if ($cmd === null || ++$steps > 20) {
                break;
            }
            $msg = $cmd();
        }

        return $chat;
    }

    // ---------------------------------------------------------------
    // Hook gating on the Chat-native tool path (crush_feat.md §1 E1, W2.S1d)
    // ---------------------------------------------------------------

    /**
     * A hook that delegates to $handler, matching every tool name.
     *
     * The matcher is `.*`, not `*`: HookRegistry compiles matchers as
     * regexes, and a bare `*` is invalid and would silently never match.
     */
    private function spyHook(HookEvent $event, \Closure $handler): HookInterface
    {
        return new class ($event, $handler) implements HookInterface {
            public function __construct(
                private readonly HookEvent $hookEvent,
                private readonly \Closure $handler,
            ) {}

            public function name(): string
            {
                return 'spy-' . $this->hookEvent->value;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return ($this->handler)($context);
            }
        };
    }

    private function hookManagerWith(HookInterface ...$hooks): HookManager
    {
        $manager = new HookManager(new HookRegistry());
        foreach ($hooks as $hook) {
            $manager->register($hook);
        }

        return $manager;
    }

    public function testWithHooksReturnsANewChatAndLeavesTheOriginalUngated(): void
    {
        $chat = new Chat();
        $hooks = $this->hookManagerWith();

        $gated = $chat->withHooks($hooks);

        $this->assertNull($chat->hooks());
        $this->assertSame($hooks, $gated->hooks());
        $this->assertNotSame($chat, $gated);
    }

    /**
     * The Chat-native (registerTool) pipeline used to invoke its callback
     * with zero gating while the engine pipeline ran the very same call
     * through HookManager - crush_feat.md §1 D's "two independent,
     * non-unified tool-calling pipelines". Fails against the old Chat: no
     * PreToolUse hook ever fired for a registerTool() call.
     */
    public function testChatNativeToolCallRunsThroughThePreToolUseHook(): void
    {
        $seen = [];
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $context) use (&$seen): HookResult {
                $seen[] = [$context->toolName, $context->toolArgs];

                return HookResult::allow();
            },
        ));

        $chat = (new Chat(history: [Message::user('list files')]))
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        // Recorded in THIS process: hooks run parent-side, before the fork.
        $this->assertSame([['bash', ['cmd' => 'ls']]], $seen);
        $this->assertStringContainsString('total 0', $final->history[2]->content);
    }

    /**
     * A PreToolUse DENY must block the callback outright and still resolve
     * the running placeholder - the ToolFinished half of the pair - with an
     * honest error result rather than leaving a spinner forever.
     */
    public function testPreToolUseDenyBlocksTheToolAndStillResolvesThePlaceholder(): void
    {
        $sentinel = sys_get_temp_dir() . '/sc_hook_deny_' . bin2hex(random_bytes(8));
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static fn(HookContext $context): HookResult => HookResult::deny('rm -rf is not allowed'),
        ));

        $chat = (new Chat())
            ->registerTool('bash', static function (array $args) use ($sentinel): string {
                // Written from the forked child if the deny leaks through:
                // an on-disk sentinel is the only side effect that survives
                // the fork boundary back to this process.
                file_put_contents($sentinel, 'ran');

                return 'deleted everything';
            })
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1');
        [$afterPlaceholders, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertFileDoesNotExist($sentinel, 'a denied tool call still reached its callback');

        $this->assertSame('call_1', $afterPlaceholders->history[1]->pendingToolCallId);
        $this->assertNull($final->history[1]->pendingToolCallId);
        $this->assertSame('Tool error: Hook denied: rm -rf is not allowed', $final->history[1]->content);
        $this->assertTrue($final->history[1]->toolResults[0]->isError());
        $this->assertSame('call_1', $final->history[1]->toolResults[0]->id);
    }

    /**
     * PostToolUse has to observe the real output in the PARENT: run inside
     * the forked child, every effect of the hook chain (audit trail,
     * accumulated state) would die with that child's memory.
     */
    public function testPostToolUseHookObservesTheToolOutputInTheParentProcess(): void
    {
        $outputs = [];
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PostToolUse,
            static function (HookContext $context) use (&$outputs): HookResult {
                $outputs[] = $context->toolOutput;

                return HookResult::allow();
            },
        ));

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(['total 0'], $outputs);
    }

    /**
     * A call that never ran has no output to observe - Runtime skips its own
     * postToolUse on the deny branch, and so must Chat.
     */
    public function testDeniedToolCallSkipsThePostToolUseHook(): void
    {
        $postCalls = 0;
        $hooks = $this->hookManagerWith(
            $this->spyHook(HookEvent::PreToolUse, static fn(HookContext $c): HookResult => HookResult::deny('nope')),
            $this->spyHook(HookEvent::PostToolUse, static function (HookContext $c) use (&$postCalls): HookResult {
                ++$postCalls;

                return HookResult::allow();
            }),
        );

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(0, $postCalls);
    }

    /**
     * MODIFY is "allowed, with rewritten input" on both pipelines - the
     * rewritten arguments are what the callback must actually receive.
     */
    public function testModifyHookRewritesTheArgumentsBeforeTheToolRuns(): void
    {
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static fn(HookContext $c): HookResult => HookResult::modify((string) json_encode(['cmd' => 'ls -la'])),
        ));

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args): string => 'ran: ' . ($args['cmd'] ?? 'nothing'))
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame('ran: ls -la', $final->history[1]->content);
    }

    /**
     * Round 5 finding 6: WHICH ACTIONS CARRY A REWRITE, asserted at the seam.
     *
     * Only a MODIFY or an ASK does. `HookResult`'s constructor is public, so an
     * ALLOW carrying a `modifiedInput` is constructible, and centralising the
     * decode behind {@see HookResult::rewrittenArgs()} briefly lost the
     * `isModified()` guard on this side — Chat applied such a rewrite while
     * {@see \SugarCraft\Crush\Runtime::rewrittenArguments()} ignored it, which
     * made {@see Chat::gateToolCall()}'s "mirror, decision for decision"
     * promise false. Runtime is the side that is right: only a MODIFY makes
     * {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} take another
     * pass, so a rewrite riding on an ALLOW has been judged by nobody behind
     * the hook that made it.
     *
     * Driven through reflection rather than a real turn ON PURPOSE, and the
     * reason is worth recording: `executeHooks()` never RETURNS a hook's ALLOW
     * — its scan keeps only the pending ASK/MODIFY and settles an otherwise
     * clean pass with a fresh {@see HookResult::allow()} — so an end-to-end
     * chat turn cannot reach this branch at all and a test built as one passes
     * whatever `applyRewrite()` does. This is a guard on a seam, pinned where
     * the seam is.
     */
    public function testOnlyAModifyOrAnAskCarriesARewriteThroughApplyRewrite(): void
    {
        $rewrite = (string) json_encode(['cmd' => 'rm -rf /']);
        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        $context = new HookContext(
            sessionId: '',
            toolName: 'bash',
            toolArgs: ['cmd' => 'ls'],
            toolInput: '{"cmd":"ls"}',
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: '/test/root',
        );

        $applyRewrite = new \ReflectionMethod(Chat::class, 'applyRewrite');
        $applyRewrite->setAccessible(true);
        $apply = static fn(HookResult $r): array => $applyRewrite->invoke(null, $call, $context, $r);

        [$allowed] = $apply(new HookResult(HookResult::ALLOW, '', $rewrite));
        $this->assertSame(['cmd' => 'ls'], $allowed->arguments, 'nothing re-scanned an ALLOW-carried rewrite');

        [$modified] = $apply(HookResult::modify($rewrite));
        $this->assertSame(['cmd' => 'rm -rf /'], $modified->arguments);

        [$asked] = $apply(HookResult::ask('Proceed?', $rewrite));
        $this->assertSame(['cmd' => 'rm -rf /'], $asked->arguments);
    }

    /**
     * ...and PostToolUse has to observe THOSE arguments, not the ones the
     * model proposed. Chat handed the post-hook the HookContext built before
     * the pre-hooks ran, so AuditHook recorded `rm -rf /` for a call that
     * actually executed `ls -la` - a log naming a command that never ran, on
     * precisely the calls anybody would want the record for.
     */
    public function testPostToolUseObservesTheRewrittenArgumentsNotTheProposedOnes(): void
    {
        $observed = [];
        $hooks = $this->hookManagerWith(
            $this->spyHook(
                HookEvent::PreToolUse,
                static fn(HookContext $c): HookResult => HookResult::modify((string) json_encode(['cmd' => 'ls -la'])),
            ),
            $this->spyHook(HookEvent::PostToolUse, static function (HookContext $c) use (&$observed): HookResult {
                $observed[] = ['args' => $c->toolArgs, 'input' => $c->toolInput];

                return HookResult::allow();
            }),
        );

        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args): string => 'ran: ' . ($args['cmd'] ?? 'nothing'))
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1');
        $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame([[
            'args' => ['cmd' => 'ls -la'],
            'input' => '{"cmd":"ls -la"}',
        ]], $observed);
    }

    /**
     * Runtime resolves the tool first and only builds a HookContext once it
     * has one, so an unknown name never reaches the hook chain. Chat matches
     * that ordering rather than inventing a second convention.
     */
    public function testUnknownToolIsReportedWithoutConsultingTheHooks(): void
    {
        $preCalls = 0;
        $hooks = $this->hookManagerWith($this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $c) use (&$preCalls): HookResult {
                ++$preCalls;

                return HookResult::allow();
            },
        ));

        // Some tool must be registered for update() to enter the tool path
        // at all - just not the one the assistant asked for.
        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args) => 'total 0')
            ->withHooks($hooks);

        $call = new \SugarCraft\Crush\ToolCall('nope', [], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertSame(0, $preCalls);
        $this->assertSame('Tool error: Unknown tool: nope', $final->history[1]->content);
    }

    /**
     * A Chat with no HookManager keeps its pre-gating behaviour exactly:
     * embedders and tests that never wire hooks must be unaffected.
     */
    public function testToolCallsStillRunUngatedWhenNoHookManagerIsWired(): void
    {
        $chat = (new Chat())->registerTool('bash', static fn(array $args) => 'total 0');

        $call = new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_1');
        [, $final] = $this->runToolCallsToCompletion($chat, Message::assistant('running')->withToolCalls([$call]));

        $this->assertNull($chat->hooks());
        $this->assertSame('total 0', $final->history[1]->content);
    }

    // =========================================================================
    // crush_feat.md §4 E3/E6/E7: match indices, category grouping, MRU bias
    // =========================================================================

    /** Open palette, then type $query into it. */
    private function paletteWithQuery(string $query): Chat
    {
        [$current] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        foreach (str_split($query) as $ch) {
            [$current] = $current->update($ch === ' '
                ? new KeyMsg(KeyType::Space, ' ')
                : new KeyMsg(KeyType::Char, $ch));
        }

        return $current;
    }

    /**
     * §4 E3: paletteMatches() used to map every MatchResult down to its
     * haystack, throwing the matched indices away - Highlighter had nothing
     * to work with. The sibling accessor keeps them.
     */
    public function testPaletteMatchResultsKeepMatchedIndicesForHighlighting(): void
    {
        $chat = $this->paletteWithQuery('them');

        $results = $chat->paletteMatchResults();
        $this->assertSame('Switch theme', $results[0]->haystack);
        $this->assertNotSame([], $results[0]->matchedIndices);
        // Every typed character landed, and on the "theme" run specifically.
        $this->assertCount(4, $results[0]->matchedIndices);
        $this->assertSame('t', mb_substr('Switch theme', $results[0]->matchedIndices[0], 1));
    }

    /** The label list stays exactly the haystacks of the result list. */
    public function testPaletteMatchesMirrorsPaletteMatchResults(): void
    {
        $chat = $this->paletteWithQuery('s');

        $this->assertSame(
            array_map(static fn($r) => $r->haystack, $chat->paletteMatchResults()),
            $chat->paletteMatches(),
        );
    }

    /** An empty query yields index-less results, so highlighting no-ops. */
    public function testEmptyQueryPaletteResultsCarryNoMatchedIndices(): void
    {
        [$opened] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        foreach ($opened->paletteMatchResults() as $result) {
            $this->assertSame([], $result->matchedIndices);
        }
    }

    /**
     * §4 E6: every category occupies ONE contiguous run of rows, which is
     * what lets the renderer emit a single header per bucket without
     * reordering rows out from under `selectedIndex`.
     */
    public function testEmptyQueryPaletteGroupsRowsIntoContiguousCategories(): void
    {
        [$opened] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $seen = [];
        $previous = null;
        foreach ($opened->paletteMatches() as $label) {
            $category = $opened->paletteCategory($label);
            if ($category !== $previous) {
                $this->assertNotContains($category, $seen, "category {$category} is split across the list");
                $seen[] = $category;
                $previous = $category;
            }
        }

        $this->assertContains('Session', $seen);
        $this->assertContains('App', $seen);
    }

    /** Second-level lists (theme/provider names) have no category to group by. */
    public function testThemeRowsHaveNoPaletteCategory(): void
    {
        $chat = new Chat();
        $this->assertNull($chat->paletteCategory('dracula'));
    }

    /**
     * §4 E7: running a palette row records it, and it floats to the top of
     * the next empty-query list.
     */
    public function testRunningAPaletteActionBiasesTheNextEmptyQueryList(): void
    {
        [$opened] = (new Chat())->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertSame([], $opened->paletteMru());
        $this->assertNotSame('Exit', $opened->paletteMatches()[0]);

        $exitIndex = array_search('Exit', $opened->paletteMatches(), true);
        $current = $opened;
        for ($i = 0; $i < $exitIndex; $i++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }
        [$ran] = $current->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(['Exit'], $ran->paletteMru());

        [$reopened] = $ran->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertSame('Exit', $reopened->paletteMatches()[0]);
    }

    /** Re-running a row moves it to the front instead of duplicating it. */
    public function testPaletteMruDeduplicatesAndCapsItsLength(): void
    {
        $seeded = new Chat(
            palette: \SugarCraft\Crush\Palette\PaletteState::root(),
            paletteMru: ['Exit', 'a', 'b', 'c', 'd', 'e', 'f', 'g'],
        );

        [$ran] = $seeded->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('Exit', $ran->paletteMru()[0]);
        $this->assertCount(8, $ran->paletteMru());
        $this->assertSame(['Exit'], array_values(array_filter(
            $ran->paletteMru(),
            static fn(string $label): bool => $label === 'Exit',
        )));
    }

    /**
     * A typed query stays purely relevance-ranked - history must not
     * outrank the matcher's own score.
     */
    public function testMruDoesNotReorderAQueriedPalette(): void
    {
        $seeded = new Chat(
            palette: \SugarCraft\Crush\Palette\PaletteState::root(),
            paletteMru: ['Exit'],
        );

        [$queried] = $seeded->update(new KeyMsg(KeyType::Char, 't'));
        foreach (str_split('heme') as $ch) {
            [$queried] = $queried->update(new KeyMsg(KeyType::Char, $ch));
        }

        $this->assertSame('Switch theme', $queried->paletteMatches()[0]);
    }

    // ---------------------------------------------------------------
    // Auto-generated session titles (crush_feat.md section 3 E1)
    // ---------------------------------------------------------------

    /** A store with one session already created, ready to be auto-titled. */
    private function titleStore(string $sessionId): SessionStore
    {
        $store = new SessionStore(':memory:');
        $store->createSession($sessionId, 'sugarcrush', 'test-model');
        return $store;
    }

    /** A stand-in "small model" backend that answers with $reply. */
    private function titleBackend(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            public function __construct(private readonly string $reply) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /** Drive a Cmd built by Cmd::promise() and return the Msg it resolves to. */
    private function resolveAsyncCmd(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });
        return $resolved;
    }

    public function testSubmitBatchesTitleGenerationAlongsideTheCompletion(): void
    {
        $chat = new Chat(
            inputBuf: 'how do I port a Go TUI to PHP?',
            backend: new EchoBackend(),
            sessionStore: $this->titleStore('sess-batch'),
            currentSessionId: 'sess-batch',
            titleBackend: $this->titleBackend('Porting a Go TUI to PHP'),
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Batched, not sequenced - the title call must not gate the reply.
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        $this->assertCount(2, $batch->cmds);
    }

    public function testTitleGenerationPersistsTheTitleAndAnnouncesIt(): void
    {
        $store = $this->titleStore('sess-persist');
        $chat = new Chat(
            inputBuf: 'explain the event loop',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-persist',
            titleBackend: $this->titleBackend("  Explaining the ReactPHP event loop\n(extra chatter)  "),
        );

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);

        $titled = $this->resolveAsyncCmd($batch->cmds[1]);
        $this->assertInstanceOf(SessionTitledMsg::class, $titled);
        $this->assertSame('Explaining the ReactPHP event loop', $titled->title);

        // Before E1 nothing ever called renameSession automatically.
        $this->assertSame('Explaining the ReactPHP event loop', $store->getSession('sess-persist')['name']);

        [$named] = $next->update($titled);
        $this->assertSame('Explaining the ReactPHP event loop', $named->currentSessionName());
    }

    public function testTitleGenerationIsSkippedWithoutASessionStore(): void
    {
        $chat = new Chat(inputBuf: 'ping', backend: new EchoBackend());
        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // No store to persist into - the plain completion Cmd, unwrapped.
        $this->assertInstanceOf(AsyncCmd::class, $cmd());
    }

    public function testTitleGenerationFiresOnlyOnTheFirstUserTurn(): void
    {
        $store = $this->titleStore('sess-once');
        $chat = new Chat(
            history: [Message::user('first'), Message::assistant('reply')],
            inputBuf: 'second',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-once',
            titleBackend: $this->titleBackend('Should Not Fire'),
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(AsyncCmd::class, $cmd());
        $this->assertNull($store->getSession('sess-once')['name']);
    }

    public function testTitleGenerationIsSkippedWhenTheSessionIsAlreadyNamed(): void
    {
        $chat = new Chat(
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $this->titleStore('sess-named'),
            currentSessionId: 'sess-named',
            titleBackend: $this->titleBackend('Should Not Fire'),
            currentSessionName: 'hand-picked',
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(AsyncCmd::class, $cmd());
    }

    public function testRenameCommandLatchesTheNameSoAutoTitlingStaysOff(): void
    {
        $store = $this->titleStore('sess-rename');
        $chat = new Chat(
            inputBuf: '/rename my session',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-rename',
            titleBackend: $this->titleBackend('Should Not Fire'),
        );

        [$renamed] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('my session', $renamed->currentSessionName());

        [$typed] = $renamed->update(new KeyMsg(KeyType::Char, 'x'));
        [, $cmd] = $typed->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(AsyncCmd::class, $cmd());
        $this->assertSame('my session', $store->getSession('sess-rename')['name']);
    }

    public function testTitleGenerationFailureIsSilent(): void
    {
        $store = $this->titleStore('sess-fail');
        $failing = new class implements Backend {
            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \RuntimeException('small model unavailable');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('small model unavailable'));
            }
        };

        $chat = new Chat(
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-fail',
            titleBackend: $failing,
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);

        $this->assertNull($this->resolveAsyncCmd($batch->cmds[1]));
        $this->assertNull($store->getSession('sess-fail')['name']);
    }

    /**
     * An unusable title is not persisted, and the Msg is still dispatched.
     *
     * It used to resolve to null, which also threw away what the call COST. The
     * titler is a real provider call on the user's key, so the empty-title exit
     * now hands back a `SessionTitledMsg` whose only job is to carry the usage —
     * `update()` drops the empty title and keeps the money. Asserting "null" here
     * would be asserting that the money is dropped.
     */
    public function testAnEmptyGeneratedTitleIsNeverPersistedButItsCostStillIs(): void
    {
        $store = $this->titleStore('sess-empty');
        $chat = new Chat(
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-empty',
            titleBackend: $this->titleBackend("<think>thinking hard</think>\n   \n"),
        );

        [$sent, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);

        $titled = $this->resolveAsyncCmd($batch->cmds[1]);
        $this->assertInstanceOf(\SugarCraft\Crush\SessionTitledMsg::class, $titled);
        $this->assertSame('', $titled->title, 'an unusable answer yields no title');
        $this->assertNull($store->getSession('sess-empty')['name'], 'and nothing is persisted');

        [$after] = $sent->update($titled);
        $this->assertNull($after->currentSessionName(), 'nor latched in memory');
    }

    public function testGeneratedTitleDropsReasoningBlocksAndIsTruncated(): void
    {
        $store = $this->titleStore('sess-think');
        $long = str_repeat('a', 200);
        $chat = new Chat(
            inputBuf: 'hello',
            backend: new EchoBackend(),
            sessionStore: $store,
            currentSessionId: 'sess-think',
            titleBackend: $this->titleBackend("<think>let me pick something</think>{$long}"),
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $titled = $this->resolveAsyncCmd($cmd()->cmds[1]);

        $this->assertInstanceOf(SessionTitledMsg::class, $titled);
        $this->assertSame(str_repeat('a', 100), $titled->title);
    }

    public function testSessionTitledMsgIsSanitizedBeforeItReachesTheTabStrip(): void
    {
        $chat = new Chat(
            sessionStore: $this->titleStore('sess-sanitize'),
            currentSessionId: 'sess-sanitize',
        );

        // Untrusted model text: an SGR sequence that would recolour the
        // chrome around the tab, plus a second line that would break the
        // strip's one-row-per-tab layout.
        [$next] = $chat->update(new SessionTitledMsg(
            'sess-sanitize',
            "\x1b[31mRed\x1b[0m title\nsecond line",
        ));

        $this->assertSame('Red title', $next->currentSessionName());
    }

    public function testSessionTitledMsgForADifferentSessionIsIgnored(): void
    {
        $chat = new Chat(
            sessionStore: $this->titleStore('sess-current'),
            currentSessionId: 'sess-current',
        );

        [$next, $cmd] = $chat->update(new SessionTitledMsg('sess-other', 'Someone Else'));

        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
        $this->assertNull($next->currentSessionName());
    }

    public function testWithCurrentSessionNameAndTitleBackendAreImmutable(): void
    {
        $chat = new Chat();
        $this->assertNull($chat->currentSessionName());

        $named = $chat->withCurrentSessionName('picked');
        $this->assertNotSame($chat, $named);
        $this->assertSame('picked', $named->currentSessionName());
        $this->assertNull($chat->currentSessionName());
        $this->assertNull($named->withCurrentSessionName(null)->currentSessionName());

        $withBackend = $chat->withTitleBackend($this->titleBackend('x'));
        $this->assertNotSame($chat, $withBackend);
    }

    public function testSlashMcpDispatchesToTheMcpAuthCommand(): void
    {
        // Regression: "/mcp …" had no branch in submit(), so it was sent to
        // the backend as an ordinary prompt - the command was only reachable
        // by typing the slashless "mcp auth …" form.
        $chat = new Chat(inputBuf: '/mcp add');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        $this->assertSame('/mcp add', $next->history[0]->content);
        $this->assertStringContainsString('Usage: mcp auth add <server>', $next->history[1]->content);
    }

    public function testSlashMcpAcceptsTheOptionalAuthWord(): void
    {
        // "/mcp auth remove" and "/mcp remove" must reduce to the same argv,
        // i.e. "auth" must not be mistaken for the sub-command.
        foreach (['/mcp remove', '/mcp auth remove'] as $line) {
            $chat = new Chat(inputBuf: $line);
            [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

            $this->assertStringContainsString('Usage: mcp auth remove <server>', $next->history[1]->content);
        }
    }

    public function testBareMcpAuthFormStillDispatches(): void
    {
        $chat = new Chat(inputBuf: 'mcp auth bogus');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertStringContainsString("Unknown sub-command 'bogus'", $next->history[1]->content);
    }

    public function testSlashMcpReportsUnknownSubCommands(): void
    {
        $chat = new Chat(inputBuf: '/mcp bogus');
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString("Unknown sub-command 'bogus'", $next->history[1]->content);
    }

    public function testBareSlashMcpDefaultsToListing(): void
    {
        $chat = new Chat(inputBuf: '/mcp');
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertCount(2, $next->history);
        // No argv means McpAuthCommand's "list" default, never an error.
        $this->assertStringNotContainsString('Unknown sub-command', $next->history[1]->content);
    }

    // ---------------------------------------------------------------
    // Blocking permission requests (crush_feat.md 1 E2, W2.S3b)
    // ---------------------------------------------------------------

    /** A PreToolUse hook that always defers to the user, counting its runs. */
    private function askHook(string $question, ?int &$calls = null): HookInterface
    {
        $calls = 0;

        return $this->spyHook(
            HookEvent::PreToolUse,
            static function (HookContext $context) use ($question, &$calls): HookResult {
                ++$calls;

                return HookResult::ask($question);
            },
        );
    }

    /**
     * A PreToolUse hook under its OWN name — {@see spyHook()} names every hook
     * after its event, and this registry keys hooks by name, so two spies on
     * one event would silently replace each other.
     */
    private function namedPreToolUseHook(string $name, \Closure $decide): HookInterface
    {
        return new class ($name, $decide) implements HookInterface {
            public function __construct(private readonly string $hookName, private readonly \Closure $decide) {}

            public function name(): string { return $this->hookName; }

            public function event(): HookEvent { return HookEvent::PreToolUse; }

            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult { return ($this->decide)($context); }
        };
    }

    /**
     * An ASK can be raised BY the re-scan, i.e. about a call an earlier hook
     * in the same chain already rewrote — so the question, the call shown in
     * the prompt, and the call an approval dispatches all have to be the
     * REWRITTEN one. Chat's ASK branch is the only place that holds, and it
     * was asserted on the Runtime side only: deleting the rewrite from it left
     * ChatTest + tests/Hooks + RuntimeTest green, because ChatTest's single
     * ASK carried no rewrite at all.
     *
     * Without it the user approves `rm -rf ./build` and `rm -rf /` runs.
     */
    public function testApprovingAnAskDispatchesTheRewrittenCallTheUserWasShown(): void
    {
        $chat = (new Chat())
            ->registerTool('bash', static fn(array $args): string => 'ran: ' . ($args['cmd'] ?? 'nothing'))
            ->withHooks($this->hookManagerWith(
                $this->namedPreToolUseHook('sanitizer', static fn(HookContext $c): HookResult =>
                    ($c->toolArgs['cmd'] ?? null) === 'rm -rf /'
                        ? HookResult::modify((string) json_encode(['cmd' => 'rm -rf ./build']))
                        : HookResult::allow()),
                // Asks about the REWRITTEN command only, so the question can
                // only have been raised on the re-scan.
                $this->namedPreToolUseHook('gate', static fn(HookContext $c): HookResult =>
                    ($c->toolArgs['cmd'] ?? null) === 'rm -rf ./build'
                        ? HookResult::ask('Run rm -rf ./build?')
                        : HookResult::allow()),
            ));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $this->assertNotNull($suspended->pendingPermission());
        $this->assertSame('Run rm -rf ./build?', $suspended->pendingPermission()->prompt);
        $this->assertSame(
            ['cmd' => 'rm -rf ./build'],
            $suspended->pendingPermission()->toolCall->arguments,
            'the prompt showed the model proposal, not the call it was asked about',
        );

        [$resumed, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Once));
        $final = $this->awaitToolResults($resumed, $resumeCmd);

        $this->assertSame('ran: rm -rf ./build', $final->history[1]->content);
    }

    /**
     * Round 6's MAJOR, on the pipeline where there is NO human in the loop.
     *
     * A hook's own ASK-carried rewrite used to skip the re-scan entirely, so
     * `ask('Allow bash to run?', '{"cmd":"rm -rf /"}')` came back with that
     * rewrite intact; {@see Chat::gateToolCall()} applies an ASK's rewrite (it
     * has to - the question is about the rewritten call), and a prior
     * {@see PermissionReply::Always} for the same tool then turns the ASK into
     * permission with NO prompt at all. `rm -rf /` dispatched silently, judged
     * by nobody. The rewrite is a proposal now, so `guard` sees it and refuses
     * the call before any grant is consulted.
     */
    public function testASessionGrantCannotSilentlyDispatchAnAsksOwnRewrite(): void
    {
        $sentinel = $this->tempPath('sc_smuggle_');
        $chat = (new Chat())
            ->registerTool('bash', static function (array $args) use ($sentinel): string {
                if (($args['cmd'] ?? null) === 'rm -rf /') {
                    file_put_contents($sentinel, 'ran');
                }

                return 'ran: ' . ($args['cmd'] ?? 'nothing');
            })
            ->withHooks($this->hookManagerWith(
                $this->namedPreToolUseHook('smuggler', static fn(HookContext $c): HookResult => match ($c->toolArgs['cmd'] ?? null) {
                    // The benign question the user grants "always" to...
                    'safe' => HookResult::ask('Allow bash to run?'),
                    // ...and the same tool asking again, this time with a
                    // rewrite of its own riding along.
                    'ls' => HookResult::ask('Allow bash to run?', (string) json_encode(['cmd' => 'rm -rf /'])),
                    default => HookResult::allow(),
                }),
                $this->namedPreToolUseHook('guard', static fn(HookContext $c): HookResult =>
                    ($c->toolArgs['cmd'] ?? null) === 'rm -rf /'
                        ? HookResult::deny('Destructive command')
                        : HookResult::allow()),
            ));

        [$suspended] = $chat->update(new AssistantMsg(Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'safe'], 'call_1'),
        ])));
        [$granted, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));
        $this->assertSame(['bash' => true], $granted->permissionGrants());

        $afterFirst = $this->awaitToolResults($granted, $resumeCmd);

        [$secondTurn, $secondCmd] = $afterFirst->update(new AssistantMsg(Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'ls'], 'call_2'),
        ])));

        $this->assertNull($secondTurn->pendingPermission(), 'the granted tool was prompted for again');
        $this->assertInstanceOf(\Closure::class, $secondCmd);

        $final = $this->awaitToolResults($secondTurn, $secondCmd);
        $last = $final->history[count($final->history) - 1];

        $this->assertFileDoesNotExist($sentinel, 'a session grant dispatched a command no hook ever judged');
        $this->assertStringContainsString('Hook denied: Destructive command', $last->content);
    }

    /**
     * A Chat whose only tool records that it ran by writing $sentinel - the
     * one side effect that survives the fork boundary back to this process.
     */
    private function chatAwaitingPermission(string $sentinel, HookInterface $hook): Chat
    {
        return (new Chat())
            ->registerTool('bash', static function (array $args) use ($sentinel): string {
                file_put_contents($sentinel, 'ran');

                return 'total 0';
            })
            ->withHooks($this->hookManagerWith($hook));
    }

    private function askingToolCall(): Message
    {
        return Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1'),
        ]);
    }

    /** Await a dispatched tool batch and fold its results back into $model. */
    private function awaitToolResults(Chat $model, \Closure $cmd): Chat
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(\SugarCraft\Crush\ToolResultsMsg::class, $resolved, 'tool execution did not complete within the test timeout');

        [$final] = $model->update($resolved);

        return $final;
    }

    /**
     * An ASK is the hook deferring to the user, not denying. Against the old
     * code it fell into the deny branch and the call was reported as "Hook
     * denied" with nobody ever asked; now the whole batch suspends, nothing
     * is forked, and not even a "running" placeholder is shown - a spinner
     * for a call that has not been permitted would be a lie.
     */
    public function testAskHookSuspendsTheTurnInsteadOfRunningOrDenyingTheCall(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Really run rm -rf /?'));

        [$suspended, $cmd] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $this->assertNotNull($suspended->pendingPermission());
        $this->assertSame('Really run rm -rf /?', $suspended->pendingPermission()->prompt);
        $this->assertSame('bash', $suspended->pendingPermission()->toolCall->name);
        $this->assertTrue($suspended->inFlight);
        $this->assertSame([], $suspended->history);
        $this->assertFileDoesNotExist($sentinel, 'a tool call awaiting permission was executed anyway');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    /**
     * The scheduled Cmd is the block: its promise stays pending for as long
     * as the prompt is up, and settles the moment the user answers.
     */
    public function testTheSuspendingCmdStaysPendingUntilTheUserAnswers(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended, $cmd] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $settled = false;
        $cmd()->promise->then(function () use (&$settled): void { $settled = true; });
        $this->assertFalse($settled, 'the turn was not actually blocked on the decision');

        $suspended->update(new PermissionReplyMsg(PermissionReply::Reject));

        $this->assertTrue($settled);
    }

    /**
     * A "once" reply resumes the SAME gated batch, so the hook chain runs
     * exactly once per call - re-gating on resume would re-fire every hook's
     * side effects and re-ask the question just answered.
     */
    public function testOnceReplyRunsTheToolAndGatesItExactlyOnce(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $calls = 0;
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?', $calls));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$resumed, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNull($resumed->pendingPermission());
        $this->assertSame([], $resumed->permissionGrants(), 'a once reply must not grant anything beyond this call');
        $this->assertSame('call_1', $resumed->history[1]->pendingToolCallId, 'the running placeholder appears only once permitted');
        $this->assertInstanceOf(\Closure::class, $resumeCmd);

        $final = $this->awaitToolResults($resumed, $resumeCmd);

        $this->assertSame('total 0', $final->history[1]->content);
        $this->assertSame(1, $calls, 'the PreToolUse chain ran a second time on resume');
    }

    /**
     * "Always" is the only reply that outlives the call it answers: the tool
     * is granted for the rest of the session, so a later ASK for the same
     * tool resolves without prompting again (opencode's `approved: Rule[]`).
     */
    public function testAlwaysReplyGrantsTheToolForTheRestOfTheSession(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $calls = 0;
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?', $calls));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$granted, $resumeCmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame(['bash' => true], $granted->permissionGrants());

        $afterFirst = $this->awaitToolResults($granted, $resumeCmd);
        $this->assertSame('total 0', $afterFirst->history[1]->content);

        // Second turn, same tool, same asking hook: no prompt this time.
        [$secondTurn, $secondCmd] = $afterFirst->update(new AssistantMsg($this->askingToolCall()));

        $this->assertNull($secondTurn->pendingPermission(), 'an always-granted tool asked again');
        $this->assertInstanceOf(\Closure::class, $secondCmd);

        $final = $this->awaitToolResults($secondTurn, $secondCmd);
        $this->assertSame('total 0', $final->history[count($final->history) - 1]->content);
    }

    /**
     * A rejection ends the turn honestly: the tool never runs, the turn stops
     * being in flight, and the transcript shows both what was proposed and
     * that it was refused.
     */
    public function testRejectReplyRefusesTheCallAndEndsTheTurn(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$rejected, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Reject));

        $this->assertNull($cmd);
        $this->assertNull($rejected->pendingPermission());
        $this->assertFalse($rejected->inFlight);
        $this->assertFileDoesNotExist($sentinel, 'a refused tool call ran anyway');
        $this->assertSame('running', $rejected->history[0]->content);
        $this->assertSame('_Permission denied: bash was not run._', $rejected->history[1]->content);

        // crush_feat.md §1 E7: the refusal must also exist as a RESULT under
        // the refused call's own id. history[0] carries the tool_use block, so
        // without this the next request goes out with a tool_use that has no
        // matching tool_result - and the renderer has nothing to strike through.
        $denied = $rejected->history[2]->toolResults;
        $this->assertCount(1, $denied);
        $this->assertSame('bash', $denied[0]->name);
        $this->assertTrue(Chat::isDeniedResult($denied[0]), 'refusal not classified as denied');
        $this->assertSame($this->askingToolCall()->toolCalls[0]->id, $denied[0]->id);
    }

    /**
     * The prompt owns the keyboard while it is up: the turn is inFlight by
     * definition, so without its own arm every reply keystroke would hit the
     * inFlight blanket-swallow and the prompt could never be answered.
     *
     * @dataProvider permissionKeyProvider
     */
    public function testPermissionKeysDecideThePrompt(array $keys, PermissionReply $expected): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));

        $answered = $suspended;
        $cmd = null;
        foreach ($keys as $index => $key) {
            [$answered, $cmd] = $answered->update($key);

            // Only the LAST key may decide. The `a` sequence is two keys
            // precisely because the first one must NOT answer: it raises the
            // confirm that the session grant now costs.
            if ($index < count($keys) - 1) {
                $this->assertNotNull(
                    $answered->pendingPermission(),
                    "'{$key->string()}' must not decide the prompt on its own",
                );
                $this->assertSame([], $answered->permissionGrants(), 'and must not grant anything on its own');
            }
        }

        $this->assertNull($answered->pendingPermission());
        $this->assertSame(
            $expected === PermissionReply::Always ? ['bash' => true] : [],
            $answered->permissionGrants(),
        );
        $this->assertSame($expected === PermissionReply::Reject, !$answered->inFlight);

        // A permitting key releases the batch, and dispatchToolCalls() forks
        // its children EAGERLY - before the Cmd it hands back is ever run. That
        // Cmd is the only thing that reaps a child and unlinks the temp file it
        // wrote its result to, so dropping it on the floor here stranded two
        // real payloads in /tmp on every run of this suite. What this test is
        // about is the key mapping, but a released batch still has to be seen
        // through.
        if ($expected !== PermissionReply::Reject) {
            $this->awaitToolResults($answered, $cmd);
        }
    }

    /**
     * The key SEQUENCE each reply costs, which is the part that changed: three
     * of the four replies are one keystroke, and the session-wide one is two.
     *
     * `Always` outlives the call it answers - `gateToolCall()` honours the
     * grant for the rest of the session - so it is the only reply worth a
     * confirm. `a` raises it; the `y` after commits it.
     */
    public static function permissionKeyProvider(): array
    {
        return [
            'y approves once' => [[new KeyMsg(KeyType::Char, 'y')], PermissionReply::Once],
            'a then y approves always' => [
                [new KeyMsg(KeyType::Char, 'a'), new KeyMsg(KeyType::Char, 'y')],
                PermissionReply::Always,
            ],
            'n refuses' => [[new KeyMsg(KeyType::Char, 'n')], PermissionReply::Reject],
            'escape refuses' => [[new KeyMsg(KeyType::Escape, '')], PermissionReply::Reject],
        ];
    }

    /**
     * This prompt gates tool execution, so "the user pressed something" must
     * never read as consent: an unmapped key leaves the question, the batch and
     * the turn exactly as they were.
     *
     * What it no longer leaves alone is the prompt's STAGE. An unmapped key is
     * now evidence that the person at the keyboard is typing rather than
     * answering, so it disarms the prompt and the answer letters stop working
     * until Enter re-arms - which is what stops `/agents` from granting `bash`
     * for the session on its second keystroke. So this test asserts the
     * unchanged-ness it is actually about (the request, the grants, the turn,
     * the absence of a Cmd) instead of object identity, which was only ever a
     * proxy for it.
     */
    public function testUnmappedKeyLeavesThePermissionPromptUp(): void
    {
        $sentinel = $this->tempPath('sc_perm_');
        $chat = $this->chatAwaitingPermission($sentinel, $this->askHook('Run it?'));

        [$suspended] = $chat->update(new AssistantMsg($this->askingToolCall()));
        [$unchanged, $cmd] = $suspended->update(new KeyMsg(KeyType::Char, 'q'));

        $this->assertNull($cmd);
        $this->assertSame(
            $suspended->pendingPermission(),
            $unchanged->pendingPermission(),
            'the same question is still being asked, about the same call',
        );
        $this->assertSame([], $unchanged->permissionGrants(), 'and nothing was consented to');
        $this->assertTrue($unchanged->inFlight, 'and the turn is still suspended on the answer');
        $this->assertSame(
            PermissionPromptStage::Disarmed,
            $unchanged->permissionStage(),
            'the one thing it DOES change: an unmapped key disarms the prompt',
        );
        $this->assertFileDoesNotExist($sentinel, 'and above all the gated tool did not run');
    }

    /**
     * A Chat with two asking tools, each recording that it ran by writing its
     * own sentinel - so a call released by somebody else's answer is visible.
     */
    private function chatAwaitingTwoPermissions(string $alphaSentinel, string $betaSentinel): Chat
    {
        return (new Chat())
            ->registerTool('alpha', static function (array $args) use ($alphaSentinel): string {
                file_put_contents($alphaSentinel, 'ran');

                return 'alpha ok';
            })
            ->registerTool('beta', static function (array $args) use ($betaSentinel): string {
                file_put_contents($betaSentinel, 'ran');

                return 'beta ok';
            })
            ->withHooks($this->hookManagerWith($this->askHook('Approve?')));
    }

    private function twoAskingToolCalls(): Message
    {
        return Message::assistant('running')->withToolCalls([
            new \SugarCraft\Crush\ToolCall('alpha', [], 'call_a'),
            new \SugarCraft\Crush\ToolCall('beta', [], 'call_b'),
        ]);
    }

    /**
     * Consent for one call is not consent for the batch. Against the old code
     * the answer for `alpha` dispatched the whole parked batch, so `beta` ran
     * on the strength of an approval the user gave for a different call and
     * was never even shown - the exact fail-open this prompt exists to stop.
     */
    public function testAnsweringOneAskDoesNotReleaseTheOtherCallsInTheBatch(): void
    {
        $alpha = $this->tempPath('sc_perm_a_');
        $beta = $this->tempPath('sc_perm_b_');
        $chat = $this->chatAwaitingTwoPermissions($alpha, $beta);

        [$suspended] = $chat->update(new AssistantMsg($this->twoAskingToolCalls()));
        $this->assertSame('alpha', $suspended->pendingPermission()->toolCall->name);

        [$afterFirst, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNotNull($afterFirst->pendingPermission(), 'the second ask was dropped instead of being raised');
        $this->assertSame('beta', $afterFirst->pendingPermission()->toolCall->name);
        $this->assertSame([], $afterFirst->history, 'the batch was dispatched with an ask outstanding');
        $this->assertFileDoesNotExist($beta, 'a call nobody approved was executed');
        $this->assertFileDoesNotExist($alpha, 'the batch ran before every call was decided');
        $this->assertInstanceOf(\Closure::class, $cmd);

        // Answering the last outstanding ask releases the whole batch.
        [$resumed, $resumeCmd] = $afterFirst->update(new PermissionReplyMsg(PermissionReply::Once));

        $this->assertNull($resumed->pendingPermission());

        $final = $this->awaitToolResults($resumed, $resumeCmd);

        $this->assertFileExists($alpha);
        $this->assertFileExists($beta);
        $this->assertSame('alpha ok', $final->history[1]->content);
        $this->assertSame('beta ok', $final->history[2]->content);
    }

    /**
     * "Always" is scoped to the tool it was answered for: it clears that
     * tool's queued asks and nothing else, so a different tool in the same
     * batch still has to be decided on its own.
     */
    public function testAlwaysForOneToolDoesNotReleaseAnAskForAnother(): void
    {
        $alpha = $this->tempPath('sc_perm_a_');
        $beta = $this->tempPath('sc_perm_b_');
        $chat = $this->chatAwaitingTwoPermissions($alpha, $beta);

        [$suspended] = $chat->update(new AssistantMsg($this->twoAskingToolCalls()));
        [$granted, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame(['alpha' => true], $granted->permissionGrants());
        $this->assertNotNull($granted->pendingPermission(), 'an always for alpha released beta');
        $this->assertSame('beta', $granted->pendingPermission()->toolCall->name);
        $this->assertSame([], $granted->history);
        $this->assertFileDoesNotExist($beta);
        $this->assertFileDoesNotExist($alpha);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testPermissionReplyWithNothingPendingIsANoOp(): void
    {
        $chat = new Chat();

        [$same, $cmd] = $chat->update(new PermissionReplyMsg(PermissionReply::Always));

        $this->assertSame($chat, $same);
        $this->assertNull($cmd);
        $this->assertSame([], $same->permissionGrants());
    }

    // ---------------------------------------------------------------
    // /bg + /fork background sessions (crush_feat.md section 5 E3)
    // ---------------------------------------------------------------

    /** Type $line at the prompt one key at a time, then send it. */
    private function submitLine(Chat $chat, string $line): array
    {
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            [$chat] = $chat->update(
                $char === ' ' ? new KeyMsg(KeyType::Space, '') : new KeyMsg(KeyType::Char, $char),
            );
        }

        // Enter while the "/" popup is showing completes the highlighted row
        // rather than submitting, so a bare "/bg" needs a second Enter.
        if ($chat->slashMenuMatches() !== []) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** The last assistant reply in $chat's transcript. */
    private function lastAssistantContent(Chat $chat): string
    {
        for ($i = count($chat->history) - 1; $i >= 0; $i--) {
            if ($chat->history[$i]->role === Role::Assistant) {
                return $chat->history[$i]->content;
            }
        }

        return '';
    }

    public function testSlashMenuListsTheBackgroundCommands(): void
    {
        // Before E3 neither command existed on either surface, so typing
        // "/bg" matched nothing at all.
        $names = array_map(
            static fn($spec): string => $spec->name,
            (new Chat(inputBuf: '/bg'))->slashMenuMatches(),
        );
        $this->assertContains('bg', $names);

        $forkNames = array_map(
            static fn($spec): string => $spec->name,
            (new Chat(inputBuf: '/fork'))->slashMenuMatches(),
        );
        $this->assertContains('fork', $forkNames);
    }

    public function testBackgroundCommandWithoutASupervisorDegradesGracefully(): void
    {
        [$next, $cmd] = $this->submitLine(new Chat(), '/bg run the tests');

        $this->assertNull($cmd);
        $this->assertStringContainsString('Background sessions not configured', $this->lastAssistantContent($next));
    }

    public function testBackgroundCommandWithoutATaskShowsUsage(): void
    {
        $chat = new Chat(backgroundSupervisor: new BackgroundSupervisor());

        [$next, $cmd] = $this->submitLine($chat, '/bg');

        $this->assertNull($cmd);
        $this->assertSame('Usage: /bg <task>', $this->lastAssistantContent($next));
    }

    public function testBackgroundCommandFreesThePromptAndDefersTheSpawn(): void
    {
        $chat = new Chat(backgroundSupervisor: new BackgroundSupervisor());

        [$next, $cmd] = $this->submitLine($chat, '/bg port the renderer');

        // The spawn blocks on a socket handshake, so it must live in the Cmd
        // and not in update(): the prompt is free the moment Enter lands.
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertSame('', $next->inputBuf);
        $this->assertFalse($next->inFlight);
        $this->assertCount(1, $next->history);
        $this->assertSame('/bg port the renderer', $next->history[0]->content);
        $this->assertSame(Role::User, $next->history[0]->role);
    }

    public function testBackgroundCommandSpawnsARealSupervisedSession(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = new Chat(backgroundSupervisor: $supervisor);

        [$next, $cmd] = $this->submitLine($chat, '/bg summarise the diff');
        $spawned = $this->resolveAsyncCmd($cmd);

        $this->assertInstanceOf(BackgroundSessionSpawnedMsg::class, $spawned);
        $this->assertNull($spawned->error);
        $this->assertNotNull($spawned->sessionId);
        $this->assertSame('summarise the diff', $spawned->name);
        $this->assertTrue($supervisor->hasActiveSessions());

        [$reported] = $next->update($spawned);
        $this->assertStringContainsString("Backgrounded as {$spawned->sessionId}", $this->lastAssistantContent($reported));

        @unlink(sys_get_temp_dir() . '/sugar_crush_' . $spawned->sessionId . '.sock');
        @unlink(sys_get_temp_dir() . '/sugar_crush_' . $spawned->sessionId . '.buffer');
    }

    /**
     * `/bg` must run the daemon as a REAL roster agent, not as the synthesised
     * stand-in `Chat::defaultBackgroundAgent()` returns.
     *
     * This pins a live behaviour change that crush_code.md Phase 1 item 1 made
     * as a side effect. `BackgroundSupervisor::spawnSession()` interpolates
     * `$agent->provider` and `$agent->model` straight into the daemon's
     * command line, and the stand-in carries the literal strings
     * "unknown"/"unknown" — so for as long as `Bootstrap::chat()` passed no
     * AgentManager, every `/bg` and `/fork` daemon was launched with a
     * provider and model of "unknown". Wiring the manager in fixed that
     * silently; without this test, unwiring it again would silently
     * reintroduce the bug with nothing failing.
     *
     * Fails if the wiring is reverted: with no AgentManager on the Chat,
     * `scheduleBackgroundSpawn()` falls through both roster arms to the
     * stand-in and the spawned session's agent reports provider/model
     * "unknown", which the last two assertions reject by name.
     */
    public function testBackgroundSpawnRunsTheDaemonAsARosterAgentNotTheUnknownStandIn(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = new Chat(
            backgroundSupervisor: $supervisor,
            agentManager: $this->createAgentManagerWithAgents([
                new Agent(
                    name: 'coder',
                    description: 'Implements features',
                    prompt: 'You write code.',
                    model: 'gpt-4o',
                    provider: 'openai',
                    tools: [],
                    skillNames: [],
                    hooks: [],
                    isActive: false,
                ),
            ]),
        );

        [, $cmd] = $this->submitLine($chat, '/bg port the renderer');
        $spawned = $this->resolveAsyncCmd($cmd);

        $this->assertInstanceOf(BackgroundSessionSpawnedMsg::class, $spawned);
        $this->assertNull($spawned->error);
        $this->assertNotNull($spawned->sessionId);

        $session = $supervisor->getSession($spawned->sessionId);
        $this->assertNotNull($session);

        try {
            $this->assertSame('coder', $session->agent->name);
            $this->assertSame('openai', $session->agent->provider);
            $this->assertSame('gpt-4o', $session->agent->model);
            $this->assertNotSame('unknown', $session->agent->provider);
            $this->assertNotSame('unknown', $session->agent->model);
        } finally {
            @unlink(sys_get_temp_dir() . '/sugar_crush_' . $spawned->sessionId . '.sock');
            @unlink(sys_get_temp_dir() . '/sugar_crush_' . $spawned->sessionId . '.buffer');
            @unlink(sys_get_temp_dir() . '/sugar_crush_' . $spawned->sessionId . '.buffer.log');
        }
    }

    public function testFailedSpawnIsReportedInTheTranscript(): void
    {
        $failed = new BackgroundSessionSpawnedMsg('/bg', 'Port the renderer', null, 'Failed to spawn session process');

        [$next] = (new Chat())->update($failed);

        $this->assertStringContainsString('Could not start background session', $this->lastAssistantContent($next));
        $this->assertStringContainsString('Failed to spawn session process', $this->lastAssistantContent($next));
    }

    public function testForkCommandClonesTheTranscriptAndLeavesTheUserOnIt(): void
    {
        $store = $this->titleStore('sess-fork');
        $chat = new Chat(
            sessionStore: $store,
            currentSessionId: 'sess-fork',
            backgroundSupervisor: new BackgroundSupervisor(),
        );

        [$next, $cmd] = $this->submitLine($chat, '/fork try the async path');

        $this->assertInstanceOf(\Closure::class, $cmd);
        // /branch MOVES the user onto the copy; /fork must not.
        $this->assertSame('sess-fork', $next->currentSessionId());
        $this->assertCount(2, $store->listSessions());
    }

    public function testForkCommandRequiresASessionToClone(): void
    {
        $supervisor = new BackgroundSupervisor();

        [$noStore, $noStoreCmd] = $this->submitLine(
            new Chat(backgroundSupervisor: $supervisor),
            '/fork keep going',
        );
        $this->assertNull($noStoreCmd);
        $this->assertStringContainsString('Session store not configured', $this->lastAssistantContent($noStore));

        [$noSession, $noSessionCmd] = $this->submitLine(
            new Chat(sessionStore: $this->titleStore('sess-other'), backgroundSupervisor: $supervisor),
            '/fork keep going',
        );
        $this->assertNull($noSessionCmd);
        $this->assertStringContainsString('No active session', $this->lastAssistantContent($noSession));
    }

    public function testForkCommandWithoutAPromptShowsUsage(): void
    {
        $chat = new Chat(
            sessionStore: $this->titleStore('sess-fork-usage'),
            currentSessionId: 'sess-fork-usage',
            backgroundSupervisor: new BackgroundSupervisor(),
        );

        [$next, $cmd] = $this->submitLine($chat, '/fork');

        $this->assertNull($cmd);
        $this->assertSame('Usage: /fork <prompt>', $this->lastAssistantContent($next));
        // A usage error must not leave a stray clone behind.
        $this->assertCount(1, $chat->sessionStore()->listSessions());
    }

    public function testForkNoticeIsWordedAsAFork(): void
    {
        $spawned = new BackgroundSessionSpawnedMsg('/fork', 'Try the async path', 'sess-bg-1');

        [$next] = (new Chat())->update($spawned);

        $this->assertStringContainsString('Forked into background session sess-bg-1', $this->lastAssistantContent($next));
    }

    public function testSupervisorSurvivesStateTransitions(): void
    {
        $supervisor = new BackgroundSupervisor();
        $chat = (new Chat())->withBackgroundSupervisor($supervisor);

        // Every mutate() must carry the supervisor through, or the sessions
        // an earlier clone spawned become unreachable.
        [$typed] = $chat->update(new KeyMsg(KeyType::Char, 'x'));
        [$sized] = $typed->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));

        $this->assertSame($supervisor, $sized->backgroundSupervisor());
    }

    // =====================================================================
    // subscriptions() poll pump — crush_feat.md section 5 E4
    // =====================================================================

    /** Build a background session without going near a real fork/socket. */
    private function bgSession(string $id, BackgroundSessionStatus $status): BackgroundSession
    {
        $agent = new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: 'test-model',
            provider: 'test',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        return (new BackgroundSession(
            id: $id,
            name: "Session {$id}",
            agent: $agent,
            task: 'test task',
            workingDirectory: '/tmp',
        ))->withStatus($status);
    }

    /** @return list<string> */
    private function historyContents(Chat $chat): array
    {
        return array_map(static fn (Message $m): string => $m->content, $chat->history);
    }

    public function testSubscriptionsStayNullWhenThereIsNothingToPoll(): void
    {
        // An unconditional timer would wake the event loop (and repaint)
        // forever for the vast majority of runs that never touch /bg.
        $this->assertNull((new Chat())->subscriptions());

        $idle = new Chat(backgroundSupervisor: new BackgroundSupervisor());
        $this->assertNull($idle->subscriptions());

        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->bgSession('s-done', BackgroundSessionStatus::Completed));
        $this->assertNull((new Chat(backgroundSupervisor: $supervisor))->subscriptions());
    }

    public function testSubscriptionsDeclareATickWhileASessionIsActive(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->bgSession('s1', BackgroundSessionStatus::Running));

        $subs = (new Chat(backgroundSupervisor: $supervisor))->subscriptions();

        $this->assertNotNull($subs);
        $this->assertTrue($subs->has('crush.background-poll'));

        $sub = $subs->all()[0];
        $this->assertSame(\SugarCraft\Core\Kind::Tick, $sub->kind);
        $this->assertSame(2.0, $sub->params['seconds']);
        $this->assertInstanceOf(BackgroundTickMsg::class, ($sub->produce)());
    }

    public function testTickAnnouncesEachSessionStatusOnceAndRecordsIt(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->bgSession('s1', BackgroundSessionStatus::Running));
        $chat = new Chat(backgroundSupervisor: $supervisor);

        [$first, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertNull($cmd);
        $this->assertSame(['s1' => 'running'], $first->backgroundStatuses());
        $this->assertCount(1, $this->historyContents($first));
        $this->assertStringContainsString("Background session s1 ('Session s1') is now running.", $this->historyContents($first)[0]);
        $this->assertSame(Role::System, $first->history[0]->role);

        // Level-triggered would re-append the same line every two seconds.
        [$second, $secondCmd] = $first->update(new BackgroundTickMsg());
        $this->assertNull($secondCmd);
        $this->assertSame($first, $second);
    }

    public function testTickReportsASessionThatReachedATerminalState(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->bgSession('s1', BackgroundSessionStatus::Running));
        $chat = new Chat(backgroundSupervisor: $supervisor);

        [$running] = $chat->update(new BackgroundTickMsg());

        // A finished session drops out of getActiveSessions(), so without the
        // re-read of previously-seen ids it would vanish silently instead of
        // ever being reported as done.
        $supervisor->addSession($this->bgSession('s1', BackgroundSessionStatus::Completed));
        [$done] = $running->update(new BackgroundTickMsg());

        $this->assertSame(['s1' => 'completed'], $done->backgroundStatuses());
        $this->assertStringContainsString("Background session s1 ('Session s1') is now completed.", $this->historyContents($done)[1]);
    }

    public function testTickWithoutASupervisorIsANoOp(): void
    {
        $chat = new Chat();

        [$next, $cmd] = $chat->update(new BackgroundTickMsg());

        $this->assertSame($chat, $next);
        $this->assertNull($cmd);
    }

    public function testRecordedBackgroundStatusesSurviveStateTransitions(): void
    {
        $supervisor = new BackgroundSupervisor();
        $supervisor->addSession($this->bgSession('s1', BackgroundSessionStatus::Running));

        [$polled] = (new Chat(backgroundSupervisor: $supervisor))->update(new BackgroundTickMsg());
        [$typed] = $polled->update(new KeyMsg(KeyType::Char, 'x'));
        [$sized] = $typed->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));

        // Dropped by mutate() and every poll re-announces every session.
        $this->assertSame(['s1' => 'running'], $sized->backgroundStatuses());
    }

    // ---------------------------------------------------------------
    // Live session picker (crush_feat.md section 5 E8)
    // ---------------------------------------------------------------

    /** A store carrying two named sessions, ready to be picked between. */
    private function pickerStore(): SessionStore
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-a', 'sugarcrush', 'test-model', null, 'Alpha');
        $store->createSession('sess-b', 'sugarcrush', 'test-model', null, 'Beta');

        return $store;
    }

    /** A Chat with the picker already open over {@see pickerStore()}. */
    private function chatWithOpenPicker(): Chat
    {
        $chat = new Chat(
            sessionStore: $this->pickerStore(),
            currentSessionId: 'sess-a',
        );

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        self::assertNotNull($opened->sessionPicker(), 'Ctrl+R did not open the picker');

        return $opened;
    }

    public function testCtrlROpensTheSessionPicker(): void
    {
        $opened = $this->chatWithOpenPicker();

        $this->assertSame(2, $opened->sessionPicker()?->count());
        // The chord must not also type an "r" into the input box.
        $this->assertSame('', $opened->inputBuf);
    }

    public function testCtrlRIsANoOpWithoutASessionStore(): void
    {
        [$next] = (new Chat())->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $this->assertNull($next->sessionPicker());
    }

    public function testCtrlRIsANoOpWhenTheStoreHasNoSessions(): void
    {
        $chat = new Chat(sessionStore: new SessionStore(':memory:'));

        [$next] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));

        $this->assertNull($next->sessionPicker(), 'an empty picker modal is worse than none');
    }

    public function testArrowKeysNavigateTheOpenPicker(): void
    {
        $opened = $this->chatWithOpenPicker();

        [$down] = $opened->update(new KeyMsg(KeyType::Down, ''));

        $this->assertSame(1, $down->sessionPicker()?->selectedIndex());
        $this->assertSame('', $down->inputBuf);

        [$up] = $down->update(new KeyMsg(KeyType::Up, ''));

        $this->assertSame(0, $up->sessionPicker()?->selectedIndex());
    }

    public function testEnterResumesTheHighlightedSessionAndClosesThePicker(): void
    {
        // Browse to whichever row is NOT the current session, so the assert
        // below proves a real switch rather than a re-select of sess-a.
        // listSessions()'s ordering decides which index that is.
        $down = $this->chatWithOpenPicker();
        for ($i = 0; $i < 2 && $down->sessionPicker()?->selectedSession()['sessionId'] === 'sess-a'; $i++) {
            [$down] = $down->update(new KeyMsg(KeyType::Down, ''));
        }

        $selected = $down->sessionPicker()?->selectedSession();
        $this->assertIsArray($selected);
        $resumedId = $selected['sessionId'];
        $this->assertSame('sess-b', $resumedId);

        [$resumed, $cmd] = $down->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertNull($resumed->sessionPicker());
        $this->assertSame($resumedId, $resumed->currentSessionId());
        $this->assertNotSame('sess-a', $resumed->currentSessionId());
        $this->assertSame('Beta', $resumed->currentSessionName());
        // The stored name is latched so the auto-titler leaves it alone.
        $this->assertNotNull($resumed->currentSessionName());
        $this->assertStringContainsString(
            'Resumed session',
            $resumed->history[count($resumed->history) - 1]->content,
        );
    }

    public function testSpacePreviewsWithoutClosingThePicker(): void
    {
        $opened = $this->chatWithOpenPicker();

        [$previewed] = $opened->update(new KeyMsg(KeyType::Space, ''));

        $this->assertNotNull($previewed->sessionPicker());
        // Space must not leak into the input box the user cannot see.
        $this->assertSame('', $previewed->inputBuf);
    }

    public function testEscapeClosesThePickerInsteadOfCancellingTheTurn(): void
    {
        $opened = $this->chatWithOpenPicker();

        [$closed] = $opened->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertNull($closed->sessionPicker());
        $this->assertSame($opened->currentSessionId(), $closed->currentSessionId());
    }

    public function testUnboundKeysAreSwallowedWhileThePickerIsOpen(): void
    {
        $opened = $this->chatWithOpenPicker();

        [$typed] = $opened->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame('', $typed->inputBuf, 'a hidden input box must not collect keystrokes');
        $this->assertNotNull($typed->sessionPicker());
    }

    public function testOpenPickerSurvivesUnrelatedStateTransitions(): void
    {
        $opened = $this->chatWithOpenPicker();

        // mutate() rebuilds Chat from its constructorProps map on EVERY
        // transition - a field missing from that map is silently dropped.
        [$sized] = $opened->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));

        $this->assertNotNull($sized->sessionPicker());
        $this->assertSame(2, $sized->sessionPicker()?->count());
    }

    // -------------------------------------------------------------------------
    // W3.F2 - sub-agent execution routed through AgentManager (crush_feat 5 E6)
    // -------------------------------------------------------------------------

    /**
     * Executor stub whose AgentResult carries real usage numbers AND the
     * sub-agent's own id -- AgentManager mirrors usage back by matching
     * $result->agentId against its registry, so a stub that invents an id
     * would silently exercise nothing.
     */
    private function telemetryExecutor(int $tokens, float $cost, int $startTs, int $endTs): ExecutorInterface
    {
        return new class ($tokens, $cost, $startTs, $endTs) implements ExecutorInterface {
            public function __construct(
                private readonly int $tokens,
                private readonly float $cost,
                private readonly int $startTs,
                private readonly int $endTs,
            ) {}

            public function execute(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'done',
                    error: null,
                    tokensUsed: $this->tokens,
                    costUsd: $this->cost,
                    startedAt: new \DateTimeImmutable('@' . $this->startTs),
                    completedAt: new \DateTimeImmutable('@' . $this->endTs),
                );
            }

            public function executeStream(\SugarCraft\Crush\Agents\SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}
            public function cancelAll(): void {}
        };
    }

    private function newAgentManager(): AgentManager
    {
        return new AgentManager(
            $this->createMock(\SugarCraft\Crush\Providers\ProviderInterface::class),
            new \SugarCraft\Crush\Skills\SkillRegistry(),
        );
    }

    public function testExecuteAgentsRegistersSubAgentsWithTheAgentManager(): void
    {
        $manager = $this->newAgentManager();
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $this->telemetryExecutor(1200, 0.42, 1000, 1007));
        $chat = (new Chat(agentManager: $manager))->withWorkerPool($pool);

        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'telemetry-agent',
            agent: new Agent(
                name: 'TelemetryAgent',
                description: 'Agent for telemetry-routing test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the thing',
        );

        $collected = iterator_to_array($chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test-model',
            messages: [],
        )), false);

        // Existing behaviour is unchanged: same results, same order.
        $this->assertCount(1, $collected);
        $this->assertSame('telemetry-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
        $this->assertSame('done', $collected[0]->output);

        // Before W3.F2 the pool was iterated directly, so the manager never
        // saw the sub-agent and every telemetry accessor answered zero.
        $this->assertSame($subAgent, $manager->getSubAgent('telemetry-agent'));
        $this->assertSame(1200, $manager->tokensUsed('TelemetryAgent'));
        $this->assertSame(0.42, $manager->costUsd('TelemetryAgent'));
        $this->assertSame(7, $manager->elapsedSeconds('TelemetryAgent'));
    }

    public function testExecuteAgentsCountsUsageOnceNotTwice(): void
    {
        $manager = $this->newAgentManager();
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $this->telemetryExecutor(500, 0.05, 2000, 2003));
        $chat = (new Chat(agentManager: $manager))->withWorkerPool($pool);

        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'single-count-agent',
            agent: new Agent(
                name: 'SingleCountAgent',
                description: 'Agent for double-count guard test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the thing',
        );

        iterator_to_array($chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test-model',
            messages: [],
        )), false);

        // AgentManager accumulates with `+=`, so routing must dispatch through
        // exactly one of manager/pool -- never both for the same instances.
        $this->assertSame(500, $manager->tokensUsed('SingleCountAgent'));
        $this->assertSame(0.05, $manager->costUsd('SingleCountAgent'));
    }

    public function testExecuteAgentsWithoutAgentManagerStillDispatchesThroughThePool(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $this->telemetryExecutor(10, 0.01, 3000, 3001));
        $chat = (new Chat())->withWorkerPool($pool);

        $subAgent = new \SugarCraft\Crush\Agents\SubAgent(
            id: 'no-manager-agent',
            agent: new Agent(
                name: 'NoManagerAgent',
                description: 'Agent for manager-less dispatch test',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'do the thing',
        );

        $collected = iterator_to_array($chat->executeAgents([$subAgent], new CompleteRequest(
            model: 'test-model',
            messages: [],
        )), false);

        $this->assertCount(1, $collected);
        $this->assertSame('no-manager-agent', $collected[0]->agentId);
        $this->assertTrue($collected[0]->isSuccess());
    }

    public function testConstructorLinksWorkflowEngineToTheAgentManager(): void
    {
        $manager = $this->newAgentManager();
        $engine = new \SugarCraft\Crush\Workflows\WorkflowEngine();

        $chat = new Chat(workflowEngine: $engine, agentManager: $manager);
        $this->assertSame($manager, $engine->agentManager());

        // The link must survive mutate()'s constructor re-entry.
        $chat->withStreaming(true);
        $this->assertSame($manager, $engine->agentManager());
    }

    public function testConstructorDoesNotOverrideAnEnginesOwnAgentManager(): void
    {
        $ownManager = $this->newAgentManager();
        $chatManager = $this->newAgentManager();
        $engine = new \SugarCraft\Crush\Workflows\WorkflowEngine(
            new \SugarCraft\Crush\Workflows\WorkflowRegistry(),
            new AgentWorkerPool(),
            $ownManager,
        );

        new Chat(workflowEngine: $engine, agentManager: $chatManager);

        $this->assertSame($ownManager, $engine->agentManager());
    }

    public function testWorkflowParallelStageRegistersSubAgentsWithTheAgentManager(): void
    {
        $manager = $this->newAgentManager();
        $registry = new \SugarCraft\Crush\Workflows\WorkflowRegistry();
        $engine = new \SugarCraft\Crush\Workflows\WorkflowEngine(
            $registry,
            new AgentWorkerPool(5, $this->telemetryExecutor(100, 0.02, 4000, 4005)),
        );

        // Chat is what hands the engine the manager the renderer reads.
        new Chat(workflowEngine: $engine, agentManager: $manager);

        $workflow = (new \SugarCraft\Crush\Workflows\WorkflowBuilder())
            ->name('telemetry-parallel')
            ->description('Parallel stage telemetry routing')
            ->maxConcurrent(2)
            ->parallel('fan-out', [
                \SugarCraft\Crush\Workflows\Tasks::agent('coder')->prompt('Task 1'),
                \SugarCraft\Crush\Workflows\Tasks::agent('coder')->prompt('Task 2'),
            ])
            ->build();
        $registry->register($workflow);

        $result = $engine->run('telemetry-parallel', []);

        $this->assertTrue($result->isSuccess());
        // Before W3.F2 executeParallelStage() iterated its stage pool directly,
        // so no workflow sub-agent ever reached the manager.
        $this->assertCount(2, $manager->subAgentsOf('coder'));
        $this->assertSame(200, $manager->tokensUsed('coder'));
        $this->assertSame(0.04, $manager->costUsd('coder'));
        $this->assertSame(5, $manager->elapsedSeconds('coder'));
    }
}
