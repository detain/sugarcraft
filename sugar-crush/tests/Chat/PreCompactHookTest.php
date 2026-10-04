<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * Roadmap 2.12: the dormant `PreCompact` hook is wired — a refusal SKIPS the
 * compaction — and `PostCompact` fires once one was applied. Driven through the
 * real `Chat::update()` on every route a compaction takes from the TUI:
 * `/compact` on the heuristic, `/compact` with a summary model, and the
 * automatic 85% tier's parked route.
 *
 * The property every route shares is asserted first in each test: the chain
 * never runs inside `update()`. `/compact` hands back a Cmd and the hook has
 * not fired yet; it fires when the Cmd runs, and the compaction (or its
 * refusal) applies when the resulting {@see HistoryCompactedMsg} lands.
 */
final class PreCompactHookTest extends TestCase
{
    private const DENY_REASON = '2.12-DENY keep the transcript whole';

    /** @var \ArrayObject<int, array{event: string, context: HookContext}> */
    private \ArrayObject $fired;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->fired = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testWithNoCompactionHookWiredTheHeuristicCompactIsStillSynchronous(): void
    {
        $chat = $this->chat(null, []);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'no hook wired: /compact compacts inline exactly as before');
        $this->assertStringContainsString('Context compacted:', $this->lastContent($next->history));
    }

    public function testABlockingPreCompactHookSkipsTheHeuristicCompaction(): void
    {
        $chat = $this->chat(null, [$this->hook(HookEvent::PreCompact, HookResult::deny(self::DENY_REASON))], '/compact keep the auth flow');
        $visibleBefore = $this->visibleContents($chat->history);

        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull($cmd, 'a wired PreCompact chain sends /compact off the update path');
        $this->assertCount(0, $this->fired, 'the hook has not run inside update()');
        $this->assertSame('', $pending->inputBuf);
        $this->assertStringContainsString('Running PreCompact hooks', $this->lastContent($pending->history));

        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertSame(self::DENY_REASON, $msg->blockedBy);

        $this->assertCount(1, $this->fired);
        $payload = json_decode($this->fired[0]['context']->toolInput, true);
        $this->assertSame(['trigger' => 'manual', 'custom_instructions' => 'keep the auth flow'], $payload);
        $this->assertSame('PreCompact', $this->fired[0]['context']->toolName);

        [$landed, $post] = $pending->update($msg);

        $this->assertNull($post, 'a blocked compaction fires no PostCompact');
        $this->assertSame($visibleBefore, $this->visibleContents($landed->history), 'nothing was condensed');
        $this->assertStringContainsString(
            'Compaction skipped: PreCompact hook blocked it (' . self::DENY_REASON . '). The history is unchanged.',
            $this->lastContent($landed->history),
        );
        $this->assertTrue($landed->history[array_key_last($landed->history)]->uiOnly, 'the reason reaches the user only');
        $this->assertNull($this->latchOf($landed));
    }

    public function testAPermittingPreCompactHookLetsTheHeuristicCompactionLandWithoutAModelFailurePreface(): void
    {
        $chat = $this->chat(null, [$this->hook(HookEvent::PreCompact, HookResult::allow())]);

        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $this->resolve($cmd);
        $this->assertNull($msg->blockedBy);

        [$landed] = $pending->update($msg);

        $report = $this->lastContent($landed->history);
        $this->assertStringStartsWith('Context compacted:', $report, 'the heuristic was never a model fallback');
        $this->assertGreaterThan(
            count(array_filter($chat->history, static fn (Message $m): bool => $m->uiOnly)),
            count(array_filter($landed->history, static fn (Message $m): bool => $m->uiOnly)),
            'rows were condensed (hidden from the model)',
        );
    }

    public function testABlockingPreCompactHookOnTheModelRouteStopsTheSummaryBeforeItIsPaidFor(): void
    {
        $summarizer = $this->summarizer();
        $chat = $this->chat($summarizer, [$this->hook(HookEvent::PreCompact, HookResult::deny(self::DENY_REASON))]);
        $visibleBefore = $this->visibleContents($chat->history);

        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertCount(0, $this->fired);

        $msg = $this->resolve($cmd);

        $this->assertSame(0, $summarizer->calls(), 'the summarization request never left');
        $this->assertSame(self::DENY_REASON, $msg->blockedBy);

        [$landed] = $pending->update($msg);
        $this->assertSame($visibleBefore, $this->visibleContents($landed->history));
        $this->assertStringContainsString('Compaction skipped: PreCompact hook blocked it', $this->lastContent($landed->history));
    }

    public function testAPermittingHooksNoteSteersTheSummaryBesideTheFocus(): void
    {
        $summarizer = $this->summarizer();
        $chat = $this->chat(
            $summarizer,
            [$this->hook(HookEvent::PreCompact, HookResult::allow('', 'keep every migration file name'))],
            '/compact the database layer',
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $this->resolve($cmd);

        $this->assertNull($msg->blockedBy);
        $this->assertSame(1, $summarizer->calls());
        $steer = end($summarizer->seen)->content;
        $this->assertStringContainsString("Focus for this summary, from the user's /compact command", $steer);
        $this->assertStringContainsString('the database layer', $steer);
        $this->assertStringContainsString("Guidance for this summary from the operator's PreCompact hook:\nkeep every migration file name", $steer);
    }

    public function testThePostCompactHookFiresAfterTheCompactionWasAppliedWithTheSummary(): void
    {
        $chat = $this->chat(null, [$this->hook(HookEvent::PostCompact, HookResult::deny('ignored: observe-only'))]);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('Context compacted:', $this->lastContent($next->history), 'applied inside update(), as before');
        $this->assertNotNull($cmd, 'PostCompact rides out as a Cmd');
        $this->assertCount(0, $this->fired, 'not inside update()');

        $this->assertNull($this->resolve($cmd), 'observe-only: nothing is dispatched back');
        $this->assertCount(1, $this->fired);
        $this->assertSame('PostCompact', $this->fired[0]['event']);
        $payload = json_decode($this->fired[0]['context']->toolInput, true);
        $this->assertSame('manual', $payload['trigger']);
        $this->assertStringContainsString('question 1', $payload['compact_summary']);
    }

    public function testNothingToCompactFiresNoPostCompact(): void
    {
        $chat = $this->chat(null, [$this->hook(HookEvent::PostCompact, HookResult::allow())], '/compact', pairs: 1);

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'no row condensed, so there is no compaction to report');
    }

    public function testTheParkedTiersPreCompactRunsWithTheAutoTriggerAndABlockStillSendsThePrompt(): void
    {
        $main = $this->mainBackend();
        $summarizer = $this->summarizer();
        $chat = new Chat(
            history: self::compactablePairs(),
            inputBuf: 'the parked prompt',
            backend: $main,
            summaryBackend: $summarizer,
            hooks: $this->hookManager([$this->hook(HookEvent::PreCompact, HookResult::deny(self::DENY_REASON))]),
        );

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($this->latchOf($parked), 'the tier parked the prompt behind a summarization');
        $this->assertCount(0, $this->fired);

        $msg = $this->resolve($cmd);
        $this->assertSame(0, $summarizer->calls());
        $this->assertSame(self::DENY_REASON, $msg->blockedBy);
        $this->assertSame('auto', json_decode($this->fired[0]['context']->toolInput, true)['trigger']);

        [$landed, $turn] = $parked->update($msg);

        $this->assertStringContainsString(
            'Compaction skipped: PreCompact hook blocked it (' . self::DENY_REASON . '). Your prompt goes out against the uncompacted history.',
            implode("\n", array_map(static fn (Message $m): string => $m->content, $landed->history)),
        );
        $this->assertTrue($landed->inFlight, 'the parked turn was dispatched');
        $this->settleTurn($turn);
        $this->assertSame(1, $main->calls(), 'the prompt went out');
        $this->assertSame(0, count(array_filter(
            $main->lastHistory(),
            static fn (Message $m): bool => str_starts_with($m->content, CompactionService::SUMMARY_ROW_PREFIX),
        )), 'against the uncompacted history');
    }

    public function testAnOutOfProcessPreCompactHookRunsInAForkedChildAndItsBlockSkipsTheCompaction(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('ext-pcntl is required for the forked compaction-hook path');
        }

        $hook = $this->scriptHook(
            'precompact-guard',
            HookEvent::PreCompact,
            "printf '%s' \"\$CRUSH_TOOL_INPUT\" > " . escapeshellarg($seen = $this->tempPath('sc_212_seen_')) . "\n"
            . "echo 'scripted refusal' >&2\nexit 2\n",
        );
        $chat = new Chat(
            history: self::transcript(6),
            inputBuf: '/compact scripted focus',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            hooks: $this->hookManager([$hook]),
        );
        $visibleBefore = $this->visibleContents($chat->history);

        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertFileDoesNotExist($seen, 'the script did not run inside update()');

        $msg = $this->await($this->promiseOf($cmd));

        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertStringContainsString('scripted refusal', (string) $msg->blockedBy);
        $this->assertSame(['trigger' => 'manual', 'custom_instructions' => 'scripted focus'], json_decode((string) file_get_contents($seen), true));

        [$landed] = $pending->update($msg);
        $this->assertSame($visibleBefore, $this->visibleContents($landed->history));
    }

    // =====================================================================
    // helpers
    // =====================================================================

    /** @return list<Message> */
    private static function transcript(int $pairs): array
    {
        $out = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $out[] = Message::user("question {$i}");
            $out[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $out;
    }

    /**
     * Past the 85% tier of an 88k window, under 95% once condensed — the shape
     * ParkedCompactionHookTest parks on.
     *
     * @return list<Message>
     */
    private static function compactablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /** @param list<HookInterface> $hooks */
    private function chat(?Backend $summarizer, array $hooks, string $draft = '/compact', int $pairs = 6): Chat
    {
        return new Chat(
            history: self::transcript($pairs),
            inputBuf: $draft,
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: $summarizer,
            hooks: $hooks === [] ? null : $this->hookManager($hooks),
        );
    }

    /** @param list<HookInterface> $hooks */
    private function hookManager(array $hooks): HookManager
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hook) {
            $registry->register($hook);
        }

        return new HookManager($registry);
    }

    private function hook(HookEvent $event, HookResult $verdict): HookInterface
    {
        return new class ($event, $verdict, $this->fired) implements HookInterface {
            public function __construct(
                private readonly HookEvent $event,
                private readonly HookResult $verdict,
                private readonly \ArrayObject $fired,
            ) {}

            public function name(): string
            {
                return 'compaction-recorder-' . $this->event->value;
            }

            public function event(): HookEvent
            {
                return $this->event;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->fired[] = ['event' => $this->event->value, 'context' => $context];

                return $this->verdict;
            }
        };
    }

    private function scriptHook(string $name, HookEvent $event, string $body): ScriptHook
    {
        $path = $this->tempPath('sc_212_hook_') . '.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);

        return new ScriptHook($name, $event, '.*', $path, '', 20.0);
    }

    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        $this->paths[] = $path;

        return $path;
    }

    /** A summarizer answering one record per offered exchange, recording what it was sent. */
    private function summarizer(): Backend
    {
        return new class implements Backend {
            /** @var list<Message> */
            public array $seen = [];

            private int $calls = 0;

            public function calls(): int
            {
                return $this->calls;
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;
                $this->seen = $history;

                return Message::assistant(self::reply());
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->calls++;
                $this->seen = $history;

                return \React\Promise\resolve(Message::assistant(self::reply()));
            }

            private static function reply(): string
            {
                $records = [];
                for ($i = 1; $i <= 12; $i++) {
                    $records[] = "{$i}.\nasked: condensed exchange {$i}";
                }

                return implode("\n", $records);
            }
        };
    }

    private function mainBackend(): Backend
    {
        return new class implements Backend, ReportsContextWindow {
            /** @var list<list<Message>> */
            private array $seen = [];

            public function contextWindow(): int
            {
                return 88_000;
            }

            public function calls(): int
            {
                return count($this->seen);
            }

            /** @return list<Message> */
            public function lastHistory(): array
            {
                return $this->seen[count($this->seen) - 1] ?? [];
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen[] = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->seen[] = $history;

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    /** Resolve an in-process Cmd::promise() Cmd and hand back what it settled with. */
    private function resolve(?\Closure $cmd): mixed
    {
        $this->assertNotNull($cmd);
        $resolved = null;
        $settled = false;
        $this->promiseOf($cmd)->then(static function ($msg) use (&$resolved, &$settled): void {
            $resolved = $msg;
            $settled = true;
        });
        $this->assertTrue($settled, 'an in-process chain settles without the loop running');

        return $resolved;
    }

    private function promiseOf(\Closure $cmd): PromiseInterface
    {
        $async = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $async);

        return $async->promise;
    }

    /** Run the shared loop until $promise settles, bounded so a regression fails rather than hangs. */
    private function await(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $done = false;
        $value = null;
        $promise->then(static function ($v) use (&$done, &$value, $loop): void {
            $done = true;
            $value = $v;
            $loop->stop();
        });

        if (!$done) {
            $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }

        $this->assertTrue($done, 'the forked Cmd settled');

        return $value;
    }

    /** Run a dispatched turn Cmd (and any batch it holds) so the backend sees the call. */
    private function settleTurn(?\Closure $cmd): void
    {
        if ($cmd === null) {
            return;
        }
        $out = $cmd();
        if ($out instanceof BatchMsg) {
            foreach ($out->cmds as $inner) {
                if ($inner instanceof \Closure) {
                    $this->settleTurn($inner);
                }
            }
        }
    }

    private function latchOf(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private function lastContent(array $history): string
    {
        $last = end($history);

        return $last === false ? '' : $last->content;
    }

    /**
     * What the model would read, as role:content strings.
     *
     * @param list<Message> $history
     * @return list<string>
     */
    private function visibleContents(array $history): array
    {
        return array_map(
            static fn (Message $m): string => $m->role->value . ':' . $m->content,
            Message::agentVisible($history),
        );
    }
}
