<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit R6 and R7 — the residuals of F-H1 and F-H2.
 *
 * R6: the text that replaces withheld output named no hook, because the
 * settled verdict did not say which hook refused; and a `PostToolUse` hook that
 * THREW was only noted next to the output, so the model read every byte the
 * hooks behind it never got to judge.
 *
 * R7: `registerBuiltIns()` runs ahead of every hook file, so `AuditHook` ran
 * FIRST in the `PostToolUse` chain and wrote its `=>` line — with a 200-byte
 * excerpt of the output — before a user's secret scanner withheld that output.
 *
 * @see HookRegistry::findMatches()
 * @see HookResult::refusingHook()
 * @see Runtime::settle()
 */
final class PostToolUseAttributionTest extends TestCase
{
    private const LEAKY_OUTPUT = 'AWS_ACCESS_KEY_ID=AKIAEXAMPLE1234567890';

    private string $logFile;
    private ProviderInterface $provider;
    private HookRegistry $registry;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/post-attribution-test-' . uniqid((string) getmypid(), true) . '.log';
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');
        $this->registry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->registry));
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    // ---- R7: the audit leg runs last ---------------------------------------

    /**
     * The production order: the audit hook registered BEFORE the scanner, as
     * `registerBuiltIns()` ahead of a hook file puts it. The only record is the
     * withheld one, and no byte of the refused output reaches the log.
     */
    public function testAnAuditHookRegisteredFirstNeverLogsAnExcerptOfWithheldOutput(): void
    {
        $this->registry->register(new AuditHook($this->logFile));
        $this->registry->register(self::hook('secret-scan', static fn (): HookResult => HookResult::deny('AWS key in output')));

        $this->dispatch(self::LEAKY_OUTPUT);

        $lines = $this->lines();
        $this->assertCount(1, $lines, 'a withheld call gets exactly one record');
        $this->assertStringContainsString('=! WITHHELD "secret-scan": AWS key in output', $lines[0]);
        $this->assertStringNotContainsString('AKIA', implode("\n", $lines));
        $this->assertStringNotContainsString(' => ', $lines[0]);
    }

    /** The control: a chain that permits still gets the ordinary `=>` record. */
    public function testAPermittingChainStillWritesTheExcerptLine(): void
    {
        $this->registry->register(new AuditHook($this->logFile));
        $this->registry->register(self::hook('note', static fn (): HookResult => HookResult::allow()));

        $this->dispatch('README.md');

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringEndsWith(' => README.md', $lines[0]);
    }

    /**
     * Ordering is decided at dispatch, so no registration order can put the
     * audit hook ahead of another hook of its event — and it leaves every
     * other event's order alone.
     */
    public function testFindMatchesRunsTheAuditHookAfterEveryOtherHookOfItsEvent(): void
    {
        $this->registry->register(new AuditHook($this->logFile));
        $this->registry->register(self::hook('first', static fn (): HookResult => HookResult::allow()));
        $this->registry->register(self::hook('second', static fn (): HookResult => HookResult::allow()));
        $this->registry->register(self::hook('pre-a', static fn (): HookResult => HookResult::allow(), HookEvent::PreToolUse));
        $this->registry->register(self::hook('pre-b', static fn (): HookResult => HookResult::allow(), HookEvent::PreToolUse));

        $names = static fn (array $hooks): array => array_map(static fn (HookInterface $h): string => $h->name(), $hooks);

        $this->assertSame(['first', 'second', AuditHook::NAME], $names($this->registry->findMatches('PostToolUse', 'Bash')));
        $this->assertSame(['pre-a', 'pre-b'], $names($this->registry->findMatches('PreToolUse', 'Bash')));
    }

    /** A hook that throws ahead of the audit leg is a withholding, so it too leaves one excerpt-free record. */
    public function testAThrowingHookLeavesOnlyAWithheldRecordNamingIt(): void
    {
        $this->registry->register(new AuditHook($this->logFile));
        $this->registry->register(self::hook('formatter', static function (): HookResult {
            throw new \LogicException('formatter crashed');
        }));

        $this->dispatch(self::LEAKY_OUTPUT);

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('=! WITHHELD "formatter": hook failed: LogicException: formatter crashed', $lines[0]);
        $this->assertStringNotContainsString('AKIA', $lines[0]);
    }

    // ---- R6: the withheld text names the hook ------------------------------

    public function testTheWithheldTextNamesTheRefusingHook(): void
    {
        $this->registry->register(self::hook('formatter', static fn (): HookResult => HookResult::allow()));
        $this->registry->register(self::hook('secret-scan', static fn (): HookResult => HookResult::deny('AWS key in output')));

        $result = $this->dispatch(self::LEAKY_OUTPUT);

        $this->assertSame(
            '[output withheld by PostToolUse hook "secret-scan": AWS key in output] The call ran; its output is not shown.',
            $result->content(),
        );
    }

    /**
     * A throwing hook has vetted nothing, and the scanner queued behind it
     * never ran: the output is withheld, naming the hook that threw, and the
     * call keeps its own result (the turn is not lost).
     */
    public function testAThrowingHookWithholdsTheOutputAndTheHooksBehindItDoNotRun(): void
    {
        $behind = 0;
        $this->registry->register(self::hook('formatter', static function (): HookResult {
            throw new \RuntimeException('script missing');
        }));
        $this->registry->register(self::hook('secret-scan', static function () use (&$behind): HookResult {
            $behind++;

            return HookResult::allow();
        }));

        $result = $this->dispatch(self::LEAKY_OUTPUT);

        $this->assertSame(0, $behind);
        $this->assertSame('call_1', $result->toolCallId());
        $this->assertFalse($result->isError(), 'the call ran; withholding its output is not a tool failure');
        $this->assertSame(
            '[output withheld by PostToolUse hook "formatter": hook failed: RuntimeException: script missing]'
            . ' The call ran; its output is not shown.',
            $result->content(),
        );
    }

    /**
     * A refusal the REGISTRY made — here a chain that keeps rewriting — has no
     * single hook to blame, so the text names the chain rather than guessing.
     */
    public function testARefusalNoSingleHookOwnsNamesTheChain(): void
    {
        $this->registry->register(self::hook('ping', static fn (HookContext $c): HookResult => HookResult::modify(
            json_encode(['command' => ($c->toolInput === '{"command":"a"}' ? 'b' : 'a')], JSON_THROW_ON_ERROR),
        )));

        $result = $this->dispatch(self::LEAKY_OUTPUT);

        $this->assertStringStartsWith('[output withheld by the PostToolUse hook chain: Hooks kept rewriting', $result->content());
        $this->assertStringNotContainsString('AKIA', $result->content());
    }

    /** The hook's name is the registry's record, never the hook's own claim. */
    public function testTheRegistryOverwritesAForgedRefuserName(): void
    {
        $this->registry->register(self::hook('real-name', static fn (): HookResult => new HookResult(
            HookResult::DENY,
            'nope',
            refusedBy: 'someone-else',
        )));

        $verdict = (new HookManager($this->registry))->postToolUse(self::context());

        $this->assertSame('real-name', $verdict->refusedBy);
        $this->assertSame('real-name', $verdict->refusingHook());
    }

    /**
     * Only PostToolUse contains a throw: a PreToolUse chain that throws still
     * propagates, as before, so the call is never dispatched.
     */
    public function testAPreToolUseThrowStillPropagates(): void
    {
        $this->registry->register(self::hook('broken-guard', static function (): HookResult {
            throw new \LogicException('guard crashed');
        }, HookEvent::PreToolUse));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('guard crashed');
        (new HookManager($this->registry))->preToolUse(self::context());
    }

    // ---- HookResult --------------------------------------------------------

    public function testRefusingHookReadsTheRightOriginForEachAction(): void
    {
        $this->assertNull(HookResult::allow()->withRefusedBy('x')->refusingHook(), 'a permitting verdict names nobody');
        $this->assertNull(HookResult::modify('{}')->withRefusedBy('x')->refusingHook());
        $this->assertSame('scan', HookResult::deny('no')->withRefusedBy('scan')->refusingHook());
        $this->assertNull(HookResult::deny('no')->refusingHook(), 'an unstamped refusal names nobody');
        $this->assertSame('first', HookResult::ask('?')->withAskedBy(['first', 'second'])->refusingHook());
        $this->assertNull(HookResult::ask('?')->refusingHook());
        $this->assertSame('odd', (new HookResult('quarantine', 'held'))->withRefusedBy('odd')->refusingHook());
    }

    public function testEveryWitherKeepsTheRefuser(): void
    {
        $deny = HookResult::deny('no')->withRefusedBy('scan');

        $this->assertSame('scan', $deny->withContextSet('note')->refusedBy);
        $this->assertSame('scan', $deny->withAskedBy(['x'])->refusedBy);
        $this->assertSame($deny, $deny->withRefusedBy('scan'), 'an unchanged stamp is a no-op');
    }

    /**
     * A YAML hook without `name:` is named after its whole command, and the
     * notice is model-visible: the name is clipped and cannot break the line.
     */
    public function testTheNoticeClipsTheHookNameAndStripsControls(): void
    {
        $notice = HookResult::withheldNotice("scan\nfake]" . str_repeat('x', 200), '  key  ');

        $this->assertStringNotContainsString("\n", $notice);
        $this->assertStringContainsString('"scan fake]' . str_repeat('x', HookResult::MAX_NAMED_HOOK_CHARS - 10) . '…": key]', $notice);
        $this->assertSame(
            '[output withheld by the PostToolUse hook chain: no reason given] The call ran; its output is not shown.',
            HookResult::withheldNotice(null, ' '),
        );
    }

    // ---- fixtures ----------------------------------------------------------

    private function dispatch(string $output): ToolResultMessage
    {
        $tool = new class ($output) implements Tool {
            public function __construct(private readonly string $output) {}
            public function name(): string { return 'Bash'; }
            public function description(): string { return 'stub'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult { return new ToolResult('stub', $this->output); }
        };
        $app = App::new($this->provider, 'test-model')->withTools([$tool]);
        $method = new \ReflectionMethod($this->runtime, 'executeToolCalls');
        $results = array_values(iterator_to_array($method->invoke(
            $this->runtime,
            [new ToolCall('call_1', 'Bash', ['command' => 'env'])],
            $app,
        ), false));
        $this->assertCount(1, $results);

        return $results[0];
    }

    /** @return list<string> */
    private function lines(): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile)), static fn (string $l): bool => $l !== ''));
    }

    private static function context(): HookContext
    {
        return new HookContext('session', 'Bash', ['command' => 'env'], '{"command":"env"}', 'out', 'model', 'provider', '/tmp');
    }

    private static function hook(string $name, \Closure $decide, HookEvent $event = HookEvent::PostToolUse): HookInterface
    {
        return new class ($name, $decide, $event) implements HookInterface {
            public function __construct(
                private readonly string $hookName,
                private readonly \Closure $decide,
                private readonly HookEvent $hookEvent,
            ) {}
            public function name(): string { return $this->hookName; }
            public function event(): HookEvent { return $this->hookEvent; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return ($this->decide)($context); }
        };
    }
}
