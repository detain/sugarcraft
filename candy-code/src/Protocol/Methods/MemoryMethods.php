<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Commands\MemoryHistoryCommand;
use SugarCraft\Code\Memory\MemoryEntry;
use SugarCraft\Code\Memory\MemoryHistory;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;

/**
 * `memory.*` (Appendix O §6.3): the `/memory` surface over the workspace's
 * memory stores — list a scope, search every scope, add, edit, delete.
 *
 * ISS-020: the surface is routed through {@see MemoryWriter}, the same router
 * `/memory` and the `Memory` tool use, so a note the model saves and a note
 * the panel lists are the same note. Since E25 piece 2 a project-scope note
 * lives in the repository's `.candy-code/memory/` whenever the tree can host
 * one; answering from the home store alone made those notes invisible here,
 * and made an id the add answered with unresolvable on the next call. So:
 *
 *  - `list`/`search` read BOTH stores, each item badged with its `source`
 *    ('repo' or 'home'), repository rows first;
 *  - `add` saves through the writer — a project note lands in the repo
 *    store and is reported as such, a fallback to home is never silent
 *    (`fellBackToHome`);
 *  - `edit`/`delete` resolve ids through {@see MemoryWriter::locate()}, so
 *    the owning store's file is the one that mutates;
 *  - every mutation runs the history sandwich `/memory` runs (roadmap
 *    5.4-2): pending outside changes commit under their own subject first,
 *    the panel's change commits under `memory: panel <verb>`. The browser
 *    has no transcript to ride a history warning after, so a failed commit
 *    is deliberately not surfaced here — the uncommitted change still lands
 *    under the outside subject at the next recording, exactly as before.
 */
final class MemoryMethods
{
    private const SCOPES = ['user', 'project', 'agent'];

    private const MAX_NOTE_BYTES = 65_536;

    /** The repository's `.candy-code/memory/` — the shared tree. */
    public const SOURCE_REPO = 'repo';

