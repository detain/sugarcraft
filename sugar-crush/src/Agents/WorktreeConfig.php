<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Support\ContainedPath;

/**
 * Configuration for git worktree isolation per agent.
 *
 * Controls where worktrees are created, when they are cleaned up, and which
 * isolation strategy is used. All values are immutable after construction —
 * use with*() methods to produce derived instances.
 *
 * THE NINTH READ PATH, and it was the ninth precisely because it looked like
 * bookkeeping. {@see new()} read `__DIR__ . '/../../../.sugar-crush/config.json'`
 * — the directory CONTAINING this package, which in the development monorepo is
 * the checkout root and under a composer install is `vendor/sugarcraft/` — with
 * no containment of any kind: no {@see ContainedPath}, no anchor, no `..` guard.
 * That file sets `worktreeIncludeFile`, {@see WorktreeManager::resolveWorktreeInclude()}
 * reads it as `$repoRoot . '/' . <value>`, and every line of THAT file becomes a
 * copy pattern. MEASURED on this host against the ungated build, with a
 * `.worktreeinclude` whose only line was `../secret/id_rsa`:
 *
 *     read  <repoRoot>/../secret/id_rsa      (outside the checkout)
 *     wrote <worktreePath>/../secret/id_rsa  (outside the worktree)
 *
 * So both directions escaped, from one committed line. The gates are now in
 * three places, because there are three boundaries and one of them cannot stand
 * in for another: the config FILE is bounded here, the include FILE and every
 * copy pattern are bounded in {@see WorktreeManager}.
 *
 * IT WAS INVISIBLE TO BOTH NEW INSTRUMENTS, which is the part worth keeping.
 * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest} classified
 * `.sugar-crush/config.json` as USER-tier ("rooted at `~`, so nobody but the
 * user chose the location") — true of {@see \SugarCraft\Crush\Cli\Bootstrap}'s
 * call site and false of this one, because the classification keyed on the
 * STRING and one string covered two tiers. And
 * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest} counts
 * compares that are WRITTEN, of which this had none. Dormancy was not an
 * exemption then either: nothing in `src/` constructs a {@see WorktreeManager}
 * yet, and "DORMANT IS NOT UNGATED" is the doctrine this file was the
 * counter-example to.
 *
 * @note This class is mutable (not `readonly class`) to support the ::new()
 * static factory which reads .sugar-crush/config.json at construction time.
 * Individual properties remain readonly once constructed.
 */
final class WorktreeConfig
{
    /** The settings file this factory reads, relative to {@see defaultConfigDir()}. */
    private const CONFIG_PATH = '.sugar-crush/config.json';

    /**
     * Base directory where agent worktrees are created.
     * Expandable via FileSystem::expandPath().
     * Defaults to .sugar-crush/worktrees/ within the repo root.
     */
    public readonly string $basePath;

    /**
     * When true, worktrees are automatically removed when the team
     * they belong to is dissolved.
     */
    public readonly bool $autoCleanup;

    /**
     * The isolation strategy for agent file operations.
     * Worktree: each agent gets a full git worktree on its own branch.
     * Branch: agents share the filesystem but work on dedicated branches.
     * Path: agents share the filesystem but file ops are routed through PathJail.
     */
    public readonly WorktreeIsolationMode $isolationMode;

    /**
     * Worktrees older than this many days are considered stale and
     * eligible for removal by cleanupStaleWorktrees().
     * Used by the periodic sweep cleanup pass.
     */
    public readonly int $worktreeCleanupPeriodDays;

    /**
     * Path to the .worktreeinclude file (relative to repo root) that
     * lists glob patterns for files to copy into every new worktree,
     * even if they are normally excluded by .gitignore.
     * Set to '' to disable .worktreeinclude resolution.
     */
    public readonly string $worktreeIncludeFile;

