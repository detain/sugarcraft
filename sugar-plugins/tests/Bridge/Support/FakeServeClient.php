<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Bridge\ServeClient;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A scripted serve connection: records every call, resolves synchronously,
 * and lets a test push event envelopes and follow results into the router.
 */
final class FakeServeClient implements ServeClient
{
    public ?\Closure $eventListener = null;

    public ?\Closure $followListener = null;

    public string $nextSessionId = 's1';

    /** @var list<array{method:string, params:array<string,mixed>}> */
    public array $calls = [];

    /** @var list<array{sessionId:string, text:string, delivery:string, images:array, documents:array}> */
    public array $sent = [];

    /**
     * Outcomes for successive send() calls: a Throwable rejects that call, null admits it.
     *
     * @var list<\Throwable|null>
     */
    public array $sendOutcomes = [];

    /** @var list<array{sessionId:string, askId:string, reply:string, note:?string}> */
    public array $permissionAnswers = [];

    /** @var list<string> session ids cancelled, in order */
    public array $cancelled = [];

    /** @var array<string, array<mixed>> method → reply for call() */
    public array $callReplies = [];

    /** @var list<string|null> names passed to createSession() */
    public array $createdNames = [];

    /** Creates to refuse, answered by a settled rejection the moment they are asked. */
    public int $createRefusals = 0;

    public function onEvent(\Closure $listener): void
    {
        $this->eventListener = $listener;
    }

    public function onFollowResult(\Closure $listener): void
    {
        $this->followListener = $listener;
    }

    public function call(string $method, array $params = []): PromiseInterface
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return resolve($this->callReplies[$method] ?? []);
    }

    public function follow(string $sessionId, ?int $afterSeq, ?\Closure $cursor = null): PromiseInterface
    {
        $this->calls[] = ['method' => 'follow', 'params' => ['sessionId' => $sessionId, 'afterSeq' => $afterSeq]];

        return resolve([]);
    }

    public function send(string $sessionId, string $text, string $delivery = 'queue', ?string $idempotencyKey = null, array $images = [], array $documents = []): PromiseInterface
    {
        $this->sent[] = ['sessionId' => $sessionId, 'text' => $text, 'delivery' => $delivery, 'images' => $images, 'documents' => $documents];
        $outcome = \array_shift($this->sendOutcomes);

        return $outcome instanceof \Throwable ? reject($outcome) : resolve(['admitted' => true]);
    }

    public function createSession(?string $name = null, ?string $permissionMode = null): PromiseInterface
    {
        $this->createdNames[] = $name;
        if ($this->createRefusals > 0) {
            $this->createRefusals--;

            return reject(new \RuntimeException('create refused'));
        }

        return resolve(['id' => $this->nextSessionId]);
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note = null): PromiseInterface
    {
        $this->permissionAnswers[] = ['sessionId' => $sessionId, 'askId' => $askId, 'reply' => $reply, 'note' => $note];

        return resolve(['answered' => true]);
    }

    public function cancel(string $sessionId, string $mode = 'hard'): PromiseInterface
    {
        $this->cancelled[] = $sessionId;

        return resolve(null);
    }

    /** @param array<string,mixed> $envelope */
    public function emit(array $envelope): void
    {
        ($this->eventListener)($envelope);
    }

    /** @param array<string,mixed> $result */
    public function followResult(string $sessionId, array $result): void
    {
        ($this->followListener)($sessionId, $result);
    }
}
