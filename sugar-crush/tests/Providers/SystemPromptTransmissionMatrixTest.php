<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\StreamResponse;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * The transmission matrix: every provider the factory can build must put
 * CompleteRequest::$systemPrompt on the wire, in whatever field that
 * provider's protocol uses.
 *
 * One test file walks the whole roster — derived from src/Providers/, not
 * hand-maintained — hands each provider an identical CompleteRequest carrying
 * a distinctive sentinel, and pins the sentinel to the exact wire slot of that
 * provider's protocol on BOTH the complete() and the completeStream() path.
 * A provider added later with no systemPrompt handling fails
 * {@see testEveryProviderImplementerHasATransmissionContract} on day one; a
 * provider whose transmission is deleted fails its per-provider test.
 *
 * Each provider is driven exactly the way its own suite drives it (Sglang /
 * Custom through a mocked Guzzle client and the captured wire body,
 * OpenAI through a captured create()/createStreamed() params array, Bedrock
 * through the AWS MockHandler's last command, Vertex through its injectable
 * predictor/streamer seams, ClaudeCode through ClaudeCodeInvocation's public
 * printModeArgs() wire shaper — its execute() step is proc_open and cannot
 * run in a unit test, so the shaper is driven with the exact options the
 * provider builds, as ClaudeCodeProviderTest does).
 */
final class SystemPromptTransmissionMatrixTest extends TestCase
{
    /**
     * The wire location each covered provider's protocol uses for
     * CompleteRequest::$systemPrompt.
     *
     * ONE ROW PER REQUEST-BODY BUILDER, NOT PER PROVIDER CLASS.
     *
     * THAT HEADLINE WAS FALSE OF THIS MAP WHEN IT WAS WRITTEN, AND IS NOW
     * TRUE (rule 42: corrected, not deleted). MEASURED: OpenAI, Custom and
     * Bedrock each assemble their request body TWICE, inline, once per path -
     * OpenAIProvider.php:79-95 and :115-129, CustomProvider.php:155-160 and
     * :210-215, BedrockProvider.php:159-172 and :206-218 (Bedrock shares
     * systemBlocks() for the hoist but not the surrounding body, and its two
     * `inferenceConfig` blocks genuinely differ) - yet each held ONE row. The
     * consequence was not cosmetic: {@see capturedBodyFor()} drives what the
     * map says, so it drove only the UNARY builder for those three, and the
     * coverage assertion in
     * {@see testTheContractSlotSpellingsAreLoadBearing()} was satisfied
     * without any STREAMING builder ever being resolved through the contract.
     * The instrument did not close, for three providers, the exact defect
     * class it was built to close for Vertex - which is the same conflation
     * ("complete() passes") that hid the Vertex Google defect in the first
     * place. Those three rows are now split `#complete`/`#stream`, with a
     * drive each. The remaining three body-shaped rows are genuinely
     * single-builder and stay undiscriminated: Sglang shares buildParams()
     * (SglangProvider.php:642, called from :447 and :464); Vertex
     * `#anthropic` is one method serving both paths
     * (anthropicBody($request, stream:), VertexProvider.php:431, called from
     * :240 and :314); Vertex `#google` is reached on the stream path only by
     * delegation (completeStream() yields complete(),
     * VertexProvider.php:290-298). So the headline now holds for 6 of 6
     * body-shaped rows, where it held for 3 of 6 before.
     *
     * BOTH HALVES OF THAT FIGURE ARE RE-DERIVABLE, which is the only reason
     * it is allowed to stand (16.2: a figure without a generator rots). The
     * denominator is generated, not asserted in prose: it is
     * `count(TRANSMISSION_CONTRACT) - count(NON_BODY_CONTRACT_ROWS)`, and
     * {@see testTheContractSlotSpellingsAreLoadBearing()} reds if the drive
     * table and that set stop agreeing, in BOTH directions. The numerator is
     * re-derived by opening the builder citations enumerated in the two
     * paragraphs above - every one names a file and a line range - and
     * counting how many rows have one builder each; the `3 of 6` half is the
     * same count taken against `git show HEAD~1`'s map, whose rows are gone
     * but whose builders are all still in the tree at the same citations.
     * Neither half is a count this file measures at runtime, so if you change
     * a provider's path structure you must re-walk those citations rather
     * than trust this sentence.
     *
     * WHAT THIS ALPHABET STILL CANNOT EXPRESS (rule 31). A row says WHICH
     * slot a builder writes; it does not say the two builders under one class
     * agree, nor that a path exists at all. A provider that silently stopped
     * having a streaming path would drop its `#stream` drive, and the
     * coverage assertion would red - but only because the ROW is still there.
     * Delete both the row and the drive together and nothing here notices;
     * that is what the derived-roster test
     * {@see testEveryProviderImplementerHasATransmissionContract()} covers at
     * CLASS granularity, and nothing covers at PATH granularity.
     *
     * WHAT THIS PARAGRAPH USED TO SAY. It said the row-per-class shape was
     * WHY the Vertex Google defect survived Phase 1: the map was
     * `array<class-string, string>`, VertexProvider builds TWO bodies chosen
     * at call time by isAnthropicModel() (VertexProvider.php:231, :397-400),
     * and a single `VertexProvider::class => 'system'` row could not SAY that,
     * so "nothing here could notice that only one of them transmitted".
     *
     * WHAT IS TRUE NOW. That causal claim was measured FALSE and is corrected
     * rather than deleted (rule 42). The map's VALUES had no enforcement power
     * at all — only `array_keys()` was ever read — so the key TYPE was never
     * what let the defect through. MEASURED before the fix below landed:
     * rewriting `BedrockProvider::class => 'system[0].text'` to `=> 'WRONG'`
     * AND the Vertex `#google` row to `=> 'TOTAL-NONSENSE-NOT-A-FIELD'` left
     * the whole file green at `OK (17 tests, 54 assertions)`. The REAL cause
     * is simpler and is a roster gap, not a key-shape gap: NO TEST DROVE A
     * NON-`claude` MODEL ID THROUGH A TRANSMISSION ASSERTION. Every Vertex
     * test here hardcoded a `claude-*` model, so the Google builder was never
     * executed by this file, and no key shape could have rescued that.
     *
     * WHY THE ROW-PER-BUILDER SHAPE STILL EARNS ITS PLACE. Two reasons, and
     * both are now load-bearing rather than descriptive. First, the alphabet
     * argument survives its false causal claim: a map that cannot SAY a class
     * has two builders cannot be walked to drive both, and
     * {@see testTheContractSlotSpellingsAreLoadBearing()} walks it to do
     * exactly that — deleting the `#google` row now reds, because the drive
     * table below asserts every row it drives is present AND that every
     * body-shaped row is driven. Second, the two Vertex arms genuinely land in
     * different slots (`system` vs `instances[0].context`), so one row per
     * class would have to drop one of the two facts.
     *
     * A class with more than one builder therefore contributes several rows,
     * keyed `<FQCN>#<discriminator>`. The class half stays a `::class`
     * reference so a class rename still breaks this file at compile time.
     *
     * EVERY VALUE HERE IS A BODY PATH READ BY {@see resolveContractSlot()},
     * except the one row named in {@see NON_BODY_CONTRACT_ROWS}.
     *
     * Four families (the `/` in a citation pair separates the `#complete`
     * builder from the `#stream` one):
     * - Sglang/Custom/OpenAI prepend an OpenAI-chat-shaped leading system
     *   message into `messages` (SglangProvider.php:672-677,
     *   CustomProvider.php:155-160 / :210-215, OpenAIProvider.php:90-95 /
     *   :127-130);
     * - Bedrock hoists it into the Converse top-level `system` block list
     *   (BedrockProvider.php:164-166 / :215-217 via systemBlocks() :337-343);
     * - Vertex hoists it into the Anthropic body's top-level `system` string
     *   (VertexProvider.php:455-458) or, for a `publishers/google` model, into
     *   `instances[0].context` (VertexProvider.php:1137-1139) — both through
     *   the one joiner, systemInstruction() :508;
     * - ClaudeCode turns it into a `--system-prompt` CLI argv pair
     *   (ClaudeCodeProvider.php:80 / :105 ->
     *   ClaudeCodeInvocation.php:75-78).
     *
     * EchoProvider is deliberately absent: it is a test double with no wire —
     * it echoes a blockquote in PHP and never serializes a request payload
     * (EchoProvider.php:18-23, 84-91). See
     * {@see testEveryProviderImplementerHasATransmissionContract} for the
     * derived-roster assertion that names this exemption.
     *
     * @var array<string, string>
     */
    private const TRANSMISSION_CONTRACT = [
        SglangProvider::class => 'messages[0]',
        CustomProvider::class . '#complete' => 'messages[0]',
        CustomProvider::class . '#stream' => 'messages[0]',
        OpenAIProvider::class . '#complete' => 'messages[0]',
        OpenAIProvider::class . '#stream' => 'messages[0]',
        BedrockProvider::class . '#complete' => 'system[0].text',
        BedrockProvider::class . '#stream' => 'system[0].text',
        VertexProvider::class . '#anthropic' => 'system',
        VertexProvider::class . '#google' => 'instances[0].context',
        ClaudeCodeProvider::class => '--system-prompt argv',
    ];

