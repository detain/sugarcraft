<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Support;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\InteractiveTurn;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Events\PermissionResolved;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * A backend whose turn runs until the test says otherwise, and which can put
 * permission questions to its caller the way {@see \SugarCraft\Code\Backend\EngineBackend}'s
 * parent does: a {@see PermissionAsked} carrying a {@see PendingAsk}, and a
 * {@see PermissionResolved} on the same channel once someone answers.
 */
final class ScriptedTurnBackend implements InteractiveTurn
{
    /** @var list<string> the newest user row of each dispatch */
    public array $sent = [];

    /** @var list<Message> the same rows whole — attachments a send carried ride them (WEB-5b) */
    public array $userMessages = [];

    /** @var list<PermissionResolved> every settlement a question reached */
    public array $settled = [];

    private ?Deferred $deferred = null;

    private ?\Closure $onEvent = null;

    private ?\Closure $onToken = null;

    private ?CancellationToken $cancellation = null;

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        return Message::assistant('unused');
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return $this->completeInteractive($history, $onToken, $cancellation, $onEvent);
    }

    public function completeInteractive(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null, ?callable $onReasoning = null, ?callable $onStep = null): PromiseInterface
    {
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role === Role::User) {
                $this->sent[] = $history[$i]->content;
                $this->userMessages[] = $history[$i];
                break;
            }
        }
        $deferred = new Deferred();
        $this->deferred = $deferred;
        $this->onEvent = $onEvent === null ? null : \Closure::fromCallable($onEvent);
        $this->onToken = $onToken === null ? null : \Closure::fromCallable($onToken);
        $this->cancellation = $cancellation;
        $cancellation?->onCancel(static function () use ($deferred): void {
            $deferred->reject(new \RuntimeException('cancelled'));
        });

        return $deferred->promise();
    }

    public function isRunning(): bool
    {
        return $this->deferred !== null;
    }

    public function cancellation(): ?CancellationToken
    {
        return $this->cancellation;
    }

    /** Report $event on the running turn's channel. */
    public function emit(object $event): void
    {
        if ($this->onEvent !== null) {
            ($this->onEvent)($event);
        }
    }

    /** Stream $text as reply tokens. */
    public function token(string $text): void
    {
        if ($this->onToken !== null) {
            ($this->onToken)($text);
        }
    }

    /**
     * Ask about a $tool call, as the turn child's `ask` frame would.
     *
     * @param array<string, mixed> $arguments
     */
    public function ask(string $toolCallId, string $tool, array $arguments, bool $offersAlways = true): PendingAsk
    {
        $pending = PendingAsk::fromFrame([
            'askId' => PendingAsk::askId($toolCallId, $tool, $arguments),
            'toolCallId' => $toolCallId,
            'tool' => $tool,
            'arguments' => $arguments,
            'reason' => 'needs approval',
            'source' => 'gate',
            'mode' => 'default',
            'suggestions' => $offersAlways ? ['once', 'always', 'reject'] : ['once', 'reject'],
            'alwaysScope' => $offersAlways ? ['tool' => $tool] : [],
        ], function (PermissionResolved $resolution): void {
            $this->settled[] = $resolution;
            $this->emit($resolution);
        });
        \assert($pending instanceof PendingAsk);
        $this->emit(new PermissionAsked($pending));

        return $pending;
    }

    /** End the running turn with $reply. */
    public function settle(Message $reply): void
    {
        $deferred = $this->deferred;
        $this->deferred = null;
        $deferred?->resolve($reply);
    }
}
