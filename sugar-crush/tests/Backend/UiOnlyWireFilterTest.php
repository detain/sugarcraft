<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CustomProvider;

/**
 * Audit 15b-03, defence in depth: Chat filters UI-only rows before it
 * dispatches, and each backend filters them AGAIN where it turns history into
 * a request, so a caller that hands a backend a raw transcript - an embedder,
 * a future dispatch site - still cannot put `/help`'s output on the wire.
 *
 * EngineBackend is asserted on the serialized HTTP body (a real
 * {@see CustomProvider} over a Guzzle MockHandler, driven through the
 * synchronous complete(), which does not fork); the command backends on the
 * bytes their child read from stdin.
 */
final class UiOnlyWireFilterTest extends TestCase
{
    private string $capture = '';

    /**
     * TIME-BOMB RECORD: the whole-body "does not contain" scan used to search
     * the bare literal `/permissions` — but the wire embeds a live git-state
     * snapshot, and a merged commit subject gained that literal (0295fcb7d),
     * so the ambient repo state eventually accused the test. A per-process
     * uniqid sentinel cannot occur in ambient state, which keeps the whole-
     * body scan (scoping it down to the conversation rows alone would be the
     * weaker fix) and still accuses any leaked ui-only row, since the same
     * sentinel-bearing row is the one the EXPECTED pin proves stripped.
     */
    private static ?string $sentinel = null;

    private static function sentinel(): string
    {
        return self::$sentinel ??= 'uio-' . uniqid((string) getmypid(), true);
    }

    protected function setUp(): void
    {
        $this->capture = sys_get_temp_dir() . '/uionly_stdin_' . uniqid((string) getmypid(), true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->capture)) {
            unlink($this->capture);
        }
    }

    /** @return list<Message> */
    private static function mixedHistory(): array
    {
        return [
            Message::assistant('HELP-LISTING')->withUiOnly(),
            Message::user(self::sentinel() . ' /permissions')->withUiOnly(),
            Message::assistant('PERMISSIONS-REPORT')->withUiOnly(),
            Message::user('first question'),
            Message::notice('QUEUED-NOTICE'),
            Message::assistant('answer one'),
            Message::user('second question'),
        ];
    }

    private const EXPECTED = [
        ['role' => 'user', 'content' => 'first question'],
        ['role' => 'assistant', 'content' => 'answer one'],
        ['role' => 'user', 'content' => 'second question'],
    ];

    public function testEngineBackendSendsTheProviderOnlyAgentVisibleRows(): void
    {
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"choices":[{"message":{"content":"ok"}}],"usage":{"total_tokens":1}}'),
        ]));
        $stack->push(Middleware::history($requests));
        $client = new Client(['base_uri' => 'https://api.example.com/', 'handler' => $stack]);

        $backend = new EngineBackend(
            new CustomProvider('custom', 'https://api.example.com', 'gpt-4', null, $client, false, false),
            'gpt-4',
        );
        $backend->complete(self::mixedHistory());

        $this->assertCount(1, $requests);
        $body = (string) $requests[0]['request']->getBody();
        foreach (['HELP-LISTING', self::sentinel() . ' /permissions', 'PERMISSIONS-REPORT', 'QUEUED-NOTICE'] as $uiOnly) {
            $this->assertStringNotContainsString($uiOnly, $body);
        }
        $conversation = array_values(array_filter(
            json_decode($body, true, flags: JSON_THROW_ON_ERROR)['messages'],
            // Neither the system prompt nor the `<turn-context>` row (step
            // 1.A-1) is a history row this filter is about.
            static fn(array $m): bool => $m['role'] !== 'system'
                && !str_starts_with((string) $m['content'], '<turn-context>' . "\n"),
        ));
        $this->assertSame(
            self::EXPECTED,
            array_map(static fn(array $m): array => ['role' => $m['role'], 'content' => $m['content']], $conversation),
        );
    }

    public function testEncodeHistoryLeavesUiOnlyRowsOut(): void
    {
        $this->assertSame(
            self::EXPECTED,
            json_decode((string) CommandBackend::encodeHistory(self::mixedHistory()), true),
        );
    }

    public function testCommandBackendsChildReadsOnlyAgentVisibleRows(): void
    {
        (new CommandBackend($this->capturingCommand()))->complete(self::mixedHistory());

        $this->assertSame(self::EXPECTED, $this->captured());
    }

    public function testStreamingCommandBackendsChildReadsOnlyAgentVisibleRows(): void
    {
        (new StreamingCommandBackend($this->capturingCommand()))->complete(self::mixedHistory(), null);

        $this->assertSame(self::EXPECTED, $this->captured());
    }

    public function testACommandBackendsOwnErrorStringIsUiOnly(): void
    {
        $failed = (new CommandBackend(['sh', '-c', 'cat > /dev/null; exit 3']))->complete([Message::user('q')]);
        $this->assertStringContainsString('[error: backend exited 3]', $failed->content);
        $this->assertTrue($failed->uiOnly, 'the model never said it, so it must not be replayed as its words');

        $streamed = (new StreamingCommandBackend(['sh', '-c', 'cat > /dev/null; exit 3']))->complete([Message::user('q')], null);
        $this->assertStringContainsString('[error: streaming backend exited 3]', $streamed->content);
        $this->assertTrue($streamed->uiOnly);

        $answered = (new CommandBackend(['sh', '-c', 'cat > /dev/null; echo fine']))->complete([Message::user('q')]);
        $this->assertSame('fine', $answered->content);
        $this->assertFalse($answered->uiOnly, 'a real reply stays agent-visible');
    }

    /** A command that saves its stdin to $capture and replies `ok`. */
    private function capturingCommand(): array
    {
        return ['sh', '-c', 'cat > "$0"; echo ok', $this->capture];
    }

    /** @return mixed */
    private function captured(): mixed
    {
        $this->assertFileExists($this->capture);

        return json_decode((string) file_get_contents($this->capture), true, flags: JSON_THROW_ON_ERROR);
    }
}
