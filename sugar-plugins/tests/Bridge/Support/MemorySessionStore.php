<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\SessionStore;

/**
 * An in-memory SessionStore. $ops records the order of writes, so a test can
 * assert which durable step happened before which.
 */
final class MemorySessionStore implements SessionStore
{
    /** @var array<string,string> key → sessionId */
    public array $bindings = [];

    /** @var array<string,int> sessionId → cursor */
    public array $cursors = [];

    /** @var array<string,array{key:string, ref:array<string,mixed>}> sessionId → last conversation */
    public array $conversations = [];

    /** @var array<string,true> "<platform>:<id>" → claimed */
    public array $claimed = [];

    /** @var list<string> */
    public array $ops = [];

    public function claimInbound(string $platform, string $inboundId): bool
    {
        $token = $platform . ':' . $inboundId;
        if (isset($this->claimed[$token])) {
            return false;
        }
        $this->claimed[$token] = true;

        return true;
    }

    public function confirmInbound(string $platform, string $inboundId): void
    {
        $this->ops[] = 'confirm';
    }

    public function forget(string $key): void
    {
        unset($this->bindings[$key]);
    }

    public function sessionIdFor(string $key): ?string
    {
        return $this->bindings[$key] ?? null;
    }

    public function bind(string $key, string $sessionId, ?string $peerId = null): void
    {
        $this->bindings[$key] = $sessionId;
    }

    public function allBindings(): array
    {
        $rows = [];
        foreach ($this->bindings as $key => $sessionId) {
            $rows[] = ['key' => $key, 'sessionId' => $sessionId];
        }

        return $rows;
    }

    public function cursorForSession(string $sessionId): int
    {
        return $this->cursors[$sessionId] ?? 0;
    }

    public function noteSeqForSession(string $sessionId, int $seq): void
    {
        $this->cursors[$sessionId] = $seq;
    }

    public function rememberConversation(string $sessionId, string $key, array $ref): void
    {
        $this->ops[] = 'remember';
        $this->conversations[$sessionId] = ['key' => $key, 'ref' => $ref];
    }

    public function lastConversation(string $sessionId): ?array
    {
        return $this->conversations[$sessionId] ?? null;
    }
}
