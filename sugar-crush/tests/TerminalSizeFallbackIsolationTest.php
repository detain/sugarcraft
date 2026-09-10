<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Mouse\Zone;

/**
 * The suite's terminal viewport is hermetic state, not the runner's window.
 *
 * `TuiRenderer::getTerminalSize()` probes `Tty(STDOUT)` only while its
 * process-global cache is NULL, and every `Chat::view()` that carries no
 * explicit size reads that fallback (Chat::rows()/Chat::cols()). In round 61
 * the two size-setting classes upstream of the victims — `tests/App/*` —
 * returned the cache to null in tearDown, so the next renders consulted the
 * live terminal: the published-mode run (STDOUT a small tty) clipped
 * `CompactModelSummaryTest`'s earliest exchange and knocked
 * `MouseModalGuardTest`'s `pane:agents` bar off its render, while the
 * linked-mode run of the identical tree and order (STDOUT a pipe, so the
 * documented 60x200 fallback answered) stayed green twice. The pollution
 * sequence this pins is exactly that handoff: a shared-cache write, then an
 * independent viewport read, in one process.
 *
 * This is a deterministic stand-in for the ambient case: rather than depend on
 * a small tty (which only exists when someone runs the suite attached to one),
 * it FORCES the two viewport states explicitly. The mechanism the victims were
 * sensitive to — the cached size, not the tty probe — is what a `view()` acts
 * on, so pinning the cache to a small viewport reproduces the published red
 * with no terminal involved at all.
 *
 * @see \SugarCraft\Crush\Tui\Renderer::getTerminalSize()
 * @see tests/bootstrap.php (the suite-level pin this test guards)
 */
final class TerminalSizeFallbackIsolationTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        // Leave the shared cache exactly as tests/bootstrap.php pins it, so a
        // forced small viewport here cannot pollute anything that runs next.
        TuiRenderer::setSize(200, 60);

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink((string) $file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * One ordered run through the whole pollution sequence: default viewport
     * renders both shapes whole (the victims' green state); forcing a small
     * viewport — what a null cache + live tty used to hand to them — clips
     * the earliest exchange and drops the bar's zone (the published red).
     */
    public function testSizeAgnosticViewsFollowTheSharedCacheNotTheRunnerTerminal(): void
    {
        // The suite's hermetic pin: the documented non-tty fallback, never null.
        self::assertSame(
            ['rows' => 60, 'cols' => 200],
            TuiRenderer::getTerminalSize(),
            'the pinned fallback is the documented 60x200 viewport',
        );

        $compact = $this->compactLike();
        $palette = $this->paletteUp();

        // -- pinned default: both size-agnostic renders stay whole (victims GREEN).
        self::assertStringContainsString(
            'question 1',
            $compact->view(),
            'at the pinned viewport the earliest exchange is still verbatim',
        );
        $palette->view();
        self::assertInstanceOf(
            Zone::class,
            Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'agents'),
            'and the bar shows through the overlay, so its zone is registered',
        );

        // -- forced small viewport: the same two reads, now against what a
        //    null cache + small live tty used to answer. Both lose their top,
        //    exactly as the archived published-mode failures did.
        TuiRenderer::setSize(60, 16);

        self::assertStringNotContainsString(
            'question 1',
            $compact->view(),
            'a small ambient viewport clips the earliest exchange off the transcript',
        );
        $palette->view();
        self::assertNull(
            Renderer::scanner()->get(Renderer::PANE_ZONE_PREFIX . 'agents'),
            'and the bar falls out of the render, so the chrome zone is never marked',
        );
    }

    /**
     * The keystone the pin rests on: with the probe ARMED (an explicit reset)
     * and STDOUT not a tty, `getTerminalSize()` answers exactly the documented
     * 60x200 default. bootstrap pins to that same viewport, which is what
     * makes the tests/App re-pins no-ops for every test that passes today —
     * if this constant ever moves, the pin silently changes the suite.
     *
     * Guarded, not skipped: the suite's skip roster (`SuiteSkipRoster`,
     * exactly one skip by name) must not move because someone attached a
     * terminal. On a tty runner the same reset ARMS the probe and the
     * assertion compares against ground truth — an independent fresh
     * `Tty(STDOUT)` probe of the live window — so the tty half exercises the
     * probe path too (the pinned cache would otherwise answer 60x200 and the
     * window would never be touched), and only when that probe cannot answer
     * does the documented fallback take the watch, mirroring
     * `getTerminalSize()`'s own positive-size rule.
     */
    public function testTheDocumentedFallbackIsExactlySixtyRowsByTwoHundredCols(): void
    {
        TuiRenderer::resetSizeCache();

        if (stream_isatty(\STDOUT)) {
            // Ground truth: an independent fresh probe of the same window,
            // folded through the same positive-size rule the renderer applies
            // in src/Tui/Renderer.php getTerminalSize().
            try {
                $probe = (new Tty(STDOUT))->size();
            } catch (\Throwable) {
                $probe = null;
            }

            $expected = ($probe !== null && $probe['rows'] > 0 && $probe['cols'] > 0)
                ? ['rows' => $probe['rows'], 'cols' => $probe['cols']]
                : ['rows' => 60, 'cols' => 200];

            self::assertSame(
                $expected,
                TuiRenderer::getTerminalSize(),
                'with the probe armed, a tty answers its own window — or the documented fallback when the probe cannot',
            );

            return;
        }

        self::assertSame(
            ['rows' => 60, 'cols' => 200],
            TuiRenderer::getTerminalSize(),
            'the fallback bootstrap pins to is the documented 60x200 default',
        );
    }

    /**
     * The compact victim's shape: five exchanges, a `/compact` draft submitted
     * with a summarizer standing by — history untouched yet, so the ONLY thing
     * that can hide `question 1` is the viewport.
     */
    private function compactLike(): Chat
    {
        $history = [];
        for ($i = 1; $i <= 5; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        $chat = new Chat(
            history: $history,
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $this->summarizer('stub'),
        );

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        return $next;
    }

    /** A stand-in summarization backend answering with a fixed reply. */
    private function summarizer(string $reply): Backend
    {
        return new class ($reply) implements Backend {
            public function __construct(private readonly string $reply) {}

            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /**
     * The mouse victim's shape: mid-turn with the command palette open over a
     * populated session whose bar carries the agents pane.
     */
    private function paletteUp(): Chat
    {
        $store = new SessionStore($this->sandboxDir() . '/sessions.db');
        $store->createSession('session-a', 'echo', 'echo-1', null, 'Alpha');
        $store->createSession('session-b', 'echo', 'echo-1', null, 'Beta');

        $manager = new AgentManager(new EchoProvider(), new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'echo-1',
            provider: 'echo',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        [$chat] = (new Chat(
            history: [Message::assistant('')->withToolResults([ToolResult::ok('grep', "alpha\nbeta", 'call_1')])],
            inFlight: true,
            sessionStore: $store,
            currentSessionId: 'session-b',
            agentManager: $manager,
        ))->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        self::assertNotNull($chat->palette(), 'fixture: the palette is really up');

        return $chat;
    }

    private function sandboxDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush_terminal_size_isolation_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
