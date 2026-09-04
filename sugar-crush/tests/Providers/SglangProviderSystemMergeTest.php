<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * Q5 (qwen.md §Q5) — the provider must NEVER emit more than one
 * `{role: system}` row, at any index other than 0.
 *
 * WHY this file exists (evidence chain, qwen.md Part III):
 *  - E-10: the deployed Qwen chat template raises HTTP 400
 *    "System message must be at the beginning." whenever a system row sits at
 *    index > 0 (or any system appears behind a non-system), and it renders
 *    ONLY messages[0]'s system content — every later system row is either a
 *    hard 400 or silently dropped. Either way the instruction is lost.
 *  - E-13: sugar-crush's Chat.php appends system rows mid-history constantly
 *    (launch notices :5747-5766, the 70% context reminder :6422, the
 *    cancellation marker :1504, queued-prompt notices :6087, the
 *    automatic-compaction-tier notices :9005/:12237, and even a literal
 *    `Message::system('')` at :8992). A session that survives long enough to
 *    grow any one of these behind the prepended prompt hits E-10.
 *  - E-12: opencode is protected by an out-of-band merge proxy; sugar-crush's
 *    baseUrl points at the server directly, so the provider itself must merge.
 *  - E-11: the precedent is in-repo — BedrockProvider hoists history system
 *    rows into its request-level `system` block list (systemBlocks(), prompt
 *    first then message order) and VertexProvider joins them into ONE
 *    instruction (systemInstruction(), same order, same "\n\n" joiner, same
 *    empty-drop rule). This class now does the OpenAI-shaped equivalent:
 *    exactly one leading system row assembled the same way.
 *
 * The E-14 pins (SglangProviderTest.php formatMessages legs + the Sglang rows
 * of SystemPromptTransmissionMatrixTest.php) live in their own files and must
 * survive this behaviour change UNTOUCHED — none of them is exercised here.
 */
final class SglangProviderSystemMergeTest extends TestCase
{
    private const MODEL = 'MiniMax-M2.7';

    /** Batch response body small enough to keep the harness cheap; the
     *  assertions here are all on the REQUEST the provider emits. */
    private const OK_BODY = '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}';

