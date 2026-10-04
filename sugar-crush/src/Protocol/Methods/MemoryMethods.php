<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;

/**
 * `memory.*` (Appendix O §6.3): the `/memory` surface over the workspace's
 * {@see MemoryStore} — list a scope, search every scope, add, edit, delete.
 */
final class MemoryMethods
{
    private const SCOPES = ['user', 'project', 'agent'];

    private const MAX_NOTE_BYTES = 65_536;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('memory.list', Scope::Read, 'The notes of one memory scope.', self::list(...)));
        $registry->add(MethodSpec::new('memory.search', Scope::Read, 'Notes matching a query, best first, across every scope.', self::search(...)));
        $registry->add(MethodSpec::new('memory.add', Scope::Write, 'Add a note.', self::add(...), true));
        $registry->add(MethodSpec::new('memory.edit', Scope::Write, 'Replace a note\'s text.', self::edit(...), true));
        $registry->add(MethodSpec::new('memory.delete', Scope::Write, 'Delete a note.', self::delete(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $entries = self::store($call)->list($params->enum('scope', self::SCOPES, 'user'));

        return ['items' => \array_map(static fn (MemoryEntry $entry): array => $entry->toArray(), \array_values($entries))];
    }

    /** @return array<string, mixed> */
    private static function search(CallContext $call, Params $params): array
    {
        $limit = $params->int('limit', 50, 1, 500);
        $entries = \array_slice(\array_values(self::store($call)->search($params->string('query', 1024))), 0, $limit);

        return ['items' => \array_map(static fn (MemoryEntry $entry): array => $entry->toArray(), $entries)];
    }

    /** @return array<string, mixed> */
    private static function add(CallContext $call, Params $params): array
    {
        $id = self::store($call)->add(
            $params->string('content', self::MAX_NOTE_BYTES),
            $params->enum('scope', self::SCOPES, 'user'),
            $params->strings('tags', 32),
        );

        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    private static function edit(CallContext $call, Params $params): array
    {
        $store = self::store($call);
        $id = $params->string('id', 128);
        $entry = $store->get($id) ?? throw RpcError::notFound(\sprintf('no note %s', $id), 'note_not_found');
        $store->update($id, $entry->withContent($params->string('content', self::MAX_NOTE_BYTES)));

        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    private static function delete(CallContext $call, Params $params): array
    {
        $store = self::store($call);
        $id = $params->string('id', 128);
        if ($store->get($id) === null) {
            throw RpcError::notFound(\sprintf('no note %s', $id), 'note_not_found');
        }
        $store->delete($id);

        return ['deleted' => true];
    }

    private static function store(CallContext $call): MemoryStore
    {
        return $call->server->hub()->workspace()->memoryStore
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace has no memory store', 'memory_unavailable');
    }
}
