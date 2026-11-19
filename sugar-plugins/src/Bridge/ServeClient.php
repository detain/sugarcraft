<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

use React\Promise\PromiseInterface;

/**
 * The serve connection as the router uses it: session lifecycle, prompt
 * admission, permission answers, and the two event feeds. A bundle's
 * ServeBridge implements this; the router never names the concrete client.
 */
interface ServeClient
{
    /** Receive every session event envelope the connection delivers. */
    public function onEvent(\Closure $listener): void;

    /** Receive each follow result (pending asks, reset snapshots) keyed by session. */
    public function onFollowResult(\Closure $listener): void;

    /**
     * Raw RPC call, for the few methods the router reads directly
     * (command.exec, session.get, permission.pending).
     *
     * @param array<string,mixed> $params
     * @return PromiseInterface<mixed>
     */
    public function call(string $method, array $params = []): PromiseInterface;

    /**
     * Follow a session's event stream from a cursor. The cursor closure
     * reports the stored sequence so a reconnect resumes where it stopped.
     *
     * @return PromiseInterface<mixed>
     */
    public function follow(string $sessionId, ?int $afterSeq, ?\Closure $cursor = null): PromiseInterface;

    /**
     * Admit one prompt. The delivery word is the serve queue vocabulary
     * (queue, steer, collect, interrupt).
     *
     * @param list<array{name?:string,mime:string,data:string}> $images
     * @param list<array{name?:string,mime:string,data:string}> $documents
     * @return PromiseInterface<mixed>
     */
    public function send(string $sessionId, string $text, string $delivery = 'queue', ?string $idempotencyKey = null, array $images = [], array $documents = []): PromiseInterface;

    /** @return PromiseInterface<mixed> */
    public function createSession(?string $name = null, ?string $permissionMode = null): PromiseInterface;

    /** @return PromiseInterface<mixed> */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note = null): PromiseInterface;

    /** @return PromiseInterface<mixed> */
    public function cancel(string $sessionId, string $mode = 'hard'): PromiseInterface;
}