    /** Stream body in the same shape MatrixTest's SSE constant uses. */
    private const SSE_BODY = "data: {\"id\":\"chatcmpl-1\",\"object\":\"chat.completion.chunk\",\"created\":1,\"model\":\"m\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\ndata: [DONE]\n\n";

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private function provider(string $body = self::OK_BODY): SglangProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://api.example.com',
            self::MODEL,
            null,
            new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]),
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    /** @param array<int, object> $messages */
    private function request(?string $systemPrompt, array $messages): CompleteRequest
    {
        return new CompleteRequest(
            model: self::MODEL,
            messages: $messages,
            systemPrompt: $systemPrompt,
        );
    }

    /**
     * The invariant every multi-system case leans on: exactly one system row
     * in the emitted wire body, and it is the first one.
     *
     * @param array<string, mixed> $sent
     */
    private function assertSingleLeadingSystem(array $sent): array
    {
        $systemRows = array_values(array_filter(
            $sent['messages'],
            static fn(array $row): bool => ($row['role'] ?? null) === 'system',
        ));
        $this->assertCount(1, $systemRows, 'provider emitted more than one system row (E-10 400 shape)');
        $this->assertSame('system', $sent['messages'][0]['role'], 'the single system row is not at index 0');
        $this->assertSame(
            1,
            substr_count((string) json_encode($sent['messages']), '"role":"system"'),
            'encoded messages carry more than one system role marker',
        );

        return $systemRows[0];
    }

    // -------------------------------------------------------------------------
    // 1. Prompt-only inputs keep today's exact shape (no history system rows).
    // -------------------------------------------------------------------------

    public function testPromptOnlyRequestEmitsTheSameLeadingSystemRowAsBefore(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request('You are SugarCrush.', [new UserMessage('Hi')]));

        $sent = $this->sentBody();
        $this->assertSingleLeadingSystem($sent);
        $this->assertSame(['role' => 'system', 'content' => 'You are SugarCrush.'], $sent['messages'][0]);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], array_slice($sent['messages'], 1));
    }

    // -------------------------------------------------------------------------
    // 2. The E-13 headline scenario: prompt + launch notice + context
    //    reminder. Three system sources, one row, prompt FIRST, then history
    //    order (Chat.php :5747-5766 / :6422 shapes).
    // -------------------------------------------------------------------------

    public function testPromptWithLaunchNoticeAndContextReminderMergesToOneSystemFirst(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request(
            'You are SugarCrush.',
            [
                new SystemMessage('Launch notice: MCP server offline.'),
                new UserMessage('fix the bug'),
                new AssistantMessage('On it.'),
                new SystemMessage('Context is 70% full; compaction tiers approach.'),
                new UserMessage('thanks'),
            ],
        ));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame(
            "You are SugarCrush.\n\n"
            . "Launch notice: MCP server offline.\n\n"
            . 'Context is 70% full; compaction tiers approach.',
            $system['content'],
        );
        $this->assertSame([
            ['role' => 'user', 'content' => 'fix the bug'],
            ['role' => 'assistant', 'content' => 'On it.'],
            ['role' => 'user', 'content' => 'thanks'],
        ], array_slice($sent['messages'], 1));
    }

    public function testTheMergeIsProviderWideAndReachesTheStreamPathThroughTheSameSeam(): void
    {
        // complete() (:599) and completeStream() (:616) both build their body
        // through buildParams() (:796), and buildParams() is the only caller
        // that passes the prompt into formatMessages() — one seam, both paths.
        $provider = $this->provider(self::SSE_BODY);
        iterator_to_array($provider->completeStream($this->request(
            'You are SugarCrush.',
            [
                new UserMessage('go'),
                new SystemMessage('_Request cancelled._'),
            ],
        )));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("You are SugarCrush.\n\n_Request cancelled._", $system['content']);
    }

    // -------------------------------------------------------------------------
    // 3. Cancellation marker (Chat.php :1504 verbatim): lands behind a full
    //    assistant turn in history — exactly the index>0 shape E-10 rejects.
    // -------------------------------------------------------------------------

    public function testCancellationMarkerMergesIntoTheLeadingSystemRow(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request(
            'You are SugarCrush.',
            [
                new UserMessage('long task please'),
                new AssistantMessage('working on…'),
                new SystemMessage('_Request cancelled._'),
                new UserMessage('try again'),
            ],
        ));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("You are SugarCrush.\n\n_Request cancelled._", $system['content']);
        $this->assertSame([
            ['role' => 'user', 'content' => 'long task please'],
            ['role' => 'assistant', 'content' => 'working on…'],
            ['role' => 'user', 'content' => 'try again'],
        ], array_slice($sent['messages'], 1));
    }

    // -------------------------------------------------------------------------
    // 4. Queued-prompt notice (:6087 shape) + automatic-compaction-tier
    //    notice (:9005 shape) stacked on the same request.
    // -------------------------------------------------------------------------

    public function testQueuedPromptAndCompactionTierNoticesMergeBehindThePrompt(): void
    {
        $queued = sprintf('Queued (2 waiting) — sent as soon as this turn finishes: %s', 'deploy it');
        $tier = sprintf(
            'Context reached the automatic-compaction tier at ~%d estimated tokens of a %d-token context window.',
            520_000,
            744_506,
        );

        $provider = $this->provider();
        $provider->complete($this->request(
            'You are SugarCrush.',
            [
                new SystemMessage($queued),
                new UserMessage('deploy it'),
                new SystemMessage($tier),
            ],
        ));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("You are SugarCrush.\n\n{$queued}\n\n{$tier}", $system['content']);
    }

    // -------------------------------------------------------------------------
    // 5. Title one-shot (Chat.php :7490 `[Message::system(TITLE_PROMPT),
    //    ...history]`): a system row with NO request-level prompt — the
    //    zero-prompt path must still emit exactly one system row.
    // -------------------------------------------------------------------------

    public function testHistorySystemWithoutPromptPassesThroughAsTheSingleSystem(): void
    {
        $titlePrompt = 'Name this session in 5 words or fewer.';
        $provider = $this->provider();
        $provider->complete($this->request(null, [
            new SystemMessage($titlePrompt),
            new UserMessage('first real user turn'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame($titlePrompt, $system['content']);
    }

    public function testHistorySystemBehindNonSystemRowsWithoutPromptIsStillHoisted(): void
    {
        // The pure zero-prompt multi-system case: today this emits TWO system
        // rows (index 0 and index >0). Post-Q5 it is one joined row.
        $provider = $this->provider();
        $provider->complete($this->request(null, [
            new SystemMessage('first instruction'),
            new UserMessage('hi'),
            new SystemMessage('_Request cancelled._'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("first instruction\n\n_Request cancelled._", $system['content']);
    }

    // -------------------------------------------------------------------------
    // 6. Order: prompt first, then every history system row in message order,
    //    no matter how many non-system rows interleave.
    // -------------------------------------------------------------------------

    public function testMergeOrderIsPromptFirstThenHistoryOrder(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request('PROMPT', [
            new UserMessage('u1'),
            new SystemMessage('S1'),
            new AssistantMessage('a1'),
            new SystemMessage('S2'),
            new UserMessage('u2'),
            new SystemMessage('S3'),
            new AssistantMessage('a2'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("PROMPT\n\nS1\n\nS2\n\nS3", $system['content']);
        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_column(array_slice($sent['messages'], 1), 'role'),
        );
    }

    // -------------------------------------------------------------------------
    // 7. Empty pieces are dropped, never joined as blank slots.
    // -------------------------------------------------------------------------

    public function testEmptySystemRowIsDropped(): void
    {
        // Chat.php :8992 literally appends Message::system('') to history.
        $provider = $this->provider();
        $provider->complete($this->request('PROMPT', [
            new SystemMessage(''),
            new UserMessage('hi'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame('PROMPT', $system['content']);
    }

    public function testEmptyPromptWithHistorySystemsYieldsHistoryOnly(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request('', [
            new UserMessage('hi'),
            new SystemMessage('A'),
            new SystemMessage('B'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame("A\n\nB", $system['content']);
    }

    public function testEverythingEmptyEmitsNoSystemRow(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request('', [
            new SystemMessage(''),
            new UserMessage('hi'),
        ]));

        $sent = $this->sentBody();
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);
    }

    // -------------------------------------------------------------------------
    // 8. Zero-system passthrough: no prompt, no system rows → no system key,
    //    messages byte-equal to the non-system formatting.
    // -------------------------------------------------------------------------

    public function testNoSystemAnywhereKeepsTheBodySystemFree(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request(null, [
            new UserMessage('Hi'),
            new AssistantMessage('Hello'),
        ]));

        $sent = $this->sentBody();
        $this->assertStringNotContainsString('"role":"system"', (string) json_encode($sent['messages']));
        $this->assertSame([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello'],
        ], $sent['messages']);
    }

    // -------------------------------------------------------------------------
    // 9. The joiner is exactly "\n\n" — no trailing, no doubled, none for a
    //    single part.
    // -------------------------------------------------------------------------

    public function testJoinerIsExactlyTwoNewlines(): void
    {
        $provider = $this->provider();
        $provider->complete($this->request('P', [
            new SystemMessage('A'),
            new SystemMessage('B'),
            new UserMessage('u'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame('P' . "\n\n" . 'A' . "\n\n" . 'B', $system['content']);
        $this->assertStringStartsNotWith("\n", $system['content']);
        $this->assertStringEndsNotWith("\n", $system['content']);
        $this->assertStringNotContainsString("\n\n\n", $system['content']);
    }

    // -------------------------------------------------------------------------
    // 10. Image parts cannot ride a system row (SystemMessage::content() is
    //    typed `string`; the emitted row keeps exactly the two OpenAI keys).
    // -------------------------------------------------------------------------

    public function testMergedSystemRowIsPlainTextWithExactlyRoleAndContentKeys(): void
    {
        $provider = $this->provider();
        $message = new SystemMessage('plain instruction');
        $this->assertIsString($message->content());

        $provider->complete($this->request('PROMPT', [
            $message,
            new UserMessage('hi'),
        ]));

        $sent = $this->sentBody();
        $system = $this->assertSingleLeadingSystem($sent);
        $this->assertSame(['role', 'content'], array_keys($system));
        $this->assertIsString($system['content']);
    }
}
