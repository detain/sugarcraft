<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Code\AssistantMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Message;
use SugarCraft\Code\PermissionReplyMsg;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResultsMsg;

/**
 * Audit F-P9 (the dormant Chat tool path): "Always" used to be filed as
 * `permissionGrants[<tool name>] = true` and {@see Chat::gateToolCall()}
 * honoured it for EVERY later ask of that tool — any arguments, any asker.
 * Answering "Always" to `Bash ls` therefore auto-approved a user hooks.yaml
 * script's "confirm before touching prod" for the rest of the session.
 *
 * A grant now answers only the permission gate's own question, for the exact
 * arguments it was given for; a user hook's ask is never grantable.
 */
final class ChatAlwaysGrantScopeTest extends TestCase
{
    /**
     * The audit's test: a registered Bash and a ScriptHook that exits 3.
     * Answer "Always" once, dispatch a different command: the prompt is
     * raised again.
     */
    public function testAlwaysOnAUserHookAskDoesNotSilenceTheNextAsk(): void
    {
        $chat = $this->chat(new ScriptHook('confirm-prod', HookEvent::PreToolUse, 'bash', 'exit 3', 'confirm'));

        $granted = $this->answerAlways($chat, ['command' => 'ls']);
        $this->assertSame([], $granted->permissionGrants(), "a user hook's question must not be grantable");

        [$next] = $granted->update($this->call(['command' => 'kubectl delete ns prod'], 'call_2'));

        $this->assertNotNull($next->pendingPermission(), 'the user hook was silenced by an earlier Always');
    }

    /** Even the SAME call: the user hook asks about content, every time. */
    public function testAlwaysOnAUserHookAskDoesNotSilenceTheSameCall(): void
    {
        $chat = $this->chat($this->askingHook('confirm-everything'));

        $granted = $this->answerAlways($chat, ['command' => 'ls']);
        [$next] = $granted->update($this->call(['command' => 'ls'], 'call_2'));

        $this->assertNotNull($next->pendingPermission());
    }

    /** The gate's grant is scoped to the call: a different command is asked again. */
    public function testAGateGrantDoesNotCoverADifferentCommand(): void
    {
        $chat = $this->chat($this->gate());

        $granted = $this->answerAlways($chat, ['command' => 'ls']);
        $this->assertSame(['call:bash {"command":"ls"}' => true], $granted->permissionGrants());

        [$next] = $granted->update($this->call(['command' => 'rm -rf build'], 'call_2'));

        $this->assertNotNull($next->pendingPermission(), 'an Always for `ls` approved `rm -rf build`');
    }

    /** The control: the same call again is answered by the grant. */
    public function testAGateGrantAnswersTheSameCallAgain(): void
    {
        $chat = $this->chat($this->gate());

        $granted = $this->answerAlways($chat, ['command' => 'ls', 'timeout' => 5]);
        // Key order is not part of the call's identity.
        [$next, $cmd] = $granted->update($this->call(['timeout' => 5, 'command' => 'ls'], 'call_2'));

        $this->assertNull($next->pendingPermission(), 'the granted call was asked about again');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->reap($cmd);
    }

    /**
     * A gate grant does not answer a call where a user hook ALSO asks: the
     * chain's ask names both askers, and only a gate-only ask is grantable.
     */
    public function testAGateGrantDoesNotAnswerAnAskAUserHookJoined(): void
    {
        $chat = $this->chat(
            $this->gate(),
            $this->askingHook('confirm-prod', static fn(HookContext $c): bool =>
                str_contains((string) ($c->toolArgs['command'] ?? ''), 'prod')),
        );

        // The gate and the user hook both ask about a prod deploy: not grantable.
        $granted = $this->answerAlways($chat, ['command' => 'deploy prod']);
        $this->assertSame([], $granted->permissionGrants(), 'the first ask was joined by the user hook, so nothing is granted');

        // Only the gate asks about a staging deploy: granted, for that call.
        $plain = $this->answerAlways($chat, ['command' => 'deploy staging']);
        $this->assertSame(['call:bash {"command":"deploy staging"}' => true], $plain->permissionGrants());
    }

    // ---- fixtures ----------------------------------------------------------

    private function chat(HookInterface ...$hooks): Chat
    {
        $manager = new HookManager(new HookRegistry());
        foreach ($hooks as $hook) {
            $manager->register($hook);
        }

        return (new Chat())
            ->registerTool('bash', static fn(array $args): string => 'ran: ' . ($args['command'] ?? ''))
            ->withHooks($manager);
    }

    /**
     * Raise the prompt for $args, answer Always, and see the released call
     * through so its forked child is reaped.
     *
     * @param array<string, mixed> $args
     */
    private function answerAlways(Chat $chat, array $args): Chat
    {
        [$suspended] = $chat->update($this->call($args, 'call_1'));
        $this->assertNotNull($suspended->pendingPermission(), 'fixture: the call must be asked about');

        [$granted, $cmd] = $suspended->update(new PermissionReplyMsg(PermissionReply::Always));
        $this->assertNull($granted->pendingPermission());
        $this->assertInstanceOf(\Closure::class, $cmd);

        return $this->reap($cmd, $granted);
    }

    /** @param array<string, mixed> $args */
    private function call(array $args, string $id): AssistantMsg
    {
        return new AssistantMsg(Message::assistant('running')->withToolCalls([new ToolCall('bash', $args, $id)]));
    }

    private function gate(): HookInterface
    {
        return new PermissionGateHook(new PermissionGate(PermissionMode::Default));
    }

    /** @param ?\Closure(HookContext): bool $asks */
    private function askingHook(string $name, ?\Closure $asks = null): HookInterface
    {
        return new class ($name, $asks ?? static fn(): bool => true) implements HookInterface {
            public function __construct(private readonly string $hookName, private readonly \Closure $asks) {}
            public function name(): string { return $this->hookName; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                return ($this->asks)($context) ? HookResult::ask('Confirm before touching prod?') : HookResult::allow();
            }
        };
    }

    /** Await a released batch and fold its results back into $model (when given). */
    private function reap(\Closure $cmd, ?Chat $model = null): Chat
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });

        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(ToolResultsMsg::class, $resolved, 'the released batch did not complete');

        if ($model === null) {
            return new Chat();
        }

        [$final] = $model->update($resolved);

        return $final;
    }
}
