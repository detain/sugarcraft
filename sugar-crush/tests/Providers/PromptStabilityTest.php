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

        // …and the divergence is confined to the tail.
        $this->assertSame(substr($batch, 0, -1) . ',"stream":true}', $streamed);
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

        shell_exec('git -C ' . escapeshellarg($dir) . ' init -q 2>/dev/null');
        if (!is_dir($dir . '/.git')) {
            $this->markTestSkipped('git is unavailable in this environment');
        }

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
     * shape, the five-layer width {@see STABLE_LAYERS_BYTES}, and the three-way
     * ordering of the three post-reorder rows.
     *
     * HOW FAR EACH ROW CAN ROT WHILE THE FILE STAYS GREEN, and an earlier
     * revision of this paragraph got it wrong in BOTH directions by leaving
     * `$diffAt` out of that list. It said "down to 4,404". MEASURED, by making
     * `Recent commits:` volatile with a four-byte counter in
     * {@see \SugarCraft\Crush\Context\EnvironmentBlock}: a prefix of 4,421 —
     * 17 B ABOVE that supposedly-green floor — already reds, at
     * `the git status or log diverged before the diff body did`. The nice row
     * is pinned far tighter than the constant suggests, by
     * `$diffAt = 4,512`; the capped and status rows, which have no `$diffAt`
     * assertion of their own, are the ones bounded only at
     * {@see MIN_STABLE_PREFIX_BYTES}. So: the nice row cannot fall below 4,513
     * green, and the other two can fall to 4,096.
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
     * COUNT, so `(2 files)` becomes `(3 files)` at byte 3,188 — ahead of
     * everything P3.S1 moved, and below this floor.
     * {@see testANewSourceFileVoidsThePrefixAcrossTurnsButNotWithinOne()} pins
     * that limit, and the lifetime that saves it.
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
     * the only one that is host-independent. MEASURED by re-running the fixture
     * under a `TMPDIR` ten bytes longer: the prompt goes 4,844 -> 4,854 and the
     * prefix 4,670 -> 4,680, and this constant does not move — the fixture
     * root's path lives inside `<env>`, past both fences, and so do
     * `OS version:` and `PHP version:`. What it DOES move with is this file's
     * own fixture content, which this file owns. If a later step edits the
     * instruction documents, the memory store or the skill body the fixture
     * writes, re-measure this and move it; that is the assertion working.
     */
    private const STABLE_LAYERS_BYTES = 1575;

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
        // for no input, only its failure message. MEASURED — at a prefix of
        // 4,421 this floor passes and the `$diffAt` assertion is what fires.
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

        // WHAT MOVED, AND HOW MUCH IT MOVED, ARE THREE DIFFERENT STATEMENTS.
        // They are asserted here strongest-first, because the coarser two are
        // implied by this one and would otherwise be what a reader sees red.
        //
        // 1. THIS ONE BINDS THE ASSEMBLER: the region between the two fences is
        //    exactly the five layers P3.S1 lifted, not four and not six.
        //    MEASURED, by demoting `<repo-map>` in `Runtime::buildSystemPrompt()`
        //    so only one layer sits between the fences, it reds with 727
        //    against 1,575.
        $this->assertSame(
            self::STABLE_LAYERS_BYTES,
            $envAt - $mapAt,
            'the region between <repo-map> and <env> is ' . ($envAt - $mapAt) . ' bytes, not '
                . self::STABLE_LAYERS_BYTES . ' - either a layer moved out from between the fences, or this '
                . "file's own fixture content changed size and the constant needs re-measuring",
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
        // holding exactly (727 = 727), and what reds is assertion 1 (or, before
        // assertion 1 existed, the floor). Kept because a helper guard is worth
        // having and this one is free; labelled correctly because a guard
        // advertised as something stronger is how a test file stops being read.
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
     * count, which sits at byte 3,188 — ahead of `<env>` and below the floor.
     * That shape is out of scope here and has its own test,
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
     * rediscovered: moving `<env>` last does not make everything ahead of it
     * stable, because `<repo-map>` is derived from the working tree too.
     *
     * {@see \SugarCraft\Crush\Context\RepoMapBlock} emits a per-directory count
     * of `.php` files. Create one and `- src/  ->  Fixture\Prefix\  (2 files)`
     * becomes `(3 files)` — MEASURED at byte 3,188 on this fixture, which is
     * ahead of `<env>` (4,056), ahead of the instruction documents, the memory
     * block and both skill layers, and BELOW
     * {@see MIN_STABLE_PREFIX_BYTES}. A turn that adds a source file therefore
     * re-prefills almost everything, and no amount of moving `<env>` changes
     * that.
     *
     * WHAT SAVES IT IS A LIFETIME, AND THE TWO ARE WORTH TELLING APART.
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} runs once per STEP
     * of the agentic loop and reads a repo map memoised on the Runtime, while
     * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} builds a FRESH
     * Runtime per user TURN. So:
     *
     *   - within one turn, the map is frozen and a new file moves only `<env>`
     *     — MEASURED prefix 4,403, the same figure as any other `Status:`
     *     change;
     *   - across turns, the map is re-captured and the prefix collapses to
     *     3,188.
     *
     * Both are asserted below, from ONE fixture in ONE test, because the pair
     * is the finding: the within-turn number alone reads as "the reorder
     * worked" and the across-turn number alone reads as "the reorder did
     * nothing", and neither sentence is true on its own.
     *
     * This is a PIN ON A MEASURED LIMIT, not an endorsement. If a later step
     * makes the repo map stable across turns — capturing it per session, or
     * dropping the file counts — the across-turn assertion here is expected to
     * flip, and it should be rewritten deliberately rather than deleted
     * quietly. The finding itself lives in `src/Context/RepoMapBlock.php` and
     * `src/Backend/EngineBackend.php`, both outside this step's declared file
     * list, so it is reported in the worklog and pinned here rather than fixed.
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
        $this->assertLessThan(
            self::MIN_STABLE_PREFIX_BYTES,
            $acrossTurns,
            'the across-turn prefix now clears the floor; the limit this test pins is gone',
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
     * injected by {@see PromptFixture}; the branch name; and the eleven git
     * config knobs listed at the `foreach` below — a list that is "found", not
     * "exhaustive", and has grown at every review so far. What is NOT: the
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
        $fixture->write(
            'AGENTS.md',
            "# Fixture conventions\n\nRun the suite before you push.\nNever edit generated files by hand.\n",
        );

        $fixture->memoryStore()->add('The fixture repository pins the prefix measurement.', MemoryScope::Project);

        $fixture->addSkill(new Skill(
            name: 'prefix-demo',
            description: 'A skill body that occupies the stable region of the prompt.',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: '',
            paths: [],
            content: "Use this skill when measuring the cache prefix.\n",
            sourcePath: $root . '/.sugar-crush/skills/prefix-demo/SKILL.md',
            source: SkillSource::Native,
        ));

        // `symbolic-ref` rather than `init -b`, which needs git 2.28; and
        // gpgsign forced off because a host that signs by default would hang
        // this on a passphrase prompt. Every exit code is asserted: a silently
        // failed `commit` leaves an empty `Recent commits:` field that reads
        // exactly like a repository with no history.
        //
        // THE ELEVEN CONFIG KNOBS BELOW ARE NOT DECORATION. `EnvironmentBlock`
        // shells out to plain `git`, so the developer's own `~/.gitconfig`
        // reaches the assembled prompt, and REPOSITORY-local config is the only
        // lever a test has over that (it outranks global without touching the
        // environment of any other test). Only over `~/.gitconfig`, though:
        // MEASURED, `GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.abbrev
        // GIT_CONFIG_VALUE_0=20` beats every pin below and takes the prompt to
        // 4,883/4,696. No ordinary shell sets it, and no repo-local key can
        // outrank it, so it is recorded as the boundary of this mechanism
        // rather than defended against.
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
        //   `status.showUntrackedFiles=no`  moves NOTHING on this fixture —
        //                                   MEASURED 4,844/4,670, unchanged,
        //                                   because every file it dirties is
        //                                   TRACKED. It is in this list for the
        //                                   OTHER repository in this file: it
        //                                   makes an untracked file invisible,
        //                                   so the two renders in
        //                                   {@see testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()}
        //                                   come out IDENTICAL and that test's
        //                                   measurement goes vacuous. That
        //                                   repository carries its own pin
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
        // and MEASURED with all eleven pinned, on a host with none of them set,
        // the numbers are unchanged at 4,844/4,670 — so the pin costs nothing
        // here, and it is what makes the figures reproducible on a host whose
        // git config this list covers. NOT "anywhere": see the paragraph on
        // completeness below, which an earlier revision of this comment
        // contradicted in the same breath.
        //
        // `log.date` and `format.pretty` ARE inert, because `--oneline`
        // overrides both — but `log.decorate` is in that same `log.*` family
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
        // and pinned a sibling key that does not cover it. If a twelfth is ever
        // wanted, look there first — at a family this list already claims.
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
        // after the fifth. Five reviews, five more knobs; the reasonable
        // prediction is that a sixth would find a twelfth. A knob that moves the
        // byte count WITHOUT reddening anything is the failure mode this
        // paragraph exists to make visible — `core.abbrev`, `color.ui`,
        // `color.diff`, `log.decorate` and both `i18n.*` keys are all of that
        // kind — so the honest statement is "eleven found" and not "eleven
        // exist". Measured-and-inert, recorded so the next reader does not
        // re-measure them: `log.date`, `format.pretty`, `core.quotePath`,
        // `init.templateDir`, `diff.algorithm`, `diff.indentHeuristic`,
        // `status.relativePaths`, `log.abbrevCommit`, `core.autocrlf`.
        // `diff.external`, `core.bigFileThreshold=1` and a `core.excludesFile`
        // naming the tracked file all move the bytes but RED, which is the
        // harmless direction and needs no pin. `color.status` and
        // `color.branch` are inert here for a reason worth writing down rather
        // than re-deriving: `status --porcelain` and `branch --show-current`
        // are plumbing-ish forms that never colour, whatever the slot says.
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
            ['add', '-A'],
            ['commit', '-q', '-m', 'fixture: initial import'],
        ] as $argv) {
            $this->assertSame(
                0,
                self::git($root, $argv, $output),
                'git ' . implode(' ', $argv) . ' failed: ' . implode("\n", $output),
            );
        }

        $fixture->write('src/Alpha.php', self::ALPHA_FIRST_EDIT);

        return $fixture;
    }

    /**
     * Run one git command under `$root` (or nowhere, for `--version`),
     * returning its exit code and handing back its combined output.
     *
     * @param list<string>  $argv   Subcommand and flags, each escaped separately
     * @param list<string> &$output Combined stdout/stderr, for the failure message
     */
    private static function git(?string $root, array $argv, ?array &$output = null): int
    {
        $command = 'git';
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
