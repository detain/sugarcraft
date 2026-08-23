<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Manages creation, deletion, and listing of git worktrees per agent.
 *
 * Each agent gets its own worktree at {basePath}/{agentId}/ on a dedicated
 * branch (agent-{agentId}-{timestamp} by default). Worktrees are tracked
 * in a registry file so they can be enumerated and cleaned up.
 *
 * Worktree state is stored under:
 *     {basePath}/{agentId}/
 *
 * while the registry is stored at:
 *     {basePath}/.registry.json
 *
 * ALL FOUR OF THIS CLASS'S DIAGNOSTICS ARE ON THE MID-SESSION TRANSCRIPT SEAM
 * (E192), and this paragraph is the per-site decision rather than a blanket
 * one. The rule is the one the two tool-call parsers' class doc-blocks state:
 * a notice goes to {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()}
 * if and only if the emitter did not produce what the caller asked for.
 * Everything this class reports is of exactly that kind, which is why it is
 * the only one of E192's three emitters to move all of its sites:
 *
 *  - {@see createWorktree()}'s `git worktree add` failure: the worktree does
 *    not exist and the method throws. Nothing the agent was spawned to do can
 *    happen.
 *  - {@see removeWorktree()}'s `git worktree remove` failure: this one LOOKS
 *    like a recovery, because the directory is force-removed below and the
 *    registry entry is dropped, so the method returns normally. It is not one,
 *    and the difference was MEASURED rather than argued (git 2.43.0, Linux
 *    6.8, this box): with the worktree directory removed behind git's back,
 *    `git worktree list` still reports the path as `prunable`, and a later
 *    `git worktree add` at the SAME path — which is what the next
 *    `createWorktree()` for that agent id runs — fails with
 *    `fatal: '<path>' is a missing but already registered worktree`. So the
 *    removal did not complete, and its consequence is that the next creation
 *    for that agent is refused. The caller is told nothing today unless it
 *    reads fd 2.
 *  - {@see resolveWorktreeInclude()}'s include-file refusal: every file the
 *    repository's `worktreeIncludeFile` listed is absent from the new
 *    worktree. An agent that needs a `.env` or a composer auth token to build
 *    will fail for a reason with no visible cause.
 *  - {@see copyGlob()}'s pattern refusal: the same, for one entry of the list.
 *    Counted per call site and not per line, so a list with several escaping
 *    patterns is one site here and several rows in the transcript — which is
 *    the honest shape: each is a distinct file the user asked for and did not
 *    get, and {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::drain()}
 *    de-duplicates identical rows within a batch.
 *
 * WHY THAT IS WORTH TRANSCRIPT TOKENS, which is the objection the routing rule
 * exists to answer — and the first thing this paragraph has to say is that
 * NOTHING IN `src/` OR `bin/` CONSTRUCTS THIS CLASS, so none of the four sites
 * above fires on any path today.
 *
 * WHAT THIS PARAGRAPH SAID: "These fire while the alternate screen is up, so
 * today they land as unprefixed lines on a frame the renderer believes it owns
 * — visible as corruption, or not at all."
 *
 * WHAT IS TRUE NOW, checked rather than asserted: neither `new WorktreeManager`
 * nor `WorktreeManager::new(` occurs anywhere in `src/` or `bin/` outside this
 * file's own doc-comments, and {@see Team::claimTask()} — the one method that
 * takes a `WorktreeManager` — has no caller in `src/` either. Only tests build
 * one. So the sentence above describes a FUTURE and not a present, and it was
 * written as a present. Two other files in this package already said as much
 * and this one did not read them: {@see \SugarCraft\Crush\Cli\Bootstrap}'s
 * "DORMANT: nothing in `src/` constructs a `WorktreeManager`", and
 * {@see WorktreeConfig}'s "DORMANT IS NOT UNGATED". The property doc-block
 * thirty lines below says it too.
 *
 * WHY THE ROUTING STILL EARNS ITS PLACE, which is a different question from
 * whether it fires. "DORMANT IS NOT UNGATED" is this package's own doctrine
 * and {@see WorktreeConfig} is the file it was written against: a dormant
 * emitter's channel is the channel its FIRST caller inherits, and choosing it
 * now costs one commit while changing it after that caller exists costs a
 * reader who has already learned the wrong one. The routing rule answers YES
 * for all four sites on their merits — each reports a thing the caller asked
 * for and did not get — and that answer does not depend on when the first
 * caller arrives. What DOES depend on it is the alternate-screen harm, which
 * is why it is now stated conditionally. The reader who could act on these is
 * the model as much as the user: a worktree that was not created is a step the
 * next turn must not assume happened, on the day a next turn can reach one.
 *
 * THE DORMANCY IS PINNED RATHER THAN MERELY WRITTEN DOWN, which is the other
 * half of that doctrine — see
 * {@see \SugarCraft\Crush\Tests\Cli\StderrEmitterCensusTest}'s
 * construction-site guard. It reds the day something in `src/` builds one, at
 * which point the paragraph above becomes true and is to be rewritten to say
 * so rather than the guard being deleted.
 */