    /**
     * The rows of {@see TRANSMISSION_CONTRACT} whose value is NOT a
     * request-body path, excluded BY KEY and with a named reason — the same
     * discipline `EchoProvider`'s exemption from the roster gets.
     *
     * `--system-prompt argv` is a CLI argument vector, not a JSON document:
     * ClaudeCodeProvider never serializes a request body at all, it shells out
     * (ClaudeCodeProvider.php:80 / :105 -> ClaudeCodeInvocation.php:75-78).
     * {@see resolveContractSlot()} walks bodies, so this row has no body to
     * walk; it is pinned instead by
     * {@see testClaudeCodeTransmitsSystemPromptAsASystemPromptArgvPairOnBothPaths()},
     * which asserts the flag AND its adjacent value.
     *
     * @var list<string>
     */
    private const NON_BODY_CONTRACT_ROWS = [
        ClaudeCodeProvider::class,
    ];

    /**
     * What {@see resolveContractSlot()} returns when the declared slot is not
     * in the body — distinctive, so it can never be confused with a real
     * value, an empty string, or `null`, each of which a body could legitimately
     * hold. Rule 17: `''`/`null`/`[]` are also what a dead instrument returns.
     */
    private const SLOT_NOT_FOUND = 'P1S7-SLOT-NOT-FOUND-1d0e77bc';

