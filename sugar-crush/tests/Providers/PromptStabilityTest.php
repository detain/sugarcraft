<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * §12 D7 of crush_feat.md: RadixAttention prefix-cache friendliness.
 *
 * SGLang caches KV state keyed on an EXACT token-prefix match. The cache hit
 * therefore survives only as far as the first byte that differs between two
 * turns of the same session — so anything near the START of the prompt that
 * varies per turn (a regenerated timestamp, a re-ordered tool list, a
 * re-serialized schema whose key order shifted) silently costs a full prefill
 * on every turn instead of a decode-only continuation. Nothing observable
 * breaks; it just gets slow and expensive.
 *
 * D7 asks for a golden-byte regression test rather than a code change, so
 * every assertion below reads RAW REQUEST BYTES rather than a `json_decode`d
 * array. Decoding would defeat the purpose twice over: it discards key order
 * (the exact thing under test) and it collapses `{}` and `[]` — the
 * empty-`properties` distinction {@see \SugarCraft\Crush\Providers\Concerns\ToolSchema}
 * normalizes — into the same PHP value.
 *
 * Byte POSITION is asserted alongside byte equality wherever it matters: a
 * chunk that is identical but shifted forward by one byte is, to a prefix
 * cache, entirely uncached.
 *
 * WHY THE REQUESTS BELOW ARE BUILT THE WAY THEY ARE
 * -------------------------------------------------
 * The assembled system prompt never rides inside $messages: its leading
 * system turn is always the prepend buildParams() makes from
 * CompleteRequest::$systemPrompt on both complete() and completeStream().
 * Transcript Role::System notices (park / compaction / tier reminders) DO
 * flow as SystemMessage instances inside history — EngineBackend::
 * toTypedMessages() maps Role::System to SystemMessage, and SglangProvider
 * encodes those as ['role' => 'system', ...] inside the messages array — so
 * a SystemMessage in $messages is a legal live shape, just not one this
 * suite exercises. The tests below deliberately build $messages free of
 * SystemMessage — plain messages with the prompt on $systemPrompt, and
 * SglangProvider::DEFAULT_MODEL wherever a model id is needed (the old
 * 'MiniMax-M2.7' literal additionally 404s against the deployed server) —
 * to pin the shape Runtime::run() actually sends, so the suite guards the
 * wire shape that reaches the cache. self::SYSTEM_PROMPT stands in for the
 * assembled output of Runtime::buildSystemPrompt(); its exact content is
 * irrelevant, only that it is byte-identical across the two turns being
 * compared.
 */
final class PromptStabilityTest extends TestCase
{
    private const SYSTEM_PROMPT = "You are SugarCrush.\n\n<env>\nWorking directory: /repo\n</env>";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @var list<string> Temp dirs created by the environment-block cases. */
    private array $tempDirs = [];

