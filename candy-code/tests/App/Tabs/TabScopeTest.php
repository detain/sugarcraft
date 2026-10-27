<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App\Tabs;

use Closure;
use PHPUnit\Framework\TestCase;
use React\Promise;
use RuntimeException;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\Kind;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Msg\SubscriptionsMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\PrintMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Screen;
use SugarCraft\Core\SequenceMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\View;
use SugarCraft\Code\App\Tabs\TabScope;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use Throwable;

/**
 * Envelope contract for {@see TabScope}: every Msg a tab's lazily-wrapped
 * Cmd produces must reach the loop stamped with its deck key — EXCEPT
 * the sentinels Program::dispatch() intercepts for the runtime, which
 * must arrive unwrapped or the runtime silently swallows them.
 *
 * The sentinel half is pinned twice over: per-class identity asserts for
 * everything TabScope claims to pass through, and a dynamic sweep of
 * Program's own `instanceof` ladder, so a sentinel added on the core
 * side reddens this file before it can swallow a quit from a parked tab.
 *
 * @internal
 *
 * @see TabScope
 */
final class TabScopeTest extends TestCase
{
    private const SCOPE_KEY = 'tabA';

    public function testANullCmdAndANullMsgBothStayNull(): void
    {
        $this->assertNull(TabScope::cmd(self::SCOPE_KEY, null));
        $this->assertNull(TabScope::msg(self::SCOPE_KEY, null));
    }

    public function testAPlainModelMsgIsEnvelopedWithItsTabKey(): void
    {
        $inner = $this->scopeProbeMsg();

        $wrapped = TabScope::msg(self::SCOPE_KEY, $inner);

        $this->assertInstanceOf(TabScopedMsg::class, $wrapped);
        $this->assertSame(self::SCOPE_KEY, $wrapped->tabKey());
        $this->assertSame($inner, $wrapped->inner());
    }

    public function testAWrappedCmdStampsTheMsgItsClosureReturns(): void
    {
        $inner = $this->scopeProbeMsg();
        $scoped = (TabScope::cmd(self::SCOPE_KEY, static fn (): ?Msg => $inner))();

        $this->assertInstanceOf(TabScopedMsg::class, $scoped);
        $this->assertSame($inner, $scoped->inner());
    }

    public function testABatchKeepsItsShapeAndWrapsEveryLeaf(): void
    {
        $first = $this->scopeProbeMsg();
        $second = $this->scopeProbeMsg();
        $batch = new BatchMsg([
            static fn (): Msg => $first,
            static fn (): Msg => $second,
        ]);

        $mapped = TabScope::msg(self::SCOPE_KEY, $batch);

        $this->assertInstanceOf(BatchMsg::class, $mapped);
        $this->assertCount(2, $mapped->cmds);

        foreach ([[$mapped->cmds[0], $first], [$mapped->cmds[1], $second]] as [$cmd, $expected]) {
            $result = $cmd();
            $this->assertInstanceOf(TabScopedMsg::class, $result);
            $this->assertSame($expected, $result->inner());
            $this->assertSame(self::SCOPE_KEY, $result->tabKey());
        }
    }

    public function testASequenceKeepsItsOrderingOverWrappedLeaves(): void
    {
        $inner = $this->scopeProbeMsg();
        $sequence = new SequenceMsg([static fn (): Msg => $inner]);

        $mapped = TabScope::msg(self::SCOPE_KEY, $sequence);

        $this->assertInstanceOf(SequenceMsg::class, $mapped);
        $this->assertInstanceOf(TabScopedMsg::class, ($mapped->cmds[0])());
        $this->assertSame($inner, (($mapped->cmds[0])())->inner());
    }

    public function testATickRequestKeepsItsDelayAndScopesItsProducer(): void
    {
        $inner = $this->scopeProbeMsg();
        $tick = new TickRequest(0.25, static fn (): Msg => $inner);

        $mapped = TabScope::msg(self::SCOPE_KEY, $tick);

        $this->assertInstanceOf(TickRequest::class, $mapped);
        $this->assertSame(0.25, $mapped->seconds);
        $produced = ($mapped->produce)();
        $this->assertInstanceOf(TabScopedMsg::class, $produced);
        $this->assertSame($inner, $produced->inner());
    }

