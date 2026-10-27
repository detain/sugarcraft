<?php

declare(strict_types=1);

namespace SugarCraft\Code\Memory;

use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\ProjectRoot;

/**
 * The per-agent memory stores a preset's `memory:` tier selects (C-AGT-3.b).
 *
 * A preset that declares `memory:` gives its delegated runs a store of their
 * OWN, separate from the session's home and checkout stores: the index of the
 * agent's notes rides the child's system prompt (via
 * {@see \SugarCraft\Code\Agents\AgentManager::resolveBatchSystemPrompts()}),
 * and the `Memory` tool in a delegation writes this store (via
 * {@see MemoryWriter::forAgent()}). An agent named in two projects keeps two
 * project-tier stores; the user tier follows the home directory instead.
 *
 * THE THREE TIERS, mirroring {@see MemoryScope}:
 *
 * - `user`  → `~/.candy-code/agent-memory/<name>/`
 * - `project` → `<root>/.candy-code/agent-memory/<name>/`
 * - `local`   → `<root>/.candy-code/agent-memory-local/<name>/`, with a
 *   `*` `.gitignore` planted in `agent-memory-local/` so private per-agent
 *   notes never ride a commit — the deliberate opposite of
 *   {@see \SugarCraft\Code\Context\ProjectMemoryWriter}'s repo store, which
 *   is shared-by-design and tracked.
 *
 * Both `user` and `project` live under a directory named `agent-memory`
 * because they can never collide: one hangs off the home directory, the other
 * off the project root, and the two are different trees for any real launch.
 *
 * LAWS CARRIED FROM {@see \SugarCraft\Code\Context\ProjectMemoryWriter}:
 * the reader never creates — folding a prompt must not lay down `.candy-code`
 * in an arbitrary tree — and the writer re-checks containment AFTER `mkdir`,
 * because a planted symlink at `.candy-code` is written THROUGH by the mkdir
 * of a path beneath it; only the post-mkdir check sees the escape. The empty
 * root is refused before any resolution, since `realpath('')` names the CWD.
 *
 * WHY THE NAME IS SANITISED rather than rejected: a preset's file name is its
 * agent's identity, and a hostile or sloppy frontmatter `name:` (spaces,
 * slashes, dots) must not become a path traversal or an unwritable file name.
 * {@see safeName()} maps every name into the note-id grammar the store itself
 * enforces, so whatever directory results is one `MemoryStore` can lawfully
 * use. The one collision the grammar cannot carry is the index stem — see the
 * `memory` special case there.
 *
 * OLD BACKGROUND FRAMES: {@see \SugarCraft\Code\Agents\Agent::fromArray()}
 * decodes a missing or unknown `memory` as null from C-AGT-3.b on, but frames
 * written by earlier builds carry `'memory' => 'user'` explicitly, so a
 * suspended run resumed after this change restores the USER tier rather than
 * silence. That is benign and disclosed here rather than rewritten: the tier
 * was never read before, so the store it selects starts empty, and the note
 * bytes a resuming agent finds are only ones it saved itself after the
 * resume.
 */
final class AgentMemory
{
    /** Where a user-tier agent store lives, under the owned home directory. */
    public const USER_RELATIVE_DIRECTORY = '.candy-code/agent-memory';

    /** Where a project-tier agent store lives, under the project root. */
    public const PROJECT_RELATIVE_DIRECTORY = '.candy-code/agent-memory';

    /** Where a local-tier agent store lives — git-ignored, never shared. */
    public const LOCAL_RELATIVE_DIRECTORY = '.candy-code/agent-memory-local';

    /** The note-id grammar caps an id at 64 characters; a store name caps too. */
    private const NAME_MAX_CHARS = 64;

    private function __construct()
    {
    }

    /**
     * Map an agent's declared name into {@see MemoryStore}'s note-id grammar:
     * letters, digits, `.`, `_` and `-`, at most 64, not starting with `.`,
     * and — because the id becomes `<scope>/<id>.md` inside the store — never
     * the index stem in any case, which the store itself refuses.
     */
    public static function safeName(string $agentName): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $agentName) ?? '';
        $safe = substr(ltrim($safe, '.'), 0, self::NAME_MAX_CHARS);
        if ($safe === '') {
            return 'agent';
        }

        if (strcasecmp($safe, 'MEMORY') === 0) {
            return '_' . $safe;
        }

        return $safe;
    }

    /**
     * Resolve (and, for a write, create) the store for one agent's tier.
     *
     * @param bool $create true on the write path (the directory is laid down
     *                     with the post-mkdir containment gate), false on the
     *                     read path (an absent directory answers null)
     */
    public static function storeFor(?MemoryScope $scope, string $agentName, string $projectRoot, bool $create): ?MemoryStore
    {
        if ($scope === null) {
            return null;
        }

        $relative = match ($scope) {
            MemoryScope::User => self::USER_RELATIVE_DIRECTORY,
            MemoryScope::Project => self::PROJECT_RELATIVE_DIRECTORY,
            MemoryScope::Local => self::LOCAL_RELATIVE_DIRECTORY,
        };

        if ($scope === MemoryScope::User) {
            $base = HomeDirectory::owned();
        } elseif ($projectRoot === '' || ($create && !is_dir($projectRoot))) {
            $base = null;
        } else {
            $base = ProjectRoot::resolve($projectRoot);
        }

        if ($base === null || $base === '') {
            return null;
        }

        $directory = $base . '/' . $relative . '/' . self::safeName($agentName);

        if ($create) {
            if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
                return null;
            }

            if ($scope === MemoryScope::Local) {
                self::plantLocalIgnore($base . '/' . self::LOCAL_RELATIVE_DIRECTORY);
            }
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            return null;
        }

        // Checked after the create, not before: an existing symlink planted at
        // the store's path resolves through it right here, exactly as the
        // post-mkdir gate catches the write that mkdir laid down THROUGH one.
        if (!ContainedPath::below($directory, $base)) {
            return null;
        }

        return MemoryStore::forRepository($directory);
    }

    /**
     * The read-side answer used when folding a child prompt: never creates,
     * never throws, null for "this agent has no store bytes yet" as much as
     * for "the tier cannot be resolved here".
     */
    public static function readStore(?MemoryScope $scope, string $agentName, string $projectRoot): ?MemoryStore
    {
        return self::storeFor($scope, $agentName, $projectRoot, create: false);
    }

    /**
     * The write-side answer used when a delegated `Memory` tool binds: lays
     * down the store's directory (0700, contained) on first use.
     */
    public static function createStore(?MemoryScope $scope, string $agentName, string $projectRoot): ?MemoryStore
    {
        return self::storeFor($scope, $agentName, $projectRoot, create: true);
    }

    /**
     * Plant the `*` ignore file one level above the local-tier stores, once.
     * Best-effort by design: a store whose ignore cannot be written is still
     * a working store, and failing a note save over a hygiene file the user
     * can write by hand would punish the wrong side.
     */
    private static function plantLocalIgnore(string $localRoot): void
    {
        if (!is_dir($localRoot) && !@mkdir($localRoot, 0o700, true) && !is_dir($localRoot)) {
            return;
        }

        $ignore = $localRoot . '/.gitignore';
        if (is_file($ignore)) {
            return;
        }

        @file_put_contents($ignore, "*\n");
    }
}
