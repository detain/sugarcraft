<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\ProgramOptions\ProgramOptionsBuilder;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Subscriptions;

/** Records every Msg it is handed and reacts to the keys the tests send. */
final class TimeoutProbeModel implements Model
{
    use SubscriptionCapable;

    /** @var list<Msg> */
    public array $seen = [];

    /** @param \Closure(): \React\Promise\PromiseInterface<?Msg> $factory */
    public function __construct(
        public readonly ?\Closure $factory = null,
        public readonly ?float $timeout = null,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        $next = clone $this;
        $next->seen = [...$this->seen, $msg];
        if ($msg instanceof KeyMsg && $msg->rune === 'p' && $this->factory !== null) {
            return [$next, Cmd::promise($this->factory, $this->timeout)];
        }
        if ($msg instanceof KeyMsg && $msg->rune === 'w') {
            return [$next, Cmd::windowSize()];
        }
        if ($msg instanceof KeyMsg && $msg->rune === 's') {
            // A timed promise that owns its failure: the timeout must dispatch
            // the mapped Msg, never the raw ExceptionMsg.
            return [$next, static fn (): AsyncCmd => new AsyncCmd(
                (new Deferred())->promise(),
                0.05,
                static fn (\Throwable $e): Msg => new KeyMsg(KeyType::Char, 'scoped'),
            )];
        }
        return [$next, null];
    }

    public function view(): string
    {
        return '';
    }
}

final class CmdPromiseTimeoutTest extends TestCase
{
    /** @return array{0:resource, 1:resource, 2:resource} */
    private function pipes(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    private function options($in, $out, StreamSelectLoop $loop, ?array $windowSize = null): ProgramOptions
    {
        return new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
            windowSize: $windowSize,
        );
    }