    public function testDeeplyNestedProducerTreesAreScopedAtTheLeaf(): void
    {
        $leaf = $this->scopeProbeMsg();
        $nested = new BatchMsg([
            static fn (): Msg => new TickRequest(1.0, static fn (): Msg => $leaf),
        ]);

        $mapped = (TabScope::cmd(self::SCOPE_KEY, static fn (): Msg => $nested))();

        $this->assertInstanceOf(BatchMsg::class, $mapped);
        $tick = ($mapped->cmds[0])();
        $this->assertInstanceOf(TickRequest::class, $tick);
        $result = ($tick->produce)();
        $this->assertInstanceOf(TabScopedMsg::class, $result);
        $this->assertSame($leaf, $result->inner());
    }

    public function testAnAsyncCmdResolvesIntoScopedMsgs(): void
    {
        $inner = $this->scopeProbeMsg();
        $mapped = TabScope::msg(self::SCOPE_KEY, new AsyncCmd(Promise\resolve($inner)));

        $this->assertInstanceOf(AsyncCmd::class, $mapped);

        $captured = null;
        /** @psalm-suppress UndefinedInterfaceMethod - react/promise v3 PromiseInterface::then */
        $mapped->promise->then(static function (?Msg $resolved) use (&$captured): void {
            $captured = $resolved;
        });

        $this->assertInstanceOf(TabScopedMsg::class, $captured);
        $this->assertSame($inner, $captured->inner());
    }

    public function testAnAsyncRejectionBecomesAScopedExceptionMsgForTheOwningTab(): void
    {
        $failure = new RuntimeException('provider exploded mid-turn');
        $mapped = TabScope::msg(self::SCOPE_KEY, new AsyncCmd(Promise\reject($failure)));

        $this->assertInstanceOf(AsyncCmd::class, $mapped);

        $captured = null;
        $rejected = false;
        $mapped->promise->then(
            static function (mixed $resolved) use (&$captured): void {
                $captured = $resolved;
            },
            static function (Throwable $e) use (&$rejected): void {
                $rejected = true;
            },
        );

        // The reject leg must have HANDLED the failure into a scoped
        // resolve; an unsettled rejection reaching the loop would trip
        // Program's raw ExceptionMsg path on the wrong tab.
        $this->assertFalse($rejected);
        $this->assertInstanceOf(TabScopedMsg::class, $captured);
        $this->assertSame(self::SCOPE_KEY, $captured->tabKey());
        $this->assertInstanceOf(ExceptionMsg::class, $captured->inner());
        $this->assertSame($failure, $captured->inner()->exception);
    }

    public function testEveryDispatchSentinelPassesThroughUnwrapped(): void
    {
        $sentinels = [
            new RawMsg("\x1b[0m"),
            new PrintMsg('notice'),
            new ExecRequest('printf ready'),
            new SuspendMsg(),
            new InterruptMsg(),
            new QuitMsg(),
            new WindowSizeMsg(80, 24),
            new SubscriptionsMsg(new Subscriptions()),
            new ScreenStackPushedMsg(new Screen($this->parkedTabModelStub())),
            new ScreenStackPoppedMsg(),
        ];

        foreach ($sentinels as $sentinel) {
            $this->assertSame(
                $sentinel,
                TabScope::msg(self::SCOPE_KEY, $sentinel),
                $sentinel::class . ' must reach Program unwrapped — an enveloped sentinel is a swallowed runtime event.',
            );
        }

        $this->assertCount(\count(TabScope::PASS_THROUGH), $sentinels);
    }

