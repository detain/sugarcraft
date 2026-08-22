<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\ToolResult as ChatToolResult;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * THE THREE WAYS A CALL IS STOPPED ARE THREE STRINGS, AND THE ROSTER THAT
 * CLASSIFIES THEM LIVES IN A DIFFERENT CLASS (E210, E211).
 *
 * {@see Runtime::gate()} is the engine-path producer of a denial reason and
 * {@see Chat::DENIED_ERROR_PREFIXES} is the roster two surfaces read to decide
 * whether a tool result is a REFUSAL (a call that never ran, drawn struck
 * through by {@see \SugarCraft\Crush\Renderer::renderToolResults()} and listed
 * in a `--output-format json` document's `refusals` array by
 * {@see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom()}) or an ordinary
 * tool ERROR (a call that ran and failed, which the model is expected to act
 * on). The producer does not READ that roster and deliberately does not — see
 * {@see Runtime::DENIAL_HOOK}'s doc-block for the measured reason, which is
 * that reading it would autoload `Chat` on the first gated tool call of every
 * run including the `-p` path that exists to avoid building one.
 *
 * WHAT IS LEFT WHEN A PRODUCER DOES NOT READ ITS ROSTER is a copy that can
 * drift, and the drift is silent in the worst direction: a prefix
 * `Runtime` invents that the roster does not carry renders a BLOCKED call as a
 * failed one on both surfaces. This file is the coupling, made loud.
 *
 * It is deliberately not in `tests/Cli` or `tests/Hooks`: the contract spans
 * `Runtime` (producer), `Chat` (roster + classifier) and `NonInteractive`
 * (second consumer), and filing it under any one of the three would put it
 * where a reader of the other two will not look.
 */
