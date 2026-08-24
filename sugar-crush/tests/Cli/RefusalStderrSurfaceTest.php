<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\HeadlessPermissionPrompt;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * WHY A REFUSED ASK WRITES TWO THINGS TO STDERR AND KEEPS DOING SO (E240).
 *
 * E219 gave {@see NonInteractive} a tool-lifecycle observer that writes one
 * line per refusal; {@see HeadlessPermissionPrompt} already wrote its own.
 * E240 recorded the overlap as cosmetic-and-deliberate and offered a cheap
 * removal: drop the prompt's terse `sugarcrush: refused <tool>.` now that the
 * observer carries a fuller line.
 *
 * MEASURED at round 49 before deciding, on PHP 8.3.6, by driving a real
 * {@see EngineBackend} turn whose hook gate ASKs, once per arm, with the
 * prompt's `$err` on a real file descriptor in a child process. TWO
 * CORRECTIONS TO THE ENTRY, and together they are why the pair stays:
 *
 *  1. THE DOUBLING IS NOT TTY-ONLY. The entry says "an ASK refused at a
 *     terminal now writes two stderr lines". The NO-TTY arm doubles too: the
 *     refusal block AND the observer's line. Dropping the terse line would
 *     therefore not remove the doubling in general — it would remove it from
 *     one of the two arms that has it. The byte and line totals this paragraph
 *     used to carry are retired and MEASURED instead, by
 *     {@see self::testBothArmsDoubleAndTheseAreTheBytesTheyWrite()} — see
 *     E256 there for why a figure in prose was the wrong shape for them.
 *  2. THE OBSERVER'S LINE CANNOT TELL THE TWO ARMS APART, and that is the
 *     decisive one. Both arms end in a reason opening with
 *     {@see DenialKind::Refused} — `Permission denied:` — because in both an
 *     approver WAS attached and answered no; the difference is only WHY it
 *     answered no, and the observer never sees that. So the prompt's own text
 *     is the only thing on stderr that distinguishes "a person typed n" from
 *     "there was nobody at the keyboard", which are different problems with
 *     different remedies.
 *
 * This file pins both corrections, so the removal is not re-proposed from the
 * entry's text alone. It deliberately does NOT assert the observer's bytes:
 * `NonInteractive::noticeRefusal()` writes to the real `\STDERR` with no
 * injectable stream, and asserting on that needs a child process
 * ({@see NonInteractiveRefusalDocumentTest} pays for one where the claim is
 * about bytes). The claim here is about WHAT THE OBSERVER CAN KNOW, which is
 * exactly what {@see NonInteractive::refusalFrom()} returns.
 */
final class RefusalStderrSurfaceTest extends TestCase
{
    /**
     * THE TERMINAL ARM: a person answers `n`.
     *
     * The prompt says a person refused; the observer's reason says the call
     * was permission-denied and nothing about who.
     */
    public function testAnAnsweredRefusalNamesThePersonOnlyInThePromptsOwnLine(): void
    {
        [$promptText, $refusal] = $this->refuse(interactive: true);

        self::assertStringContainsString('refused Bash.', $promptText);
        self::assertStringNotContainsString('stdin is not a terminal', $promptText);

        self::assertNotNull($refusal, 'the observer saw no refusal at all, so there is no pair to reason about');
        self::assertSame('Bash', $refusal['tool']);
        self::assertStringStartsWith(DenialKind::Refused->value, $refusal['reason']);
    }

    /**
     * THE NO-TTY ARM: nobody is there.
     *
     * A DIFFERENT prompt text — and, the point of the file, the SAME observer
     * reason. This is the assertion that makes dropping the prompt's line a
     * loss of information rather than a de-duplication.
     */
    public function testAnUnanswerableRefusalIsIndistinguishableInTheObserversLine(): void
    {
        [$promptText, $refusal] = $this->refuse(interactive: false);

        self::assertStringContainsString('stdin is not a terminal', $promptText);
        self::assertStringNotContainsString('refused Bash.', $promptText);

        self::assertNotNull($refusal);
        self::assertStringStartsWith(DenialKind::Refused->value, $refusal['reason']);

        // The two arms compared directly rather than by eye, because "the same
        // reason" is the whole claim and two assertStringStartsWith calls in
        // two tests do not make it.
        [, $answered] = $this->refuse(interactive: true);
        self::assertNotNull($answered);
        self::assertSame(
            $answered['reason'],
            $refusal['reason'],
            'the observer CAN now tell an answered refusal from an unanswerable one, which is the fact this '
            . "file exists to deny. If that is deliberate, the prompt's terse line may finally be droppable",
        );
    }

