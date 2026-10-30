<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Usage;

/**
 * C-SEC-8.b: the user-tier `redactToolOutput` setting, end to end.
 *
 * When it is on, {@see SecretRedactor} runs over settled tool output at the
 * one choke inside {@see Runtime} that stands BEFORE the `ToolFinished`
 * emit — because measurement of the serve path (TranscriptProjector rows and
 * the EventLog `content` both fold from the event's result, not from the
 * folded provider message) proved that redacting only at
 * {@see Runtime}-internal message building would leave sessions.db raw.
 * Every assertion below therefore reads the surfaces a turn produces: the
 * captured event, the provider-visible history on the next request, and —
 * pinned directly in the serve-projection test — the transcript row and the
 * durable `tool.finished` payload that land in sessions.db.
 *
 * Off is the default and must be invisible: the same bytes, and — pinned
 * structurally — literally the same {@see ToolResult} object, so no path that
 * did not ask for redaction even pays for a `withContent()` rebuild.
 *
 * HOME is redirected to a sandbox for the whole class (same convention as
 * EngineBackendParallelConfigTest), so nothing here reads or writes the real
 * ~/.candy-code/config.json.
 */
final class ToolOutputRedactionTest extends TestCase
{
    /** ≥16 chars of the Bearer arm's class, so the pattern bites. */
    public const TOKEN = 'supersecretvalue0123456789abcdef';

    private string $dir;

    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_tool_redaction_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/home', 0o700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->originalHome === '' ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    // =========================================================================
    // End-to-end through a real turn
    // =========================================================================