    /**
     * The directory this factory looks for `.sugar-crush/config.json` under when
     * no `$configDir` is passed: the one CONTAINING this package.
     *
     * Kept as the production default because changing where a shipped library
     * reads its settings from is not this change-set's call — what changed is
     * that the answer is now a named seam rather than an inline expression, so
     * {@see \SugarCraft\Crush\Tests\Agents\WorktreeConfigTest} can drive a
     * temporary tree instead of writing to the repository's own tracked
     * `.sugar-crush/config.json` and restoring it in a `finally`. An interrupted
     * run used to leave a git-tracked file mutated, and three separate sessions
     * burned time diagnosing the "7 is identical to 21" failure that produced.
     */
    public static function defaultConfigDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * Create a new config, optionally loading values from .sugar-crush/config.json.
     *
     * Values from the JSON file are used as defaults; any explicitly-passed
     * arguments override those defaults. This allows `new WorktreeConfig()` to
     * automatically pick up settings from the JSON file without requiring
     * callers to know about the file path.
     *
     * THAT SENTENCE WAS FALSE UNTIL THIS REVISION, and it is written above
     * rather than deleted because it states the intent correctly. The two
     * file-backed parameters were `int $x = 7` / `string $y = '.worktreeinclude'`
     * and the file's value was assigned over whatever arrived, so
     * `new(worktreeCleanupPeriodDays: 3)` returned the FILE's number and not 3 —
     * PHP cannot tell a passed default from an omitted argument. They are
     * nullable now, with null meaning "omitted", which is the same `$XSet`
     * sentinel idiom the rest of this codebase uses for exactly this. Every
     * existing caller passes an `int`/`string` or nothing, so none changes
     * behaviour; the accessors are still non-nullable.
     *
     * TWO BOUNDARIES on the file, the same pair every other repository-chosen
     * read in this package carries and for the same reason (see the class
     * doc-block for what the ungated version was measured doing):
     *
     *  - `<dir>/.sugar-crush` must resolve STRICTLY inside `<dir>`
     *    ({@see ContainedPath::below()}), so a committed `.sugar-crush -> /elsewhere`
     *    relocates the check instead of tripping it;
     *  - `config.json` must resolve inside that directory
     *    ({@see ContainedPath::within()}), so `config.json -> /elsewhere/evil.json`
     *    is refused on its own.
     *
     * A refused config is silently the DEFAULTS, not an exception: this factory
     * has no error channel, every value it reads has a working default, and a
     * throw here would take out a caller that never asked to read a file. The
     * defaults are the safe direction — `.worktreeinclude` inside the repo root.
     *
     * @param string|null $configDir Directory holding `.sugar-crush/config.json`.
     *        Null means {@see defaultConfigDir()}. Passing it is what lets a test
     *        drive a temporary tree; it is NOT a way around the two gates above,
     *        which apply to an injected directory exactly as they do to the
     *        default one.
     */
    public static function new(
        string $basePath = '.sugar-crush/worktrees/',
        bool $autoCleanup = true,
        WorktreeIsolationMode $isolationMode = WorktreeIsolationMode::Worktree,
        ?int $worktreeCleanupPeriodDays = null,
        ?string $worktreeIncludeFile = null,
        ?string $configDir = null,
    ): self {
        $json = self::readConfig($configDir ?? self::defaultConfigDir());

        $worktreeCleanupPeriodDays ??= isset($json['worktreeCleanupPeriodDays'])
            ? (int) $json['worktreeCleanupPeriodDays']
            : 7;
        $worktreeIncludeFile ??= isset($json['worktreeIncludeFile'])
            ? (string) $json['worktreeIncludeFile']
            : '.worktreeinclude';

        return new self(
            basePath: $basePath,
            autoCleanup: $autoCleanup,
            isolationMode: $isolationMode,
            worktreeCleanupPeriodDays: $worktreeCleanupPeriodDays,
            worktreeIncludeFile: $worktreeIncludeFile,
        );
    }

