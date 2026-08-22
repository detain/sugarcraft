<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * E173: `--output-format json` carries the tool calls the run REFUSED.
 *
 * THE GAP, as {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}'s own
 * docblock stated it: three of that class's four shapes are refusals and all
 * three land on stderr only, while the caller it names — "whose entire view of
 * the run is stdout plus an exit code" — read
 * `{"result": "<the model's answer>"}` for a turn in which a tool was stopped.
 * The model saw the refusal and answered around it; the operator could not see
 * it at all.
 *
 * WHY THE SEAM IS THE EVENT STREAM AND NOT THE APPROVER. The approver is built
 * four frames away, inside {@see \SugarCraft\Crush\Cli\Bootstrap::backend()}'s
 * private `withConsolePermissionPrompt()`, from a gate this class never holds —
 * so {@see NonInteractive} cannot wrap it without a second copy of the mode
 * decision. It does not need to: a blocked call terminates through
 * {@see \SugarCraft\Crush\Runtime::failure()}, which emits a
 * {@see ToolFinished} carrying the reason, and `Backend::complete()`'s third
 * argument is exactly that stream.
 *
 * THE PIN THAT MATTERS IS THE END-TO-END ONE.
 * {@see testARealEngineTurnPutsItsBlockedToolCallInTheJsonDocument()} drives a
 * real {@see EngineBackend} with a real hook gate and asserts on the decoded
 * document, so nothing in this file assumes what `Runtime` writes into a
 * blocked call's result. The fake-backend cases below pin the document's SHAPE,
 * which an engine turn cannot vary; the engine case pins the CLASSIFICATION,
 * which is the half that would rot silently if `Runtime` reworded its verdict.
 *
 * @see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom() for why the roster
 *      that decides what a refusal is belongs to {@see Chat} rather than here.
 */
final class NonInteractiveRefusalDocumentTest extends TestCase
{
    /**
     * THE END-TO-END CASE, and the reason the rest of the file is allowed to
     * use fakes.
     *
     * A real {@see EngineBackend}, a real provider round-trip that asks for
     * `Bash rm -rf ./build`, and the shipped hook gate that stops it. Nothing
     * here names the string `Runtime` renders a blocked call with — the
     * assertion is that the refusal ARRIVES in the document, so a reword of
     * that verdict reds this test instead of silently emptying the array.
     *
     * That is the failure this test exists for: {@see NonInteractive} matches
     * {@see Chat::DENIED_ERROR_PREFIXES} against the result text, and a roster
     * matched against a string nobody re-checks is a guard with a hole shaped
     * exactly like the next reword.
     */
    public function testARealEngineTurnPutsItsBlockedToolCallInTheJsonDocument(): void
    {
        $tool = $this->bashSpyTool();
        $backend = EngineBackend::new($this->providerAskingToDeleteABuildTree(), 'bash')
            ->withTools([$tool]);

        $document = $this->documentFrom($backend, NonInteractive::EXIT_OK);

        // The spy exists to be READ. Without this the whole file would pass on
        // a gate that reported a denial and ran the command anyway, which is
        // the one outcome none of the document assertions can distinguish.
        self::assertFalse(
            $tool->executed,
            'the gate reported a refusal and the tool ran regardless; the document is honest and the '
            . 'behaviour it describes is not',
        );

        self::assertArrayHasKey(
            'refusals',
            $document,
            'a real engine turn whose tool call the hook gate stopped emitted a document with no refusals '
            . 'in it; the whole of what a JSON consumer learns about that call is nothing',
        );
        self::assertCount(1, $document['refusals']);
        self::assertSame('Bash', $document['refusals'][0]['tool']);
        self::assertNotSame('', $document['refusals'][0]['reason']);

        // The answer is still the answer. A refusal is additional information,
        // never a replacement for the turn's result.
        self::assertIsString($document['result']);
    }