    public function testTheStructuralSetMatchesTheSentinelClassesMappedNotSkipped(): void
    {
        $expected = ['AsyncCmd', 'BatchMsg', 'SequenceMsg', 'TickRequest'];
        $actual = array_map(
            static fn (string $class): string => substr($class, strrpos($class, '\\') === false ? 0 : strrpos($class, '\\') + 1),
            TabScope::STRUCTURAL,
        );
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Sweep Program's own dispatch ladder for every `$msg instanceof`
     * shape it intercepts, and refuse to let any of them be unknown to
     * TabScope. This is the loud half of the sentinel pin: per-class
     * asserts above prove the set we KNOW is honoured, this one proves
     * nobody ADDED a shape underneath us.
     */
    public function testProgramInspectsNoDispatchShapeThatTabScopeDoesNotKnow(): void
    {
        $filename = (new \ReflectionClass(Program::class))->getFileName();
        $this->assertNotFalse($filename);
        $source = file_get_contents($filename);
        $this->assertNotFalse($source);

        // The dispatch ladder names its subject `$msg` throughout
        // sugar-core/src/Program.php; every intercept point is matched by
        // that spelling, while model-shape checks read `$this->model`.
        $matched = preg_match_all('/\$msg\s+instanceof\s+([A-Za-z0-9_]+)/', $source, $hits);
        $this->assertIsInt($matched);
        $this->assertGreaterThan(0, $matched, 'the dispatch sweep found nothing to inspect, so it proves nothing — Program::dispatch() has been rewritten and TabScope must be re-derived from it.');

        $known = [];
        foreach ([...TabScope::PASS_THROUGH, ...TabScope::STRUCTURAL] as $class) {
            $known[substr($class, (int) strrpos($class, '\\') + 1)] = $class;
        }

        $unknown = array_values(array_unique(array_filter(
            $hits[1],
            static fn (string $short): bool => !isset($known[$short]),
        )));

        $this->assertSame(
            [],
            $unknown,
            'Program dispatch-inspects ' . implode(', ', $unknown) . ' but TabScope treats it as an ordinary model Msg, so a wrapped copy would be silently swallowed. Classify it in PASS_THROUGH or STRUCTURAL (with mapping) before shipping that shape.',
        );
    }

    public function testSubscriptionsAreRekeyedAndTheirProducersScoped(): void
    {
        $leaf = $this->scopeProbeMsg();
        $subscriptions = (new Subscriptions())
            ->withTick('poll', 0.5, static fn (): Msg => $leaf)
            ->withCustom('done', [], static fn (): ?Msg => null);

        $mapped = TabScope::subscriptions(self::SCOPE_KEY, $subscriptions);

        $this->assertNotNull($mapped);
        $all = $mapped->all();
        $this->assertCount(2, $all);
        $this->assertSame('tab:tabA:poll', $all[0]->id);
        $this->assertSame(Kind::Tick, $all[0]->kind);
        $this->assertSame(['seconds' => 0.5], $all[0]->params);

        $fired = ($all[0]->produce)();
        $this->assertInstanceOf(TabScopedMsg::class, $fired);
        $this->assertSame(self::SCOPE_KEY, $fired->tabKey());
        $this->assertSame($leaf, $fired->inner());

        // The null-producing leaf stays null through the wrapper, so a
        // skipped event is skipped, not enveloped into nothingness.
        $this->assertNull(($all[1]->produce)());
    }

    public function testForegroundOnlyIdsAreMatchedBeforeTheRekeyAndDropped(): void
    {
        $subscriptions = (new Subscriptions())
            ->withTick('statusLine', 1.0, static fn (): Msg => new RawMsg(''))
            ->withTick('keep', 2.0, static fn (): Msg => new RawMsg(''));

        $mapped = TabScope::subscriptions(self::SCOPE_KEY, $subscriptions, ['statusLine']);

        $this->assertNotNull($mapped);
        $this->assertSame(['tab:tabA:keep'], array_map(static fn ($s): string => $s->id, $mapped->all()));
        // The re-keyed spelling must not accidentally suppress anything
        // either — the filter runs against ORIGINAL ids only.
        $this->assertFalse($mapped->has('statusLine'));
    }

    public function testANullSubscriptionSetStaysNull(): void
    {
        $this->assertNull(TabScope::subscriptions(self::SCOPE_KEY, null));
    }

    /**
     * A model Msg the App might actually route: an anonymous CoreMsg
     * carrier, distinct from every sentinel so it must be enveloped.
     */
    private function scopeProbeMsg(): Msg
    {
        return new class implements Msg {};
    }

    /**
     * Minimal Model so a Screen instance can be constructed for the
     * screen-stack sentinel pin.
     */
    private function parkedTabModelStub(): Model
    {
        return new class implements Model {
            public function init(): ?Closure
            {
                return null;
            }

            public function update(Msg $msg): array
            {
                return [$this, null];
            }

            public function view(): string|View
            {
                return '';
            }

            public function subscriptions(): ?Subscriptions
            {
                return null;
            }
        };
    }
}