final class WorktreeManager
{
    /** @var array<string, array{branch: string, createdAt: string}> */
    private array $registry = [];

    private readonly string $expandedBasePath;
    private readonly string $registryPath;

    /**
     * NOT A PROMOTED PROPERTY, and not nullable, and both halves are the fix
     * for a fatal rather than a style preference.
     *
     * It was `private readonly ?WorktreeConfig $config = null` promoted, with
     * `$this->config = WorktreeConfig::new()` in the body for the null case.
     * PHP initialises a promoted readonly property AT PROMOTION, so that
     * assignment is a second write and throws:
     *
     *     new WorktreeManager()
     *     Error: Cannot modify readonly property WorktreeManager::$config
     *
     * MEASURED on this host, PHP 8.3, against the unmodified class. Which means
     * the entire default-configuration branch — `new WorktreeManager()`,
     * `WorktreeManager::new($repoRoot)`, the documented factory — was not a
     * wrong config, it was an uncatchable `Error`. Nothing in `src/` constructs
     * one (the class is dormant) and every test passed an explicit
     * {@see WorktreeConfig}, so no suite ever entered the branch. Resolving the
     * config into a declared property before it is ever written once makes the
     * branch reachable, which is also what makes the `configDir` argument below
     * mean anything at all.
     */
    private readonly WorktreeConfig $config;

    public function __construct(
        ?WorktreeConfig $config = null,
        private readonly string $repoRoot = '',
    ) {
        if ($config === null) {
            // CONFIGURED BY THE REPOSITORY IT MANAGES, not by whatever directory
            // happens to contain this package. Until this line passed
            // `configDir`, `WorktreeConfig::new()` fell through to
            // {@see WorktreeConfig::defaultConfigDir()} — `dirname(__DIR__, 3)`,
            // the directory ABOVE the package — while `$repoRoot` sat right
            // here in the same constructor.
            //
            // WHAT THAT WOULD HAVE DONE, in the conditional and not the past
            // tense, because it never actually ran: the readonly double-write
            // documented on the property above made this whole branch an
            // uncatchable `Error`, so no caller ever reached the config read at
            // all. Both halves of the defect are real and they are SEQUENTIAL —
            // the fatal came first, and the cross-tree read is what the branch
            // would have done on the first day it worked. MEASURED in this
            // checkout for the directory the old expression names: it is the
            // sugarcraft monorepo root, whose tracked `.sugar-crush/config.json`
            // reads `{"worktreeCleanupPeriodDays": 30, "worktreeIncludeFile":
            // ".worktreeinclude"}` — so a working
            // `WorktreeManager::new('/srv/someone-elses-repo')` would have taken
            // a 30-day cleanup period and an include-file NAME out of THIS
            // repository and applied both to that one, with
            // {@see resolveWorktreeInclude()} resolving that name against
            // `$this->repoRoot`. A value read from tree A, applied to tree B:
            // the two halves of the sentence would have had different domains.
            //
            // It would also be inert rather than merely wrong under a real
            // `composer require`, where `dirname(__DIR__, 3)` is
            // `vendor/sugarcraft/` — a directory that will not hold a
            // `.sugar-crush/config.json` — so the feature could only ever have
            // appeared to work from a monorepo checkout. That asymmetry is why
            // this is a fix and not a preference.
            //
            // `''` STAYS ON THE OLD DEFAULT, deliberately. An empty
            // `$repoRoot` is this class's "no repository named" (see
            // {@see resolveWorktreeInclude()}, which falls back to `getcwd()`
            // for the same input), and it is not a path — `configDir: ''`
            // would make {@see WorktreeConfig::readConfig()} resolve
            // `/.sugar-crush/config.json` at the filesystem root. Null keeps
            // the documented default for that case, so no existing caller that
            // passes no root changes behaviour.
            $config = WorktreeConfig::new(
                configDir: $repoRoot !== '' ? $repoRoot : null,
            );
        }

        $this->config = $config;
        $this->expandedBasePath = $this->expandPath($this->config->basePath);
        $this->registryPath = $this->expandedBasePath . '/.registry.json';
        $this->loadRegistry();
    }

    /**
     * Factory method matching the sibling WorktreeConfig::new() pattern.
     *
     * @param string $repoRoot Path to the git repository root.
     * @param WorktreeConfig|null $config Optional worktree configuration.
     */
    public static function new(string $repoRoot = '', ?WorktreeConfig $config = null): self
    {
        return new self($config, $repoRoot);
    }

    // -------------------------------------------------------------------------
    // Core operations
    // -------------------------------------------------------------------------

    /**
     * Create a new git worktree for the given agent.
     *
     * Creates a dedicated branch named "agent-{agentId}-{timestamp}" if no
     * branch name is provided, then runs "git worktree add {path} {branch}"
     * to create the isolated worktree directory.
     *
     * @param string $agentId Unique identifier for the agent (used as directory name).
     * @param string|null $branch Optional branch name; defaults to agent-{agentId}-{timestamp}.
     * @return string The absolute path to the newly created worktree.
     * @throws \InvalidArgumentException When agentId is empty or contains path traversal.
     * @throws \RuntimeException When git worktree creation fails.
     */
    public function createWorktree(string $agentId, ?string $branch = null): string
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (str_contains($agentId, '..') || str_contains($agentId, '/') || str_contains($agentId, '\\')) {
            throw new \InvalidArgumentException(
                'Agent ID must not contain path traversal sequences, slashes, or backslashes.',
            );
        }

