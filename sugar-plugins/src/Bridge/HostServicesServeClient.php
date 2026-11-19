<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Host\HostServices;

use function React\Promise\reject;

/**
 * The {@see ServeClient} the router uses, answered by a {@see HostServices} in process (PL-5d).
 *
 * A bundle used to dial its own host over a loopback WebSocket. This client keeps the router's
 * call shape and sends each call to the host's method for it instead: `command.exec` to
 * execCommand, `session.get` to sessionSnapshot, `permission.pending` to pendingPermissions,
 * follow to subscribeSession, send to sendTurn, cancel to cancelSession, and the rest by name.
 *
 * Every rejection passes through $refuse, which the bundle supplies to turn the host's refusal
 * into the error its router reads (the bundle's own error type, with the wire's kind). Without
 * one, the rejection passes through unchanged.
 *
 * onFollowResult is a no-op: the in-process door never fires follow results (see
 * {@see HostServices::subscribeFollowResults()}), and a follow's answer is the follow() result.
 */
final class HostServicesServeClient implements ServeClient
{
    /** @var list<\Closure(array<string,mixed>): void> */
    private array $listeners = [];

    private bool $subscribed = false;

    /** @param (\Closure(string, \Throwable): \Throwable)|null $refuse maps a rejection of $method to the router's error */
    public function __construct(
        private readonly HostServices $host,
        private readonly ?\Closure $refuse = null,
    ) {
    }

    public function onEvent(\Closure $listener): void
    {
        $this->listeners[] = $listener;
        if ($this->subscribed) {
            return;
        }
        // One host subscription fans out to every listener, so a second onEvent() never
        // doubles the delivery.
        $this->subscribed = true;
        $this->host->subscribeEvents(function (array $envelope): void {
            $this->deliver($envelope);
        });
    }

    /**
     * Runs every listener even when one throws, so a failing listener never starves its
     * siblings of the envelope. The failures are rethrown once the fan-out is done, so the
     * host's drain still logs them: one failure rethrows as itself, several as one aggregate
     * that carries the first as its previous.
     *
     * @param array<string,mixed> $envelope
     */
    private function deliver(array $envelope): void
    {
        $failures = [];
        foreach ($this->listeners as $listener) {
            try {
                $listener($envelope);
            } catch (\Throwable $e) {
                $failures[] = $e;
            }
        }

        if ($failures === []) {
            return;
        }
        if (\count($failures) === 1) {
            throw $failures[0];
        }

        $messages = \array_map(static fn (\Throwable $e): string => $e->getMessage(), $failures);
        throw new \RuntimeException(
            \count($failures) . ' event listeners failed: ' . \implode('; ', $messages),
            0,
            $failures[0],
        );
    }

    public function onFollowResult(\Closure $listener): void
    {
        // The in-process door never fires follow results; see the class docblock.
    }

    public function call(string $method, array $params = []): PromiseInterface
    {
        return match ($method) {
            'command.exec' => $this->settle($method, $this->host->execCommand(
                (string) ($params['sessionId'] ?? ''),
                (string) ($params['name'] ?? ''),
                (string) ($params['args'] ?? ''),
            )),
            'session.get' => $this->settle($method, $this->host->sessionSnapshot(
                (string) ($params['sessionId'] ?? ''),
                (int) ($params['limit'] ?? 1),
            )),
            'permission.pending' => $this->settle($method, $this->host->pendingPermissions()),
            default => reject(new \InvalidArgumentException("no in-process host call for {$method}")),
        };
    }

    public function follow(string $sessionId, ?int $afterSeq, ?\Closure $cursor = null): PromiseInterface
    {
        return $this->settle('session.subscribe', $this->host->subscribeSession($sessionId, $afterSeq, $cursor));
    }

    public function send(string $sessionId, string $text, string $delivery = 'queue', ?string $idempotencyKey = null, array $images = [], array $documents = []): PromiseInterface
    {
        return $this->settle('session.send', $this->host->sendTurn($sessionId, $text, $delivery, $idempotencyKey, $images, $documents));
    }

    public function createSession(?string $name = null, ?string $permissionMode = null): PromiseInterface
    {
        return $this->settle('session.create', $this->host->createSession($name, $permissionMode));
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note = null): PromiseInterface
    {
        return $this->settle('permission.respond', $this->host->respondPermission($sessionId, $askId, $reply, $note));
    }

    public function cancel(string $sessionId, string $mode = 'hard'): PromiseInterface
    {
        return $this->settle('session.cancel', $this->host->cancelSession($sessionId, $mode));
    }

    /** Passes an answer through and maps a rejection through the bundle's refusal mapper. */
    private function settle(string $method, PromiseInterface $answer): PromiseInterface
    {
        if ($this->refuse === null) {
            return $answer;
        }

        return $answer->then(null, fn (\Throwable $e): PromiseInterface => reject(($this->refuse)($method, $e)));
    }
}
