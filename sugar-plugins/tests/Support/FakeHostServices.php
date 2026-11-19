<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Host\HostServices;
use function React\Promise\resolve;

/**
 * A {@see HostServices} that records every call in order and answers from a script.
 *
 * A method not named in the script returns its default: an empty array, a resolved
 * promise, `false` for isConnected, or nothing for the void calls. Each call is
 * recorded under its method name with the arguments it received.
 */
final class FakeHostServices implements HostServices
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $results scripted return values keyed by method name */
    public function __construct(private array $results = [])
    {
    }

    public function hello(): array
    {
        return $this->record('hello', [], []);
    }

    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): array
    {
        return $this->record('subscribeSession', [$sessionId, $afterSeq, $cursor], []);
    }

    public function unsubscribeSession(string $sessionId): void
    {
        $this->record('unsubscribeSession', [$sessionId], null);
    }

    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): array {
        return $this->record(
            'sendTurn',
            [$sessionId, $text, $delivery, $idempotencyKey, $images, $documents],
            [],
        );
    }

    public function createSession(?string $name, ?string $permissionMode): array
    {
        return $this->record('createSession', [$name, $permissionMode], []);
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): array
    {
        return $this->record('respondPermission', [$sessionId, $askId, $reply, $note], []);
    }

    public function cancelSession(string $sessionId, string $mode): array
    {
        return $this->record('cancelSession', [$sessionId, $mode], []);
    }

    public function sessionSnapshot(string $sessionId, int $limit): array
    {
        return $this->record('sessionSnapshot', [$sessionId, $limit], []);
    }

    public function execCommand(string $sessionId, string $name, string $args): array
    {
        return $this->record('execCommand', [$sessionId, $name, $args], []);
    }

    public function pendingPermissions(): array
    {
        return $this->record('pendingPermissions', [], []);
    }

    public function connect(): PromiseInterface
    {
        return $this->record('connect', [], resolve(null));
    }

    public function disconnect(): void
    {
        $this->record('disconnect', [], null);
    }

    public function isConnected(): bool
    {
        return $this->record('isConnected', [], false);
    }

    public function followedSessions(): array
    {
        return $this->record('followedSessions', [], []);
    }

    public function subscribeEvents(\Closure $listener): void
    {
        $this->record('subscribeEvents', [$listener], null);
    }

    public function subscribeFollowResults(\Closure $listener): void
    {
        $this->record('subscribeFollowResults', [$listener], null);
    }

    /**
     * @param list<mixed> $args
     */
    private function record(string $method, array $args, mixed $default): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        return array_key_exists($method, $this->results) ? $this->results[$method] : $default;
    }
}