        if ($this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" already exists.', $agentId),
            );
        }

        $worktreePath = $this->expandedBasePath . '/' . $agentId;
        $branch ??= 'agent-' . $agentId . '-' . time();

        // Ensure parent directory exists
        if (!is_dir($this->expandedBasePath)) {
            mkdir($this->expandedBasePath, 0755, true);
        }

        // Create the worktree via git - use -b to create and checkout the new branch atomically
        $escapedPath = escapeshellarg($worktreePath);
        $escapedBranch = escapeshellarg($branch);
        $repoRootArg = $this->repoRoot !== '' ? '-C ' . escapeshellarg($this->repoRoot) . ' ' : '';

        $cmd = "git {$repoRootArg} worktree add -b {$escapedBranch} {$escapedPath} 2>&1";
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outputStr = trim(implode("\n", $output));

        // Git writes worktree path to stdout on success; exit code 0 + dir exists = success.
        // Any exit code != 0 or output containing "fatal" indicates failure.
        if ($exitCode !== 0 || str_contains($outputStr, 'fatal')) {
            RuntimeNoticeSink::warn("WorktreeManager: git worktree add failed for agent \"{$agentId}\" — exit {$exitCode}: {$outputStr}");
            throw new \RuntimeException(
                sprintf('Failed to create worktree for agent "%s": %s', $agentId, $outputStr ?: 'unknown error'),
            );
        }

        // Double-check the directory was actually created
        if (!is_dir($worktreePath)) {
            throw new \RuntimeException(
                sprintf('Failed to create worktree for agent "%s": directory not found after git command', $agentId),
            );
        }

        // Register the worktree
        $this->registry[$agentId] = [
            'branch' => $branch,
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            'named' => false,
        ];
        $this->saveRegistry();

        // Copy .worktreeinclude-listed files (e.g. .env, composer auth) into
        // the fresh worktree — previously only reachable via manual reflection.
        $this->resolveWorktreeInclude($worktreePath);

        return $worktreePath;
    }

    /**
     * Remove the worktree for the given agent.
     *
     * Runs "git worktree remove {path}" to delete the worktree and its
     * working directory, then removes the entry from the registry.
     *
     * @param string $agentId The agent whose worktree should be removed.
     * @throws \InvalidArgumentException When agentId is empty.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function removeWorktree(string $agentId): void
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        $worktreePath = $this->expandedBasePath . '/' . $agentId;

        // Remove via git first
        $escapedPath = escapeshellarg($worktreePath);
        if ($this->repoRoot !== '') {
            $cmd = sprintf(
                'git -C %s worktree remove %s 2>&1',
                escapeshellarg($this->repoRoot),
                $escapedPath,
            );
        } else {
            $cmd = "git worktree remove {$escapedPath} 2>&1";
        }

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outputStr = trim(implode("\n", $output));

        // Git worktree remove returns exit code 0 on success, but may print to stderr.
        // Any exit code != 0 or output containing "fatal" indicates failure.
        if ($exitCode !== 0 || str_contains($outputStr, 'fatal')) {
            RuntimeNoticeSink::warn("WorktreeManager: git worktree remove failed for agent \"{$agentId}\" — exit {$exitCode}: {$outputStr}");
        }

        // Remove the directory in case git didn't (e.g., dirty worktree was force-removed)
        if (is_dir($worktreePath)) {
            $this->removeDirectory($worktreePath);
        }

        unset($this->registry[$agentId]);
        $this->saveRegistry();
    }

    /**
     * Return the absolute path to the worktree for a given agent.
     *
     * @param string $agentId The agent whose worktree path is requested.
     * @return string The absolute path to the agent's worktree directory.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function getWorktreePath(string $agentId): string
    {
        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        return $this->expandedBasePath . '/' . $agentId;
    }

    /**
     * List all managed worktrees.
     *
     * Returns the registry of known agent worktrees with their branch
     * and creation timestamp.
     *
     * @return array<string, array{branch: string, createdAt: string}>
     */
    public function listWorktrees(): array
    {
        // Filter out entries whose directories no longer exist (orphaned)
        $valid = [];
        foreach ($this->registry as $agentId => $meta) {
            $path = $this->expandedBasePath . '/' . $agentId;
            if (is_dir($path)) {
                $valid[$agentId] = $meta;
            }
        }

        // Sync registry if any were removed externally
        if (count($valid) !== count($this->registry)) {
            $this->registry = $valid;
            $this->saveRegistry();
        }

        return $this->registry;
    }

    // -------------------------------------------------------------------------
    // .worktreeinclude resolution
    // -------------------------------------------------------------------------

    /**
     * Copy files matching patterns in .worktreeinclude into the new worktree.
     *
     * Reads .worktreeinclude (or the configured alternative) from the repo root,
     * interprets each line as a glob pattern (empty lines / # comments / !negs ignored),
     * and copies matching files into the newly-created worktree directory so that
     * normally-ignored files (e.g. .env, composer auth) are available to the agent.
     *
     * Mirrors: same approach as git's exclude file mechanism.
     *
     * TWO REPOSITORY-CHOSEN INPUTS, both now bounded, and neither was:
     *
     *  - WHERE the list lives. `worktreeIncludeFile` comes out of
     *    `.sugar-crush/config.json` ({@see WorktreeConfig::new()}), so its value
     *    is chosen by whoever committed that file, and it was concatenated onto
     *    `$repoRoot` and read. A value of `../../elsewhere/list` therefore named
     *    a file outside the checkout whose every line became a copy pattern.
     *  - WHAT the list names. Each line is globbed relative to the include
     *    file's own directory and the RELATIVE result is concatenated onto
     *    `$repoRoot` again by {@see copyGlob()}, so a leading `../` survived the
     *    round trip intact. MEASURED on this host against the ungated build,
     *    one line, `../secret/id_rsa`:
     *
     *        read  <repoRoot>/../secret/id_rsa      (outside the checkout)
     *        wrote <worktreePath>/../secret/id_rsa  (outside the worktree)
     *
     *    Both directions, from one committed line — the read is the exfiltration
     *    and the write is arbitrary file placement next to the worktree.
     *
     * THE TWO GATES ARE NOT EQUALLY REACHABLE, and saying so is the difference
     * between a measurement and a pair of assertions. The PATTERN gate in
     * {@see copyGlob()} is what closes the copy, in both directions. The
     * INCLUDE-FILE gate here closes something narrower, measured by deleting each
     * in turn: patterns are globbed relative to the include file's OWN directory
     * while the copy resolves against `$repoRoot`, so an outside list cannot name
     * a file the in-repo list could not have named anyway. What it does close is
     * that the outside file is READ at all — `file()` on a path a committed
     * config value chose — and that its lines then reach the user through the
     * pattern refusal below. THAT REFUSAL NO LONGER GOES ONLY TO `error_log()`;
     * E192 routed all four of this class's diagnostics onto
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()}, which
     * writes `error_log()` itself and ALSO puts the row on the mid-session
     * transcript. The measurement this sentence rests on is unchanged — the
     * outside file's lines still reach a user-visible diagnostic — and it is
     * now one the user can actually read with the alternate screen up. Two drafts of
     * {@see \SugarCraft\Crush\Tests\Agents\WorktreeIncludeContainmentTest} asserted
     * on the copy and stayed green with this gate deleted, which is how the
     * distinction was found rather than assumed.
     *
     * AND FOR ONE ROUND THE INCLUDE-FILE GATE HAD A HOLE THE SIZE OF ITS OWN
     * DEFAULT. It was written `if ($this->repoRoot !== '' && !within(...))`,
     * skipped on the empty-root branch and justified by "the include file is a
     * caller-supplied relative path against the process CWD" — false twice over:
     * the value is `.sugar-crush/config.json`'s, and `$repoRoot = ''` is the
     * constructor's own default. MEASURED with that pair and a
     * `worktreeIncludeFile` of `../<a directory beside the CWD>/list`: the outside
     * file was READ and its line reached the pattern refusal below (which went to
     * `error_log()` when this was measured and now goes to the seam as well) — pinned by
     * `WorktreeIncludeContainmentTest::testAnIncludeFileEscapingTheCwdIsRefusedWhenThereIsNoRepoRoot`,
     * which reddens with the old conjunct restored. The gate is now unconditional and
     * the anchor is the tree the path resolves against, CWD included. What it
     * costs: a caller with no repo root, standing outside the tree its include
     * file lives in, no longer reads it — which is the same refusal a repo-rooted
     * caller has always got.
     *
     * A refused input is SKIPPED rather than thrown on, for the reason
     * {@see \SugarCraft\Crush\Agents\AgentPresetRegistry::list()} skips a refused
     * entry: one bad line in an optional convenience file must not fail worktree
     * creation, which has already happened by the time this is called.
     *
     * @param string $worktreePath Absolute path to the newly created worktree.
     */
    public function resolveWorktreeInclude(string $worktreePath): void
    {
        if ($this->config->worktreeIncludeFile === '') {
            return;
        }

        $includeFile = $this->repoRoot !== ''
            ? $this->repoRoot . '/' . $this->config->worktreeIncludeFile
            : $this->config->worktreeIncludeFile;

        // ABSENCE IS CHECKED FIRST, and the order is the whole correctness of
        // the refusal notice. `ContainedPath` answers false for anything it
        // cannot resolve, so containment-then-existence reported "resolves
        // outside the repository root" for the overwhelmingly common case of a
        // checkout that simply ships no `.worktreeinclude` — measured as seven
        // such lines in one run of this class's own suite. A file that is not
        // there is not an escape; it is nothing.
        if (!file_exists($includeFile)) {
            return;
        }

        // THE GATE USED TO BE SKIPPED ENTIRELY WHEN $repoRoot WAS EMPTY, on a
        // justification that was false in both of its halves: "the include file
        // is a CALLER-SUPPLIED relative path against the process CWD and there is
        // no repository-chosen component to bound". It is not caller-supplied —
        // `worktreeIncludeFile` comes out of `.sugar-crush/config.json` via
        // {@see WorktreeConfig::new()}, which is the very input the class
        // doc-block records as repository-chosen — and `$repoRoot = ''` is not an
        // exotic state but this class's CONSTRUCTOR DEFAULT, reached by
        // `WorktreeManager::new()` and by `new WorktreeManager($config)`.
        // MEASURED on this host with `$repoRoot = ''` and
        // `worktreeIncludeFile: '../outside/list'`: the outside file was READ and
        // its lines reached the pattern refusal below — `error_log()` when this
        // was measured, and since E192 the transcript seam as well —
        // verbatim the harm this gate's own doc-block says it closes.
        //
        // SO THE ANCHOR IS THE TREE THE PATH IS ACTUALLY RESOLVED AGAINST: the
        // repo root when there is one, and otherwise the process CWD, which is
        // what a relative path means. `getcwd()` can fail (deleted directory,
        // permissions); an empty anchor makes {@see ContainedPath} answer false,
        // so that failure REFUSES rather than skipping — the direction a gate has
        // to fail in.
        $anchor = $this->repoRoot !== '' ? $this->repoRoot : (getcwd() ?: '');

        if (!ContainedPath::within($includeFile, $anchor)) {
            RuntimeNoticeSink::warn(sprintf(
                'WorktreeManager: refusing worktreeIncludeFile "%s" — it resolves outside %s (%s), and a file '
                . 'outside that tree does not list what this worktree wants copied.',
                $this->config->worktreeIncludeFile,
                $this->repoRoot !== '' ? 'the repository root' : 'the process working directory',
                // NAMED, not left as the empty string. And this arm is REACHABLE,
                // which is the question the round that wrote it forgot to ask about
                // its own messages: `getcwd()` returns false inside a directory that
                // has been removed under the process — measured on this host,
                // `mkdir d; cd d; rmdir d` then `getcwd()` is `bool(false)` — and
                // that is exactly the launch whose relative include path can no
                // longer be anchored to anything.
                $anchor === '' ? '<unresolvable>' : $anchor,
            ));

            return;
        }

        $lines = file($includeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and negation patterns
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                continue;
            }

            $patterns = $this->resolveNegations($line, $includeFile);

            foreach ($patterns as $pattern) {
                // THE SAME $anchor the include file was judged against, not
                // `$this->repoRoot`. With an empty repo root the old expression
                // made `$srcPath` = `'' . '/' . $pattern` — an ABSOLUTE path — and
                // then refused it against an empty boundary, so every pattern
                // failed with a message reading `it leaves the repository root ()`.
                // Failing closed for a reason nobody can act on is still a defect:
                // a relative pattern from a relative include file means "relative
                // to the tree this process is standing in", and that is the tree
                // the gate above already established.
                $this->copyGlob($anchor, $worktreePath, $pattern);
            }
        }
    }

    /**
     * Resolve a pattern that may contain negations into a list of positive-only patterns.
     *
     * Negation patterns (lines starting with !) remove files from the result set.
     * This method expands a line containing ! patterns into individual positive patterns
     * after applying the negations.
     */
    private function resolveNegations(string $line, string $includeFile): array
    {
        $dir = dirname($includeFile);
        $positivePatterns = [];
        $negations = [];
        $parts = preg_split('/\s+/', $line);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '!')) {
                $negations[] = substr($part, 1);
            } else {
                $positivePatterns[] = $part;
            }
        }

        $result = [];
        foreach ($positivePatterns as $pattern) {
            $matched = $this->globAll($dir, $pattern);
            foreach ($matched as $file) {
                $isNegated = false;
                foreach ($negations as $neg) {
                    if ($this->matchesGlob($file, $neg, $dir)) {
                        $isNegated = true;
                        break;
                    }
                }
                if (!$isNegated) {
                    $result[] = $file;
                }
            }
        }

        return $result;
    }

    /**
     * Copy a single file or directory matching a glob pattern from src to dest.
     *
     * BOTH ENDS ARE BOUNDED, and the two checks are different questions rather
     * than one written twice:
     *
     *  - the SOURCE is judged by {@see ContainedPath::within()}, which resolves
     *    it — so a `..` pattern AND a pattern that reaches outside through a
     *    symlink inside the checkout are both refused;
     *  - the DESTINATION cannot be judged that way, because it does not exist
     *    yet and {@see ContainedPath} refuses what it cannot resolve. It is
     *    judged on the PATTERN instead ({@see patternStaysInside()}), which is
     *    the only part of the destination path a repository chose.
     *
     * The second is not redundant on today's call path — the source check
     * already refuses everything the destination check would — and that is
     * exactly why it is written: `within()` is a two-`realpath()` snapshot, so
     * "the source resolved inside" is a statement about the instant it was
     * computed, and the write happens later. A purely lexical guard on the one
     * attacker-chosen string has no such window.
     */
    private function copyGlob(string $srcRoot, string $destRoot, string $pattern): void
    {
        $srcPath = $srcRoot . '/' . $pattern;

        if (!ContainedPath::within($srcPath, $srcRoot) || !self::patternStaysInside($pattern)) {
            RuntimeNoticeSink::warn(sprintf(
                'WorktreeManager: refusing .worktreeinclude pattern "%s" — it leaves the source root (%s) or '
                . 'the worktree (%s).',
                $pattern,
                // "the repository root (%s)" is what this said, and with no repo
                // root it printed `it leaves the repository root ()` — a refusal
                // naming an operand that is the empty string. Two things were wrong
                // and only one of them was the wording: the CALLER was passing
                // `$this->repoRoot` rather than the tree it had actually resolved
                // the include file against, so the empty case was reachable.
                //
                // NO `$srcRoot === ''` ARM HERE, deliberately. It would be a
                // message branch that cannot fire: this method is private with one
                // caller, and that caller has already refused the include file
                // against this same anchor — `ContainedPath::within($file, '')` is
                // false — so an empty root never reaches this line. An arm guarding
                // an unreachable state is the twin of the figure that travels
                // without its domain, and this round found three of those.
                $srcRoot,
                $destRoot,
            ));

            return;
        }

        if (is_dir($srcPath)) {
            $destPath = $destRoot . '/' . $pattern;
            if (!is_dir($destPath)) {
                mkdir($destPath, 0755, true);
            }
            $this->copyDirectory($srcPath, $destPath);
            return;
        }

        if (is_file($srcPath)) {
            $destPath = $destRoot . '/' . $pattern;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            copy($srcPath, $destPath);
        }
    }

    /**
     * Can `<root>/<pattern>` name anything outside `<root>` on its spelling
     * alone?
     *
     * SEGMENT-WALKED rather than prefix-compared, and deliberately not routed
     * through {@see ContainedPath}: the path being judged is one that does not
     * exist yet, which that class refuses outright (see its doc-block's
     * `BashEscapeDenyHook` entry for the same trade made for the same reason).
     * It is also not a substring test — `str_contains($pattern, '..')` would
     * reject the legitimate `..dotfile` and `a..b/` — so `..` is only a
     * traversal when it is a whole SEGMENT.
     *
     * An absolute pattern is refused too, though today it merely produces the
     * unreachable `<root>//abs/path`: the guard is about what the string may
     * NAME, not about which concatenation bug currently defuses it.
     *
     * ABSOLUTENESS IS TESTED IN EVERY SPELLING THIS METHOD CLAIMS TO JUDGE, and
     * for one round it was not. The `str_replace('\\', '/')` above treats a
     * BACKSLASH as a separator, which is the method claiming the Windows domain;
     * the absoluteness test was `str_starts_with($pattern, '/')` alone, which is
     * the POSIX half of it. MEASURED, five shapes ALLOWED and one control refused:
     *
     *     \etc\passwd                 ALLOWED      /etc/passwd   refused
     *     \Windows\System32\x         ALLOWED
     *     C:\Users\victim\.ssh\id_rsa ALLOWED
     *     C:/Users/x                  ALLOWED
     *     \                           ALLOWED
     *
     * That is worse than a POSIX-only guard would be, because the doc-block
     * argument for this method's existence is that the LEXICAL guard is the
     * durable one — {@see ContainedPath::within()} is a two-`realpath()` snapshot
     * and the write happens later — so on Windows the durable guard was the
     * defeated one and the racy guard was carrying it alone. The drive-letter
     * predicate is the same one {@see \SugarCraft\Crush\Support\HomeDirectory::owned()}
     * uses forty lines from here, deliberately spelled the same way.
     *
     * WHAT IT COSTS: a pattern whose first character is a backslash, or that
     * begins `X:/` or `X:\`, is refused on any host including POSIX ones, where
     * `\etc` is a legal (if perverse) relative filename. That is the trade a
     * lexical guard makes — it judges the STRING, not the host — and refusing a
     * filename nobody commits is cheaper than honouring an absolute path on the
     * host where it is one.
     *
     * WHAT STILL PASSES, stated because the shapes above were found by asking this
     * question of the previous revision: the DRIVE-RELATIVE spelling `C:x` (no
     * separator after the colon), which on Windows means "x relative to the
     * current directory ON DRIVE C" and so can denote a path outside the root.
     * It is measured ALLOWED here. It is left allowed rather than swept up by
     * widening the pattern to a bare `[A-Za-z]:` because the predicate is
     * deliberately the same one
     * {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} uses, and two
     * spellings of one absoluteness test is how the two drift. THE RESIDUAL IS
     * UNVERIFIED, not argued away: this host is POSIX, so whether
     * `<root>/C:x` reaches the drive-relative path or is rejected by the Windows
     * filesystem as a colon inside a component could not be driven here. It is a
     * negative result — an untested case — and not a claim of safety.
     */
    private static function patternStaysInside(string $pattern): bool
    {
        if ($pattern === ''
            || str_starts_with($pattern, '/')
            || str_starts_with($pattern, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $pattern) === 1
        ) {
            return false;
        }

        $depth = 0;
        foreach (explode('/', str_replace('\\', '/', $pattern)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (--$depth < 0) {
                    return false;
                }

                continue;
            }

            ++$depth;
        }

        return true;
    }

    /**
     * Recursively copy a directory's contents.
     */
    private function copyDirectory(string $src, string $dest): void
    {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $entries = @scandir($src);
        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $srcPath = $src . '/' . $entry;
            $destPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /**
     * Get all files matching a glob pattern relative to a base directory.
     *
     * @return array<string> List of relative file paths.
     */
    private function globAll(string $baseDir, string $pattern): array
    {
        if (str_contains($pattern, '**')) {
            return $this->globRecursive($baseDir, $pattern);
        }

        $escaped = addcslashes($pattern, './');
        $escaped = str_replace(['\\?', '\\*'], ['?', '*'], $escaped);

        $fullPattern = $baseDir . '/' . $escaped;
        $matches = glob($fullPattern);

        return $matches === false ? [] : array_map(
            fn(string $m): string => ltrim(substr($m, strlen($baseDir) + 1), '/'),
            $matches,
        );
    }

    /**
     * Recursive glob for ** patterns.
     *
     * @return array<string> List of relative file paths.
     */
    private function globRecursive(string $baseDir, string $pattern): array
    {
        $results = [];
        $prefix = rtrim(substr($pattern, 0, strpos($pattern, '**')), '/');
        $suffix = ltrim(substr($pattern, strpos($pattern, '**') + 2), '/');

        $searchDir = $prefix === '' ? $baseDir : $baseDir . '/' . $prefix;
        if (!is_dir($searchDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }

            $relativePath = $file->getPathname();
            if ($prefix !== '') {
                $relativePath = substr($relativePath, strlen($baseDir) + 1);
            }

            if ($suffix !== '' && !$this->matchesGlob($relativePath, $suffix, $baseDir)) {
                continue;
            }

            if (str_starts_with($relativePath, './')) {
                $relativePath = substr($relativePath, 2);
            }

            $results[] = $relativePath;
        }

        return $results;
    }

    /**
     * Match a path against a glob pattern.
     */
    private function matchesGlob(string $path, string $pattern, string $baseDir): bool
    {
        if ($pattern === '*') {
            return !str_contains($path, '/');
        }

        if (str_ends_with($pattern, '/*')) {
            $dir = substr($pattern, 0, -2);
            return str_starts_with($path, $dir . '/') && !str_contains(substr($path, strlen($dir) + 1), '/');
        }

        if (str_ends_with($pattern, '/**')) {
            $prefix = substr($pattern, 0, -3);
            return str_starts_with($path, $prefix . '/');
        }

        return fnmatch($pattern, $path, FNM_PATHNAME);
    }

    // -------------------------------------------------------------------------
    // Cleanup policy
    // -------------------------------------------------------------------------

    /**
     * Determine whether a worktree has uncommitted changes.
     *
     * Uses `git status --porcelain` to detect any uncommitted modifications,
     * untracked files, or staged changes in the worktree.
     *
     * @param string $worktreePath Absolute path to the worktree.
     * @return bool True if the worktree has uncommitted changes, false otherwise.
     */
    public function worktreeHasUncommittedDiff(string $worktreePath): bool
    {
        if (!is_dir($worktreePath)) {
            return false;
        }

        $escapedPath = escapeshellarg($worktreePath);
        $cmd = "git -C {$escapedPath} status --porcelain 2>&1";

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = trim(implode("\n", $output));

        return $outputStr !== '';
    }

    /**
     * Remove stale worktrees older than the configured cleanup period.
     *
     * Implements the two-tier cleanup policy:
     *
     * 1. **Named worktrees** (created for explicit human sessions) are NEVER
     *    removed automatically — they are always preserved regardless of age.
     *
     * 2. **Unnamed (ephemeral) worktrees** follow a conditional auto-cleanup:
     *    - If the worktree is clean (no uncommitted diff), it is automatically removed.
     *    - If the worktree is dirty (has uncommitted changes), it is left alone
     *      so no work is lost.
     *
     * This method performs a periodic sweep and is typically called at startup
     * or by a background timer. It does not affect worktrees that are still active.
     *
     * @param int $days Worktrees older than this many days are considered stale. Defaults to config value.
     * @return int The number of worktrees actually removed.
     */
    public function cleanupStaleWorktrees(int $days = 0): int
    {
        if ($days <= 0) {
            $days = $this->config->worktreeCleanupPeriodDays;
        }

        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days");
        $removed = 0;

        foreach ($this->registry as $agentId => $meta) {
            $worktreePath = $this->expandedBasePath . '/' . $agentId;

            if (!is_dir($worktreePath)) {
                continue;
            }

            // Named worktrees are always preserved regardless of age
            if (($meta['named'] ?? false) === true) {
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $meta['createdAt']);
            if ($createdAt === false || $createdAt > $cutoff) {
                continue;
            }

            // Unnamed + old + dirty = leave alone so work is not lost
            if ($this->worktreeHasUncommittedDiff($worktreePath)) {
                continue;
            }

            // Unnamed + old + clean = safe to remove
            try {
                $this->removeWorktree($agentId);
                $removed++;
            } catch (\Throwable) {
                // Skip worktrees that fail to remove (e.g., locked files)
            }
        }

        return $removed;
    }

    /**
     * Minimum time between two real cleanupStaleWorktrees() sweeps triggered
     * via sweepIfDue(), in seconds.
     */
    private const SWEEP_INTERVAL_SECONDS = 3600;

    /**
     * Run a cleanup sweep, but only if the last one is more than
     * SWEEP_INTERVAL_SECONDS old (or there has never been one).
     *
     * cleanupStaleWorktrees() previously had no real caller anywhere in the
     * codebase — this gives it one cheap-to-call entry point (a single
     * filemtime-style check on most calls) so Team::claimTask() can invoke it
     * on every claim without doing a full sweep every time.
     *
     * @return int The number of worktrees removed, or 0 if the sweep was skipped.
     */
    public function sweepIfDue(): int
    {
        $marker = $this->expandedBasePath . '/.last-sweep';
        $now = time();

        if (file_exists($marker)) {
            $lastSweep = (int) file_get_contents($marker);
            if (($now - $lastSweep) < self::SWEEP_INTERVAL_SECONDS) {
                return 0;
            }
        }

        if (!is_dir($this->expandedBasePath)) {
            mkdir($this->expandedBasePath, 0755, true);
        }
        file_put_contents($marker, (string) $now, LOCK_EX);

        return $this->cleanupStaleWorktrees();
    }

    /**
     * Mark a worktree as "named" (created for an explicit human session).
     *
     * Named worktrees are never removed automatically by cleanupStaleWorktrees().
     * This is called by P3.S3 after createWorktree() returns to set the named flag.
     *
     * @param string $agentId The agent whose worktree should be marked.
     * @throws \InvalidArgumentException When agentId is empty or contains path traversal.
     * @throws \RuntimeException When the worktree does not exist.
     */
    public function markWorktreeNamed(string $agentId): void
    {
        if ($agentId === '') {
            throw new \InvalidArgumentException('Agent ID must not be empty.');
        }

        if (str_contains($agentId, '..') || str_contains($agentId, '/') || str_contains($agentId, '\\')) {
            throw new \InvalidArgumentException(
                'Agent ID must not contain path traversal sequences, slashes, or backslashes.',
            );
        }

        if (!$this->worktreeExists($agentId)) {
            throw new \RuntimeException(
                sprintf('Worktree for agent "%s" does not exist.', $agentId),
            );
        }

        $this->registry[$agentId]['named'] = true;
        $this->saveRegistry();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a worktree is registered for the given agent.
     */
    private function worktreeExists(string $agentId): bool
    {
        return isset($this->registry[$agentId]);
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        $items = array_diff($entries, ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }

    /**
     * Expand ~ to the server's HOME directory and validate the path.
     *
     * @param string $path A path that may begin with ~ (will be expanded).
     * @return string The expanded, absolute path.
     */
    private function expandPath(string $path): string
    {
        $envOverride = self::worktreesDirOverride();
        if ($envOverride !== null) {
            $path = $envOverride;
        }

        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                sprintf('Path must not contain "..": %s', $path),
            );
        }

        if (str_starts_with($path, '~/')) {
            $home = HomeDirectory::path();
            $path = $home . '/' . substr($path, 2);
        }

        return $path;
    }

    /**
     * The base-path override from the environment, or null when unset/empty.
     *
     * The canonical name is SUGARCRUSH_WORKTREES_DIR. SUGAR_CRUSH_WORKTREES_DIR
     * is the original spelling and one of only two app variables that ever
     * carried the underscore after SUGAR — every other SUGARCRUSH_* variable
     * this app reads does not (crush_code.md Phase 4 item 4). It keeps working
     * for one release so an existing export does not silently relocate every
     * agent worktree back to the config default the day the rename lands; the
     * canonical name wins when both are set, which is the ordering that lets
     * an operator add the new export to a shared profile before removing the
     * old one.
     *
     * No deprecation warning is emitted: the only caller runs inside the
     * constructor, and the interactive TUI owns the terminal by then — a
     * stray STDERR line there corrupts the frame rather than informing anyone.
     */
    private static function worktreesDirOverride(): ?string
    {
        foreach (['SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Load the worktree registry from disk.
     */
    private function loadRegistry(): void
    {
        if (!file_exists($this->registryPath)) {
            $this->registry = [];
            return;
        }

        $fp = fopen($this->registryPath, 'r');
        if ($fp === false) {
            $this->registry = [];
            return;
        }

        flock($fp, LOCK_SH);

        // Read until EOF to avoid TOCTOU race between filesize() and fread()
        $content = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || $content === '') {
            $this->registry = [];
            return;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->registry = is_array($data) ? $data : [];
        } catch (\JsonException) {
            $this->registry = [];
        }
    }

    /**
     * Persist the current worktree registry to disk.
     *
     * @throws \RuntimeException When the file cannot be written.
     */
    private function saveRegistry(): void
    {
        $dir = dirname($this->registryPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents(
            $this->registryPath,
            json_encode($this->registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        if ($bytes === false) {
            throw new \RuntimeException(
                sprintf('Failed to write registry to "%s".', $this->registryPath),
            );
        }
    }
}
