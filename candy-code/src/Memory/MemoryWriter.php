<?php

declare(strict_types=1);

namespace SugarCraft\Code\Memory;

use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Context\ProjectMemoryWriter;

/**
 * The one place memory notes are routed to a store (roadmap 5.1-2), shared by
 * `/memory add` and the `Memory` tool so the two can never disagree about
 * where a note lands or which copy an id names.
 *
 * Writes: a `project` note goes to the repository's `.candy-code/memory/`
 * through {@see ProjectMemoryWriter::createForRoot()} whenever the tree can
 * host one, and falls back to the home store otherwise — the fallback is
 * reported in {@see MemorySaveResult}, never silent (15d-05). `user` and
 * `agent` notes go to the home store.
 *
 * Lookups: an id resolves in the repository store FIRST, the same precedence
 * {@see \SugarCraft\Code\Context\MemoryBlock::capture()} gives the repo copy
 * of a shared id, so the note the prompt's index shows is the note an edit or
 * delete reaches.
 *
 * The home store is resolved lazily: building the writer (and the tool that
 * holds it) touches nothing on disk; the first operation does.
 */
final class MemoryWriter
{
    /** The note types {@see MemoryEntry} carries and the prompt instructions name. */
    public const TYPES = ['pattern', 'convention', 'decision', 'preference', 'intent'];

    /** @var \Closure(): ?MemoryStore */
    private \Closure $homeResolver;

    private ?MemoryStore $home = null;

    private bool $homeResolved = false;

    /**
     * @param \Closure(): ?MemoryStore $home
     */
    private function __construct(\Closure $home, private readonly string $root)
    {
        $this->homeResolver = $home;
    }

    /**
     * @param MemoryStore|\Closure(): ?MemoryStore $home the home store, or a
     *        resolver called on first use (null when it cannot be opened)
     * @param string $root the project root; '' means no repository store
     */
    public static function new(MemoryStore|\Closure $home, string $root): self
    {
        return new self($home instanceof MemoryStore ? static fn (): MemoryStore => $home : $home, $root);
    }

    /**
     * A writer bound to ONE agent's per-agent store (C-AGT-3.b), used by
     * {@see \SugarCraft\Code\Tools\BuiltIn\MemoryTool} inside a delegation
     * whose preset declares `memory:`.
     *
     * The project root is deliberately '': there is no repository store here,
     * so every scope a note carries — including `project`, the tool's default
     * — routes to the agent's own store (its `<scope>` directory inside it).
     * A delegated run cannot reach the session's home store or the checkout's
     * repo store through this writer, and the agent's notes cannot leak into
     * either; the index the child prompt shows is the index of this store.
     */
    public static function forAgent(string $agentName, MemoryScope $scope, string $projectRoot): self
    {
        return new self(
            static fn (): ?MemoryStore => AgentMemory::storeFor($scope, $agentName, $projectRoot, create: true),
            '',
        );
    }

    /** The home store, or null when it cannot be opened. */
    public function home(): ?MemoryStore
    {
        if (!$this->homeResolved) {
            $this->homeResolved = true;
            try {
                $this->home = ($this->homeResolver)();
            } catch (\Throwable) {
                $this->home = null;
            }
        }

        return $this->home;
    }

    /** The repository's note store, or null when the tree has none. */
    public function repository(): ?MemoryStore
    {
        return ProjectMemoryWriter::forRoot($this->root)?->store();
    }

    /**
     * Save a new note.
     *
     * An `intent` note (C-CTX-3.f) additionally carries a `when:` trigger —
     * `path:<glob>` or `keyword:<word>` (or a bare value classified by
     * {@see MemoryEntry::normalizeWhen()}) — that {@see \SugarCraft\Code\Context\RulePathNudge}
     * consults at tool time. The trigger is refused LOUD at save: an intent
     * without one, a malformed one, and a `when:` on any other type all throw
     * rather than persist a note whose promise its trigger cannot keep.
     *
     * @param list<string> $tags
     *
     * @throws \InvalidArgumentException on an empty note, an unknown scope or type,
     *                                   a missing/malformed intent trigger, or a
     *                                   trigger on a non-intent type
     * @throws \RuntimeException when no store can take the note
     */
    public function save(string $content, string $scope = 'project', string $type = 'pattern', array $tags = [], ?string $when = null): MemorySaveResult
    {
        if (trim($content) === '') {
            throw new \InvalidArgumentException('a memory note must not be empty');
        }

        if (!\in_array($scope, ['project', 'user', 'agent'], true)) {
            throw new \InvalidArgumentException("unknown memory scope '{$scope}'");
        }

        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("unknown memory type '{$type}'");
        }

        $normalizedWhen = null;
        if ($type === 'intent') {
            if ($when === null || trim($when) === '') {
                throw new \InvalidArgumentException("type 'intent' needs a when: trigger ('path:<glob>' or 'keyword:<word>')");
            }
            $normalizedWhen = MemoryEntry::normalizeWhen($when);
        } elseif ($when !== null) {
            throw new \InvalidArgumentException("a when: trigger only applies to type 'intent'");
        }

