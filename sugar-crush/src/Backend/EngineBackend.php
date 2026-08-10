<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;

/**
 * Bridges the chat-shell {@see Backend} seam to the full agent engine —
 * a {@see ProviderInterface} driven by the {@see Runtime}, with tools,
 * skills and hooks.
 *
 * This is what makes the merged product *work*: the tested {@see \SugarCraft\Crush\Chat}
 * Model keeps speaking its simple `complete(history): Message` contract,
 * while underneath each turn runs a bounded agentic loop — call the model,
 * execute any tool calls through the hook gate, feed the results back, and
 * repeat until the model stops calling tools (or {@see $maxSteps} is hit).
 *
 * The chassis works in the root {@see Message} value object; the engine
 * works in the typed {@see \SugarCraft\Crush\Messages\Message} hierarchy.
 * Conversion happens here at the seam.
 */
final class EngineBackend implements Backend
{
    /**
     * Hard wall-clock ceiling on a forked completion child in
     * {@see completeAsync()} - a hung provider (or a tool stuck in the
     * agentic loop) is SIGKILLed and reported as a timeout rather than
     * blocking that turn forever. Generous: up to {@see $maxSteps} model
     * round-trips can legitimately take a while.
     */
    private const COMPLETE_TIMEOUT_SECONDS = 120;

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool>   $tools
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly string $model,
        private readonly array $tools = [],
        private readonly array $skills = [],
        private readonly ?HookManager $hookManager = null,
        private readonly int $maxSteps = 8,
        private readonly bool $hooksDisabled = false,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self($provider, $model);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool> $tools
     */
    public function withTools(array $tools): self
    {
        return new self($this->provider, $this->model, $tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function withSkills(array $skills): self
    {
        return new self($this->provider, $this->model, $this->tools, $skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled);
    }

    public function withHooks(HookManager $hookManager): self
    {
        // An explicit hook manager always wins and clears any prior opt-out.
        return new self($this->provider, $this->model, $this->tools, $this->skills, $hookManager, $this->maxSteps, false);
    }

    /**
     * Escape hatch for callers that deliberately want an UNGUARDED engine —
     * no built-in hooks, no custom manager. Everything else is safe-by-default
     * (see {@see resolveHookManager()}), so opting out is an explicit choice.
     */
    public function withoutHooks(): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, null, $this->maxSteps, true);
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, max(1, $maxSteps), $this->hooksDisabled);
    }

    /**
     * Register BashEscapeDenyHook with the given worktree root to prevent Bash
     * commands from referencing paths outside the worktree.
     *
     * This wires the heuristic PreToolUse hook so that Bash commands are
     * checked before execution. Without this, Bash is confined only by the
     * `cd $worktreeRoot` prefix which does NOT prevent escape via `cd /` or
     * `..` traversal within the command string itself.
     *
     * @see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook
     */
    public function withWorktreeRoot(string $worktreeRoot): self
    {
        if ($this->hooksDisabled) {
            return $this;
        }

        $manager = $this->hookManager ?? new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new BashEscapeDenyHook($worktreeRoot));

        return new self($this->provider, $this->model, $this->tools, $this->skills, $manager, $this->maxSteps, false);
    }

    public function complete(array $history, ?callable $onToken = null): Message
    {
        $runtime = new Runtime($this->provider, $this->resolveHookManager());

        $app = App::new($this->provider, $this->model)
            ->withTools($this->tools)
            ->withEnabledSkills($this->skills)
            ->withMessages($this->toTypedMessages($history));

        $lastAssistant = null;

        // Bounded agentic loop: keep running while the model asks for tools.
        // The Runtime resolves one assistant turn + its tool calls per run();
        // we feed the results back and re-run until the model answers without
        // tools — or we hit the step ceiling (guards against runaway loops,
        // which neither sugar-crush nor candy-crush had).
        for ($step = 0; $step < $this->maxSteps; $step++) {
            $assistant = null;
            $toolResults = [];

            foreach ($runtime->run($app) as $message) {
                if ($message instanceof AssistantMessage) {
                    $assistant = $message;
                } elseif ($message instanceof ToolResultMessage) {
                    $toolResults[] = $message;
                }
            }

            if ($assistant !== null) {
                $lastAssistant = $assistant;
            }

            if ($toolResults === []) {
                break; // model answered without calling tools — done
            }

            $app = $app->withMessages([
                ...$app->messages,
                ...($assistant !== null ? [$assistant] : []),
                ...$toolResults,
            ]);
        }

        $content = $lastAssistant?->content() ?? '';
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        return Message::assistant($content);
    }

    /**
     * Runs {@see complete()} - one or more real, blocking provider HTTP calls
     * plus any tool execution the agentic loop drives - in a forked child so
     * the caller's event loop (the TUI's render/input loop) never blocks on
     * it. Without this, `Program`'s `futureTick()`-scheduled Cmd execution
     * calls this method's factory closure directly on the loop: the old
     * implementation wrapped a *synchronous* {@see complete()} call in a
     * `React\Promise\Promise` whose executor runs immediately (that's the
     * Promise constructor's contract, not deferred), so the "async" call was
     * really just a blocking one wearing a Promise - the whole terminal
     * froze (no spinner animation, no keystrokes, no Ctrl+C) for the full
     * duration of every provider round-trip. Forking moves that blocking
     * work off the loop entirely; the parent only watches a non-blocking
     * socket via {@see Loop::addReadStream()} for the result, so rendering
     * and input keep flowing while a turn is in flight - same rationale as
     * {@see \SugarCraft\Crush\Chat::executeToolsParallel()}'s R14b fork fix,
     * extended here to cross the ReactPHP loop boundary rather than just
     * fanning out sibling tool calls.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred);
        }

        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred);
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->completeAsyncBlocking($history, $onToken, $deferred);
        }

        if ($pid === 0) {
            $this->runCompleteInChild($childSocket, $history);
        }

        fclose($childSocket);
        stream_set_blocking($parentSocket, false);

        $loop = Loop::get();
        $buffer = '';
        $settled = false;

        // $timeoutTimer/$cancelTimer are assigned below, AFTER $teardown is
        // built (each timer's own callback needs to call $teardown) - they're
        // captured by reference here specifically so $teardown still sees
        // the real TimerInterface once addTimer()/addPeriodicTimer() below
        // assign into these same variables.
        $timeoutTimer = null;
        $cancelTimer = null;

        // Shared teardown for every way this can end (success, timeout,
        // cancellation): stop watching the socket, cancel BOTH timers
        // (critical for $cancelTimer, a periodic timer that would otherwise
        // keep polling forever after settling via a different path), and
        // reap the child so it never zombies. $rejectMessage is null on the
        // success path (the caller settles $deferred itself via
        // settleFromChildPayload(), after doing its own equivalent cleanup).
        $teardown = function (?string $rejectMessage) use (&$settled, $loop, $parentSocket, $pid, $deferred, &$timeoutTimer, &$cancelTimer): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $loop->removeReadStream($parentSocket);
            fclose($parentSocket);
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            if ($cancelTimer !== null) {
                $loop->cancelTimer($cancelTimer);
            }
            if ($rejectMessage !== null && function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            if ($rejectMessage !== null) {
                $deferred->reject(new \RuntimeException($rejectMessage));
            }
        };

        $timeoutTimer = $loop->addTimer(self::COMPLETE_TIMEOUT_SECONDS, function () use ($teardown): void {
            $teardown('Provider request timed out after ' . self::COMPLETE_TIMEOUT_SECONDS . 's');
        });

        // Escape-Escape abort (see Chat::update()'s Escape handling): the
        // cancellation flag can flip at any point after this call returns,
        // long after the closures below were built, so it has to be polled
        // rather than checked once up front.
        $cancelTimer = $cancellation === null ? null : $loop->addPeriodicTimer(0.1, function () use ($cancellation, $teardown): void {
            if ($cancellation->isCancelled()) {
                $teardown('Request cancelled');
            }
        });

        $loop->addReadStream($parentSocket, function ($stream) use (&$buffer, &$settled, $deferred, $loop, $pid, $onToken, &$timeoutTimer, &$cancelTimer): void {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                $loop->removeReadStream($stream);
                fclose($stream);
                if ($timeoutTimer !== null) {
                    $loop->cancelTimer($timeoutTimer);
                }
                if ($cancelTimer !== null) {
                    $loop->cancelTimer($cancelTimer);
                }
                if ($settled) {
                    return;
                }
                $settled = true;

                $status = 0;
                pcntl_waitpid($pid, $status);
                $this->settleFromChildPayload($buffer, $deferred, $onToken);

                return;
            }
            $buffer .= $chunk;
        });

        return $deferred->promise();
    }

    /**
     * The forked child's half of {@see completeAsync()}: run the real
     * (blocking) engine loop in isolation, write its outcome back over the
     * socket as a single serialized payload, and exit. Never returns.
     *
     * @param array<int, Message> $history
     */
    private function runCompleteInChild($childSocket, array $history): never
    {
        try {
            $message = $this->complete($history, null);
            $payload = serialize(['ok' => true, 'content' => $message->content]);
        } catch (\Throwable $e) {
            $payload = serialize(['ok' => false, 'error' => $e->getMessage()]);
        }

        fwrite($childSocket, $payload);
        fclose($childSocket);
        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
    }

    /**
     * Decode the forked child's payload and settle $deferred - resolving
     * with the real content (firing $onToken once, matching {@see complete()}'s
     * own one-shot-at-the-end semantics) or rejecting with its error message.
     * A missing/undecodable payload (child crashed before writing anything)
     * is reported as a failure rather than silently resolving empty.
     */
    private function settleFromChildPayload(string $buffer, Deferred $deferred, ?callable $onToken): void
    {
        $data = $buffer !== '' ? @unserialize($buffer, ['allowed_classes' => false]) : false;
        if (!is_array($data)) {
            $deferred->reject(new \RuntimeException('Provider worker process exited without a result'));

            return;
        }

        if (($data['ok'] ?? false) !== true) {
            $deferred->reject(new \RuntimeException((string) ($data['error'] ?? 'Provider worker process failed')));

            return;
        }

        $content = (string) ($data['content'] ?? '');
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        $deferred->resolve(Message::assistant($content));
    }

    /**
     * Fallback for an environment without pcntl/stream_socket_pair support:
     * the old synchronous-under-a-Promise behaviour. Blocks the caller for
     * the duration of the request instead of freezing the whole program
     * silently - a real capability gap, not a bug to hide.
     */
    private function completeAsyncBlocking(array $history, ?callable $onToken, Deferred $deferred): PromiseInterface
    {
        try {
            $deferred->resolve($this->complete($history, $onToken));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    /**
     * Resolve the hook manager that gates every tool call this turn.
     *
     * Safe-by-default: a backend constructed without an explicit
     * {@see withHooks()} call still registers the built-in hooks
     * ({@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}) so Bash/Edit/Write
     * tools never run unguarded. Callers opt out explicitly via
     * {@see withoutHooks()}.
     */
    private function resolveHookManager(): HookManager
    {
        if ($this->hookManager !== null) {
            return $this->hookManager;
        }

        $manager = new HookManager(new HookRegistry());
        if (!$this->hooksDisabled) {
            $manager->registerBuiltIns();
        }

        return $manager;
    }

    /**
     * Convert the chassis's root Message history into the engine's typed
     * message hierarchy.
     *
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history): array
    {
        $out = [];
        foreach ($history as $msg) {
            $out[] = match ($msg->role->value) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
                default => new SystemMessage($msg->content),
            };
        }

        return $out;
    }
}