    /**
     * The distinctive, FIXED marker every covered provider must put on the
     * wire verbatim. Recognisable in any payload by grep, and unique enough
     * that a `substr_count(..., 1)` over the whole serialized body proves the
     * sentinel rides the protocol's system slot and nowhere else.
     */
    private const SENTINEL = 'P1S7-SENTINEL-4f8a2c91';

    /**
     * A `publishers/google` model id, which is what selects VertexProvider's
     * SECOND body builder: isAnthropicModel() is
     * `str_contains(strtolower($model), 'claude')` (VertexProvider.php:397-400)
     * and modelId() prefers the REQUEST's model over the provider's own
     * default (:412-415), so this id on the request routes there whatever the
     * helper below constructs the provider with.
     */
    private const VERTEX_GOOGLE_MODEL = 'gemini-1.5-pro-002';

    /**
     * One OpenAI-compatible streamed chunk plus the terminal marker, carrying
     * the full envelope (id/object/created/model/index) the openai-php SDK's
     * CreateStreamedResponse requires — byte-identical to the fixture
     * OpenAIProviderTest::testCompleteStreamPayloadLeadsWithSystemPrompt
     * feeds createStreamed(). Sglang/Custom parse only `choices[0].delta`,
     * so the extra keys are harmless to them.
     */
    private const SSE_BODY = "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\",\"created\":1,\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n\n";

    // =========================================================================
    // Derived roster
    // =========================================================================

    public function testEveryProviderImplementerHasATransmissionContract(): void
    {
        // Derived roster (rule 15: derive, never hand-maintain): the SAME
        // derivation ProviderRequestResponseTest::providerImplementers() runs
        // for its streamed-usage contract, shared so the two contracts cannot
        // drift apart. A future provider with no transmission entry reds this
        // test on day one.
        $implementers = ProviderRequestResponseTest::providerImplementers();

        // A multi-body provider contributes several rows under one class, and
        // the roster diff is about CLASS coverage, so drop the
        // `#<discriminator>` suffix and de-duplicate before comparing.
        $contracted = array_values(array_unique(array_map(
            static function (string $key): string {
                $fqcn = explode('#', $key)[0];

                return substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            },
            array_keys(self::TRANSMISSION_CONTRACT),
        )));

        $this->assertSame(
            ['EchoProvider'],
            array_values(array_diff($implementers, $contracted)),
            'Every ProviderInterface implementer except EchoProvider must transmit '
            . 'CompleteRequest::$systemPrompt onto the wire. EchoProvider is exempted WITH A '
            . 'NAMED REASON: it is a test double with no wire — it echoes a blockquote in PHP '
            . 'and never serializes a request payload (EchoProvider.php:18-23, 84-91). A NEW '
            . 'provider must add a TRANSMISSION_CONTRACT entry AND a per-provider transmission '
            . 'test.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($contracted, $implementers)),
            'a TRANSMISSION_CONTRACT entry names a class that no longer implements '
            . 'ProviderInterface; delete the entry.',
        );
    }