    public function __construct(
        string $basePath = '.sugar-crush/worktrees/',
        bool $autoCleanup = true,
        WorktreeIsolationMode $isolationMode = WorktreeIsolationMode::Worktree,
        int $worktreeCleanupPeriodDays = 7,
        string $worktreeIncludeFile = '.worktreeinclude',
    ) {
        self::assertIsolationModeImplemented($isolationMode);

        $this->basePath = $basePath;
        $this->autoCleanup = $autoCleanup;
        $this->isolationMode = $isolationMode;
        $this->worktreeCleanupPeriodDays = $worktreeCleanupPeriodDays;
        $this->worktreeIncludeFile = $worktreeIncludeFile;
    }

    /**
     * `<dir>/.sugar-crush/config.json`, decoded, or `[]` when there is nothing
     * there this factory may read.
     *
     * The refusals are recorded nowhere on purpose: {@see new()} has no seam to
     * report through and this class is dormant, so a refusal here would be a
     * message with no reader. What it must not be is a SILENT ACCEPTANCE, which
     * is what the inline read it replaces was.
     *
     * @return array<string, mixed>
     */
    private static function readConfig(string $dir): array
    {
        // ONE LITERAL, split by `dirname()` rather than written twice. The two
        // halves were spelled separately for a draft, which made this read path
        // invisible to {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest}
        // — that instrument derives dot-paths from string literals, and a path
        // assembled from fragments is its stated blind spot. Making the fix
        // hide the finding from the inventory would have been the round's own
        // defect, one level up.
        $configPath = rtrim($dir, '/') . '/' . self::CONFIG_PATH;
        $configDir = \dirname($configPath);

        if (!ContainedPath::below($configDir, $dir)) {
            return [];
        }

        if (!ContainedPath::within($configPath, $configDir)) {
            return [];
        }

        // No `file_exists()` ahead of this: within() resolves both sides, so a
        // true answer already proves the file is there and readable — the same
        // reason {@see AgentPresetRegistry::load()} dropped its existence check.
        $raw = @file_get_contents($configPath);
        if ($raw === false) {
            return [];
        }

        $json = json_decode($raw, true);

        return \is_array($json) ? $json : [];
    }

    /**
     * Guard against silently accepting an isolation mode WorktreeManager does
     * not actually implement. Only WorktreeIsolationMode::Worktree has real
     * behavior today (full git worktree per agent) — Branch and Path are
     * defined on the enum but WorktreeManager::createWorktree() never
     * branches on them, so setting either previously had no effect at all.
     *
     * @throws \InvalidArgumentException When $mode has no implementation in WorktreeManager.
     */
    private static function assertIsolationModeImplemented(WorktreeIsolationMode $mode): void
    {
        if ($mode !== WorktreeIsolationMode::Worktree) {
            throw new \InvalidArgumentException(sprintf(
                'WorktreeIsolationMode::%s is not implemented by WorktreeManager yet — only'
                . ' WorktreeIsolationMode::Worktree is currently supported.',
                $mode->name,
            ));
        }
    }

    /**
     * Create a new config with a different basePath value.
     */
    public function withBasePath(string $basePath): self
    {
        return new self(
            basePath: $basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different autoCleanup value.
     */
    public function withAutoCleanup(bool $autoCleanup): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different isolationMode value.
     */
    public function withIsolationMode(WorktreeIsolationMode $isolationMode): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different worktreeCleanupPeriodDays value.
     */
    public function withWorktreeCleanupPeriodDays(int $worktreeCleanupPeriodDays): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $worktreeCleanupPeriodDays,
            worktreeIncludeFile: $this->worktreeIncludeFile,
        );
    }

    /**
     * Create a new config with a different worktreeIncludeFile value.
     */
    public function withWorktreeIncludeFile(string $worktreeIncludeFile): self
    {
        return new self(
            basePath: $this->basePath,
            autoCleanup: $this->autoCleanup,
            isolationMode: $this->isolationMode,
            worktreeCleanupPeriodDays: $this->worktreeCleanupPeriodDays,
            worktreeIncludeFile: $worktreeIncludeFile,
        );
    }
}
