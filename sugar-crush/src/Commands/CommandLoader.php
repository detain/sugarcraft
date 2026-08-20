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
 * earlier ones by name so a project file can replace a built-in row — EXCEPT
 * for {@see CommandRegistry::CONTROL_PLANE}, the names by which the user drives
 * and leaves the application, which {@see loadAll()} takes back and records on
 * {@see refusedCommands()}.
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
 * WIRED (crush_code.md Phase 2 item 4): {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
 * builds one and hands it to {@see \SugarCraft\Crush\Chat}, which loads once at
 * construction and then serves all three command surfaces from the merged map —
 * the "/" popup, `/help`'s listing, and dispatch itself
 * ({@see \SugarCraft\Crush\Commands\CommandSpec::expandTemplate()} turns the
 * template into the prompt that is sent). ALL FOUR of crush_feat.md section
 * 4.E4's template forms are now implemented: `$ARGUMENTS` and `$1`..`$9` in
 * {@see CommandSpec::expandTemplate()}, and `` !`cmd` `` and `@file` behind the
 * two gates the earlier revision of this doc-block said each would need.
 *
 * WHAT GATES THE TWO NEW FORMS, and why they are gated differently — the
 * distinction this class exists to make, since it is the only thing that knows
 * which DIRECTORY a command came out of:
 *
 *  - `@file` is a bounded read confined to the CHECKOUT, for both tiers, by
 *    {@see CommandSpec::includeFile()}'s {@see ContainedPath} compare. Same
 *    boundary as the `*.md` walk below and for the same reason: an included
 *    file becomes prompt text.
 *  - `` !`cmd` `` runs a shell, so the tier decides. A USER-tier command
 *    (`~/.sugar-crush/commands`) is the operator's own file — as much theirs as
 *    `~/.bashrc` — and runs subject only to the launch's
 *    {@see \SugarCraft\Crush\Permissions\PermissionGate}. A PROJECT-tier one
 *    arrived in a `git clone`, so it ADDITIONALLY requires the operator to have
 *    listed this root under `trustedProjectCommands` in
 *    `~/.sugar-crush/config.json` — the same per-key trust mechanism
 *    {@see \SugarCraft\Crush\Cli\Bootstrap::trustedProjectRoots()} already
 *    serves `trustedProjectHooks` and `trustedProjectMcp`. Untrusted, the form
 *    is refused with a notice in the prompt's place, not silently dropped.
 *
 * The tier is stamped onto each row here ({@see CommandSpec::$tier}) rather than
 * read from the file's frontmatter, because a frontmatter field is written by
 * whoever wrote the file — which for the project tier is the party being gated.
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
     * Tier directories refused by the anchor check, path => reason.
     *
     * ACCUMULATED ON THE INSTANCE rather than only written to `error_log()`,
     * because this class now has a production caller and `error_log()` on a TUI
     * goes wherever the launching shell's stderr went — which for a full-screen
     * app is under the alternate screen, i.e. nowhere the user will read it. The
     * shape is {@see \SugarCraft\Crush\Skills\SkillLoader::refusedDirectories()}'s
     * so {@see \SugarCraft\Crush\Cli\Bootstrap}'s existing collector can drain
     * it with the same one-liner it drains the other feeders with. The
     * `error_log()` calls stay: a refusal that reaches a log AND the launch
     * report is reported twice, and a refusal that reaches only a log is the
     * failure being fixed.
     *
     * DIRECTORY REFUSALS ONLY, matching the name and the collector's subject. A
     * single `*.md` skipped for pointing outside, or for failing to parse, is a
     * per-FILE event and stays on `error_log()` — the collector's readers print
     * one line per refused directory, and a malformed command file is an
     * authoring mistake rather than a containment answer.
     *
     * @var array<string, string>
     */
    private array $refusedDirectories = [];

    /**
     * File-based commands refused for taking a control-plane name, name => reason.
     *
     * SEPARATE FROM {@see $refusedDirectories} because it is a different subject
     * and that property's doc-block states the distinction it keeps: that one
     * answers "which directory did this loader decline to read", and its keys are
     * paths its readers tell the user to go and look at. This one answers "which
     * FILE inside a directory it did read was declined", and its keys are command
     * names. Merging them would put a name where every reader prints a path.
     *
     * This is the one per-FILE refusal that is collected rather than logged: the
     * others ({@see loadFromDirectory()}'s containment skip and its parse
     * failure) are answers about one malformed or misplaced `*.md`, whereas this
     * one means a command the user can SEE in the popup is not the command they
     * wrote — so it belongs in the launch report beside the directory refusals.
     *
     * @var array<string, string>
     */
    private array $refusedCommands = [];

    /**
     * name => the `*.md` it was last read from, so a refusal can name a path
     * the user can open rather than a bare command name.
     *
     * WRITTEN IN TIER ORDER by {@see loadFromDirectory()} and therefore
     * overwritten exactly as the command map is — {@see loadAll()} calls the
     * user tier before the project tier, so the path recorded for a name is the
     * file that WON, which is the file a refusal is about.
     *
     * @var array<string, string>
     */
    private array $commandSources = [];

    /**
     * Tier directories this loader refused to read, path => reason — drained at
     * launch by {@see \SugarCraft\Crush\Cli\Bootstrap::reportProjectTierRefusals()}.
     *
     * CUMULATIVE ACROSS CALLS on one instance, like the walk it records: one
     * loader serves both tiers, and a caller that asked for both wants both
     * answers.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
    }

    /**
     * Command files this loader refused for naming a control-plane command,
     * path => reason — drained at launch alongside {@see refusedDirectories()}.
     *
     * PATH-KEYED like {@see refusedDirectories()}, so
     * {@see \SugarCraft\Crush\Cli\Bootstrap}'s collector — every reader of
     * which prints its key as a place to go and look — can spread this in
     * unchanged. The name is in the reason text.
     *
     * @return array<string, string>
     */
    public function refusedCommands(): array
    {
        return $this->refusedCommands;
    }

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

     * @param string|null $tier stamped onto every row this call produces
     *        ({@see CommandSpec::$tier}) — `'user'` or `'project'`. It is a
     *        PARAMETER because only the caller knows which directory it asked
     *        for, and it is what decides whether a `` !`cmd` `` in one of these
     *        files may reach a shell; null leaves the rows untiered, which for
     *        `` !`cmd` `` is treated as "an in-process caller chose this", not as
     *        "project".
     * @return array<string, CommandSpec>
     */
    public function loadFromDirectory(string $dir, ?string $anchoredIn = null, ?string $tier = null): array
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
            $reason = sprintf(
                'Skipping commands directory %s: resolves to %s, %s the tree it was anchored to (%s)',
                $dir,
                $realDir,
                realpath($anchoredIn) === $realDir ? 'which is exactly' : 'outside',
                $anchoredIn,
            );
            error_log($reason);
            // Keyed on the path as GIVEN, not on $realDir: the collector's
            // readers print the directory the user can go and look at, and
            // $realDir is the far end of the link that caused the refusal.
            $this->refusedDirectories[$dir] = $reason;

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
                $commands[$name] = CommandSpec::fromFile($realPath, $name, $tier);
                $this->commandSources[$name] = $realPath;
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
            $reason = 'Skipping user commands: this process cannot establish that $HOME is this user\'s own '
                . 'directory (see HomeDirectory::owned()), so there is no anchor to hold '
                . '~/.sugar-crush/commands inside.';
            error_log($reason);
            // Recorded under the literal `~/...` spelling because there is no
            // resolved path to name — establishing one is exactly what failed.
            $this->refusedDirectories['~/.sugar-crush/commands'] = $reason;

            return [];
        }

        return $this->loadFromDirectory($this->userCommandsDir($home), $home, 'user');
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
        return $this->loadFromDirectory($this->projectCommandsDir($projectRoot), $projectRoot, 'project');
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

        $merged = array_merge(
            $builtIn,
            $this->loadUserCommands(),
            $this->loadProjectCommands($projectRoot),
        );

        // THE CONTROL PLANE IS NOT OVERRIDABLE. Everything above this line is
        // the override the tiering exists for; this loop takes back the seven
        // names in {@see CommandRegistry::CONTROL_PLANE}, because a file that
        // won one of those would be answering a keystroke the user aimed at the
        // application. Measured before the reservation: a project `exit.md`
        // made `/exit` send a prompt while idle and still quit mid-turn, so the
        // effect depended on whether a reply happened to be streaming.
        //
        // ENFORCED HERE rather than in {@see \SugarCraft\Crush\Chat}, so the
        // map every caller of this method receives is already reserved — the
        // popup, `/help` and dispatch all read that one map, and a check placed
        // in one consumer would leave the others advertising a row that no
        // longer runs.
        foreach (CommandRegistry::CONTROL_PLANE as $reserved) {
            if (!isset($merged[$reserved]) || !$merged[$reserved]->isFileBased()) {
                continue;
            }

            $reason = sprintf(
                'Refusing file-based command /%s: it is a control-plane command (%s) and a command file '
                . 'cannot take one over. The built-in still runs; rename the file to use it.',
                $reserved,
                implode(', ', CommandRegistry::CONTROL_PLANE),
            );
            error_log($reason);
            // Keyed on the FILE, not the name, so the launch report prints
            // something the user can open. {@see $commandSources} holds the path
            // of the file that won the merge, which is the one being refused.
            $this->refusedCommands[$this->commandSources[$reserved] ?? $reserved] = $reason;

            // Restore the built-in rather than dropping the name: `/exit` must
            // still quit. `permissions` has no registry row, so it is unset.
            if (isset($builtIn[$reserved])) {
                $merged[$reserved] = $builtIn[$reserved];
            } else {
                unset($merged[$reserved]);
            }
        }

        return $merged;
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