    /**
     * THE MAP'S VALUES ARE LOAD-BEARING. This test exists for nothing else.
     *
     * Before it, only `array_keys(self::TRANSMISSION_CONTRACT)` was ever read,
     * so the slot spellings were metadata standing in for a test (1.11).
     * MEASURED on the unfixed file: deleting the whole Vertex `#google` row,
     * and separately rewriting two rows' values to `'WRONG'` and
     * `'TOTAL-NONSENSE-NOT-A-FIELD'`, both left the file green at
     * `OK (17 tests, 54 assertions)`. Both mutations must red here.
     *
     * POSITIVE AND NEGATIVE, IN ONE TEST (rule 16 — a sibling test is a
     * separately deletable unit, so the control lives here):
     *
     * - POSITIVE, per driven row: the row must EXIST in the map, its declared
     *   slot must resolve against the real captured body, and it must resolve
     *   to the EXACT value that protocol puts there (rule 20 — an exact value,
     *   not "contains the sentinel", because `messages[0]` broadened to
     *   `messages` still "contains" it);
     * - COVERAGE, both directions: every body-shaped row is driven, and every
     *   driven key is a row. A row deleted from the map reds the first; a row
     *   added without a drive reds the second;
     * - NEGATIVE CONTROL: the resolver must ANSWER NOT-FOUND for a well-formed
     *   slot that is simply wrong for that body, and for a malformed one. An
     *   instrument that says "found" for everything is indistinguishable from
     *   one that is dead.
     *
     * WHAT THIS TEST CANNOT EXPRESS (rule 31): it pins the slot SPELLING and
     * the value AT that spelling. It does not pin that no OTHER path also
     * carries the sentinel — that is the `substr_count(..., 1)` assertion in
     * each per-provider test, which is kept alongside this one precisely
     * because the two catch different mutations.
     */
    public function testTheContractSlotSpellingsAreLoadBearing(): void
    {
        // The expected value AT each declared slot, stated as the protocol
        // fact it is — never rendered from the map (rule 21: an expected value
        // derived from the constant under test stays green under any mutation
        // of it).
        $expectedAtSlot = [
            SglangProvider::class => ['role' => 'system', 'content' => self::SENTINEL],
            CustomProvider::class . '#complete' => ['role' => 'system', 'content' => self::SENTINEL],
            CustomProvider::class . '#stream' => ['role' => 'system', 'content' => self::SENTINEL],
            OpenAIProvider::class . '#complete' => ['role' => 'system', 'content' => self::SENTINEL],
            OpenAIProvider::class . '#stream' => ['role' => 'system', 'content' => self::SENTINEL],
            BedrockProvider::class . '#complete' => self::SENTINEL,
            BedrockProvider::class . '#stream' => self::SENTINEL,
            VertexProvider::class . '#anthropic' => self::SENTINEL,
            VertexProvider::class . '#google' => self::SENTINEL,
        ];

        $bodyShapedRows = array_values(array_diff(
            array_keys(self::TRANSMISSION_CONTRACT),
            self::NON_BODY_CONTRACT_ROWS,
        ));

        // Coverage, direction 1: every body-shaped row is driven below. A row
        // ADDED to the map with no drive reds here rather than riding along
        // unexercised.
        sort($bodyShapedRows);
        $driven = array_keys($expectedAtSlot);
        sort($driven);
        $this->assertSame(
            $bodyShapedRows,
            $driven,
            'every TRANSMISSION_CONTRACT row that is a body path must be driven by this test, '
            . 'and every row driven here must be in the map. A row deleted from the map (the '
            . 'measured mutation that used to leave this file green) reds here.',
        );

        foreach ($expectedAtSlot as $key => $expected) {
            // Coverage, direction 2: deleting the row reds inside
            // contractSlotFor(), before any slot is resolved.
            $slot = $this->contractSlotFor($key);
            $body = $this->capturedBodyFor($key);

            $this->assertSame(
                $expected,
                $this->resolveContractSlot($body, $slot),
                sprintf(
                    'TRANSMISSION_CONTRACT declares %s transmits the system prompt at `%s`, but '
                    . 'that path does not hold it in the body %s actually built: %s',
                    $key,
                    $slot,
                    $key,
                    (string) json_encode($body),
                ),
            );
        }

        // NEGATIVE CONTROLS, through the same resolver, against a real body.
        // Without these, a resolver that returned the sentinel for any input
        // would pass every assertion above.
        $googleBody = $this->capturedBodyFor(VertexProvider::class . '#google');

        $this->assertSame(
            self::SLOT_NOT_FOUND,
            $this->resolveContractSlot($googleBody, 'system'),
            'a well-formed slot that is wrong for this body must answer NOT-FOUND — `system` is '
            . 'the ANTHROPIC arm\'s slot and has no meaning in the Google instances envelope',
        );
        $this->assertSame(
            self::SLOT_NOT_FOUND,
            $this->resolveContractSlot($googleBody, 'instances[1].context'),
            'an index past the end must answer NOT-FOUND, not the element at 0',
        );
        $this->assertSame(
            self::SLOT_NOT_FOUND,
            $this->resolveContractSlot($googleBody, 'instances[0].contxt'),
            'a one-character typo in a slot spelling must answer NOT-FOUND',
        );
        $this->assertSame(
            self::SLOT_NOT_FOUND,
            $this->resolveContractSlot($googleBody, 'TOTAL-NONSENSE-NOT-A-FIELD'),
            'a malformed slot must answer NOT-FOUND — this is the exact garbage value that used '
            . 'to leave the whole file green',
        );

        // And the resolver is not merely negative: the same body, walked with
        // the RIGHT spelling, still finds the sentinel. A dead resolver would
        // answer NOT-FOUND to everything and pass all four controls above.
        $this->assertSame(
            self::SENTINEL,
            $this->resolveContractSlot($googleBody, 'instances[0].context'),
        );
    }

    /**
     * The declared slot for one contract row, failing LEGIBLY if the row is
     * gone.
     *
     * Rule 25: a guard's failure message is the one part of a green suite that
     * never runs. Without this, a deleted row reached
     * {@see resolveContractSlot()} as `null` and the per-provider test died on
     * a TypeError about argument #2 — a red, but one that names the helper
     * rather than the missing row.
     */
    private function contractSlotFor(string $contractKey): string
    {
        $this->assertArrayHasKey(
            $contractKey,
            self::TRANSMISSION_CONTRACT,
            'TRANSMISSION_CONTRACT has no row for ' . $contractKey . '; a provider whose wire '
            . 'slot is no longer declared is a provider whose transmission nothing pins.',
        );

        return self::TRANSMISSION_CONTRACT[$contractKey];
    }