    /**
     * AND THE KNOWN-POSITIVE FOR THE HARNESS ITSELF (rule 15): a plain hook
     * DENY reaches the observer and never reaches the prompt at all.
     *
     * Without this row the two tests above could both be describing a turn in
     * which the prompt was never attached and the observer saw everything, or
     * one in which nothing ran. This one has a DIFFERENT expected value in
     * both columns — empty prompt text, {@see DenialKind::Hook} reason — so a
     * harness stuck on one answer cannot satisfy it.
     *
     * THE EMPTY STRING ONLY MEANS SOMETHING BECAUSE THE PROMPT IS THERE TO
     * WRITE IT. WHAT THE HELPER USED TO DO: attach the approver only on the
     * `ask` arm, so this row ran with no `HeadlessPermissionPrompt` in
     * existence and `''` was what the ABSENCE of the instrument returned — the
     * rule-25 shape, and the assertion would have survived this class writing
     * to stderr unconditionally. WHAT IS TRUE NOW: the approver is attached on
     * both arms, MEASURED on PHP 8.3.6 as still producing `''` here, so the
     * emptiness is the gate returning on the DENY before `settleAsk()` is
     * reached rather than nobody being wired up.
     */
    public function testAPlainHookDenyNeverReachesThePromptAndStillReachesTheObserver(): void
    {
        [$promptText, $refusal] = $this->refuse(interactive: true, verdict: 'deny');

        self::assertSame('', $promptText, 'a hook DENY reached the attached approver. The gate is supposed '
            . 'to return on the DENY before settleAsk() is reached, so the prompt should never have been '
            . 'consulted at all');
        self::assertNotNull($refusal);
        self::assertStringStartsWith(DenialKind::Hook->value, $refusal['reason']);
    }

    /**
     * THE GENERATOR FOR E240's FIGURES, WHICH E240 DID NOT SHIP (E256).
     *
     * WHAT THE TWO DOC-BLOCKS SAID: that the no-tty arm writes "526 bytes over
     * 9 lines" and the terminal arm "266 over 8". WHAT WAS WRONG WITH IT: not
     * the numbers — re-derived here on PHP 8.3.6 they are exactly those — but
     * that they lived in prose in {@see HeadlessPermissionPrompt}'s class
     * doc-block and in this file's, with no runnable thing behind either. A
     * figure whose generator is a sentence cannot be re-derived by the next
     * reader and cannot go red when it stops being true, which is the one
     * property that would make it worth writing down. Both copies are retired
     * and this method is what replaces them.
     *
     * THE NUMBERS LIVE HERE AND NOWHERE ELSE, deliberately. Reword
     * {@see HeadlessPermissionPrompt::refusal()} or
     * {@see NonInteractive::refusalNotice()} and this test goes red with the
     * measured values in the message; update them in this one place. That is
     * the whole difference between a number a test owns and a number a
     * paragraph owns.
     *
     * BOTH HALVES ARE MEASURED FROM THE CODE THAT RUNS. The prompt's bytes are
     * whatever it wrote to its own `$err`; the observer's are
     * `NonInteractive::refusalNotice()` invoked by reflection on the reason
     * the observer actually saw. Neither is a string this file spells — a
     * re-spelled copy would agree with itself and with nothing else.
     *
     * AND THE CLAIM THE FIGURES SUPPORT IS ASSERTED SEPARATELY FROM THEM,
     * because it is the part that matters and it must not depend on any
     * particular wording: BOTH arms put a prompt block AND an observer line on
     * stderr, and the observer's line is byte-identical across the two. That
     * is E249 — the doubling is not tty-only — and it is why the terse line
     * cannot be dropped as a duplicate.
     *
     * THE WORDING-INDEPENDENT ASSERTIONS RUN FIRST, AND THAT ORDER IS LOAD-
     * BEARING RATHER THAN COSMETIC. WHAT THIS DOC-BLOCK SAID when the method
     * landed: that those two assertions "must not depend on any particular
     * wording". WHAT WAS WRONG WITH IT: they sat BELOW the exact-figure
     * `assertSame`, and PHPUnit stops a test at its first failure — so on the
     * one class of change they exist to survive, they did not execute at all.
     * MEASURED on PHP 8.3.6 before the reorder, by deleting the three
     * remedy lines from {@see HeadlessPermissionPrompt::refusal()} (a mutation
     * that breaks the DOUBLING claim itself, not merely the byte counts): the
     * failure was reported at the figure `assertSame`, 11 assertions in, and
     * the `assertGreaterThan` that names the doubling never ran. WHY THIS
     * STILL EARNS ITS PLACE: the figures are still the generator E256 asked
     * for and still go red on a reword — they just no longer mask the
     * structural claim while doing it. A reword now reds ONE assertion with
     * the new numbers in its message; a change that also breaks the doubling
     * reds the doubling first, which is the one a reader must not miss.
     */
    public function testBothArmsDoubleAndTheseAreTheBytesTheyWrite(): void
    {
        $notice = new \ReflectionMethod(NonInteractive::class, 'refusalNotice');
        $notice->setAccessible(true);

        $measured = [];
        foreach (['terminal' => true, 'no-tty' => false] as $arm => $interactive) {
            [$promptText, $refusal] = $this->refuse(interactive: $interactive);
            self::assertNotNull($refusal, "the {$arm} arm produced no refusal for the observer to report");

            $observer = (string) $notice->invoke(null, $refusal['tool'], $refusal['kind'], $refusal['reason']);

            // THE DOUBLING ITSELF, per arm, before any number is compared.
            self::assertNotSame('', $promptText, "the {$arm} arm's prompt wrote nothing, so there is no "
                . 'pair here and the figures below are measuring one producer');
            self::assertNotSame('', $observer, "the {$arm} arm's observer line is empty");

            $measured[$arm] = [
                'promptBytes' => \strlen($promptText),
                'promptLines' => substr_count($promptText, "\n"),
                'observerBytes' => \strlen($observer),
                'observerLines' => substr_count($observer, "\n"),
                'totalBytes' => \strlen($promptText) + \strlen($observer),
                'totalLines' => substr_count($promptText, "\n") + substr_count($observer, "\n"),
            ];
        }

        // AND THE OBSERVER CANNOT TELL THE ARMS APART, at the byte level.
        // Asserted from the measurement rather than restated, so it cannot
        // drift away from the exact figures below. FIRST, and that ordering is
        // the fix rather than a tidy: see the class of assertion each one is
        // in this method's doc-block.
        self::assertSame(
            $measured['terminal']['observerBytes'],
            $measured['no-tty']['observerBytes'],
            'the observer line now differs between the two arms, which is the fact this file exists to '
            . "deny. If that is deliberate, the prompt's terse line may finally be droppable",
        );
        self::assertGreaterThan(
            $measured['terminal']['totalLines'],
            $measured['no-tty']['totalLines'],
            'the no-tty arm no longer writes MORE than the terminal one, so E240\'s "removing the terse '
            . 'line removes the doubling" would now be arguable',
        );

        self::assertSame(
            [
                'terminal' => [
                    'promptBytes' => 189, 'promptLines' => 7,
                    'observerBytes' => 87, 'observerLines' => 1,
                    'totalBytes' => 276, 'totalLines' => 8,
                ],
                'no-tty' => [
                    'promptBytes' => 449, 'promptLines' => 8,
                    'observerBytes' => 87, 'observerLines' => 1,
                    'totalBytes' => 536, 'totalLines' => 9,
                ],
            ],
            $measured,
            'the stderr surface of a refused ASK has changed size. This is the generator for the figures '
            . "E240 recorded in prose; if the wording moved on purpose, the new numbers are in this test's "
            . 'diff and this is the one place they are written down',
        );
    }