    /**
     * THE CLASSIFIER'S KNOWN-POSITIVE CONTROL, run against the same engine.
     *
     * The test above asserts the document carries the refusal. This one
     * asserts WHY it could: the text the engine actually produced for a
     * blocked call still starts with one of {@see Chat::DENIED_ERROR_PREFIXES}.
     * Separated so that a failure says which of the two halves broke — an
     * empty `refusals` array with this test green means the wiring went, and
     * with this test red means the roster and `Runtime` have drifted apart.
     */
    public function testTheEngineStillRendersABlockedCallWithATextTheDeniedRosterMatches(): void
    {
        $tool = $this->bashSpyTool();
        $backend = EngineBackend::new($this->providerAskingToDeleteABuildTree(), 'bash')
            ->withTools([$tool]);

        $events = [];
        $backend->complete([Message::user('nuke it')], null, static function (object $e) use (&$events): void {
            $events[] = $e;
        });

        $finished = array_values(array_filter($events, static fn(object $e): bool => $e instanceof ToolFinished));
        self::assertCount(1, $finished, 'the engine emitted no ToolFinished at all; this control proves nothing');
        self::assertTrue($finished[0]->result->isError());
        self::assertFalse($tool->executed, 'the gate errored the call and the tool ran anyway');

        $matched = false;
        foreach (Chat::DENIED_ERROR_PREFIXES as $prefix) {
            if (str_starts_with($finished[0]->result->content(), $prefix)) {
                $matched = true;
            }
        }
        self::assertTrue(
            $matched,
            'the engine renders a blocked tool call as "' . $finished[0]->result->content() . '", which no '
            . 'entry in Chat::DENIED_ERROR_PREFIXES matches. The TUI would draw it as an ordinary failure '
            . 'and the JSON document would omit it entirely',
        );
    }

    /**
     * A TURN THAT REFUSES NOTHING EMITS THE DOCUMENT IT ALWAYS DID.
     *
     * `refusals` is the one optional key in the contract, and this is the half
     * of that decision a test can hold: the zero-refusal document must not
     * merely be equivalent, it must have the same keys — because
     * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushAutoloadGuardTest}
     * compares `array_keys()` against a document `bin/sugarcrush` hand-rolls
     * before `vendor/autoload.php` exists, in a process where no tool could
     * ever have run.
     */
    public function testATurnThatRefusesNothingEmitsExactlyTheKeysItAlwaysDid(): void
    {
        $backend = $this->backendEmitting([]);

        self::assertSame(['result'], array_keys($this->documentFrom($backend, NonInteractive::EXIT_OK)));
    }

    /**
     * A TOOL THAT RAN AND FAILED IS NOT A REFUSAL.
     *
     * The negative case is as load-bearing as the positive one: a classifier
     * that takes every errored result turns `refusals` into "tool calls that
     * did not go well", which is a different and much less useful claim — the
     * model saw those errors and answered around them, and the operator can
     * read the answer.
     */
    public function testAToolThatRanAndReturnedAnErrorIsNotReportedAsARefusal(): void
    {
        $backend = $this->backendEmitting([
            ['Read', 'Tool error: ENOENT /nope', true],
            ['Bash', 'Hook denied: rm -rf is not allowed', true],
            ['Grep', 'no matches', false],
        ]);

        $document = $this->documentFrom($backend, NonInteractive::EXIT_OK);

        self::assertSame(
            [['tool' => 'Bash', 'reason' => 'Hook denied: rm -rf is not allowed']],
            $document['refusals'],
            'the refusal list is not the error list; a call that ran and failed is a result',
        );
    }

    /**
     * EVERY REFUSAL, IN THE ORDER THE TURN RAISED THEM.
     *
     * A turn can block several calls, and collapsing them to a boolean or to
     * the first one loses precisely what a consumer would act on.
     */
    public function testEveryRefusedCallIsListedInTheOrderTheTurnRaisedThem(): void
    {
        $backend = $this->backendEmitting([
            ['Bash', 'Hook denied: rm -rf is not allowed', true],
            ['Write', 'Permission denied: /etc/passwd', true],
        ]);

        self::assertSame(
            [
                ['tool' => 'Bash', 'reason' => 'Hook denied: rm -rf is not allowed'],
                ['tool' => 'Write', 'reason' => 'Permission denied: /etc/passwd'],
            ],
            $this->documentFrom($backend, NonInteractive::EXIT_OK)['refusals'],
        );
    }