    /**
     * Resolve a TRANSMISSION_CONTRACT slot spelling against a captured request
     * body, or answer {@see SLOT_NOT_FOUND}.
     *
     * A GENERAL PATH WALKER, DELIBERATELY NOT A `match` ON THE FOUR LITERAL
     * SPELLINGS IN THE MAP — a `match` on the literals would be the same
     * tautology in a new costume (rule 21): every arm would be written FROM
     * the map, so no mutation of the map could ever disagree with it. This
     * walks `.`-separated segments, each an identifier optionally followed by
     * one or more `[<int>]` indices, and reports anything it cannot parse or
     * cannot follow rather than guessing (rule 32: a guard must never silently
     * pass what it cannot read).
     *
     * @param array<string, mixed> $body
     */
    private function resolveContractSlot(array $body, string $slot): mixed
    {
        $cursor = $body;

        foreach (explode('.', $slot) as $segment) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)((?:\[\d+\])*)$/', $segment, $m) !== 1) {
                return self::SLOT_NOT_FOUND;
            }

            $keys = [$m[1]];
            if ($m[2] !== '') {
                preg_match_all('/\[(\d+)\]/', $m[2], $indices);
                foreach ($indices[1] as $index) {
                    $keys[] = (int) $index;
                }
            }

            foreach ($keys as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    return self::SLOT_NOT_FOUND;
                }
                $cursor = $cursor[$key];
            }
        }

        return $cursor;
    }

    /**
     * Drive one TRANSMISSION_CONTRACT row's provider with the shared
     * {@see request()} and return the request body it actually built.
     *
     * The `match` is on the CONTRACT KEY, not on the slot spelling: each
     * provider needs its own harness (a Guzzle history, an SDK mock, an AWS
     * MockHandler, an injected predictor), so the drives cannot be derived.
     * Both directions of the map/drive correspondence are asserted in
     * {@see testTheContractSlotSpellingsAreLoadBearing()}, so this list cannot
     * silently omit a row the way a hand-maintained `@dataProvider` can
     * (rule 15).
     *
     * @return array<string, mixed>
     */
    private function capturedBodyFor(string $contractKey): array
    {
        switch ($contractKey) {
            case SglangProvider::class:
                $this->sglangProvider()->complete($this->request());

                return $this->sentBody();

            case CustomProvider::class . '#complete':
                $this->customProvider()->complete($this->request());

                return $this->sentBody();

            case CustomProvider::class . '#stream':
                // The SSE body is what makes the streamed builder reachable:
                // completeStream() parses the response before returning, so
                // the unary fixture would throw before the request body could
                // be read back off the history middleware.
                iterator_to_array($this->customProvider(self::SSE_BODY)->completeStream($this->request()));

                return $this->sentBody();

            case OpenAIProvider::class . '#complete':
                $captured = [];
                $this->openAiProviderWithCapture($captured)->complete($this->request());

                return $captured;

            case OpenAIProvider::class . '#stream':
                $streamCaptured = [];
                iterator_to_array(
                    $this->openAiProviderWithCapture($streamCaptured)->completeStream($this->request()),
                );

                return $streamCaptured;

            case BedrockProvider::class . '#complete':
                $mock = new MockHandler();
                $mock->append(new Result([
                    'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
                ]));
                $provider = new BedrockProvider(
                    $this->offlineRuntimeClient($mock),
                    'us-east-1',
                    'anthropic.claude-sonnet-4-6',
                );
                $provider->complete($this->request());

                return $mock->getLastCommand()->toArray();

            case BedrockProvider::class . '#stream':
                // ConverseStream answers an event stream; an empty one is
                // enough, because the assertion is about the REQUEST the
                // command carries, not the response.
                $streamMock = new MockHandler();
                $streamMock->append(new Result(['stream' => new \ArrayIterator([])]));
                $streamProvider = new BedrockProvider(
                    $this->offlineRuntimeClient($streamMock),
                    'us-east-1',
                    'anthropic.claude-sonnet-4-6',
                );
                iterator_to_array($streamProvider->completeStream($this->request()));

                return $streamMock->getLastCommand()->toArray();

            case VertexProvider::class . '#anthropic':
                $vertexCaptured = null;
                $this->vertexProvider([], $vertexCaptured)
                    ->complete($this->request(model: 'claude-3-sonnet@20240229'));

                return $vertexCaptured['body'];

            case VertexProvider::class . '#google':
                $googleCaptured = null;
                $this->vertexProvider([], $googleCaptured)
                    ->complete($this->request(model: self::VERTEX_GOOGLE_MODEL));

                return $googleCaptured['body'];
        }

        // Rule 32: an unreadable row is reported, never quietly passed.
        $this->fail('no transmission drive is wired for contract row ' . $contractKey);
    }

    // =========================================================================
    // Sglang — leading `messages[0]` system message, OpenAI chat/completions
    // order (SglangProvider.php:672-677). Both paths share buildParams().
    // =========================================================================

    public function testSglangTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        // Driven through a mocked Guzzle client the way
        // SglangProviderRequestBuildingTest drives it (history middleware,
        // then the decoded wire body). Both paths are pinned independently:
        // "complete() passes" was exactly the conflation that hid the same
        // bug in OpenAIProvider::completeStream().
        $provider = $this->sglangProvider();
        $provider->complete($this->request());

        $sent = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $sent['messages'][0]);
        // Prepended, not replacing: the original message still follows, untouched.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
        // The sentinel rides the protocol's system slot and nowhere else.
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamProvider = $this->sglangProvider(self::SSE_BODY);
        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $streamed['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($streamed['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testSglangNullSystemPromptTransmitsNothing(): void
    {
        // The guard lives in the shared buildParams(), so one path pins both.
        // '' is "unset" for this provider (SglangProvider.php:672), matching
        // the optional-knob filter below it.
        $provider = $this->sglangProvider();
        $provider->complete($this->request(null));

        // Exact shape: no system element may appear, empty or otherwise.
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }

    // =========================================================================
    // Custom — leading `messages[0]` system message, same OpenAI shape
    // (CustomProvider.php:155-160 / :210-215). Both paths share the guard.
    // =========================================================================

    public function testCustomTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        // Same harness family as CustomProviderTest (a MockHandler's wire
        // body); history middleware is used here so one sentBody() helper
        // serves both this provider and Sglang.
        $provider = $this->customProvider();
        $provider->complete($this->request());

        $sent = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $sent['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamProvider = $this->customProvider(self::SSE_BODY);
        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $this->sentBody();
        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $streamed['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($streamed['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testCustomNullSystemPromptTransmitsNothing(): void
    {
        // The guard lives in the shared message-prepend block, so one path
        // pins both. '' is "unset" for this provider (CustomProvider.php:155).
        $provider = $this->customProvider();
        $provider->complete($this->request(null));

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $this->sentBody()['messages']);
    }

    // =========================================================================
    // OpenAI — leading `messages[0]` system message
    // (OpenAIProvider.php:90-95 / :127-130). Driven through captured
    // create()/createStreamed() params, the way OpenAIProviderTest drives it.
    // =========================================================================

    public function testOpenAiTransmitsSystemPromptAsTheLeadingSystemMessageOnBothPaths(): void
    {
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request());

        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $captured['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($captured['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($captured), self::SENTINEL));

        iterator_to_array($provider->completeStream($this->request()));

        $this->assertSame(['role' => 'system', 'content' => self::SENTINEL], $captured['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($captured['messages'], 1));
        $this->assertSame(1, substr_count((string) json_encode($captured), self::SENTINEL));
    }

    public function testOpenAiNullSystemPromptTransmitsNothing(): void
    {
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request(null));

        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $captured['messages']);
    }

    public function testOpenAiEmptyStringSystemPromptIsTransmittedBecauseTheGuardIsNotNullOnly(): void
    {
        // Measured guard difference: OpenAIProvider's guard is `!== null` only
        // (OpenAIProvider.php:90, :127), so '' — which Sglang/Custom/Vertex
        // treat as "unset" — IS transmitted here. Pin the measured behaviour
        // so the guards cannot silently drift together.
        $captured = [];
        $provider = $this->openAiProviderWithCapture($captured);

        $provider->complete($this->request(''));

        $this->assertSame(['role' => 'system', 'content' => ''], $captured['messages'][0]);
    }

    // =========================================================================
    // Bedrock — Converse top-level `system` block list, never inside messages
    // (BedrockProvider.php:164-166 / :215-217 via systemBlocks() :337-343).
    // =========================================================================

    public function testBedrockTransmitsSystemPromptInTheConverseSystemBlockListOnBothPaths(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
        ]));
        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $provider->complete($this->request());

        $sent = $mock->getLastCommand()->toArray();
        $this->assertSame([['text' => self::SENTINEL]], $sent['system']);
        // Converse has no per-message `system` role: system text lives ONLY in
        // the top-level block list, so messages must carry no system entry at
        // all — and no smuggled sentinel either.
        $this->assertSame([['role' => 'user', 'content' => [['text' => 'Hi']]]], $sent['messages']);
        $this->assertSame(1, substr_count((string) json_encode($sent), self::SENTINEL));

        $streamMock = new MockHandler();
        $streamMock->append(new Result(['stream' => new \ArrayIterator([])]));
        $streamProvider = new BedrockProvider($this->offlineRuntimeClient($streamMock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        iterator_to_array($streamProvider->completeStream($this->request()));

        $streamed = $streamMock->getLastCommand()->toArray();
        $this->assertSame([['text' => self::SENTINEL]], $streamed['system']);
        $this->assertSame([['role' => 'user', 'content' => [['text' => 'Hi']]]], $streamed['messages']);
        $this->assertSame(1, substr_count((string) json_encode($streamed), self::SENTINEL));
    }

    public function testBedrockNullSystemPromptTransmitsNothing(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
        ]));
        $provider = new BedrockProvider($this->offlineRuntimeClient($mock), 'us-east-1', 'anthropic.claude-sonnet-4-6');

        $provider->complete($this->request(null));

        // systemBlocks() returns [] when the prompt is null, and complete()
        // only sets the key for a non-empty list (BedrockProvider.php:164-166).
        $this->assertArrayNotHasKey('system', $mock->getLastCommand()->toArray());
    }

    public function testBedrockEmptyStringSystemPromptIsTransmittedBecauseTheGuardIsNotNullOnly(): void
    {
        // Measured guard difference: systemBlocks() checks `!== null` only
        // (BedrockProvider.php:341), so '' — which Sglang/Custom/Vertex treat
        // as "unset" — IS shaped into a system block. The wire itself cannot
        // carry it: the AWS SDK's Converse validator rejects a zero-length
        // text block before any I/O (measured 2026-08-26: "expected string
        // length to be >= 1"), so the honest pin is the shaper's output, not
        // the serialized wire. Reflect systemBlocks() the way
        // BedrockProviderTest reflects private methods.
        $provider = new BedrockProvider(
            $this->offlineRuntimeClient(new MockHandler()),
            'us-east-1',
            'anthropic.claude-sonnet-4-6',
        );

        $method = new \ReflectionMethod(BedrockProvider::class, 'systemBlocks');
        $method->setAccessible(true);

        $this->assertSame(
            [['text' => '']],
            $method->invoke($provider, $this->request('')),
        );
    }

    // =========================================================================
    // Vertex — TWO body builders, selected by isAnthropicModel()
    // (VertexProvider.php:231). The Anthropic arm hoists into the body's
    // top-level `system` string (VertexProvider.php:455-458): a `system` role
    // inside messages is a 400 on the Anthropic API. The Google arm hoists
    // into `instances[0].context` (VertexProvider.php:1137-1139): that
    // envelope has no system role at all, and formatMessages()'s
    // `default => 'user'` arm would otherwise deliver the prompt as an
    // ordinary user turn. Both arms go through the one joiner,
    // systemInstruction() :508. Driving only the Anthropic arm is how the
    // Google arm's silence survived the whole of Phase 1.
    // =========================================================================

    public function testVertexTransmitsSystemPromptInTheAnthropicTopLevelSystemFieldOnBothPaths(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(model: 'claude-3-sonnet@20240229'));

        $this->assertSame(self::SENTINEL, $captured['body']['system']);
        // ALSO through the declared contract slot, so a wrong spelling in
        // TRANSMISSION_CONTRACT reds a real per-provider test and not only the
        // contract test. Kept ALONGSIDE the direct assertion above, never
        // instead of it: the direct one pins the value, this one pins that the
        // map still names where the value lives.
        $this->assertSame(
            self::SENTINEL,
            $this->resolveContractSlot(
                $captured['body'],
                $this->contractSlotFor(VertexProvider::class . '#anthropic'),
            ),
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $captured['body']['messages'],
            'a `system` role inside messages is a 400 on the Anthropic API',
        );
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), self::SENTINEL));

        $streamed = null;
        $streamProvider = $this->vertexStreamer([], $streamed);
        iterator_to_array($streamProvider->completeStream($this->request(model: 'claude-3-sonnet@20240229')));

        $this->assertSame(self::SENTINEL, $streamed['body']['system']);
        $this->assertSame(
            self::SENTINEL,
            $this->resolveContractSlot(
                $streamed['body'],
                $this->contractSlotFor(VertexProvider::class . '#anthropic'),
            ),
        );
        $this->assertSame(
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
            $streamed['body']['messages'],
        );
        $this->assertSame(1, substr_count((string) json_encode($streamed['body']), self::SENTINEL));
    }

    public function testVertexNullSystemPromptTransmitsNothing(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(null, 'claude-3-sonnet@20240229'));

        // systemInstruction() returns null when no part exists, and anthropicBody()
        // only sets the key for a non-null value (VertexProvider.php:455-458).
        $this->assertArrayNotHasKey('system', $captured['body']);
    }

    public function testVertexTransmitsSystemPromptInTheGoogleInstanceContextOnBothPaths(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(model: self::VERTEX_GOOGLE_MODEL));

        $this->assertSame('predict', $captured['method']);
        $this->assertSame(self::SENTINEL, $captured['body']['instances'][0]['context']);
        // ALSO through the declared contract slot — see the note on the
        // Anthropic arm above; both assertions are kept.
        $this->assertSame(
            self::SENTINEL,
            $this->resolveContractSlot(
                $captured['body'],
                $this->contractSlotFor(VertexProvider::class . '#google'),
            ),
        );
        $this->assertSame(
            [['role' => 'user', 'content' => 'Hi']],
            $captured['body']['instances'][0]['messages'],
            // NARROWED, because the wider claim this message used to make was
            // one this assertion cannot support: it read "a prompt left in
            // `messages` reaches the model as an ordinary user turn", but
            // request() hands every provider in this file the SAME
            // CompleteRequest — one UserMessage and no SystemMessage
            // (see request(), and the class doc-block's identical-request
            // invariant) — so no hoist-dedup failure is observable here.
            // MEASURED: reverting only the withoutSystemMessages() call in
            // VertexProvider::googleBody() leaves this whole file green.
            // What this assertion DOES pin is that the `systemPrompt` hoist
            // did not also leave a copy in `messages`, and that the one user
            // turn survives it unaltered. The hoist-dedup for a history
            // SystemMessage is pinned in
            // VertexProviderTest::testTheGooglePromptRidesContextAndNowhereElseInTheBody
            // and
            // VertexProviderTest::testGoogleInstanceContextJoinsEveryHistorySystemMessageInMessageOrder.
            'the systemPrompt must be hoisted into `context` and NOT also left in `messages`; '
            . 'the one user turn must survive unaltered',
        );
        $this->assertSame(1, substr_count((string) json_encode($captured['body']), self::SENTINEL));

        // NOT the streamer seam: completeStream() yields complete() for a
        // non-Anthropic model (VertexProvider.php:290-297), so the streaming
        // path is captured on the PREDICTOR. Asserting it separately is still
        // the point — complete() passing is not evidence about
        // completeStream(), which is exactly how the OpenAI arm hid this same
        // defect until P1.S3.
        $streamed = null;
        $streamProvider = $this->vertexProvider([], $streamed);
        iterator_to_array(
            $streamProvider->completeStream($this->request(model: self::VERTEX_GOOGLE_MODEL)),
            false,
        );

        $this->assertSame('predict', $streamed['method']);
        $this->assertSame(self::SENTINEL, $streamed['body']['instances'][0]['context']);
        $this->assertSame(
            self::SENTINEL,
            $this->resolveContractSlot(
                $streamed['body'],
                $this->contractSlotFor(VertexProvider::class . '#google'),
            ),
        );
        $this->assertSame(1, substr_count((string) json_encode($streamed['body']), self::SENTINEL));
    }

    public function testVertexGoogleArmNullSystemPromptTransmitsNothing(): void
    {
        $captured = null;
        $provider = $this->vertexProvider([], $captured);
        $provider->complete($this->request(null, self::VERTEX_GOOGLE_MODEL));

        $this->assertArrayNotHasKey('context', $captured['body']['instances'][0]);
    }

    // =========================================================================
    // ClaudeCode — `--system-prompt` CLI argv pair
    // (ClaudeCodeProvider.php:80 / :105 -> ClaudeCodeInvocation.php:75-78).
    // The provider hands its invocation exactly the options below; the wire
    // shaper is printModeArgs(), driven the way ClaudeCodeProviderTest drives
    // it — the execute() step that would follow is proc_open and cannot run
    // in a unit test.
    // =========================================================================

    public function testClaudeCodeTransmitsSystemPromptAsASystemPromptArgvPairOnBothPaths(): void
    {
        $invocation = new ClaudeCodeInvocation();

        $completeArgs = $invocation->printModeArgs('Hi', [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => self::SENTINEL,
        ]);
        $completeFlag = array_search('--system-prompt', $completeArgs, true);
        $this->assertNotFalse($completeFlag, 'complete() must pass --system-prompt');
        $this->assertSame(self::SENTINEL, $completeArgs[$completeFlag + 1]);
        $this->assertSame(1, substr_count(implode(' ', $completeArgs), self::SENTINEL));

        $streamArgs = $invocation->printModeArgs('Hi', [
            'format' => 'stream-json',
            'bare' => true,
            'systemPrompt' => self::SENTINEL,
        ]);
        $streamFlag = array_search('--system-prompt', $streamArgs, true);
        $this->assertNotFalse($streamFlag, 'completeStream() must pass --system-prompt');
        $this->assertSame(self::SENTINEL, $streamArgs[$streamFlag + 1]);
        $this->assertSame(1, substr_count(implode(' ', $streamArgs), self::SENTINEL));
    }

    public function testClaudeCodeNullSystemPromptTransmitsNoFlag(): void
    {
        // The provider always passes 'systemPrompt' => $request->systemPrompt
        // (ClaudeCodeProvider.php:80); printModeArgs() gates the pair on
        // isset() (ClaudeCodeInvocation.php:75-78), so null -> no flag at all.
        // (Note: '' would still emit the pair with an empty value — the same
        // `!== null`-only polarity as OpenAI/Bedrock, by a different idiom.)
        $invocation = new ClaudeCodeInvocation();

        $args = $invocation->printModeArgs('Hi', [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => null,
        ]);

        $this->assertNotContains('--system-prompt', $args);
    }

    // =========================================================================
    // Harness — each provider driven exactly as its own suite drives it.
    // =========================================================================

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * Same mock harness as SglangProviderRequestBuildingTest::provider().
     */
    private function sglangProvider(string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new GuzzleMockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            'gpt-4',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /**
     * Same mock family as CustomProviderTest (a MockHandler's wire body),
     * routed through history middleware so one sentBody() serves both it and
     * Sglang.
     */
    private function customProvider(string $body = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'): CustomProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new GuzzleMockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new CustomProvider(
            'custom',
            'https://api.example.com',
            'gpt-4',
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
            true,
            true,
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    /**
     * Same mock chain as OpenAIProviderTest::testCompleteStreamPayloadLeadsWithSystemPrompt:
     * the chat mock records the params array it was handed, on both the
     * create() and the createStreamed() wire methods.
     *
     * $captured is taken by reference (the same idiom as
     * {@see vertexProvider()}'s $captured) so the closures' writes are
     * visible to the caller after this helper returns.
     *
     * @param array<string, mixed> $captured
     */
    private function openAiProviderWithCapture(array &$captured): OpenAIProvider
    {
        $captured = [];
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);

        $chat->method('create')->willReturnCallback(function (array $params) use (&$captured): ChatCreateResponse {
            $captured = $params;

            return ChatCreateResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion',
                'created' => 1,
                'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], MetaInformation::from([]));
        });

        $chat->method('createStreamed')->willReturnCallback(function (array $params) use (&$captured): StreamResponse {
            $captured = $params;

            return new StreamResponse(
                CreateStreamedResponse::class,
                new Response(200, [], Utils::streamFor(self::SSE_BODY)),
            );
        });

        return new OpenAIProvider($client, 'gpt-4o');
    }

    /**
     * Same offline client as BedrockProviderTest::offlineRuntimeClient().
     */
    private function offlineRuntimeClient(MockHandler $handler): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
        ]);
    }

    /**
     * Same unary-seam provider as VertexProviderTest::providerWithPredictor().
     *
     * @param array<string, mixed> $return
     * @param-out array<string, mixed> $captured
     */
    private function vertexProvider(array $return = [], ?array &$captured = null): VertexProvider
    {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: function (string $endpoint, string $method, array $body) use ($return, &$captured): array {
                $captured = [
                    'called' => true,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'body' => $body,
                ];

                return $return;
            },
        );
    }

    /**
     * Same streaming-seam provider as VertexProviderTest::providerWithStreamer().
     *
     * @param array<int, array<string, mixed>> $events
     * @param-out array<string, mixed> $captured
     */
    private function vertexStreamer(array $events, ?array &$captured = null): VertexProvider
    {
        $captured = ['called' => false];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: 'claude-3-sonnet@20240229',
            predictor: fn (): array => [],
            streamer: function (string $endpoint, string $method, array $body) use ($events, &$captured): \Generator {
                $captured = [
                    'called' => true,
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'body' => $body,
                ];

                yield from $events;
            },
        );
    }

    /**
     * The identical CompleteRequest every covered provider is handed: one
     * user turn plus the sentinel on $systemPrompt by default.
     */
    private function request(?string $systemPrompt = self::SENTINEL, string $model = 'gpt-4'): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: [new UserMessage('Hi')],
            systemPrompt: $systemPrompt,
        );
    }
}