<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * The repo-local home of project-scope memory notes — E25 piece 2.
 *
 * Before this class there was exactly one writer that could reach the
 * `<project-memory>` prompt fold: the human's `/memory add --scope project`,
 * and its bytes landed in the SHARED store under `~/.sugar-crush/memory/`
 * ({@see MemoryStore}), where they outlived the repository and nothing in
 * `git status` could see them. A note about THIS project belongs WITH this
 * project: under `<root>/.sugar-crush/memory/`, reviewable and versionable
 * like any other tracked convention file (the `AGENTS.md` comparison is the
 * point — an actor with file-write footing in the tree could already plant
 * those bytes, so living in the tree adds no new capability, and piece 1's
 * fence discipline in {@see MemoryBlock::renderEntry()} is what stops
 * planted bytes from escalating through the prompt fold). The directory is
 * not new either: {@see \SugarCraft\Crush\Chat}'s `/memory import` already
 * stamps its `.imported-<target>` sentinels there.
 *
 * Two resolvers, two contracts, because reading and writing carry different
 * duties. {@forRoot()} is the reader the prompt fold uses and it NEVER
 * creates anything — a missing directory is the ordinary state, answered
 * with null. {@see createForRoot()} is the writer's resolver and may create
 * the tree. Both refuse a directory that does not resolve STRICTLY inside the
 * project root: `ContainedPath::below()` is the same gate `.mcp.json` faces,
 * policing the symlink a cloned tree can actually carry (see the class
 * doc-block there for why symlinks and not hard links are the case
 * containment serves here).
 *
 * Fail-fast bound: one note's content is capped at {@see MAX_CONTENT_BYTES}.
 * The fold already clips each entry to {@see MemoryBlock::MAX_ENTRY_BYTES}
 * at render time, but the cap here keeps a pathological write from filling
 * the tree with megabytes of never-displayed bytes; the number is the store
 * format's headroom, not the prompt's.
 */
final class ProjectMemoryWriter
{
    /**
     * Where project notes live, relative to the repository root. Chosen to
     * match the sentinel directory `/memory import` already writes into —
     * one repo-local SugarCrush corner, not two.
     */
    public const RELATIVE_DIRECTORY = '.sugar-crush/memory';

    /**
     * Ceiling on one note's raw content, checked before anything is written.
     * Enforced as a byte length (`strlen`), not a character count, because
     * the bound's job is bounding what lands on disk.
     */
    public const MAX_CONTENT_BYTES = 8192;

    private function __construct(
        private readonly MemoryStore $store,
        private readonly string $directory,
    ) {}

    /**
     * The repo-local store for $root, if one already exists.
     *
     * Read-only by contract: no directory is ever created here, because this
     * resolver runs on every session's prompt fold and a read that lays down
     * `.sugar-crush/` in whatever tree the user happened to `cd` into is a
     * side effect nobody asked for. An empty $root is refused outright —
     * `realpath('')` answers with the process CWD, which would silently
     * anchor containment wherever PHP happened to be standing.
     */
    public static function forRoot(string $root): ?self
    {
        if ($root === '') {
            return null;
        }

        $directory = $root . '/' . self::RELATIVE_DIRECTORY;

        if (!is_dir($directory) || !is_writable($directory)) {
            return null;
        }

        if (!self::resolvesInsideRoot($directory, $root)) {
            return null;
        }

        return new self(new MemoryStore($directory), $directory);
    }

    /**
     * The repo-local store for $root, creating the tree when absent.
     *
     * Returns null — never throws — when the root does not exist or the
     * corner cannot be made to resolve inside it, so the single command
     * surface that writes project notes can degrade to the shared home store
     * instead of failing a user's `/memory add`. The containment gate runs
     * AFTER the mkdir on purpose: if `.sugar-crush` is already a symlink out
     * of the tree, `mkdir -p` happily writes through it, and only the
     * post-check can see that the bytes we would go on to store live
     * elsewhere.
     */
    public static function createForRoot(string $root): ?self
    {
        if ($root === '' || !is_dir($root)) {
            return null;
        }

        $directory = $root . '/' . self::RELATIVE_DIRECTORY;

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            return null;
        }

        if (!is_writable($directory) || !self::resolvesInsideRoot($directory, $root)) {
            return null;
        }

        return new self(new MemoryStore($directory), $directory);
    }

    /**
     * Persist one project note and return its id.
     *
     * The guards run first so the store below is only ever asked for bytes
     * this class has already judged; the id returned is the store's UUID and
     * the file lands at `directory()/<id>.md`.
     *
     * @param array<string> $tags
     */
    public function write(string $content, array $tags = []): string
    {
        if (trim($content) === '') {
            throw new \InvalidArgumentException('Project memory content must not be empty.');
        }

        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException(
                'Project memory content exceeds ' . self::MAX_CONTENT_BYTES . ' bytes.'
            );
        }

        return $this->store->add($content, MemoryScope::Project, $tags);
    }

    /** The store the prompt fold reads through — {@see MemoryBlock::capture()}. */
    public function store(): MemoryStore
    {
        return $this->store;
    }

    /** Absolute path of the repo-local memory directory. */
    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Both resolvers' shared gate: the memory directory must be the root's
     * OWN descendant, not a link's destination wearing the directory's name.
     */
    private static function resolvesInsideRoot(string $directory, string $root): bool
    {
        return ContainedPath::below($directory, $root);
    }
}
