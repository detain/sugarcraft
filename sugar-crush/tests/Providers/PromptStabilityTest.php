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
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;
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
    // The environment block, which sits at the very front of the prefix.
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
        // HAZARD PIN, not an endorsement. `render()` shells out to
        // `git status --porcelain` every call rather than freezing the snapshot
        // at capture time, and `Runtime::environmentSnapshot()` memoizes only
        // per Runtime — while `EngineBackend::complete()` builds a fresh
        // Runtime per user turn. So the moment the agent writes a file, the
        // <env> block at the very front of the system prompt changes and the
        // ENTIRE RadixAttention prefix is voided for the rest of the session.
        // See the report accompanying W4.S3; this test exists so the mechanism
        // is observable. If a later change freezes the snapshot at capture
        // (the fix), this assertion is expected to flip and should be rewritten
        // deliberately rather than deleted quietly.
        $dir = $this->tempDir();

        shell_exec('git -C ' . escapeshellarg($dir) . ' init -q 2>/dev/null');
        if (!is_dir($dir . '/.git')) {
            $this->markTestSkipped('git is unavailable in this environment');
        }

        $block = new EnvironmentBlock($dir, SglangProvider::DEFAULT_MODEL, new DateTimeImmutable('2026-08-10 12:00:00'));
        $before = $block->render();

        file_put_contents($dir . '/scratch.txt', 'written by the agent mid-session');
        $after = $block->render();

        $this->assertNotSame(
            $before,
            $after,
            'expected the <env> git snapshot to track the working tree; if it no longer does, D7 got fixed',
        );
        $this->assertStringContainsString('scratch.txt', $after);
    }

    // -------------------------------------------------------------------------
    // What the P3.S1 reorder bought: how far into the prompt the hit survives.
    // -------------------------------------------------------------------------

    /**
     * The floor the assembled prompt's shared prefix must clear on the fixture
     * {@see dirtyRepoFixtureWithEveryStableLayer()} builds, in bytes.
     *
     * MEASURED 2026-08-29, PHP 8.3.6, Linux 6.8.0-138-generic, three takes per
     * side and identical on all three, by assembling two consecutive prompts
     * through the real private
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} over that fixture
     * and counting bytes to the first one that differs:
     *
     *   - `<env>` LAST, the order P3.S1 shipped: **4,670** of 4,844 bytes.
     *   - `<env>` back at layer 2, the pre-P3.S1 order, reproduced by moving
     *     the single `$base .= "\n\n" . $this->environmentSnapshot($app)->render();`
     *     statement in `Runtime::buildSystemPrompt()` back above the repo map
     *     and restoring it afterwards: **3,095** of 4,844.
     *
     * Both figures are OF THAT FIXTURE and of nothing else: a different
     * repository, a different instruction set or a different edit moves them,
     * and §3.4's own pair (598 B then 615 B, first differing at 524) is of a
     * two-edit `<env>` in isolation rather than of an assembled prompt. The
     * prompt is the same 4,844 bytes in both orders - this was a reorder, not
     * an addition - and the reorder moved 1,575 of them from behind the first
     * differing byte to in front of it.
     *
     * WHY THE FLOOR IS 4,096 AND NOT 4,670. The exact value moves with bytes
     * this file does not own: `OS version:` and `PHP version:` are read off
     * the host at render time, and the base heredoc is prose four later steps
     * are licensed to edit. Pinning 4,670 would red on a host with a shorter
     * kernel string, which is a fact about the host and not about the layer
     * order. 4,096 sits 1,001 B ABOVE the pre-reorder measurement - that is
     * what makes it discriminating, because the old assembly cannot reach it
     * on this fixture - and 574 B BELOW the post-reorder one, which is the
     * headroom those host lines need.
     *
     * It is a MAGNITUDE floor and nothing more. The ORDERING decision is
     * pinned by the marker assertions beside it and by the old-order control,
     * neither of which depends on a literal; the SIZE OF THE WIN is pinned by
     * {@see MIN_PREFIX_GAIN_BYTES}, which is invariant to both host lines and
     * base-heredoc edits because those bytes cancel between the two orders. So
     * if a deliberate prose cut ever reds this number, re-measure it and move
     * it, and expect everything around it to stay green - they are assertions
     * about different properties.
     */
    private const MIN_STABLE_PREFIX_BYTES = 4096;

    /**
     * The floor on how much the reorder must move the first differing byte, in
     * bytes, on the same fixture.
     *
     * MEASURED 4,670 - 3,095 = **1,575** by the same runs. This delta is the
     * combined size of the four layers the reorder lifted over `<env>` - the
     * repo map, the project instructions, the project memory and the two skill
     * layers - so unlike {@see MIN_STABLE_PREFIX_BYTES} it is unaffected by the
     * length of the base heredoc or of the host lines, which shift both sides
     * equally and cancel. It moves only when this file's own fixture content
     * moves, which is why it carries the tighter margin of the two.
     */
    private const MIN_PREFIX_GAIN_BYTES = 1500;

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

        // THE STEP'S HEADLINE ASSERTION.
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
        $this->assertSame(\strlen($first), \strlen($oldFirst), 'the re-splice lost or duplicated bytes');

        $oldPrefix = self::commonPrefixLength($oldFirst, $oldSecond);
        $oldMapAt = strpos($oldFirst, '<repo-map>');
        $this->assertIsInt($oldMapAt);
        $this->assertLessThan(
            $oldMapAt,
            $oldPrefix,
            'the counter reported a prefix reaching past <repo-map> with <env> AHEAD of it, which cannot be true '
                . '- the instrument is broken, not the code',
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_PREFIX_GAIN_BYTES,
            $prefix - $oldPrefix,
            'the reorder moved the first differing byte by only ' . ($prefix - $oldPrefix) . ' bytes',
        );
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
     * Host-independence is a property of the fixture, not an accident. `git
     * init` runs BEFORE any prompt is assembled, and
     * {@see \SugarCraft\Crush\Context\InstructionFileLoader::ancestorRoot()}
     * returns null the moment `$root/.git` exists, so the ancestor walk that
     * would otherwise read `CLAUDE.md` from `/tmp` and `/` never starts and
     * nothing outside this directory can reach the prompt. The date and
     * platform are injected by {@see PromptFixture}; the branch is forced to
     * `master` so a host defaulting to `main` does not move the byte count.
     */
    private function dirtyRepoFixtureWithEveryStableLayer(): PromptFixture
    {
        if (self::git(null, ['--version'], $probe) !== 0) {
            $this->markTestSkipped('git is unavailable in this environment');
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
        foreach ([
            ['init', '-q'],
            ['symbolic-ref', 'HEAD', 'refs/heads/master'],
            ['config', 'user.email', 'fixture@example.invalid'],
            ['config', 'user.name', 'Prefix Fixture'],
            ['config', 'commit.gpgsign', 'false'],
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
