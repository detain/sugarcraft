<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tools\ToolCall;

/**
 * AUT-4: `--json-schema` from the argv token to the wire request, the
 * honesty gate in front of the billed call, and the terminal-turn rule.
 *
 * Three witnesses, each at the seam that owns the claim. The request-level
 * double sees the ACTUAL CompleteRequest — proving the whole
 * NonInteractive→withJsonSchema→newRuntime→jsonSchemaFor chain put the
 * decoded object on the wire, and the refusal path leaves that counter at
 * zero, so "never billed" is measured, not asserted from a docblock. The
 * direct Runtime pins decide the rule itself (first step constrained,
 * trailing tool activity free) without renting a turn. The e2e pins run the
 * real console boundary: inline object, file path, malformed shapes, and
 * the unsupported-provider refusal document with the provider's name on it.
 *
 * HOME is redirected to a sandbox for the whole class — same convention as
 * {@see EngineBackendLengthStopTest} — because NonInteractive::run reads the
 * user tier, and this class must not read or write the real config.
 */
final class EngineBackendJsonSchemaTest extends TestCase
{
    /** @var array<string, mixed> */
    private const SCHEMA = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]];

    private string $sandboxDir;

    private string|false $originalHome;

    private mixed $originalServerHome = null;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxDir = sys_get_temp_dir() . '/sc_aut4_json_schema_' . bin2hex(random_bytes(6));
        mkdir($this->sandboxDir . '/home', 0o700, true);

        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->sandboxDir . '/home');
        $_SERVER['HOME'] = $this->sandboxDir . '/home';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        if ($this->originalHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->originalHome);
        }

        if (null === $this->originalServerHome) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeSandboxTree();

        parent::tearDown();
    }

    private function removeSandboxTree(): void
    {
        if (!is_dir($this->sandboxDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandboxDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->sandboxDir);
    }

    /**
     * The request-facing witness: answers once with plain content, records
     * every CompleteRequest it was billed for.
     */
    private function recordingProvider(\ArrayObject $seen, bool $supportsSchema): ProviderInterface
    {
        return new class ($seen, $supportsSchema) implements ProviderInterface {
            public function __construct(
                private readonly \ArrayObject $seen,
                private readonly bool $schemaOk,
            ) {}

            public function name(): string
            {
                return 'aut4-recording';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return $this->schemaOk;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->seen[] = $request;

                return new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: 'answered');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    private function runtimeWithSchema(ProviderInterface $provider): Runtime
    {
        return new Runtime($provider, new HookManager(new HookRegistry()), jsonSchema: self::SCHEMA);
    }

    // =========================================================================
    // The rule, at the Runtime: which requests are self-finishing candidates
    // =========================================================================

    public function testTheFirstStepOfATurnCarriesTheSchema(): void
    {
        $runtime = $this->runtimeWithSchema($this->recordingProvider(new \ArrayObject(), true));
        $app = App::new($this->recordingProvider(new \ArrayObject(), true), 'm')
            ->withMessages([new UserMessage('hi')]);

        $this->assertSame(self::SCHEMA, $runtime->jsonSchemaFor($app));
    }

    public function testAStepWhoseNewestActivityIsAToolResultStaysFree(): void
    {
        $runtime = $this->runtimeWithSchema($this->recordingProvider(new \ArrayObject(), true));
        $call = new ToolCall('c1', 'Bash', ['command' => 'ls']);
        $app = App::new($this->recordingProvider(new \ArrayObject(), true), 'm')
            ->withMessages([
                new UserMessage('hi'),
                new AssistantMessage('let me look', [$call]),
                new ToolResultMessage('c1', 'file list'),
            ]);

        $this->assertNull($runtime->jsonSchemaFor($app), 'forcing a schema on the loop-continuation call would strand it — that call must be able to answer with tool calls');
    }

    public function testTheTodoReminderTailDoesNotFoolTheRule(): void
    {
        // The shape AUT-4 nearly shipped wrong: runTurn splices todo and
        // context rows as USER messages AFTER the step's tool results, so a
        // row-KIND tail-scan would call this mid-loop history self-finishing
        // and force a schema onto the continuation call.
        $runtime = $this->runtimeWithSchema($this->recordingProvider(new \ArrayObject(), true));
        $call = new ToolCall('c1', 'Bash', ['command' => 'ls']);
        $app = App::new($this->recordingProvider(new \ArrayObject(), true), 'm')
            ->withMessages([
                new UserMessage('hi'),
                new AssistantMessage('let me look', [$call]),
                new ToolResultMessage('c1', 'file list'),
                new UserMessage('todo reminder: keep going'),
            ]);

        $this->assertNull($runtime->jsonSchemaFor($app));
    }

    public function testAnUntouchedRuntimeNeverConstrains(): void
    {
        $runtime = new Runtime($this->recordingProvider(new \ArrayObject(), true), new HookManager(new HookRegistry()));
        $app = App::new($this->recordingProvider(new \ArrayObject(), true), 'm')
            ->withMessages([new UserMessage('hi')]);

        $this->assertNull($runtime->jsonSchemaFor($app), 'no --json-schema, no wire change — byte-identical requests are the E646 opt-in baseline');
    }

    // =========================================================================
    // The wire witness: the decoded object lands on the actual request
    // =========================================================================

    public function testATurnOnASupportingProviderPutsTheSchemaOnTheRequest(): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, true), 'm');
        $backend = $backend->withJsonSchema(self::SCHEMA);

        $this->assertSame(self::SCHEMA, $backend->jsonSchema());
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertSame('answered', $reply->content);
        $this->assertCount(1, $seen);
        $this->assertSame(self::SCHEMA, $seen[0]->jsonSchema, 'the request the provider was billed for carries the schema');
    }

    public function testWithoutTheWitherTheRequestIsByteForByteTheOldOne(): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, true), 'm');

        $backend->complete([Message::user('hi')]);

        $this->assertCount(1, $seen);
        $this->assertNull($seen[0]->jsonSchema);
    }

    // =========================================================================
    // The console boundary: decode, refusal-before-billing, and the document
    // =========================================================================

    public function testAnUnsupportedProviderIsRefusedBeforeAnythingIsBilled(): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, false), 'm');
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--json-schema', '{"type":"object"}']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_JSON);
        $printed = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $this->assertCount(0, $seen, 'the honesty gate runs BEFORE the first billed call — a refusal that still sent the request is not a refusal');
        $document = json_decode(trim($printed), true);
        $this->assertIsArray($document);
        $this->assertSame('json_schema_unsupported', $document['error']['type']);
        $this->assertSame('aut4-recording', $document['error']['provider']);
    }

    public function testAConsoleRunOnASupportingProviderCarriesTheSchemaToTheWire(): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, true), 'm');
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--json-schema', '{"type":"object"}']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_JSON);
        $printed = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertCount(1, $seen);
        $this->assertSame(['type' => 'object'], $seen[0]->jsonSchema, 'inline JSON decodes at the boundary and rides as the array the wire wants');
        $this->assertSame('answered', json_decode(trim($printed), true)['result']);
    }

    public function testAFileSpellingDecodesFromTheNamedFile(): void
    {
        $path = $this->sandboxDir . '/aut4-schema-' . uniqid('', true) . '.json';
        $this->assertNotFalse(file_put_contents($path, json_encode(self::SCHEMA, JSON_THROW_ON_ERROR)));
        $this->tempFiles[] = $path;

        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, true), 'm');
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--json-schema', $path]);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_JSON);
        ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertSame(self::SCHEMA, $seen[0]->jsonSchema);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function malformedSchemaUses(): iterable
    {
        yield 'a JSON list is not a schema' => [['candy-code', '-p', 'hi', '--json-schema', '[1,2]'], 'must be a JSON object'];
        yield 'a bare malformed document' => [['candy-code', '-p', 'hi', '--json-schema', '{oops'], 'not valid JSON'];
        yield 'a named file that does not exist' => [['candy-code', '-p', 'hi', '--json-schema', '/nonexistent/aut4-nope.json'], 'no readable file'];
    }

    /**
     * @param list<string> $argv
     * @dataProvider malformedSchemaUses
     */
    public function testAMalformedSchemaIsAUsageRefusalNeverASilentDrop(array $argv, string $needle): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, true), 'm');
        $args = ArgvParser::parse($argv);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_JSON);
        $printed = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $this->assertCount(0, $seen);
        $document = json_decode(trim($printed), true);
        $this->assertSame('usage', $document['error']['type']);
        $this->assertStringContainsString($needle, $document['error']['message']);
    }

    public function testANonEngineBackendCannotBeConstrainedEither(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--json-schema', '{"type":"object"}']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, new EchoBackend(), NonInteractive::FORMAT_JSON);
        $printed = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $code);
        $document = json_decode(trim($printed), true);
        $this->assertSame('json_schema_unsupported', $document['error']['type']);
        $this->assertArrayNotHasKey('provider', $document['error'], 'a bare Backend has no provider to name');
    }

    public function testNoFlagMeansNoGateAndNoWireChange(): void
    {
        $seen = new \ArrayObject();
        $backend = new EngineBackend($this->recordingProvider($seen, false), 'm');
        $args = ArgvParser::parse(['candy-code', '-p', 'hi']);
        $this->assertNull($args->usageError);

        ob_start();
        $code = NonInteractive::run($args, $backend, NonInteractive::FORMAT_JSON);
        ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code, 'the whole mechanism is opt-in: without the flag even a non-supporting provider answers normally');
        $this->assertCount(1, $seen);
        $this->assertNull($seen[0]->jsonSchema);
    }
}
