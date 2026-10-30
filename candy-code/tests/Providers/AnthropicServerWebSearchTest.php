<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\WebSearch;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolLimits;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tools\Web\Search\SearchBackends;
use SugarCraft\Code\Usage;

/**
 * C-TOOL-2.c: the Anthropic server-side `web_search` backend.
 *
 * The laws pinned here, in order: the tool-swap is settings-driven and
 * in-place (the prompt-cache shape — see PromptPrefixByteStabilityTest's
 * companion leg); the server's own blocks (`server_tool_use`,
 * `web_search_tool_result`) NEVER surface as client tool calls; a
 * `pause_turn` is answered by resending the partial assistant reply
 * VERBATIM, bounded by a leg COUNT and never by a clock (E646); the reply
 * replays as plain text (no citation blocks are stored to re-send); the
 * search count rides Usage as an unpriced extra so a server-searched turn's
 * recorded spend is a lower bound; and off the native wire the client
 * `WebSearch` tool refuses with a named reason instead of dialling.
 */
final class AnthropicServerWebSearchTest extends TestCase
{
    use HomeSandboxTrait;

    private const MODEL = 'claude-sonnet-4-6';
    private const URL_ONE = 'https://example.invalid/one';
    private const URL_TWO = 'https://example.invalid/two';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const SEARCH_ENV_KEYS = ['CANDY_CODE_SEARCH_ENDPOINT', 'BRAVE_SEARCH_API_KEY', 'TAVILY_API_KEY', 'EXA_API_KEY'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_asws_' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->dir . '/home');
        foreach (self::SEARCH_ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::SEARCH_ENV_KEYS as $key) {
            $saved = $this->savedEnv[$key];
            putenv($saved === false ? $key : $key . '=' . $saved);
        }
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheServerToolSwapsTheWebSearchSlotOnlyUnderTheAnthropicBackend(): void
    {
        $tools = [self::plainTool('Alpha'), new WebSearch(), self::plainTool('Zeta')];

        // Backend=anthropic: the local declaration is replaced IN PLACE —
        // same slot, same list length — with the versioned server tool whose
        // max_uses rides the webSearchMaxResults setting.
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 7,
        ]);
        $swapped = [];
        self::anthropicProvider([self::plainDoc()], $swapped)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: $tools));

        $sent = $swapped[0][1]['tools'];
        self::assertSame(['Alpha', 'web_search', 'Zeta'], array_map(self::toolName(...), $sent));
        self::assertSame(
            ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 7],
            $sent[1],
        );

        // Backend=searxng (or anything else): byte-identical pre-C-TOOL-2.c
        // shape — the client declaration ships, no server entry appears.
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_SEARXNG]);
        $plain = [];
        self::anthropicProvider([self::plainDoc()], $plain)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: $tools));

        self::assertSame(['Alpha', 'WebSearch', 'Zeta'], array_map(self::toolName(...), $plain[0][1]['tools']));

        // And the swap is a replacement, never an injection: a tool list
        // without WebSearch stays exactly as long under the same settings.
        $lean = [];
        self::anthropicProvider([self::plainDoc()], $lean)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [self::plainTool('Alpha')]));

        self::assertSame(['Alpha'], array_map(self::toolName(...), $lean[0][1]['tools']));
        self::assertStringNotContainsString('web_search', json_encode($lean[0][1]['tools']));
    }

    public function testServerBlocksNeverSurfaceAsClientToolCallsAndTheSourcesRideTheTicker(): void
    {
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 4,
        ]);

        $doc = self::messageDoc([
            self::serverUseBlock(),
            self::searchResultBlock([self::URL_ONE, self::URL_ONE, self::URL_TWO]),
            ['type' => 'text', 'text' => 'the searched answer'],
            ['type' => 'tool_use', 'id' => 'tu_9', 'name' => 'Edit', 'input' => ['path' => 'Alpha.php']],
        ], 'end_turn', 2);

        $captured = [];
        $response = self::anthropicProvider([$doc], $captured)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()]));

        self::assertNotNull($response->toolCalls);
        self::assertSame(
            ['Edit'],
            array_map(static fn ($call): string => $call->name(), $response->toolCalls),
            'the server called its OWN tool; only the client tool_use reaches the loop',
        );
        self::assertSame('the searched answer', $response->content);
        self::assertSame(
            'Sources: ' . self::URL_ONE . ', ' . self::URL_TWO
            . ' — 2 provider-side web searches.'
            . ' The searches are billed by the provider outside the price table on file here,'
            . ' so the recorded session spend is a lower bound (>=).',
            $response->reasoning,
            'the URLs fold into the display-only ticker, never into the replayed answer',
        );
        self::assertSame(2, $response->usage?->webSearchRequests);
        self::assertCount(1, $captured, 'end_turn is terminal; no continuation was dialled');
    }

    public function testAPausedTurnResendsThePartialReplyVerbatimAndMergesTheLegs(): void
    {
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 5,
        ]);

        $leg1 = self::messageDoc([
            self::serverUseBlock(),
            self::searchResultBlock([self::URL_ONE]),
            ['type' => 'text', 'text' => 'still searching —'],
        ], 'pause_turn', 1);
        $leg2 = self::messageDoc([['type' => 'text', 'text' => 'and here is the answer']], 'end_turn', 1);

        $captured = [];
        $response = self::anthropicProvider([$leg1, $leg2], $captured)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()]));

        self::assertCount(2, $captured, 'one pause costs exactly one continuation');
        $first = $captured[0][1]['messages'];
        $resume = $captured[1][1]['messages'];
        self::assertSame($first, array_slice($resume, 0, count($first)), 'the original turns ride untouched');

        $partial = $resume[count($resume) - 1];
        self::assertSame('assistant', $partial['role']);
        self::assertEquals(
            $leg1['content'],
            $partial['content'],
            'the partial assistant reply is re-sent VERBATIM — server blocks included, they are the receipt of what was searched',
        );

        self::assertFalse($response->isError);
        self::assertSame(
            'still searching —and here is the answer',
            $response->content,
            'the resumed text CONTINUES the answer — no separator is invented',
        );
        self::assertSame(30, $response->usage?->totalTokens, 'both legs are billed, summed');
        self::assertSame(2, $response->usage?->webSearchRequests, 'one search per leg, summed across the merged doc');
        self::assertSame(
            'Sources: ' . self::URL_ONE
            . ' — 2 provider-side web searches.'
            . ' The searches are billed by the provider outside the price table on file here,'
            . ' so the recorded session spend is a lower bound (>=).',
            $response->reasoning,
            'exactly one Sources notice for the whole merged turn',
        );
    }

    public function testTheContinuationIsBoundedByLegCountNotAClock(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC]);

        // The provider pauses FOREVER: the seam replays the same paused leg.
        // The step must still terminate — at the leg budget — with the
        // shortfall disclosed, never by a wall-clock kill (E646).
        $always = self::messageDoc([
            self::serverUseBlock(),
            ['type' => 'text', 'text' => 'again'],
        ], 'pause_turn', 1);

        $captured = [];
        $response = self::anthropicProvider([$always], $captured)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()]));

        self::assertCount(
            1 + AnthropicProvider::MAX_PAUSED_TURN_CONTINUATIONS,
            $captured,
            'the first dial plus the bounded continuation budget, nothing more',
        );
        self::assertFalse($response->isError, 'a capped answer is still an answer');
        self::assertSame(str_repeat('again', 9), $response->content);
        self::assertStringContainsString(
            'The provider kept pausing the turn for more searches and the continuation limit of 8 was reached; this answer may be incomplete.',
            (string) $response->reasoning,
        );
        self::assertStringContainsString('9 provider-side web searches', (string) $response->reasoning);
    }

    public function testAServedReplyReplaysAsTextOnlyWithNoServerBlocksAnywhere(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC]);

        // A reply the server produced WITH web search is stored as its plain
        // text (the Sources line rode the display-only ticker, not the
        // content), so a later step replays exactly that text — the design's
        // "history replays text only" law, pinned on the wire.
        $captured = [];
        self::anthropicProvider([self::plainDoc()], $captured)->complete(new CompleteRequest(
            model: self::MODEL,
            messages: [new UserMessage('q'), new AssistantMessage('the answer lands here'), new UserMessage('more')],
            tools: [new WebSearch()],
        ));

        $wire = json_encode($captured[0][1]['messages']);
        self::assertStringContainsString('the answer lands here', $wire);
        self::assertStringNotContainsString('server_tool_use', $wire);
        self::assertStringNotContainsString('web_search_tool_result', $wire);
    }

    public function testTheAnthropicBackendRefusesClientDialsOffTheNativeWire(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC]);

        $backend = SearchBackends::make(SearchBackends::BACKEND_ANTHROPIC, null, 30);
        self::assertSame(SearchBackends::BACKEND_ANTHROPIC, $backend->name());
        self::assertFalse($backend->isConfigured());

        // The tool takes the existing not-configured refusal arm — a /websearch
        // or an off-Anthropic provider's WebSearch call explains itself and
        // dials NOTHING.
        $result = (new WebSearch(transport: self::neverTransport()))
            ->execute(['query' => 'php', 'description' => 't']);

        self::assertTrue($result->isError());
        self::assertSame($backend->configurationRefusal(), $result->content());
        self::assertStringContainsString('searxng, brave, tavily or exa', $result->content());
        self::assertSame($backend->configurationRefusal(), $backend->search('php', null, null, 5)->error);
    }

    public function testAStreamedPauseArrivesAsOneTerminalFrameWithNoDoubleCount(): void
    {
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 6,
        ]);

        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srvu_1', 'name' => 'web_search', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"php releases"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => self::searchResultBlock([self::URL_ONE])],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'pre — ']],
            ['type' => 'content_block_stop', 'index' => 2],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'pause_turn'], 'usage' => ['output_tokens' => 5, 'input_tokens' => 10, 'server_tool_use' => ['web_search_requests' => 1]]],
            ['type' => 'message_stop'],
        ];
        $continuation = self::messageDoc([['type' => 'text', 'text' => 'post-continuation answer']], 'end_turn', 1);

        $streamBodies = [];
        $unary = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODEL,
            self::dialScript([$continuation], $unary),
            self::streamScript($events, $streamBodies),
        );

        $frames = [];
        foreach ($provider->completeStream(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()])) as $frame) {
            $frames[] = $frame;
        }

        $final = $frames[count($frames) - 1];
        self::assertSame('post-continuation answer', $final->content, 'the continuation arrives as ONE terminal frame');
        self::assertStringContainsString('Sources: ' . self::URL_ONE, (string) $final->reasoning);
        self::assertNull($final->toolCalls);
        self::assertSame(1, $final->usage?->webSearchRequests, 'the terminal frame carries only the continuation leg');

        self::assertCount(1, $streamBodies);
        self::assertTrue($streamBodies[0]['stream']);
        self::assertCount(1, $unary, 'exactly one continuation leg was dialled');
        self::assertArrayNotHasKey('stream', $unary[0][1], 'the continuation is fetched unary, not re-streamed');

        $resumeTurns = $unary[0][1]['messages'];
        $streamTurns = $streamBodies[0]['messages'];
        self::assertCount(count($streamTurns) + 1, $resumeTurns);
        $partial = $resumeTurns[count($resumeTurns) - 1];
        self::assertSame('assistant', $partial['role']);
        self::assertEquals(
            [
                ['type' => 'server_tool_use', 'id' => 'srvu_1', 'name' => 'web_search', 'input' => ['query' => 'php releases']],
                self::searchResultBlock([self::URL_ONE]),
                ['type' => 'text', 'text' => 'pre — '],
            ],
            $partial['content'],
            'the streamed partial is reconstructed VERBATIM — server blocks included, never as client calls',
        );

        $carriers = [];
        foreach ($frames as $frame) {
            if ($frame->usage !== null) {
                $carriers[] = $frame->usage;
            }
        }
        self::assertSame(
            2,
            Usage::sum($carriers)->webSearchRequests,
            'the paused leg counted 1 and the continuation 1; summed across frames, no double count',
        );
    }

    public function testAStreamedSearchWithoutAPauseStillClosesWithTheSourcesFrame(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC]);

        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 4, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => self::searchResultBlock([self::URL_ONE])],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ans']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4, 'input_tokens' => 4, 'server_tool_use' => ['web_search_requests' => 1]]],
            ['type' => 'message_stop'],
        ];

        $unary = [];
        $streamBodies = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODEL,
            self::refusingDial($unary),
            self::streamScript($events, $streamBodies),
        );

        $frames = [];
        foreach ($provider->completeStream(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()])) as $frame) {
            $frames[] = $frame;
        }

        self::assertSame([], $unary, 'end_turn is terminal — no continuation is dialled, even though searches ran');

        $final = $frames[count($frames) - 1];
        self::assertSame('', $final->content);
        self::assertStringStartsWith('Sources: ', (string) $final->reasoning);
        self::assertStringContainsString('1 provider-side web search.', (string) $final->reasoning);
        self::assertNull($final->usage, 'the count already rode the delta carrier; the notice frame carries none');

        $carriers = [];
        foreach ($frames as $frame) {
            if ($frame->usage !== null) {
                $carriers[] = $frame->usage;
            }
        }
        self::assertSame(1, Usage::sum($carriers)->webSearchRequests, 'counted exactly once across the stream');
    }

    public function testABatchPausedEchoResendsZeroArgumentToolInputsAsObjects(): void
    {
        Bootstrap::writeUserConfig([
            ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC,
            ToolLimits::WEB_SEARCH_MAX_RESULTS_KEY => 5,
        ]);

        // Under assoc decoding an empty JSON object IS an empty array, and
        // json_encode would resend it as `"input":[]` — rejected as
        // not-an-object. The C-TOOL-2.c fix-round law: every echoed
        // tool_use/server_tool_use with no arguments rides as `"input":{}`,
        // exactly like toolUseFrom() constructs it.
        $leg1 = self::messageDoc([
            ['type' => 'server_tool_use', 'id' => 'srvu_zero', 'name' => 'web_search', 'input' => []],
            ['type' => 'tool_use', 'id' => 'tu_zero', 'name' => 'Alpha', 'input' => []],
            ['type' => 'text', 'text' => 'paused with zero-arg calls'],
        ], 'pause_turn', 1);
        $leg2 = self::messageDoc([['type' => 'text', 'text' => 'done']], 'end_turn');

        $captured = [];
        $response = self::anthropicProvider([$leg1, $leg2], $captured)
            ->complete(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()]));

        self::assertCount(2, $captured);
        $resume = $captured[1][1]['messages'];
        $partial = $resume[count($resume) - 1];
        self::assertSame('assistant', $partial['role']);
        self::assertCount(3, $partial['content'], 'every partial block rides the echo');

        $wire = (string) json_encode($partial['content']);
        self::assertSame(2, substr_count($wire, '"input":{}'), 'both zero-arg blocks echo as empty OBJECTS');
        self::assertStringNotContainsString('"input":[]', $wire, 'an empty list never rides the pause echo');

        // The normalization is echo-shape only — the call still reaches the
        // loop as an ordinary zero-argument client call.
        self::assertNotNull($response->toolCalls);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('Alpha', $response->toolCalls[0]->name());
    }

    public function testAStreamedPausedEchoResendsZeroArgumentInputsAsObjects(): void
    {
        Bootstrap::writeUserConfig([ToolLimits::WEB_SEARCH_BACKEND_KEY => SearchBackends::BACKEND_ANTHROPIC]);

        // Stream side of the same law, on BOTH arms the lane touched: the
        // server_tool_use THIS FILE reconstructs at content_block_stop (its
        // input_json_delta decodes to an empty map) and the RAW wire-decoded
        // blocks a resumed leg re-echoes when the continuation pauses again.
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'server_tool_use', 'id' => 'srvu_zero', 'name' => 'web_search', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'pause_turn'], 'usage' => ['output_tokens' => 5, 'input_tokens' => 10, 'server_tool_use' => ['web_search_requests' => 1]]],
            ['type' => 'message_stop'],
        ];
        $resume1 = self::messageDoc([
            ['type' => 'tool_use', 'id' => 'tu_zero', 'name' => 'Alpha', 'input' => []],
            ['type' => 'text', 'text' => 'second pause'],
        ], 'pause_turn');
        $resume2 = self::messageDoc([['type' => 'text', 'text' => 'final']], 'end_turn');

        $streamBodies = [];
        $unary = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODEL,
            self::dialScript([$resume1, $resume2], $unary),
            self::streamScript($events, $streamBodies),
        );

        $frames = [];
        foreach ($provider->completeStream(new CompleteRequest(model: self::MODEL, messages: [new UserMessage('go')], tools: [new WebSearch()])) as $frame) {
            $frames[] = $frame;
        }

        self::assertCount(2, $unary, 'two pauses cost two continuations');

        $firstEcho = (string) json_encode($unary[0][1]['messages']);
        self::assertSame(1, substr_count($firstEcho, '"input":{}'), 'the reconstructed empty server_tool_use echoes as an object');
        self::assertStringNotContainsString('"input":[]', $firstEcho);

        $secondEcho = (string) json_encode($unary[1][1]['messages']);
        self::assertSame(2, substr_count($secondEcho, '"input":{}'), 'the raw wire-decoded zero-arg tool_use of the resumed leg joins it, one per carried turn');
        self::assertStringNotContainsString('"input":[]', $secondEcho);

        $final = $frames[count($frames) - 1];
        self::assertSame('second pausefinal', $final->content, 'the resumed legs continue the same answer — no separator');
    }

    /**
     * @param list<array<string, mixed>> $responses
     * @param list<array{0: string, 1: array<string, mixed>}> $captured
     */
    private static function anthropicProvider(array $responses, array &$captured): AnthropicProvider
    {
        return new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODEL,
            self::dialScript($responses, $captured),
        );
    }

    /**
     * @param list<array<string, mixed>> $responses
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     */
    private static function dialScript(array $responses, array &$calls): callable
    {
        $index = 0;

        return static function (string $endpoint, array $body) use ($responses, &$calls, &$index): array {
            $calls[] = [$endpoint, $body];
            $response = $responses[min($index, count($responses) - 1)];
            $index++;

            return $response;
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $bodies
     */
    private static function streamScript(array $events, array &$bodies): callable
    {
        return static function (string $endpoint, array $body) use ($events, &$bodies): iterable {
            $bodies[] = $body;
            yield from $events;
        };
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private static function refusingDial(array &$calls): callable
    {
        return static function (string $endpoint, array $body) use (&$calls): array {
            $calls[] = $body;

            throw new \RuntimeException('the unary seam must stay untouched when the stream never paused');
        };
    }

    private static function neverTransport(): callable
    {
        return static function (array $request, string $dial): array {
            throw new \RuntimeException('the refusal happens before any dial');
        };
    }

    /** @return array<string, mixed> */
    private static function plainDoc(): array
    {
        return self::messageDoc([['type' => 'text', 'text' => 'plain turn']], 'end_turn');
    }

    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function messageDoc(array $content, string $stopReason, ?int $searches = null, int $input = 10, int $output = 5): array
    {
        $usage = ['input_tokens' => $input, 'output_tokens' => $output];
        if ($searches !== null) {
            $usage['server_tool_use'] = ['web_search_requests' => $searches];
        }

        return ['content' => $content, 'stop_reason' => $stopReason, 'usage' => $usage];
    }

    /** @return array<string, mixed> */
    private static function serverUseBlock(): array
    {
        return ['type' => 'server_tool_use', 'id' => 'srvu_1', 'name' => 'web_search', 'input' => ['query' => 'php releases']];
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, mixed>
     */
    private static function searchResultBlock(array $urls): array
    {
        $content = [];
        foreach ($urls as $url) {
            $content[] = ['type' => 'web_search_result', 'url' => $url, 'title' => 'A result'];
        }

        return ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvu_1', 'content' => $content];
    }

    /** @param array<string, mixed> $entry */
    private static function toolName(array $entry): string
    {
        return (string) $entry['name'];
    }

    private static function plainTool(string $name): Tool
    {
        return new class($name) implements Tool {
            public function __construct(private readonly string $toolName)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolName . ' fixture';
            }

            /** @return array<string, mixed> */
            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'done');
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
