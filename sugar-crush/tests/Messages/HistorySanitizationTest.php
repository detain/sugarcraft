<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Client;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\HistorySanitizer;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;

/**
 * prompt_plan.md P9.S7 — the wire history reaching a provider is always a
 * history every provider converter can serialize without a 400.
 *
 * Three families of tests, in order:
 *  - the sanitizer as a pure function (R-A's four operations, one each way);
 *  - the sanitizer over the REAL converter of every provider this app ships
 *    (R-D), each asserted with the invariants that matter for that wire shape;
 *  - the sanitizer over histories the running application actually produces —
 *    the ESC-ESC cancel recipe of {@see \SugarCraft\Crush\Tests\ChatTest} and
 *    the refusal commit — mapped through the REAL
 *    {@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()}.
 *
 * The §1.12 honesty the class doc-block of {@see HistorySanitizer} promises is
 * rehearsed here as fact: on the Chat path the structured orphan the plan
 * motivated cannot arrive, because toTypedMessages drops the tool calls and
 * results before pairing could break — the test
 * `testTypedMappingOfToolCarryingChatRowLosesTheCalls` sends a genuinely
 * tool-carrying Chat row through that real mapper and shows the typed row
 * comes out with NO tool calls at all. What DOES arrive is the bare
 * `{"role":"assistant"}` row, and op (4) is the live-value operation every
 * converter test pins.
 */
final class HistorySanitizationTest extends TestCase
{
    private const MARKER = 'Tool call interrupted by restart';

    // ── R-A: the four operations, as pure-function units ─────────────────

    /**
     * Operation (2), shape (b) of the plan's done-when: an answer to a call
     * nobody made. Sglang/OpenAI would put a `tool` row with an unknown
     * `tool_call_id` on the wire — the server-400 family — and Vertex would
     * emit a `tool_result` block with nothing to attach to.
     */
    public function testOrphanToolResultWithoutMatchingCallIsDropped(): void
    {
        $user = new UserMessage('list files');
        $orphan = new ToolResultMessage('call_never_made', 'total 0');

        $out = HistorySanitizer::sanitize([$user, $orphan]);

        $this->assertSame([$user], $out, 'the orphan result must vanish and nothing else may move');
    }

    /**
     * Operation (4), shape (c): the live bare-row producer. Chat's refusal
     * path (Chat.php :2498-2509) commits `Message::assistant('')`, and the
     * converters' `array_filter` (SglangProvider::formatMessages) drops the
     * empty content key, leaving exactly the bare row SglangProviderTest pins.
     */
    public function testEmptyAssistantRowWithNoToolCallsIsDropped(): void
    {
        $user = new UserMessage('go');
        $bare = new AssistantMessage('');

        $out = HistorySanitizer::sanitize([$user, $bare, $user]);

        $this->assertSame([$user, $user], $out);
    }

    /**
     * The control for the drop above: content, not emptiness, is the gate.
     */
    public function testAssistantRowWithContentPassesThroughUntouched(): void
    {
        $assistant = new AssistantMessage('here is the answer');

        $this->assertSame([$assistant], HistorySanitizer::sanitize([$assistant]));
    }