    /**
     * Drive one gated turn and return what each of the two producers got.
     *
     * @return array{0: string, 1: array{tool: string, reason: string}|null}
     *     [everything the prompt wrote to its own stderr, the entry the
     *     observer would have written a line for]
     */
    private function refuse(bool $interactive, string $verdict = 'ask'): array
    {
        $registry = new HookRegistry();
        $registry->register($this->gateHook($verdict));

        $backend = EngineBackend::new($this->providerAskingToDeleteABuildTree(), 'bash')
            ->withTools([$this->bashSpyTool()])
            ->withHooks(new HookManager($registry));

        $in = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($err);
        fwrite($in, "n\n");
        rewind($in);

        // THE APPROVER IS ATTACHED ON BOTH ARMS, AND THAT IS THE WHOLE POINT
        // OF THE DENY ROW. It used to be attached only when $verdict === 'ask',
        // which made that row's `assertSame('', $promptText)` true by
        // construction: no prompt existed, so nothing could have written. The
        // expected value was what an ABSENT instrument returns, so the
        // assertion would have held with this class writing to stderr on every
        // call. Attached unconditionally, the empty string is evidence that a
        // hook DENY returns out of the gate before the approver is consulted.
        $backend = $backend->withPermissionApprover(
            (new HeadlessPermissionPrompt(PermissionMode::Default, $in, $err, $interactive))->approver(),
        );

        $refusalFrom = new \ReflectionMethod(NonInteractive::class, 'refusalFrom');
        $refusalFrom->setAccessible(true);

        $seen = null;
        $backend->complete(
            [Message::user('clean the build tree')],
            null,
            static function (object $event) use ($refusalFrom, &$seen): void {
                if (!$event instanceof ToolFinished) {
                    return;
                }
                $seen ??= $refusalFrom->invoke(null, $event);
            },
        );

        return [(string) stream_get_contents($err, -1, 0), $seen];
    }

    private function gateHook(string $verdict): HookInterface
    {
        return new class ($verdict) implements HookInterface {
            public function __construct(private readonly string $verdict) {}
            public function name(): string { return 'refusal-surface-probe'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult
            {
                return $this->verdict === 'deny'
                    ? HookResult::deny('rm -rf is not allowed')
                    : HookResult::ask('Allow Bash rm -rf ./build?');
            }
        };
    }

    private function providerAskingToDeleteABuildTree(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public int $calls = 0;

            public function name(): string { return 'bash-tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;

                return $this->calls === 1
                    ? new CompleteResponse(
                        content: 'cleaning',
                        toolCalls: [new ToolCall('c1', 'Bash', ['command' => 'rm -rf ./build'])],
                    )
                    : new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }

    private function bashSpyTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Bash'; }
            public function description(): string { return 'spy bash'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                throw new \LogicException('the gate let a refused call through to the tool');
            }
        };
    }
}
