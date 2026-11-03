<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Kind;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscription;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\SubscriptionCapable;
use React\EventLoop\StreamSelectLoop;

/**
 * Tests for Elm-style subscription reconciliation.
 *
 * Verifies that:
 * - New subscriptions are started when the model declares them
 * - Dropped subscriptions are cancelled
 * - Stable subscriptions are kept across update cycles
 * - Program does not double-fire ticks after reconciliation
 */
final class SubscriptionsReconcileTest extends TestCase
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

    public function testSubscriptionsHasWithTickAndHas(): void
    {
        $subs = (new Subscriptions())
            ->withTick('a', 1.0, static fn () => null)
            ->withTick('b', 2.0, static fn () => null);

        $this->assertTrue($subs->has('a'));
        $this->assertTrue($subs->has('b'));
        $this->assertFalse($subs->has('c'));
        $this->assertCount(2, $subs->all());
    }

    public function testSubscriptionsWithKeyAndSignal(): void
    {
        $signo = defined('SIG_HUP') ? SIG_HUP : 1;
        $subs = (new Subscriptions())
            ->withKey('key-sub', static fn () => null)
            ->withSignal('sig-hup', $signo, static fn () => null);

        $this->assertTrue($subs->has('key-sub'));
        $this->assertTrue($subs->has('sig-hup'));
        $all = $subs->all();
        $this->assertCount(2, $all);
        $this->assertSame(Kind::Key, $all[0]->kind);
        $this->assertSame(Kind::Signal, $all[1]->kind);
    }

    public function testSubscriptionsWithCustom(): void
    {
        $subs = (new Subscriptions())->withCustom(
            'custom',
            ['interval' => 0.5],
            static fn () => null,
        );

        $all = $subs->all();
        $this->assertCount(1, $all);
        $this->assertSame(Kind::Custom, $all[0]->kind);
        $this->assertSame(0.5, $all[0]->params['interval']);
    }

    public function testSubscriptionKindEnum(): void
    {
        $this->assertSame('Tick', Kind::Tick->name);
        $this->assertSame('Key', Kind::Key->name);
        $this->assertSame('Signal', Kind::Signal->name);
        $this->assertSame('Custom', Kind::Custom->name);
    }

    public function testSubscribeCmdProducesSubscriptionsMsg(): void
    {
        $subs = (new Subscriptions())->withTick('test', 1.0, static fn () => null);
        $cmd = new \SugarCraft\Core\Cmd\SubscribeCmd($subs);
        $result = $cmd();

        $this->assertInstanceOf(\SugarCraft\Core\Msg\SubscriptionsMsg::class, $result);
        $this->assertSame($subs, $result->subscriptions);
    }

    public function testModelWithoutSubscriptionsReturnsNull(): void
    {
        $model = new class () implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(\SugarCraft\Core\Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
        };

        $this->assertNull($model->subscriptions());
    }

    public function testModelWithTickSubscription(): void
    {
        $model = new class () implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public const TICK_ID = 'test-tick';
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(\SugarCraft\Core\Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
            public function subscriptions(): ?Subscriptions
            {
                return (new Subscriptions())->withTick(self::TICK_ID, 0.1, static fn () => null);
            }
        };

        $subs = $model->subscriptions();
        $this->assertNotNull($subs);
        $this->assertCount(1, $subs->all());
        $tickSub = $subs->all()[0];
        $this->assertSame('test-tick', $tickSub->id);
        $this->assertSame(Kind::Tick, $tickSub->kind);
        $this->assertSame(0.1, $tickSub->params['seconds']);
    }

    public function testProgramStartsSubscriptionWhenModelRequests(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        // Track ticks received.
        $receivedTicks = [];

        $model = new class ($receivedTicks) implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public const TICK_ID = 'model-tick';

            /** @var list<object> */
            private array $ticks;

            public function __construct(array &$ticks)
            {
                $this->ticks = &$ticks;
            }

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(\SugarCraft\Core\Msg $msg): array
            {
                if ($msg instanceof \SugarCraft\Core\Msg\WindowSizeMsg) {
                    $this->ticks[] = $msg;
                }
                return [$this, null];
            }

            public function view(): string
            {
                return '';
            }

            public function subscriptions(): ?Subscriptions
            {
                return (new Subscriptions())->withTick(self::TICK_ID, 0.1, static fn () => new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
            }
        };

        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 60.0,
            input: $in,
            output: $out,
            loop: $loop,
            subscriptions: static fn ($m) => $m->subscriptions(),
        ));

        $loop->addTimer(0.3, static fn () => $loop->stop());
        $program->run();

        $this->assertNotEmpty($receivedTicks, 'Should have received at least one tick');
        fclose($writer);
        fclose($in);
    }

    /**
     * The Model interface REQUIRES subscriptions(), so the Program must honor a
     * model's declared subscriptions even when no `subscriptions:` closure is
     * passed to ProgramOptions. Otherwise every consumer has to remember to wire
     * `subscriptions: fn($m) => $m->subscriptions()` by hand or their ticks
     * silently never fire (regression: candy-sql admin polling).
     */
    public function testProgramStartsSubscriptionWithoutExplicitOptionClosure(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();

        $receivedTicks = [];

        // A unique marker message so the startup WindowSizeMsg can't be mistaken
        // for a tick — only the subscription's produce() emits this type.
        $tickMsg = new class () implements \SugarCraft\Core\Msg {};

        $model = new class ($receivedTicks, $tickMsg) implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public const TICK_ID = 'default-tick';

            /** @var list<object> */
            private array $ticks;
            private \SugarCraft\Core\Msg $tickMsg;

            public function __construct(array &$ticks, \SugarCraft\Core\Msg $tickMsg)
            {
                $this->ticks = &$ticks;
                $this->tickMsg = $tickMsg;
            }

            public function init(): ?\Closure
            {
                return null;
            }

            public function update(\SugarCraft\Core\Msg $msg): array
            {
                if ($msg === $this->tickMsg) {
                    $this->ticks[] = $msg;
                }
                return [$this, null];
            }

            public function view(): string
            {
                return '';
            }

            public function subscriptions(): ?Subscriptions
            {
                $tick = $this->tickMsg;
                return (new Subscriptions())->withTick(self::TICK_ID, 0.1, static fn () => $tick);
            }
        };

        // NOTE: no `subscriptions:` argument — the Program must default to
        // calling $model->subscriptions() on its own.
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 60.0,
            input: $in,
            output: $out,
            loop: $loop,
        ));

        $loop->addTimer(0.3, static fn () => $loop->stop());
        $program->run();

        $this->assertNotEmpty($receivedTicks, 'Model-declared tick should fire without an explicit subscriptions closure');
        fclose($writer);
        fclose($in);
    }

    /**
     * @group slow
     * @group subscription-timing
     *
     * NOTE: This test has a subtle timing edge case where the subscription
     * cancellation in the first run leaves a pending tick that fires in the
     * second run. The core subscription functionality works correctly;
     * this is a test infrastructure issue. Skipping for now.
     */
    public function testProgramCancelsSubscriptionWhenModelRemovesIt(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $model = new class () implements \SugarCraft\Core\Model {
            public int $cycles = 0;
            use SubscriptionCapable;
            public function init(): ?\Closure { return null; }
            public function update(\SugarCraft\Core\Msg $msg): array
            {
                $this->cycles++;
                return [$this, null];
            }
            public function view(): string { return ''; }
            public function subscriptions(): ?Subscriptions
            {
                return $this->cycles === 0
                    ? (new Subscriptions())->withTick('gone-next', 60.0, static fn () => null)
                    : null;
            }
        };
        $program = new Program($model, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));

        $reconcile = new \ReflectionMethod(Program::class, 'reconcileWantedSubscriptions');
        $activeProp = new \ReflectionProperty(Program::class, 'activeSubscriptions');

        $reconcile->invoke($program);
        self::assertCount(1, $activeProp->getValue($program), 'model request starts the tick');

        $model->cycles = 1; // subscriptions() now answers null
        $reconcile->invoke($program);
        self::assertCount(0, $activeProp->getValue($program), 'removal cancels the timer');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * M11 (lib-reviews-1102): while a screen is pushed, its OWN
     * subscriptions are the wanted set; after the pop, the root's are
     * again. Reverting to root-only consultation reddens both legs.
     */
    public function testNestedSubscriptionsFollowTheActiveScreen(): void
    {
        [$in, $out, $writer] = $this->pipes();

        $root = new class () implements \SugarCraft\Core\Model, \SugarCraft\Core\ScreenStackCapable {
            use SubscriptionCapable;
            public function __construct(public \SugarCraft\Core\ScreenStack $screens = new \SugarCraft\Core\ScreenStack()) {}
            public function screens(): \SugarCraft\Core\ScreenStack { return $this->screens; }
            public function init(): ?\Closure { return null; }
            public function update(\SugarCraft\Core\Msg $msg): array
            {
                if ($msg instanceof \SugarCraft\Core\Msg\ScreenStackPushedMsg) {
                    return [new self($this->screens->push($msg->screen)), null];
                }
                if ($msg instanceof \SugarCraft\Core\Msg\ScreenStackPoppedMsg) {
                    return [new self($this->screens->pop()), null];
                }
                return [$this, null];
            }
            public function view(): string { return 'root'; }
        };
        $screen = new class () implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public function init(): ?\Closure { return null; }
            public function update(\SugarCraft\Core\Msg $msg): array { return [$this, null]; }
            public function view(): string { return 'screen'; }
            public function subscriptions(): ?Subscriptions
            {
                return (new Subscriptions())->withTick('screen-tick', 60.0, static fn () => null);
            }
        };

        $program = new Program($root, new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));

        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');
        $reconcile = new \ReflectionMethod(Program::class, 'reconcileWantedSubscriptions');
        $activeProp = new \ReflectionProperty(Program::class, 'activeSubscriptions');

        $dispatch->invoke($program, new \SugarCraft\Core\Msg\ScreenStackPushedMsg(
            new \SugarCraft\Core\Screen($screen)
        ));
        $reconcile->invoke($program);
        $active = $activeProp->getValue($program);
        self::assertArrayHasKey('screen-tick', $active, 'the pushed screen owns the wanted set');

        $dispatch->invoke($program, new \SugarCraft\Core\Msg\ScreenStackPoppedMsg());
        $reconcile->invoke($program);
        self::assertCount(0, $activeProp->getValue($program), 'the base layer takes its subscriptions back');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * M12 (lib-reviews-1102): ids are the identity — a same-id-different-
     * closure reconciliation keeps the FIRST handle alive (the lock
     * hazard sugar-query once tripped over), and duplicate ids inside a
     * single set start exactly one timer.
     */
    public function testSameIdDifferentClosureKeepsTheFirstHandle(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $program = new Program($this->nullModel(), new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));
        $reconcile = new \ReflectionMethod(Program::class, 'reconcileSubscriptions');
        $activeProp = new \ReflectionProperty(Program::class, 'activeSubscriptions');

        $first = static fn () => null;
        $second = static fn () => null;
        $reconcile->invoke($program, (new Subscriptions())->withTick('same', 1.0, $first));
        $reconcile->invoke($program, (new Subscriptions())->withTick('same', 2.0, $second));

        $active = $activeProp->getValue($program);
        self::assertCount(1, $active, 'same-id re-reconcile does not start a second timer');
        self::assertSame(1.0, $active['same'][1]->params['seconds'], 'the FIRST handle stays live');

        $dupes = (new Subscriptions())->withTick('x', 3.0, $first)->withTick('x', 4.0, $second);
        self::assertCount(2, $dupes->all());
        $reconcile->invoke($program, $dupes);
        $active = $activeProp->getValue($program);
        self::assertSame(3.0, $active['x'][1]->params['seconds'], 'duplicate ids collapse to the first entry');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    /**
     * M13 (lib-reviews-1102): a SubscribeCmd-installed set is transient —
     * the next model reconciliation cancels anything the model itself
     * does not declare. This is documented behaviour; this arm pins it.
     */
    public function testCmdArmedSubscriptionsAreCancelledByTheNextModelReconcile(): void
    {
        [$in, $out, $writer] = $this->pipes();
        $program = new Program($this->nullModel(), new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));
        $dispatch = new \ReflectionMethod(Program::class, 'dispatch');
        $activeProp = new \ReflectionProperty(Program::class, 'activeSubscriptions');

        $cmd = new \SugarCraft\Core\Cmd\SubscribeCmd(
            (new Subscriptions())->withTick('cmd-armed', 60.0, static fn () => null)
        );
        $dispatch->invoke($program, $cmd());
        self::assertArrayHasKey('cmd-armed', $activeProp->getValue($program), 'the Cmd installs immediately');

        $dispatch->invoke($program, new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'k'));
        self::assertCount(0, $activeProp->getValue($program), 'model parity reclaims the Cmd-armed sub');

        fclose($writer);
        fclose($in);
        fclose($out);
    }

    public function testSubscriptionProducesCorrectMsg(): void
    {
        $producedMsg = null;
        $sub = new Subscription(
            'test',
            Kind::Tick,
            ['seconds' => 1.0],
            static fn () => new \SugarCraft\Core\Msg\QuitMsg(),
        );

        $produce = $sub->produce;
        $msg = $produce();

        $this->assertInstanceOf(\SugarCraft\Core\Msg\QuitMsg::class, $msg);
    }

    private function nullModel(): \SugarCraft\Core\Model
    {
        return new class () implements \SugarCraft\Core\Model {
            use SubscriptionCapable;
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(\SugarCraft\Core\Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
        };
    }

    /**
     * Cancelling a signal subscription must restore the handler that was
     * installed before it — not leave the subscription's handler wired up
     * (the leak) and not lose the predecessor. Reverting the prev-handler
     * tracking makes cancel restore SIG_DFL instead of the sentinel and fails.
     */
    public function testSignalSubscriptionCancelRestoresPreviousHandler(): void
    {
        if (!function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !defined('SIGUSR1')
        ) {
            $this->markTestSkipped('pcntl signal introspection not available');
        }

        // A sentinel handler installed BEFORE the subscription.
        $sentinel = static function (): void {
        };
        pcntl_signal(SIGUSR1, $sentinel);
        $this->assertSame($sentinel, pcntl_signal_get_handler(SIGUSR1));

        [$in, $out, $writer] = $this->pipes();
        $program = new Program($this->nullModel(), new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            input: $in,
            output: $out,
            loop: new StreamSelectLoop(),
        ));

        $reconcile = new \ReflectionMethod(Program::class, 'reconcileSubscriptions');
        $reconcile->setAccessible(true);

        // Install a signal subscription → our handler displaces the sentinel.
        $withSig = (new Subscriptions())->withSignal('usr1', SIGUSR1, static fn () => null);
        $reconcile->invoke($program, $withSig);
        $this->assertNotSame(
            $sentinel,
            pcntl_signal_get_handler(SIGUSR1),
            'the subscription handler should be installed',
        );

        // Reconcile to an empty set (cancel) → the sentinel must come back.
        $reconcile->invoke($program, new Subscriptions());
        $this->assertSame(
            $sentinel,
            pcntl_signal_get_handler(SIGUSR1),
            'the previous handler must be restored on cancel',
        );

        pcntl_signal(SIGUSR1, SIG_DFL);
        fclose($writer);
        fclose($in);
        fclose($out);
    }
}
