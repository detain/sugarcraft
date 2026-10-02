<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit F-H1, on the live engine path: a `PostToolUse` hook that refuses must
 * keep the output it refused away from the model (and the UI), and its reason
 * must surface — it used to be a silent no-op that delivered the raw bytes.
 *
 * @see Runtime::settle()
 */
final class RuntimePostToolUseBlockTest extends TestCase
{
    private const SECRET = 'AWS_ACCESS_KEY_ID=AKIAEXAMPLE1234567890';

    private const SCANNER = 'if grep -q AKIA "$CRUSH_TOOL_OUTPUT_FILE"; then '
        . 'echo "output contains AWS key, blocked" >&2; exit 2; fi; exit 0';

    private ProviderInterface $provider;
    private HookRegistry $registry;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');
        $this->registry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->registry));
    }

    public function testAScannerBlockWithholdsTheOutputAndSurfacesTheReason(): void
    {
        $this->registry->register(new ScriptHook('secret-scan', HookEvent::PostToolUse, '.*', self::SCANNER, ''));
        $tool = self::bashTool(self::SECRET);

        [$results, $finished] = $this->run1([new ToolCall('call_env', 'Bash', ['command' => 'env'])], $tool);

        $this->assertSame(1, $tool->calls, 'PostToolUse cannot un-run the call');
        $this->assertCount(1, $results);
        foreach ([$results[0]->content(), $finished[0]->result->content()] as $content) {
            $this->assertStringNotContainsString('AKIA', $content);
            $this->assertStringContainsString('[output withheld by PostToolUse hook "secret-scan": output contains AWS key, blocked]', $content);
        }
        $this->assertSame('call_env', $results[0]->toolCallId());
        $this->assertFalse($results[0]->isError(), 'the call ran; withholding its output is not a tool failure');
    }

    public function testCleanOutputPassesTheSameScannerUntouched(): void
    {
        $this->registry->register(new ScriptHook('secret-scan', HookEvent::PostToolUse, '.*', self::SCANNER, ''));

        [$results] = $this->run1([new ToolCall('call_ls', 'Bash', ['command' => 'ls'])], self::bashTool('README.md'));

        $this->assertSame('README.md', $results[0]->content());
    }

    /**
     * The diff and the image are renderings of the refused output; leaving
     * them would leak it one field over.
     */
    public function testTheDiffAndImageAreWithheldWithTheText(): void
    {
        $this->registry->register(self::verdictHook(HookResult::deny('diff carries a key')));
        $tool = self::bashTool(self::SECRET, diff: "+key=AKIAEXAMPLE\n", image: 'PNG-OF-AKIA');

        [$results, $finished] = $this->run1([new ToolCall('call_edit', 'Bash', ['command' => 'x'])], $tool);

        $this->assertFalse($finished[0]->result->hasDiff());
        $this->assertFalse($finished[0]->result->hasImage());
        $this->assertFalse($results[0]->hasImage());
        $this->assertNull($results[0]->imageBytes());
        $this->assertSame(
            '[output withheld by PostToolUse hook "verdict": diff carries a key] The call ran; its output is not shown.',
            $results[0]->content(),
        );
    }

    /**
     * A blocking verdict's own note is stdout written while looking at the
     * refused output — it is dropped with it. An empty reason says so.
     */
    public function testABlockingVerdictsNoteIsDroppedAndAnEmptyReasonIsNamed(): void
    {
        $this->registry->register(self::verdictHook(HookResult::deny('', 'echoed AKIAEXAMPLE')));

        [$results] = $this->run1([new ToolCall('call_q', 'Bash', ['command' => 'x'])], self::bashTool(self::SECRET));

        $this->assertStringNotContainsString('AKIA', $results[0]->content());
        $this->assertStringContainsString('[output withheld by PostToolUse hook "verdict": no reason given]', $results[0]->content());
    }

    /**
     * Fail closed on everything that does not permit: an ASK has nobody to
     * answer it after the call ran.
     */
    public function testANonPermittingAskWithholdsToo(): void
    {
        $this->registry->register(self::verdictHook(HookResult::ask('really show this?')));

        [$results] = $this->run1([new ToolCall('call_a', 'Bash', ['command' => 'x'])], self::bashTool(self::SECRET));

        $this->assertStringNotContainsString('AKIA', $results[0]->content());
        $this->assertStringContainsString('really show this?', $results[0]->content());
    }

    public function testAPermittingHooksNoteStillAppendsExactlyAsBefore(): void
    {
        $this->registry->register(self::verdictHook(HookResult::allow('', 'post note')));

        [$results] = $this->run1([new ToolCall('call_ok', 'Bash', ['command' => 'ls'])], self::bashTool('README.md'));

        $this->assertSame("README.md\n\npost note", $results[0]->content());
    }

    public function testTheConcurrentPathWithholdsOnlyTheBlockedCall(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrent dispatch arm');
        }

        $this->registry->register(new ScriptHook('secret-scan', HookEvent::PostToolUse, '.*', self::SCANNER, ''));
        $tool = self::bashTool(null, parallelSafe: true);

        [$results, $finished] = $this->run1([
            new ToolCall('call_a', 'Bash', ['command' => 'clean-a']),
            new ToolCall('call_b', 'Bash', ['command' => self::SECRET]),
            new ToolCall('call_c', 'Bash', ['command' => 'clean-c']),
        ], $tool);

        $this->assertSame(['call_a', 'call_b', 'call_c'], array_map(
            static fn (ToolResultMessage $m): string => $m->toolCallId(),
            $results,
        ));
        $this->assertSame('clean-a', $results[0]->content());
        $this->assertStringNotContainsString('AKIA', $results[1]->content());
        $this->assertStringContainsString('output contains AWS key, blocked', $results[1]->content());
        $this->assertStringNotContainsString('AKIA', $finished[1]->result->content());
        $this->assertSame('clean-c', $results[2]->content());
    }

    /**
     * @param list<ToolCall> $calls
     *
     * @return array{0: list<ToolResultMessage>, 1: list<ToolFinished>}
     */
    private function run1(array $calls, Tool $tool): array
    {
        $app = App::new($this->provider, 'test-model')->withTools([$tool]);
        $finished = [];
        $onEvent = static function (object $event) use (&$finished): void {
            if ($event instanceof ToolFinished) {
                $finished[] = $event;
            }
        };
        $method = new \ReflectionMethod($this->runtime, 'executeToolCalls');
        $results = array_values(iterator_to_array($method->invoke($this->runtime, $calls, $app, $onEvent), false));

        return [$results, $finished];
    }

    /**
     * A `Bash` stand-in that prints $output (or, when null, its own command)
     * and counts its calls.
     */
    private static function bashTool(
        ?string $output,
        bool $parallelSafe = false,
        ?string $diff = null,
        ?string $image = null,
    ): Tool&ParallelSafe {
        return new class ($output, $parallelSafe, $diff, $image) implements Tool, ParallelSafe {
            public int $calls = 0;

            public function __construct(
                private readonly ?string $output,
                private readonly bool $parallelSafe,
                private readonly ?string $diff,
                private readonly ?string $image,
            ) {
            }

            public function name(): string
            {
                return 'Bash';
            }

            public function description(): string
            {
                return 'stub bash';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(
                    toolCallId: 'stub',
                    content: $this->output ?? (string) ($args['command'] ?? ''),
                    imageBytes: $this->image,
                    imageProtocol: $this->image === null ? null : 'kitty',
                    diff: $this->diff,
                );
            }

            public function isParallelSafe(): bool
            {
                return $this->parallelSafe;
            }
        };
    }

    /**
     * A `PostToolUse` hook that answers $verdict for every call.
     */
    private static function verdictHook(HookResult $verdict): HookInterface
    {
        return new class ($verdict) implements HookInterface {
            public function __construct(private readonly HookResult $verdict)
            {
            }

            public function name(): string
            {
                return 'verdict';
            }

            public function event(): HookEvent
            {
                return HookEvent::PostToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return $this->verdict;
            }
        };
    }
}
