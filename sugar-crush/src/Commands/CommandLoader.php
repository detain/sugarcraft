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
 * against the directory it was listed from, and the project directory itself
 * against the checkout that named it — and a file that fails to parse is
 * logged and skipped rather than aborting discovery, so one malformed
 * command cannot make every other command disappear.
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
     * @param string|null $anchoredIn the checkout $dir was derived from, which
     *        $dir must resolve strictly inside; null for a directory whose
     *        location no repository chose (the user's own tree)
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
            error_log(sprintf(
                'Skipping commands directory %s: resolves to %s, %s the checkout it was reached from (%s)',
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
     * @return array<string, CommandSpec>
     */
    public function loadUserCommands(): array
    {
        return $this->loadFromDirectory($this->userCommandsDir());
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

    /** ~/.sugar-crush/commands */
    private function userCommandsDir(): string
    {
        $home = HomeDirectory::path();

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
