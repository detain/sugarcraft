<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\CancelledWorkflowReportMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\BangShell;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Role;

/**
 * Roadmap 5.14g: `!<command>` in the TUI's input runs a shell command for the
 * user and puts its output in the transcript, where the model reads it on the
 * next turn.
 *
 * Driven through the real `update()` entry: Enter must hand back a Cmd without
 * having run the command, the Cmd's promise settles on the shared loop with the
 * REAL fork, and the Msg it resolves with is fed back through `update()`. The
 * command is the user's, so nothing asks; a configured Deny rule and plan
 * mode's read-only rule still refuse it.
 */
final class BangShellTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir() . '/sc_bang_' . bin2hex(random_bytes(4));
        mkdir($this->project, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->project . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->project);

        parent::tearDown();
    }

    public function testABangLineRunsOffTheUpdatePathAndItsOutputJoinsTheTranscript(): void
    {
        $backend = new BangShellRecordingBackend();
        $chat = $this->type($this->chat($backend), "!sleep 0.3; printf 'hello from the shell\\n'; pwd");

        $started = microtime(true);
        [$pending, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertLessThan(0.25, microtime(true) - $started, 'update(Enter) must not wait for the command');

        $this->assertNotNull($cmd);
        $this->assertFalse($pending->inFlight, 'a shell command starts no turn');
        $this->assertSame('', $pending->inputBuf, 'the box is consumed');
        $this->assertStringContainsString('Running `sleep 0.3;', $this->last($pending)->content);

        $msg = $this->await($this->promiseOf($cmd));
        $this->assertInstanceOf(CancelledWorkflowReportMsg::class, $msg, 'the append-only Msg: it settles no turn');

        [$after, $afterCmd] = $pending->update($msg);
        $this->assertNull($afterCmd);
        $row = $this->last($after);
        $this->assertSame(Role::User, $row->role, 'context the user put in front of the model');
        $this->assertFalse($row->uiOnly, 'that the model reads');
        $this->assertStringStartsWith("[The user ran a shell command: sleep 0.3; printf 'hello from the shell\\n'; pwd]", $row->content);
        $this->assertStringContainsString("Exit code: 0\nOutput:\nhello from the shell\n" . realpath($this->project), $row->content, 'it runs in the project root');
        $this->assertSame(0, $backend->calls, 'and costs nothing until the next prompt');
    }

    public function testAFailingCommandReportsItsExitCodeAndStderr(): void
    {
        $row = $this->runBang('!echo it broke >&2; exit 3');

        $this->assertStringContainsString('Exit code: 3', $row->content);
        $this->assertStringContainsString('it broke', $row->content);
    }

    public function testEscapeSequencesInTheOutputNeverReachTheFrame(): void
    {
        $row = $this->runBang("!printf '\\033[31mred\\033[0m\\a done'");

        $this->assertStringContainsString('red done', $row->content);
        $this->assertStringNotContainsString("\e", $row->content);
        $this->assertStringNotContainsString("\x07", $row->content);
    }

    public function testNothingAsksInDefaultModeBecauseTheUserTypedIt(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);
        $row = $this->runBang('!touch made-by-hand && echo made', $gate);

        $this->assertStringContainsString("Exit code: 0\nOutput:\nmade", $row->content);
        $this->assertFileExists($this->project . '/made-by-hand');
    }

    public function testADenyRuleTheUserConfiguredStillRefusesIt(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Bash(touch *)', PermissionAction::Deny)]);
        [$refused, $cmd] = $this->type($this->chat(new BangShellRecordingBackend(), $gate), '!touch nope')
            ->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'nothing runs');
        $this->assertSame('Did not run `touch nope`: a permission rule denies Bash for it.', $this->last($refused)->content);
        $this->assertSame('', $refused->inputBuf);
        $this->assertFileDoesNotExist($this->project . '/nope');
    }

    public function testPlanModeRunsOnlyWhatItCanProveReadOnly(): void
    {
        $gate = new PermissionGate(PermissionMode::Plan);

        [$refused, $cmd] = $this->type($this->chat(new BangShellRecordingBackend(), $gate), '!touch planned')
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($cmd);
        $this->assertSame('Did not run `touch planned`: plan mode runs only commands it can prove read-only.', $this->last($refused)->content);

        file_put_contents($this->project . '/seen.txt', 'x');
        $row = $this->runBang('!ls', $gate);
        $this->assertStringContainsString('seen.txt', $row->content, 'a read-only command still runs in plan mode');
    }

    public function testAReadOnlyWindowRefusesItLikeAnyPrompt(): void
    {
        $chat = new Chat(backend: new BangShellRecordingBackend(), projectRoot: $this->project, readOnlySession: true);
        [$refused, $cmd] = $this->type($chat, '!touch blocked')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertFileDoesNotExist($this->project . '/blocked');
    }

    public function testMidTurnABangLineWaitsForTheTurnInsteadOfSteeringIt(): void
    {
        $chat = $this->type(new Chat(backend: new BangShellRecordingBackend(), projectRoot: $this->project, inFlight: true), '!git status');
        [$queued, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertSame(['!git status'], $queued->queuedPrompts(), 'queued to run once the turn settles');
    }

    public function testOnlyABangWithACommandIsAShellLine(): void
    {
        $this->assertSame('ls -la', BangShell::commandOf('!ls -la'));
        $this->assertSame('ls', BangShell::commandOf('!  ls  '));
        $this->assertNull(BangShell::commandOf('!'));
        $this->assertNull(BangShell::commandOf('! '));
        $this->assertNull(BangShell::commandOf('run !ls'));
    }

    // -------------------------------------------------------------------------

    private function runBang(string $line, ?PermissionGate $gate = null): Message
    {
        [$pending, $cmd] = $this->type($this->chat(new BangShellRecordingBackend(), $gate), $line)
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd, 'the command was refused: ' . $this->last($pending)->content);

        [$after] = $pending->update($this->await($this->promiseOf($cmd)));

        return $this->last($after);
    }

    private function chat(Backend $backend, ?PermissionGate $gate = null): Chat
    {
        $hooks = null;
        if ($gate !== null) {
            $hooks = new HookManager(new HookRegistry());
            $hooks->register(new PermissionGateHook($gate));
        }

        return new Chat(backend: $backend, hooks: $hooks, projectRoot: $this->project);
    }

    private function type(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat;
    }

    private function last(Chat $chat): Message
    {
        return $chat->history[array_key_last($chat->history)];
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
}

/** A backend that only counts: a `!cmd` must never reach it. */
final class BangShellRecordingBackend implements Backend
{
    public int $calls = 0;

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant('ok');
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}