        $repoWriter = $scope === 'project' ? ProjectMemoryWriter::createForRoot($this->root) : null;
        if ($repoWriter !== null) {
            $id = $repoWriter->write($content, $tags);
            self::restyle($repoWriter->store(), $id, $type, $normalizedWhen);

            return new MemorySaveResult($id, $scope, true, false);
        }

        $home = $this->home() ?? throw new \RuntimeException('the memory store is not available');
        $id = $home->add($content, $scope, $tags);
        self::restyle($home, $id, $type, $normalizedWhen);

        return new MemorySaveResult($id, $scope, false, $scope === 'project');
    }

    /**
     * The note an id names and the store that owns it, repository first.
     *
     * @return array{0: MemoryEntry, 1: MemoryStore}|null
     */
    public function locate(string $id): ?array
    {
        $repo = $this->repository();
        $entry = $repo?->get($id);
        if ($repo !== null && $entry !== null) {
            return [$entry, $repo];
        }

        $home = $this->home();
        $entry = $home?->get($id);

        return $home !== null && $entry !== null ? [$entry, $home] : null;
    }

    /**
     * Replace the one occurrence of $old in a note's content.
     *
     * @throws \InvalidArgumentException when the note is missing or $old does not occur exactly once
     */
    public function replace(string $id, string $old, string $new): MemoryEntry
    {
        [$entry, $store] = $this->locate($id) ?? throw new \InvalidArgumentException("no memory note '{$id}'");
        if ($old === '') {
            throw new \InvalidArgumentException('old_str must not be empty');
        }

        $count = substr_count($entry->content(), $old);
        if ($count !== 1) {
            throw new \InvalidArgumentException(
                $count === 0
                    ? "old_str does not occur in note '{$id}'"
                    : "old_str occurs {$count} times in note '{$id}'; include more context so it is unique",
            );
        }

        $content = str_replace($old, $new, $entry->content());
        if (trim($content) === '') {
            throw new \InvalidArgumentException('the edit would leave the note empty; delete it instead');
        }

        $updated = $entry->withContent($content)->withModifiedAt(new \DateTimeImmutable());
        $store->update($id, $updated);

        return $updated;
    }

    /** Delete a note; false when no store holds the id. */
    public function delete(string $id): bool
    {
        $located = $this->locate($id);
        if ($located === null) {
            return false;
        }

        $located[1]->delete($id);

        return true;
    }

    /**
     * Notes matching $query in the repository store and then the home store,
     * a note both hold listed once (the repository copy).
     *
     * @return list<MemoryEntry>
     */
    public function recall(string $query): array
    {
        $found = [];
        foreach ([$this->repository(), $this->home()] as $store) {
            foreach ($store?->search($query) ?? [] as $entry) {
                $found[$entry->id()] ??= $entry;
            }
        }

        return array_values($found);
    }

    /**
     * Intent notes carrying a live `when:` trigger, repository store first.
     *
     * The one reader {@see \SugarCraft\Code\Context\RulePathNudge} binds for
     * intent lookup (C-CTX-3.f): both stores, `project` then `user` scope,
     * deduped by id the same way {@see recall()} dedupes — the repository
     * copy of a shared id wins, matching the precedence an edit or delete
     * reaches. Notes whose trigger never round-tripped (or were cleared) are
     * excluded, so the nudge can never announce an intent that lost its
     * promise: an intent with no trigger is just a note body.
     *
     * @return list<MemoryEntry>
     */
    public function intentNotes(): array
    {
        $found = [];
        foreach ([$this->repository(), $this->home()] as $store) {
            if ($store === null) {
                continue;
            }
            foreach (['project', 'user'] as $scope) {
                try {
                    $notes = $store->list($scope);
                } catch (\Throwable) {
                    // A store that cannot be walked is no intent source; the
                    // nudge stays silent rather than failing the tool call.
                    continue;
                }
                foreach ($notes as $entry) {
                    if ($entry->type() === 'intent' && $entry->when() !== '') {
                        $found[$entry->id()] ??= $entry;
                    }
                }
            }
        }

        return array_values($found);
    }

    /**
     * Apply the type (and, for an intent, the normalised trigger) a store's
     * default constructor could not know, in one read-modify-write.
     */
    private static function restyle(MemoryStore $store, string $id, string $type, ?string $when): void
    {
        if ($type === 'pattern' && $when === null) {
            return;
        }

        $entry = $store->get($id);
        if ($entry === null) {
            return;
        }

        if ($type !== 'pattern') {
            $entry = $entry->withType($type);
        }
        if ($when !== null) {
            $entry = $entry->withWhen($when);
        }
        $store->update($id, $entry);
    }
}