    /** The per-user home memory directory. */
    public const SOURCE_HOME = 'home';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('memory.list', Scope::Read, 'The notes of one memory scope, from both stores, each badged with its source.', self::list(...)));
        $registry->add(MethodSpec::new('memory.search', Scope::Read, 'Notes matching a query, best first, across every scope and both stores.', self::search(...)));
        $registry->add(MethodSpec::new('memory.add', Scope::Write, 'Add a note, routed like `/memory add`: a project note goes to the repository store when the tree can host one.', self::add(...), true));
        $registry->add(MethodSpec::new('memory.edit', Scope::Write, 'Replace a note\'s text in the store that owns the id.', self::edit(...), true));
        $registry->add(MethodSpec::new('memory.delete', Scope::Write, 'Delete a note from the store that owns the id.', self::delete(...), true));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', self::SCOPES, 'user');
        $writer = self::writer($call);
        $items = [];
        // The grouping rule `/memory list` ships: repo rows lead, home rows
        // follow, and only the scope that can host repo notes reads two
        // stores. No dedupe by id here — like the CLI listing, each group
        // says what is on its disk; `search` is where a shared id lists once.
        $repo = $scope === 'project' ? $writer->repository() : null;
        foreach ($repo?->list($scope) ?? [] as $entry) {
            $items[] = self::item($entry, self::SOURCE_REPO);
        }
        foreach (self::homeStore($writer)->list($scope) as $entry) {
            $items[] = self::item($entry, self::SOURCE_HOME);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private static function search(CallContext $call, Params $params): array
    {
        $limit = $params->int('limit', 50, 1, 500);
        $query = $params->string('query', 1024);
        $writer = self::writer($call);
        $seen = [];
        $items = [];
        foreach ($writer->repository()?->search($query) ?? [] as $entry) {
            $seen[$entry->id()] = true;
            $items[] = self::item($entry, self::SOURCE_REPO);
        }
        foreach (self::homeStore($writer)->search($query) as $entry) {
            // The fold law restated for search (MemoryWriter::recall): a note
            // both stores hold lists once, as its repository copy.
            if (isset($seen[$entry->id()])) {
                continue;
            }
            $items[] = self::item($entry, self::SOURCE_HOME);
        }

        return ['items' => \array_slice($items, 0, $limit)];
    }

    /** @return array<string, mixed> */
    private static function add(CallContext $call, Params $params): array
    {
        $content = $params->string('content', self::MAX_NOTE_BYTES);
        $scope = $params->enum('scope', self::SCOPES, 'user');
        $tags = $params->strings('tags', 32);
        $writer = self::writer($call);
        try {
            $saved = self::recordAround($writer, 'add', static fn () => $writer->save($content, $scope, 'pattern', $tags));
        } catch (RpcError $e) {
            throw $e; // a refusal the sandwich itself typed (never here today) rides through
        } catch (\InvalidArgumentException $e) {
            // gap-minor N-3: an empty note or the project store's 8 KiB bound
            // (ProjectMemoryWriter::write) is an input refusal the panel can
            // show honestly — never the dispatcher's -32099 internal envelope.
            throw RpcError::invalidParams($e->getMessage());
        } catch (\RuntimeException $e) {
            // no store could take the note: the same typed refusal the reads answer with
            throw RpcError::of(ErrorCode::UnsupportedInServer, $e->getMessage(), 'memory_unavailable');
        }

        return [
            'id' => $saved->id,
            'source' => $saved->inRepository ? self::SOURCE_REPO : self::SOURCE_HOME,
            'fellBackToHome' => $saved->fellBackToHome,
        ];
    }

    /** @return array<string, mixed> */
    private static function edit(CallContext $call, Params $params): array
    {
        $id = $params->string('id', 128);
        $content = $params->string('content', self::MAX_NOTE_BYTES);
        $writer = self::writer($call);
        self::recordAround($writer, 'edit', static function () use ($writer, $id, $content): void {
            [$entry, $store] = $writer->locate($id)
                ?? throw RpcError::notFound(\sprintf('no note %s', $id), 'note_not_found');
            $store->update($id, $entry->withContent($content));
        });

        return ['id' => $id];
    }

    /** @return array<string, mixed> */
    private static function delete(CallContext $call, Params $params): array
    {
        $id = $params->string('id', 128);
        $writer = self::writer($call);
        self::recordAround($writer, 'delete', static function () use ($writer, $id): void {
            if (!$writer->delete($id)) {
                throw RpcError::notFound(\sprintf('no note %s', $id), 'note_not_found');
            }
        });

        return ['deleted' => true];
    }

    /**
     * Run one mutation inside the history sandwich `/memory` runs: whatever
     * changed since the last commit is credited outside first, the panel's
     * own change commits under `memory: panel <action>` after. A mutation
     * that threw never earns its subject — its residue (an index rewrite
     * that died after the note landed) is credited outside, as it would be
     * at the next recording, and the error rides on to the caller.
     *
     * Only the HOME directory is versioned; {@see MemoryHistory::forStore()}
     * answers null for a repository store, so a repo-note mutation degrades
     * to a plain run like the CLI's does.
     *
     * @template T
     *
     * @param \Closure(): T $mutate
     *
     * @return T
     */
    private static function recordAround(MemoryWriter $writer, string $action, \Closure $mutate): mixed
    {
        $history = MemoryHistory::forStore(self::homeStore($writer));
        MemoryHistoryCommand::record($history, MemoryHistoryCommand::OUTSIDE_SUBJECT);
        try {
            $done = $mutate();
        } catch (\Throwable $e) {
            MemoryHistoryCommand::record($history, MemoryHistoryCommand::OUTSIDE_SUBJECT);

            throw $e;
        }
        MemoryHistoryCommand::record($history, 'memory: panel ' . $action);

        return $done;
    }

    /**
     * The home store or a refusal — the same `memory_unavailable` the surface
     * answered with before this class learned about two stores.
     */
    private static function homeStore(MemoryWriter $writer): MemoryStore
    {
        return $writer->home() ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace has no memory store', 'memory_unavailable');
    }

    /**
     * The router `/memory` shares with the `Memory` tool, over the
     * workspace's home store and project root — a server with no root has no
     * repository half, exactly like a headless `--root ''` launch.
     */
    private static function writer(CallContext $call): MemoryWriter
    {
        return MemoryWriter::new(self::store($call), $call->server->hub()->workspace()->root ?? '');
    }

    private static function store(CallContext $call): MemoryStore
    {
        return $call->server->hub()->workspace()->memoryStore
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace has no memory store', 'memory_unavailable');
    }

    /**
     * One wire item: the entry's fields plus the store it came from — an id
     * does not say which tree holds the file, so the row says it (ISS-020).
     *
     * @param self::SOURCE_REPO|self::SOURCE_HOME $source
     *
     * @return array<string, mixed>
     */
    private static function item(MemoryEntry $entry, string $source): array
    {
        return $entry->toArray() + ['source' => $source];
    }
}