    public function testAPromiseStillPendingAtTheDeadlineDispatchesATimeoutException(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        // A promise nobody ever settles: only the timeout can end the wait.
        $factory = static fn () => (new Deferred())->promise();
        $model = new TimeoutProbeModel($factory, 0.05);
        $program = new Program($model, $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 'p'));
        $loop->addTimer(0.5, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $final = $program->run();

        $errors = array_values(array_filter($final->seen, static fn ($m) => $m instanceof ExceptionMsg));
        $this->assertCount(1, $errors, 'an expired promise must dispatch exactly one ExceptionMsg');
        $this->assertStringContainsString('timed out', $errors[0]->exception->getMessage());
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testATimeoutDispatchesTheOnFailureMsgInsteadOfTheRawExceptionMsg(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $program = new Program(new TimeoutProbeModel(), $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 's'));
        $loop->addTimer(0.5, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $final = $program->run();

        $this->assertSame([], array_values(array_filter($final->seen, static fn ($m) => $m instanceof ExceptionMsg)));
        $scoped = array_values(array_filter(
            $final->seen,
            static fn ($m) => $m instanceof KeyMsg && $m->rune === 'scoped',
        ));
        $this->assertCount(1, $scoped, 'the timeout must reach the model as the onFailure Msg');
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testNonFrameOutputIsDroppedOnceTheBacklogSaturatesAndReportedOnce(): void
    {
        // A pipe whose reader never reads stalls for real: a unix socket pair
        // accepts far more than the 8 MiB threshold before blocking, and
        // php://memory never blocks at all. Raw output is 64 KiB per message.
        [$in, , $writer] = $this->pipes();
        $proc = proc_open(['sleep', '30'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $procPipes);
        $this->assertIsResource($proc);
        [$out] = $procPipes;
        $loop = new StreamSelectLoop();
        $program = new Program(new TimeoutProbeModel(), $this->options($in, $out, $loop));

        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 200; $i++) {
            $program->send(new RawMsg($chunk));
        }
        $loop->addTimer(0.5, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $final = $program->run();

        $overflows = array_values(array_filter(
            $final->seen,
            static fn ($m) => $m instanceof ExceptionMsg && str_contains($m->exception->getMessage(), 'backlog is full'),
        ));
        $this->assertCount(1, $overflows, 'a saturated backlog reports exactly once per episode');
        proc_terminate($proc);
        foreach ($procPipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($proc);
        fclose($writer);
        fclose($in);
    }

    public function testAPromiseThatSettlesInTimeDispatchesItsResultAndNoTimeout(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $factory = static function () use (&$deferred) {
            $deferred = new Deferred();
            return $deferred->promise();
        };
        $model = new TimeoutProbeModel($factory, 1.0);
        $program = new Program($model, $this->options($in, $out, $loop));

        $program->send(new KeyMsg(KeyType::Char, 'p'));
        $loop->addTimer(0.05, static function () use (&$deferred): void {
            $deferred?->resolve(new KeyMsg(KeyType::Char, 'done'));
        });
        // Past the 1 s deadline: a timer that was not cancelled would reject here.
        $loop->addTimer(1.3, static fn () => $program->quit());
        $loop->addTimer(3.0, static fn () => $loop->stop());
        $final = $program->run();

        $this->assertSame([], array_values(array_filter($final->seen, static fn ($m) => $m instanceof ExceptionMsg)));
        $results = array_values(array_filter(
            $final->seen,
            static fn ($m) => $m instanceof KeyMsg && $m->rune === 'done',
        ));
        $this->assertCount(1, $results, 'the settled result is delivered once');
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testPromiseWithoutATimeoutStaysUnbounded(): void
    {
        $factory = static fn () => (new Deferred())->promise();
        $cmd = Cmd::promise($factory);
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $this->assertNull($async->timeoutSeconds);
    }

    public function testPromiseCarriesItsTimeoutOnTheAsyncCmd(): void
    {
        $factory = static fn () => (new Deferred())->promise();
        $async = (Cmd::promise($factory, 2.5))();
        $this->assertInstanceOf(AsyncCmd::class, $async);
        $this->assertSame(2.5, $async->timeoutSeconds);
    }

    public function testPromiseRefusesANonPositiveOrNonFiniteTimeoutAtTheCallSite(): void
    {
        // A zero or negative bound would reject real pending work on the next
        // loop turn, so the factory refuses it where the value was computed.
        $factory = static fn () => (new Deferred())->promise();
        foreach ([0.0, -1.0, NAN, INF, -INF] as $bad) {
            try {
                Cmd::promise($factory, $bad);
                $this->fail('a timeout of ' . var_export($bad, true) . ' must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('timeout', $e->getMessage());
            }
        }
    }

    public function testPromiseAcceptsAPositiveTimeoutOrNoneAtAll(): void
    {
        $factory = static fn () => (new Deferred())->promise();
        $this->assertNull((Cmd::promise($factory, null))()->timeoutSeconds);
        $this->assertSame(0.001, (Cmd::promise($factory, 0.001))()->timeoutSeconds);
    }

    public function testWindowSizeCmdDispatchesTheForcedSize(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $model = new TimeoutProbeModel();
        $program = new Program($model, $this->options($in, $out, $loop, ['cols' => 132, 'rows' => 43]));

        $program->send(new KeyMsg(KeyType::Char, 'w'));
        $loop->addTimer(0.2, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $final = $program->run();

        $sizes = array_values(array_filter($final->seen, static fn ($m) => $m instanceof WindowSizeMsg));
        $this->assertGreaterThanOrEqual(2, count($sizes), 'startup size plus the Cmd-requested one');
        $last = $sizes[array_key_last($sizes)];
        $this->assertSame(132, $last->cols);
        $this->assertSame(43, $last->rows);
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testWindowSizeCmdIsAnUnresolvedSentinelUntilTheProgramRunsIt(): void
    {
        $request = (Cmd::windowSize())();
        $this->assertInstanceOf(\SugarCraft\Core\WindowSizeRequest::class, $request);
    }

    public function testEscapeDelayDefaultsToFiftyMillisecondsAndIsValidated(): void
    {
        $this->assertSame(0.05, (new ProgramOptions())->escapeDelay);
        $this->assertSame(0.0, (new ProgramOptions(escapeDelay: 0.0))->escapeDelay);
        $this->assertSame(0.3, (new ProgramOptions(escapeDelay: 0.3))->escapeDelay);

        $this->expectException(\InvalidArgumentException::class);
        new ProgramOptions(escapeDelay: -0.01);
    }

    public function testBuilderSetsEscapeDelayAndRefusesInvalidValues(): void
    {
        $built = (new ProgramOptionsBuilder())->withEscapeDelay(0.2)->build();
        $this->assertSame(0.2, $built->escapeDelay);
        $this->assertSame(0.05, (new ProgramOptionsBuilder())->build()->escapeDelay);

        $this->expectException(\InvalidArgumentException::class);
        (new ProgramOptionsBuilder())->withEscapeDelay(INF);
    }

    public function testSubscriptionsAreReadAfterEveryUpdateEvenForTheSameIdentity(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $model = new SubscriptionCountingModel(returnsSelf: true);
        $program = new Program($model, $this->options($in, $out, $loop));

        for ($i = 0; $i < 5; $i++) {
            $program->send(new KeyMsg(KeyType::Char, 'x'));
        }
        $loop->addTimer(0.2, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        // The model returns $this from every update, so the identity never
        // changes. A model may still change what it wants between updates
        // (Codex P2 on #1523), so the declared set is re-read every cycle:
        // the initial collection plus one per message. Reconciliation only
        // re-arms what actually changed.
        $this->assertGreaterThanOrEqual(6, $model->counter->calls);
        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testChangingModelIdentityRecollectsSubscriptions(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $model = new SubscriptionCountingModel(returnsSelf: false);
        $program = new Program($model, $this->options($in, $out, $loop));

        for ($i = 0; $i < 5; $i++) {
            $program->send(new KeyMsg(KeyType::Char, 'x'));
        }
        $loop->addTimer(0.2, static fn () => $program->quit());
        $loop->addTimer(2.0, static fn () => $loop->stop());
        $program->run();

        // A new instance per update must not be served a stale collection.
        $this->assertGreaterThan(1, $model->counter->calls);
        fclose($writer);
        fclose($in);
        fclose($out);
    }
}

/** Shared call counter for the subscriptions memo tests. */
final class SubscriptionCallCounter
{
    public int $calls = 0;
}

/** Model whose subscriptions() call count the memo tests read. */
final class SubscriptionCountingModel implements Model
{
    public function __construct(
        private readonly bool $returnsSelf,
        public readonly SubscriptionCallCounter $counter = new SubscriptionCallCounter(),
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($this->returnsSelf) {
            return [$this, null];
        }
        return [clone $this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?Subscriptions
    {
        $this->counter->calls++;
        return null;
    }
}
