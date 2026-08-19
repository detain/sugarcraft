<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Message;

/**
 * @see ContextWindow
 */
final class ContextWindowTest extends TestCase
{
    public function testResolvePassesAPositiveWindowThrough(): void
    {
        $this->assertSame(196_608, ContextWindow::resolve(196_608));
        $this->assertSame(1, ContextWindow::resolve(1));
    }

    /**
     * The failure mode this class exists to prevent. ContextCompactor's three
     * predicates each early-return false on `$tokenLimit <= 0`, so a 0 handed
     * through would disable the reminder, the automatic compaction and the
     * blocking tier all at once — turning the feature off in exactly the case
     * where the backend could not answer.
     */
    public function testResolveTurnsANonPositiveWindowIntoTheFallbackRatherThanPassingItThrough(): void
    {
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::resolve(0));
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::resolve(-1));
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::resolve(PHP_INT_MIN));
        $this->assertGreaterThan(0, ContextWindow::FALLBACK_TOKENS, 'a fallback of 0 would disable every tier');
    }

    public function testFallbackIsTheBudgetThisAppActedOnBeforeAnyWindowWasReachable(): void
    {
        $this->assertSame(100_000, ContextWindow::FALLBACK_TOKENS);
    }

    public function testOfBackendReportsTheWindowOfACapableBackend(): void
    {
        $this->assertSame(196_608, ContextWindow::ofBackend(new FakeWindowBackend(196_608)));
    }

    public function testOfBackendFallsBackForABackendThatReportsAnUnusableWindow(): void
    {
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::ofBackend(new FakeWindowBackend(0)));
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::ofBackend(new FakeWindowBackend(-42)));
    }

    /**
     * The backends with no model behind them are not interrogated and are not
     * asked to invent a number — which is the whole reason this is a separate
     * capability instead of a method on Backend.
     */
    public function testOfBackendFallsBackForBackendsThatDoNotImplementTheCapability(): void
    {
        $this->assertNotInstanceOf(ReportsContextWindow::class, new EchoBackend());
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::ofBackend(new EchoBackend()));

        $command = new CommandBackend('cat');
        $this->assertNotInstanceOf(ReportsContextWindow::class, $command);
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::ofBackend($command));
    }
}

/**
 * A Backend that reports whatever window the test asks for. Completion is out
 * of scope here — only the capability is under test.
 */
final class FakeWindowBackend implements Backend, ReportsContextWindow
{
    public function __construct(private readonly int $window) {}

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant('ok');
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return \React\Promise\resolve($this->complete($history, $onToken, $onEvent));
    }
}
