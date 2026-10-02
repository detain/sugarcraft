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
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\CustomCommandExpandedMsg;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\SafetyClassifier;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\TurnHooksResolvedMsg;

use function React\Promise\resolve;

/**
 * Audit 15b-20: a file-based command's `` !`…` `` forms ran inside
 * `Chat::update()` through `CommandSpec::runShellSubstitution()`'s blocking
 * `stream_select()` loop, freezing the frame, Escape and Ctrl+C for up to the
 * whole 10-second shell budget, and on timeout `proc_terminate()` signalled
 * only the direct child, so a `bash -c '…'` grandchild or an `&` job survived.
 *
 * The Chat half drives the REAL fork: Enter must hand back a pending Cmd
 * without having run the shell, the Cmd's promise is settled on the shared
 * loop, and the {@see CustomCommandExpandedMsg} it resolves with is fed back
 * through update(). The suite's hosts carry ext-pcntl and ext-posix.
 */
final class CustomCommandShellAsyncTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    /** @var list<int> */
    private array $pids = [];

    protected function tearDown(): void
    {
        foreach ($this->pids as $pid) {
            if (posix_kill($pid, 0)) {
                posix_kill($pid, 9);
            }
        }
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
        $this->pids = [];
    }

    public function testEnterReturnsAtOnceAndTheShellRunsExactlyOnceInTheForkedExpansion(): void
    {
        $counter = $this->tempPath('sc_15b20_count_');
        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, '!`sleep 1; echo ran >> ' . escapeshellarg($counter) . '; printf %s twig` now');

        $chat = $this->type($chat, '/branch');
        $started = microtime(true);
        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertLessThan(0.1, microtime(true) - $started, 'update(Enter) must not wait for the shell');

        $this->assertNotNull($cmd);
        $this->assertSame(0, $backend->calls, 'nothing is sent before the template has been expanded');
        $this->assertTrue($pending->inFlight, 'the command holds the turn slot while its shell runs');
        $this->assertSame('', $pending->inputBuf, 'the box is consumed');
        $this->assertFileDoesNotExist($counter, 'the shell form did not run inside update()');

        $msg = $this->await($this->promiseOf($cmd));
        $this->assertInstanceOf(CustomCommandExpandedMsg::class, $msg);
        $this->assertSame('twig now', $msg->expanded);

        [$turn, $turnCmd] = $pending->update($msg);
        $this->drive($turn, $turnCmd);

        $this->assertSame(1, $backend->calls, 'the expansion dispatches the turn');
        $last = $turn->history[count($turn->history) - 1];
        $this->assertSame(Role::User, $last->role);
        $this->assertSame('twig now', $last->content);
        $this->assertSame("ran\n", (string) file_get_contents($counter), 'the re-entry consumed the expansion instead of re-running it');
    }

    public function testATemplateWithoutAShellFormStaysSynchronous(): void
    {
        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, 'Recap $ARGUMENTS');

        [$turn, $cmd] = $this->type($chat, '/branch notes')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('Recap notes', $turn->history[count($turn->history) - 1]->content, 'no fork: the prompt is in place on the same update()');
        $this->assertNotNull($cmd);
    }

    public function testDoubleEscapeKillsTheExpansionTreeAndALateExpansionIsIgnored(): void
    {
        $pidFile = $this->tempPath('sc_15b20_pid_');
        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, '!`bash -c ' . escapeshellarg('echo $$ > ' . escapeshellarg($pidFile) . '; exec sleep 30') . '`');

        [$pending, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));
        $promise = $this->promiseOf($cmd);

        $shellPid = $this->waitForPid($pidFile);
        $this->pids[] = $shellPid;
        $this->assertTrue(posix_kill($shellPid, 0), 'the grandchild shell is running');

        [$once] = $pending->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled, $cancelCmd] = $once->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($cancelCmd);
        $this->assertFalse($cancelled->inFlight);
        $this->assertSame('/branch', $cancelled->inputBuf, 'the never-sent command goes back into the empty box');

        $this->assertNull($this->await($promise), 'a cancelled expansion resolves with nothing to dispatch');
        $this->assertTrue($this->waitUntilGone($shellPid), 'the grandchild died with the expansion child');

        $late = new CustomCommandExpandedMsg($this->generation($pending), '/branch', 'too late');
        [$after, $afterCmd] = $cancelled->update($late);
        $this->assertSame($cancelled, $after, 'an expansion for a cancelled command changes nothing');
        $this->assertNull($afterCmd);
        $this->assertSame(0, $backend->calls);
    }

    public function testAStaleExpansionForAnotherGenerationIsIgnoredWhilePending(): void
    {
        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, '!`printf %s twig`');

        [$pending, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));

        $stale = new CustomCommandExpandedMsg($this->generation($pending) - 1, '/branch', 'stale');
        [$same, $sameCmd] = $pending->update($stale);
        $this->assertSame($pending, $same);
        $this->assertNull($sameCmd);

        [$turn, $turnCmd] = $pending->update($this->await($this->promiseOf($cmd)));
        $this->drive($turn, $turnCmd);
        $this->assertSame('twig', $turn->history[count($turn->history) - 1]->content);
    }

    public function testAnExpansionThenAScriptPromptHookRunBothOffTheUpdatePathAndTheShellRunsOnce(): void
    {
        $counter = $this->tempPath('sc_15b20_count_');
        $hookPath = $this->tempPath('sc_15b20_hook_') . '.sh';
        file_put_contents($hookPath, "#!/bin/sh\nsleep 0.2\nprintf 'HOOK-NOTE'\n");
        chmod($hookPath, 0o755);
        $registry = new HookRegistry();
        $registry->register(new ScriptHook('note', HookEvent::UserPromptSubmit, '.*', $hookPath, '', 20.0));

        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat(
            $backend,
            '!`echo ran >> ' . escapeshellarg($counter) . '; printf %s twig`',
            new HookManager($registry),
        );

        [$expanding, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(0, $backend->calls);

        $started = microtime(true);
        [$judging, $hookCmd] = $expanding->update($this->await($this->promiseOf($cmd)));
        $this->assertLessThan(0.1, microtime(true) - $started, 'the expanded prompt parks behind the hook, it does not run it');
        $this->assertTrue($judging->inFlight);
        $this->assertSame(0, $backend->calls, 'the hook has not judged the prompt yet');
        $this->assertNotNull($hookCmd);

        $verdict = $this->await($this->promiseOf($hookCmd));
        $this->assertInstanceOf(TurnHooksResolvedMsg::class, $verdict);
        $this->assertSame('twig', $verdict->text, 'the hook judged the expanded prompt');

        [$turn, $turnCmd] = $judging->update($verdict);
        $this->drive($turn, $turnCmd);

        $this->assertSame(1, $backend->calls);
        $this->assertSame(['HOOK-NOTE', 'twig'], array_map(
            static fn(Message $m): string => $m->content,
            array_slice($turn->history, -2),
        ));
        $this->assertSame("ran\n", (string) file_get_contents($counter), 'neither re-entry re-ran the shell');
    }

    public function testAPromptQueuedDuringTheExpansionIsReleasedWhenTheExpansionIsRefused(): void
    {
        $backend = new CustomCommandShellAsyncBackend();
        // Expands to nothing, which submit() refuses rather than sends.
        $chat = $this->chat($backend, '!`sleep 0.2`');

        [$pending, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));
        [$queued] = $this->type($pending, 'second')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['second'], $queued->queuedPrompts(), 'Enter during the expansion queues, as mid-turn');

        [$released, $releaseCmd] = $queued->update($this->await($this->promiseOf($cmd)));
        $this->drive($released, $releaseCmd);

        $this->assertSame([], $released->queuedPrompts(), 'the refusal ends the pending turn and drains the queue');
        $this->assertSame(1, $backend->calls, 'the queued prompt went out');
        $contents = array_map(static fn(Message $m): string => $m->content, $released->history);
        $this->assertSame('second', $contents[count($contents) - 1]);
    }

    /**
     * The forked child evaluates against ITS copy of the permission gate, so the
     * Auto-mode circuit breaker would lose every strike the expansion banked.
     * Three refused `fly deploy` forms are three consecutive strikes on the
     * synchronous path, which flips the next dangerous call to Ask; the parent's
     * replay is what keeps that true.
     */
    public function testTheChildsGateEvaluationsAreReplayedAgainstTheSessionsGate(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook($gate));

        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, '!`fly deploy` !`fly deploy` !`fly deploy`', $hooks);

        [$pending, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));
        $msg = $this->await($this->promiseOf($cmd));
        $this->assertInstanceOf(CustomCommandExpandedMsg::class, $msg);
        $this->assertSame(['fly deploy', 'fly deploy', 'fly deploy'], $msg->gatedCommands);

        [$turn, $turnCmd] = $pending->update($msg);
        $this->drive($turn, $turnCmd);

        $this->assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Bash', ['command' => 'fly deploy'])),
            'the three strikes the child banked reached the session gate',
        );
    }

    public function testAChildThatReportsNothingIsRefusedWithTheCommandBackInTheBox(): void
    {
        $backend = new CustomCommandShellAsyncBackend();
        $chat = $this->chat($backend, '!`printf %s twig`');

        [$pending, $cmd] = $this->type($chat, '/branch')->update(new KeyMsg(KeyType::Enter, ''));
        $real = $this->await($this->promiseOf($cmd));
        $this->assertInstanceOf(CustomCommandExpandedMsg::class, $real);

        [$refused, $next] = $pending->update(new CustomCommandExpandedMsg($real->generation, '/branch', null));

        $this->assertNull($next);
        $this->assertSame(0, $backend->calls);
        $this->assertFalse($refused->inFlight);
        $this->assertSame('/branch', $refused->inputBuf);
        $this->assertStringContainsString('ended without a result', $refused->history[count($refused->history) - 1]->content);

        $method = new \ReflectionMethod(Chat::class, 'collectCustomCommandExpansion');
        $empty = $this->tempPath('sc_15b20_empty_');
        file_put_contents($empty, '');
        $this->assertSame([null, []], $method->invoke(null, $empty));
        $this->assertFileDoesNotExist($empty, 'the payload is discarded either way');
    }

    /**
     * The mechanism half: on timeout the WHOLE tree goes, not just the direct
     * child. Before the fix the `exec -a <tag> sleep 38` grandchild (r14) and
     * the `&` job (whose shell had already exited) both outlived the
     * "killed after N seconds" notice.
     *
     * @dataProvider timedOutTrees
     */
    public function testATimedOutSubstitutionLeavesNoDescendantRunning(string $shape): void
    {
        $tag = 'sc15b20' . bin2hex(random_bytes(4));
        $command = sprintf($shape, $tag);

        $out = CommandSpec::new('t', 'd', 'Custom', template: '!`x`')
            ->runShellSubstitution($command, sys_get_temp_dir(), 0.5);

        $this->assertStringContainsString('was killed after 0.5 seconds', $out);
        $survivors = $this->processesTagged($tag);
        $this->pids = [...$this->pids, ...$survivors];
        $this->assertSame([], $survivors, 'no process of the substitution survived its timeout');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function timedOutTrees(): array
    {
        return [
            'a grandchild of the shell' => ["bash -c 'exec -a %s sleep 38'; echo done"],
            'a background job holding stdout' => ['(exec -a %s sleep 37) & echo started'],
        ];
    }

    // -------------------------------------------------------------------------

    private function chat(Backend $backend, string $template, ?HookManager $hooks = null): Chat
    {
        return new Chat(
            backend: $backend,
            hooks: $hooks,
            projectRoot: sys_get_temp_dir(),
            customCommands: ['branch' => CommandSpec::new(
                name: 'branch',
                description: 'x',
                category: 'Custom',
                template: $template,
            )],
        );
    }

    private function generation(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'generation'))->getValue($chat);
    }

    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        $this->paths[] = $path;

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

        $this->assertTrue($done, 'the forked Cmd settled');

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
            $loop->futureTick(static fn() => $loop->stop());
            $loop->run();
            usleep(20000);
        }

        $this->fail('the forked child never started');
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

    /**
     * Live (non-zombie) pids whose argv[0] is $tag — `exec -a` names the
     * process, so the tag is attributable to this test alone.
     *
     * @return list<int>
     */
    private function processesTagged(string $tag): array
    {
        usleep(100000);
        $found = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $cmdline = @file_get_contents($dir . '/cmdline');
            if ($cmdline === false || explode("\0", $cmdline)[0] !== $tag) {
                continue;
            }
            $stat = (string) @file_get_contents($dir . '/stat');
            if (preg_match('/^\d+ \(.*\) Z /', $stat) === 1) {
                continue;
            }
            $found[] = (int) basename($dir);
        }

        return $found;
    }
}

/**
 * A backend that records each turn it is handed and answers at once.
 */
final class CustomCommandShellAsyncBackend implements Backend
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
