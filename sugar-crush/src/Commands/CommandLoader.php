<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Discovers file-based custom commands, mirroring the three-tier pattern
 * {@see \SugarCraft\Crush\Skills\SkillLoader} already proves for skills:
 * built-in ({@see CommandRegistry::all()}) < user (`~/.sugar-crush/commands`)
 * < project (`<root>/.sugar-crush/commands`), later sources overriding
 * earlier ones by name so a project file can replace a built-in row.
 *
 * A file's path under the commands directory IS its name - `test.md` gives
 * `/test`, `deploy/staging.md` gives `/deploy/staging`.
 *
 * Everything walked here is user-controlled: paths are resolved and
 * containment-checked before reading through {@see ContainedPath} — the entry
 * against the directory it was listed from, and EACH TIER'S DIRECTORY against
 * the tree that named it (the checkout for a project's, `$HOME` for the user's)
 * — and a file that fails to parse is logged and skipped rather than aborting
 * discovery, so one malformed command cannot make every other command
 * disappear.
 *
 * THE USER TIER'S DIRECTORY ANCHOR IS NEW and closes a measured escape rather
 * than tidying a symmetry; {@see loadUserCommands()} carries what was measured
 * and what the fix costs.
 *
 * NOT YET REACHABLE FROM bin/sugarcrush: nothing constructs a CommandLoader
 * in production yet. The consumer is `Chat`'s slash-command surface (the "/"
 * popup feeds off `CommandRegistry::filter()`, which is still registry-only),
 * and `src/Chat.php` is owned by a concurrent track, so wiring plus the
 * `$ARGUMENTS`/`$1`/`` !`cmd` ``/`@file` template substitution described
 * alongside this item in crush_feat.md section 4.E4 land in later steps. The
 * loader is deliberately standalone and independently testable until then.
 */
final class CommandLoader
{
    /**
     * Subdirectories namespace commands, but only shallowly in practice. A
     * hard cap also bounds the walk over a user-controlled tree, which is
     * what stops a symlink cycle inside the commands directory from
     * recursing forever.
     */
    private const MAX_DEPTH = 4;