    /**
     * Operation (1)+(3) boundary: an assistant row with calls and EMPTY
     * content is not the bare row — it carries structure — so it survives, and
     * each unanswered call gets its stand-in result immediately after it.
     */
    public function testToolCallsOnlyAssistantSurvivesAndGetsItsAnswerSynthesized(): void
    {
        $call = new EngineToolCall('call_a', 'bash', ['cmd' => 'ls']);
        $assistant = new AssistantMessage('', [$call]);

        $out = HistorySanitizer::sanitize([$assistant]);

        $this->assertCount(2, $out);
        $this->assertSame($assistant, $out[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $out[1]);
        $this->assertSame('call_a', $out[1]->toolCallId());
        $this->assertTrue($out[1]->isError());
    }

    /**
     * Shape (a) of the done-when: an orphaned tool_call with no result
     * anywhere gets the interrupted stand-in under the SAME id, in the same
     * position the repair precedent uses — immediately after its assistant
     * row, mirroring {@see Chat::reviveCheckpointMessage()}.
     */
    public function testUnansweredCallGetsSynthesizedInterruptedResultDirectlyAfterItsRow(): void
    {
        $user = new UserMessage('run it');
        $call = new EngineToolCall('call_x', 'bash', ['cmd' => 'sleep 900']);
        $assistant = new AssistantMessage('thinking', [$call]);
        $trailing = new UserMessage('and now?');

        $out = HistorySanitizer::sanitize([$user, $assistant, $trailing]);

        $this->assertSame([$user, $assistant], array_slice($out, 0, 2));
        $this->assertCount(4, $out, 'user, assistant, synthesized result, trailing user');
        $this->assertSame($trailing, $out[3]);
        $this->assertEquals(new ToolResultMessage('call_x', self::MARKER, true), $out[2]);
    }

    /**
     * The other half of operation (3)'s gate: a call the list already answers
     * must not gain a second, contradicting result anywhere.
     */
    public function testAnsweredCallGetsNoSynthesizedResult(): void
    {
        $call = new EngineToolCall('call_a', 'bash', []);
        $assistant = new AssistantMessage('running', [$call]);
        $result = new ToolResultMessage('call_a', 'total 0');

        $out = HistorySanitizer::sanitize([$assistant, $result]);

        $this->assertSame([$assistant, $result], $out);
    }

    /**
     * R-A's drift guard: the synthesized marker is tied to Chat's constant by
     * REFLECTION only, so neither file couples to the other in src, and the
     * day either string changes this goes red instead of the wire quietly
     * splitting into two vocabularies for "this call lost its runner".
     */
    public function testSyntheticMarkerIsValueEqualToChatInterruptedConstant(): void
    {
        $reflection = new \ReflectionClass(HistorySanitizer::class);
        $constant = $reflection->getReflectionConstant('INTERRUPTED_MARKER');
        $this->assertNotFalse($constant, 'the sanitizer lost its marker constant');
        $this->assertFalse($constant->isPublic(), 'the marker is an implementation detail (R-F)');
        $this->assertSame(
            Chat::INTERRUPTED_TOOL_CALL,
            $constant->getValue(),
            'Chat and the sanitizer now spell the interrupted marker differently',
        );
    }

    /**
     * Purity (R-A): the input array is never mutated — same length, same
     * object identities, same key order — and the output reuses the input's
     * surviving objects rather than rebuilding them.
     */
    public function testInputListIsNeverMutated(): void
    {
        $user = new UserMessage('go');
        $bare = new AssistantMessage('');
        $orphan = new ToolResultMessage('call_gone', 'late');
        $input = [$user, $bare, $orphan];

        $out = HistorySanitizer::sanitize($input);

        $this->assertSame([$user, $bare, $orphan], $input, 'sanitize() must not touch its argument');
        $this->assertSame([$user], $out);
        $this->assertSame($user, $out[0], 'surviving rows must be the same instances, not copies');
    }

    /**
     * "Anything it doesn't recognize passes through byte-identical": a Message
     * implementation this class has never heard of must survive untouched.
     */
    public function testUnrecognizedMessageTypePassesThroughByteIdentical(): void
    {
        $unknown = new class implements TypedMessage {
            public function role(): string
            {
                return 'future';
            }

            public function content(): string
            {
                return 'unheard of';
            }

            public function toArray(): array
            {
                return ['role' => 'future'];
            }
        };

        $this->assertSame([$unknown], HistorySanitizer::sanitize([$unknown]));
    }

    /**
     * The wire array is a LIST: keys re-indexed from zero, order preserved.
     * A gap left by a drop must not reach json_encode() as a JSON object.
     */
    public function testOutputIsAZeroIndexedListInInputOrder(): void
    {
        $a = new UserMessage('a');
        $b = new UserMessage('b');

        $out = HistorySanitizer::sanitize([9 => $a, 40 => $b]);

        $this->assertSame([0, 1], array_keys($out));
        $this->assertSame([$a, $b], $out);
    }

    /**
     * Two unanswered calls in ONE assistant row get TWO results, both
     * directly after that row and in call order — the converter emits both
     * ids on the wire, so leaving either unanswered is the same 400 twice.
     */
    public function testEachUnansweredCallInOneRowSynthesizesItsOwnResult(): void
    {
        $calls = [
            new EngineToolCall('call_1', 'bash', []),
            new EngineToolCall('call_2', 'read', ['path' => 'x']),
        ];
        $assistant = new AssistantMessage('two calls', $calls);

        $out = HistorySanitizer::sanitize([$assistant]);

        $this->assertCount(3, $out);
        $this->assertSame('call_1', $out[1]->toolCallId());
        $this->assertSame('call_2', $out[2]->toolCallId());
    }

    /**
     * Pre-shaped (array) toolCalls entries reach the wire with their `id`
     * key — ToolSchema::formatToolCalls() passes them through untouched — so
     * pairing must collect THAT id, or operation (2) would delete the matching
     * result the converter is about to emit a row for.
     */
    public function testArrayShapedToolCallIdsAreCollectedForPairing(): void
    {
        $assistant = new AssistantMessage('replayed', [
            ['id' => 'call_disk', 'type' => 'function', 'function' => ['name' => 'bash', 'arguments' => '{}']],
        ]);
        $result = new ToolResultMessage('call_disk', 'from disk');

        $out = HistorySanitizer::sanitize([$assistant, $result]);

        $this->assertSame([$assistant, $result], $out, 'a replayed pair must survive intact');
    }

    // ── R-E: valid engine histories pass through UNCHANGED ────────────────

    /**
     * The settle-path fixture shape (RuntimeTest: assistant with one call,
     * the result echoing the ORIGINAL id — Runtime.php :2048-2054): identity
     * in, identity out, element for element, instances included.
     */
    public function testSettlePathHistoryPassesThroughUnchanged(): void
    {
        $user = new UserMessage('ls please');
        $call = new EngineToolCall('call_settle', 'bash', ['cmd' => 'ls']);
        $assistant = new AssistantMessage('running ls', [$call], 'thinking about dirs');
        $result = new ToolResultMessage('call_settle', 'total 0');

        $out = HistorySanitizer::sanitize([$user, $assistant, $result]);

        $this->assertSame([$user, $assistant, $result], $out, 'X-NOOP: a valid history must be identical');
    }

    /**
     * The denied-path shape: sequential denial still produces the paired
     * error result with the original id (Runtime::failure(), Runtime.php
     * :2402) — refusal is a RESULT, not a hole. Nothing here may move.
     */
    public function testDeniedPathHistoryPassesThroughUnchanged(): void
    {
        $call = new EngineToolCall('call_denied', 'bash', ['cmd' => 'rm -rf /']);
        $assistant = new AssistantMessage('requesting', [$call]);
        // The sentence is DenialKind::Refused->reason() output verbatim; the
        // pairing shape is what this fixture pins, not the wording.
        $denied = new ToolResultMessage('call_denied', 'Refused: bash was not run.', isError: true);

        $out = HistorySanitizer::sanitize([$assistant, $denied]);

        $this->assertSame([$assistant, $denied], $out);
    }

    // ── R-B: the wire-in point is buildMessages, the last stop pre-send ───

    /**
     * The choke itself: {@see Runtime::buildMessages()} runs the sanitizer
     * over the instanceof-filtered list. X-CHOKE's discriminator — comment
     * that one line and every wire-shape assertion in this file class goes
     * red while the units above stay green.
     */
    public function testBuildMessagesSanitizesTheHistoryItHandsToCompleteRequest(): void
    {
        $runtime = $this->makeRuntime();
        $call = new EngineToolCall('call_choked', 'bash', []);
        $app = App::new($this->makeProviderStub(), 'test-model')->withMessages([
            new UserMessage('go'),
            new AssistantMessage('thinking', [$call]),
            new AssistantMessage(''),
            'not a message at all',
        ]);

        $out = $this->invokePrivateMethod($runtime, 'buildMessages', [$app]);

        $this->assertCount(3, $out, 'bare row dropped, non-Message filtered, stand-in appended');
        $this->assertSame('call_choked', $out[2]->toolCallId());
    }

    /**
     * The same choke on a VALID history must hand out the byte-identical
     * list — R-E proven at the seam, not just on the class.
     */
    public function testBuildMessagesKeepsValidHistoryByteIdentical(): void
    {
        $runtime = $this->makeRuntime();
        $call = new EngineToolCall('call_ok', 'bash', []);
        $user = new UserMessage('go');
        $assistant = new AssistantMessage('thinking', [$call]);
        $result = new ToolResultMessage('call_ok', 'total 0');
        $app = App::new($this->makeProviderStub(), 'test-model')
            ->withMessages([$user, $assistant, $result, 7 => 'noise']);

        $out = $this->invokePrivateMethod($runtime, 'buildMessages', [$app]);

        $this->assertSame([$user, $assistant, $result], $out);
    }

    // ── R-D: every provider converter sees a valid wire ───────────────────

    /**
     * The OpenAI-shape converters (Sglang and OpenAI share the row grammar):
     * every `tool` row's id was CALLED by a preceding assistant row, every
     * call id is followed by its result, no assistant row is bare, and the
     * rows that survive are byte-identical to what the same converter makes
     * of them alone.
     */
    public function testOpenAiShapeConvertersEmitPairedNonBareWires(): void
    {
        foreach (['sglang', 'openai'] as $which) {
            $wire = $this->invokePrivateMethod($this->makeProvider($which), 'formatMessages', [
                $this->choke($this->nastyHistory()),
            ]);

            $this->assertOpenAiShapeWire($wire, $which);
        }
    }

    /**
     * The same invariants on a history that is ALREADY valid — the converter
     * may not gain or lose a row through the sanitizer, which is what lets
     * every existing provider pin survive this step untouched.
     */
    public function testOpenAiShapeConvertersAreByteIdenticalOnValidHistories(): void
    {
        $valid = [
            new UserMessage('go'),
            new AssistantMessage('thinking', [new EngineToolCall('call_v', 'bash', [])]),
            new ToolResultMessage('call_v', 'total 0'),
        ];

        foreach (['sglang', 'openai'] as $which) {
            $provider = $this->makeProvider($which);
            $this->assertSame(
                $this->invokePrivateMethod($provider, 'formatMessages', [$valid]),
                $this->invokePrivateMethod($provider, 'formatMessages', [HistorySanitizer::sanitize($valid)]),
                $which . ' must not see any difference on a valid history',
            );
        }
    }

    /**
     * Vertex (Anthropic shape): a turn with no blocks is a 400 (the provider
     * already drops it, VertexProvider.php :649-653) and a `tool_result` must
     * find its `tool_use` in a preceding turn. Post-sanitizer both hold, and
     * the synthesized error block carries the marker text.
     */
    public function testVertexAnthropicConverterSeesFullyPairedTurns(): void
    {
        $turns = $this->invokePrivateMethod($this->makeProvider('vertex'), 'formatAnthropicMessages', [
            $this->choke($this->nastyHistory()),
        ]);

        $this->assertNotSame([], $turns);
        $openUse = [];
        $closedUse = [];
        foreach ($turns as $turn) {
            $this->assertNotSame([], $turn['content'], 'an empty turn reached the wire');
            foreach ($turn['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $openUse[] = $block['id'];
                } elseif (($block['type'] ?? '') === 'tool_result') {
                    $this->assertContains(
                        $block['tool_use_id'],
                        $openUse,
                        'a tool_result block reached the wire before any matching tool_use',
                    );
                    $closedUse[] = $block['tool_use_id'];
                }
            }
        }

        $this->assertSame(['call_u', 'call_o'], $openUse, 'both calls must be on the wire, once each');
        $this->assertEqualsCanonicalizing(
            $openUse,
            $closedUse,
            'a tool_use block reached the wire with no tool_result after it',
        );
    }

    /**
     * Bedrock flattens every type to text and never emits an id (brief: its
     * validity check is content-shape, not pairing). Post-sanitizer the bare
     * assistant contributes no empty-text row, and every surviving row is
     * what the converter alone makes of the same message object.
     */
    public function testBedrockConverterLosesTheBareRowAndNothingElse(): void
    {
        $nasty = $this->nastyHistory();
        $provider = $this->makeProvider('bedrock');

        $before = $this->invokePrivateMethod($provider, 'formatMessages', [$nasty]);
        $after = $this->invokePrivateMethod($provider, 'formatMessages', [$this->choke($nasty)]);

        $this->assertCount(\count($before) - 1, $after, 'two rows dropped, one stand-in added');
        $this->assertNotContains(['role' => 'assistant', 'content' => [['text' => '']]], $after);
        $this->assertContains($after[0], $before);
        $this->assertContains($after[\count($after) - 1], $before);
    }

    /**
     * ClaudeCode flattens everything to a text prompt (its
     * `Tool Result: {content}` shape) — post-sanitizer the bare assistant
     * contributes no dangling `Assistant: ` section, and the orphan answer
     * contributes no `Tool Result:` line naming a call that never happened.
     */
    public function testClaudeCodeConverterPromptHasNoPhantomSections(): void
    {
        $prompt = $this->invokePrivateMethod($this->makeProvider('claudecode'), 'buildPrompt', [
            $this->choke($this->nastyHistory()),
        ]);

        $this->assertIsString($prompt);
        $this->assertDoesNotMatchRegularExpression('/^Assistant: $/m', $prompt);
        $this->assertStringNotContainsString('Tool Result: orphaned output', $prompt);
        $this->assertStringContainsString('Tool Result: ' . self::MARKER, $prompt);
    }

    /**
     * Echo only reads user turns; the sanitizer may not disturb which user
     * rows exist or their content. Included because R-D lists it, and because
     * a sanitizer bug that ate non-assistant rows would show up even here.
     */
    public function testEchoConverterStillSeesTheSameUserTurns(): void
    {
        $nasty = $this->nastyHistory();

        $this->assertSame(
            $this->invokePrivateMethod($this->makeProvider('echo'), 'echo', [$nasty]),
            $this->invokePrivateMethod($this->makeProvider('echo'), 'echo', [$this->choke($nasty)]),
        );
    }

    // ── R-D: the real application histories, through the real mapper ─────

    /**
     * The ESC-ESC cancel recipe of
     * {@see \SugarCraft\Crush\Tests\ChatTest::testDoubleEscWithinWindowAbortsInFlightRequest},
     * then the REAL {@see EngineBackend::toTypedMessages()} (private; reached
     * by reflection without touching the class), then the sanitizer: the
     * cancelled history must reach the wire with the notice intact and no
     * bare row added by the sanitizer itself.
     *
     * WHAT THIS PROVES UNREACHABLE, per the plan's say-so clause: with
     * EchoBackend the cancelled turn commits no assistant row at all, so
     * ESC-ESC alone leaves no orphan tool_call in the TYPED vocabulary — the
     * orphan the plan motivated needs the engine-loop fork window, and even a
     * Chat row that DID carry calls loses them in toTypedMessages (next
     * test). Both halves are asserted, so the claim is evidence, not prose.
     */
    public function testEscEscCancelHistoryReachesTheWireThroughTheRealMapper(): void
    {
        $chat = (new Chat(backend: new EchoBackend(), inputBuf: 'hi'))
            ->update(new KeyMsg(KeyType::Enter, ''))[0];
        $this->assertTrue($chat->inFlight);

        [$afterFirst] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $afterFirst->update(new KeyMsg(KeyType::Escape, ''));

        $history = $cancelled->history;
        $this->assertStringContainsString('cancelled', end($history)->content);

        $typed = $this->toTyped($history);

        $this->assertSame(
            $typed,
            $this->choke($typed),
            'the cancel recipe must arrive already valid — defense, not fix'
        );

        $wire = $this->invokePrivateMethod($this->makeProvider('sglang'), 'formatMessages', [$this->choke($typed)]);
        foreach ($wire as $row) {
            $this->assertTrue(
                ($row['role'] ?? '') !== 'assistant' || \array_key_exists('content', $row),
                'a bare assistant row reached the wire after the cancel',
            );
        }
        // The R-C follow-up this test used to record: the notice was hoisted
        // into the merged system row. It is a UI-only row now (audit 15b-03),
        // so it stays in the transcript and never reaches the wire at all.
        $this->assertTrue(end($history)->uiOnly, 'the cancel notice is flagged UI-only');
        foreach ($wire as $row) {
            $this->assertStringNotContainsString(
                '_Request cancelled._',
                (string) ($row['content'] ?? ''),
                'the cancel notice must not be hoisted into the system row, or reach the wire in any row',
            );
        }
    }

    /**
     * toTypedMessages loses structure: a Chat row that DID carry tool calls
     * (the refusal commit's $request->assistantMessage shape) comes back with
     * `toolCalls() === null`. This is the proof behind the class doc-block's
     * claim that ops (2)/(3) are dormant on the Chat path — recorded honestly
     * rather than fixed, because fixing it would mean editing toTypedMessages
     * (ruling R-C says zero edits there).
     */
    public function testTypedMappingOfToolCarryingChatRowLosesTheCalls(): void
    {
        $row = Message::assistant('running')->withToolCalls([
            new ToolCall('bash', ['cmd' => 'ls'], 'call_lost'),
        ]);

        $typed = $this->toTyped([$row]);

        $this->assertSame(Role::Assistant, $row->role);
        $this->assertInstanceOf(AssistantMessage::class, $typed[0]);
        $this->assertNull($typed[0]->toolCalls(), 'the Chat path cannot deliver orphan calls');
        $this->assertSame([$typed[0]], HistorySanitizer::sanitize($typed), 'nothing to do on a lost structure');
    }

    /**
     * The refusal commit produced by the REAL keystroke path, not assembled
     * by hand: a PreToolUse hook raises an ASK, one ESC answers it with
     * Reject (ChatTest's 'escape refuses' mapping), and Chat.php :2494-2510
     * commits the three rows — tool-carrying assistant, `Refused:` note,
     * `Message::assistant('')` carrying the denial as a result.
     * toTypedMessages flattens that to [assistant, system, bare assistant];
     * the bare row is the LIVE producer op (4) exists for, and after the
     * sanitizer the OpenAI-shape wire has no bare row while the denial note
     * survives (hoisted into the merged system row on this provider — the
     * R-C leak recorded for its own follow-up step, not fixed here).
     */
    public function testRefusalCommitHistoryHasNoBareAssistantRowAfterSanitize(): void
    {
        $ask = Message::assistant('running')->withToolCalls([
            new ToolCall('bash', ['cmd' => 'rm -rf /'], 'call_1'),
        ]);

        $chat = (new Chat(backend: new EchoBackend()))
            ->registerTool('bash', static fn (array $args): string => 'must never run')
            ->withHooks($this->askHookManager('Run it?'));

        [$awaiting] = $chat->update(new AssistantMsg($ask));
        $this->assertNotNull($awaiting->pendingPermission(), 'the ASK prompt must be up');

        [$refused] = $awaiting->update(new KeyMsg(KeyType::Escape, ''));

        $history = $refused->history;
        $this->assertNotSame($awaiting->history, $history, 'the refusal must commit rows');
        $last = end($history);
        $this->assertSame(Role::Assistant, $last->role);
        $this->assertSame('', $last->content, 'the committed refusal row is the empty assistant row');
        $this->assertCount(1, $last->toolResults, 'with its denial as a RESULT, not only a note');

        $mapped = $this->toTyped($history);

        $this->assertContains(AssistantMessage::class, array_map('get_class', $mapped));
        $this->assertContains(SystemMessage::class, array_map('get_class', $mapped));
        $bare = array_values(array_filter(
            $mapped,
            static fn (TypedMessage $m): bool => $m instanceof AssistantMessage
                && $m->content() === '' && $m->toolCalls() === null,
        ));
        $this->assertCount(1, $bare, 'the real commit must yield exactly one bare typed row');

        $typed = $this->choke($mapped);

        $this->assertSame(
            array_values(array_filter($mapped, static fn (TypedMessage $m): bool => $m !== $bare[0])),
            $typed,
            'only the bare row may leave the list',
        );
        $this->assertCount(\count($mapped) - 1, $typed);

        $wire = $this->invokePrivateMethod($this->makeProvider('sglang'), 'formatMessages', [$typed]);

        foreach ($wire as $row) {
            $this->assertFalse(
                ($row['role'] ?? '') === 'assistant' && !\array_key_exists('content', $row),
                'op (4) neutered? a bare assistant row reached the OpenAI-shape wire',
            );
        }
        $this->assertStringContainsString('was not run', (string) ($wire[0]['content'] ?? ''));
    }

    /**
     * A PreToolUse hook that answers ASK, wrapped in its own manager — the
     * ChatTest recipe, kept minimal so the refusal branch is reached by keys.
     */
    private function askHookManager(string $question): HookManager
    {
        $hook = new class ($question) implements HookInterface {
            public function __construct(private readonly string $question) {}

            public function name(): string
            {
                return 'ask-once';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::ask($this->question);
            }
        };

        $manager = new HookManager(new HookRegistry());
        $manager->register($hook);

        return $manager;
    }

    // ── fixtures / harness ────────────────────────────────────────────────

    /**
     * Every live-bad shape at once: an answered call, an ORPHAN call (a), an
     * ORPHAN result (b), the BARE assistant row (c), a system notice, and a
     * trailing user row that must stay last.
     *
     * @return list<TypedMessage>
     */
    private function nastyHistory(): array
    {
        return [
            new UserMessage('go'),
            new AssistantMessage('thinking', [new EngineToolCall('call_u', 'bash', [])]),
            new ToolResultMessage('call_u', 'total 0'),
            new AssistantMessage('more work', [new EngineToolCall('call_o', 'read', ['path' => 'x'])]),
            new ToolResultMessage('call_never_made', 'orphaned output'),
            new AssistantMessage(''),
            new SystemMessage('_Request cancelled._'),
            new UserMessage('and now?'),
        ];
    }

    /**
     * @param array<int, Message> $history
     *
     * @return list<TypedMessage>
     */
    private function toTyped(array $history): array
    {
        $backend = (new \ReflectionClass(EngineBackend::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($backend, 'toTypedMessages');
        $method->setAccessible(true);

        /** @var list<TypedMessage> $typed */
        $typed = $method->invoke($backend, $history);

        return $typed;
    }

    /**
     * The REAL send choke: {@see Runtime::buildMessages()} over an App
     * carrying exactly these typed messages — what the provider would receive
     * on the wire. Converter assertions run on this output, never on
     * sanitize() directly, so a wire that only the standalone class would
     * sanitize still goes red (X-CHOKE).
     *
     * @param list<TypedMessage> $messages
     *
     * @return list<TypedMessage>
     */
    private function choke(array $messages): array
    {
        $app = App::new($this->makeProviderStub(), 'test-model')->withMessages($messages);

        return $this->invokePrivateMethod($this->makeRuntime(), 'buildMessages', [$app]);
    }

    private function makeRuntime(): Runtime
    {
        return new Runtime($this->makeProviderStub(), new HookManager(new HookRegistry()));
    }

    private function makeProviderStub(): EchoProvider
    {
        return new EchoProvider();
    }

    private function makeProvider(string $which): object
    {
        return match ($which) {
            'sglang' => new SglangProvider(
                'https://api.example.test', 'test-model', null, $this->createMock(Client::class)
            ),
            'openai' => new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o'),
            'vertex' => new VertexProvider('test-project', 'us-central1', 'claude-test-vertex'),
            'bedrock' => new BedrockProvider(
                new BedrockRuntimeClient([
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                ]),
                'us-east-1',
                'anthropic.claude-test',
            ),
            'claudecode' => new ClaudeCodeProvider(new ClaudeCodeInvocation()),
            'echo' => new EchoProvider(),
            default => $this->fail("unknown converter {$which}"),
        };
    }

    /**
     * The three OpenAI-shape invariants, asserted over a converted wire.
     *
     * @param array<array<string, mixed>> $wire
     */
    private function assertOpenAiShapeWire(array $wire, string $which): void
    {
        $called = [];
        $answeredSoFar = [];
        $bareRows = [];
        foreach ($wire as $row) {
            $role = $row['role'] ?? null;
            if ($role === 'assistant') {
                if (!\array_key_exists('content', $row)) {
                    $bareRows[] = $row;
                }
                foreach ($row['tool_calls'] ?? [] as $call) {
                    $called[] = $call['id'] ?? null;
                }
            } elseif ($role === 'tool') {
                $this->assertContains(
                    $row['tool_call_id'] ?? null,
                    $called,
                    "{$which}: a tool row whose call was never made",
                );
                $answeredSoFar[] = $row['tool_call_id'] ?? null;
            }
        }

        $this->assertSame([], $bareRows, "{$which}: a bare assistant row reached the wire");
        $this->assertSame(
            ['call_u', 'call_o'],
            $called,
            "{$which}: the answered call must keep its id and the orphan must gain one",
        );
        $this->assertEqualsCanonicalizing($called, $answeredSoFar, "{$which}: calls without results on the wire");
        $this->assertStringContainsString(
            '_Request cancelled._',
            (string) json_encode($wire),
            "{$which}: the system notice must still reach the wire (hoisted or in place)",
        );
    }

    /**
     * Same reflection idiom as the private-converter harness in
     * {@see \SugarCraft\Crush\Tests\Providers\SglangProviderTest}: the wire
     * builders are private by design and the sanitizer contract is defined
     * over their output.
     *
     * @param array<int, mixed> $args
     */
    private function invokePrivateMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
