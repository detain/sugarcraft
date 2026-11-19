<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Host\HostServices;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A {@see HostServices} that records every call in order and answers from a script.
 *
 * Every method returns a promise, as the contract does. A method named in the script
 * resolves to its scripted value, and a scripted {@see \Throwable} rejects with it, so a
 * test can drive both the answer and the refusal of a call. A method not named in the
 * script resolves to its default: an empty array, `false` for isConnected, an empty list
 * for followedSessions, or null for the void calls. Each call is recorded under its method
 * name with the arguments it received.
 */
final class FakeHostServices implements HostServices
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $results scripted answers keyed by method name; a \Throwable rejects */
    public function __construct(private array $results = [])
    {
    }

    public function hello(): PromiseInterface
    {
        return $this->record('hello', [], []);
    }

    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): PromiseInterface
    {
        return $this->record('subscribeSession', [$sessionId, $afterSeq, $cursor], []);
    }

    public function unsubscribeSession(string $sessionId): PromiseInterface
    {
        return $this->record('unsubscribeSession', [$sessionId], null);
    }

    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): PromiseInterface {
        return $this->record(
            'sendTurn',
            [$sessionId, $text, $delivery, $idempotencyKey, $images, $documents],
            [],
        );
    }

    public function createSession(?string $name, ?string $permissionMode): PromiseInterface
    {
        return $this->record('createSession', [$name, $permissionMode], []);
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): PromiseInterface
    {
        return $this->record('respondPermission', [$sessionId, $askId, $reply, $note], []);
    }

    public function cancelSession(string $sessionId, string $mode): PromiseInterface
    {
        return $this->record('cancelSession', [$sessionId, $mode], []);
    }

    public function sessionSnapshot(string $sessionId, int $limit): PromiseInterface
    {
        return $this->record('sessionSnapshot', [$sessionId, $limit], []);
    }

    public function execCommand(string $sessionId, string $name, string $args): PromiseInterface
    {
        return $this->record('execCommand', [$sessionId, $name, $args], []);
    }

    public function pendingPermissions(): PromiseInterface
    {
        return $this->record('pendingPermissions', [], []);
    }

    public function connect(): PromiseInterface
    {
        return $this->record('connect', [], null);
    }

    public function disconnect(): PromiseInterface
    {
        return $this->record('disconnect', [], null);
    }

    public function isConnected(): PromiseInterface
    {
        return $this->record('isConnected', [], false);
    }

    public function followedSessions(): PromiseInterface
    {
        return $this->record('followedSessions', [], []);
    }

    public function subscribeEvents(\Closure $listener): PromiseInterface
    {
        return $this->record('subscribeEvents', [$listener], null);
    }

    public function subscribeFollowResults(\Closure $listener): PromiseInterface
    {
        return $this->record('subscribeFollowResults', [$listener], null);
    }

    /**
     * @param list<mixed> $args
     */
    private function record(string $method, array $args, mixed $default): PromiseInterface
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        $answer = array_key_exists($method, $this->results) ? $this->results[$method] : $default;

        return $answer instanceof \Throwable ? reject($answer) : resolve($answer);
    }
}