    /**
     * Load every `*.md` command file under $dir, keyed by command name.
     *
     * A missing directory is normal (most projects have none) and yields an
     * empty array rather than an error.
     *
     * TWO BOUNDARIES, for the reason
     * {@see \SugarCraft\Crush\Skills\SkillLoader::skillFilesIn()} needs two: the
     * per-ENTRY check below resolves each `*.md` and requires it to still live
     * under $dir, and $anchoredIn requires $dir ITSELF to resolve strictly inside
     * the checkout that named it. Without the second one the first is
     * relocatable rather than binding — `realpath()` on both sides means a
     * boundary directory that is itself a symlink travels with the link, so a
     * committed `.sugar-crush/commands -> <outside>` would have every `*.md`
     * under the target pass the entry check and become a slash command whose
     * body is a prompt. Anchored NOW rather than when step #14 wires this class,
     * because a containment rule added at wiring time is one written after the
     * consumer already trusts the loader.
     *
     * @param string|null $anchoredIn the tree $dir was derived from, which $dir
     *        must resolve strictly inside — a checkout for the project tier, the
     *        user's own `$HOME` for theirs. NULL IS UNANCHORED, and no caller in
     *        this class passes it any more: the one that did was
     *        {@see loadUserCommands()}, on the premise that "the user's own tree"
     *        needs no anchor, and that premise is measured false there. It remains
     *        accepted for a caller holding a directory with genuinely no
     *        containing tree to name, and such a caller is choosing the per-entry
     *        boundary alone — which the paragraph above measures as relocatable.
     *

     * @return array<string, CommandSpec>
     */
    public function loadFromDirectory(string $dir, ?string $anchoredIn = null): array
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return [];
        }

        if ($anchoredIn !== null && !ContainedPath::below($dir, $anchoredIn)) {
            // "THE CHECKOUT IT WAS REACHED FROM" is what this said, and the
            // anchor is no longer always a checkout: {@see loadUserCommands()}
            // passes `$HOME`. A refusal naming an operand the caller did not pass
            // is the failure this round found in three other messages, so the word
            // is "tree" and the path is interpolated for the reader to judge.
            error_log(sprintf(
                'Skipping commands directory %s: resolves to %s, %s the tree it was anchored to (%s)',
                $dir,
                $realDir,
                realpath($anchoredIn) === $realDir ? 'which is exactly' : 'outside',
                $anchoredIn,
            ));

            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $iterator->setMaxDepth(self::MAX_DEPTH);

        $commands = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            // A symlink inside the commands directory can point anywhere;
            // resolve it and require the target to still live under $realDir
            // so a command file cannot smuggle in ~/.ssh/config as a prompt.
            // {@see ContainedPath} rather than a local prefix compare, so this
            // class is not a fourth spelling of the predicate.
            if (!ContainedPath::within($file->getPathname(), $realDir)) {
                error_log("Skipping command file outside {$realDir}: {$file->getPathname()}");

                continue;
            }

            $realPath = (string) realpath($file->getPathname());

            $name = $this->commandNameFor($realDir, $realPath);

            try {
                $commands[$name] = CommandSpec::fromFile($realPath, $name);
            } catch (\Throwable $e) {
                error_log("Failed to load command from {$realPath}: {$e->getMessage()}");
            }
        }

        // Directory iteration order is filesystem-dependent; sort so the "/"
        // popup lists a project's commands the same way on every machine.
        ksort($commands);

        return $commands;
    }

    /**
     * Load user commands from ~/.sugar-crush/commands/.
     *
     * ANCHORED TO `$HOME`, and it was anchored to NOTHING — the argument was
     * omitted here while {@see loadProjectCommands()} passed its root, i.e. the
     * "null for a directory whose location no repository chose" arm of
     * {@see loadFromDirectory()} taken on the premise that a user's own tree
     * cannot be pointed elsewhere. MEASURED on this host, `$HOME` mode 0700 and
     * owned, with `~/.sugar-crush/commands -> <outside>` delivered the way a
     * tarball delivers one: every `*.md` under the target became a command whose
     * `CommandSpec::$template` — the PROMPT — is the outside file's body, with
     * `refusals=[]`. The per-entry check in {@see loadFromDirectory()} cannot see
     * it, because that check resolves `$realDir` too and therefore travels with
     * the directory link. Same refutation as
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()}'s and
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::readableUserDir()}'s: a
     * symlink arrives in a tarball, and ownership cannot answer who chose where a
     * link points.
     *
     * NULL WHEN THE HOME CANNOT BE ESTABLISHED, which is why this returns early
     * rather than passing a fallback: {@see HomeDirectory::owned()} answering null
     * means this process cannot tell whose home it is in, and there is no anchor
     * to hold the directory inside. The cost is stated rather than hidden — a
     * launch with an unresolvable, world-writable or foreign-owned `$HOME` loses
     * its user commands entirely, and that is the direction to fail in for a
     * directory whose files become prompt text.
     *
     * @return array<string, CommandSpec>
     */
    public function loadUserCommands(): array
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            error_log(
                'Skipping user commands: this process cannot establish that $HOME is this user\'s own '
                . 'directory (see HomeDirectory::owned()), so there is no anchor to hold '
                . '~/.sugar-crush/commands inside.',
            );

            return [];
        }

        return $this->loadFromDirectory($this->userCommandsDir($home), $home);
    }

    /**
     * Load project commands from <projectRoot>/.sugar-crush/commands/.
     *
     * $projectRoot is passed on as the trust anchor, not merely used to build the
     * path: it is the one path in the pair a repository cannot have forged, since
     * a link that moved it would have to sit above the clone. See
     * {@see loadFromDirectory()}.
     *
     * @return array<string, CommandSpec>
     */
    public function loadProjectCommands(string $projectRoot): array
    {
        return $this->loadFromDirectory($this->projectCommandsDir($projectRoot), $projectRoot);
    }

    /**
     * Every command both surfaces should know about: built-in rows first,
     * then user files, then project files - each tier overriding the previous
     * tier's row of the same name.
     *
     * Keyed by name (unlike {@see CommandRegistry::all()}'s list) because
     * override-by-name is the whole point of the tiering: a project
     * `compact.md` must replace the built-in `/compact` row, not append a
     * second one.
     *
     * @return array<string, CommandSpec>
     */
    public function loadAll(string $projectRoot = '.'): array
    {
        $builtIn = [];
        foreach (CommandRegistry::all() as $spec) {
            $builtIn[$spec->name] = $spec;
        }

        return array_merge(
            $builtIn,
            $this->loadUserCommands(),
            $this->loadProjectCommands($projectRoot),
        );
    }

    /**
     * `~/.sugar-crush/commands`, under the home the CALLER established.
     *
     * The home is a parameter rather than resolved here, so the directory and the
     * anchor {@see loadUserCommands()} holds it inside are the same string by
     * construction. Resolving twice is how a directory comes to be checked
     * against a different home than the one it was built from —
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} derives both from
     * one call for the same reason.
     */
    private function userCommandsDir(string $home): string
    {
        return $home . '/.sugar-crush/commands';
    }

    /** <projectRoot>/.sugar-crush/commands */
    private function projectCommandsDir(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/.sugar-crush/commands';
    }

    /**
     * The file's path relative to the commands directory, minus the `.md`
     * extension, with separators normalised to "/" - so nesting namespaces
     * the command exactly as Claude Code's `deploy/staging.md` -> `/deploy/staging`.
     */
    private function commandNameFor(string $baseDir, string $filePath): string
    {
        $relative = substr($filePath, strlen($baseDir) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        return substr($relative, 0, -strlen('.md'));
    }
}