final class DenialPrefixRosterTest extends TestCase
{
    private ProviderInterface $provider;
    private HookRegistry $hookRegistry;
    private Runtime $runtime;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->hookRegistry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->hookRegistry));
    }

    // ── the coupling ─────────────────────────────────────────────────────

    /**
     * EVERY PREFIX `Runtime` PRODUCES IS ON THE ROSTER.
     *
     * Enumerated by reflection rather than listed here, so a fourth
     * `DENIAL_*` constant added later is covered by this test on the day it is
     * added rather than on the day someone remembers to extend the test.
     */
    public function testEveryDenialPrefixRuntimeProducesIsOnChatsRoster(): void
    {
        $produced = self::runtimeDenialPrefixes();

        self::assertNotSame([], $produced, 'no DENIAL_* constants found; this guard is scanning nothing');

        foreach ($produced as $name => $prefix) {
            self::assertContains(
                $prefix,
                Chat::DENIED_ERROR_PREFIXES,
                "Runtime::{$name} is '{$prefix}', which Chat::DENIED_ERROR_PREFIXES does not carry - so a call "
                . 'blocked this way renders as an ordinary tool ERROR in the TUI and is missing from the '
                . '--output-format json refusals array. Add it to the roster or reuse an entry that is on it',
            );
        }
    }

    /**
     * AND THE CLASSIFIER AGREES, which is a different claim from membership:
     * {@see Chat::isDeniedResult()} matches with `str_starts_with` against
     * `$result->error`, so a prefix that is on the roster but is not how the
     * finished reason actually STARTS is still misclassified.
     *
     * The negative case is in the same test on purpose (rule 15): an assertion
     * that three strings are recognised proves nothing about the classifier
     * unless something it must NOT recognise is put through it too.
     *
     * TWO CLASSES ARE CALLED `ToolResult` IN THIS APPLICATION and this test
     * touches both: {@see \SugarCraft\Crush\ToolResult} (aliased
     * `ChatToolResult` here) is the transcript-side value with the nullable
     * `$error` the classifier reads, and {@see \SugarCraft\Crush\Tools\ToolResult}
     * is the engine-side one {@see Runtime::failure()} builds with an
     * `isError` flag and the reason in `content()`. Aliased rather than
     * fully-qualified inline so the difference is visible at every use.
     */
    public function testTheClassifierRecognisesEachPrefixAndStillRefusesAPlainError(): void
    {
        foreach (self::runtimeDenialPrefixes() as $name => $prefix) {
            self::assertTrue(
                Chat::isDeniedResult(ChatToolResult::error('Bash', $prefix . ' something', 'call_1')),
                "Chat::isDeniedResult() does not recognise a result opening with Runtime::{$name}",
            );
        }

        self::assertFalse(
            Chat::isDeniedResult(ChatToolResult::error('Bash', 'No such file or directory', 'call_1')),
            'the classifier calls an ordinary tool failure a refusal; the assertions above prove nothing',
        );
    }

    // ── the three events, driven for real ────────────────────────────────

    /**
     * A HOOK OBJECTING IS `Hook denied:`.
     */
    public function testAChainDenyIsReportedAsAHookDenial(): void
    {
        $this->hookRegistry->register(self::denyHook('rm -rf is not allowed'));

        self::assertStringStartsWith(
            Runtime::DENIAL_HOOK . ' ',
            $this->reasonFor(null),
        );
    }

    /**
     * AN APPROVER ANSWERING NO IS `Permission denied:` — the user's own
     * decision, and the one of the three that is NOT the hook's doing.
     *
     * Before E210 this produced `Hook denied: <the hook's question>`, which
     * named the wrong party and pointed a reader at the hook configuration
     * rather than at the answer they had just typed.
     */
    public function testAnApproverRefusalIsReportedAsAPermissionDenial(): void
    {
        $this->hookRegistry->register(self::askHook('Run it?'));

        $reason = $this->reasonFor(static fn (ToolCall $c, HookResult $a): bool => false);

        self::assertStringStartsWith(Runtime::DENIAL_REFUSED . ' ', $reason);
        self::assertStringContainsString('Run it?', $reason, 'the question the user answered is gone');
    }

    /**
     * AND AN ASK WITH NOBODY ATTACHED IS `Permission required:` — nobody
     * refused it, there was nobody to ask.
     *
     * The wording check is not decoration: this arm's message used to open
     * "Permission required and no approver…" and be prefixed `Hook denied: `,
     * so the finished reason named two different events in one line. The words
     * a reader greps for are still all present.
     */
    public function testAnAskWithNoApproverIsReportedAsPermissionRequired(): void
    {
        $this->hookRegistry->register(self::askHook('Delete production data?'));

        $reason = $this->reasonFor(null);

        self::assertStringStartsWith(Runtime::DENIAL_UNANSWERED . ' ', $reason);
        self::assertStringContainsString('no approver is attached to this run', $reason);
        self::assertStringContainsString('Delete production data?', $reason);
    }

    /**
     * THE THREE ARE MUTUALLY DISTINGUISHABLE, which is the property E210 is
     * actually about and which no one of the three tests above establishes:
     * all three could pass while two prefixes were the same string.
     */
    public function testTheThreeOutcomesDoNotCollapseIntoOneString(): void
    {
        $this->hookRegistry->register(self::denyHook('nope'));
        $hookDeny = $this->reasonFor(null);

        $this->hookRegistry = new HookRegistry();
        $this->runtime = new Runtime($this->provider, new HookManager($this->hookRegistry));
        $this->hookRegistry->register(self::askHook('Run it?'));

        $refused = $this->reasonFor(static fn (ToolCall $c, HookResult $a): bool => false);
        $unanswered = $this->reasonFor(null);

        $prefixes = array_map(
            static fn (string $r): string => explode(':', $r, 2)[0] . ':',
            [$hookDeny, $refused, $unanswered],
        );

        self::assertSame($prefixes, array_unique($prefixes), 'two of the three denials are still one string');
    }

    // ── no second definition of a prefix ─────────────────────────────────

    /**
     * `Runtime` SPELLS A DENIAL PREFIX IN EXACTLY ONE PLACE EACH — the
     * constant — so the coupling the tests above pin cannot be sidestepped by
     * a hand-rolled literal somewhere else in the file.
     *
     * Compared as SETS against the constants themselves rather than against a
     * count: a cardinality written into a test rots the moment the file grows
     * a fourth outcome, and the claim worth making is "no literal that is not a
     * constant's own value", which is a set difference.
     *
     * Token-based, not `strpos`: doc-blocks in this file discuss all three
     * prefixes at length, and a textual scan would report every paragraph as a
     * second definition.
     *
     * BOTH STRING TOKEN KINDS, AND THE SECOND IS THE ONE THAT MATTERS. The
     * first cut of this scanner read `T_CONSTANT_ENCAPSED_STRING` only, and
     * MEASURED on PHP 8.3.6 that would have missed the exact defect it exists
     * to catch: the line it replaced was `"Hook denied: {$hookResult->message}"`,
     * an INTERPOLATED string, whose literal half is `T_ENCAPSED_AND_WHITESPACE`.
     * The same scan over `src/Chat.php` reported three hits — the roster's own
     * entries at its three constant lines — and zero for either of that file's
     * two hand-rolled producers, both of which are interpolated. A guard that
     * cannot see the shape the code is actually written in is a guard whose
     * emptiness means nothing.
     */
    public function testRuntimeSpellsNoDenialPrefixOutsideItsConstants(): void
    {
        $source = self::runtimeSource();

        // KNOWN-POSITIVE FIRST (rule 15). The assertion below is an emptiness
        // claim, and an emptiness claim over a dead scanner is green in a tree
        // where anything at all is wrong. The fixture is assembled from parts
        // so this test file is not itself matched if the scan set widens.
        $literal = "'Hook " . "denied: hand rolled'";
        self::assertSame(
            ['Hook denied: hand rolled'],
            self::denialLiteralsIn("<?php \$x = {$literal};"),
            'the literal scanner can no longer see a denial string it is looking straight at',
        );

        // AND THE INTERPOLATED SHAPE, which is the one the tree is written in
        // and the one the first cut of this scanner could not see at all.
        $interpolated = '"Permission ' . 'denied: {$why}"';
        self::assertSame(
            ['Permission denied: '],
            self::denialLiteralsIn("<?php \$y = {$interpolated};"),
            'the scanner is blind to an interpolated denial string, which is how every producer in this '
            . 'tree spells one',
        );
        self::assertSame(
            [],
            self::denialLiteralsIn('<?php /** Hook denied: only in a comment */ $x = 1;'),
            'the scanner reads comments, so it cannot answer this question at all',
        );

        $declared = array_values(self::runtimeDenialPrefixes());
        $found = self::denialLiteralsIn($source);

        $extra = array_values(array_filter(
            $found,
            static function (string $literal) use ($declared): bool {
                foreach ($declared as $prefix) {
                    if ($literal === $prefix) {
                        return false;
                    }
                }

                return true;
            },
        ));

        self::assertSame(
            [],
            $extra,
            'src/Runtime.php spells a denial prefix outside its DENIAL_* constants. A second spelling is a '
            . 'second definition, and the one that drifts off Chat::DENIED_ERROR_PREFIXES will do so silently',
        );
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * Every `DENIAL_*` constant on {@see Runtime}, as name => value.
     *
     * @return array<string, string>
     */
    private static function runtimeDenialPrefixes(): array
    {
        $out = [];
        foreach ((new \ReflectionClass(Runtime::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'DENIAL_')) {
                // Loud rather than skipped (rule 14): a DENIAL_* that is not a
                // string is something this guard cannot judge, and quietly
                // dropping it leaves a hole shaped exactly like the next
                // defect.
                self::assertIsString($value, "Runtime::{$name} is not a string; this guard cannot judge it");
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** The raw text of `src/Runtime.php`, or a loud failure. */
    private static function runtimeSource(): string
    {
        $path = \dirname(__DIR__) . '/src/Runtime.php';
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("{$path} could not be read; this guard cannot answer for it");
        }

        return $source;
    }

    /**
     * Every string LITERAL in `$source` that opens with a denial-shaped
     * prefix, in source order.
     *
     * The shape rather than the three known values, so a fourth invented
     * spelling (`'Permission blocked: '`) is caught by the same scan that
     * catches a duplicate of a known one.
     *
     * @return list<string>
     */
    private static function denialLiteralsIn(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                // A whole literal: strip the one quote character at each end.
                $value = substr($token[1], 1, -1);
            } elseif ($token[0] === \T_ENCAPSED_AND_WHITESPACE) {
                // The literal RUN inside an interpolated string — already
                // unquoted, because the quote belongs to the surrounding
                // `"` token rather than to this one.
                $value = $token[1];
            } else {
                continue;
            }
            if (preg_match('/^(Hook|Permission) [a-z]+:/', $value) === 1) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Gate one tool call through the registered chain and return the reason it
     * was refused.
     *
     * Driven through `executeToolCalls()` rather than `gate()` itself: the
     * reason string only reaches a consumer after
     * {@see Runtime::failure()} has wrapped it in a {@see ToolResult}, and it
     * is that finished text — the one `Chat::isDeniedResult()` and
     * `NonInteractive::refusalFrom()` see — this whole file is about.
     */
    private function reasonFor(?callable $onPermissionRequest): string
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn('blocked_tool');
        $tool->method('description')->willReturn('a tool that must not run');
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturnCallback(
            static fn (): ToolResult => self::fail('the gate let a refused call through to the tool'),
        );

        $app = App::new($this->provider, 'gpt-4')->withTools([$tool]);

        $reflection = new \ReflectionMethod($this->runtime, 'executeToolCalls');
        $reflection->setAccessible(true);
        $results = iterator_to_array($reflection->invokeArgs($this->runtime, [
            [new ToolCall('call_1', 'blocked_tool', [])],
            $app,
            null,
            $onPermissionRequest,
        ]));

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isError(), 'the call was not refused at all');

        return $results[0]->content();
    }

    private static function denyHook(string $message): HookInterface
    {
        return new class ($message) implements HookInterface {
            public function __construct(private readonly string $message) {}
            public function name(): string { return 'deny-hook'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::deny($this->message); }
        };
    }

    private static function askHook(string $question): HookInterface
    {
        return new class ($question) implements HookInterface {
            public function __construct(private readonly string $question) {}
            public function name(): string { return 'ask-hook'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return HookResult::ask($this->question); }
        };
    }
}
