<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\TurnHooksResolvedMsg;

use function React\Promise\resolve;

/**
 * Audit 15b-04: a `UserPromptSubmit` / `SessionStart` chain with a
 * {@see ScriptHook} in it ran its blocking `proc_open()` drain inside
 * `Chat::update()`, freezing the frame, Escape and Ctrl+C for as long as the
 * script took (60 s per hook at the default). The chain now runs in a forked
 * child from a Cmd and the submission re-enters `submit()` when the
 * {@see TurnHooksResolvedMsg} lands.
 *
 * Every test here drives the REAL fork: Enter must hand back a pending Cmd
 * without having run the hook, the Cmd's promise is settled by running the
 * shared loop, and the Msg it resolves with is fed back through update(). The
 * suite's hosts carry ext-pcntl and ext-posix, which the fork path needs.
 */
final class TurnHookAsyncTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    public function testEnterReturnsAtOnceWithAPendingCmdWhileASlowPromptHookRuns(): void
    {
        $backend = new TurnHookAsyncBackend();
        $chat = $this->chat($backend, [
            $this->script('slow', HookEvent::UserPromptSubmit, "sleep 2\nprintf 'NOTE-FROM-SLOW-HOOK'\n"),
        ]);

        $chat = $this->type($chat, 'deploy it');
        $started = microtime(true);
        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertLessThan(0.1, microtime(true) - $started, 'update(Enter) must not wait for the hook');

        $this->assertNotNull($cmd, 'the hook chain is handed back as a Cmd');
        $this->assertSame(0, $backend->calls, 'no turn is dispatched before the hook has judged the prompt');
        $this->assertTrue($pending->inFlight, 'the submission holds the turn slot while the hook runs');
        $this->assertSame('', $pending->inputBuf, 'the box is consumed, so the next Enter cannot queue it twice');

        $forkedAt = microtime(true);
        $promise = $this->promiseOf($cmd);
        $this->assertLessThan(0.5, microtime(true) - $forkedAt, 'running the Cmd forks; it does not wait either');

        $msg = $this->await($promise);
        $this->assertInstanceOf(TurnHooksResolvedMsg::class, $msg);

        [$turn, $turnCmd] = $pending->update($msg);
        $this->assertNotNull($turnCmd);
        $this->drive($turn, $turnCmd);

        $this->assertSame(1, $backend->calls, 'the verdict dispatches the turn');
        $this->assertSame(Role::System, $turn->history[0]->role);
        $this->assertSame('NOTE-FROM-SLOW-HOOK', $turn->history[0]->content, 'the hook note rides ahead of the prompt');
        $this->assertSame(Role::User, $turn->history[1]->role);
        $this->assertSame('deploy it', $turn->history[1]->content);
    }

    public function testABlockingScriptHookRefusesThePromptAndPutsItBackInTheBox(): void
    {
        $backend = new TurnHookAsyncBackend();
        $chat = $this->chat($backend, [
            $this->script('freeze', HookEvent::UserPromptSubmit, "sleep 0.2\necho 'change freeze is on' >&2\nexit 2\n"),
        ]);

        [$pending, $cmd] = $this->type($chat, 'ship it')->update(new KeyMsg(KeyType::Enter, ''));
        [$refused, $next] = $pending->update($this->await($this->promiseOf($cmd)));

        $this->assertNull($next);
        $this->assertSame(0, $backend->calls);
        $this->assertFalse($refused->inFlight, 'a refused prompt releases the turn slot');
        $this->assertSame('ship it', $refused->inputBuf, 'the draft stays in the box, as on the synchronous path');
        $last = $refused->history[count($refused->history) - 1];
        $this->assertStringContainsString('change freeze is on', $last->content);
        $this->assertStringContainsString('Your prompt was not sent and is still in the box.', $last->content);
    }

    public function testADraftTypedWhileTheHookRanSurvivesARefusalAndTheNoticeQuotesThePrompt(): void
    {
        $backend = new TurnHookAsyncBackend();
        $chat = $this->chat($backend, [
            $this->script('freeze', HookEvent::UserPromptSubmit, "sleep 0.2\necho 'blocked' >&2\nexit 2\n"),
        ]);

        [$pending, $cmd] = $this->type($chat, 'first thought')->update(new KeyMsg(KeyType::Enter, ''));
        $pending = $this->type($pending, 'half typed');
        [$refused] = $pending->update($this->await($this->promiseOf($cmd)));

        $this->assertSame('half typed', $refused->inputBuf, 'text typed during the wait is never overwritten');
        $last = $refused->history[count($refused->history) - 1];
        $this->assertStringContainsString('first thought', $last->content, 'the refused prompt is quoted instead');
        $this->assertStringNotContainsString('still in the box', $last->content);
    }

    public function testSessionStartAndPromptNotesFromScriptsKeepTheirOrderOnTheFirstTurn(): void
    {
        $backend = new TurnHookAsyncBackend();
        $chat = $this->chat($backend, [
            $this->script('ups', HookEvent::UserPromptSubmit, "printf 'PROMPT-NOTE'\n"),
            $this->script('ss', HookEvent::SessionStart, "printf 'SESSION-NOTE'\n"),
        ]);

        [$pending, $cmd] = $this->type($chat, 'hello')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(0, $backend->calls);
        [$turn, $turnCmd] = $pending->update($this->await($this->promiseOf($cmd)));
        $this->drive($turn, $turnCmd);

        $this->assertSame(['SESSION-NOTE', 'PROMPT-NOTE', 'hello'], array_map(
            static fn(Message $m): string => $m->content,
            array_slice($turn->history, 0, 3),
        ));
        $this->assertSame(1, $backend->calls);
    }

    public function testDoubleEscapeWhileTheHookRunsKillsItsProcessTreeAndALateVerdictIsIgnored(): void
    {
        $backend = new TurnHookAsyncBackend();
        $pidFile = $this->tempPath('sc_15b04_pid_');
        $chat = $this->chat($backend, [
            $this->script('hang', HookEvent::UserPromptSubmit, "echo \$\$ > " . escapeshellarg($pidFile) . "\nexec sleep 30\n"),
        ]);

        [$pending, $cmd] = $this->type($chat, 'never mind')->update(new KeyMsg(KeyType::Enter, ''));
        $promise = $this->promiseOf($cmd);

        $scriptPid = $this->waitForPid($pidFile);
        $this->assertTrue(posix_kill($scriptPid, 0), 'the hook script is running');

        [$once] = $pending->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled, $cancelCmd] = $once->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cancelCmd);
        $this->assertFalse($cancelled->inFlight);
        $this->assertSame('never mind', $cancelled->inputBuf, 'the never-sent prompt goes back into the empty box');
        $this->assertSame('_Request cancelled._', $cancelled->history[count($cancelled->history) - 1]->content);

        $this->assertNull($this->await($promise), 'a cancelled chain resolves with nothing to dispatch');
        $this->assertTrue($this->waitUntilGone($scriptPid), 'the hook script was killed with its parent child');

        $late = new TurnHooksResolvedMsg((new \ReflectionProperty(Chat::class, 'generation'))->getValue($pending), 'never mind', HookResult::allow());
        [$after, $afterCmd] = $cancelled->update($late);
        $this->assertSame($cancelled, $after, 'a verdict for a cancelled submission changes nothing');
        $this->assertNull($afterCmd);
        $this->assertSame(0, $backend->calls);
    }

    public function testAPromptQueuedWhileTheHookRanIsReleasedWhenThePendingPromptIsRefused(): void
    {
        $backend = new TurnHookAsyncBackend();
        $chat = $this->chat($backend, [
            $this->script(
                'gate',
                HookEvent::UserPromptSubmit,
                "case \"\$CRUSH_TOOL_INPUT\" in *first*) echo 'no first' >&2; exit 2;; esac\nprintf 'OK-NOTE'\n",
            ),
        ]);

        [$pending, $cmd] = $this->type($chat, 'first')->update(new KeyMsg(KeyType::Enter, ''));
        [$queued] = $this->type($pending, 'second')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['second'], $queued->queuedPrompts(), 'Enter during the wait queues, as mid-turn');

        [$released, $releaseCmd] = $queued->update($this->await($this->promiseOf($cmd)));
        $this->assertSame([], $released->queuedPrompts(), 'the refusal ends the pending turn and drains the queue');
        $this->assertTrue($released->inFlight, 'the queued prompt is now waiting on its own hook run');
        $this->assertSame(0, $backend->calls);
        $this->assertNotNull($releaseCmd);

        [$turn, $turnCmd] = $released->update($this->await($this->promiseOf($releaseCmd)));
        $this->drive($turn, $turnCmd);
        $this->assertSame(1, $backend->calls);
        $contents = array_map(static fn(Message $m): string => $m->content, $turn->history);
        $this->assertContains('OK-NOTE', $contents);
        $this->assertSame('second', $contents[count($contents) - 1]);
    }

    public function testAnInProcessHookStillRunsSynchronously(): void
    {
        $backend = new TurnHookAsyncBackend();
        $hook = new class implements HookInterface {
            public function name(): string
            {
                return 'php-note';
            }

            public function event(): HookEvent
            {
                return HookEvent::UserPromptSubmit;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow('', 'PHP-NOTE');
            }
        };

        [$turn, $cmd] = $this->type($this->chat($backend, [$hook]), 'hi')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('PHP-NOTE', $turn->history[0]->content, 'no fork: the note is in place on the same update()');
        $this->assertNotNull($cmd);
    }

    public function testAChildThatReportsNothingFailsClosed(): void
    {
        $method = new \ReflectionMethod(Chat::class, 'collectTurnHookResults');
        $empty = $this->tempPath('sc_15b04_empty_');
        file_put_contents($empty, '');

        [$prompt, $session] = $method->invoke(null, $empty);
        $this->assertInstanceOf(HookResult::class, $prompt);
        $this->assertFalse($prompt->permitsExecution(), 'an unreadable verdict is a DENY, never an allow');
        $this->assertNull($session);
        $this->assertFileDoesNotExist($empty, 'the payload is discarded either way');
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<HookInterface> $hooks
     */
    private function chat(Backend $backend, array $hooks): Chat
    {
        $registry = new HookRegistry();
        foreach ($hooks as $hook) {
            $registry->register($hook);
        }

        return new Chat(backend: $backend, hooks: new HookManager($registry));
    }

    private function script(string $name, HookEvent $event, string $body): ScriptHook
    {
        $path = $this->tempPath('sc_15b04_hook_') . '.sh';
        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0o755);

        return new ScriptHook($name, $event, '.*', $path, '', 20.0);
    }

    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        $this->paths[] = $path;
        $this->paths[] = $path . '.sh';

        return $path;
    }

    private function type(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat;
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
            $guard = $loop->addTimer(15.0, static fn() => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }

        $this->assertTrue($done, 'the turn-hook Cmd settled');

        return $value;
    }

    /** Settle a dispatched turn so the backend recorder sees the call. */
    private function drive(Chat $chat, ?\Closure $cmd): void
    {
        if ($cmd === null) {
            return;
        }
        $out = $cmd();
        if ($out instanceof BatchMsg) {
            foreach ($out->cmds as $inner) {
                if ($inner instanceof \Closure) {
                    $this->drive($chat, $inner);
                }
            }
        }
    }

    private function waitForPid(string $file): int
    {
        $loop = Loop::get();
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $pid = (int) trim((string) @file_get_contents($file));
            if ($pid > 0) {
                return $pid;
            }
            // Let the parent's poll timer run between checks.
            $loop->futureTick(static fn() => $loop->stop());
            $loop->run();
            usleep(20000);
        }

        $this->fail('the hook script never started');
    }

    private function waitUntilGone(int $pid): bool
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false || preg_match('/^\d+ \(.*\) Z /', $stat) === 1) {
                return true;
            }
            usleep(20000);
        }

        return false;
    }
}

/**
 * A backend that records each turn it is handed and answers at once.
 */
final class TurnHookAsyncBackend implements Backend
{
    public int $calls = 0;

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant('ok');
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return resolve(Message::assistant('ok'));
    }
}
