<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * crush_code.md Phase 5 item 7's first half: the token count and cost every
 * provider already returned, travelling the two seams that used to discard them.
 *
 * The seams, measured before this change: `Runtime::runBatch()` built its
 * {@see \SugarCraft\Crush\Messages\AssistantMessage} from `$response->content`,
 * `->toolCalls` and `->reasoning` and dropped `$response->tokensUsed` /
 * `->costUsd` on the floor; `EngineBackend::complete()` then built a root
 * {@see Message} that had nowhere to put them anyway. So
 * {@see \SugarCraft\Crush\Util\TokenTracker} could not be constructed anywhere
 * in `src/` or `bin/` — there was nothing to feed it.
 *
 * Every test here drives the REAL Runtime and the REAL EngineBackend against a
 * provider double. Nothing calls a private method or asserts on a docblock: the
 * question in each case is what a caller can observe on the returned Message.
 */
final class UsageWiringTest extends TestCase
{
    /**
     * THE test of this bundle, and the one the chain's signature defect would
     * fail. A turn is N provider calls, not one:
     * {@see EngineBackend::complete()} loops while the model keeps asking for
     * tools, and every step is its own billed call. A readout fed from the LAST
     * step would report 7 tokens for a turn that cost 107.
     *
     * Two steps here — step 1 calls `clock` and is billed 100 tokens / $0.10,
     * step 2 answers and is billed 7 tokens / $0.007 — so the turn's figure has
     * to be 107 and $0.107, not 7 and $0.007.
     */
    public function testATurnsUsageIsTheSumOverEveryStepOfTheAgenticLoopNotTheLastOne(): void
    {
        $provider = new ScriptedUsageProvider([
            [
                'response' => new CompleteResponse(
                    content: 'let me check the clock',
                    toolCalls: [new ToolCall('call_1', 'clock', [])],
                    tokensUsed: 100,
                    costUsd: 0.10,
                ),
            ],
            ['response' => new CompleteResponse(content: 'it is noon', tokensUsed: 7, costUsd: 0.007)],
        ]);

        $backend = (new EngineBackend($provider, 'scripted'))
            ->withoutHooks()
            ->withTools([$this->clockTool()]);

        $reply = $backend->complete([Message::user('what time is it?')]);

        $this->assertSame('it is noon', $reply->content, 'fixture: the loop must really have run two steps');
        $this->assertSame(2, $provider->calls(), 'fixture: two provider calls, so there are two bills to add up');
        $this->assertNotNull($reply->usage);
        $this->assertSame(107, $reply->usage->totalTokens, 'the sum over steps, not the final step alone');
        $this->assertEqualsWithDelta(0.107, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * A step that reports nothing must not zero out the steps that did. `sum()`
     * skips nulls rather than treating them as zeros, which matters because a
     * provider can answer the tool-calling step with usage and the final step
     * without (or the other way round).
     */
    public function testAStepThatReportsNothingDoesNotEraseTheStepsThatDid(): void
    {
        $provider = new ScriptedUsageProvider([
            [
                'response' => new CompleteResponse(
                    content: 'checking',
                    toolCalls: [new ToolCall('call_1', 'clock', [])],
                    tokensUsed: 90,
                    costUsd: 0.09,
                ),
            ],
            ['response' => new CompleteResponse(content: 'it is noon')],
        ]);

        $reply = (new EngineBackend($provider, 'scripted'))
            ->withoutHooks()
            ->withTools([$this->clockTool()])
            ->complete([Message::user('what time is it?')]);

        $this->assertNotNull($reply->usage);
        $this->assertSame(90, $reply->usage->totalTokens);
        $this->assertEqualsWithDelta(0.09, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * A turn where NO step reported anything carries no usage at all — null, not
     * a zero-valued Usage. This is the common production case (a streamed turn),
     * and it is the difference between the status bar saying nothing and the
     * status bar claiming `$0.0000` was spent.
     */
    public function testATurnWhoseProviderReportedNothingCarriesNoUsageAtAll(): void
    {
        $reply = (new EngineBackend(new EchoProvider(), 'echo'))
            ->withoutHooks()
            ->complete([Message::user('hello')]);

        $this->assertNull($reply->usage, 'nothing reported must stay nothing reported');
    }

    /**
     * The streaming seam. Usage can arrive split across chunks —
     * {@see \SugarCraft\Crush\Providers\VertexProvider} emits input tokens on
     * `message_start` and output tokens on the terminal `message_delta`, each
     * priced separately — so `Runtime::runStreaming()` has to accumulate them.
     * Reading the last chunk alone would bill the turn for its output and none
     * of its input.
     */
    public function testStreamedUsageIsAccumulatedAcrossChunksAndNotReadOffTheLastOne(): void
    {
        $provider = new ChunkedUsageProvider([
            new CompleteResponse(content: '', tokensUsed: 400, costUsd: 0.004),
            new CompleteResponse(content: 'it is '),
            new CompleteResponse(content: 'noon'),
            new CompleteResponse(content: '', tokensUsed: 12, costUsd: 0.0006),
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'chunked')));

        $this->assertSame('it is noon', $messages[0]->content(), 'fixture: the text must still assemble whole');
        $usage = $messages[0]->usage();
        $this->assertNotNull($usage);
        $this->assertSame(412, $usage->totalTokens, 'both usage-bearing chunks, not just the terminal one');
        $this->assertEqualsWithDelta(0.0046, $usage->costUsd, 0.0000001);
    }

    /**
     * A stream of pure content chunks — which is what
     * {@see \SugarCraft\Crush\Providers\OpenAIProvider::completeStream()}
     * produces, its own docblock stating that `tokensUsed` and `costUsd` are
     * always 0 there — reports nothing rather than zero.
     */
    public function testAStreamThatNeverReportsUsageYieldsNullNotZero(): void
    {
        $provider = new ChunkedUsageProvider([
            new CompleteResponse(content: 'it is '),
            new CompleteResponse(content: 'noon'),
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'chunked')));

        $this->assertNull($messages[0]->usage());
    }

    /**
     * The batch seam on its own, one layer below the agentic loop: whatever the
     * provider reported has to be on the typed AssistantMessage before
     * EngineBackend can sum anything.
     */
    public function testTheBatchPathPutsTheProvidersFiguresOnTheAssistantMessage(): void
    {
        $provider = new ScriptedUsageProvider([
            ['response' => new CompleteResponse(content: 'hi', tokensUsed: 33, costUsd: 0.0011)],
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'scripted')));

        $usage = $messages[0]->usage();
        $this->assertNotNull($usage);
        $this->assertSame(33, $usage->totalTokens);
        $this->assertEqualsWithDelta(0.0011, $usage->costUsd, 0.0000001);
    }

    /**
     * The wire shape sent back to the provider must NOT grow a usage key: it is
     * what the next request carries, and the previous call's bill has no business
     * in the next call's prompt.
     */
    public function testUsageDoesNotLeakIntoTheWireShapeSentBackToTheProvider(): void
    {
        $message = new \SugarCraft\Crush\Messages\AssistantMessage('hi', null, null, Usage::new(50, 0.01));

        $this->assertSame(
            ['role', 'content', 'tool_calls', 'reasoning'],
            array_keys($message->toArray()),
        );
    }

    /**
     * The THIRD seam, and the one easiest to leave out: {@see
     * EngineBackend::completeAsync()} runs the turn in a `pcntl_fork()`ed child,
     * so the usage has to cross a socket. The parent unserializes with
     * `allowed_classes => false`, which means the object cannot make the trip —
     * only {@see Usage::toArray()}'s plain array can. This is exactly where
     * `reasoning` and `imageBytes` each had to be threaded separately.
     *
     * Driven the way {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest}
     * drives one: a single `run()`/`stop()` pair with a safety timer. Measured on
     * this platform it is a real fork (pcntl present, `stream_socket_pair`
     * succeeding); where either is unavailable `completeAsync()` degrades to its
     * documented in-process fallback, on which the same assertion still holds —
     * so the test cannot silently stop covering the seam, only cover it through
     * the other route, and it skips outright where pcntl is absent altogether.
     */
    public function testUsageCrossesTheForkBoundaryOfCompleteAsync(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('completeAsync() needs pcntl + stream_socket_pair to fork at all');
        }

        $provider = new ScriptedUsageProvider([
            ['response' => new CompleteResponse(content: 'forked reply', tokensUsed: 271, costUsd: 0.0271)],
        ]);

        $reply = $this->awaitPromise(
            (new EngineBackend($provider, 'scripted'))
                ->withoutHooks()
                ->completeAsync([Message::user('hello')])
        );

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertSame('forked reply', $reply->content, 'fixture: the child really produced the turn');
        $this->assertNotNull($reply->usage, 'the usage must survive the socket, not be dropped with the child');
        $this->assertSame(271, $reply->usage->totalTokens);
        $this->assertEqualsWithDelta(0.0271, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * And a turn the child reported nothing for arrives with no usage rather than
     * with a zero — the same distinction on the far side of the socket.
     */
    public function testAForkedTurnWithNoReportedUsageArrivesWithNone(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('completeAsync() needs pcntl + stream_socket_pair to fork at all');
        }

        $reply = $this->awaitPromise(
            (new EngineBackend(new EchoProvider(), 'echo'))
                ->withoutHooks()
                ->completeAsync([Message::user('hello')])
        );

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertNull($reply->usage);
    }

    /**
     * A single `run()`/`stop()` pair, for the reason
     * {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest::awaitPromise()}
     * documents at length: re-entering `run()` from a freshly re-added timer can
     * miss the socket's readability edge and hang.
     */
    private function awaitPromise(\React\Promise\PromiseInterface $promise): mixed
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            function (\Throwable $e) use (&$settled, &$error, $loop): void {
                $settled = true;
                $error = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        if (!$settled) {
            $this->fail('Promise did not settle within the test timeout');
        }
        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    /**
     * `Message`'s clone methods have to carry the usage or it vanishes at
     * whichever seam happens to touch the message next — the exact failure mode
     * that lost `reasoning` before it was threaded through the same set.
     *
     * The SET is derived by reflection, the CARRYING is checked by calling each
     * one. Those are two different jobs and only the second can be done with real
     * arguments, which is why they are not collapsed: a hand-written list is
     * complete only until someone adds a seventh clone method, and a
     * generically-invoked one cannot supply a `ToolCall` where a `ToolCall` is
     * wanted. So the reflection half asserts the list has not grown behind the
     * explicit half's back, naming any method it does not cover.
     *
     * `withUsage()` is excluded because it SETS the usage rather than carrying it
     * — checking that it forwards the old value would assert the opposite of its
     * contract.
     */
    public function testEveryMessageCloneMethodCarriesTheUsageForward(): void
    {
        $base = Message::assistant('hi')->withUsage(Usage::new(21, 0.002));

        $clones = [
            'attachFile' => $base->attachFile('/tmp/x'),
            'attachImage' => $base->attachImage('/tmp/x.png'),
            'withToolCalls' => $base->withToolCalls([new \SugarCraft\Crush\ToolCall('t', [], 'i')]),
            'withToolResults' => $base->withToolResults([new \SugarCraft\Crush\ToolResult('i', 'r')]),
            'withReasoning' => $base->withReasoning('because'),
            'withImage' => $base->withImage('bytes', 'kitty'),
        ];

        $reflected = [];
        foreach ((new \ReflectionClass(Message::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returns = $method->getReturnType();
            if ($method->isStatic() || !$returns instanceof \ReflectionNamedType) {
                continue;
            }
            if (in_array($returns->getName(), ['self', 'static', Message::class], true)) {
                $reflected[] = $method->getName();
            }
        }
        sort($reflected);
        $expected = [...array_keys($clones), 'withUsage'];
        sort($expected);
        $this->assertSame(
            $expected,
            $reflected,
            'Message grew or lost a self-returning method; add it to $clones above (or to the '
            . "withUsage() exclusion) so the usage it must carry is actually checked",
        );

        foreach ($clones as $method => $clone) {
            $this->assertNotNull($clone->usage, "{$method}() dropped the usage");
            $this->assertSame(21, $clone->usage->totalTokens, "{$method}() changed the usage");
        }
    }

    private function clockTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'clock';
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call_1', content: 'noon');
            }
        };
    }

    // -------------------------------------------------------------------------
    //  P4.S2 — providers populate the Usage buckets (prompt_plan.md)
    //
    //  Each provider's parse of a REAL-SHAPED usage object is asserted bucket
    //  by bucket, with the payload's provenance named in the test that uses
    //  it: LIVE-PROBE (byte-copied from the 2026-09-02 skynet2 responses,
    //  pasted into the P4.S2 worklog entry), VENDORED-SHAPE (field names and
    //  optionality read out of the SDK this repo itself ships — a locally
    //  verifiable source), or UNVERIFIED-DOCUMENTED (the published API's
    //  shape with no local artifact to check it against, labelled as such in
    //  the docblock per the step brief's source-of-truth ranking).
    //
    //  Two layers run in every provider's cases on purpose. The `parse…()`
    //  seam asserts the BUCKETS - the artifact this step adds. And a
    //  `complete()`/`completeStream()` driven through the provider's own mock
    //  transport asserts the provider really routes through that parse:
    //  tokensUsed/costUsd keep their exact pre-P4.S2 values for legitimate
    //  payloads, and the negative-clamp cases (which the old inline parses
    //  passed through) go red if the routing is reverted. The buckets' last
    //  hop onto CompleteResponse/Message is the reported "widen
    //  CompleteResponse" seam (Usage.php's class docblock, "a later seam");
    //  until it lands, no consumer in src/ can observe the bucket fields on a
    //  LIVE response — which is the honest state of this step, not a gap
    //  these tests hide.
    // -------------------------------------------------------------------------

    /**
     * LIVE-PROBE payload: skynet2 sglang `/v1/chat/completions`, 2026-09-02
     * 14:43 UTC, curl exit 0 (model deepseek-ai/DeepSeek-V4-Flash-0731).
     * Byte-copied; the worklog entry carries the same body.
     */
    private const SGLANG_PROBE_COLD = <<<'JSON'
{"id":"8985b0de56a44d82b3fdd6fafa3ec3e8","object":"chat.completion","created":1788360201,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"message":{"role":"assistant","content":"","reasoning_content":"We need to respond to user: \"What fruit am I thinking of?\" Must respond with exactly single word BANANA and","tool_calls":null},"logprobs":null,"finish_reason":"length","matched_stop":null}],"usage":{"prompt_tokens":75,"total_tokens":99,"completion_tokens":24,"prompt_tokens_details":null,"reasoning_tokens":25},"metadata":{"weight_version":"default"}}
JSON;

    /**
     * LIVE-PROBE payload: the SAME 1,258-token request body re-sent
     * immediately (probe 2 of 2, 2026-09-02). sglang's radix cache cannot
     * miss a byte-identical 1,258-token prefix, and this deployment STILL
     * reports `prompt_tokens_details: null` - the measured fact behind
     * "Sglang reports no cache fields here". Byte-copied.
     */
    private const SGLANG_PROBE_CACHED = <<<'JSON'
{"id":"5d6374111a64453ea9e33342baa4bcc5","object":"chat.completion","created":1788360220,"model":"deepseek-ai/DeepSeek-V4-Flash-0731","choices":[{"index":0,"message":{"role":"assistant","content":"","reasoning_content":"We need respond to user. User says repeated sentence then \"REPLY ONLY:","tool_calls":null},"logprobs":null,"finish_reason":"length","matched_stop":null}],"usage":{"prompt_tokens":1258,"total_tokens":1274,"completion_tokens":16,"prompt_tokens_details":null,"reasoning_tokens":16},"metadata":{"weight_version":"default"}}
JSON;

    public function testP4S2SglangPopulatesBucketsFromTheLiveProbedResponse(): void
    {
        $document = json_decode(self::SGLANG_PROBE_COLD, true, flags: JSON_THROW_ON_ERROR);
        $provider = $this->p4s2SglangRespondingWith(self::SGLANG_PROBE_COLD);

        $response = $provider->complete(new CompleteRequest(
            model: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            messages: [new UserMessage('What fruit am I thinking of?')],
        ));

        // Production routing: the billable pair leaves the provider exactly as
        // it always did (99 = the wire's own total_tokens, 0.0 = self-hosted).
        $this->assertSame(99, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);

        $usage = $provider->parseUsage($document['usage']);
        $this->assertSame(99, $usage->totalTokens);
        $this->assertSame(75, $usage->inputTokens, 'no cache reported, so the whole prompt is fresh input');
        $this->assertSame(24, $usage->outputTokens);
        $this->assertNull($usage->cacheReadTokens, 'prompt_tokens_details null = the server said nothing, NOT zero');
        $this->assertNull($usage->cacheCreationTokens, 'the OpenAI-compatible family has no cache-creation field; parseUsage must not invent one');
        // The flat `reasoning_tokens: 25` this server sends is NOT a Usage
        // bucket (qwen.md §P1 records the flat spelling); folding it into
        // outputTokens would bill 49 completion tokens nobody counted.
        $this->assertSame(24, $usage->outputTokens, 'reasoning_tokens must not leak into outputTokens');
        // promptTokens() refuses across the unreported creation bucket - P4.S1
        // doctrine, now reached by real provider data for the first time.
        $this->assertNull($usage->promptTokens());
    }

    public function testP4S2SglangReportsNoCacheOnTheDeploymentThatWasProbed(): void
    {
        $document = json_decode(self::SGLANG_PROBE_CACHED, true, flags: JSON_THROW_ON_ERROR);
        $provider = $this->p4s2SglangRespondingWith(self::SGLANG_PROBE_CACHED);

        $response = $provider->complete(new CompleteRequest(
            model: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            messages: [new UserMessage('again')],
        ));
        $this->assertSame(1274, $response->tokensUsed);

        // The key's PRESENCE with a null value is the deployment fact (radix
        // cache cannot miss this prefix; it still reports nothing).
        $this->assertArrayHasKey('prompt_tokens_details', $document['usage']);
        $this->assertNull($document['usage']['prompt_tokens_details']);

        $usage = $provider->parseUsage($document['usage']);
        $this->assertSame(1258, $usage->inputTokens);
        $this->assertSame(16, $usage->outputTokens);
        $this->assertNull($usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens);
    }

    public function testP4S2SglangReadsCachedTokensTheMomentAServerReportsThem(): void
    {
        // The decide-the-honest-behaviour-and-pin-it half of the brief's
        // no-cache-field rule: the as-received probe payload, with the
        // documented member planted where the null key sits. A server that
        // starts reporting (sglang launched with cache reporting, or a
        // vLLM front) is READ, not crashed on and not ignored.
        $provider = SglangProvider::openAiCompatible('https://api.example.com');
        $usage = $provider->parseUsage([
            'prompt_tokens' => 1258,
            'total_tokens' => 1274,
            'completion_tokens' => 16,
            'prompt_tokens_details' => ['cached_tokens' => 1152],
            'reasoning_tokens' => 16,
        ]);

        $this->assertSame(106, $usage->inputTokens, 'this family\'s prompt_tokens COUNTS the cached prefix; input is the fresh remainder');
        $this->assertSame(16, $usage->outputTokens);
        $this->assertSame(1152, $usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens);
        $this->assertSame(1274, $usage->totalTokens, 'the cached split must never move the wire total');

        // Pathological half: a server reporting more cached than prompt is a
        // provider bug; the fresh remainder floors at 0, it does not go
        // negative and it does not void the reported cache read.
        $broken = $provider->parseUsage([
            'prompt_tokens' => 10,
            'total_tokens' => 12,
            'completion_tokens' => 2,
            'prompt_tokens_details' => ['cached_tokens' => 15],
        ]);
        $this->assertSame(0, $broken->inputTokens);
        $this->assertSame(15, $broken->cacheReadTokens);
    }

    public function testP4S2SglangNegativeWireTotalBillsZeroThroughTheRealParsePath(): void
    {
        // The routing-revert RED test: with the old inline
        // `$data['usage']['total_tokens'] ?? 0` the negative passed through to
        // CompleteResponse; once every number leaves through the parsed
        // Usage, Usage::new()'s clamp owns it (provider bug accounted as
        // zero - the doctrine in Usage's class docblock, first enforced here
        // on this provider's live path).
        $body = '{"choices":[{"message":{"content":"ok","reasoning_content":null,"tool_calls":null}}],'
            . '"usage":{"prompt_tokens":-5,"total_tokens":-9,"completion_tokens":-4,"prompt_tokens_details":null}}';
        $provider = $this->p4s2SglangRespondingWith($body);

        $response = $provider->complete(new CompleteRequest(
            model: 'm',
            messages: [new UserMessage('x')],
        ));

        $this->assertSame(0, $response->tokensUsed, 'a negative wire total now routes through the clamp');
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testP4S2CustomNegativeWireTotalBillsZeroThroughTheRealParsePath(): void
    {
        // Routing falsifier for Custom, the twin of the sglang one: revert
        // parseResponse to its old inline `?? 0` read and this goes red
        // (-9 passes through), because once every number leaves through the
        // parsed Usage, Usage::new()'s clamp owns it.
        $body = '{"choices":[{"message":{"content":"ok"}}],'
            . '"usage":{"prompt_tokens":-5,"total_tokens":-9,"completion_tokens":-4}}';
        $response = $this->p4s2CustomRespondingWith($body)->complete(new CompleteRequest(
            model: 'm',
            messages: [new UserMessage('x')],
        ));

        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testP4S2OpenAiNegativeWireTotalsBillZeroThroughTheRealParsePath(): void
    {
        // Routing falsifier for OpenAI: the DTO passes signed ints through
        // untouched, so the old inline read would have billed -9.
        [$provider, $response] = $this->p4s2OpenAiCompleteWith([
            'prompt_tokens' => -5,
            'completion_tokens' => -4,
            'total_tokens' => -9,
        ]);

        $this->assertSame(0, $response->tokensUsed, 'negative totals route through Usage::new clamps');
        $this->assertSame(0.0, $response->costUsd, 'a negative computed price clamps too - a turn is never credited');

        $usage = $provider->parseUsage([
            'prompt_tokens' => -5,
            'completion_tokens' => -4,
            'total_tokens' => -9,
        ]);
        $this->assertSame(0, $usage->inputTokens, 'reported negative = reported zero, not unreported null');
    }

    public function testP4S2CustomMirrorsTheFamilyParseOnTheProbedShape(): void
    {
        // LIVE-PROBE payload by family: CustomProvider fronts any
        // OpenAI-compatible server - frequently, its own class docblock says,
        // exactly the sglang deployment probed above - so the probed body is
        // the real-shaped fixture for it.
        $document = json_decode(self::SGLANG_PROBE_COLD, true, flags: JSON_THROW_ON_ERROR);
        $provider = $this->p4s2CustomRespondingWith(self::SGLANG_PROBE_COLD);

        $response = $provider->complete(new CompleteRequest(
            model: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            messages: [new UserMessage('What fruit am I thinking of?')],
        ));
        $this->assertSame(99, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);

        $usage = $provider->parseUsage($document['usage']);
        $this->assertSame(99, $usage->totalTokens);
        $this->assertSame(75, $usage->inputTokens);
        $this->assertSame(24, $usage->outputTokens);
        $this->assertNull($usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens);
    }

    public function testP4S2CustomParsesCachedTokensWhenTheFrontedServerReportsThem(): void
    {
        $document = json_decode(self::SGLANG_PROBE_CACHED, true, flags: JSON_THROW_ON_ERROR);
        $reported = $document['usage'];
        $reported['prompt_tokens_details'] = ['cached_tokens' => 1152];

        $usage = $this->p4s2CustomRespondingWith('{"usage":{}}')
            ->parseUsage($reported);

        $this->assertSame(106, $usage->inputTokens);
        $this->assertSame(1152, $usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens, 'no cache-creation field exists on this family - Custom must not invent one');
    }

    public function testP4S2OpenAiSplitsPromptTokensDetailsAcrossBuckets(): void
    {
        // VENDORED-SHAPE fixture: every key of this usage object is one the
        // repo's own vendored OpenAI SDK types - CreateResponseUsage
        // documents `prompt_tokens_details?:array{cached_tokens:int}` and
        // CreateResponse passes it through `CreateResponse::toArray()`
        // untouched. Values are realistic gpt-4o cached-prompt figures, not
        // live-captured (no OpenAI credential exists for this plan).
        $usageArray = [
            'prompt_tokens' => 2000,
            'completion_tokens' => 100,
            'total_tokens' => 2100,
            'prompt_tokens_details' => ['cached_tokens' => 1536],
        ];
        [$provider, $response] = $this->p4s2OpenAiCompleteWith($usageArray);

        $this->assertSame(2100, $response->tokensUsed, 'the wire total, exactly as before P4.S2');
        $this->assertEqualsWithDelta(0.0115, $response->costUsd, 0.0000001, 'pricing is (2000*0.005 + 100*0.015)/1000, unchanged - cache-aware pricing is a REPORTED follow-up, not this step');

        $usage = $provider->parseUsage($usageArray);
        $this->assertSame(2100, $usage->totalTokens);
        $this->assertSame(464, $usage->inputTokens, '2000 prompt - 1536 cached = the fresh remainder');
        $this->assertSame(100, $usage->outputTokens);
        $this->assertSame(1536, $usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens, 'OpenAI prompt caching has no separately-counted write - the field does not exist and must not be invented');
        $this->assertEqualsWithDelta(0.0115, $usage->costUsd, 0.0000001, 'the parsed Usage carries the SAME cost the response does - one source, no drift');
    }

    public function testP4S2OpenAiWithoutPromptTokenDetailsReportsNoCache(): void
    {
        // Both polarities of the details key, through the REAL SDK DTO - the
        // difference between the two rows is the difference between "the
        // server sent no details" and "the server sent details and said
        // zero cache reads", and the SDK itself draws it: `toArray()` emits
        // `prompt_tokens_details` only when the response carried it.
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');

        $bare = $provider->parseUsage([
            'prompt_tokens' => 30,
            'completion_tokens' => 10,
            'total_tokens' => 40,
        ]);
        $this->assertNull($bare->cacheReadTokens, 'no details key = unreported, not zero');
        $this->assertSame(30, $bare->inputTokens, 'with no cache reported, the whole prompt is fresh input');

        // Details present but WITHOUT the member: the SDK's own
        // CreateResponseUsagePromptTokensDetails::from() coerces that to
        // cached_tokens 0 - a reported measurement, so parseUsage must hand
        // the zero through as zero. Feeding what THAT class actually emits:
        $sdkEmitted = \OpenAI\Responses\Chat\CreateResponseUsage::from([
            'prompt_tokens' => 30,
            'completion_tokens' => 10,
            'total_tokens' => 40,
            'prompt_tokens_details' => [],
        ])->toArray();
        $this->assertSame(
            ['cached_tokens' => 0],
            $sdkEmitted['prompt_tokens_details'],
            'fixture: the vendored SDK really coerces an empty details object to a measured zero',
        );

        $zero = $provider->parseUsage($sdkEmitted);
        $this->assertSame(0, $zero->cacheReadTokens, 'a measured cache-read zero is NOT the unreported null');
        $this->assertSame(30, $zero->inputTokens);
    }

    public function testP4S2OpenAiNullCompletionTokenStaysUnreported(): void
    {
        // The vendored DTO's own return shape types `completion_tokens:
        // int|null` - an explicit null is a real wire possibility and must
        // decode to UNREPORTED, never to a counted zero. (Absent and null
        // arrive at the same bucket: both are "not said".)
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');

        $usage = $provider->parseUsage([
            'prompt_tokens' => 2000,
            'completion_tokens' => null,
            'total_tokens' => 2000,
        ]);

        $this->assertNull($usage->outputTokens);
        $this->assertSame(2000, $usage->inputTokens);
        $this->assertSame(2000, $usage->totalTokens);
        // Cost keeps calculateCost()'s exact prior expression: `?? 0` treats
        // the null as 0 for PRICING while the bucket stays unreported.
        $this->assertEqualsWithDelta(0.010, $usage->costUsd, 0.0000001);
    }

    public function testP4S2BedrockMapsBothCacheSidesFromTheVendoredShape(): void
    {
        // VENDORED-SHAPE fixture: the five usage keys are MEASURED from this
        // repo's own vendor/aws/.../bedrock-runtime/2023-09-30/api-2.json.php
        // shape `TokenUsage` {inputTokens, outputTokens, totalTokens,
        // cacheReadInputTokens, cacheWriteInputTokens, cacheDetails}. This is
        // the only provider whose wire reports BOTH cache sides, so it is the
        // only one whose four-bucket prompt identity becomes computable.
        // totalTokens is deliberately a THIRD number (2500 != 1200+300):
        // complete() still bills input+output as it always has, and the
        // fixture pins that the wire total is NOT silently preferred -
        // switching the billable total is a pricing-visible change outside
        // this step and REPORTED as such.
        $usageArray = [
            'inputTokens' => 1200,
            'outputTokens' => 300,
            'totalTokens' => 2500,
            'cacheReadInputTokens' => 8000,
            'cacheWriteInputTokens' => 1234,
            // The two cache-WRITE-side numbers are DELIBERATELY distinct:
            // cacheCreationTokens must source ONLY from cacheWriteInputTokens
            // (parseUsage reads cacheDetails nowhere - it has no Usage bucket),
            // and equal values here would let a future mis-wire to the TTL
            // split pass on coincidence instead of on the right field.
            'cacheDetails' => ['ephemeral5mInputTokens' => 99],
        ];
        $provider = $this->p4s2BedrockUnaryWith($usageArray);

        $response = $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));
        $this->assertSame(1500, $response->tokensUsed, 'the preserved input+output expression, NOT the wire 2500');
        $this->assertEqualsWithDelta(0.0081, $response->costUsd, 0.0000001, '(1200*0.003 + 300*0.015)/1000 - pricing left exactly as before');

        $usage = $provider->parseUsage($usageArray, 'anthropic.claude-sonnet-4-6');
        $this->assertSame(1200, $usage->inputTokens, 'Bedrock follows the Anthropic convention - inputTokens is the fresh side, no subtraction');
        $this->assertSame(300, $usage->outputTokens);
        $this->assertSame(8000, $usage->cacheReadTokens);
        $this->assertSame(1234, $usage->cacheCreationTokens, 'a cache WRITE is what Usage calls cache CREATION');
        $this->assertSame(10434, $usage->promptTokens(), 'the one provider family here where total = cacheRead + cacheCreation + input has all three sides reported');
    }

    public function testP4S2BedrockStreamMetadataSharesTheSameParse(): void
    {
        // The step brief's named trap: "a bucket wired only in complete()
        // reads zero on the live streamed path while non-streaming tests stay
        // green". The vendored API definition binds
        // `ConverseStreamMetadataEvent.usage` to the SAME `TokenUsage` shape
        // (measured from api-2.json.php), so this drives the stream arm with
        // cache fields present and pins they are parsed there too.
        $usageArray = [
            'inputTokens' => 1200,
            'outputTokens' => 300,
            'totalTokens' => 2500,
            'cacheReadInputTokens' => 8000,
            'cacheWriteInputTokens' => 1200,
        ];
        $provider = $this->p4s2BedrockStreamWith($usageArray);

        $content = '';
        $tokens = 0;
        $cost = 0.0;
        $usageEvents = 0;
        foreach ($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        )) as $chunk) {
            $content .= $chunk->content;
            $tokens += $chunk->tokensUsed;
            $cost += $chunk->costUsd;
            if ($chunk->tokensUsed > 0) {
                $usageEvents++;
            }
        }

        $this->assertSame('Hello', $content);
        $this->assertSame(1, $usageEvents, 'usage lands exactly once, on the terminal metadata event');
        $this->assertSame(1500, $tokens, 'the same preserved input+output expression on the stream arm');
        $this->assertEqualsWithDelta(0.0081, $cost, 0.0000001);
    }

    public function testP4S2BedrockWithoutCacheMembersDecodesThemUnreported(): void
    {
        $provider = new BedrockProvider(
            $this->p4s2BedrockClient([]),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $usage = $provider->parseUsage(
            ['inputTokens' => 10, 'outputTokens' => 5],
            'anthropic.claude-sonnet-4-6',
        );
        $this->assertNull($usage->cacheReadTokens, 'no cache members = the provider said nothing about cache');
        $this->assertNull($usage->cacheCreationTokens);
        $this->assertSame(15, $usage->totalTokens);
        $this->assertNull($usage->promptTokens(), 'promptTokens refuses across unreported cache buckets - measured zero and unreported must stay distinguishable end to end');
    }

    public function testP4S2BedrockNegativeWireSidesBillZeroThroughTheRealParsePath(): void
    {
        // Routing falsifier for Bedrock: the old inline parse summed the two
        // signed ints (-15) and passed the negative through CompleteResponse;
        // routed through the parsed Usage, the clamp owns it.
        $provider = $this->p4s2BedrockUnaryWith(['inputTokens' => -10, 'outputTokens' => -5]);

        $response = $provider->complete(new CompleteRequest(
            model: 'anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));

        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd, 'the negative computed price clamps too');
    }

    public function testP4S2VertexAnthropicUnaryMapsBothCacheSides(): void
    {
        // UNVERIFIED-DOCUMENTED fixture, labelled per the brief's fallback
        // rule: `cache_read_input_tokens` / `cache_creation_input_tokens` are
        // the published Anthropic Messages-API field names (the same pair the
        // vendored Bedrock shape carries in AWS camelCase), and this repo
        // vendors no Anthropic SDK to check them against; Vertex rawPredict
        // passes the native document through verbatim.
        $usageArray = [
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_read_input_tokens' => 7000,
            'cache_creation_input_tokens' => 900,
        ];
        $provider = $this->p4s2VertexWith(
            ['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => $usageArray],
            'claude-3-sonnet@20240229',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        ));
        $this->assertSame(15, $response->tokensUsed, 'input+output preserved; whether cache belongs in the billable total is the REPORTED pricing question, not this step');

        $usage = $provider->parseAnthropicUsage($usageArray, 'claude-3-sonnet@20240229');
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertSame(7000, $usage->cacheReadTokens);
        $this->assertSame(900, $usage->cacheCreationTokens);
        $this->assertSame(7910, $usage->promptTokens());
    }

    public function testP4S2VertexAnthropicNegativeWireSidesBillZeroThroughTheRealParsePath(): void
    {
        // Routing falsifier for the Vertex Anthropic arm: the old inline
        // `(int)(...) + (int)(...)` billed -15 straight through.
        $provider = $this->p4s2VertexWith(
            ['content' => [['type' => 'text', 'text' => 'Hi']], 'usage' => ['input_tokens' => -10, 'output_tokens' => -5]],
            'claude-3-sonnet@20240229',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        ));

        // tokensUsed is the discriminating assertion; cost stays 0.0 either
        // way because Vertex's rate table is a placeholder 0.0 - said plainly
        // so no reader grades that line as a falsifier.
        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testP4S2VertexAnthropicStreamParsesCacheOnMessageStartKeepingPerDeltaSplit(): void
    {
        // UNVERIFIED-DOCUMENTED fixture (same labelling as the unary case).
        // P1.S5's streamed-Usage contract is the constraint here: the split
        // events must stay per-delta - `message_start` bills input only,
        // `message_delta` output only - because Runtime SUMS them. P4.S2
        // widens only what each event's usage DOCUMENT feeds the parse.
        $startUsage = [
            'input_tokens' => 12,
            'cache_read_input_tokens' => 6400,
            'cache_creation_input_tokens' => 500,
        ];
        $provider = $this->p4s2VertexStreamerWith([
            ['type' => 'message_start', 'message' => ['usage' => $startUsage]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']],
            ['type' => 'message_delta', 'usage' => ['output_tokens' => 4]],
            ['type' => 'message_stop'],
        ], 'claude-3-sonnet@20240229');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        )));

        $usageChunks = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed !== 0));
        $this->assertCount(2, $usageChunks, 'exactly the two split events bill, the text deltas do not');
        $this->assertSame([12, 4], array_map(static fn (CompleteResponse $c): int => $c->tokensUsed, $usageChunks));

        $parsed = $provider->parseAnthropicUsage($startUsage, 'claude-3-sonnet@20240229');
        $this->assertSame(6400, $parsed->cacheReadTokens, 'the stream arm reports cache too - a bucket wired only on the unary path is the failure mode this case exists to red');
        $this->assertSame(500, $parsed->cacheCreationTokens);
    }

    public function testP4S2VertexAnthropicAllCachedMessageStartStillDropsLikeAlways(): void
    {
        // Recorded honestly: `message_start` with input_tokens 0 and a
        // positive cache read - every prompt token served from cache - still
        // yields NO usage event, exactly as an input-less start did before
        // this step existed. Changing that gate changes P1.S5-pinned
        // emission semantics, and with CompleteResponse carrying no Usage
        // yet, the event would bill zero tokens anyway. The case is pinned
        // so the day the carrier widens, this test names the gate to revisit.
        $provider = $this->p4s2VertexStreamerWith([
            ['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 0,
                'cache_read_input_tokens' => 9000,
            ]]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']],
            ['type' => 'message_delta', 'usage' => ['output_tokens' => 3]],
            ['type' => 'message_stop'],
        ], 'claude-3-sonnet@20240229');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'claude-3-sonnet@20240229',
            messages: [new UserMessage('hi')],
        )));

        $usageChunks = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed !== 0));
        $this->assertCount(1, $usageChunks);
        $this->assertSame(3, $usageChunks[0]->tokensUsed, 'only the message_delta bills; the zero-input start is dropped as it always was');
    }

    public function testP4S2VertexGeminiSubtractsLocallyProvenCacheSubset(): void
    {
        // VENDORED-SHAPE fixture: `cachedContentTokenCount` and its SUBSET
        // semantics are MEASURED from this repo's vendored proto
        // (GenerateContentResponse/UsageMetadata.php field comment: "Number
        // of tokens in the cached part in the input"). totalTokenCount 9999
        // is a deliberate third number - the arm still refuses to read it
        // (thinking tokens make it disagree with the priced pair;
        // VertexProviderTest pins that refusal at 99).
        $usageArray = [
            'promptTokenCount' => 1000,
            'candidatesTokenCount' => 50,
            'totalTokenCount' => 9999,
            'cachedContentTokenCount' => 750,
        ];
        $provider = $this->p4s2VertexWith(
            ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]], 'usageMetadata' => $usageArray],
            'gemini-1.5-pro-002',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'gemini-1.5-pro-002',
            messages: [new UserMessage('hi')],
        ));
        $this->assertSame(1050, $response->tokensUsed, 'prompt + candidates, totalTokenCount unread - the preserved expression');

        $usage = $provider->parseUsageMetadata($usageArray, 'gemini-1.5-pro-002');
        $this->assertSame(250, $usage->inputTokens, '1000 - 750: the proto PROVES cached is a part of the prompt, so the Anthropic direct-map would double-count here');
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(750, $usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens, 'the proto has no cache-creation token member (explicit caching bills stored content by time) - recorded, not invented');
        $this->assertNull($usage->promptTokens());
    }

    public function testP4S2VertexGeminiWithoutCachedFieldLeavesItUnreported(): void
    {
        $provider = $this->p4s2VertexWith([], 'gemini-1.5-pro-002');
        $usage = $provider->parseUsageMetadata(
            ['promptTokenCount' => 11, 'candidatesTokenCount' => 5],
            'gemini-1.5-pro-002',
        );

        $this->assertSame(16, $usage->totalTokens);
        $this->assertSame(11, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertNull($usage->cacheReadTokens);
        $this->assertNull($usage->cacheCreationTokens);
    }

    public function testP4S2VertexGeminiNegativeWireCountsBillZeroThroughTheRealParsePath(): void
    {
        // Routing falsifier for the Gemini arm: the relocated `geminiUsage()`
        // pair summed signed ints straight into tokensUsed (-15 old); routed
        // through the parsed Usage the clamp owns it, and cost() receives the
        // same signed values today's expression would have.
        $provider = $this->p4s2VertexWith(
            ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
             'usageMetadata' => ['promptTokenCount' => -10, 'candidatesTokenCount' => -5]],
            'gemini-1.5-pro-002',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'gemini-1.5-pro-002',
            messages: [new UserMessage('hi')],
        ));

        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testP4S2VertexGeminiFullyCachedPromptStillParksItsUsage(): void
    {
        // The park-gate regression pin: prompt 10 of which ALL 10 are cached
        // (fresh input therefore 0, candidates 0 mid-stream). The OLD gate
        // parked on the raw wire fields (prompt != 0); a gate re-derived on
        // the NEW subtracted inputTokens bucket would silently stop parking
        // - the turn's usage vanishes while every test that never sends an
        // all-cached prefix stays green. This case exists to red that.
        $provider = $this->p4s2VertexStreamerWith([
            ['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]],
             'usageMetadata' => ['promptTokenCount' => 10, 'cachedContentTokenCount' => 10, 'candidatesTokenCount' => 0]],
        ], 'gemini-1.5-pro-002');

        $chunks = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'gemini-1.5-pro-002',
            messages: [new UserMessage('hi')],
        )));

        $this->assertCount(2, $chunks, 'the text delta AND the parked usage response');
        $this->assertSame('hi', $chunks[0]->content);
        $this->assertSame(10, $chunks[1]->tokensUsed, 'the all-cached turn still bills its 10 prompt tokens through the wire-total gate');
    }

    public function testP4S2VertexLegacyArmRecordsThatNoUsageObjectArrives(): void
    {
        // The third Vertex arm, recorded honestly rather than invented past:
        // `publishers/google` `:predict` (PaLM-era chat-bison) answers with a
        // predictions document this provider parses with NO usage read at
        // all - the document itself carries none - so there is no cache
        // field, and no bucket, to parse here. The step rule "a provider
        // whose API reports no cache fields is a legitimate outcome: record
        // it, do not invent a field" applies with full force.
        $provider = $this->p4s2VertexWith(
            ['predictions' => [['content' => 'sure', 'safetyAttributes' => ['blocked' => false]]]],
            'chat-bison@002',
        );

        $response = $provider->complete(new CompleteRequest(
            model: 'chat-bison@002',
            messages: [new UserMessage('hi')],
        ));

        $this->assertSame('sure', $response->content, 'fixture: the legacy arm really parsed');
        $this->assertSame(0, $response->tokensUsed);
        $this->assertSame(0.0, $response->costUsd);
    }

    public function testP4S2EveryParseOfAnEmptyUsageObjectIsAllUnreported(): void
    {
        // The null half of every polarity pair, one sweep: an absent/empty
        // usage object must produce total 0 (the pre-P4.S2 `?? 0` behaviour
        // CompleteResponse has always carried) beside FOUR unreported
        // buckets - never four zeroes. A single fabricated zero here would
        // make "nothing reported" and "measured nothing" indistinguishable
        // on the very next seam up.
        $sglang = SglangProvider::openAiCompatible('https://api.example.com')->parseUsage([]);
        $custom = $this->p4s2CustomRespondingWith('{"usage":{}}')->parseUsage([]);
        $openai = new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o');
        $oa = $openai->parseUsage([]);
        $vx = $this->p4s2VertexWith([], 'claude-3-sonnet@20240229');
        $anth = $vx->parseAnthropicUsage([], 'claude-3-sonnet@20240229');
        $gem = $vx->parseUsageMetadata([], 'gemini-1.5-pro-002');

        foreach (['sglang' => $sglang, 'custom' => $custom, 'openai' => $oa, 'bedrock-empty' => (new BedrockProvider($this->p4s2BedrockClient([]), 'us-east-1', 'anthropic.claude-sonnet-4-6'))->parseUsage([], 'anthropic.claude-sonnet-4-6'), 'vertex-anthropic' => $anth, 'vertex-gemini' => $gem] as $name => $usage) {
            $this->assertSame(0, $usage->totalTokens, "{$name}: empty usage totals zero, the pre-P4.S2 expression");
            $this->assertSame(0.0, $usage->costUsd, "{$name}: empty usage costs nothing");
            $this->assertNull($usage->inputTokens, "{$name}: unreported input");
            $this->assertNull($usage->outputTokens, "{$name}: unreported output");
            $this->assertNull($usage->cacheReadTokens, "{$name}: unreported cache read - null, never zero");
            $this->assertNull($usage->cacheCreationTokens, "{$name}: unreported cache creation");
            $this->assertNull($usage->promptTokens(), "{$name}: the identity refuses across unreported buckets");
        }
    }

    public function testP4S2ReportedZeroIsDistinctFromUnreportedAcrossTheCacheReadBucket(): void
    {
        // ...and the non-null half of the same pair: a server that REPORTS
        // zero cache reads is making a measurement, and the bucket must hold
        // 0 where every fixture above holds null. These two asserts in one
        // test are the whole point of Usage's null-vs-zero split, first
        // reachable through real provider parses by this step.
        $sglang = SglangProvider::openAiCompatible('https://api.example.com')->parseUsage([
            'prompt_tokens' => 100,
            'total_tokens' => 110,
            'completion_tokens' => 10,
            'prompt_tokens_details' => ['cached_tokens' => 0],
        ]);
        $this->assertSame(0, $sglang->cacheReadTokens, 'reported zero stays zero');
        $this->assertSame(100, $sglang->inputTokens, 'prompt - 0 cached = the whole prompt is genuinely fresh');

        $missing = SglangProvider::openAiCompatible('https://api.example.com')->parseUsage([
            'prompt_tokens' => 100,
            'total_tokens' => 110,
            'completion_tokens' => 10,
        ]);
        $this->assertNull($missing->cacheReadTokens, 'and the same parse without the key says NOTHING, which is a different claim');
    }

    // ---- P4.S2 fixture builders (transport mocks in the shapes the provider's own suites use) ----

    private function p4s2SglangRespondingWith(string $body): SglangProvider
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $body));

        return new SglangProvider('https://api.example.com', 'deepseek-ai/DeepSeek-V4-Flash-0731', null, $httpClient);
    }

    private function p4s2CustomRespondingWith(string $body): CustomProvider
    {
        $httpClient = $this->createMock(Client::class);
        $httpClient->method('post')->willReturn(new Response(200, [], $body));

        return new CustomProvider('probe-family', 'https://api.example.com', 'test-model', null, $httpClient, true, true);
    }

    /**
     * @param array<string, mixed> $usageArray
     * @return array{0: OpenAIProvider, 1: CompleteResponse}
     */
    private function p4s2OpenAiCompleteWith(array $usageArray): array
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);
        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-p4s2',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => $usageArray,
        ], MetaInformation::from([])));

        $provider = new OpenAIProvider($client, 'gpt-4o');

        return [$provider, $provider->complete(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('hi')],
        ))];
    }

    /** @param array<string, mixed> $usage */
    private function p4s2BedrockClient(array $usage): \Aws\BedrockRuntime\BedrockRuntimeClient
    {
        $mock = new \Aws\MockHandler();
        $mock->append(new \Aws\Result([
            'output' => ['message' => [
                'role' => 'assistant',
                'content' => [['text' => 'Hello']],
            ]],
            'stopReason' => 'end_turn',
            'usage' => $usage,
        ]));

        return new \Aws\BedrockRuntime\BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $mock,
        ]);
    }

    /** @param array<string, mixed> $usage */
    private function p4s2BedrockUnaryWith(array $usage): BedrockProvider
    {
        return new BedrockProvider($this->p4s2BedrockClient($usage), 'us-east-1', 'anthropic.claude-sonnet-4-6');
    }

    /** @param array<string, mixed> $usage */
    private function p4s2BedrockStreamWith(array $usage): BedrockProvider
    {
        $mock = new \Aws\MockHandler();
        $mock->append(new \Aws\Result(['stream' => new \ArrayIterator([
            ['messageStart' => ['role' => 'assistant']],
            ['contentBlockDelta' => ['delta' => ['text' => 'Hello'], 'contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => $usage]],
        ])]));

        return new BedrockProvider(
            new \Aws\BedrockRuntime\BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                'handler' => $mock,
            ]),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );
    }

    /**
     * @param array<string, mixed> $response the decoded document the seam answers with
     */
    private function p4s2VertexWith(array $response, string $model): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static fn (): array => $response,
        );
    }

    /** @param array<int, array<string, mixed>> $events */
    private function p4s2VertexStreamerWith(array $events, string $model): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: static fn (): array => [],
            streamer: static function () use ($events): \Generator {
                yield from $events;
            },
        );
    }
}

/**
 * A batch provider that answers a scripted sequence of responses, one per
 * `complete()` call, so an agentic turn's successive steps can be given
 * different usage figures. Records how many calls it took, because "the loop
 * really ran twice" is a fixture claim the sum test depends on.
 */
final class ScriptedUsageProvider implements ProviderInterface
{
    private int $calls = 0;

    /** @param list<array{response:CompleteResponse}> $script */
    public function __construct(private readonly array $script) {}

    public function calls(): int
    {
        return $this->calls;
    }

    public function name(): string
    {
        return 'scripted-usage';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $step = $this->script[$this->calls] ?? null;
        ++$this->calls;

        return $step['response'] ?? new CompleteResponse(content: 'script exhausted');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield $this->complete($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}

/**
 * A streaming provider that yields a fixed list of chunks, so usage can be
 * placed on whichever chunks a test wants — including on a content-free chunk,
 * which is exactly how the real Anthropic-shaped SSE streams report it.
 */
final class ChunkedUsageProvider implements ProviderInterface
{
    /** @param list<CompleteResponse> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function name(): string
    {
        return 'chunked-usage';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'batch');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}