    public function testSettledToolOutputIsRawWhenTheSettingIsOff(): void
    {
        $provider = $this->leakyProvider();

        $events = [];
        EngineBackend::new($provider, 'm')
            ->withTools([$this->leakyTool()])
            ->withoutHooks()
            ->complete([Message::user('go')], null, static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $finished = $this->toolFinished($events);
        $this->assertStringContainsString(self::TOKEN, $finished->result->content());

        $history = $provider->requests[array_key_last($provider->requests)]->messages;
        $this->assertStringContainsString(self::TOKEN, $this->resultMessageContent($history));
    }

    public function testSettledToolOutputIsRedactedInBothEventAndHistoryWhenOn(): void
    {
        Bootstrap::writeUserConfig(['redactToolOutput' => true]);

        $provider = $this->leakyProvider();

        $events = [];
        EngineBackend::new($provider, 'm')
            ->withTools([$this->leakyTool()])
            ->withoutHooks()
            ->complete([Message::user('go')], null, static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        // The event is the persistence surface: serve transcript rows, the
        // EventLog content and the TUI engine rows all fold from it.
        $finished = $this->toolFinished($events);
        $this->assertStringNotContainsString(self::TOKEN, $finished->result->content());
        $this->assertStringContainsString(SecretRedactor::MARKER, $finished->result->content());
        // The scheme word survives (the redactor's own documented shape), so
        // the model can still tell the user WHERE the secret was.
        $this->assertStringContainsString('Bearer', $finished->result->content());

        // ...and the provider-visible history carries the same redacted bytes.
        $history = $provider->requests[array_key_last($provider->requests)]->messages;
        $this->assertStringNotContainsString(self::TOKEN, $this->resultMessageContent($history));
    }

    public function testImageDocumentAndUsagePayloadsRideThroughUntouched(): void
    {
        Bootstrap::writeUserConfig(['redactToolOutput' => true]);

        $image = "\x89PNG\r\n\x1a\n" . 'swatch-bytes';
        $usage = Usage::new(totalTokens: 1234, costUsd: 0.25);

        $events = [];
        EngineBackend::new($this->leakyProvider(), 'm')
            ->withTools([$this->leakyTool(payload: [
                'imageBytes' => $image,
                'imageProtocol' => 'kitty',
                'usage' => $usage,
                'documentBytes' => '%PDF-1.4 doc-bytes',
                'documentName' => 'report.pdf',
            ])])
            ->withoutHooks()
            ->complete([Message::user('go')], null, static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $result = $this->toolFinished($events)->result;

        $this->assertStringNotContainsString(self::TOKEN, $result->content());
        $this->assertSame($image, $result->imageBytes());
        $this->assertSame('kitty', $result->imageProtocol());
        $this->assertSame($usage, $result->usage());
        $this->assertSame('%PDF-1.4 doc-bytes', $result->documentBytes());
        $this->assertSame('report.pdf', $result->documentName());
    }

    public function testFailurePathIsRedactedBeforeItEmits(): void
    {
        $message = 'hook refused: header says Authorization: Bearer ' . self::TOKEN;

        $on = new Runtime($this->leakyProvider(), new HookManager(new HookRegistry()), redactToolOutput: true);
        $off = new Runtime($this->leakyProvider(), new HookManager(new HookRegistry()));

        $onEvent = null;
        $onFailure = new \ReflectionMethod(Runtime::class, 'failure');
        $onResult = $onFailure->invoke($on, new ToolCall('c1', 'Leak', []), $message, static function (object $e) use (&$onEvent): void {
            $onEvent = $e;
        });
        $offResult = $onFailure->invoke($off, new ToolCall('c1', 'Leak', []), $message, null);

        $this->assertInstanceOf(ToolResultMessage::class, $onResult);
        $this->assertStringNotContainsString(self::TOKEN, $onResult->content());
        $this->assertStringContainsString(SecretRedactor::MARKER, $onResult->content());

        // The emit that persists saw the redacted object too.
        $this->assertInstanceOf(ToolFinished::class, $onEvent);
        $this->assertStringNotContainsString(self::TOKEN, $onEvent->result->content());

        $this->assertStringContainsString(self::TOKEN, $offResult->content(), 'off keeps the historical bytes');
    }

    public function testTheDurableServeSurfacesFoldTheRedactedEventBytes(): void
    {
        // The measurement that put the choke upstream of the emit: what
        // persists is the EventLog `tool.finished` wire event and the
        // transcript row it projects, and TranscriptProjector folds BOTH
        // from the ToolFinished payload (finish() and toolFinishedEvent()
        // read $event->result), NOT from the provider-facing
        // ToolResultMessage. A redaction applied upstream of the emit must
        // therefore land on both durable surfaces; a redaction that lived
        // only inside resultMessage() would leave sessions.db raw. If
        // persistence ever moves to the folded message, this pin flips
        // and the choke's position is re-adjudicated.
        $armed = new Runtime($this->leakyProvider(), new HookManager(new HookRegistry()), redactToolOutput: true);

        $raw = new ToolResult(toolCallId: 'c1', content: 'Bearer ' . self::TOKEN);
        $event = ToolFinished::fromResult(
            new ToolCall('c1', 'Leak', []),
            (new \ReflectionMethod(Runtime::class, 'redactResult'))->invoke($armed, $raw),
        );

        $projector = TranscriptProjector::new();
        [, $row] = $projector->finish([], $event);
        $this->assertStringNotContainsString(self::TOKEN, $row->toolResults[0]->result);
        $this->assertStringContainsString(SecretRedactor::MARKER, $row->toolResults[0]->result);

        $wire = $projector->toolFinishedEvent($event, $row, null, null, 'sess-1', '1');
        $this->assertTrue($wire->isDurable(), 'tool.finished is the persisted event');
        $this->assertStringNotContainsString(self::TOKEN, $wire->payload()['content']);
        $this->assertStringContainsString(SecretRedactor::MARKER, $wire->payload()['content']);
    }

    // =========================================================================
    // The choke itself: idempotence and the off-identity promise
    // =========================================================================

    public function testRedactingAlreadyRedactedBytesChangesNothing(): void
    {
        $once = SecretRedactor::new()->redact('Bearer ' . self::TOKEN);
        $twice = SecretRedactor::new()->redact($once);

        $this->assertSame($once, $twice);
        $this->assertStringContainsString(SecretRedactor::MARKER, $once);
        $this->assertStringNotContainsString(self::TOKEN, $once);
    }

    public function testTheOffSwitchHandsBackTheVerySameResultObject(): void
    {
        $runtime = new Runtime($this->leakyProvider(), new HookManager(new HookRegistry()));
        $armed = new Runtime($this->leakyProvider(), new HookManager(new HookRegistry()), redactToolOutput: true);

        $raw = new ToolResult(toolCallId: 'c1', content: 'Bearer ' . self::TOKEN);
        $choke = new \ReflectionMethod(Runtime::class, 'redactResult');

        $this->assertSame($raw, $choke->invoke($runtime, $raw), 'off must not even rebuild the object');

        $after = $choke->invoke($armed, $raw);
        $this->assertNotSame($raw, $after);
        $this->assertStringContainsString(SecretRedactor::MARKER, $after->content());

        // ...and an armed choke over clean bytes does not churn either.
        $benign = new ToolResult(toolCallId: 'c1', content: 'nothing to hide');
        $this->assertSame($benign, $choke->invoke($armed, $benign));
    }

    // =========================================================================
    // The settings reader
    // =========================================================================

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('readerShapes')]
    public function testTheReaderHonoursOnlyValuesThatSaysSo(bool $expected, array $config): void
    {
        $this->assertSame($expected, $this->read($config));
    }

    /**
     * @return iterable<string, array{bool, array<string, mixed>}>
     */
    public static function readerShapes(): iterable
    {
        yield 'bare config defaults off' => [false, []];
        yield 'literal true' => [true, ['redactToolOutput' => true]];
        yield 'int one' => [true, ['redactToolOutput' => 1]];
        yield 'string one' => [true, ['redactToolOutput' => '1']];
        yield 'string true' => [true, ['redactToolOutput' => 'true']];
        yield 'string TRUE' => [true, ['redactToolOutput' => 'TRUE']];
        yield 'string yes padded' => [true, ['redactToolOutput' => '  Yes  ']];
        yield 'string on' => [true, ['redactToolOutput' => 'on']];
        yield 'literal false' => [false, ['redactToolOutput' => false]];
        yield 'int zero' => [false, ['redactToolOutput' => 0]];
        yield 'string zero' => [false, ['redactToolOutput' => '0']];
        yield 'string false' => [false, ['redactToolOutput' => 'false']];
        yield 'nonsense string' => [false, ['redactToolOutput' => 'maybe']];
        yield 'null' => [false, ['redactToolOutput' => null]];
        yield 'array' => [false, ['redactToolOutput' => ['yes']]];
        yield 'empty string' => [false, ['redactToolOutput' => '']];
    }

    public function testAnAbsentConfigKeyReadsOffDiskAndStaysOff(): void
    {
        // invoke() with no argument takes the $config = null path: the reader
        // falls through to the on-disk user config, which the sandbox has none of.
        $this->assertFalse($this->read(null));
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @param array<string, mixed> $config
     */
    private function read(?array $config): bool
    {
        $reader = new \ReflectionMethod(EngineBackend::class, 'redactToolOutput');

        return $config === null ? $reader->invoke(null) : $reader->invoke(null, $config);
    }

    /**
     * @param list<object> $events
     */
    private function toolFinished(array $events): ToolFinished
    {
        foreach ($events as $event) {
            if ($event instanceof ToolFinished) {
                return $event;
            }
        }

        $this->fail('the turn emitted no ToolFinished event');
    }

    /**
     * @param list<object> $messages
     */
    private function resultMessageContent(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof ToolResultMessage) {
                return $message->content();
            }
        }

        $this->fail('the last request carried no ToolResultMessage');
    }

    /**
     * @param array<string, mixed> $payload extra ToolResult fields to carry
     */
    private function leakyTool(array $payload = []): Tool
    {
        return new class ($payload) implements Tool {
            /** @param array<string, mixed> $payload */
            public function __construct(private array $payload) {}

            public function name(): string
            {
                return 'Leak';
            }

            public function description(): string
            {
                return 'echoes a credential like a fetch of a private API would';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(...[
                    'toolCallId' => '',
                    'content' => 'fetched: Authorization: Bearer ' . ToolOutputRedactionTest::TOKEN,
                ] + $this->payload);
            }
        };
    }

    private function leakyProvider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            private int $index = 0;

            public function name(): string
            {
                return 'test';
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

            public function supportsDocuments(): bool
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

            public function costPer1kTokens(string $m, string $d): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->requests[] = $r;

                return $this->index++ === 0
                    ? new CompleteResponse(content: 'working', toolCalls: [new ToolCall('c1', 'Leak', [])])
                    : new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator
            {
                yield $this->complete($r);
            }

            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