    /** @var list<PromptFixture> Fixture repositories created by the prefix-win case. */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }

        $this->tempDirs = [];

        // Destroyed here rather than at the end of the test body so a FAILING
        // run cleans up exactly like a passing one.
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * A provider wired to a mock transport that will answer `$turns` requests,
     * recording each one so its raw outgoing bytes can be compared.
     */
    private function provider(int $turns = 2, string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): SglangProvider
    {
        $this->history = [];
        // A fresh Response per turn: a single instance's body stream is
        // consumed by the first read and would come back empty on the second.
        $queue = array_map(static fn(): Response => new Response(200, [], $body), range(1, $turns));
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            SglangProvider::DEFAULT_MODEL,
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /** Raw serialized request body of the nth recorded turn, unparsed. */
    private function rawBody(int $turn): string
    {
        return (string) $this->history[$turn]['request']->getBody();
    }

    /**
     * The raw `"messages":[…]` member, sliced out of the body bytes.
     *
     * `temperature` is the key {@see SglangProvider::buildParams()} always
     * emits directly after `messages`, so it is a reliable terminator without
     * having to brace-match through message content.
     */
    private static function messagesBytes(string $raw): string
    {
        $start = strpos($raw, '"messages":');
        $end = strpos($raw, ',"temperature":');
        self::assertIsInt($start);
        self::assertIsInt($end);

        return substr($raw, $start, $end - $start);
    }

    /**
     * The raw `"tools":[…]` member, sliced out of the body bytes.
     *
     * `tools` is emitted last on the batch path and second-to-last on the
     * streaming path (where `stream` follows it), so the slice runs to either
     * the `stream` key or the body's closing brace.
     */
    private static function toolsBytes(string $raw): string
    {
        $start = strpos($raw, '"tools":');
        self::assertIsInt($start);

        $end = strpos($raw, ',"stream":');

        return $end === false
            ? rtrim(substr($raw, $start), '}')
            : substr($raw, $start, $end - $start);
    }

    /**
     * The exact bytes a `system` turn serializes to inside `messages`.
     *
     * The same bytes whether the turn arrives as a SystemMessage instance or
     * via buildParams()' prepend of CompleteRequest::$systemPrompt — both
     * encode the same ordered role/content array — so the helper doubles as
     * the expected leading chunk of the production-shaped wire body.
     */
    private static function systemChunk(string $prompt): string
    {
        // buildParams() hands an ordered PHP array to Guzzle's `json` option,
        // which encodes with default flags — so a plain json_encode() of the
        // same ordered array reproduces the wire bytes exactly.
        return (string) json_encode(['role' => 'system', 'content' => $prompt]);
    }

    // -------------------------------------------------------------------------
    // The cache-critical prefix: system prompt + tool schemas.
    // -------------------------------------------------------------------------

    public function testSystemPromptBytesAndOffsetAreIdenticalAcrossTurns(): void
    {
        $provider = $this->provider();

        $turnOne = [new UserMessage('First question')];
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: $turnOne,
            systemPrompt: self::SYSTEM_PROMPT,
        ));

        // Turn two of the SAME session: the history has grown, but everything
        // ahead of the new messages must be byte-for-byte where it was.
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [
                ...$turnOne,
                new AssistantMessage('First answer'),
                new UserMessage('Second question'),
            ],
            systemPrompt: self::SYSTEM_PROMPT,
        ));

        $chunk = self::systemChunk(self::SYSTEM_PROMPT);
        $first = strpos($this->rawBody(0), $chunk);
        $second = strpos($this->rawBody(1), $chunk);

        $this->assertIsInt($first, 'system turn is not serialized as expected');
        $this->assertSame(
            $first,
            $second,
            'the system prompt moved between turns — identical bytes at a shifted offset are still a cache miss',
        );
    }

    public function testSystemTurnLeadsTheMessageArraySoThereIsAPrefixToShare(): void
    {
        $provider = $this->provider(1);
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: self::SYSTEM_PROMPT,
        ));

        // Anything ahead of the system turn is prompt the cache can never
        // reuse; anything after it only shares a prefix because the system
        // turn itself did not move. The prepend in buildParams() is what puts
        // the system turn first — a SystemMessage inside $messages would have
        // been the OLD shape this suite no longer sends.
        $this->assertStringStartsWith(
            '"messages":[{"role":"system"',
            self::messagesBytes($this->rawBody(0)),
        );
    }

    public function testSystemPromptIsOmittedWhenUnsetOrEmpty(): void
    {
        // Negative polarity of the prepend guard (§16.2: both polarities are
        // pinned — the cases above prove the guard fires, this one proves it
        // does NOT fire always). `null` and `''` both mean "unset", the same
        // convention as the optional-knob filter and VertexProvider's
        // systemPrompt hoist. If the guard ever flipped to fire
        // unconditionally, every request would grow a spurious leading system
        // turn and the prefix contract would silently change shape.
        $provider = $this->provider(2);

        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: null,
        ));
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: '',
        ));

        foreach ([0, 1] as $turn) {
            $bytes = self::messagesBytes($this->rawBody($turn));

            // The leading turn is the user message itself — no prepended
            // system chunk in either polarity.
            $this->assertStringStartsWith('"messages":[{"role":"user"', $bytes);
            $this->assertStringNotContainsString(self::systemChunk(self::SYSTEM_PROMPT), $bytes);
        }

        // `null` and `''` are equivalent: the two bodies must be byte-identical.
        $this->assertSame($this->rawBody(0), $this->rawBody(1));
    }

    public function testHistoryGrowsAsAStrictByteWisePrefixExtension(): void
    {
        $provider = $this->provider();

        $turnOne = [new UserMessage('First question')];
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: $turnOne,
            systemPrompt: self::SYSTEM_PROMPT,
        ));
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [
                ...$turnOne,
                new AssistantMessage('First answer'),
                new UserMessage('Second question'),
            ],
            systemPrompt: self::SYSTEM_PROMPT,
        ));

        // Drop turn one's closing `]` — turn two must continue from exactly
        // there. This is the whole RadixAttention contract in one assertion:
        // an append-only conversation is an append-only token prefix.
        $prefix = substr(self::messagesBytes($this->rawBody(0)), 0, -1);

        $this->assertStringStartsWith($prefix, self::messagesBytes($this->rawBody(1)));
    }

    public function testToolSchemaSerializationIsByteStableAcrossTurns(): void
    {
        $tools = [new StablePrefixRealToolStub(), new StablePrefixEmptyToolStub()];

        $provider = $this->provider();
        $provider->complete(new CompleteRequest(model: SglangProvider::DEFAULT_MODEL, messages: [new UserMessage('Hi')], tools: $tools));
        $provider->complete(new CompleteRequest(model: SglangProvider::DEFAULT_MODEL, messages: [new UserMessage('Hi again')], tools: $tools));

        $first = self::toolsBytes($this->rawBody(0));

        $this->assertSame($first, self::toolsBytes($this->rawBody(1)));

        // The empty-`properties` tool must serialize as `{}` on BOTH turns:
        // an object-vs-array flip is a one-byte change inside the cached
        // prefix, which is all it takes to void the whole thing.
        $this->assertStringContainsString('"properties":{}', $first);
        $this->assertStringNotContainsString('"properties":[]', $first);
    }

    public function testToolListOrderIsPreservedRatherThanNormalized(): void
    {
        // Not a defect — a deliberate pin. The provider serializes the caller's
        // order verbatim, so keeping a stable tool ORDER between turns is the
        // caller's obligation (a registry backed by an unordered map, or a
        // per-turn filter that drops then re-adds a tool, silently reshuffles
        // the prefix). Should someone ever add sorting here to defend against
        // that, this test is the record that the behaviour changed on purpose.
        $provider = $this->provider();
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            tools: [new StablePrefixRealToolStub(), new StablePrefixEmptyToolStub()],
        ));
        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            tools: [new StablePrefixEmptyToolStub(), new StablePrefixRealToolStub()],
        ));

        $this->assertStringStartsWith('"tools":[{"type":"function","function":{"name":"real"', self::toolsBytes($this->rawBody(0)));
        $this->assertStringStartsWith('"tools":[{"type":"function","function":{"name":"empty"', self::toolsBytes($this->rawBody(1)));
        $this->assertNotSame(self::toolsBytes($this->rawBody(0)), self::toolsBytes($this->rawBody(1)));
    }

    // -------------------------------------------------------------------------
    // No hidden per-request nondeterminism.
    // -------------------------------------------------------------------------

    public function testTwoIdenticalRequestsProduceByteIdenticalBodies(): void
    {
        // The broadest guard available: if the provider ever grew a request id,
        // a nonce, a `Current time:` stamp or any key emitted from an unordered
        // map, these two bodies would diverge and every turn would prefill from
        // scratch.
        $request = new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: [new UserMessage('Hi')],
            systemPrompt: self::SYSTEM_PROMPT,
            temperature: 0.2,
            maxTokens: 256,
            tools: [new StablePrefixRealToolStub(), new StablePrefixEmptyToolStub()],
            topP: 0.95,
            topK: 40,
            minP: 0.05,
            repetitionPenalty: 1.1,
            stop: ['</done>'],
            extraTemplateKwargs: ['enable_thinking' => true],
        );

        $provider = $this->provider();
        $provider->complete($request);
        $provider->complete($request);

        $this->assertSame($this->rawBody(0), $this->rawBody(1));
    }

    public function testStreamingAndBatchTurnsShareTheSamePrefixBytes(): void
    {
        // The live chat loop streams; a compaction/title call may not. Both go
        // through buildParams(), and `stream` is appended AFTER every prefix
        // key, so the two paths land on the same cached prefix instead of
        // maintaining two near-identical ones. The system prompt is part of
        // that shared prefix, so it must ride $systemPrompt on BOTH paths.
        $messages = [new UserMessage('Hi')];
        $tools = [new StablePrefixRealToolStub()];

        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'),
            new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"ok\"}}]}\n\n"),
        ]));
        $stack->push(Middleware::history($this->history));
        $provider = new SglangProvider(
            'https://api.example.com',
            SglangProvider::DEFAULT_MODEL,
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );

        $provider->complete(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: $messages,
            tools: $tools,
            systemPrompt: self::SYSTEM_PROMPT,
        ));
        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: SglangProvider::DEFAULT_MODEL,
            messages: $messages,
            tools: $tools,
            systemPrompt: self::SYSTEM_PROMPT,
        )));

        $batch = $this->rawBody(0);
        $streamed = $this->rawBody(1);

        $this->assertSame(self::messagesBytes($batch), self::messagesBytes($streamed));
        $this->assertSame(self::toolsBytes($batch), self::toolsBytes($streamed));

        // …and the divergence is confined to the tail. §Q6 amendment
        // (qwen.md, 2026-09-04): this arm pinned the streamed body as the
        // batch body plus `"stream":true` and nothing more; Q6 appended
        // `"stream_options":{"include_usage":true}` to the stream arm so the
        // final SSE chunk carries usage, flipping that exact tail claim.
        // What still guards prefix-cache stability: the messages/tools byte
        // identity above (the cache-critical prefix) plus the divergence
        // pinned below — the stream arm carries EXACTLY the stream +
        // stream_options tail, the batch arm carries neither key.
        $streamTail = ',"stream":true,"stream_options":{"include_usage":true}';
        $this->assertSame(substr($batch, 0, -1) . $streamTail . '}', $streamed);
        $this->assertStringEndsWith($streamTail . '}', $streamed);
        $this->assertStringNotContainsString('"stream"', $batch);
        $this->assertStringNotContainsString('"stream_options"', $batch);
    }

    // -------------------------------------------------------------------------
    // The environment block, which is now the TAIL of the prefix. It was
    // layer 2 of 7 when this section was written; P3.S1 moved it last, and
    // testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree() below
    // measures what that bought.
    // -------------------------------------------------------------------------

    public function testEnvironmentBlockRenderIsDeterministicForAFixedCapture(): void
    {
        // Outside a git repo the block is pure function of its constructor
        // args, so re-rendering the same capture is free of drift. This is the
        // property the whole prefix depends on — see the git case below for
        // where it stops holding.
        $dir = $this->tempDir();
        $block = new EnvironmentBlock($dir, SglangProvider::DEFAULT_MODEL, new DateTimeImmutable('2026-08-10 12:00:00'));

        $this->assertSame($block->render(), $block->render());
        $this->assertStringContainsString('Current date: 2026-08-10', $block->render());
        $this->assertStringContainsString('Is directory a git repo: No', $block->render());
    }

    public function testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture(): void
    {
        // BOUNDED COST PIN. `render()` shells out to `git status --porcelain`
        // every call rather than freezing the snapshot at capture time, and
        // `Runtime::environmentSnapshot()` memoizes only per Runtime — while
        // `EngineBackend::complete()` builds a fresh Runtime per user turn. So
        // the moment the agent writes a file, the `<env>` block changes and
        // everything from that byte on is re-prefilled.
        //
        // WHAT THIS COMMENT USED TO SAY, in two claims that are no longer
        // true. First, that `<env>` sits "at the very front of the system
        // prompt" and that a write therefore voids "the ENTIRE RadixAttention
        // prefix for the rest of the session": P3.S1 moved the block LAST, and
        // testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree() measures
        // the remainder — MEASURED on its fixture, 4,670 of 4,844 bytes still
        // shared after an edit, against 3,095 in the old order. The cost is
        // real and it is now the tail, not the whole prompt. Second, that
        // freezing the snapshot at capture is "the fix" and that this
        // assertion would then be "expected to flip": P3.S3 decided the
        // opposite on purpose and SHIPPED that decision in prompt text — see
        // `EnvironmentBlock::GIT_STATE_CAVEAT`, which tells the model the git
        // state is as of this render precisely because it is. A model reading
        // a stale `git status` after its own edits is the worse failure, and
        // P3.S2's `withWriteSinceLastRender()` is the lever that was taken
        // instead. So this assertion is not awaiting a flip; it pins a live
        // decision, and anything that freezes the snapshot must rewrite the
        // caveat in the same commit.
        //
        // WHY IT STILL EARNS ITS PLACE: the live-polling is what makes the
        // `<env>` tail volatile at all, which is the whole reason the layer
        // order matters. Delete this and the reorder above it loses its
        // premise.
        $dir = $this->tempDir();

        // THE INIT IS CHECKED, and the skip is narrowed to the one condition
        // it was ever meant to cover. This used to be an unchecked
        // `shell_exec(... 'init -q 2>/dev/null')` whose only guard was
        // `is_dir($dir . '/.git')`. MEASURED on git 2.43.0: under a global
        // `[core] quotePath = nonsense`, `git init` exits 128 AND STILL LEAVES
        // a partial `.git` (branches/description/hooks/info; no
        // HEAD/config/objects/refs), so that guard passed, the exit code and
        // stderr were discarded, and this test red two assertions later at the
        // `status.showUntrackedFiles` pin with `fatal: not in a git directory`
        // — naming neither `git init` nor the hostile config. The skip stays
        // keyed on the DIRECTORY, not on the exit code, because a host with no
        // git at all creates no `.git` and must still skip rather than fail;
        // a nonzero exit that DID create one is a real failure and now says so.
        $initOutput = [];
        $initExit = self::git($dir, ['init', '-q'], $initOutput);
        if (!is_dir($dir . '/.git')) {
            $this->markTestSkipped(
                'git is unavailable in this environment: `git init` exit ' . $initExit . ', '
                    . self::gitSaid($initOutput),
            );
        }

        $this->assertSame(
            0,
            $initExit,
            'git init failed on the scratch repository, exit ' . $initExit . ' - nothing below this line is a '
                . 'statement about live polling. MEASURED cause: an INVALID value for a key `git init` itself '
                . 'reads, anywhere in the config precedence chain (e.g. a global `[core] quotePath = nonsense`), '
                . 'which leaves a PARTIAL `.git` behind so the skip above cannot see it. git said: '
                . self::gitSaid($initOutput),
        );

        // The write below is an UNTRACKED file, and `status.showUntrackedFiles`
        // decides whether `git status --porcelain` can see one — this block
        // runs `status --porcelain` with no untracked flag of its own. MEASURED:
        // with `status.showUntrackedFiles=no` in a global gitconfig, this test
        // reds on master too — the two renders come out byte-identical, and
        // master's failure message then reported a config artefact as "D7 got
        // fixed". Pinned repo-locally rather than left to the developer's own
        // config, and that message rewritten below to say what a red here
        // actually means.
        $this->assertSame(
            0,
            self::git($dir, ['config', 'status.showUntrackedFiles', 'normal'], $configured),
            'could not pin status.showUntrackedFiles on the scratch repository: ' . implode("\n", $configured),
        );

        $block = new EnvironmentBlock($dir, SglangProvider::DEFAULT_MODEL, new DateTimeImmutable('2026-08-10 12:00:00'));
        $before = $block->render();

        file_put_contents($dir . '/scratch.txt', 'written by the agent mid-session');
        $after = $block->render();

        $this->assertNotSame(
            $before,
            $after,
            'the <env> git snapshot stopped tracking the working tree. That is a BEHAVIOUR CHANGE, not '
                . 'a fix: P3.S3 shipped EnvironmentBlock::GIT_STATE_CAVEAT precisely so the block could keep '
                . 'live-polling and tell the model the snapshot is a point-in-time reading. If freezing it is '
                . 'now the intended design, retire the caveat in the same change and rewrite this test — do not '
                . 'delete this assertion on its own',
        );
        $this->assertStringContainsString('scratch.txt', $after);
    }

    // -------------------------------------------------------------------------
    // What the P3.S1 reorder bought: how far into the prompt the hit survives.
    // -------------------------------------------------------------------------

    /**
     * The floor the assembled prompt's shared prefix must clear on the fixture
     * {@see dirtyRepoFixtureWithEveryStableLayer()} builds, in bytes, for a
     * change that moves `<env>` AND NOTHING ELSE.
     *
     * MEASURED 2026-08-29, PHP 8.3.6, Linux 6.8.0-138-generic, three takes per
     * row and identical on all three, by assembling two consecutive prompts
     * through the real private
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} over that fixture
     * and counting bytes to the first one that differs. The two prompt lengths
     * are given separately because they are two different strings:
     *
     *   | between the two renders            | prompt 1 | prompt 2 | prefix | diverges at   | driven by |
     *   |------------------------------------|---------:|---------:|-------:|---------------|-----------|
     *   | the same file edited again         |    4,844 |    4,844 |  4,670 | blob hash     | {@see testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree()} |
     *   | 400 vs 405 lines, over the 8,192 B cap | 12,751 | 12,751 |  4,583 | `--shortstat` | {@see testTheFloorHoldsForEveryChangeThatMovesOnlyTheEnvBlock()} |
     *   | a SECOND tracked file dirtied      |    4,844 |    5,083 |  4,403 | `Status:`     | {@see testTheFloorHoldsForEveryChangeThatMovesOnlyTheEnvBlock()} |
     *   | pre-P3.S1 order, same file edited  |    4,844 |    4,844 |  3,095 | blob hash     | the old-order control inside the first test |
     *
     * WHAT THE LAST COLUMN MEANS, AND WHAT IT DOES NOT. It names the test that
     * exercises the row's SCENARIO. That is all it claims — an earlier revision
     * of this paragraph said "the generator is the test, not this table", and
     * that was false of every literal above. NO TEST DERIVES OR ASSERTS THESE
     * BYTE COUNTS. What the tests pin is deliberately weaker: the floor
     * {@see MIN_STABLE_PREFIX_BYTES}, the gain floor
     * {@see MIN_PREFIX_GAIN_BYTES}, `prefix > $envAt` per fixture, each stable
     * marker ending before the prefix does, `prefix > $diffAt` on the nice
     * shape and `$diffAt > MIN_STABLE_PREFIX_BYTES` beside it, the membership
     * and order of the five layers between the fences (no literal at all), the
     * per-layer widths {@see STABLE_LAYER_WIDTHS} and their total
     * {@see STABLE_LAYERS_BYTES}, and the three-way ordering of the three
     * post-reorder rows.
     *
     * HOW FAR EACH ROW CAN ROT WHILE THE FILE STAYS GREEN. Two earlier
     * revisions of this paragraph got this wrong, in different ways, and both
     * corrections are kept because the second one is a correction OF the first
     * (rule 7: a false correction is trusted, and it overwrites something that
     * was right).
     *
     * The FIRST revision left `$diffAt` out of the list and said the nice row
     * could rot "down to 4,404". The SECOND corrected that with an anecdote —
     * "a prefix of 4,421 already reds" — and gave no generator for 4,421, so
     * the next reader who ran the experiment got a different number and could
     * not tell which of them had mistyped. WHAT IS TRUE NOW, with the
     * generator written down so the figure reproduces. Apply this exact
     * mutation to {@see \SugarCraft\Crush\Context\EnvironmentBlock::gitStatusSnapshot()},
     * which makes `Recent commits:` differ between two renders at its first
     * byte:
     *
     *     -        $log = $this->gitField(['log', '--oneline', '-5'], self::SUMMARY_MAX_BYTES);
     *     +        static $rot = 1000;
     *     +        $log = ((string) $rot++) . $this->gitField(['log', '--oneline', '-5'], self::SUMMARY_MAX_BYTES);
     *
     * MEASURED 2026-08-31, PHP 8.3.6, Linux 6.8.0-138-generic, this worktree:
     * prompt 4,844 -> 4,848 and the shared prefix **4,423**, not 4,421. The
     * arithmetic checks: the log field's first byte is at 4,420 (the fence
     * `"\n\nRecent commits:\n"` opens at 4,402 and is 18 B long) and `1000`
     * and `1001` share three bytes, so 4,420 + 3 = 4,423. The 4,421 the last
     * revision carried is not reachable by that mutation at all. The CLAIM
     * both revisions were making survives the correction and is why the
     * paragraph stays: 4,423 is 19 B above the 4,404 the first revision called
     * a green floor, and it reds, at
     * `the git status or log diverged before the diff body did /
     * Failed asserting that 4423 is greater than 4516`.
     *
     * The nice row is pinned far tighter than the constant suggests, by
     * `$diffAt`, MEASURED 4,512 here — and that RELATION is now asserted
     * rather than left in prose, at the `$diffAt` site in
     * {@see testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree()}, so a
     * fixture that shrank until the floor became the binding constraint reds
     * there instead of silently making this paragraph false. The capped and
     * status rows have no `$diffAt` assertion of their own.
     *
     * SO, DERIVED FROM THE ASSERTIONS RATHER THAN OBSERVED — and an earlier
     * revision of this sentence was off by one on two of the three rows, which
     * is the same class of defect the paragraph is itself a correction of:
     * `$statusPrefix >= 4,096` is the only row bounded by the floor alone, so
     * the STATUS row can fall to **4,096**; `$statusPrefix < $cappedPrefix` then
     * forces the CAPPED row to **4,097**, not 4,096; `$cappedPrefix <
     * $nicePrefix` forces the nice row to 4,098 from that chain, and the
     * `$diffAt` pin in the other test binds it harder still at **4,513**. Three
     * different floors, one per row, and none of them is the constant.
     *
     * That rot risk is taken ON PURPOSE, and the alternative is worse: these
     * absolutes contain bytes this file does not own — `OS version:` and `PHP
     * version:` (28 B here) and the fixture root's path length — so pinning
     * them by equality would red on the next developer's host for no defect.
     * §16.3's rule, "a number no test derives rots", is answered the other way
     * instead. Every figure here carries the domain it was measured in, and the
     * ONE relation that is host-independent — 4,670 - 3,095 = 4,056 - 2,481 =
     * 1,575, the size of exactly the block P3.S1 lifted — IS asserted by
     * equality, inside the first test beside the old-order control. Treat the
     * rest as dated observations: re-measure before quoting them, do not
     * correct them from arithmetic.
     *
     * `<env>` opens at byte 4,056 on this fixture, so every post-reorder row
     * above keeps the whole stable region — base heredoc, repo map, instruction
     * documents, memory, both skill layers — inside the shared prefix, and the
     * last row does not. The prompt is the same 4,844 bytes in the first and
     * last rows: this was a reorder, not an addition, and it moved 1,575 of
     * them from behind the first differing byte to in front of it.
     *
     * THE CLASS OF CHANGE MATTERS, AND AN EARLIER REVISION OF THIS BLOCK DID
     * NOT SAY SO. It claimed the worst case was "bounded by WHERE `<env>`
     * starts, not by how big the diff gets". That is true only for a change
     * that leaves the layers ahead of `<env>` alone. It is FALSE for a turn
     * that creates a source file: `<repo-map>` carries a per-directory `.php`
     * COUNT, so the divergence sits 707 B INSIDE the map — P3.S1 measured that
     * byte at 3,188, ahead of everything P3.S1 moved and below this floor,
     * until P5.S5's static voice layer ahead of the map lifted the shape over
     * it (MEASURED 4,762, 2026-09-05).
     * {@see testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne()} pins
     * the position (inside the map, ahead of `<env>`), the lifetime that saved
     * it before the lift, and the lift itself — above this floor — since.
     *
     * Both `<env>` figures are OF THIS FIXTURE and of nothing else. §3.4's own
     * pair — 598 B then 615 B, first differing at 524 — is of a two-edit
     * `<env>` block in isolation, not of an assembled prompt, and does not
     * compare.
     *
     * WHY THE FLOOR IS 4,096 AND NOT 4,670, AND WHY THAT IS A DEVIATION FROM
     * THE STEP TEXT. P3.S4 says the assertion pins "at least N bytes, where N
     * is the measured value on the fixture". Taken literally that is 4,670, and
     * this constant is deliberately NOT that. Two reasons, both measured.
     * First, 4,670 is the value for ONE shape; the worst shape in the class
     * this floor governs is 4,403, and a floor that only the nicest edit can
     * clear pins the fixture's luck rather than the layer order. Second, some
     * bytes inside the prefix are read off the host and this file does not own
     * them — `OS version:` and `PHP version:` are 28 B of it here — while the
     * base heredoc ahead of them is 2,481 B of prose four later steps are
     * licensed to edit. 4,096 sits 1,001 B ABOVE the pre-reorder measurement,
     * which is what makes it discriminating — the old assembly cannot reach it
     * on this fixture, and the deletion experiment in the worklog shows it
     * reporting exactly 3,095 — and 307 B below the worst row of the class it
     * governs, which is the slack. The dominant consumer of that slack is the
     * editable base heredoc, not the host lines.
     *
     * It is a MAGNITUDE floor and nothing more. The ORDERING decision is pinned
     * by the marker assertions beside it and by the old-order control, neither
     * of which depends on a literal; the SIZE OF THE WIN is pinned by
     * {@see MIN_PREFIX_GAIN_BYTES}, which is invariant to both the host lines
     * and the base heredoc because those bytes cancel between the two orders.
     * So if a deliberate prose cut ever reds this number, re-measure it and
     * move it, and expect everything around it to stay green — they are
     * assertions about different properties.
     */
    private const MIN_STABLE_PREFIX_BYTES = 4096;

    /**
     * The floor on how much the reorder must move the first differing byte, in
     * bytes, on the same fixture and for the same-file-edited-again shape.
     *
     * MEASURED 4,670 - 3,095 = **1,575** by the same runs. This delta is the
     * combined size of the FIVE layers the reorder lifted over `<env>` — the
     * repo map, the project instructions, the project memory, the enabled
     * skill's body and the skill listing — and MEASURED it is exactly
     * `strpos($prompt, "\n\n<env>") - strpos($prompt, "\n\n<repo-map>")`,
     * 4,056 - 2,481. Unlike {@see MIN_STABLE_PREFIX_BYTES} it is unaffected by
     * the length of the base heredoc or of the host lines, which shift both
     * orders equally and cancel; it moves only when this file's own fixture
     * content moves, which is why it carries the tighter margin of the two.
     */
    private const MIN_PREFIX_GAIN_BYTES = 1500;

    /**
     * The combined size of the five layers P3.S1 lifted over `<env>`, in bytes,
     * on the fixture {@see dirtyRepoFixtureWithEveryStableLayer()} builds:
     * everything between the `<repo-map>` fence and the `<env>` fence.
     *
     * MEASURED **1,575** = `strpos($p, "\n\n<env>\n") - strpos($p, "\n\n<repo-map>")`
     * = 4,056 - 2,481, three takes, and it is ALSO the gain the reorder bought,
     * 4,670 - 3,095. Those are the same number for a reason given at the
     * assertion site, not by coincidence.
     *
     * PINNED BY EQUALITY, unlike every other figure in this file, because it is
     * the only one that is HOST-independent. MEASURED by re-running the fixture
     * under a `TMPDIR` ten bytes longer: the prompt goes 4,844 -> 4,854 and the
     * prefix 4,670 -> 4,680, and this constant does not move — the fixture
     * root's path lives inside `<env>`, past both fences, and so do
     * `OS version:` and `PHP version:`. That half held up under every attack
     * and is not in question.
     *
     * WHAT THIS DOC-BLOCK USED TO CLAIM, AND WHY IT WAS THE WRONG LICENCE.
     * It said: *"What it DOES move with is this file's own fixture content,
     * which this file owns. If a later step edits the instruction documents,
     * the memory store or the skill body the fixture writes, re-measure this
     * and move it."* Host-independence is not the same property as
     * this-file-owns-it, and the second sentence was the one licensing an
     * equality pin. It is FALSE, by a wide margin. MEASURED 2026-08-31 in this
     * worktree, by summing the byte ranges whose VALUE the fixture chose
     * ({@see STABLE_LAYER_FIXTURE_FRAGMENTS}, which is the same roster the test
     * asserts against) and taking the complement:
     *
     *   fixture-authored (this file owns it)    289 B   18.3 %
     *   production-authored (it does not)     1,286 B   81.7 %
     *
     * THE FIRST REVISION OF THIS SPLIT SAID 324 / 1,251 (20.6 % / 79.4 %) and
     * called itself byte-exact. It was measured at the granularity of whole
     * RENDERED LINES, which credits production formatting to the fixture:
     * `MemoryBlock`'s `"- [pattern] "` (12 B), `SkillMatcher`'s `"- "` and
     * `": "` (4 B), and `RepoMapBlock`'s `"- "`, `"  ->  "`, `"  ("`,
     * `" files)"` and the file COUNT it computes (19 B) are all rendered by
     * production code around values the fixture supplied. Corrected rather than
     * dropped, because the difference is 35 bytes and the conclusion — the
     * great majority of the pinned region is prose this file does not own — is
     * the same at either granularity, and because a reader who re-measures at
     * the coarser granularity should find the coarser number explained rather
     * than absent.
     *
     * The 1,286 is static prose and fence spellings inside
     * {@see \SugarCraft\Crush\Context\RepoMapBlock} (its 403 B header and
     * its 257 B PSR-4 note), {@see \SugarCraft\Crush\Context\MemoryBlock}
     * (its 414 B header) and {@see \SugarCraft\Crush\Skills\SkillMatcher}
     * (its 41 B caption), plus the file-count arithmetic `RepoMapBlock`
     * performs. Four of this plan's later steps are licensed to edit exactly
     * that prose. RE-MEASURED 2026-09-05 (P5.S6): the production side became
     * 1,568 when Runtime's authority preamble (280 B) plus its blank-line
     * separator (2 B) landed inside the project-instructions fence; the
     * 1,286 above is the pre-P5.S6 figure and is kept as the historical
     * baseline the surrounding measurement notes were written against. MEASURED: changing `'A map of where code lives'` to
     * `'A map of where the code lives'` in `RepoMapBlock` — four bytes, no
     * behaviour — red this constant at 1,579, under a failure message offering
     * two causes ("a layer moved out from between the fences" / "this file's
     * own fixture content changed size") of which NEITHER had happened.
     *
     * WHAT IS TRUE NOW, AND WHY THE CONSTANT STILL EARNS ITS PLACE. The two
     * properties are separated. The property this equality was licensed for —
     * that the region between the fences holds exactly these five layers, in
     * this order, and nothing else — is pinned prose-immune by the membership
     * and order assertions at the head of
     * {@see testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree()}, which
     * carry no byte literal at all and survive every prose edit. The SIZE stays
     * pinned by equality, because a size pin that a foreign prose edit reds is
     * still worth having (it is the only thing that would catch a layer
     * silently doubling), but it is now pinned PER LAYER through
     * {@see STABLE_LAYER_WIDTHS}, and split again inside each layer into the
     * bytes the fixture chose and the bytes production wrote. A red therefore
     * names ONE layer AND ONE SIDE of that layer's split: a fixture edit reds
     * the fragment or the fixture-width guard, a prose edit reds the
     * production-width assertion, and the message names the class that owns
     * those bytes. An earlier revision of this paragraph promised only that the
     * message would list the three possible repairs; a menu is not a name, and
     * the offsets to tell them apart were already in the test.
     */
    // MEASURED 2026-09-05 at P5.S6: 1,575 -> 1,857. One mover only: the
    // project-instructions layer grew by the authority preamble Runtime now
    // renders inside every fence (280 B + 2 B separator; this fixture carries
    // one document). Sum identity with STABLE_LAYER_WIDTHS re-verified.
    private const STABLE_LAYERS_BYTES = 1857;

    /**
     * The same 1,857 bytes (post-P5.S6; 1,575 before) as
     * {@see STABLE_LAYERS_BYTES}, split per layer, so a
     * width that moves names the layer AND the code that authored the bytes.
     *
     * Keyed by the layer's marker, in assembly order; the value is the byte
     * distance from that layer's own `"\n\n" . $marker` boundary to the next
     * layer's (the last one to the `<env>` fence). MEASURED 2026-08-31, PHP
     * 8.3.6, Linux 6.8.0-138-generic, in this worktree, on the fixture
     * {@see dirtyRepoFixtureWithEveryStableLayer()} builds, three takes and
     * identical on all three. The last two columns are the ownership split
     * described at {@see STABLE_LAYERS_BYTES}, measured the same way:
     *
     *   | layer                    | width | fixture | production, and by whom |
     *   |--------------------------|------:|--------:|-------------------------|
     *   | `<repo-map>`             |   727 |      19 |  708  RepoMapBlock header + PSR-4 note + entry formatting + fences |
     *   | `<project-instructions>` |   421 |      90 |  331  the fence spellings + P5.S6 authority preamble (280 B) + separator |
     *   | `<project-memory>`       |   518 |      51 |  467  MemoryBlock header + `- [pattern] ` + fences |
     *   | `## Skill: prefix-demo`  |    73 |      59 |   14  Skill::systemPromptContribution()'s heading |
     *   | the skill listing        |   118 |      70 |   48  SkillMatcher's caption + `- `/`: ` |
     *   | **total**                | 1,857 |     289 | 1,568 |
     *
     * The `project-instructions` row is MEASURED 2026-09-05 (P5.S6): the
     * pre-preamble take of it was 139/90/49, recorded 2026-08-31; the whole
     * delta of 282 bytes sits on the production side because the preamble is
     * harness-authored bytes rendered inside the fence, not fixture content.
     *
     * The widths sum to {@see STABLE_LAYERS_BYTES} and that identity is
     * asserted, so the two constants cannot drift apart silently. The `fixture`
     * column is {@see STABLE_LAYER_FIXTURE_WIDTHS} and is asserted separately
     * from the `production` column, which is the difference — that is what lets
     * a failure name WHICH side moved rather than list the possibilities.
     */
    private const STABLE_LAYER_WIDTHS = [
        '<repo-map>' => 727,
        '<project-instructions>' => 421,
        '<project-memory>' => 518,
        '## Skill: prefix-demo' => 73,
        'Available skills (invoke via Skill tool):' => 118,
    ];

    /**
     * The `fixture` column of the table above: bytes inside each layer whose
     * VALUE this file chose, MEASURED 2026-08-31, three takes in one session,
     * identical on all three.
     *
     * These are asserted separately from the layer's total width, and the
     * difference between the two is the production-authored remainder. That
     * separation is the whole point: a fixture edit moves this column, a prose
     * edit in RepoMapBlock/MemoryBlock/SkillMatcher moves the remainder, and
     * the two reds say different things.
     */
    private const STABLE_LAYER_FIXTURE_WIDTHS = [
        '<repo-map>' => 19,
        '<project-instructions>' => 90,
        '<project-memory>' => 51,
        '## Skill: prefix-demo' => 59,
        'Available skills (invoke via Skill tool):' => 70,
    ];

    /**
     * The byte ranges {@see STABLE_LAYER_FIXTURE_WIDTHS} is the sum of, per
     * layer, spelled as the substrings they are.
     *
     * DERIVED FROM THE SAME CONSTANTS THE FIXTURE WRITES, not copied beside
     * them, so an edit to the fixture's AGENTS.md body, memory note or skill
     * moves both sides at once and cannot drift. Each fragment is asserted to
     * appear EXACTLY ONCE inside its own layer before its length is counted —
     * a fragment that stopped appearing would otherwise silently contribute its
     * length to a sum that still balanced.
     *
     * What is deliberately NOT here: `RepoMapBlock`'s `(2 files)` count. The
     * fixture supplies the two files; the digit is arithmetic production
     * performs over them, so it is counted on the production side. The earlier,
     * coarser split counted the whole rendered line as the fixture's — see
     * {@see STABLE_LAYERS_BYTES} for the 324/1,251 figure that produced and why
     * it is recorded rather than dropped.
     */
    private const STABLE_LAYER_FIXTURE_FRAGMENTS = [
        '<repo-map>' => [self::FIXTURE_SOURCE_DIR, self::FIXTURE_PSR4_PREFIX],
        '<project-instructions>' => [self::FIXTURE_AGENTS_BODY],
        '<project-memory>' => [self::FIXTURE_MEMORY_NOTE],
        '## Skill: prefix-demo' => [self::FIXTURE_SKILL_NAME, self::FIXTURE_SKILL_BODY],
        'Available skills (invoke via Skill tool):' => [self::FIXTURE_SKILL_NAME, self::FIXTURE_SKILL_DESCRIPTION],
    ];

    /**
     * Which production class authors the bytes of each layer that the fixture
     * does not, named so a width failure can say where to go and read.
     */
    private const STABLE_LAYER_OWNERS = [
        '<repo-map>' => 'SugarCraft\\Crush\\Context\\RepoMapBlock',
        '<project-instructions>' => 'SugarCraft\\Crush\\Runtime (the fence spellings and the P5.S6 authority preamble)',
        '<project-memory>' => 'SugarCraft\\Crush\\Context\\MemoryBlock',
        '## Skill: prefix-demo' => 'SugarCraft\\Crush\\Skills\\Skill::systemPromptContribution()',
        'Available skills (invoke via Skill tool):' => 'SugarCraft\\Crush\\Skills\\SkillMatcher',
    ];

    /**
     * The prefix every degraded `<env>` field shares.
     *
     * {@see \SugarCraft\Crush\Context\EnvironmentBlock} renders three of
     * them — `unavailable (git exited N)` from `gitField()`:907 and
     * `gitDiffSection()`:969, `NO_PROCESS_REASON`:327, and an inline
     * `'unavailable (shell_exec is disabled on this build)'` at :855. Scanning
     * the shared prefix covers all three at RENDER time; scanning the git-exit
     * spelling alone, which an earlier revision did, covered one under a
     * heading claiming every field.
     *
     * WHAT IS PINNED AGAINST A RENAME, AND WHAT IS NOT — an earlier revision of
     * this paragraph claimed all three and had measured one. Two are pinned:
     * the git-exit family, by control A, which renders it; and
     * `NO_PROCESS_REASON`, by an assertion on its VALUE. The THIRD, the
     * `shell_exec` literal at :855, is pinned by NOTHING here. MEASURED,
     * renaming it and its `proc_open` sibling together to `no-subprocess (…)`
     * left this file green. It is reachable only on a build with `shell_exec`
     * in `disable_functions`, which this suite cannot produce, and it is an
     * inline literal where its sibling is a constant — under a doc-block on
     * that very constant arguing the two must say the same thing. Giving it a
     * constant is a production change, so it is escalated rather than made
     * here; until then this scan sees it at render time and nothing sees a
     * rename of it.
     */
    private const GIT_UNAVAILABLE_MARKER = 'unavailable (';

    /**
     * The cap on captured git output quoted into a PHPUnit failure message.
     *
     * Production caps the same stream: {@see EnvironmentBlock::DIFF_MAX_BYTES}
     * is 8,192 B and every diff that reaches a prompt is truncated to it. The
     * guards in this file interpolate the SAME capture into their messages with
     * no bound of their own, and the bound that matters is not the fixture's
     * (small, and measured) but the host git's — an external diff or a locale
     * catalogue can put arbitrary bytes there. 2,048 is a quarter of the
     * production cap and still carries every capture MEASURED at the four call
     * sites in this file WHOLE, on git 2.43.0 and this fixture: the coloured
     * control's own patch, the largest, is 342 B; the binary control's is
     * 178 B; the longest fatal — the shortstat line plus `fatal: external diff
     * died, stopping at src/Alpha.php` — is 102 B. So the cap never fires here
     * today, which is why {@see
     * testCapturedGitOutputIsCappedBeforeItReachesAFailureMessage()} asserts it
     * directly rather than leaving it to a fixture that cannot reach it.
     */
    private const GIT_SAID_MAX_BYTES = 2048;

    /** The fixture's PSR-4 source directory, as `RepoMapBlock` renders it. */
    private const FIXTURE_SOURCE_DIR = 'src/';

    /** And the namespace that directory maps to. */
    private const FIXTURE_PSR4_PREFIX = 'Fixture\\Prefix\\';

    /** The body of the fixture's one root instruction document. */
    private const FIXTURE_AGENTS_BODY = "# Fixture conventions\n\nRun the suite before you push.\nNever edit generated files by hand.\n";

    /** The one note in the fixture's project memory store. */
    private const FIXTURE_MEMORY_NOTE = 'The fixture repository pins the prefix measurement.';

    /** The fixture's one skill: name, one-line description, body. */
    private const FIXTURE_SKILL_NAME = 'prefix-demo';

    /** @see self::FIXTURE_SKILL_NAME */
    private const FIXTURE_SKILL_DESCRIPTION = 'A skill body that occupies the stable region of the prompt.';

    /** @see self::FIXTURE_SKILL_NAME */
    private const FIXTURE_SKILL_BODY = "Use this skill when measuring the cache prefix.\n";

    /**
     * One marker per layer the reorder lifted into the cacheable prefix.
     *
     * Read, never written - these are the fence spellings the assembler
     * already emits, and every one of them is asserted to appear exactly once
     * before its offset is compared against anything, because an absent marker
     * makes `strpos()` return false and `false < $prefix` is silently true.
     */
    private const STABLE_LAYER_MARKERS = [
        '<repo-map>',
        '<project-instructions>',
        '<project-memory>',
        '## Skill: prefix-demo',
        'Available skills (invoke via Skill tool):',
    ];

    /**
     * {@see PromptFixture}'s own defaults, restated so a Runtime built by hand
     * here renders the same bytes the fixture's default one does.
     *
     * Restating them is a liability the fixture does not otherwise impose, so
     * the one test that needs them asserts byte equality against
     * `PromptFixture::systemPrompt()` rather than trusting the copy.
     */
    private const FIXTURE_PLATFORM = 'linux';

    /** @see self::FIXTURE_PLATFORM */
    private const FIXTURE_NOW = '2026-01-15 12:00:00 UTC';

    /** The committed body of the fixture's one edited file. */
    private const ALPHA_COMMITTED = "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Alpha {}\n";

    /** Its body after the edit that dirties the tree BEFORE the first render. */
    private const ALPHA_FIRST_EDIT = "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Alpha { public int \$one = 1; }\n";

    /** And after the edit BETWEEN the two renders - one line, same length. */
    private const ALPHA_SECOND_EDIT = "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Alpha { public int \$one = 2; }\n";

    /**
     * P3.S4. A reorder that did not move the first differing byte is a reorder
     * that did nothing, and this is the assertion that says how far it moved.
     *
     * Two consecutive assembled prompts on a DIRTY working tree, one ordinary
     * edit apart - the shape §3.4 priced when it measured the `<env>` block
     * alone at "598 B then 615 B and first differs at byte 524". A
     * RadixAttention hit survives only as far as the first byte that differs,
     * so the shared-prefix length IS the win: every layer behind that byte is
     * re-prefilled on every step of every turn for the rest of the session.
     * Before P3.S1 the repo map, the instruction documents, the memory block
     * and both skill layers all sat behind it. They now sit in front.
     *
     * THE OLD-ORDER CONTROL AT THE END IS NOT DECORATION. "The shared prefix
     * is long" is an assertion of ABSENCE - no differing byte yet - and an
     * unfired instrument and a dead one produce identical silence. So the same
     * counter is run, in this same test, over the same two prompts re-spliced
     * into the pre-P3.S1 order, and it must come back SHORT and must stop
     * before the FIRST layer that used to follow `<env>`. Replace
     * {@see commonPrefixLength()} with `return strlen($a);` and that control
     * is what reds.
     *
     * WHAT IT DOES NOT COVER. This drives the assembler, not the wire; the
     * transmitted-prompt pins live in
     * `tests/Integration/SystemPromptWiringTest.php`. And it says nothing
     * about `Agents\Agent::systemPrompt()`, the second assembler §17.2 keeps
     * deliberately separate, where the block is still the tail of the system
     * message.
     */
    public function testTheCachePrefixReachesPastEveryStableLayerOnADirtyTree(): void
    {
        $fixture = $this->dirtyRepoFixtureWithEveryStableLayer();

        $first = $fixture->systemPrompt();
        $fixture->write('src/Alpha.php', self::ALPHA_SECOND_EDIT);
        $second = $fixture->systemPrompt();

        // The instrument fired, and for the right reason. Two prompts that are
        // byte-identical would make every assertion below vacuously true, and
        // a git that could not run renders its own failure text into the
        // status field instead of a status - which is why the diff header is
        // checked for rather than assumed.
        $this->assertNotSame(
            $first,
            $second,
            'the two prompts must differ, or there is no first differing byte to measure',
        );
        $this->assertSame(
            \strlen($first),
            \strlen($second),
            'the fixture edit is one line for one line; a length change means the fixture, not the assembler, moved',
        );
        $this->assertStringContainsString('Unstaged changes (git diff, working tree vs index):', $first);
        $this->assertStringContainsString('diff --git a/src/Alpha.php b/src/Alpha.php', $first);

        foreach (self::STABLE_LAYER_MARKERS as $marker) {
            $this->assertSame(
                1,
                substr_count($first, $marker),
                'the fixture must render exactly one "' . $marker . '" layer for its offset to mean anything',
            );
        }

        $prefix = self::commonPrefixLength($first, $second);

        // THE STEP'S HEADLINE ASSERTION — and inside THIS test it is subsumed,
        // which is worth knowing before anyone edits it. `$diffAt` is 4,512 on
        // this fixture and the assertion 40 lines below demands
        // `$prefix > $diffAt`, so every prefix that reds this floor reds that
        // one too: deleting these five lines would change this test's verdict
        // for no input, only its failure message. MEASURED 2026-08-31 with the
        // volatile-log mutation written out in full at
        // {@see MIN_STABLE_PREFIX_BYTES} — at a prefix of 4,423 this floor
        // passes and the `$diffAt` assertion is what fires, at
        // `Failed asserting that 4423 is greater than 4516`. (This line used to
        // say 4,421 and cited no generator; 4,421 is not a value that mutation
        // can produce. See the doc-block for the arithmetic.) The relation the
        // sentence depends on — `$diffAt` above the floor — is asserted at the
        // `$diffAt` site rather than left here as prose.
        //
        // The floor is NOT dead, because the shape that binds it is in the
        // other test: {@see testTheFloorHoldsForEveryChangeThatMovesOnlyTheEnvBlock()}
        // has no `$diffAt` pin and its status shape sits at 4,403, 307 B of
        // slack. It is kept here because this is the test the step text points
        // at, and a reader who finds only a `> 4512` here would not find the
        // step's number at all.
        $this->assertGreaterThanOrEqual(
            self::MIN_STABLE_PREFIX_BYTES,
            $prefix,
            'the shared prefix collapsed to ' . $prefix . ' bytes of ' . \strlen($first)
                . ' - something volatile moved back ahead of the stable layers (P3.S1)',
        );

        // …and it does not merely clear a number: it runs past every stable
        // layer and into <env> itself, which is the shape of the decision.
        $envAt = strpos($first, "\n\n<env>\n");
        $this->assertIsInt($envAt, '<env> is not where the assembler emits it');
        $this->assertGreaterThan(
            $envAt,
            $prefix,
            'the first differing byte landed before <env> began, so a layer other than <env> is now volatile',
        );
        $this->assertStringEndsWith(
            "\n</env>",
            $first,
            '<env> must be the LAST layer of the assembled prompt (P3.S1)',
        );

        foreach (self::STABLE_LAYER_MARKERS as $marker) {
            $endsAt = (int) strpos($first, $marker) + \strlen($marker);
            $this->assertLessThan(
                $prefix,
                $endsAt,
                'the "' . $marker . '" layer ends at byte ' . $endsAt . ', behind the shared prefix at '
                    . $prefix . ' - it is re-prefilled on every step',
            );
        }

        // The divergence is not just inside <env>; it is inside the diff BODY,
        // past the caveat, the branch, the status and the log. Those four are
        // cached too.
        $diffAt = strpos($first, 'Unstaged changes (git diff, working tree vs index):');
        $this->assertIsInt($diffAt);
        $this->assertGreaterThan(
            $diffAt,
            $prefix,
            'the git status or log diverged before the diff body did',
        );

        // WHICH OF THE TWO BINDS, ASSERTED RATHER THAN NARRATED. The docblock
        // on MIN_STABLE_PREFIX_BYTES claims this shape is pinned by `$diffAt`
        // and not by the floor; that claim is only true while `$diffAt` sits
        // ABOVE the floor, and it was carried for three review cycles as prose
        // backed by an anecdotal figure nobody could reproduce. It is one
        // comparison, it needs no literal, and if the fixture ever shrinks far
        // enough that the floor becomes the binding constraint on this shape it
        // reds here instead of quietly making that docblock false.
        $this->assertGreaterThan(
            self::MIN_STABLE_PREFIX_BYTES,
            $diffAt,
            'the diff body now starts at byte ' . $diffAt . ', at or below the floor of '
                . self::MIN_STABLE_PREFIX_BYTES . ' - the floor, not $diffAt, is what binds this shape now, '
                . 'and MIN_STABLE_PREFIX_BYTES\' doc-block says the opposite',
        );

        // ---- the control: the same counter, the pre-P3.S1 order ------------
        $oldFirst = self::reassembledWithEnvAtLayerTwo($first);
        $oldSecond = self::reassembledWithEnvAtLayerTwo($second);

        // A guard on the SPLICE HELPER, not on the code under test: three
        // complementary substr() ranges of one string cannot change its length,
        // so this cannot fire against today's helper — it fires against a
        // future edit to it. MEASURED: dropping the third `substr` from
        // reassembledWithEnvAtLayerTwo() reds exactly this pair.
        $this->assertSame(\strlen($first), \strlen($oldFirst), 'the re-splice lost or duplicated bytes');
        $this->assertSame(\strlen($second), \strlen($oldSecond), 'the re-splice lost or duplicated bytes');

        $oldPrefix = self::commonPrefixLength($oldFirst, $oldSecond);
        $oldMapAt = strpos($oldFirst, '<repo-map>');
        $this->assertIsInt($oldMapAt);
        $this->assertLessThan(
            $oldMapAt,
            $oldPrefix,
            'the counter reported a prefix reaching past <repo-map> with <env> AHEAD of it, which cannot be true '
                . '- the instrument is broken, not the code',
        );
        $mapAt = strpos($first, "\n\n<repo-map>");
        $this->assertIsInt($mapAt, 'the assembled prompt carries no <repo-map> layer to have lifted');

        // 0. MEMBERSHIP AND ORDER — THE PROSE-IMMUNE HALF, and the property
        //    assertion 1 below was licensed for but does not have. It carries
        //    no byte literal, so no prose edit anywhere in
        //    RepoMapBlock/MemoryBlock/SkillMatcher can red it; what reds it is
        //    a layer leaving the region, or arriving in the wrong place.
        //    MEASURED: demoting `<repo-map>` in `Runtime::buildSystemPrompt()`
        //    to sit immediately before `<env>` reds this at
        //    `<project-instructions>`, at
        //    `Failed asserting that 2481 is greater than 3329`. Read the
        //    message for what it is: `<project-instructions>` is the layer that
        //    STAYED and `<repo-map>` is the one that moved, so the name in the
        //    message is the first layer to find itself on the wrong side of the
        //    fence, not the culprit. An earlier revision of this line called it
        //    "the layer that left", which is the opposite.
        //
        //    The FIRST iteration is an equality, not an ordering: `<repo-map>`
        //    must open the region exactly, at `$mapAt`. Seeding the ordering
        //    with `$mapAt - 1` instead, as an earlier revision did, made
        //    iteration one `assertGreaterThan($mapAt - 1, $mapAt)` — true for
        //    every possible input, which is one of the 304 assertions doing
        //    nothing.
        $boundaries = [];
        $previous = null;

        foreach (self::STABLE_LAYER_WIDTHS as $marker => $width) {
            $at = strpos($first, "\n\n" . $marker);
            $this->assertIsInt(
                $at,
                'the "' . $marker . '" layer is not in the assembled prompt at all, so the region between '
                    . '<repo-map> and <env> cannot be the five layers P3.S1 lifted',
            );

            if ($previous === null) {
                $this->assertSame(
                    $mapAt,
                    $at,
                    'the region P3.S1 lifted does not open at the "' . $marker . '" fence: that layer starts at '
                        . 'byte ' . $at . ' and the region starts at ' . $mapAt,
                );
            } else {
                $this->assertGreaterThan(
                    $previous,
                    $at,
                    'the "' . $marker . '" layer is out of assembly order at byte ' . $at
                        . ' - the layers between <repo-map> and <env> were reordered',
                );
            }

            $this->assertLessThan(
                $envAt,
                $at,
                'the "' . $marker . '" layer starts at byte ' . $at . ', at or after <env> at ' . $envAt
                    . ' - it left the region P3.S1 lifted',
            );
            $boundaries[$marker] = $at;
            $previous = $at;
        }

        // The roster this loop walks and the roster the two `substr_count`
        // loops walk are two hand-maintained lists of the same five layers, so
        // they are asserted to be the same list. Two hand-maintained constants,
        // not one derived from the other: this catches an edit to one of them,
        // and nothing about the assembler.
        //
        // ALL FOUR of the keyed rosters are checked, not one of them. An
        // earlier revision guarded only STABLE_LAYER_WIDTHS. MEASURED: adding a
        // `<bogus-layer>` key to the other three left the file at
        // `OK (14 tests, 368 assertions)`, and DELETING a key from
        // STABLE_LAYER_OWNERS turned the owner-naming failure message into a
        // PHP `Undefined array key` warning — the message this file's whole F5
        // repair rests on, rendered as `written by `.
        foreach ([
            'STABLE_LAYER_WIDTHS' => self::STABLE_LAYER_WIDTHS,
            'STABLE_LAYER_FIXTURE_WIDTHS' => self::STABLE_LAYER_FIXTURE_WIDTHS,
            'STABLE_LAYER_FIXTURE_FRAGMENTS' => self::STABLE_LAYER_FIXTURE_FRAGMENTS,
            'STABLE_LAYER_OWNERS' => self::STABLE_LAYER_OWNERS,
        ] as $name => $roster) {
            $this->assertSame(
                self::STABLE_LAYER_MARKERS,
                array_keys($roster),
                $name . ' and STABLE_LAYER_MARKERS have drifted apart: ' . json_encode(array_keys($roster)),
            );
        }
        $this->assertSame(
            self::STABLE_LAYERS_BYTES,
            array_sum(self::STABLE_LAYER_WIDTHS),
            'the per-layer widths no longer sum to STABLE_LAYERS_BYTES',
        );

        // WHAT MOVED, AND HOW MUCH IT MOVED, ARE THREE DIFFERENT STATEMENTS.
        // AN EARLIER REVISION OF THIS PARAGRAPH SAID THEY WERE ASSERTED
        // "strongest-first, because the coarser two are implied by this one".
        // That is true of assertion 2 and FALSE of assertion 3, and the file
        // contradicted itself about assertion 3 seventeen lines further down.
        // MEASURED: shift the rotation point of {@see reassembledWithEnvAtLayerTwo()}
        // by one byte (splice at `$mapAt + 1`, which preserves length so the
        // guard above it stays green) and assertion 1 holds at 1,575 while
        // assertion 3 reds at 1,574. 1 and 3 are INCOMPARABLE — 1 is about the
        // assembler's layout and 3 is about the splice helper, and neither
        // implies the other. "Strongest-first" is not a total order; what is
        // true is that 2 is implied by 1 and 3 together, which is why 2 can
        // never be the first to red and is kept for its wording rather than
        // for its verdict.
        //
        // 1. THIS ONE BINDS THE ASSEMBLER'S SIZE: the region between the two
        //    fences is exactly the five layers P3.S1 lifted, at their measured
        //    widths. MEASURED, by demoting `<repo-map>` in
        //    `Runtime::buildSystemPrompt()` so only one layer sits between the
        //    fences, the total reds with 727 against 1,575.
        //
        //    It is pinned PER LAYER, and inside each layer the fixture's own
        //    bytes are counted separately from production's, because 81.7 % of
        //    these 1,575 bytes are prose this file does not own (MEASURED; see
        //    {@see STABLE_LAYERS_BYTES}) and a four-byte prose edit in
        //    RepoMapBlock is a LEGITIMATE change that must red with a message
        //    naming its own cause. An earlier revision listed three possible
        //    causes and left the reader to pick; the offsets to tell them apart
        //    were already in this test, so it now tells them apart:
        //
        //      - a fixture fragment that stopped appearing  -> the
        //        `substr_count` assertion, naming the fragment;
        //      - the fixture's own content resized          -> the
        //        fixture-width assertion, naming this file;
        //      - production prose resized                   -> the
        //        production-width assertion, naming the class that wrote it.
        $offsets = array_values($boundaries);
        $offsets[] = $envAt;

        foreach (array_keys(self::STABLE_LAYER_WIDTHS) as $index => $marker) {
            $segment = substr($first, $offsets[$index], $offsets[$index + 1] - $offsets[$index]);
            $measured = \strlen($segment);
            $fixtureBytes = 0;

            foreach (self::STABLE_LAYER_FIXTURE_FRAGMENTS[$marker] as $fragment) {
                $this->assertSame(
                    1,
                    substr_count($segment, $fragment),
                    'the fixture fragment ' . json_encode($fragment) . ' no longer appears exactly once inside '
                        . 'the "' . $marker . '" layer, so the fixture/production byte split for that layer '
                        . 'cannot be computed. This file writes that fragment into the fixture; if the fixture '
                        . 'changed, change the constant it is written from',
                );
                $fixtureBytes += \strlen($fragment);
            }

            // A drift guard between two hand-maintained constants, not a fact
            // about the assembler: `$fixtureBytes` is the sum of the fragment
            // constants and this is the literal beside them. It reds when the
            // fixture's own content is resized, which is the case the width
            // assertion below must NOT be blamed for.
            $this->assertSame(
                self::STABLE_LAYER_FIXTURE_WIDTHS[$marker],
                $fixtureBytes,
                'THIS FILE resized its own fixture content inside the "' . $marker . '" layer: it now writes '
                    . $fixtureBytes . ' bytes there, not ' . self::STABLE_LAYER_FIXTURE_WIDTHS[$marker]
                    . '. Re-measure STABLE_LAYER_FIXTURE_WIDTHS and STABLE_LAYER_WIDTHS together; no production '
                    . 'code is involved',
            );

            // THE ONE THAT A PROSE EDIT REDS, and it names the owner.
            $expectedProduction = self::STABLE_LAYER_WIDTHS[$marker] - self::STABLE_LAYER_FIXTURE_WIDTHS[$marker];
            $this->assertSame(
                $expectedProduction,
                $measured - $fixtureBytes,
                'the production-authored half of the "' . $marker . '" layer is now '
                    . ($measured - $fixtureBytes) . ' bytes, not ' . $expectedProduction . '. Those bytes are '
                    . 'written by ' . self::STABLE_LAYER_OWNERS[$marker] . ', NOT by this file, and later steps '
                    . 'of this plan are licensed to edit exactly that prose - so this is most likely a '
                    . 'legitimate change: re-measure STABLE_LAYER_WIDTHS and move it. The fixture-width '
                    . 'assertion above stayed green, so this file did not cause it',
            );

            // FORCED by the two assertions above — their conjunction IS this
            // one — and labelled rather than left reading like a third
            // independent check, the same way the region total below is. Kept
            // because it is the per-layer figure a reader looks up in
            // {@see STABLE_LAYER_WIDTHS}, stated in the units that table uses.
            $this->assertSame(
                self::STABLE_LAYER_WIDTHS[$marker],
                $measured,
                'the "' . $marker . '" layer occupies ' . $measured . ' bytes, not '
                    . self::STABLE_LAYER_WIDTHS[$marker] . '; the two assertions above say which half moved',
            );
        }

        // FORCED, NOT MEASURED, and labelled as such rather than left reading
        // like the assertion that binds. `$boundaries['<repo-map>']` is the
        // same `strpos` as `$mapAt`, so the per-layer widths above telescope to
        // `$envAt - $mapAt`; with the sum identity asserted at the head of this
        // block, this equality cannot fail unless one of them already has. An
        // earlier revision of the comment above claimed "MEASURED, by demoting
        // `<repo-map>` … the total reds with 727 against 1,575". MEASURED under
        // exactly that demotion, what reds is assertion 0, sixty-one assertions
        // earlier, and this line is never reached. Kept because a coarse
        // statement of the same fact is what a reader looking for the step's
        // number will find, and because it is the assertion the failure
        // messages above point back at.
        $this->assertSame(
            self::STABLE_LAYERS_BYTES,
            $envAt - $mapAt,
            'the region between <repo-map> and <env> is ' . ($envAt - $mapAt) . ' bytes, not '
                . self::STABLE_LAYERS_BYTES . ' - the per-layer assertions above say which layer moved and '
                . 'which half of it',
        );

        // 2. THE GAIN FLOOR, in the units the step text uses ("the reorder moved
        //    the first-difference position by N bytes"). Given 1 and 3 it is
        //    now arithmetically implied and cannot be the assertion that reds
        //    first — it is kept because it is the claim the step makes, stated
        //    where a reader looking for the step's number will find it, and
        //    because it survives edits to this file's fixture content that
        //    would force 1 to be re-measured.
        $this->assertGreaterThanOrEqual(
            self::MIN_PREFIX_GAIN_BYTES,
            $prefix - $oldPrefix,
            'the reorder moved the first differing byte by only ' . ($prefix - $oldPrefix) . ' bytes',
        );

        // 3. AND THE GAIN IS THAT SAME NUMBER — but read this for what it is.
        // The equality is FORCED by three assertions already made above, so it
        // can never be the assertion that catches a defect in the assembler:
        // `assertNotSame($first, $second)` plus equal lengths plus
        // `$prefix > $envAt` give that the two prompts share every byte up to
        // `$envAt` and first differ at some offset `d` inside `<env>`; the
        // splice is the rotation `B·R·E -> B·E·R`, so the shipped order
        // diverges at `|B| + |R| + d` and the old one at `|B| + d`, and the
        // difference is `|R|` identically, for every possible input.
        //
        // It is therefore a GUARD ON THE SPLICE HELPER, exactly like the
        // length check above it, and an earlier revision of this comment
        // claimed the opposite — that it catches a reorder lifting only some of
        // the layers, which "the floor above cannot". MEASURED, that is
        // inverted: the demotion mutation described above leaves this identity
        // holding exactly (727 = 727), and what reds is assertion 1.
        //
        // THE PARENTHESIS THAT USED TO CLOSE THAT SENTENCE NAMED THE WRONG
        // ASSERTION. It read "(or, before assertion 1 existed, the floor)", and
        // "the floor" means {@see MIN_STABLE_PREFIX_BYTES} everywhere else in
        // this file. MEASURED under the demotion mutation, with the assertion-1
        // and assertion-0 values computed directly: the region is 727, the gain
        // is 727, `$prefix` is **4,670 — unmoved** — and `$oldPrefix` is 3,943.
        // So MIN_STABLE_PREFIX_BYTES PASSES, `$prefix > $envAt` passes and
        // `$prefix > $diffAt` passes; what would have red before assertion 1
        // existed is the GAIN floor {@see MIN_PREFIX_GAIN_BYTES}, at 727
        // against 1,500. The demotion moves layers around WITHIN the shared
        // prefix, so it cannot move the first differing byte at all — which is
        // exactly why the magnitude floor is blind to it and the gain floor is
        // not. Kept because a helper guard is worth having and this one is
        // free; labelled correctly because a guard advertised as something
        // stronger is how a test file stops being read.
        $this->assertSame(
            $envAt - $mapAt,
            $prefix - $oldPrefix,
            'the re-splice moved the first differing byte by ' . ($prefix - $oldPrefix) . ' bytes while rotating '
                . 'a ' . ($envAt - $mapAt) . '-byte region - reassembledWithEnvAtLayerTwo() is not a pure '
                . 'rotation any more',
        );
    }

    /**
     * The floor is a floor across the shapes of a change that moves `<env>` and
     * nothing ahead of it — not a property of one lucky edit.
     *
     * READ THE NAME AS A SCOPE, NOT AS A BOAST. An earlier revision of this
     * test was called `…ForEveryShapeOfBetweenStepChange` and its docblock
     * claimed the worst case was "bounded by WHERE `<env>` starts, not by how
     * big the diff gets". A review found the counterexample in one command: a
     * turn that CREATES a `.php` file moves `<repo-map>`'s per-directory file
     * count, which P3.S1 measured at byte 3,188 — ahead of `<env>` and, as the
     * floor then lay, below it. P5.S5's static voice layer ahead of the map has
     * since lifted that shape over the floor (its own test records the flip);
     * it is still out of scope HERE, which pins only changes that move `<env>`
     * and nothing ahead of it —
     * {@see testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne()}.
     *
     * The test above drives the nicest in-scope shape there is: the same file
     * edited again, so `git status --porcelain` is byte-identical across the
     * two renders and the first difference is an abbreviated blob hash deep
     * inside the diff body. Two harsher in-scope shapes exist in an ordinary
     * session and both move the divergence EARLIER, because `<env>` emits the
     * caveat, the branch, the status and the log AHEAD of the diff:
     *
     *   - a working diff LARGER than {@see EnvironmentBlock::DIFF_MAX_BYTES}
     *     whose two revisions differ in size, so the `--shortstat` line that
     *     leads the diff section changes before any patch byte does;
     *   - a SECOND file dirtied between the steps, so the `Status:` field
     *     itself changes — the earliest field OF `<env>` a write can move,
     *     which is not the same claim as the earliest byte of the PROMPT a
     *     write can move.
     *
     * MEASURED on this fixture: 4,583 and 4,403 against the nice shape's 4,670,
     * with `<env>` opening at 4,056. Every one of them still carries the whole
     * stable region, and every one still clears
     * {@see MIN_STABLE_PREFIX_BYTES}.
     *
     * THE BOUND AND THE FLOOR ARE 40 BYTES APART, AND SAYING SO IS THE POINT.
     * (An earlier revision of this heading said 61. That is a third quantity —
     * the length of the frozen region the body derives below, 4,117 - 4,056 —
     * and putting it in a heading whose whole subject is two numbers being
     * confused was the same mistake one level up.)
     * An earlier revision of this paragraph read "the worst case IS bounded by
     * where `<env>` starts however big the diff gets" and offered that as the
     * justification for the floor. It is not one: "bounded by where `<env>`
     * starts" gives >= 4,056, and 4,056 does not imply 4,096. What closes the
     * gap is the MEASURED byte map of the fence and the line after it —
     *
     *     4,056  "\n\n<env>\n"
     *     4,064  Working directory: /tmp/crush_promptfix_<12 hex>
     *     4,117  Is directory a git repo: Yes
     *
     * — where every byte from 4,056 to 4,116 is the fence plus a value
     * {@see \SugarCraft\Crush\Context\EnvironmentBlock::capture()} FROZE, so
     * it cannot differ between two renders of one fixture. The earliest
     * divergence any in-class change can actually produce is therefore 4,117,
     * which is 21 B above the floor rather than 40 B below it. What putting the
     * block last buys is that the bound exists at all and does not move with
     * the size of the diff; what makes 4,096 safe is that the first 61 bytes
     * of `<env>` are frozen.
     *
     * The three prefixes are also asserted to be DISTINCT and ordered. Three
     * scenarios that silently produced the same number would be three copies of
     * one test, and the ordering is the derived statement that each one bit
     * where it was supposed to.
     */
    public function testTheFloorHoldsForEveryChangeThatMovesOnlyTheEnvBlock(): void
    {
        // Shape 1 — the nice one, repeated here so the three numbers come from
        // one run and are directly comparable.
        $nice = $this->dirtyRepoFixtureWithEveryStableLayer();
        $niceFirst = $nice->systemPrompt();
        $nice->write('src/Alpha.php', self::ALPHA_SECOND_EDIT);
        $nicePrefix = self::commonPrefixLength($niceFirst, $nice->systemPrompt());

        // Shape 2 — a working diff over the cap whose two revisions differ in
        // size. The cap firing is a KNOWN-POSITIVE CONTROL: without it this is
        // just a bigger version of shape 1.
        $capped = $this->dirtyRepoFixtureWithEveryStableLayer();
        $capped->write('src/Alpha.php', self::generatedLines(400, 'A'));
        $cappedFirst = $capped->systemPrompt();
        $capped->write('src/Alpha.php', self::generatedLines(405, 'B'));
        $cappedSecond = $capped->systemPrompt();

        // The cap FIRED — asserted through the marker the block itself emits,
        // not through the prompt's length, which is capped and therefore cannot
        // grow past the bound however big the diff gets. That was the first
        // version of this assertion and it was measuring the cap with a ruler
        // the cap had already shortened: `strlen($cappedFirst) - strlen($niceFirst)`
        // came back 7,907 against a DIFF_MAX_BYTES of 8,192 for a diff of tens
        // of kilobytes.
        foreach ([$cappedFirst, $cappedSecond] as $rendered) {
            $this->assertStringContainsString(
                '... [truncated: ',
                $rendered,
                'the generated revision is too small to reach the diff cap; this shape is not the pathological one',
            );
            $this->assertStringContainsString(
                'insertions(+)',
                $rendered,
                'the diff section did not render a --shortstat line to diverge on',
            );
        }
        $cappedPrefix = self::commonPrefixLength($cappedFirst, $cappedSecond);

        // Shape 3 — a second tracked file dirtied, so `Status:` moves. This is
        // the earliest field of <env> an ordinary write can reach.
        $status = $this->dirtyRepoFixtureWithEveryStableLayer();
        $statusFirst = $status->systemPrompt();
        $status->write('src/Beta.php', "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Beta { public int \$two = 2; }\n");
        $statusSecond = $status->systemPrompt();
        $this->assertStringContainsString(' M src/Beta.php', $statusSecond, 'the second write did not reach `git status`');
        $statusPrefix = self::commonPrefixLength($statusFirst, $statusSecond);

        // EACH SHAPE IS COMPARED AGAINST ITS OWN FIXTURE'S `<env>` OFFSET, not
        // against the nice fixture's. All three are 4,056 today, so borrowing
        // one would be correct by construction — and silently wrong the first
        // time the region ahead of `<env>` becomes content-dependent. It
        // already is in part: {@see \SugarCraft\Crush\Context\RepoMapBlock}
        // counts `.php` files, and the capped fixture's `src/Alpha.php` is a
        // different SIZE, not a different count, only because this shape was
        // chosen that way. A borrowed offset would let a divergence sitting
        // BEFORE that fixture's real `<env>` pass an assertion whose message
        // says it did not. The same argument is made at length by
        // {@see testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne()};
        // this test used to be the one place in the file that did not follow it.
        $envOffsets = [];

        foreach ([
            'same file edited again' => [$nicePrefix, $niceFirst],
            'diff over the cap, revisions of different size' => [$cappedPrefix, $cappedFirst],
            'a second file dirtied' => [$statusPrefix, $statusFirst],
        ] as $shape => [$prefix, $first]) {
            $envAt = strpos($first, "\n\n<env>\n");
            $this->assertIsInt(
                $envAt,
                'shape "' . $shape . '" produced a prompt with no <env> fence at all',
            );
            $envOffsets[$shape] = $envAt;

            $this->assertGreaterThanOrEqual(
                self::MIN_STABLE_PREFIX_BYTES,
                $prefix,
                'shape "' . $shape . '" left only ' . $prefix . ' bytes of shared prefix',
            );
            $this->assertGreaterThan(
                $envAt,
                $prefix,
                'shape "' . $shape . '" diverged before <env> began, so a stable layer is volatile',
            );
        }

        // The three ordering comparisons below put prefix lengths measured on
        // three DIFFERENT repositories on one number line, which is only
        // meaningful while the layers ahead of `<env>` assemble to the same
        // length in all three. That is a premise, so it is asserted rather than
        // assumed — if a future block makes the pre-`<env>` region depend on
        // file sizes or LOC, this reds here with a clear reason instead of
        // reddening the ordering assertions with a misleading one.
        $this->assertSame(
            [$envOffsets['same file edited again']],
            array_values(array_unique($envOffsets)),
            'the three fixtures no longer agree on where <env> starts (' . json_encode($envOffsets)
                . '), so their prefix lengths are not comparable to each other',
        );

        // Each shape bit somewhere different, and in the order the layout
        // predicts: `Status:` is ahead of `--shortstat`, which is ahead of the
        // patch body. If two of these ever collapse onto one number, one of the
        // three fixtures has stopped exercising what it claims to.
        $this->assertLessThan($cappedPrefix, $statusPrefix, 'the `Status:` shape must diverge earliest');
        $this->assertLessThan($nicePrefix, $cappedPrefix, 'the `--shortstat` shape must diverge before the patch body');
    }

    /**
     * THE LIMIT OF WHAT P3.S1 BOUGHT, pinned rather than left to be
     * rediscovered — and since 2026-09-05, the record of that limit MOVING.
     *
     * Moving `<env>` last does not make everything ahead of it stable, because
     * `<repo-map>` is derived from the working tree too.
     * {@see \SugarCraft\Crush\Context\RepoMapBlock} emits a per-directory count
     * of `.php` files. Create one and `- src/  ->  Fixture\Prefix\  (2 files)`
     * becomes `(3 files)` — 707 B INSIDE the map. P3.S1 measured that byte at
     * 3,188: ahead of `<env>` (then 4,056), ahead of the instruction documents,
     * the memory block and both skill layers, and BELOW
     * {@see MIN_STABLE_PREFIX_BYTES}. A turn that adds a source file therefore
     * re-prefilled almost everything, and no amount of moving `<env>` changed
     * that.
     *
     * WHAT SAVES IT IS A LIFETIME, AND THE TWO ARE WORTH TELLING APART.
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} runs once per STEP
     * of the agentic loop and reads a repo map memoised on the Runtime, while
     * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} builds a FRESH
     * Runtime per user TURN. So:
     *
     *   - within one turn, the map is frozen and a new file moves only `<env>`
     *     — MEASURED 2026-09-05, PHP 8.3.6, Linux 6.8.0-138-generic, this
     *     worktree: prefix 5,977, still the same 347 B-into-`<env>` shape as
     *     any other `Status:` change, riding the fence's new position at 5,630;
     *   - across turns, the map is re-captured and the prefix diverges again at
     *     the file count — 4,762, still the same 707 B-into-the-map shape P3.S1
     *     measured at 3,188, riding the map's new position at 4,055.
     *
     * Both are asserted below, from ONE fixture in ONE test, because the pair
     * is the finding: the within-turn number alone reads as "the reorder
     * worked" and the across-turn number alone reads as "the reorder did
     * nothing", and neither sentence is true on its own.
     *
     * THE ACROSS-TURN PIN FLIPPED, AND THIS PARAGRAPH IS THE DELIBERATE
     * REWRITE THE PREVIOUS VERSION AUTHORIZED. It licensed a flip if a later
     * step made the map stable across turns — "it should be rewritten
     * deliberately rather than deleted quietly" — and the flip arrived by a
     * DIFFERENT route: the map is NOT stable (it is still re-captured per
     * turn, and the structural assertions below, inside-map and ahead-of-`<env>`,
     * pass untouched). P5.S5 wired a 1,191 B STATIC voice layer at section
     * index one, AHEAD of the map, and the divergence rode it over the floor:
     * MEASURED 4,762 at this tip, against 3,571 re-measured with that wiring
     * commented out in a sandbox copy (the 3,188 -> 3,571 part is ordinary
     * static-prose growth — P3.S1's figure stays what this family says it is,
     * a dated observation; both post-flip figures were re-measured, not
     * corrected by arithmetic, per the rule closing the floor's own doc-block).
     *
     * SO THE TEST POLICES THE IMPROVEMENT, NOT A NUMBER. The shared floor
     * constant stayed 4,096 — the within-turn half and the two tests above
     * (cache-prefix reach, env-only shapes) bind it from the same side and
     * stay green at the new figures; the family's doctrine for moving the
     * constant is re-measure-and-move, and nothing here re-measures THAT
     * property. What is pinned is the side of
     * the floor a file-creating across-turn render now lives on: 666 B of
     * slack above it, consumable by later prose edits ahead of the map before
     * this reddens, exactly like the slack the floor's doc-block prices. If a
     * later step makes the map ITSELF stable across turns, the STRUCTURAL
     * assertions flip next — same licence: rewrite deliberately, do not delete
     * quietly. The finding about the map's own volatility lives in
     * `src/Context/RepoMapBlock.php` and `src/Backend/EngineBackend.php`, both
     * outside this step's declared file list, so it was reported in the worklog
     * and pinned here rather than fixed.
     */
    public function testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne(): void
    {
        $fixture = $this->dirtyRepoFixtureWithEveryStableLayer();
        $app = $fixture->app();

        // ACROSS TURNS: each systemPrompt() call without an explicit Runtime
        // gets a fresh one, which is what EngineBackend::complete() does per
        // user turn.
        $before = $fixture->systemPrompt($app);
        $this->assertStringContainsString(
            '- src/  ->  Fixture\Prefix\  (2 files)',
            $before,
            'the repo map does not carry the per-directory file count this test is about',
        );

        $fixture->write('src/Gamma.php', "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Gamma {}\n");
        $after = $fixture->systemPrompt($app);
        $this->assertStringContainsString('- src/  ->  Fixture\Prefix\  (3 files)', $after);

        $acrossTurns = self::commonPrefixLength($before, $after);
        $mapAt = strpos($before, "\n\n<repo-map>");
        $envAt = strpos($before, "\n\n<env>\n");
        $this->assertIsInt($mapAt);
        $this->assertIsInt($envAt);

        $this->assertGreaterThan(
            $mapAt,
            $acrossTurns,
            'the divergence should be INSIDE <repo-map>, not ahead of it',
        );
        $this->assertLessThan(
            $envAt,
            $acrossTurns,
            'if a new source file no longer voids the prefix ahead of <env>, the repo map became stable — '
                . 'rewrite this test deliberately, do not delete it',
        );
        // THE FLIPPED PIN (P5.S5 — see the doc-block). P3.S1 pinned
        // `acrossTurns < floor` as a LIMITATION; this step's static voice
        // layer ahead of <repo-map> lifted the divergence over the floor, so
        // the relation is now pinned the other way and POLICES THE LIFT: if
        // the layer is unwired, or prose ahead of the map is cut past the
        // slack, the cache posture this step bought is gone and this reddens
        // naming it.
        $this->assertGreaterThan(
            self::MIN_STABLE_PREFIX_BYTES,
            $acrossTurns,
            'the across-turn prefix collapsed to ' . $acrossTurns . ' bytes, back below the floor of '
                . self::MIN_STABLE_PREFIX_BYTES . ' - the static maxims layer P5.S5 wired ahead of <repo-map> '
                . 'is what carries this shape over the floor; if it is gone the lift is gone, and if only the '
                . 'prose ahead of the map shrank, the floor\'s 666 B of slack just priced that edit honestly (P5.S5)',
        );

        // WITHIN ONE TURN: the same two writes, one Runtime. buildSystemPrompt()
        // reads the memoised repoMapSnapshot(), so the map cannot move and the
        // only thing left to diverge is <env>.
        $sameTurn = $this->dirtyRepoFixtureWithEveryStableLayer();
        $sameTurnApp = $sameTurn->app();
        $runtime = new Runtime(
            $sameTurnApp->provider,
            new HookManager(new HookRegistry()),
            new EnvironmentBlock($sameTurn->root(), $sameTurnApp->model, new DateTimeImmutable(self::FIXTURE_NOW), self::FIXTURE_PLATFORM),
        );

        // The hand-built Runtime must be the one PromptFixture would have made,
        // or this half is measuring a different prompt from the half above.
        // Byte equality against the fixture's own default is what says so.
        $this->assertSame(
            $sameTurn->systemPrompt($sameTurnApp),
            $sameTurn->systemPrompt($sameTurnApp, $runtime),
            'PromptFixture no longer builds its Runtime the way this test does',
        );

        $stepOne = $sameTurn->systemPrompt($sameTurnApp, $runtime);
        $sameTurn->write('src/Gamma.php', "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Gamma {}\n");
        $stepTwo = $sameTurn->systemPrompt($sameTurnApp, $runtime);

        $this->assertStringContainsString(
            '- src/  ->  Fixture\Prefix\  (2 files)',
            $stepTwo,
            'the memoised repo map moved inside a single turn',
        );

        // Read from THIS fixture's own prompt, not from $before's. Both roots
        // are `sys_get_temp_dir() . '/crush_promptfix_' . 12 hex` and both
        // offsets are 4,056 today, so reusing the other one would be correct by
        // construction — and silently wrong the day a fixture root's length
        // stops being constant. The two are asserted equal instead, which says
        // the coupling out loud rather than depending on it.
        $sameTurnEnvAt = strpos($stepOne, "\n\n<env>\n");
        $this->assertIsInt($sameTurnEnvAt);
        $this->assertSame(
            $envAt,
            $sameTurnEnvAt,
            'the two fixtures no longer lay out identically, so their byte offsets are not comparable',
        );

        // WHAT MAKES THE TWO RENDERS DIFFER AT ALL, asserted before the
        // difference is measured. `src/Gamma.php` is UNTRACKED, so the only
        // field of `<env>` that can see it is `git status --porcelain`, and
        // whether that command reports an untracked file is decided by
        // `status.showUntrackedFiles`. MEASURED: delete the repo-local pin from
        // dirtyRepoFixtureWithEveryStableLayer() and run this suite with a
        // global `status.showUntrackedFiles=no`, and the assertion below reds
        // as `Failed asserting that two strings are not identical` — a message
        // that names neither the file nor the knob. This one names both, and it
        // reds first.
        $this->assertStringContainsString(
            "\n?? src/Gamma.php\n",
            $stepTwo,
            'the untracked src/Gamma.php did not reach `git status --porcelain`, so the two renders have '
                . 'nothing to differ about. TWO MEASURED causes, not one: the repo-local '
                . '`status.showUntrackedFiles=normal` pin in dirtyRepoFixtureWithEveryStableLayer() is gone or '
                . 'was overridden - OR a gitignore source (an in-tree `.gitignore`, `.git/info/exclude`, or a '
                . 'global `core.excludesFile`) is excluding the path, which no `status.*` pin defends',
        );

        $withinTurn = self::commonPrefixLength($stepOne, $stepTwo);
        $this->assertNotSame($stepOne, $stepTwo, '<env> must still track the new file within the turn');
        $this->assertGreaterThanOrEqual(
            self::MIN_STABLE_PREFIX_BYTES,
            $withinTurn,
            'within one turn the shared prefix fell to ' . $withinTurn . ' bytes, below the floor',
        );
        $this->assertGreaterThan(
            $sameTurnEnvAt,
            $withinTurn,
            'within one turn the memoised layers must all stay inside the shared prefix',
        );
        $this->assertGreaterThan(
            $acrossTurns,
            $withinTurn,
            'the two lifetimes must differ, or this test is measuring one thing twice',
        );
    }

    /**
     * EVERY GIT FIELD IN `<env>` CARRIES A REAL VALUE ON THIS FIXTURE, not a
     * degraded placeholder that reads to the model as an answer.
     *
     * WHY THIS EXISTS RATHER THAN A FOURTEENTH CONFIG PIN. The `foreach` in
     * {@see dirtyRepoFixtureWithEveryStableLayer()} carries SIXTEEN `config`
     * rows, of which THIRTEEN are hazard pins; the other three
     * (`user.email`, `user.name`, `commit.gpgsign`) are identity and are not
     * counted, which is the convention every count in this file uses and which
     * an earlier revision of this sentence stated both ways two lines apart.
     * The hazard list has grown at EVERY review that looked for another one —
     * four, seven, eight, ten, eleven, now thirteen plus an attributes
     * file. It is a hand-maintained roster, and a test over a hand-maintained
     * list inherits that list's omissions. Worse, the omissions have a shape:
     * three separate reviews found a knob that MOVED THE BYTES AND RED
     * NOTHING, which is the wrong-green direction. This test is the guard built
     * over the POPULATION instead of over the enumerated sites: it asserts what
     * the fields must LOOK like, so an unpinned knob in any family reds here
     * whether or not anybody ever added it to the roster.
     *
     * MEASURED 2026-08-31, in this worktree, each hostile setting in a
     * `GIT_CONFIG_GLOBAL` of its own with the whole suite otherwise untouched.
     * All four of these left `PromptStabilityTest` at `OK (13 tests, 229
     * assertions)` before this test existed:
     *
     *   - `log.date=true`      -> `Recent commits:` renders
     *                             `unavailable (git exited 128)`. `true` is not
     *                             a date format, so `git log` dies.
     *   - `format.pretty=true` -> the same 128, by the same route.
     *   - `color.branch.current=true` -> `Current branch:` renders EMPTY.
     *   - `GIT_DIFF_OPTS=-u10` (an environment variable, not a config key, and
     *                          therefore outside the reach of every repo-local
     *                          pin) -> prompt 4,844 -> 4,851 B, the hunk header
     *                          `@@ -2,4 +2,4 @@` becomes `@@ -1,5 +1,5 @@`.
     *
     * And one that moved the bytes while reddening only the OVER-CAP shape,
     * whose message named the cap and not the cause — `core.attributesFile`
     * naming a file that says `*.php -diff`, prompt 4,844 -> 4,749, the patch
     * body replaced by `Binary files a/src/Alpha.php and b/src/Alpha.php
     * differ`. The same damage arrives from `init.templateDir` (which seeds
     * `$GIT_DIR/info/attributes`) and from a bare `XDG_CONFIG_HOME` containing
     * `git/attributes`: MEASURED, all three give 4,749/4,672.
     *
     * A NOTE ON WHAT A PIN CAN AND CANNOT REACH, because it is the reason this
     * test is not replaceable by more rows in that `foreach`.
     *
     * An INVALID value in a lower-precedence file is fatal even when a
     * higher-precedence file overrides it — with `color.branch.current=normal`
     * set repo-locally, `git config --get color.branch.current` answers
     * `normal` and `git branch --show-current` still dies with
     * `fatal: bad config variable 'color.branch.current' ... exit 128`.
     *
     * THE REASON USED TO BE WRITTEN HERE AS "git PARSES every config file in
     * the precedence chain before it uses any of them", AND THAT IS FALSE. If
     * it were true every git subprocess would die on any invalid value
     * anywhere; MEASURED on git 2.43.0, they do not. The conversion is per
     * COMMAND and per KEY: a command's config callback converts the value of
     * each key IT consumes as it walks the chain, so a bad value is fatal for
     * the commands that read that key and inert for the ones that do not.
     * MEASURED, invalid in a global with a VALID value pinned repo-locally —
     * `log.abbrevCommit = nonsense` kills `log --oneline` (128) and leaves
     * `status --porcelain`, `branch --show-current` and
     * `diff --shortstat --patch` at 0; `color.branch.current = true` does the
     * exact reverse, killing only `branch --show-current`.
     *
     * SO THE SCOPE OF THE VERDICT IS NARROWER THAN IT USED TO READ: an invalid
     * value for a key a subprocess READS is undefendable by pinning and only
     * detectable; for a key it does not read it is inert. `log.date` and
     * `format.pretty` are the exception that proves the mechanism: their
     * handlers STORE the string and parse the format later, last value wins,
     * so pinning them repo-locally does work and they are pinned. MEASURED,
     * `log.date=nonsense` in a global is `fatal: unknown date format nonsense`
     * (128) with no pin, and exit 0 with `log.date = default` pinned.
     *
     * The three absence assertions run FIRST, ahead of every positive one, and
     * each runs its scanner over a known-positive control **rendered by the
     * production class under test** rather than typed into this file. Both of
     * those are deliberate and both were got wrong once here.
     *
     * ORDER: a degraded rendering deletes the shape the positive assertions
     * look for, so a positive assertion that fires first reports the wrong
     * knob. MEASURED — with the absences last, a hostile `core.attributesFile`
     * red this test on the HUNK HEADER, naming `GIT_DIFF_OPTS`.
     *
     * CONTROLS: an unfired instrument and a dead one produce identical
     * silence, and a control that is a string literal in this file cannot tell
     * them apart, because the scan of it never touches production. MEASURED —
     * with literal controls, renaming `unavailable (git exited {N})` in
     * `EnvironmentBlock` left the placeholder scan SILENTLY GREEN while all
     * three controls passed. The controls below build a directory with a `.git`
     * that is not a repository, a fixture whose attributes say `* -diff`, and a
     * fixture with `color.diff=always`, and assert what production renders for
     * each — so a rename over there reds the control, which is the message that
     * says the scanner needs updating.
     */
    public function testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder(): void
    {
        $fixture = $this->dirtyRepoFixtureWithEveryStableLayer();

        // Untracked on purpose: it is the only thing in this file that exercises
        // the `status.showUntrackedFiles` pin on THIS fixture, and a comment in
        // the knob list used to claim no such thing existed.
        $fixture->write('src/Gamma.php', "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Gamma {}\n");

        $prompt = $fixture->systemPrompt();

        // ORDER MATTERS HERE, and it is the ABSENCES FIRST on purpose. A
        // degraded rendering removes the very shape the positive assertions
        // below look for — a `-diff` attribute leaves no `@@` hunk header at
        // all — so a positive assertion placed first reds with a message about
        // its own knob while the real cause sits two assertions further down,
        // unread. MEASURED: with the absences last, a hostile
        // `core.attributesFile` red this test at "the unstaged diff is not
        // rendered at three lines of context … GIT_DIFF_OPTS", which is the
        // wrong knob, the wrong family and the wrong repair.

        // 0. THE KNOWN-POSITIVE CONTROLS, and they are RENDERED BY PRODUCTION,
        //    not typed here. An earlier revision of this block built the
        //    control as a string literal three lines above the scans of it,
        //    which tests `substr_count()` and nothing else. MEASURED, that
        //    version was worth nothing: rename `unavailable (git exited {N})`
        //    at {@see \SugarCraft\Crush\Context\EnvironmentBlock}:907 and :969
        //    to `unavailable (git failed {N})`, force the placeholder with
        //    `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=log.date
        //    GIT_CONFIG_VALUE_0=true`, and the absence scan below went SILENTLY
        //    GREEN while all three literal controls still passed — the test then
        //    red two assertions later on the log SHAPE, naming `i18n.*`. Wrong
        //    knob, wrong family, wrong repair: an unfired instrument and a dead
        //    one produce identical silence, and a literal control cannot tell
        //    them apart because it never touches the instrument.
        //
        //    Each control below is the real class rendering the real degraded
        //    output, so a production rename reds HERE, on the control, which is
        //    the message that says the scanner needs updating.

        //    THE CONTROLS ARE LIVENESS CHECKS AND NOTHING ELSE — `> 0`, not an
        //    exact count — and an earlier revision of this block got that wrong
        //    in a way that broke the very ordering the paragraph above argues
        //    for. It asserted `21` escape bytes and `4` placeholder fields, and
        //    both of those are FUNCTIONS OF THE HAZARDS UNDER TEST. MEASURED:
        //    under `GIT_DIFF_OPTS=-u10` the coloured control renders 22 escapes,
        //    not 21, so the control red FIRST and assertion 7 below — the one
        //    that names `GIT_DIFF_OPTS` — was never reached; under
        //    `log.date=true` it renders 19, and the placeholder absence
        //    assertion was never reached either. The exact counts are still
        //    asserted, at the END of this test where they cannot mask anything,
        //    with messages that name their own causes.

        //    A. Every git subprocess failing: a directory that HAS a `.git` (so
        //       EnvironmentBlock's `file_exists` gate opens) but is not a
        //       repository.
        $brokenRepo = $this->tempDir();
        $this->assertTrue(mkdir($brokenRepo . '/.git', 0o700, true), 'could not build the broken-repo control');
        $degraded = (new EnvironmentBlock(
            $brokenRepo,
            SglangProvider::DEFAULT_MODEL,
            new DateTimeImmutable(self::FIXTURE_NOW),
            self::FIXTURE_PLATFORM,
        ))->render();

        $this->assertGreaterThan(
            0,
            substr_count($degraded, self::GIT_UNAVAILABLE_MARKER),
            'the placeholder scanner is looking for ' . json_encode(self::GIT_UNAVAILABLE_MARKER)
                . ' and EnvironmentBlock renders nothing containing it for a git that cannot run at all. The '
                . 'scanner is dead: update GIT_UNAVAILABLE_MARKER to whatever gitField() and gitDiffSection() '
                . 'emit now, or the absence assertion below passes on every input',
        );

        //    A2. The SECOND spelling that shares the marker, pinned by its VALUE
        //        rather than by a control, because it is reachable only on a
        //        build with `proc_open` in `disable_functions` and this suite
        //        cannot produce one. Reflection is used to READ it; the
        //        assertion is on what it says, not on whether it exists (§1.11).
        $noProcess = new \ReflectionClassConstant(EnvironmentBlock::class, 'NO_PROCESS_REASON');
        $this->assertStringStartsWith(
            self::GIT_UNAVAILABLE_MARKER,
            (string) $noProcess->getValue(),
            'EnvironmentBlock::NO_PROCESS_REASON no longer starts with '
                . json_encode(self::GIT_UNAVAILABLE_MARKER) . ', so the absence scan below cannot see it',
        );

        //    B. A binary-rendered working diff, from a real `-diff` attribute.
        $binary = $this->dirtyRepoFixtureWithEveryStableLayer();
        $this->assertNotFalse(
            file_put_contents($binary->root() . '/.git/info/attributes', "* -diff\n"),
            'could not build the binary-diff control',
        );
        $binaryPrompt = $binary->systemPrompt();

        //       THE CONTROL'S OWN SUBPROCESS IS ASSERTED BEFORE ITS LIVENESS,
        //       and the reason is that this control blamed the wrong file once.
        //       MEASURED: with `diff.external` naming a command that cannot
        //       exec — a knob a developer's own `~/.gitconfig` can carry —
        //       `git diff --shortstat --patch` exits 128 on
        //       `fatal: external diff died`, EnvironmentBlock renders
        //       `unavailable (git exited 128)` in place of the patch, no
        //       `Binary files ` reaches the prompt, and the liveness assertion
        //       below red FIRST with "The scanner is dead". That sentence is
        //       FALSE — the scanner is fine and a host knob broke `git diff` —
        //       and the placeholder assertion further down, which would have
        //       named the real cause, was never reached. A guard whose failure
        //       message names the wrong cause sends the next reader to the
        //       wrong file, which is the same wrong-domain failure the knob
        //       roster in {@see dirtyRepoFixtureWithEveryStableLayer()} exists
        //       to record. The exit code is captured rather than asserted
        //       inline so the message can carry the actual N.
        $binaryDiffOutput = [];
        $binaryDiffExit = self::git($binary->root(), ['diff', '--shortstat', '--patch'], $binaryDiffOutput);
        $this->assertSame(
            0,
            $binaryDiffExit,
            'git diff failed, exit ' . $binaryDiffExit . ' - the binary-diff control fixture cannot produce a '
                . 'diff at all, so nothing below this line is a statement about the scanner. MEASURED cause: a '
                . '`diff.external` naming a command that cannot exec, anywhere in the config precedence chain. '
                . 'git said: ' . self::gitSaid($binaryDiffOutput),
        );

        //       AND THEN GIT'S OWN `Binary files ` LINE, STILL BEFORE THE
        //       PROMPT'S. The exit code above is only half the guard, and the
        //       half it left out is the one that was actually reachable.
        //       MEASURED on git 2.43.0, each as a GLOBAL config file, both
        //       leaving `git diff --shortstat --patch` at EXIT 0 so the
        //       assertion above stays GREEN: `[diff] external = /bin/true` — an
        //       external diff that SUCCEEDS and prints nothing — yields
        //       ` 1 file changed, 0 insertions(+), 0 deletions(-)` and no patch
        //       body; `[core] excludesFile` naming this fixture's `Alpha.php`
        //       yields NO OUTPUT AT ALL, because the exclude is already in
        //       force when the fixture's own `git add -A` runs below, so the
        //       file is never tracked. Under EITHER, the liveness assertion
        //       below red with "The scanner is dead" while the scanner was
        //       alive — the exact wrong-domain failure the exit-code block was
        //       written to stop, left standing in the one domain that does not
        //       raise the exit code. Control C carries both halves; this is
        //       control B's second half.
        $this->assertGreaterThan(
            0,
            substr_count(implode("\n", $binaryDiffOutput), 'Binary files '),
            'git itself rendered no `Binary files ` line in the binary-diff control fixture, so nothing below '
                . 'this line is a statement about the scanner. MEASURED causes, BOTH of which leave `git diff` '
                . 'at exit 0 so the guard above cannot see them: a `diff.external` / `GIT_EXTERNAL_DIFF` that '
                . 'succeeds and prints nothing, which keeps the shortstat line and drops the patch body; and a '
                . '`core.excludesFile` naming `Alpha.php`, which is in force before the fixture\'s own '
                . '`git add -A` so the file is never tracked and git prints nothing at all. git said: '
                . self::gitSaid($binaryDiffOutput),
        );

        $this->assertGreaterThan(
            0,
            substr_count($binaryPrompt, 'Binary files '),
            'the binary-diff scanner found nothing in a fixture whose attributes say `* -diff`, so either git '
                . 'stopped honouring $GIT_DIR/info/attributes or the rendering changed. The scanner is dead',
        );

        //    C. Raw ANSI, from a real `color.diff=always`.
        $coloured = $this->dirtyRepoFixtureWithEveryStableLayer();
        $this->assertSame(0, self::git($coloured->root(), ['config', 'color.diff', 'always']));
        $this->assertSame(0, self::git($coloured->root(), ['config', 'color.ui', 'always']));
        $colouredPrompt = $coloured->systemPrompt();

        //       THE CONTROL'S OWN SUBPROCESS, BEFORE ITS LIVENESS — the same
        //       repair control B carries two blocks up, for the same reason,
        //       and the previous cycle judged this control safe because control
        //       B's guard "intercepts first". MEASURED, it does not, for the
        //       COLOUR family: `GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=color.diff
        //       GIT_CONFIG_VALUE_0=never GIT_CONFIG_KEY_1=color.ui
        //       GIT_CONFIG_VALUE_1=never` takes this control to ZERO escapes
        //       while control B stays fully green — its `git diff` exits 0 and
        //       `Binary files ` still renders — and the liveness assertion below
        //       then reds ALONE, naming two causes, NEITHER of which happened.
        //       The environment outranks every config file (boundary (a) in
        //       {@see dirtyRepoFixtureWithEveryStableLayer()}), so the
        //       repo-local `color.diff=always` written just above is not enough
        //       to make this control fire. Both halves are asserted: the exit
        //       code, because a `diff.external` that cannot exec breaks this
        //       fixture exactly as it breaks control B's, and then git's OWN
        //       escape bytes, because that is the half a colour override kills
        //       while leaving the exit code at 0. The escape half has a SECOND
        //       cause and the message below names it: MEASURED, a global
        //       `[diff] external = /bin/true` also takes this probe to exit 0
        //       with ZERO escapes, because there is no patch body left to
        //       colour. Control B's `Binary files ` guard reds first under that
        //       knob today, but ordering is not a licence to name one cause -
        //       that is the argument this control's own repair rests on.
        $colourProbe = [];
        $colourExit = self::git($coloured->root(), ['diff', '--shortstat', '--patch'], $colourProbe);
        $this->assertSame(
            0,
            $colourExit,
            'git diff failed, exit ' . $colourExit . ' - the coloured control fixture cannot produce a diff at '
                . 'all, so nothing below this line is a statement about the scanner. git said: '
                . self::gitSaid($colourProbe),
        );
        $this->assertGreaterThan(
            0,
            substr_count(implode("\n", $colourProbe), "\x1b"),
            'git itself emitted no escape bytes in the coloured control fixture, so nothing below this line is a '
                . 'statement about the scanner. MEASURED causes, BOTH at exit 0: a colour setting the repo-local '
                . '`color.diff=always` cannot outrank - GIT_CONFIG_COUNT / GIT_CONFIG_PARAMETERS in the '
                . 'environment beats every config file; or a `diff.external` / `GIT_EXTERNAL_DIFF` that succeeds '
                . 'and prints nothing, which leaves exit 0 with no patch body to colour - MEASURED on git 2.43.0, '
                . 'a global `[diff] external = /bin/true` takes THIS probe to ZERO escape bytes while the repo '
                . 'keeps `color.diff=always`. git said: ' . self::gitSaid($colourProbe),
        );

        $this->assertGreaterThan(
            0,
            substr_count($colouredPrompt, "\x1b"),
            'the escape-byte scanner found no escapes in a fixture with `color.diff=always`. Either the scanner '
                . 'is dead, or EnvironmentBlock started passing `--no-color` - which would be the fix for '
                . 'worklog escalation 2 and makes this control, not the absence assertion, the thing to rewrite',
        );

        // 1. No field degraded to a placeholder. The scan is on the PREFIX
        //    `unavailable (`, not on the git-exit spelling alone, because
        //    EnvironmentBlock has three of these and the other two -
        //    `unavailable (proc_open is disabled on this build)` at :327 and
        //    `unavailable (shell_exec is disabled on this build)` at :855 - are
        //    the same defect from a different cause. An earlier revision
        //    scanned only for the git-exit spelling under a heading claiming
        //    EVERY field.
        $this->assertSame(
            0,
            substr_count($prompt, self::GIT_UNAVAILABLE_MARKER),
            'a git subprocess exited nonzero and <env> rendered the placeholder. MEASURED causes: `log.date` or '
                . '`format.pretty` set to a value that is not a format, and an INVALID value anywhere in the '
                . 'config precedence chain FOR A KEY THE FAILING SUBPROCESS ITSELF READS - git converts that '
                . 'key as it walks the chain and rejects it there, whatever a higher-precedence file says. NOT '
                . 'every invalid value anywhere: MEASURED on git 2.43.0, a global `log.abbrevCommit = nonsense` '
                . 'kills `log --oneline` (128) and leaves `status --porcelain`, `branch --show-current` and '
                . '`diff --shortstat --patch` at 0',
        );

        // 2. The working diff is a patch, not a binary difference.
        $this->assertSame(
            0,
            substr_count($prompt, 'Binary files '),
            'the working diff rendered as a binary difference rather than a patch, so a gitattributes source is '
                . 'marking the fixture `-diff`. MEASURED sources: `core.attributesFile`, `init.templateDir` and '
                . '`$XDG_CONFIG_HOME/git/attributes`, all three beaten by the `.git/info/attributes` the fixture '
                . 'writes - which is at the TOP of that precedence chain and is why it is written rather than '
                . 'configured',
        );

        // 3. No raw ANSI reaches the model.
        $this->assertSame(
            0,
            substr_count($prompt, "\x1b"),
            'a raw ANSI escape byte reached the system prompt. `color.ui=always` or `color.diff=always` on a host '
                . 'whose pins were bypassed puts 21 of them there (worklog escalation 2)',
        );

        // 4. The branch read. `color.branch.current=true` empties this.
        $this->assertStringContainsString(
            "\nCurrent branch: master\n",
            $prompt,
            'the <env> branch field is not the fixture branch. An EMPTY `Current branch:` is what a git that '
                . 'exited nonzero renders here - EnvironmentBlock swallows that exit code (worklog escalation 3), '
                . 'so this assertion is the only thing that sees it',
        );

        // 5. The status field sees a tracked edit AND an untracked file.
        $this->assertStringContainsString(
            "\n M src/Alpha.php\n",
            $prompt,
            'the <env> status field lost the tracked edit the fixture makes before it returns',
        );
        $this->assertStringContainsString(
            "\n?? src/Gamma.php\n",
            $prompt,
            'the <env> status field cannot see an untracked file. TWO MEASURED causes, not one: '
                . '`status.showUntrackedFiles` is not `normal` for this repository, because the repo-local pin in '
                . 'dirtyRepoFixtureWithEveryStableLayer() is gone or was overridden - OR a gitignore source (an '
                . 'in-tree `.gitignore`, `.git/info/exclude`, or a global `core.excludesFile`) is excluding the '
                . 'path, which no `status.*` pin defends. MEASURED, a global `core.excludesFile` listing '
                . '`Gamma.php` reds exactly here with that pin fully intact',
        );

        // 6. The log field: a subject, and a 7-hex abbreviation. Pins the
        //    `i18n.*` family (which deletes the subject) and `core.abbrev` /
        //    `GIT_CONFIG_COUNT` (which widens the sha).
        $this->assertSame(
            1,
            preg_match('/\nRecent commits:\n[0-9a-f]{7} fixture: initial import\n/', $prompt),
            'the <env> log field is not `<7 hex> fixture: initial import`. A missing SUBJECT is what an '
                . '`i18n.commitEncoding` / `i18n.logOutputEncoding` mismatch leaves behind; a sha of another '
                . 'width is `core.abbrev` or a `GIT_CONFIG_COUNT` override of it',
        );

        // 7. The diff body at the pinned three lines of context, with a 7-hex
        //    index line. `GIT_DIFF_OPTS=-u10` moves the hunk header and NOTHING
        //    ELSE in this file used to notice.
        $this->assertStringContainsString(
            "\n@@ -2,4 +2,4 @@\n",
            $prompt,
            'the unstaged diff is not rendered at three lines of context. GIT_DIFF_OPTS=-u10 makes this '
                . '`@@ -1,5 +1,5 @@`, and it is an ENVIRONMENT variable, so no repo-local `diff.context` pin '
                . 'reaches it',
        );
        $this->assertSame(
            1,
            preg_match('/\nindex [0-9a-f]{7}\.\.[0-9a-f]{7} 100644\n/', $prompt),
            'the diff index line is not two 7-hex blobs, so core.abbrev is not 7 for this subprocess',
        );

        // 8. THE EXACT CONTROL COUNTS, LAST. These are the figures the worklog
        //    escalations quote and they are worth pinning, but they are pinned
        //    HERE rather than beside the controls because neither is a fact
        //    about a scanner: one is the number of git FIELDS `<env>` renders
        //    and the other is a function of the WIDTH of the diff git colours.
        //    At the end, nothing they red can hide an assertion above them.
        //
        //    They are also this file's only equality pins on a figure GIT
        //    produces rather than this repository, and {@see MIN_STABLE_PREFIX_BYTES}
        //    argues the opposite policy for host-dependent figures. The
        //    exception is argued rather than taken quietly: both are read off a
        //    fixture whose git config is fully pinned; both moved in this
        //    session only when a hazard moved them; and a red on a different git
        //    version is cheap, because it names this line and this line says so.
        $this->assertSame(
            4,
            substr_count($degraded, self::GIT_UNAVAILABLE_MARKER),
            'the broken-repo control renders ' . substr_count($degraded, self::GIT_UNAVAILABLE_MARKER)
                . ' degraded fields, not 4. That is the COUNT OF GIT FIELDS in <env> - branch, status, log, '
                . 'staged diff, unstaged diff, of which four degrade to the placeholder and the branch read '
                . 'renders EMPTY instead (worklog escalation 3) - and not a fact about the scanner. A step that '
                . 'adds or removes a git field re-measures this',
        );
        $this->assertSame(
            21,
            substr_count($colouredPrompt, "\x1b"),
            'the coloured control renders ' . substr_count($colouredPrompt, "\x1b") . ' escape bytes, not the '
                . '21 MEASURED for worklog escalation 2. Three things move this and none of them is the '
                . 'scanner: the diff CONTEXT WIDTH (MEASURED, GIT_DIFF_OPTS=-u10 makes it 22), a field that '
                . 'stopped rendering (MEASURED, `log.date=true` IN THE ENVIRONMENT - GIT_CONFIG_COUNT - makes '
                . 'it 19; the same key in a global config FILE leaves it at 21, because this fixture pins '
                . '`log.date default` repo-locally and a file loses to that pin while the environment does '
                . 'not - boundary (a)), and a different git version colouring differently',
        );
    }

    /**
     * `log.abbrevCommit` is parse-time validated, so no repo-local pin defends
     * it — the fourteenth knob, and the assertion the roster's prose is not.
     *
     * WHAT THE ROSTER USED TO SAY, in
     * {@see dirtyRepoFixtureWithEveryStableLayer()}: that `log.abbrevCommit` is
     * "inert for ANY value, MEASURED", listed beside `core.quotePath`,
     * `diff.algorithm`, `diff.indentHeuristic`, `status.relativePaths` and
     * `core.autocrlf`. WHAT IS TRUE NOW: half of it. This test is the
     * measurement, and it exists because a paragraph is not an assertion — the
     * roster carried that claim through six reviews without anything able to
     * red on it.
     *
     * BOTH POLARITIES, because a knob that is fatal for every value and one
     * that is fatal for none are the same test otherwise. A VALID value is
     * inert: `log --oneline` carries its own `--abbrev-commit` and a
     * command-line flag beats config, so a global `abbrevCommit = false`
     * leaves the output byte-identical to a run with no global config at all.
     * An INVALID one is fatal: exit 128, and {@see EnvironmentBlock} renders
     * `Recent commits: unavailable (git exited 128)`, which MEASURED takes the
     * fixture prompt 4,844 -> 4,841 B and the cache prefix 4,670 -> 4,667.
     *
     * AND THE PIN IS LIVE WHILE THAT HAPPENS, which is the whole point and the
     * one assertion that separates "undefendable by pinning" from "nobody
     * pinned it". The repository below sets `log.abbrevCommit=false` in its own
     * config, at HIGHER precedence than the hostile global file: `git config
     * --get` answers `false`, and `git log` dies anyway, because `log` READS
     * this key and CONVERTS the value it meets in every file it walks, so the
     * global's `nonsense` is rejected on the way past whatever the repo-local
     * file says afterwards. NOT because "git parses every file in the chain
     * before it uses any of them" — this doc-block used to give that as the
     * mechanism and it is FALSE; it would predict that every git subprocess
     * dies on every invalid value anywhere. MEASURED on git 2.43.0, this exact
     * repository and this exact global: `log --oneline` 128, `status
     * --porcelain` 0, `branch --show-current` 0, `diff --shortstat --patch` 0.
     * That is boundary (b)
     * of the roster comment, the same shape as `color.branch.current`, and NOT
     * the `log.date`/`format.pretty` shape whose values reach a formatter
     * rather than a parser and which pinning therefore does defend. So the knob
     * gets no `foreach` row: detection is the only available answer, and
     * {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}
     * already is it — MEASURED `Failures: 1` under this knob at this commit,
     * and SILENTLY GREEN at 1267e6fbb, where that guard did not exist.
     *
     * The exit code is what is asserted exactly; the fatal SENTENCE is asserted
     * only for the knob name it must contain, because the wording is a git
     * version's and the exit code is not.
     */
    public function testLogAbbrevCommitIsParseTimeValidatedSoNoRepoLocalPinDefendsIt(): void
    {
        $probe = [];
        if (self::git(null, ['--version'], $probe) !== 0) {
            $this->markTestSkipped('git is unusable in this environment: ' . implode("\n", $probe));
        }

        $root = $this->tempDir();
        $configs = $this->tempDir();

        foreach ([
            ['init', '-q'],
            ['symbolic-ref', 'HEAD', 'refs/heads/master'],
            ['config', 'user.email', 'fixture@example.invalid'],
            ['config', 'user.name', 'Prefix Fixture'],
            ['config', 'commit.gpgsign', 'false'],
            // THE PIN ITSELF. Repo-local, so it outranks both hostile files
            // written below - the test measures a DEFENDED repository, not an
            // undefended one, or it would prove nothing about the defence.
            ['config', 'log.abbrevCommit', 'false'],
            ['commit', '-q', '--allow-empty', '-m', 'fixture: initial import'],
        ] as $argv) {
            $output = [];
            $this->assertSame(
                0,
                self::git($root, $argv, $output),
                'git ' . implode(' ', $argv) . ' failed: ' . implode("\n", $output),
            );
        }

        $valid = $configs . '/valid-global.gitconfig';
        $invalid = $configs . '/invalid-global.gitconfig';
        $this->assertNotFalse(
            file_put_contents($valid, "[log]\n\tabbrevCommit = false\n"),
            'could not write the valid-value global config, so the inert polarity below cannot be measured',
        );
        $this->assertNotFalse(
            file_put_contents($invalid, "[log]\n\tabbrevCommit = nonsense\n"),
            'could not write the invalid-value global config, so the fatal polarity below cannot be measured',
        );

        // POLARITY 1 - a VALID value is inert, and the run with NO global
        // config at all is the control that says so rather than saying the
        // command merely happened to work twice.
        $withValid = [];
        $this->assertSame(
            0,
            self::git($root, ['log', '--oneline', '-5'], $withValid, $valid),
            '`git log --oneline -5` failed under a VALID global `log.abbrevCommit`, so the knob is not inert '
                . 'for valid values either and the roster bullet needs rewriting again: ' . implode("\n", $withValid),
        );

        $withNone = [];
        $this->assertSame(
            0,
            self::git($root, ['log', '--oneline', '-5'], $withNone, '/dev/null'),
            '`git log --oneline -5` failed with NO global config at all, so this host cannot measure the knob: '
                . implode("\n", $withNone),
        );
        $this->assertSame(
            $withNone,
            $withValid,
            'a VALID `log.abbrevCommit` changed what `log --oneline` renders, so `--abbrev-commit` no longer '
                . 'overrides the config key and the inert half of the roster bullet is false: '
                . json_encode([$withNone, $withValid]),
        );

        // POLARITY 2 - an INVALID value is fatal, ACROSS the repo-local pin.
        $withInvalid = [];
        $this->assertSame(
            128,
            self::git($root, ['log', '--oneline', '-5'], $withInvalid, $invalid),
            '`git log --oneline -5` did not exit 128 under an INVALID `log.abbrevCommit` in a lower-precedence '
                . 'global file. If it exited 0 the knob really is inert for any value and the roster bullet '
                . 'this test corrects should go back to what it said; any other code is a third behaviour '
                . 'nobody has measured: ' . implode("\n", $withInvalid),
        );
        $this->assertStringContainsString(
            'log.abbrevcommit',
            implode("\n", $withInvalid),
            'git died under the invalid value but did not name `log.abbrevcommit` while doing it, so this test '
                . 'is measuring some other fatal: ' . implode("\n", $withInvalid),
        );

        // AND THE PIN WAS LIVE THROUGHOUT. Same hostile file, same repository,
        // one command later: the pinned value is what `--get` answers, and the
        // command above still died. Without this pair the test would be
        // indistinguishable from one run against a repository that never
        // pinned the knob at all.
        $pinned = [];
        $this->assertSame(
            0,
            self::git($root, ['config', '--get', 'log.abbrevCommit'], $pinned, $invalid),
            '`git config --get log.abbrevCommit` failed under the hostile global file, so the pin cannot be '
                . 'shown to be live and the fatal above says nothing about pinning: ' . implode("\n", $pinned),
        );
        $this->assertSame(
            ['false'],
            $pinned,
            'the repo-local pin does not answer `false` under the hostile global file, so the fatal above was '
                . 'measured against an UNPINNED repository and proves nothing about whether pinning defends: '
                . json_encode($pinned),
        );
    }

    /**
     * Captured git output is capped before it is quoted into a failure message.
     *
     * WHY THIS IS A TEST AND NOT A COMMENT: the guards in
     * {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}
     * interpolate a whole `git` capture into their messages, and production
     * caps the SAME stream at {@see EnvironmentBlock::DIFF_MAX_BYTES} = 8,192 B
     * before it reaches a prompt. The fixture's own captures are small and
     * MEASURED, so the cap never fires on this host and nothing else in this
     * file can red if it is removed — which is exactly the shape of a bound
     * that quietly stops holding. The bound is asserted here directly.
     *
     * BOTH POLARITIES. Under the cap the text passes through byte-identical,
     * because a truncation marker on a 30 B `fatal:` line would be its own
     * wrong-domain read; over it the result is capped AND announces the
     * truncation with the original length, so a reader can tell "git said
     * nothing more" from "the message stops here".
     */
    public function testCapturedGitOutputIsCappedBeforeItReachesAFailureMessage(): void
    {
        $short = ['fatal: external diff died, stopping at src/Alpha.php'];
        $this->assertSame(
            'fatal: external diff died, stopping at src/Alpha.php',
            self::gitSaid($short),
            'a capture well under the cap was altered on its way into a failure message, so the messages in '
                . 'this file no longer quote git verbatim',
        );

        $long = [str_repeat('x', 10000)];
        $capped = self::gitSaid($long);
        $this->assertSame(
            2048,
            substr_count($capped, 'x'),
            'a 10,000 B capture did not truncate to exactly ' . self::GIT_SAID_MAX_BYTES . ' bytes of git output '
                . 'in a failure message, so the cap that mirrors EnvironmentBlock::DIFF_MAX_BYTES is not holding',
        );
        $this->assertSame(
            2048 + \strlen(' [truncated at 2048 B of 10000]'),
            \strlen($capped),
            'the truncated message is not the capped output plus its announcement, so either the cap or the '
                . 'marker moved: ' . substr($capped, 2040),
        );
        $this->assertStringEndsWith(
            ' [truncated at 2048 B of 10000]',
            $capped,
            'a truncated git capture no longer announces that it was truncated, so a reader cannot tell a '
                . 'message that stops early from git having said nothing more',
        );
    }

    /**
     * `$count` comment lines under the fixture's namespace, tagged `$revision`
     * so two calls of the same count differ on every line.
     *
     * The body's SIZE is a deterministic function of `$count` — MEASURED
     * `generatedLines(400, 'A')` and `generatedLines(400, 'B')` are both
     * 23,924 B, and `generatedLines(405, 'B')` is 24,224 B — which is what
     * makes the two revisions of the over-cap shape differ in size by a known
     * amount while differing on every line.
     *
     * The lines are NOT fixed-width, and an earlier revision of this docblock
     * said they were: the loop interpolates a bare `$i`, so MEASURED at
     * `$count = 400` the comment lines are 57 B (×10), 58 B (×90) and 59 B
     * (×300) EXCLUDING the newline — 58/59/60 including it, which is the
     * reading under which these widths reconcile with the 23,924 B above:
     * `34 + 10·58 + 90·59 + 300·60 = 23,924`, the 34 being the file header.
     * (The domain used to be unstated, and the two figures then contradicted
     * each other on their face by exactly 400 B, one per line.)
     * Nothing depends on the width — that same revision said the width
     * was "what lets the caller assert it cleared
     * {@see EnvironmentBlock::DIFF_MAX_BYTES}", and by then the caller had
     * already abandoned the length-arithmetic form of that assertion as
     * unsound and replaced it with the block's own truncation marker (see the
     * comment above that assertion).
     */
    private static function generatedLines(int $count, string $revision): string
    {
        $body = "<?php\n\nnamespace Fixture\\Prefix;\n\n";
        for ($i = 0; $i < $count; ++$i) {
            $body .= '// generated line ' . $i . ' rev ' . $revision . " padding padding padding padding\n";
        }

        return $body;
    }

    /**
     * A fixture repository that renders EVERY layer of the assembled prompt,
     * inside a real git repository with one commit and a dirty working tree.
     *
     * Dirty BEFORE the caller's first render, on purpose: the step measures two
     * consecutive prompts on a tree that is ALREADY dirty, which is the state a
     * session is in from its first write onwards - not the clean-to-dirty
     * transition, where `git status` itself changes and the divergence starts
     * much earlier.
     *
     * WHAT IS PINNED, AND WHAT IS STILL READ OFF THE HOST — stated as two
     * lists rather than as the sentence this used to carry, *"nothing outside
     * this directory can reach the prompt"*, which was false: the git
     * subprocesses honour the developer's own `~/.gitconfig`, and MEASURED,
     * `core.abbrev=20` and `diff.context=10` each move the byte count. What is
     * pinned: instruction-file discovery (`git init` runs BEFORE any prompt is
     * assembled, and
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::ancestorRoot()}
     * returns null the moment `$root/.git` exists, so the ancestor walk that
     * would otherwise read `CLAUDE.md` from `/tmp` and `/` never starts);
     * `forcedInstructions`, which defaults to `[]`; the date and platform,
     * injected by {@see PromptFixture}; the branch name; the thirteen git
     * config knobs listed at the `foreach` below plus the
     * `.git/info/attributes` written after it — a list that is "found", not
     * "exhaustive", and has grown at every review so far, which is why
     * {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}
     * guards the rendered fields rather than the roster. What is NOT: the
     * `Working directory:` path (`sys_get_temp_dir()`), `OS version:` and
     * `PHP version:`. MEASURED on this host those three contribute 33 + 23 + 5
     * = 61 B inside the shared prefix, and only the last two can SHRINK on
     * another host — `/tmp` is already the shortest plausible temp root — which
     * is why {@see MIN_STABLE_PREFIX_BYTES} needs tens of bytes of headroom for
     * them and not hundreds.
     */
    private function dirtyRepoFixtureWithEveryStableLayer(): PromptFixture
    {
        // The captured output goes INTO the message: a `git` that exists but
        // exits nonzero — a broken wrapper, a hostile GIT_* in the environment
        // — would otherwise skip under a sentence naming the wrong cause while
        // its stderr sat unread in $probe. (`exec()` itself being disabled
        // raises an Error rather than returning nonzero, so that build fails
        // loudly instead of skipping quietly.)
        if (self::git(null, ['--version'], $probe) !== 0) {
            $this->markTestSkipped('git is unusable in this environment: ' . implode("\n", $probe));
        }

        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;
        $root = $fixture->root();

        $fixture->writeJson('composer.json', [
            'name' => 'fixture/prefix-win',
            'description' => 'Fixture repository for the P3.S4 prefix measurement.',
            'autoload' => ['psr-4' => ['Fixture\\Prefix\\' => 'src/']],
        ]);
        $fixture->write('src/Alpha.php', self::ALPHA_COMMITTED);
        $fixture->write('src/Beta.php', "<?php\n\nnamespace Fixture\\Prefix;\n\nfinal class Beta {}\n");
        // The four constants below are the SAME ones
        // {@see STABLE_LAYER_FIXTURE_FRAGMENTS} counts, referenced rather than
        // repeated, so an edit here moves the roster with it instead of leaving
        // the roster asserting a string the fixture no longer writes.
        $fixture->write('AGENTS.md', self::FIXTURE_AGENTS_BODY);

        $fixture->memoryStore()->add(self::FIXTURE_MEMORY_NOTE, MemoryScope::Project);

        $fixture->addSkill(new Skill(
            name: self::FIXTURE_SKILL_NAME,
            description: self::FIXTURE_SKILL_DESCRIPTION,
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: '',
            paths: [],
            content: self::FIXTURE_SKILL_BODY,
            sourcePath: $root . '/.sugar-crush/skills/' . self::FIXTURE_SKILL_NAME . '/SKILL.md',
            source: SkillSource::Native,
        ));

        // `symbolic-ref` rather than `init -b`, which needs git 2.28; and
        // gpgsign forced off because a host that signs by default would hang
        // this on a passphrase prompt. Every exit code is asserted: a silently
        // failed `commit` leaves an empty `Recent commits:` field that reads
        // exactly like a repository with no history.
        //
        // THE THIRTEEN CONFIG KNOBS BELOW ARE NOT DECORATION. `EnvironmentBlock`
        // shells out to plain `git`, so the developer's own `~/.gitconfig`
        // reaches the assembled prompt, and REPOSITORY-local config is the only
        // lever a test has over that (it outranks global without touching the
        // environment of any other test).
        //
        // TWO BOUNDARIES ON THAT LEVER, both measured, and the first of them
        // used to be recorded here as if it were the only one.
        //
        // (a) THE ENVIRONMENT OUTRANKS EVERY CONFIG FILE. MEASURED,
        //     `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.abbrev
        //     GIT_CONFIG_VALUE_0=20` beats every pin below and takes the prompt
        //     to 4,883/4,696; and `GIT_DIFF_OPTS=-u10` — which is not a config
        //     key at all, so no `diff.context` pin can reach it — takes it to
        //     4,851, byte-identical damage to the `diff.context=10` row below.
        //     Before {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}
        //     existed, MEASURED, the whole file stayed `OK (13 tests, 229
        //     assertions)` under `GIT_DIFF_OPTS=-u10`.
        // (b) AN INVALID VALUE ANYWHERE IN THE CHAIN IS FATAL, WHATEVER
        //     OVERRIDES IT — FOR THE COMMANDS THAT READ THAT KEY, AND NO
        //     OTHERS. A command's config callback CONVERTS the value of every
        //     key it consumes as it walks the chain, so an invalid value is
        //     fatal wherever it is read and inert everywhere else, whatever a
        //     higher-precedence file says. An earlier revision of this bullet
        //     gave the mechanism as "git parses every config file it can reach
        //     before it uses any of them", which is FALSE and would predict
        //     that every subprocess dies on every invalid value. MEASURED on
        //     git 2.43.0, invalid in a global with a VALID value pinned
        //     repo-locally: `log.abbrevCommit = nonsense` kills `log --oneline`
        //     (128) and leaves `status --porcelain`, `branch --show-current`
        //     and `diff --shortstat --patch` at 0; `color.branch.current=true`
        //     does the exact reverse, killing ONLY `branch --show-current` on
        //     `error: invalid color value: true` / `fatal: bad config variable
        //     'color.branch.current' … exit 128` while `git config --get`
        //     answers the pinned `normal`. `EnvironmentBlock` swallows that
        //     exit into an empty `Current branch:`. A pin cannot defend a key a
        //     subprocess READS; only a test that reads the rendered field can
        //     see it.
        //
        // Neither boundary is defended by adding a row here — one is out of
        // reach and the other is unfixable by precedence — so both are covered
        // by asserting the RENDERED FIELDS instead, in
        // {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}.
        // That test is where a fourteenth knob is meant to be caught; this list
        // is what keeps the byte figures below reproducible on a host that has
        // set one of them.
        //
        // MEASURED on this host, each knob set in a `GIT_CONFIG_GLOBAL` with
        // THAT KNOB'S OWN PIN REMOVED and the rest still in force — which is
        // the only reading under which each row is a fact about its own knob,
        // and NOT the reading an earlier revision of this list used (see the
        // `color.ui` row for what that cost):
        //   `diff.noprefix=true`            reds the `diff --git a/… b/…` assertion
        //   `diff.mnemonicPrefix=true`      reds the same assertion
        //   `core.abbrev=20`                prompt 4,844 -> 4,883 B, prefix -> 4,696
        //   `diff.context=10`               prompt 4,844 -> 4,851 B
        //   `color.ui=always`               prompt 4,844 -> 4,921 B, prefix -> 4,689
        //                                   ONLY WITH THE `color.diff` PIN ALSO
        //                                   REMOVED. With `color.diff=false` in
        //                                   force this knob moves NOTHING —
        //                                   MEASURED 4,844/4,670, unchanged —
        //                                   because the slot key outranks it.
        //                                   The figure was measured before
        //                                   `color.diff` was pinned and is kept
        //                                   with its domain rather than dropped,
        //                                   since it is what the colour hazard
        //                                   costs when nothing covers it.
        //   `color.diff=always`             prompt 4,844 -> 4,921 B, prefix -> 4,689,
        //                                   and 21 raw ESC bytes in the prompt EVEN
        //                                   WITH `color.ui=false` pinned below it:
        //                                   git's per-command `color.<slot>` keys
        //                                   OUTRANK `color.ui`, so pinning `color.ui`
        //                                   alone does NOT cover the colour hazard it
        //                                   appears to name. Both are pinned.
        //   `diff.suppressBlankEmpty=true`  prompt 4,844 -> 4,842 B
        //   `status.showUntrackedFiles=no`  moves nothing on the two-render
        //                                   measurement (MEASURED 4,844/4,670,
        //                                   unchanged) and is LOAD-BEARING on
        //                                   this fixture anyway. THIS ROW USED
        //                                   TO SAY the opposite twice over —
        //                                   that "every file it dirties is
        //                                   TRACKED", and that the pin was here
        //                                   "for the OTHER repository in this
        //                                   file". Both are false.
        //                                   {@see testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne()}
        //                                   writes an UNTRACKED `src/Gamma.php`
        //                                   into this fixture and both of its
        //                                   halves depend on `git status`
        //                                   seeing it. MEASURED, with this pin
        //                                   deleted and a global
        //                                   `status.showUntrackedFiles=no`,
        //                                   that test reds at `<env> must still
        //                                   track the new file within the turn
        //                                   / Failed asserting that two strings
        //                                   are not identical`. It is now named
        //                                   at the site as well, by a
        //                                   `?? src/Gamma.php` assertion that
        //                                   reds first and says which knob.
        //                                   (The scratch repository in
        //                                   {@see testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()}
        //                                   does also need it, and does carry
        //                                   its own pin — that half of the old
        //                                   row was right about a DIFFERENT
        //                                   repository, which is exactly the
        //                                   "a claim never travels without its
        //                                   domain" failure.)
        //                                   AND THERE IS A THIRD SITE, which
        //                                   the sentence above framed as if
        //                                   there were two: `tests/RuntimeTest.php`
        //                                   builds its own scratch repository
        //                                   with a fourteen-row config roster
        //                                   identical to the ELEVEN-hazard set
        //                                   this file carried before this step —
        //                                   no `log.date`, no `format.pretty`,
        //                                   no `.git/info/attributes`. MEASURED
        //                                   with a hostile `core.attributesFile`
        //                                   saying `*.php -diff`: this file is
        //                                   green and `RuntimeTest` reds at
        //                                   `RuntimeTest.php:1918`. That file is
        //                                   outside this step's declared list,
        //                                   so it is REPORTED, not edited.
        //   `log.decorate=full`             prompt 4,844 -> 4,872 B, prefix -> 4,698
        //   `i18n.logOutputEncoding=UTF-16` prompt 4,844 -> 4,821 B, prefix -> 4,647.
        //                                   `--oneline` does NOT override it, and the
        //                                   damage is not a shifted byte count but a
        //                                   DELETION: the commit SUBJECT disappears
        //                                   from `Recent commits:` entirely, leaving
        //                                   the bare sha (-23 B, the subject's length)
        //   `i18n.commitEncoding=UTF-16`    prompt 4,844 -> 4,821 B, prefix -> 4,647:
        //                                   byte-identical damage to the row above,
        //                                   and pinning that row does NOT cover it.
        //                                   git converts FROM the declared commit
        //                                   encoding, so declaring UTF-16 mangles a
        //                                   UTF-8 commit whatever the OUTPUT encoding
        //                                   says. Found by the fifth review, in the
        //                                   same `foreach` the fourth had just fixed
        //                                   for `color.ui`/`color.diff`, and by the
        //                                   same mechanism: the pinned key names the
        //                                   hazard family, the unpinned sibling in
        //                                   that family wins
        // and MEASURED with all thirteen pinned (and with the
        // `.git/info/attributes` written), on a host with none of them set,
        // the numbers are unchanged at 4,844/4,670 — so the pin costs nothing
        // here, and it is what makes the figures reproducible on a host whose
        // git config this list covers. NOT "anywhere": see the paragraph on
        // completeness below, which an earlier revision of this comment
        // contradicted in the same breath.
        //
        // `log.date` and `format.pretty` are inert FOR A VALID VALUE ONLY, and
        // that qualifier is the whole content of the row. This comment used to
        // carry them as flatly inert "because `--oneline` overrides both".
        // MEASURED: `--oneline` overrides what they FORMAT, and neither key is
        // validated at config-parse time, so `git log` reads the value and
        // dies on it — `log.date=true` and `format.pretty=true` each make the
        // subprocess exit 128, `Recent commits:` render
        // `unavailable (git exited 128)`, and (before
        // {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()}
        // existed) the whole file stay `OK (13 tests, 229 assertions)`. They
        // are pinned above now, to valid values, which MEASURED does defeat
        // both — the exception to boundary (b), because the value is used
        // rather than parsed. `log.decorate` is in that same `log.*` family
        // and `--oneline` does NOT override it, and neither does it override
        // either `i18n.*` key. `log.decorate`'s default is `auto`, which
        // decorates only to a tty, so a piped `proc_open` sees nothing and the
        // knob is invisible until a developer sets it. All four of
        // `log.decorate`, `color.diff`, `i18n.logOutputEncoding` and
        // `i18n.commitEncoding` were found by a review, not by this list, AFTER
        // the list had already claimed to make the figures portable — which is
        // exactly the shape the completeness paragraph below is about. Two of
        // them share the worst version of it: the list did not merely omit the
        // colour hazard and then the commit-encoding hazard, it NAMED each one
        // and pinned a sibling key that does not cover it. If a fourteenth is
        // ever wanted, look there first — at a family this list already claims.
        // An earlier revision of this comment put
        // `status.showUntrackedFiles` in that same sentence under that same
        // reason, and the reason does not apply to it:
        // {@see EnvironmentBlock} runs `status --porcelain` with no untracked
        // flag at all, so the knob decides what that field contains. It is
        // pinned above rather than explained away.
        //
        // THIS LIST IS NOT CLAIMED TO BE COMPLETE, and the evidence that the
        // caveat is load-bearing is that the list has grown at EVERY review
        // that looked for more, without exception: four after the first, seven
        // after the second, eight after the third, ten after the fourth, eleven
        // after the fifth, thirteen plus an attributes file after the sixth.
        // Six reviews, seven more knobs; the reasonable prediction is that a
        // seventh review finds a fourteenth. A knob that moves the byte count
        // WITHOUT reddening anything is the failure mode this paragraph exists
        // to make visible — `core.abbrev`, `color.ui`, `color.diff`,
        // `log.decorate` and both `i18n.*` keys are all of that kind — so the
        // honest statement is "thirteen found" and not "thirteen exist". THE
        // ANSWER TO THAT PREDICTION IS NOT A FOURTEENTH ROW. It is
        // {@see testEveryGitFieldRendersARealValueRatherThanADegradedPlaceholder()},
        // which asserts the rendered fields rather than the knobs and therefore
        // reds for members of these families that nobody has enumerated.
        //
        // THE PREDICTION CAME TRUE AND THE ANSWER HELD. A seventh review found
        // the fourteenth, and it was `log.abbrevCommit` — a knob THIS list
        // already named, in its inert bullet. MEASURED, git 2.43.0, with
        // `[log] abbrevCommit = nonsense` in a lower-precedence global file:
        // `git log --oneline -5` dies `fatal: bad boolean config value
        // 'nonsense' for 'log.abbrevcommit'`, exit 128, the `Recent commits:`
        // field degrades to `unavailable (git exited 128)`, and the prompt goes
        // 4,844 -> 4,841 B with the prefix 4,670 -> 4,667. It is STILL NOT A
        // ROW in the `foreach` below, because a row there would not defend it
        // — see the corrected bullet in the list beneath — and it reds at the
        // rendered-field guard exactly as this paragraph predicted it would:
        // MEASURED `Failures: 1` under that knob at this commit, and SILENTLY
        // GREEN at 1267e6fbb where the guard did not yet exist. The knob's own
        // behaviour is pinned by
        // {@see testLogAbbrevCommitIsParseTimeValidatedSoNoRepoLocalPinDefendsIt()}
        // rather than restated here, because a paragraph is not an assertion.
        //
        // THE INERT LIST, WITH THE DOMAIN OF EACH ENTRY — an earlier revision
        // of it carried three entries without one, and one hazard with no entry
        // at all.
        //   - Inert for ANY value: NOTHING here is, and this bullet used to
        //     name five keys as if they were. They are the SAME family as
        //     `log.abbrevCommit` two bullets down — inert for a valid value,
        //     fatal for an invalid one, undefendable by a repo-local pin. What
        //     separates them is the DOMAIN of the failure, not the mechanism,
        //     and that is the next bullet.
        //   - Inert for any VALID value, and an invalid one reds the fixture
        //     BUILD rather than a rendered field, because `git init` and
        //     `git commit` consume these keys too — so they fail LOUDLY, in
        //     git's own words, before a prompt is assembled. Loud is not inert.
        //     MEASURED on git 2.43.0, each key pinned to the VALID value shown
        //     repo-locally and set to `nonsense` in a lower-precedence global,
        //     as exit codes for `log --oneline` / `status --porcelain` /
        //     `branch --show-current` / `diff --shortstat --patch`:
        //       `core.quotePath` (false)        128 / 128 / 128 / 128
        //       `core.autocrlf` (false)         128 / 128 / 128 / 128
        //       `diff.indentHeuristic` (true)   128 / 128 /   0 / 128
        //       `diff.algorithm` (myers)        128 / 128 /   0 / 128
        //       `status.relativePaths` (true)     0 / 128 /   0 /   0
        //     e.g. `fatal: bad boolean config value 'nonsense' for
        //     'core.quotepath'`. MEASURED end to end at this commit, a global
        //     `[core] quotePath = nonsense` reds this file at `Failures: 6`,
        //     all six on a CHECKED `git init` exit code quoting git's own
        //     `fatal: bad boolean config value 'nonsense' for 'core.quotepath'`
        //     — not a rendered field. FIVE of them are the fixture's own
        //     `assertSame(0, self::git(...))` below, spelled `git init -q
        //     failed: …`; the SIXTH is the scratch repository in
        //     {@see testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()},
        //     spelled `git init failed on the scratch repository, exit 128 …`.
        //     AND THAT SIXTH IS NEW. This bullet used to say "every one of
        //     them" and it was FIVE OF SIX: that test's `git init` was
        //     UNCHECKED, guarded only by `is_dir($dir . '/.git')`, and MEASURED
        //     a failed `init` still leaves a partial `.git`, so the guard
        //     passed and the test red two assertions later on
        //     `could not pin status.showUntrackedFiles … fatal: not in a git
        //     directory` — naming neither `git init` nor the hostile config.
        //     The init is checked there now, so the claim is true as it reads.
        //     `log.abbrevCommit` is the contrast: it leaves the build GREEN and
        //     degrades only `Recent commits:`, which is why it needed a test.
        //   - Inert only for a VALID value: `log.date`, `format.pretty`. An
        //     invalid one exits 128; see the paragraph above. Both are pinned.
        //   - Inert only for a VALID value AND UNDEFENDABLE BY PINNING:
        //     `log.abbrevCommit`. CORRECTED — the bullet above used to carry it
        //     as "inert for ANY value, MEASURED", and half of that is right.
        //     WHAT IS TRUE NOW, MEASURED on git 2.43.0: a VALID value is inert,
        //     because `log --oneline` carries its own `--abbrev-commit` and a
        //     command-line flag beats config, so `abbrevCommit = false` in a
        //     global file leaves `git log --oneline -5` byte-identical. An
        //     INVALID one is FATAL — exit 128, `Recent commits:` degrades, the
        //     prompt loses 3 B — and NO REPO-LOCAL PIN REACHES IT, because the
        //     value is parsed rather than used: with `log.abbrevCommit=false`
        //     set repo-locally and `= nonsense` in a global file,
        //     `git config --get` answers `false` and `git log` still exits 128.
        //     That is boundary (b) above, the same shape as
        //     `color.branch.current`, and NOT the `log.date`/`format.pretty`
        //     shape whose values reach a formatter and which pinning does
        //     defend. WHY IT STILL EARNS A LINE HERE rather than a `foreach`
        //     row: a row would be a licence, not a defence. It would move the
        //     knob out of the inert list and into a list of things claimed to
        //     be handled while handling nothing — which is the exact failure
        //     the completeness paragraph above records ("the list did not
        //     merely omit the hazard, it NAMED it and pinned a sibling key that
        //     does not cover it"). Detection is the only available answer and
        //     it is already built: the rendered-field guard reds on it.
        //   - `color.status` and `color.branch` are inert for a reason worth
        //     writing down rather than re-deriving: `status --porcelain` and
        //     `branch --show-current` are plumbing-ish forms that never colour,
        //     whatever the slot says. That is NOT true of the per-slot COLOUR
        //     values: MEASURED, `color.branch.current=true` is an invalid
        //     colour, git rejects it at parse time, `branch --show-current`
        //     exits 128 and `Current branch:` renders EMPTY — and no repo-local
        //     pin can defend it (boundary (b) above).
        //   - NOT inert, and listed as inert by an earlier revision:
        //     `init.templateDir`. MEASURED, a template dir carrying
        //     `info/attributes` that says `*.php -diff` takes the prompt to
        //     4,749/4,672 and replaces the patch body with `Binary files …
        //     differ`. It seeds `$GIT_DIR/info/attributes`, which is the top of
        //     the gitattributes precedence chain.
        //   - `core.attributesFile` was in NO list at all. MEASURED, same
        //     4,749/4,672 and the same binary rendering; so is a bare
        //     `XDG_CONFIG_HOME` holding `git/attributes`. All three are now
        //     beaten by the `.git/info/attributes` this fixture writes below,
        //     MEASURED back to 4,844/4,670 under each of them.
        //   - `diff.external` moves the bytes and REDS, which is the harmless
        //     direction and needs no pin — but it has TWO domains and only one
        //     of them was ever measured here. MEASURED, git 2.43.0: an external
        //     diff that SUCCEEDS and prints nothing (`/bin/true`) leaves
        //     `git diff` at exit 0, so the `--shortstat` line survives and only
        //     the patch body is lost — 4,844 -> 4,617, the two renders
        //     IDENTICAL, `Failures: 3`. An external diff that FAILS
        //     (`/bin/false`, or a path that cannot exec) takes `git diff` to
        //     128 on `fatal: external diff died`, the whole field degrades to
        //     `unavailable (git exited 128)` — 4,844 -> 4,599, renders
        //     identical, `Failures: 3`. `GIT_EXTERNAL_DIFF` reproduces both
        //     figures exactly and is an ENVIRONMENT variable, so no pin reaches
        //     it. The 128 domain is the one control B's exit-code guard above
        //     was built for; the EXIT-0 domain slipped straight past it and red
        //     the liveness assertion with "The scanner is dead", which is why
        //     control B now also asserts git's OWN `Binary files ` line.
        //     MEASURED at this commit, `[diff] external = /bin/true` reds at
        //     that second guard quoting git's ` 1 file changed, 0 insertions(+),
        //     0 deletions(-)`, and `/bin/false` still reds at the exit-code
        //     guard quoting `fatal: external diff died` — both still
        //     `Failures: 3`.
        //   - `core.bigFileThreshold=1` USED TO belong on that line and no
        //     longer does. MEASURED at this commit it moves NOTHING —
        //     4,844/4,670, byte-for-byte the clean figures — because the
        //     `.git/info/attributes` `* diff` written below forces the text
        //     path. Verified on THIS fixture rather than on a stand-in, by
        //     deleting that one file from a built fixture and re-running
        //     `git diff --shortstat --patch`: with it, the ordinary patch;
        //     without it, ` 1 file changed, 0 insertions(+), 0 deletions(-)`,
        //     the binary path. So it belongs in the attributes family three
        //     bullets up, beside `core.attributesFile`, `init.templateDir` and
        //     XDG; its old domain, before that file existed, is what the line
        //     it used to sit on still described.
        //   - `core.excludesFile` NAMING THE FIXTURE'S TRACKED FILE stays on
        //     the moves-and-reds line, and a review that moved it off was
        //     REFUSED ON MEASUREMENT: a global `core.excludesFile` listing
        //     `Alpha.php` (or `src/Alpha.php`) takes the prompt 4,844 -> 4,561
        //     and reds this file at `Failures: 3`. The refused reasoning was
        //     "gitignore never applies to a tracked path", which is TRUE in
        //     isolation — MEASURED, exclude a path AFTER committing it and
        //     `status --porcelain` still says ` M src/Alpha.php` — and beside
        //     the point HERE, because of ORDER: the exclude is already in force
        //     when the fixture's own `git add -A` runs below, so the file is
        //     never tracked in the first place. MEASURED in a scratch repo,
        //     excluded before the `add`: `git ls-files` EMPTY, status empty.
        //     In the rendered prompt both `Status:` and the unstaged diff go
        //     `(none)`. AND WHICH MESSAGE IT REDS WITH IS NOW READ, not just
        //     the count: this bullet used to justify the line by `Failures: 3`
        //     alone, and MEASURED, one of those three was the liveness
        //     assertion saying "The scanner is dead" about a live scanner —
        //     `git diff` exits 0 here and simply prints NOTHING, because the
        //     file was never tracked. Control B's second guard covers it now
        //     and reds with git's own empty output.
        //   - `core.excludesFile` naming the UNTRACKED `src/Gamma.php` is a
        //     SECOND hazard the line above never covered. It moves nothing in
        //     this fixture's own byte figures — `Gamma.php` is written by the
        //     two tests that need it, not here — but MEASURED it reds this file
        //     at `Failures: 2`, and BOTH failure messages used to blame the
        //     healthy `status.showUntrackedFiles` pin; they now name the
        //     gitignore family too. No `status.*` pin defends this: gitignore
        //     is a different mechanism from `showUntrackedFiles`.
        //
        // ONE RECORDED UNKNOWN, LABELLED RATHER THAN GUARDED. `git diff
        // --shortstat`'s " N files changed, X insertions(+), Y deletions(-)" is
        // a gettext string, and nothing in this roster pins a locale. On a host
        // carrying `git-l10n` a `LC_ALL`/`LANGUAGE` in the environment would
        // therefore move the nice shape's bytes and red the capped shape's
        // `insertions(+)` assertion under a message about the CAP rather than
        // about the locale. That is a HYPOTHESIS, NOT A MEASUREMENT: this host
        // has ZERO git catalogues installed (`find / -name git.mo` -> none, and
        // no de_DE/fr_FR in `locale -a`), so `LC_ALL=de_DE.UTF-8 git diff
        // --shortstat` renders the untranslated English here and the hypothesis
        // cannot be confirmed or refuted on this box. No pin is added for it,
        // because a guard whose premise was never observed is a claim wider
        // than its evidence — which is the defect this whole roster exists to
        // stop. It wants one measurement on a translated host.
        foreach ([
            ['init', '-q'],
            ['symbolic-ref', 'HEAD', 'refs/heads/master'],
            ['config', 'user.email', 'fixture@example.invalid'],
            ['config', 'user.name', 'Prefix Fixture'],
            ['config', 'commit.gpgsign', 'false'],
            ['config', 'diff.noprefix', 'false'],
            ['config', 'diff.mnemonicPrefix', 'false'],
            ['config', 'core.abbrev', '7'],
            ['config', 'diff.context', '3'],
            ['config', 'color.ui', 'false'],
            ['config', 'color.diff', 'false'],
            ['config', 'diff.suppressBlankEmpty', 'false'],
            ['config', 'status.showUntrackedFiles', 'normal'],
            ['config', 'log.decorate', 'no'],
            ['config', 'i18n.logOutputEncoding', 'UTF-8'],
            ['config', 'i18n.commitEncoding', 'UTF-8'],
            ['config', 'log.date', 'default'],
            ['config', 'format.pretty', 'medium'],
            ['add', '-A'],
            ['commit', '-q', '-m', 'fixture: initial import'],
        ] as $argv) {
            $this->assertSame(
                0,
                self::git($root, $argv, $output),
                'git ' . implode(' ', $argv) . ' failed: ' . implode("\n", $output),
            );
        }

        // A FILE, not a config key, and at the TOP of the precedence chain
        // rather than in the middle of it. Attributes are consulted in the
        // order `$GIT_DIR/info/attributes`, then in-tree `.gitattributes`, then
        // `core.attributesFile`, then the XDG/system file — so writing this one
        // beats all three of the sources a review found (`core.attributesFile`,
        // `init.templateDir`, and a bare `XDG_CONFIG_HOME` holding
        // `git/attributes`). Each of those, saying `*.php -diff`, MEASURED the
        // prompt 4,844 -> 4,749 B and the prefix 4,670 -> 4,672, replacing the
        // patch body with `Binary files … differ`; with this file written,
        // MEASURED all three leave 4,844 / 4,670 untouched. `* diff` forces the
        // text path for every path in the fixture, which is what a working-diff
        // measurement needs.
        //
        // THE DIRECTORY IS CREATED RATHER THAN ASSUMED, and the reason is the
        // very knob two lines up. An earlier revision of this block wrote
        // straight into `$root/.git/info/` and said `init.templateDir` "SEEDS
        // this very path at `git init`". It seeds it only when the template
        // CARRIES an `info/` subdirectory. MEASURED, git 2.43.0:
        // `git init -q --template=<an empty directory>` produces a `.git`
        // holding `config HEAD objects refs` and NO `info` at all — so on a host
        // whose `~/.gitconfig` merely sets `init.templateDir`, the write
        // returned false and reddened FOUR tests at once with a message about
        // the attributes pin rather than about the host. That is the same
        // wrong-domain failure this whole knob list exists to record.
        // The return is CHECKED, and saying why is the point: an unchecked
        // mkdir() on a `.git` this process cannot write emits a PHP warning
        // naming mkdir and then reds the assertion below with "the gitattributes
        // family is unpinned" — a message about the pin rather than about the
        // host, which is the exact defect the paragraph above narrates.
        if (!is_dir($root . '/.git/info')) {
            $this->assertTrue(
                mkdir($root . '/.git/info', 0o700, true),
                'could not create ' . $root . '/.git/info on the scratch repository - the fixture repository is '
                    . 'not writable, which is a fact about this host and not about the attributes pin below',
            );
        }

        $this->assertNotFalse(
            file_put_contents($root . '/.git/info/attributes', "* diff\n"),
            'could not write .git/info/attributes on the scratch repository, so the gitattributes family is '
                . 'unpinned and the diff body is at the mercy of the developer\'s own attributes files',
        );

        $fixture->write('src/Alpha.php', self::ALPHA_FIRST_EDIT);

        return $fixture;
    }

    /**
     * Captured git output, capped before it is quoted into a failure message.
     *
     * See {@see GIT_SAID_MAX_BYTES} for why there is a cap at all. The
     * truncation is ANNOUNCED rather than silent, and carries the original
     * length, because a message that stops mid-sentence with no marker sends
     * the reader looking for a cause git never printed — the same wrong-domain
     * read every guard in this file exists to stop.
     *
     * @param list<string> $output Combined stdout/stderr as {@see git()} handed it back
     */
    private static function gitSaid(array $output): string
    {
        $text = implode("\n", $output);
        if (\strlen($text) <= self::GIT_SAID_MAX_BYTES) {
            return $text;
        }

        return substr($text, 0, self::GIT_SAID_MAX_BYTES)
            . ' [truncated at ' . self::GIT_SAID_MAX_BYTES . ' B of ' . \strlen($text) . ']';
    }

    /**
     * Run one git command under `$root` (or nowhere, for `--version`),
     * returning its exit code and handing back its combined output.
     *
     * `$globalConfig` replaces the caller's own `~/.gitconfig` for the duration
     * of the one command. The GLOBAL slot is the point of it: it sits BELOW the
     * repository's own config in the precedence chain, which is the only
     * arrangement that can show whether a repo-local pin defends a hostile host
     * value at all. Passing `/dev/null` is how a caller asks for "no global
     * config", which is a different statement from passing nothing.
     *
     * @param list<string>  $argv         Subcommand and flags, each escaped separately
     * @param list<string> &$output       Combined stdout/stderr, for the failure message
     * @param string|null   $globalConfig Path to stand in for the global config file, or null to inherit
     */
    private static function git(?string $root, array $argv, ?array &$output = null, ?string $globalConfig = null): int
    {
        $command = 'git';
        if ($globalConfig !== null) {
            $command = 'GIT_CONFIG_GLOBAL=' . escapeshellarg($globalConfig) . ' ' . $command;
        }
        if ($root !== null) {
            $command .= ' -C ' . escapeshellarg($root);
        }
        foreach ($argv as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        exec($command . ' 2>&1', $output, $exitCode);

        return $exitCode;
    }

    /** Bytes the two strings share from the front - the whole cache contract. */
    private static function commonPrefixLength(string $a, string $b): int
    {
        $limit = min(\strlen($a), \strlen($b));
        $i = 0;
        while ($i < $limit && $a[$i] === $b[$i]) {
            ++$i;
        }

        return $i;
    }

    /**
     * The same prompt, re-spliced into the pre-P3.S1 layer order.
     *
     * The old assembly was base heredoc, `<env>`, repo map, instruction
     * documents, memory, skills - so lifting the `<env>` tail back to
     * immediately after the heredoc reproduces it exactly, and by construction
     * loses no byte. The split is taken at the two structural fences rather
     * than at the base prompt's prose end-marker: prose is what a later step
     * edits.
     *
     * It asserts the shipped order first, so a tree where `<env>` is NOT last
     * fails here with that sentence instead of silently producing a
     * meaningless splice.
     */
    private static function reassembledWithEnvAtLayerTwo(string $prompt): string
    {
        $mapAt = strpos($prompt, "\n\n<repo-map>");
        $envAt = strpos($prompt, "\n\n<env>\n");
        self::assertIsInt($mapAt, 'the fixture prompt carries no <repo-map> layer to splice around');
        self::assertIsInt($envAt, 'the fixture prompt carries no <env> layer to splice');

        // The same one-occurrence precondition the marker loop applies, and for
        // the same reason: strpos() takes the FIRST hit, so a prompt carrying
        // two "\n\n<env>\n" runs — an instruction document or a memory note
        // quoting the fence — would splice around the wrong one and still come
        // out the right LENGTH, because the length guard below is structural.
        self::assertSame(
            1,
            substr_count($prompt, "\n\n<env>\n"),
            'the splice needs exactly one <env> fence to be unambiguous',
        );
        self::assertGreaterThan(
            $mapAt,
            $envAt,
            'this re-splice assumes the shipped order, repo map ahead of <env>; that is no longer true',
        );

        return substr($prompt, 0, $mapAt)                   // the base heredoc
            . substr($prompt, $envAt)                       // <env>, back at layer 2
            . substr($prompt, $mapAt, $envAt - $mapAt);     // repo map … skill listing
    }

    // -------------------------------------------------------------------------
    // Temp-dir plumbing
    // -------------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush-prompt-stability-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}

/**
 * Declares real parameters — the ordinary shape of a tool schema in the prefix.
 *
 * Local to this file rather than shared with `ToolSchemaEncodingTest`: those
 * stubs live in a file PSR-4 will not autoload by class name, so reaching for
 * them would make this suite depend on test-file execution order.
 */
final class StablePrefixRealToolStub implements Tool
{
    public function name(): string { return 'real'; }
    public function description(): string { return 'takes a path'; }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'a path']],
            'required' => ['path'],
        ];
    }
    public function execute(array $args): ToolResult
    {
        return ToolResult::ok('ok');
    }
}

/** Declares no parameters — the `{}`-vs-`[]` encoding hazard inside the prefix. */
final class StablePrefixEmptyToolStub implements Tool
{
    public function name(): string { return 'empty'; }
    public function description(): string { return 'takes nothing'; }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
    public function execute(array $args): ToolResult
    {
        return ToolResult::ok('ok');
    }
}