    /**
     * THE REFUSALS RIDE ON THE ERROR DOCUMENT TOO.
     *
     * A turn can block a call and then have the backend throw, and that
     * consumer has LESS other information than the successful one, not more.
     * Attaching the array only to the success document would drop it from
     * exactly the runs it matters most on.
     */
    public function testARefusalRaisedBeforeTheBackendThrowsStillReachesTheDocument(): void
    {
        $backend = $this->backendEmitting(
            [['Bash', 'Hook denied: rm -rf is not allowed', true]],
            throw: new \RuntimeException('provider went away'),
        );

        $document = $this->documentFrom($backend, NonInteractive::EXIT_FAILURE);

        self::assertNull($document['result']);
        self::assertSame('backend', $document['error']['type']);
        self::assertSame(
            [['tool' => 'Bash', 'reason' => 'Hook denied: rm -rf is not allowed']],
            $document['refusals'],
        );
    }

    /**
     * `--output-format text` IS UNTOUCHED, AND IS THE ONE PLACE A REFUSAL CAN
     * STILL GO MISSING ENTIRELY.
     *
     * WHAT THIS DOC-BLOCK SAID: that the format "does not need touching: on
     * that format the operator is at the terminal, where
     * {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt} has already
     * written every refusal to stderr". WHAT IS TRUE NOW: that class settles an
     * ASK, and nothing else ever reaches it — a plain hook DENY returns out of
     * {@see \SugarCraft\Crush\Runtime::gate()} before `settleAsk()` is
     * called, and `Runtime` writes to stderr nowhere. So the refusal
     * {@see testARealEngineTurnPutsItsBlockedToolCallInTheJsonDocument()}
     * exercises — the shipped gate stopping `rm -rf ./build`, which is the
     * commonest refusal there is — reaches NEITHER channel under `text`.
     * MEASURED on PHP 8.3.6: that turn writes zero bytes to stderr.
     *
     * WHY THIS TEST STILL EARNS ITS PLACE, and why it is not marked incomplete:
     * the assertion is not "the operator is informed". It is that stdout under
     * `text` is the answer and NOTHING ELSE, which is the contract a shell
     * pipeline depends on and the reason the refusal list was not simply
     * printed here. That contract is worth pinning whichever way the gap is
     * closed, and closing it belongs on the DENY path in `Runtime`. If a stderr
     * line lands there, this test keeps passing — which is the point.
     */
    public function testTextFormatStillPrintsTheAnswerAndNothingElse(): void
    {
        $backend = $this->backendEmitting([['Bash', 'Hook denied: rm -rf is not allowed', true]]);

        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['sugarcrush', '-p', 'go']),
            $backend,
            NonInteractive::FORMAT_TEXT,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertSame("the answer\n", $stdout);
    }

    /**
     * THE HALF-CHANNEL, PINNED — because four doc-blocks and a README paragraph
     * now rest on it.
     *
     * WHAT THOSE FIVE PLACES USED TO SAY: that `--output-format text` needs no
     * refusal list because "every refusal is already on stderr". Round 47
     * measured that false. The sentence was true of
     * {@see \SugarCraft\Crush\Cli\HeadlessPermissionPrompt}, which writes its
     * four shapes to stderr, and was generalised to every refusal — but that
     * class settles an ASK and is reached from nowhere else, while
     * {@see \SugarCraft\Crush\Runtime::gate()} returns a plain DENY before
     * `settleAsk()` is ever called. So the commonest refusal there is reaches
     * NEITHER channel under `text`.
     *
     * THE MECHANISM HALF OF THAT CLAIM IS "`Runtime` WRITES TO STDERR NOWHERE",
     * and a sentence in a doc-block cannot red. This can. The day someone adds
     * the missing deny-path line — which is the right fix and is on the
     * hardening backlog — this test reds, and it reds pointing at the five
     * places that describe the gap, so the fix and the prose land together
     * instead of the prose rotting into a second wrong promise. That is the
     * only reason it is an assertion about SOURCE rather than about behaviour:
     * the behaviour is an absence, and an absence observed in one turn does not
     * generalise to the ones the suite does not run.
     *
     * @see testTextFormatStillPrintsTheAnswerAndNothingElse() for the contract
     *      that keeps the fix off stdout.
     */
    public function testRuntimeWritesNothingToStderrSoATextFormatDenialIsSilent(): void
    {
        // KNOWN-POSITIVE FIRST (rule 15): an absence is not evidence unless the
        // same test shows the scanner can still find a presence. Assembled from
        // parts so this file cannot be scanned into reddening on its own
        // fixture if the scan set is ever widened past Runtime.
        $err = 'STD' . 'ERR';
        self::assertSame(
            [$err],
            self::stderrWritesIn("<?php \fwrite(\{$err}, \$e->getMessage());
"),
            'the stderr scanner can no longer see a write it is looking straight at; the absence asserted '
            . 'below proves nothing',
        );
        self::assertSame(
            ['php://stderr'],
            self::stderrWritesIn("<?php \$h = fopen('php://stderr', 'w');
"),
        );
        self::assertSame([], self::stderrWritesIn("<?php echo 'on stdout';
"));

        $runtime = @file_get_contents(\dirname(__DIR__, 2) . '/src/Runtime.php');
        if ($runtime === false) {
            // Loud, never "it is fine": this guard cannot answer for a file it
            // could not read.
            throw new \RuntimeException('src/Runtime.php could not be read; this guard cannot answer for it');
        }

        self::assertSame(
            [],
            self::stderrWritesIn($runtime),
            'Runtime now writes to stderr. If that write is the deny-path refusal line, this is the good '
            . 'news — but NonInteractive::run(), NonInteractive::format(), NonInteractive::emitErrorDocument(), '
            . 'this file\'s text-format test and README.md all state that no such line exists, and all five '
            . 'are now wrong. Update them in the same change',
        );
    }

    /**
     * Every construct in `$source` that writes to the standard error stream.
     *
     * Deliberately syntactic and deliberately broad — the constant, the stream
     * wrapper, and `error_log()`'s default sink all reach the same place, and a
     * scanner that knew only the first would answer "nothing" for the other two.
     *
     * @return list<string>
     */
    private static function stderrWritesIn(string $source): array
    {
        preg_match_all('/STDERR|php:\/\/stderr|\berror_log\s*\(/i', $source, $matches);

        return $matches[0];
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * Run one `-p … --output-format json` turn and decode the single object it
     * puts on stdout.
     *
     * @return array<string, mixed>
     */
    private function documentFrom(Backend $backend, int $expectedCode): array
    {
        ob_start();
        $code = NonInteractive::run(
            ArgvParser::parse(['sugarcrush', '-p', 'go']),
            $backend,
            NonInteractive::FORMAT_JSON,
        );
        $stdout = (string) ob_get_clean();

        self::assertSame($expectedCode, $code, "stdout was:\n" . $stdout);

        $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'stdout was not one JSON object: ' . $stdout);

        return $decoded;
    }

    /**
     * A backend that replays a fixed list of tool outcomes through `$onEvent`
     * and then answers (or throws).
     *
     * Each entry is `[tool name, result text, isError]`. A {@see ToolStarted}
     * is emitted alongside every one, because that is what a real engine does
     * and a classifier that only works when handed nothing else is not being
     * tested.
     *
     * @param list<array{0: string, 1: string, 2: bool}> $outcomes
     */
    private function backendEmitting(array $outcomes, ?\Throwable $throw = null): Backend
    {
        return new class ($outcomes, $throw) implements Backend {
            /** @param list<array{0: string, 1: string, 2: bool}> $outcomes */
            public function __construct(
                private readonly array $outcomes,
                private readonly ?\Throwable $throw,
            ) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                foreach ($this->outcomes as $i => [$name, $text, $isError]) {
                    $call = new ToolCall('c' . $i, $name, []);
                    if ($onEvent === null) {
                        continue;
                    }
                    $onEvent(ToolStarted::fromCall($call));
                    $onEvent(ToolFinished::fromResult(
                        $call,
                        new ToolResult(toolCallId: $call->id(), content: $text, isError: $isError),
                    ));
                }

                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return Message::assistant('the answer');
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
            }
        };
    }

    /**
     * Two provider round-trips: the first asks to delete a build tree, the
     * second answers after seeing what happened to that request. Copied in
     * shape from {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest}'s
     * own deny fixture, deliberately — the point is that the SHIPPED hook gate
     * stops it, so the fixture has to be one the shipped gate recognises.
     */
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

    /**
     * A tool named `Bash` that records whether it ran but never runs a shell.
     *
     * `$executed` IS ASSERTED ON, by both engine tests. It was not when this
     * spy landed — it was set and never read, which made "records whether it
     * ran" a description of a field nothing consulted.
     */
    private function bashSpyTool(): Tool
    {
        return new class implements Tool {
            public bool $executed = false;

            public function name(): string { return 'Bash'; }
            public function description(): string { return 'spy bash'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                $this->executed = true;

                return new ToolResult(toolCallId: '', content: 'ran');
            }
        };
    }
}
