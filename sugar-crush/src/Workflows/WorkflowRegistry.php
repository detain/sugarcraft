<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and loads workflow definitions from PHP DSL files.
 *
 * Workflows are defined as PHP files that return a Workflow object:
 *
 *   // ~/.sugar-crush/workflows/refactor-service.php
 *   <?php
 *   return (new WorkflowBuilder())
 *       ->name('refactor-service')
 *       ->description('Refactor a microservice with tests and docs')
 *       ->stage('analyze', Tasks::agent('architect')->prompt('Analyze the service'))
 *       ->build();
 *
 * The registry maintains an in-memory map for session-registered workflows
 * and delegates to the filesystem for persisted workflow definitions.
 */
final class WorkflowRegistry
{
    /** @var array<string, Workflow> */
    private array $registered = [];

    /**
     * Why the project tier's directory was refused the last time
     * {@see readableProjectDir()} judged it, or null when it was not refused.
     *
     * The diagnostic half of the refusal, kept rather than thrown away, for the
     * reason {@see \SugarCraft\Crush\Skills\SkillLoader::recordSkip()} keeps
     * its skips: the refusal is silent everywhere else — the not-found message
     * stops naming the directory and {@see projectWorkflowsPath()} still
     * reports it — so a user whose repository ships a workflows directory this
     * loader will not read had nothing at all telling them so. Read through
     * {@see projectTierRefusal()}, surfaced at launch by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::reportProjectTierRefusals()}.
     */
    private ?string $projectDirRefusal = null;

    /**
     * Why the USER tier's directory was refused the last time
     * {@see readableUserDir()} judged it, or null when it was not refused.
     *
     * A second field rather than a shared one, because the two answer different
     * questions about different anchors and a launch can refuse both. Read
     * through {@see userTierRefusal()}.
     *
     * THE REFUSAL HAS TO BE LOUDER HERE than for the project tier, not quieter.
     * A refused project tier costs the user workflows they can see in their
     * checkout; a refused USER tier costs them workflows they wrote, and the
     * only other signal is {@see loadYaml()}'s not-found message and a shorter
     * {@see list()}. Drained by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()} into the same
     * launch-refusal collector, which prints `ignoring <path> — <reason>` before
     * Program takes the terminal.
     */
    private ?string $userDirRefusal = null;

    /**
     * @param string      $workflowsPath        The user's own workflow directory. Both
     *        forms load from here: a `.php` file is `require`d, which is running its
     *        code, and this is the directory whose contents the user is ASSUMED to
     *        have written — an assumption $userHome below turns into a check, for
     *        the reason measured further down.
     * @param string|null $projectWorkflowsPath A project's checked-in workflow
     *        directory, or null for none. `.yaml` ONLY, and that asymmetry is the
     *        whole point of the parameter: {@see load()} reaches a `.php` workflow
     *        through `require`, so honouring one out of a cloned repository would
     *        make `/workflow run <name>` arbitrary code execution from repository
     *        content. A YAML workflow is declarative — agent labels, prompts and
     *        tool-name lists — and the tasks it names are dispatched as
     *        {@see \SugarCraft\Crush\Agents\SubAgent}s that carry the launch's
     *        {@see \SugarCraft\Crush\Permissions\PermissionGate}, which refuses
     *        any tool the definition DECLARES and this session's mode denies
     *        before the stage is dispatched at all
     *        ({@see WorkflowEngine::refuseDeniedTools()}). What that gate does
     *        not yet do is decide an individual tool call mid-run, and the
     *        reason is not a missing gate: the pool's executor is still the
     *        simulation described on {@see \SugarCraft\Crush\Agents\ProcessExecutor},
     *        so a workflow stage issues no provider request and no tool call for
     *        anything to evaluate. Read this parameter's safety story as
     *        "declarative, and its declared capability is gated", NOT as
     *        "identical enforcement to a typed delegation" — a typed delegation
     *        through {@see \SugarCraft\Crush\Agents\AgentManager::executeSubAgent()}
     *        does evaluate real tool calls, and no production caller reaches
     *        that path either.
     *
     *        So a `.php` file sitting in a project directory is not a workflow at
     *        all here: it is not listed and not loaded, which is also what stops
     *        it shadowing a same-named one of the user's own.
     *
     *        Symlinks in this tier are CONFINED to it, and the DIRECTORY itself
     *        is confined to $projectRoot — {@see readableProjectDir()} states
     *        why per-entry confinement could not see a symlinked workflows
     *        directory, and what boundary each of the two checks enforces. Both
     *        halves are decided by {@see \SugarCraft\Crush\Support\ContainedPath},
     *        which is also what a project's `.claude/skills` now asks
     *        ({@see \SugarCraft\Crush\Skills\SkillLoader::skillFilesIn()}) — the
     *        two tiers ran their own copies of the idiom until the skills one
     *        was found to be missing the directory-level half entirely, which is
     *        why there is one implementation and no restatement of it here.
     *
     *        This used to be the one tier-difference in the other direction,
     *        justified by "nothing here reads a byte that is not the value of a
     *        known workflow key". That justification was false, and what made it
     *        false was the loader's own error reporting: a committed
     *        `leak.yaml -> ../../secret/id_rsa` was LISTED by {@see list()}, and
     *        `/workflow run leak` answered with the YAML parser's message —
     *        which quotes the offending line of the file it was parsing. A line
     *        of the linked target's content reached the transcript and the
     *        session store, so a REJECTED target leaked more than an accepted
     *        one would. Both halves are closed: the parse error no longer
     *        carries the parser's snippet (see {@see buildFromYamlFile()}), and
     *        a project-tier entry whose real path is outside this directory —
     *        or whose directory's own real path is outside the checkout it was
     *        reached from — is neither listed nor loaded.
     *
     *        THE USER'S OWN TIER IS CONFINED THE SAME TWO WAYS, and the
     *        sentence that stood here until this revision is why it now is. It
     *        read: *"the user's own tier stays unconfined: it is the directory
     *        whose `.php` files this class `require`s, so a link inside it is
     *        the user pointing at their own file, not repository content
     *        reaching for a path the session happens to be able to read."* That
     *        premise is false in the one tier where the consequence is
     *        `require`. A symlink needs no repository to arrive — `tar`, `zip`,
     *        `rsync -a`, `degit` and "download the release tarball" all carry
     *        one and carry no `.git` — and
     *        {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} answers YES
     *        for every such row, because the user extracted the tarball into
     *        their own home. Ownership answers "whose directory is this"; it
     *        cannot answer "who chose where this link points", and only
     *        containment does — the same correction
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} made for
     *        `~/.sugar-crush/agents`, which is a directory of DATA. This one is
     *        the directory of CODE.
     *
     *        MEASURED on this host, `$HOME` mode 0700 and owned by the
     *        launching uid, no `.git` anywhere in the tree, against the build
     *        immediately before this change:
     *
     *            ~/.sugar-crush/workflows -> <outside>    load('pwned') EXECUTED uid=1000
     *            workflows/entry.php -> <outside>/x.php   load('entry') EXECUTED uid=1000
     *
     *        — arbitrary code execution, twice, from `/workflow run <name>`, at
     *        `WorkflowRegistry.php:` the `require` in {@see load()}. TWO
     *        SPELLINGS AND TWO GATES, because neither gate sees the other's
     *        shape: the DIRECTORY must resolve strictly inside $userHome
     *        ({@see readableUserDir()}), which is the only thing that can see
     *        the first row, and each ENTRY must resolve inside that directory
     *        ({@see containedIn()}, now applied to `.php` in {@see load()} and
     *        to `.yaml` through {@see yamlDirectories()}), which is the only
     *        thing that can see the second. A directory anchor alone leaves row
     *        two live; per-entry confinement alone leaves row one live, because
     *        `realpath()` on both sides means the boundary travels with a linked
     *        directory.
     *
     *        WHAT IT COSTS, stated because the previous round's version of this
     *        sentence is what was refuted: a user's own
     *        `~/.sugar-crush/workflows/deploy.php -> /opt/team-workflows/deploy.php`
     *        stops loading, and so does the whole directory symlinked to a
     *        network share. A link whose target is elsewhere INSIDE `$HOME`
     *        — `-> ~/src/workflows/deploy.php`, the layout the refuted sentence
     *        named as its justification — is refused too, and that is a REAL
     *        loss rather than a hypothetical one: the entry check confines to
     *        the workflows directory, not to the home. It is accepted here and
     *        not softened to "anywhere in `$HOME`" because the entry predicate is
     *        the project tier's, one implementation, and a second per-tier
     *        boundary is how the skills copy came to be missing a half. A user
     *        who wants a workflow file that lives elsewhere copies it, or points
     *        the whole registry at that directory through
     *        {@see \SugarCraft\Crush\Cli\Bootstrap}'s configured path — which is
     *        then anchored to its own parent rather than to `$HOME`, i.e. the
     *        user naming the directory is honoured and a link INSIDE the named
     *        directory still is not.
     *
     *        When one directory is BOTH tiers (a session rooted at the user's
     *        home) the two now differ only in their ANCHOR, not in whether they
     *        confine: the dedupe in {@see yamlDirectories()} keeps the project
     *        entry, so the anchor applied is $projectRoot. {@see list()} and
     *        {@see load()} apply the identical predicate, so the two never
     *        disagree about what exists.
     * @param string|null $projectRoot The checkout $projectWorkflowsPath was
     *        derived from, when the caller knows it. It is the boundary the
     *        project workflows DIRECTORY is itself held inside, which is a
     *        different question from the one {@see containedIn()} answers about
     *        an entry — {@see readableProjectDir()} states why the second
     *        question exists, what a caller that omits this gets instead, and
     *        why the answer is not simply "refuse every link".
     *
     *        BE PRECISE ABOUT WHAT IT BUYS, because "inside the checkout" and
     *        "repository content" are not the same set and this doc-block used
     *        to equate them. A checkout also holds untracked, gitignored,
     *        developer-local files. The residual that survives is therefore: a
     *        repository can commit `.sugar-crush/workflows -> <some other
     *        directory in the checkout>` and have {@see list()} disclose the
     *        basenames of every `[a-zA-Z0-9_-]+\.yaml` in it, plus the
     *        `description` of each one that parses as a workflow map. What
     *        {@see readableProjectDir()}'s strictness removes is the version of
     *        that primitive worth having — a link resolving ONTO the checkout
     *        root, which is where a developer's `local-secrets.yaml` and
     *        `kubeconfig.yaml` actually sit, and which `-> ..` reached in one
     *        committed line.
     * @param string|null $userHome The home $workflowsPath must resolve strictly
     *        inside — what $projectRoot is for the project tier, for the tier
     *        whose files are EXECUTED. {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()}
     *        passes the parent of {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()},
     *        so the directory and the home it is held inside can never be two
     *        different homes.
     *
     *        NULL IS NOT "UNCONFINED", and that difference is this parameter's
     *        whole point: null falls back to the directory's OWN PARENT, exactly
     *        as $projectRoot's absence does. Be precise about what the fallback
     *        catches, because the previous round's equivalent sentence was wrong
     *        by one component: it refuses a link AT the workflows directory —
     *        which is the measured escape, and the spelling a tarball needs one
     *        line for — and it does NOT refuse one at a component above it (a
     *        `~/.sugar-crush -> /elsewhere` leaves `<...>/workflows` resolving
     *        inside its own parent). A caller that knows the home gets the
     *        complete boundary; one that does not gets the partial one written
     *        down here rather than a guarantee only somebody else holds.
     *
     *        IT IS NOT AN OWNERSHIP CHECK EITHER, and this class does not make
     *        one. {@see expandPath()} resolves a leading `~` through
     *        {@see \SugarCraft\Crush\Support\HomeDirectory::path()}, whose
     *        fallback is `sys_get_temp_dir()` — so a DEFAULT-constructed
     *        registry in a process with no resolvable `HOME` anchors
     *        `/tmp/.sugar-crush/workflows` to `/tmp/.sugar-crush`, which
     *        contains it, and a local user who pre-created that tree owns the
     *        `require`. Nothing in `src/` constructs one that way — the
     *        production caller passes an absolute path derived from
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()},
     *        which refuses that launch on {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}'s
     *        first line — and the residual is recorded here rather than
     *        half-migrated, because two home resolutions inside one class is the
     *        failure {@see \SugarCraft\Crush\Support\HomeDirectory} exists to
     *        have ended.
     */
    public function __construct(
        private readonly string $workflowsPath = '~/.sugar-crush/workflows/',
        private readonly ?string $projectWorkflowsPath = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $userHome = null,
    ) {}

    /**
     * The user's own workflow directory, tilde expanded and without its
     * trailing slash.
     *
     * Public because it is also where {@see WorkflowEngine} keeps its pause
     * files: the engine anchors those to this registry's directory rather than
     * to `~` so that a registry pointed somewhere trusted does not pause into a
     * directory nobody vetted. See {@see WorkflowEngine::getPauseFilePath()}.
     *
     * THE CONFIGURED PATH, NOT THE ONE {@see readableUserDir()} VOUCHES FOR, and the
     * difference is a stated residual rather than an oversight. Every read this
     * class performs goes through the anchored answer; the pause files do not,
     * because they are the engine's own writes and the accessor predates the
     * anchor. So a `~/.sugar-crush/workflows -> <outside>` link that this registry
     * refuses to list or `require` out of still relocates
     * `<outside>/.running/*.json`, which the engine writes and reads back. What that
     * yields an attacker is the engine's own JSON in a directory they already chose
     * — not code, not a foreign file's contents — which is why it is recorded here
     * and in {@see \SugarCraft\Crush\Tests\Support\ReadPathCensusTest} instead of
     * being closed by handing the engine a second, stricter accessor whose two
     * answers could then disagree about where a run was paused.
     */
    public function workflowsPath(): string
    {
        return $this->expandPath($this->workflowsPath);
    }

    /**
     * The project's checked-in workflow directory, expanded, or null when this
     * registry was built without one.
     *
     * The CONFIGURED directory, not a promise that anything is read from it:
     * {@see readableProjectDir()} can refuse the directory itself, and this
     * accessor still names it. Callers wanting "where workflows actually come
     * from" want {@see list()}.
     */
    public function projectWorkflowsPath(): ?string
    {
        return $this->projectWorkflowsPath === null
            ? null
            : $this->expandPath($this->projectWorkflowsPath);
    }

    /**
     * Load a workflow by name from the filesystem or registered sessions.
     *
     * Tries .php first, then falls back to .yaml.
     *
     * VALIDATION BELOW COVERS YAML ONLY, and the gap is not fixable here: a
     * user-tier `.php` workflow is reached by `require`, so a syntax error in it
     * is a COMPILE fatal — uncatchable, which means {@see \SugarCraft\Crush\Chat}'s
     * `catch (\Throwable)` around `/workflow run` cannot keep the session alive
     * through one. A PHP workflow that does not parse takes the TUI with it. The
     * YAML path, by contrast, reports every malformed shape as a
     * {@see WorkflowLoadException} the command turns into one transcript line.
     *
     * @throws WorkflowNotFoundException When the workflow file does not exist.
     * @throws WorkflowLoadException When the file does not return a Workflow instance.
     */
    public function load(string $name): Workflow
    {
        // Check in-memory registry first
        if (isset($this->registered[$name])) {
            return $this->registered[$name];
        }

        $this->validateName($name);

        // The project tier is consulted BEFORE the user's own directory, the
        // same precedence {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresets()}
        // gives a checked-in agent preset: what `deploy` means is a property of
        // the checkout you are sitting in.
        //
        // THAT CROSSES EXTENSIONS, and the previous phrasing here ("the override
        // it can perform is bounded to data") was true about the PAYLOAD and
        // silent about the SUBSTITUTION. Because this fast path runs before
        // {@see resolvePhpPath()} below, a cloned `deploy.yaml` shadows the
        // user's own `deploy.php`: `/workflow run deploy` runs the repository's
        // declarative stages instead of the PHP workflow the user wrote.
        // Deliberate, and pinned by
        // `testAProjectYamlShadowsASameNamedUserPhpWorkflow`. The alternative —
        // letting a `.php` win by extension — makes what a name means depend on
        // which spelling each tier happens to use, and hands a stale
        // `~/.sugar-crush/workflows/deploy.php` the power to shadow the curated
        // `deploy.yaml` a repository ships, which is the same surprise pointing
        // the other way. What the tier still cannot do is ADD code: it is
        // `.yaml`-only (see the constructor), so the substitution replaces code
        // with data and never the reverse.
        //
        // is_file() AND contained: is_file() stats THROUGH a symlink, so
        // without the second test a committed `deploy.yaml -> /etc/shadow`
        // would be opened here. The listing filters the same entry with the
        // same predicate (see baseNames()), which is what keeps "listed" and
        // "loadable" the same set.
        $projectYaml = $this->projectYamlPath($name);
        if ($projectYaml !== null && is_file($projectYaml) && $this->containedIn($projectYaml, dirname($projectYaml))) {
            return $this->buildFromYamlFile($projectYaml)->build();
        }

        // Try PHP file first.
        //
        // is_file(), NOT file_exists(): a DIRECTORY named `<name>.php` satisfies
        // file_exists(), and `require`ing a directory emits a PHP Warning on
        // stderr — which lands in the middle of a live TUI frame — before
        // failing with an uncatchable "Failed opening required" compile error.
        // list() has always filtered directories out (see baseNames()), so
        // file_exists() here made load() strictly MORE permissive than the
        // listing, which is the one direction that can hurt.
        // NULL when the user tier's own directory is refused, which is a
        // different answer from "no such file" and has to be one: this is the
        // path that gets `require`d, so the directory-level question must be
        // settled before the file-level one. See {@see readableUserDir()}.
        $phpPath = $this->resolvePhpPath($name);

        // is_file() AND contained, the same pair the project fast path above
        // needs and for a worse reason. is_file() stats THROUGH a symlink, so
        // without the second test `workflows/entry.php -> <outside>/x.php` was
        // `require`d — arbitrary code execution measured on this host, see the
        // constructor. The listing filters the same entry with the same
        // predicate ({@see baseNames()}), which is what keeps "listed" and
        // "loadable" one set here too.
        if ($phpPath !== null && is_file($phpPath) && $this->containedIn($phpPath, \dirname($phpPath))) {
            $workflow = require $phpPath;

            if (!$workflow instanceof Workflow) {
                throw new WorkflowLoadException(
                    "Workflow file {$phpPath} must return a Workflow instance, got " . get_debug_type($workflow)
                );
            }

            return $workflow;
        }

        // Fall back to YAML
        return $this->loadYaml($name);
    }

    /**
     * List all available workflow names from the filesystem.
     *
     * Returns base names of .php and .yaml files in the user's workflow
     * directory plus .yaml files in the project one, excluding hidden files
     * and directories.
     *
     * Deliberately the exact set of names {@see load()} will look a file up for,
     * which is why four kinds of entry are absent: a project `.php` file (that
     * tier does not honour PHP at all), a directory that merely ends in `.yaml`
     * or `.php`, a file whose stripped basename is not a name
     * {@see validateName()} accepts — `lint.v2.yaml` among them — and an entry
     * whose real path is outside the directory it was listed from, in EITHER
     * tier ({@see baseNames()}). Listing a name whose `/workflow run` answers
     * "not found" or "Invalid workflow name" is worse than not listing it.
     *
     * The set of names, not the set of names that will SUCCEED: a listed
     * `deploy` whose YAML is malformed is still listed, and `load()` reports the
     * malformed shape. Discovery and validity are separate answers.
     *
     * "From the filesystem", also literally: a workflow put in this session's
     * memory by {@see register()} is deliberately absent, even though
     * {@see load()} resolves it first of all. A registered workflow is a value
     * the calling code already holds — it needs no discovering, it exists only
     * for this process, and listing it would advertise as available-to-run
     * something no later session can resolve. That is the one direction in
     * which this listing is narrower than the loader, and it is intentional.
     *
     * @return string[]
     */
    public function list(): array
    {
        $names = [];

        foreach ($this->yamlDirectories() as $dir) {
            foreach ($this->baseNames($dir, '.yaml') as $name) {
                $names[$name] = true;
            }
        }

        // readableUserDir(), NOT workflowsPath(): the `.php` listing has to make
        // the same directory-level judgement load() makes, and reading the
        // configured path here is what kept a linked workflows directory in the
        // listing after the loader had stopped resolving out of it.
        $userDir = $this->readableUserDir();
        if ($userDir !== null) {
            foreach ($this->baseNames($userDir, '.php') as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Base names of the $suffix files directly inside $dir, hidden entries and
     * subdirectories skipped, and names {@see load()} would refuse skipped too.
     *
     * That last filter is what makes {@see list()}'s "exactly the set load() can
     * resolve" claim true rather than nearly true. Stripping the suffix leaves
     * the REST of the basename intact, so `lint.v2.yaml` used to be listed as
     * `lint.v2` — a name {@see validateName()} then rejects, i.e. `/workflow
     * list` offering an entry whose `/workflow run` answers "Invalid workflow
     * name". Pre-existing for a user's own directory; the project tier is what
     * made it reachable by committing a file, which is why it is filtered here
     * rather than left as a curiosity.
     *
     * AN ENTRY WHOSE REAL PATH IS OUTSIDE $dir IS SKIPPED, in BOTH tiers and for
     * BOTH suffixes. This was a `bool $confineSymlinks = false` parameter, passed
     * `true` only for the project tier; the user tier's `false` is the escape
     * measured in the constructor's doc-block, and the `.php` listing never passed
     * anything at all. {@see load()} applies the identical predicate to the entry
     * it resolves, so a name dropped here is also a name the loader refuses, and
     * the two answers stay one answer.
     *
     * @return list<string>
     */
    private function baseNames(string $dir, string $suffix): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);
        if ($files === false) {
            return [];
        }

        $names = [];
        foreach ($files as $file) {
            // Skip hidden files, '.', and '..'
            if ($file === '' || $file[0] === '.') {
                continue;
            }

            if (!str_ends_with($file, $suffix) || !is_file($dir . '/' . $file)) {
                continue;
            }

            if (!$this->containedIn($dir . '/' . $file, $dir)) {
                continue;
            }

            $name = basename($file, $suffix);
            if ($this->nameIsValid($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Does $path really live under $dir, rather than merely being named there?
     *
     * `is_file()` and `Yaml::parseFile()` both stat and read THROUGH a symlink,
     * so this is the only thing standing between a committed
     * `deploy.yaml -> /etc/shadow` and the loader opening it.
     *
     * THE PREDICATE ITSELF LIVES IN {@see ContainedPath}, which is where the
     * "resolve both sides, compare with a trailing separator" argument is
     * written down once — including why the separator is load-bearing and what
     * an unresolvable path answers. This method exists for the ENTRY question
     * ({@see ContainedPath::within()}, equality counted as contained) and for
     * the sentence below, which is specific to this class.
     *
     * $dir is resolved too, which is what makes this predicate blind to a $dir
     * that is ITSELF a link: the boundary moves with it. That is a real escape
     * and it is answered separately, on the directory rather than the entry, by
     * {@see readableProjectDir()} — which asks the STRICTER
     * {@see ContainedPath::below()} precisely because the two questions differ
     * about equality.
     *
     * The separator half is pinned by
     * `testASiblingDirectorySharingTheProjectDirsNameIsNotContained`: dropping
     * the `. '/'` from either side of that comparison left the whole suite green
     * while making a `proj/sib.yaml -> ../projevil/x.yaml` link loadable.
     */
    private function containedIn(string $path, string $dir): bool
    {
        return ContainedPath::within($path, $dir);
    }

    /**
     * Every directory a `.yaml` workflow may be read from, project tier first.
     *
     * THIS USED TO CARRY A PER-DIRECTORY `confine symlinks?` FLAG, and the flag
     * was the escape. It was `true` for the project tier and `false` for the
     * user's, which is the constructor's refuted premise expressed as data; with
     * both tiers confined the flag has exactly one value, so it is gone rather
     * than threaded through as a constant. A parameter that can only be wrong in
     * one direction is worth less than an unconditional check: the next reader
     * cannot pass `false` by accident because there is nothing to pass.
     *
     * Deduplicated because the two tiers genuinely can name one directory — a
     * session rooted at the user's own home resolves both to
     * `~/.sugar-crush/workflows` — and a repeated entry would put the same
     * directory twice into {@see loadYaml()}'s not-found message. The dedupe
     * keeps the PROJECT entry when they collide, which is now purely cosmetic
     * again: both tiers confine their entries, so the only surviving difference
     * is which ANCHOR the directory itself was judged against, and that judgement
     * has already happened by the time a directory reaches this list.
     *
     * EITHER TIER CAN BE ABSENT HERE, and both absences are refusals rather than
     * emptinesses: a project directory {@see readableProjectDir()} refuses is not
     * added, and neither is a user directory {@see readableUserDir()} refuses.
     * When BOTH are refused this returns `[]`, which is why {@see loadYaml()} has
     * to name a directory of its own in that case rather than reporting "not
     * found at " with nothing after it.
     *
     * @return list<string> directories, project tier first
     */
    private function yamlDirectories(): array
    {
        $dirs = [];

        $projectDir = $this->readableProjectDir();
        if ($projectDir !== null) {
            $dirs[] = $projectDir;
        }

        $userDir = $this->readableUserDir();
        if ($userDir !== null && !\in_array($userDir, $dirs, true)) {
            $dirs[] = $userDir;
        }

        return $dirs;
    }

    /**
     * Where a project-tier `$name.yaml` would be, or null when this registry
     * was built without a project tier — or with one whose directory
     * {@see readableProjectDir()} refuses. Caller checks existence.
     *
     * Null for a refused directory rather than a path the caller then filters,
     * because {@see load()}'s project-first fast path is the ONE place that
     * reads a project entry without going through {@see yamlDirectories()}. A
     * directory dropped from that map and still reachable here would be exactly
     * the "listed and loadable are the same set" claim breaking in the
     * dangerous direction.
     */
    private function projectYamlPath(string $name): ?string
    {
        $dir = $this->readableProjectDir();
        if ($dir === null) {
            return null;
        }

        $this->validateName($name);

        return $dir . "/{$name}.yaml";
    }

    /**
     * May the project tier's own DIRECTORY be read from at all?
     *
     * {@see containedIn()} judges an entry against the directory it was reached
     * from, and it resolves that directory too — so when the directory is
     * itself a link, the boundary travels with it and nothing inside it can
     * ever be outside it. A repository can ship precisely that: the path
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()} builds is
     * `<root>/.sugar-crush/workflows`, and committing that as a symlink was
     * enough to have {@see list()} enumerate every `[a-zA-Z0-9_-]+\.yaml`
     * basename in the target directory and {@see load()} read any of them that
     * parsed as a workflow map — its `description` into the listing, its
     * prompts into agent tasks. Per-entry confinement cannot see that, because
     * per-entry confinement is being asked the wrong question.
     *
     * So the directory gets its own, coarser boundary: it must resolve STRICTLY
     * inside the checkout. $projectRoot is the one path in this pair a
     * repository cannot have forged, since a link that moved it would have to
     * sit ABOVE the checkout. A link that stays inside the checkout is honoured
     * — refusing every link would break the ordinary
     * `.sugar-crush/workflows -> tools/workflows` layout for no gain.
     *
     * STRICTLY, and the word replaced a measured leak. {@see containedIn()}
     * counts "resolves onto the boundary" as contained, which is right for an
     * entry and wrong for this: a repository committing the single line
     * `.sugar-crush/workflows -> ..` resolved its workflows directory exactly
     * onto the checkout root and was accepted. Measured on this host against
     * the pre-fix build, with a checkout holding two gitignored developer-local
     * files: `list()` returned `["kubeconfig","local-secrets"]` and
     * `load('local-secrets')->description` was `TOKEN=sk-live-DEADBEEF`. So
     * `/workflow list` enumerated the basenames of every
     * `[a-zA-Z0-9_-]+\.yaml` in the developer's checkout regardless of
     * provenance, and any that parsed as a workflow map put its `description`
     * in the listing and the transcript. {@see ContainedPath::below()} refuses
     * the equality arm, and the checkout root is the one directory in the tree
     * where untracked local files conventionally sit.
     *
     * WHAT IS LEFT, stated rather than implied: a link to some OTHER directory
     * inside the checkout is still honoured, and a checkout is not the same set
     * as repository content — it holds untracked and gitignored files too. A
     * repository that commits `.sugar-crush/workflows -> vendor` can still
     * disclose `vendor/*.yaml` basenames. That residual is bounded by the
     * attacker having to name a directory whose contents they cannot see, and
     * closing it entirely would mean refusing the `-> tools/workflows` layout
     * this method exists not to refuse. It is a reduction, not an elimination.
     *
     * With no $projectRoot the anchor falls back to the directory's own parent.
     * Be precise about what that does and does not catch: it refuses a link AT
     * the workflows directory, and it does NOT refuse one at a component above
     * it (a committed `.sugar-crush -> /elsewhere` leaves `<...>/workflows`
     * resolving inside its own parent). That is the whole of what a registry
     * holding only the leaf path can check, which is why
     * {@see \SugarCraft\Crush\Cli\Bootstrap} passes the root — the production
     * path gets the complete boundary, and a caller that omits it gets a
     * partial one that is written down rather than a guarantee only somebody
     * else has.
     *
     * A directory that does not resolve AT ALL splits in two, and it used to
     * not. "Left readable, because nothing is behind a path that does not
     * resolve" was true per-instant and false across the call: granting is one
     * syscall and reading is another, so a target created in between is read
     * through a boundary that has moved with it — and per-ENTRY confinement
     * cannot refuse that, because by then both sides resolve through the same
     * link. Structurally: grant the dangling directory, create the target, and
     * `baseNames($granted, '.yaml', confine: true)` returns its contents.
     *
     * The half worth keeping is the fresh checkout that simply has no
     * `.sugar-crush/workflows` — keeping it is what lets {@see loadYaml()}'s
     * not-found message still name the directory the user was expecting. The
     * half worth refusing is a DANGLING SYMLINK: a link naming a path that does
     * not exist yet is a request to read whatever appears there later, and a link
     * that names nothing has no workflows to lose. `is_link()` tells those two
     * apart with no extra trust.
     *
     * `is_link()` DOES NOT COVER EVERY NON-RESOLVING LAYOUT A REPOSITORY CAN
     * COMMIT, and an earlier revision of this paragraph claimed it did. Measured
     * this session with the link one component higher — `.sugar-crush -> <a
     * directory that exists but holds no workflows/>` — `realpath()` of this
     * method's `$dir` is false AND `is_link($dir)` is false, because the link is
     * on the parent: the refusal does not fire and the directory is granted.
     * `is_link()` is still worth keeping (it catches the one-line spelling that
     * needs no second component), but the honest statement is that NOT RESOLVING
     * is granted except for the leaf-link case.
     *
     * So the window is narrowed, not closed, and the cost of the remainder is
     * lower than this paragraph used to claim. It said the grant-then-read
     * remainder needs "write access to the checkout, and anything with that can
     * simply commit a `.yaml`" — false for the layout above, where the path that
     * must appear is `<the link's target>/workflows`, OUTSIDE the checkout: for
     * `.sugar-crush -> /tmp/pwn` it costs write access to `/tmp`, which any local
     * user has. What the attacker must then win is a race inside ONE call, between
     * this method returning $dir and `baseNames($dir, …)` reading it.
     *
     * Across CALLS there is no residue: every {@see list()} and {@see load()}
     * re-evaluates this method, so a target that has appeared is refused on the
     * next one — measured for both layouts (`list()` returns `[]` while the leaf
     * link dangles, `[]` after its outside-the-checkout target is created, `[]`
     * before the parent-link target's `workflows/` exists and `[]` after, the
     * last one now carrying a refusal). Nor could the in-call race be won here:
     * measured over 20,000 `list()` calls in the parent-link layout above, with a
     * forked child creating and removing `/tmp/pwn/workflows` as fast as it could
     * for the whole run, **0** returned a name. So this is recorded as a claim
     * defect over "the spellings a repository can commit" — the `is_link()`
     * sentence was false and the cost sentence was wrong by one component — and
     * not as a live escape.
     *
     * The refusal drops the tier rather than emptying it, which matters for the
     * one directory that is BOTH tiers: {@see yamlDirectories()} then offers it
     * to {@see readableUserDir()}, which asks the same question against the
     * user's home instead of the checkout. THE JUSTIFICATION FOR THAT USED TO BE
     * A REFUTED ONE — "the user tier `require`s the `.php` files out of that same
     * directory, so a stricter YAML reading of it would be guarding a door
     * beside an open one" — and the door is now shut: the `require` is gated by
     * a directory anchor and a per-entry check of its own. What survives is the
     * narrower and true statement: a directory the CHECKOUT does not vouch for
     * may still be one the USER's home does, and dropping it out of both tiers
     * would refuse a user their own workflows because a project pointed at them.
     * Pinned by
     * `testACollidedDirectoryRefusedAsTheProjectTierIsStillReadAsTheUserTier`,
     * because "drops rather than empties" was documented three times and
     * measured nowhere.
     */
    private function readableProjectDir(): ?string
    {
        // Reset first: this method is re-evaluated on every list()/load(), and a
        // stale refusal outliving the condition that caused it would report a
        // directory as refused after it had started working.
        $this->projectDirRefusal = null;

        if ($this->projectWorkflowsPath === null) {
            return null;
        }

        $dir = $this->expandPath($this->projectWorkflowsPath);
        $real = realpath($dir);

        if ($real === false) {
            if (is_link($dir)) {
                $this->projectDirRefusal = 'is a symlink that resolves to nothing this process can '
                    . 'read, so it names no workflows — and a committed link to a path that does not exist '
                    . 'yet is a request to read whatever appears there later.';

                return null;
            }

            return $dir;
        }

        $anchor = $this->projectRoot !== null ? $this->expandPath($this->projectRoot) : dirname($dir);

        if (ContainedPath::below($dir, $anchor)) {
            return $dir;
        }

        // THE ANCHOR IS NAMED, not merely described. This message said "which is
        // outside the checkout root" and stopped there, while its three sibling
        // feeders all interpolate the anchor PATH — so the one refusal a reader
        // could do least about was the one that did not say WHICH root it was
        // measured against. A launch can have a `--root` the user did not expect
        // (see {@see \SugarCraft\Crush\Cli\ArgvParser}), and "outside the checkout
        // root" is unfalsifiable without it.
        $this->projectDirRefusal = sprintf(
            'resolves to %s, which is %s %s (%s), so it is not this repository pointing at its own '
            . 'workflows; the directory is skipped and no name in it is listed or loadable.',
            $real,
            realpath($anchor) === $real ? 'exactly' : 'outside',
            $this->projectRoot !== null ? 'the checkout root' : "this directory's own parent",
            $anchor,
        );

        return null;
    }

    /**
     * May the USER tier's own directory be read from — and `require`d out of —
     * at all?
     *
     * {@see readableProjectDir()}'s question, asked about the tier that had no
     * containment of any kind. Everything that method's doc-block says about WHY
     * a directory needs its own boundary applies here verbatim and is not
     * restated: per-entry confinement resolves the directory too, so a linked
     * directory moves the boundary with it and nothing inside it can ever be
     * outside it.
     *
     * WHAT IS DIFFERENT IS THE ANCHOR AND THE STAKE. The anchor is `$userHome`
     * (see the constructor) rather than a checkout, and the stake is `require`:
     * this is the only directory in the package whose contents are EXECUTED, so
     * the escape this closes was arbitrary code execution rather than
     * disclosure. Both are measured in the constructor's doc-block.
     *
     * BELOW(), NOT WITHIN(), for the reason {@see readableProjectDir()} takes
     * the strict predicate: a `workflows -> ..` link resolving exactly onto
     * `~/.sugar-crush` would make every `.php` file in the config directory a
     * loadable workflow, and `~/.sugar-crush` is where `config.json`,
     * `hooks.yaml` and the session store live rather than a curated directory of
     * workflow files.
     *
     * A DIRECTORY THAT DOES NOT RESOLVE splits the same two ways it does for the
     * project tier, and for the same measured reason — a fresh install simply has
     * no `~/.sugar-crush/workflows` and must keep {@see loadYaml()}'s not-found
     * message naming it, while a DANGLING link is a request to read whatever
     * appears at that path later. `is_link()` tells those apart, and carries the
     * same bound as it does there: a link one component HIGHER
     * (`~/.sugar-crush -> <an existing directory holding no workflows/>`)
     * resolves to false with `is_link($dir)` false, so it is granted. That bound
     * is narrower here than there in one respect and wider in another: narrower
     * because `$userHome` is passed in production so the resolving case is fully
     * anchored, wider because what a won race yields here is a `require`.
     */
    private function readableUserDir(): ?string
    {
        // Reset first, for the reason readableProjectDir() resets first: this is
        // re-evaluated on every list()/load(), and a stale refusal would outlive
        // the condition that caused it.
        $this->userDirRefusal = null;

        $dir = $this->expandPath($this->workflowsPath);
        $real = realpath($dir);

        if ($real === false) {
            if (is_link($dir)) {
                $this->userDirRefusal = 'is a symlink that resolves to nothing this process can read, so it '
                    . 'names no workflows — and a link to a path that does not exist yet is a request to '
                    . 'require whatever appears there later.';

                return null;
            }

            return $dir;
        }

        $anchor = $this->userHome !== null ? $this->expandPath($this->userHome) : \dirname($dir);

        if (ContainedPath::below($dir, $anchor)) {
            return $dir;
        }

        $this->userDirRefusal = sprintf(
            'resolves to %s, which is %s %s (%s), so it is not a directory inside the home this launch '
            . 'established as yours; it is skipped and no workflow in it is listed, loadable or `require`d.',
            $real,
            realpath($anchor) === $real ? 'exactly' : 'outside',
            $this->userHome !== null ? 'your home directory' : "this directory's own parent",
            $anchor,
        );

        return null;
    }

    /**
     * Why this registry refuses to read its user tier's directory, or null when
     * it does not refuse it.
     *
     * {@see projectTierRefusal()}'s seam for the other tier — same pull-based
     * shape, same reason it is not a stderr write (a write from here lands
     * mid-frame under the alt screen), same mid-clause string starting at
     * "resolves to …" because the one notice that prints it composes
     * `ignoring <path> — <reason>` and already holds the configured path.
     *
     * UNLIKE its project-tier twin this one interpolates the ANCHOR PATH as well
     * as describing it in words, which is what the other three feeders do; see
     * {@see projectTierRefusal()} for the corrected statement of how the four
     * messages differ.
     */
    public function userTierRefusal(): ?string
    {
        $this->readableUserDir();

        return $this->userDirRefusal;
    }

    /**
     * Why this registry refuses to read its project tier's directory, or null
     * when it does not refuse it.
     *
     * The seam the refusal needed to stop being invisible. Everything else about
     * a refused tier is silent by design: {@see loadYaml()}'s not-found message
     * drops the directory from `$searched` (so a user reads `not found at
     * /…/home/zzz.yaml` and concludes the loader never looked in their repo),
     * {@see projectWorkflowsPath()} still reports the CONFIGURED path, and
     * {@see list()} simply returns fewer names. None of those can say "your
     * repository's workflows directory was rejected, and here is why".
     *
     * A REASON, NOT A SENTENCE: the string starts mid-clause ("resolves to …")
     * and does not name the CONFIGURED path, because that path is what the caller
     * already has — {@see projectWorkflowsPath()} here, the map key in
     * {@see \SugarCraft\Crush\Cli\Bootstrap::projectTierRefusals()} — and the one
     * notice that prints it composes `ignoring <path> — <reason>`. It used to
     * repeat the path inside the reason, which put it in that line twice.
     *
     * THE FEEDERS ARE NOT IDENTICAL, and the sentence here used to claim they
     * were, then claimed a difference that this revision removed. Measured
     * against the reason strings as they now stand:
     *
     *   - this one and {@see userTierRefusal()} name the RESOLVED target,
     *     describe the anchor in words ("the checkout root" / "your home
     *     directory" / "this directory's own parent") AND interpolate the anchor
     *     path;
     *   - {@see \SugarCraft\Crush\Skills\SkillLoader::recordRefusedDirectory()}
     *     and {@see \SugarCraft\Crush\Agents\AgentPresetRegistry::refusedDirectories()}
     *     name the resolved target AND interpolate the anchor PATH, without the
     *     words.
     *
     * So all of them omit the configured path — which is the property the
     * collector needs, since the collector supplies it as the map key — and all
     * of them now name the anchor. The previous statement, "only this one also
     * omits the anchor path", was accurate and is what got fixed: a refusal that
     * cannot say which root it measured against is unfalsifiable for the person
     * reading it. A further holder of a repository-chosen directory,
     * {@see \SugarCraft\Crush\Commands\CommandLoader}, does not feed the collector
     * at all — it `error_log()`s, and its message carries `$dir`, `$realDir` and
     * `$anchoredIn` together.
     *
     * Public and pull-based rather than a stderr write, for the reason
     * {@see \SugarCraft\Crush\Skills\SkillLoader::recordSkip()} is: a write from
     * here can land mid-frame under the alt screen.
     * {@see \SugarCraft\Crush\Cli\Bootstrap::reportProjectTierRefusals()} asks
     * for it at construction time, before Program takes the terminal.
     */
    public function projectTierRefusal(): ?string
    {
        $this->readableProjectDir();

        return $this->projectDirRefusal;
    }

    /**
     * Register a workflow in-memory for the current session.
     *
     * Registered workflows take precedence over filesystem workflows
     * when using load().
     */
    public function register(Workflow $workflow): void
    {
        $this->registered[$workflow->name] = $workflow;
    }

    /**
     * Load a workflow definition from a YAML file.
     *
     * @throws WorkflowNotFoundException When the YAML file does not exist.
     * @throws WorkflowLoadException When the YAML is invalid or missing required fields.
     */
    public function loadYaml(string $name): Workflow
    {
        $this->validateName($name);

        $searched = [];
        foreach ($this->yamlDirectories() as $dir) {
            $yamlPath = $dir . "/{$name}.yaml";

            // An entry that resolves outside its directory is treated as absent —
            // the same answer {@see list()} gives for it, and the reason the two
            // cannot disagree. It stays in $searched so the not-found message
            // still names where the loader looked.
            if (is_file($yamlPath) && $this->containedIn($yamlPath, $dir)) {
                return $this->buildFromYamlFile($yamlPath)->build();
            }

            $searched[] = $yamlPath;
        }

        // BOTH TIERS REFUSED leaves nothing to name, and "not found at " with
        // nothing after it is a worse answer than naming the directory the user
        // configured — which is what they are looking for and what
        // {@see userTierRefusal()} explains separately. The configured spelling,
        // deliberately, since a refused directory has no path this loader is
        // willing to have resolved.
        if ($searched === []) {
            $searched[] = $this->workflowsPath() . "/{$name}.yaml";
        }

        throw new WorkflowNotFoundException(
            "Workflow '{$name}' not found at " . implode(', ', $searched)
        );
    }

    /**
     * Parse, validate and map one YAML workflow file onto a builder chain.
     *
     * Every key this loader READS is shape-checked, and every failed check
     * reports a {@see WorkflowLoadException} naming the file — because these
     * files are hand-authored and, since the project tier exists, may have been
     * hand-authored by somebody else. Without them a `stages:` entry missing its
     * `name` reached {@see WorkflowBuilder::stage()} as null and the user's
     * `/workflow run` answered with a raw TypeError about an argument they never
     * passed.
     *
     * The checked set, exhaustively, so this claim can be audited rather than
     * trusted: the document is a map; `name` is a non-empty string that
     * {@see nameIsValid()} accepts; `description` is a string if present;
     * `stages` is a LIST if present (`array_is_list()`, not merely an array);
     * each stage is a map with a non-empty string `name`, and no two stages
     * share one; `parallel` is a real boolean if present; `agent`/`prompt` (and
     * a parallel agent's `type`/`name`/`prompt`) are strings if present;
     * `tools` is a list of strings; a parallel stage's `agents` is a list of
     * maps; `config` is a map whose `maxConcurrent`/`timeout` are whole numbers
     * >= 1.
     *
     * "Is a list" means `array_is_list()`. It used to mean `is_array()`, which
     * is a different claim: `stages: {a: {...}}`, `tools: {a: Read}` and a
     * parallel `agents: {x: {...}}` all satisfied the check and loaded, the
     * keys silently discarded. An author who wrote a map meant something by the
     * keys, and quietly reindexing them is the loader deciding it knows better.
     *
     * A key that is PRESENT with an explicit null (`stages: ~`, `prompt: ~`,
     * `timeout: ~`) is refused, not treated as absent. `isset()` cannot tell
     * the two apart, and the difference was doing real damage at the top:
     * `stages: ~` loaded as a workflow with zero stages, which `/workflow run`
     * then reported as `completed` having executed nothing — the same silent
     * success `stages: nope` used to produce.
     *
     * What is deliberately NOT checked: unknown keys. A `stagez:` typo, or a key
     * a future version adds, is ignored rather than refused, so an older build
     * reading a newer file degrades instead of failing — but note the corollary,
     * that a misspelled key is silently a missing key. This exemption covers
     * only keys nothing here READS; a key the loader acts on gets a shape check
     * (which is why `parallel` is in the list above and not here).
     *
     * @throws WorkflowLoadException When the file is not a valid workflow map.
     */
    private function buildFromYamlFile(string $yamlPath): WorkflowBuilder
    {
        try {
            $data = Yaml::parseFile($yamlPath);
        } catch (ParseException $e) {
            // The parser's message, NOT included on purpose. ParseException
            // quotes the offending line of the file it was reading, and this
            // string is rendered into the transcript and written to the session
            // store. For a project-tier `leak.yaml -> ../secret/id_rsa` that
            // put a line of the linked target's content on screen and on disk —
            // a rejected file leaking more than an accepted one. The parsed
            // LINE NUMBER is kept, because it is what the author of a genuinely
            // malformed file needs and it reveals nothing about the content.
            // (Symlinks out of the project tier are also refused outright now;
            // this is the half that holds for any unreadable-but-present file.)
            $line = $e->getParsedLine();
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} is not valid YAML"
                . ($line > 0 ? " (line {$line})" : '')
                . '. The parser message is withheld because it quotes the file being parsed.'
            );
        }

        if (!is_array($data)) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} must contain a YAML map, got " . get_debug_type($data)
            );
        }

        if (!array_key_exists('name', $data) || !is_string($data['name']) || $data['name'] === '') {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} must have a \"name\" field"
            );
        }

        // The document's own name held to the same rule as the name used to
        // look it up ({@see validateName()}), which it was not before: `name:
        // " w "` loaded, and flowed through
        // {@see WorkflowEngine::generateWorkflowId()} into a pause FILENAME.
        // `getPauseFilePath()` bounds that with its own `..`/`/` guard, so this
        // is asymmetry rather than a hole — but a name the loader accepts and
        // the lookup would reject is a name `/workflow run` can never be used
        // on, so accepting it only defers the error.
        if (!$this->nameIsValid($data['name'])) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} has an unusable \"name\" ('{$data['name']}'). "
                . 'Use only alphanumeric characters, underscores, and hyphens.'
            );
        }

        return $this->parseYamlWorkflow($data, $yamlPath);
    }

    /**
     * Map parsed YAML data to a WorkflowBuilder chain.
     *
     * @param array{name: string, description?: string, stages?: array, config?: array} $data
     * @param string $yamlPath The file $data came out of, for error messages.
     */
    private function parseYamlWorkflow(array $data, string $yamlPath): WorkflowBuilder
    {
        $builder = (new WorkflowBuilder())
            ->name($data['name']);

        // requireString() rather than an `is_string()` that silently skips: a
        // `description: 42` used to be dropped on the floor and the workflow
        // loaded with an empty description, so the author's only signal that
        // their file was wrong was a blank line in the listing.
        $description = $this->requireString($data, 'description', '', "Workflow file {$yamlPath}");
        if ($description !== '') {
            $builder = $builder->description($description);
        }

        // A `stages:` that is present but not a list is REPORTED, not skipped.
        // `stages: nope` used to load as a workflow with zero stages, and
        // `/workflow run` then printed "Workflow 'w' completed … Status:
        // completed" for a run that executed nothing at all — a silent failure
        // reported as a success, which is the worst answer available. An empty
        // list stays legal: `stages: []` is a workflow that deliberately does
        // nothing, and it is what the wiring tests drive.
        if (array_key_exists('stages', $data) && !is_array($data['stages'])) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"stages\" must be a list of stages, got " . get_debug_type($data['stages'])
            );
        }

        if (array_key_exists('stages', $data) && is_array($data['stages'])) {
            // A MAP of stages is refused too, for the reason on
            // buildFromYamlFile(): `stages: {first: {...}}` used to load with
            // the keys thrown away, so the author's `first:` meant nothing and
            // nothing said so.
            if (!array_is_list($data['stages'])) {
                throw new WorkflowLoadException(
                    "Workflow file {$yamlPath} \"stages\" must be a list of stages, got a map"
                );
            }

            $seenStageNames = [];

            foreach ($data['stages'] as $index => $stage) {
                $stageName = $this->requireStageName($stage, $index, $yamlPath);

                // Duplicates refused: `{{build.output}}` names a stage, and
                // {@see WorkflowEngine::runFromWorkflow()} keys stage output as
                // `<name>.output`, so two stages called `build` make every
                // reference to either one mean "whichever ran last". There is no
                // answer the interpolation can give that is not a guess.
                //
                // Reported by the two STAGE INDICES, not by the name, and the
                // reason is usefulness rather than policy. A previous revision
                // of this comment claimed it was "the one load-error path that
                // interpolated a value read out of the file"; that was measured
                // false over the complete domain of all 20
                // `throw new WorkflowLoadException` sites in this file — 9 of
                // them interpolate file content, and still do: the `name` arm in
                // {@see buildFromYamlFile()} quotes the document's own name, the
                // {@see requirePositiveInt()} arm quotes a `config:` value, and
                // the seven `$where`-carrying arms quote the STAGE NAME verbatim
                // (`$where` is built as `Workflow file … stage '<name>'`).
                //
                // ECHOING IT IS FINE, which is why those nine are left alone.
                // The project tier is confined to checkout content
                // ({@see readableProjectDir()}), so a message quoting a value
                // this loader read out of a validated key tells the repository
                // only what the repository already wrote; the user tier is the
                // user's own files. The genuinely different case is the
                // parse-error arm, and the difference is not "content vs no
                // content" but BOUNDEDNESS: it would quote an arbitrary line of
                // a file the parser could not make sense of, chosen by the file
                // rather than by a key this loader asked for.
                //
                // So the index form is kept on its own merits, not as a policy
                // closure: a duplicate name is by definition written twice, and
                // `#0 and #3` says where both are while the name says neither.
                // The indices are 0-based to match `stage #{$index}` in
                // {@see requireStageName()} and `agent #{$agentIndex}` in
                // {@see parseYamlStage()}; nothing in the README, `workflows/`
                // or `examples/workflows/` numbers stages from 1.
                if (isset($seenStageNames[$stageName])) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} has two stages with the same name "
                        . "(stages #{$seenStageNames[$stageName]} and #{$index}); stage names are how "
                        . '{{<name>.output}} refers to one, so they must be unique.'
                    );
                }
                $seenStageNames[$stageName] = $index;

                $isParallel = $this->requireParallelFlag($stage, "Workflow file {$yamlPath} stage '{$stageName}'");
                $parsed = $this->parseYamlStage($stage, $stageName, $yamlPath, $isParallel);

                if ($isParallel) {
                    // $parsed is an array of TaskBuilders for parallel stages
                    // This delegates to WorkflowBuilder::parallel(), which feeds
                    // executeParallelStage() in WorkflowEngine.php.  The full
                    // parallel() primitive chain spans: WorkflowEngine,
                    // WorkflowBuilder, Workflow, WorkflowRegistry, AgentWorkerPool.
                    $builder = $builder->parallel($stageName, $parsed);
                } else {
                    // $parsed is a single TaskBuilder for regular stages
                    $builder = $builder->stage($stageName, $parsed);
                }
            }
        }

        if (array_key_exists('config', $data) && !is_array($data['config'])) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"config\" must be a map, got " . get_debug_type($data['config'])
            );
        }

        if (array_key_exists('config', $data) && is_array($data['config'])) {
            // array_key_exists(), not isset(): `timeout: ~` is a key the author
            // wrote, and isset() reported it absent so it silently took the
            // default the docblock says it must be a whole number instead of.
            if (array_key_exists('maxConcurrent', $data['config'])) {
                $builder = $builder->maxConcurrent($this->requirePositiveInt(
                    $data['config']['maxConcurrent'],
                    'config.maxConcurrent',
                    $yamlPath,
                ));
            }
            if (array_key_exists('timeout', $data['config'])) {
                $builder = $builder->timeout($this->requirePositiveInt(
                    $data['config']['timeout'],
                    'config.timeout',
                    $yamlPath,
                ));
            }
        }

        return $builder;
    }

    /**
     * Is this stage a fan-out? Decided ONCE, from a value that must be a real
     * boolean.
     *
     * Two separate `$stage['parallel'] === true` tests used to answer this —
     * one here in {@see parseYamlWorkflow()} choosing `parallel()` over
     * `stage()`, one in {@see parseYamlStage()} choosing what to build — and a
     * strict comparison against a value nobody had shape-checked. So
     * `parallel: "true"` (the quoted form, which is what a YAML author writes
     * by accident) failed BOTH tests: the whole `agents:` list, including every
     * tool it declared, was never read and never permission-checked, one
     * no-tool agent ran on the stage-level prompt, and `/workflow run` reported
     * the workflow **completed**. That is the same "silent failure reported as a
     * success" the `stages: nope` check above exists to stop, one level down.
     *
     * Refused rather than coerced, unlike {@see requirePositiveInt()}'s
     * acceptance of `timeout: "600"`. A quoted integer has exactly one meaning;
     * a truthy string does not — `"true"`, `"yes"`, `"on"`, `"1"` and YAML 1.1's
     * history of treating some of them as booleans and some not is a family of
     * spellings, and guessing which one an author meant is how the next silent
     * mis-parse gets built. The exception says what to write instead.
     *
     * @param array<array-key, mixed> $stage
     *
     * @throws WorkflowLoadException When `parallel` is present and not a boolean.
     */
    private function requireParallelFlag(array $stage, string $where): bool
    {
        if (!array_key_exists('parallel', $stage)) {
            return false;
        }

        if (!is_bool($stage['parallel'])) {
            throw new WorkflowLoadException(
                "{$where} \"parallel\" must be a boolean (true or false, unquoted), got "
                . get_debug_type($stage['parallel'])
            );
        }

        return $stage['parallel'];
    }

    /**
     * A `config:` value as a count of things, at least 1.
     *
     * The `(int)` casts this replaces were the quietest defect in the loader.
     * `maxConcurrent: [1,2]` cast to 1, `timeout: abc` cast to 0, and
     * `maxConcurrent: 0` was taken literally — which
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::executeAll()} turns into a
     * `while (count($active) < 0)` that never runs, so it yields no results at
     * all, and {@see WorkflowEngine::executeParallelStage()} maps "no failures
     * among zero results" onto Completed. A repo-shipped `config: {maxConcurrent:
     * 0}` plus one parallel stage therefore reported a stage complete having
     * executed nothing. Refusing the value at load time is the only place that
     * can distinguish "ran nothing because you asked for nothing" from "ran
     * nothing and told you it worked".
     *
     * An integer-valued string is accepted (`timeout: "600"` is how a quoted
     * YAML scalar arrives, and it means exactly what the unquoted form means);
     * a float, a list, a map, a boolean and a non-numeric string are not.
     *
     * @throws WorkflowLoadException When the value is not a whole number >= 1.
     */
    private function requirePositiveInt(mixed $value, string $key, string $yamlPath): int
    {
        $int = null;
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);
        }

        if ($int === null) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"{$key}\" must be a whole number, got " . get_debug_type($value)
            );
        }

        if ($int < 1) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"{$key}\" must be at least 1, got {$int}"
            );
        }

        return $int;
    }

    /**
     * The `name` of one `stages:` entry.
     *
     * @param mixed $stage The raw YAML value, which is why this takes mixed:
     *        `stages: [just-a-string]` is legal YAML and used to reach
     *        {@see parseYamlStage()} as a string.
     *
     * @throws WorkflowLoadException When the entry is not a named map.
     */
    private function requireStageName(mixed $stage, int|string $index, string $yamlPath): string
    {
        if (!is_array($stage)) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} stage #{$index} must be a map, got " . get_debug_type($stage)
            );
        }

        if (!array_key_exists('name', $stage) || !is_string($stage['name']) || $stage['name'] === '') {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} stage #{$index} must have a \"name\" field"
            );
        }

        return $stage['name'];
    }

    /**
     * Map a parsed YAML stage to a TaskBuilder or array of TaskBuilders.
     *
     * Returns an array of TaskBuilders for parallel stages, or a single
     * TaskBuilder for regular stages.
     *
     * @param array{name: string, agent?: string, prompt?: string, tools?: array, parallel?: bool, agents?: array} $stage
     * @param bool $isParallel Already decided by {@see requireParallelFlag()}
     *        and PASSED IN rather than re-derived here, so this method and its
     *        caller cannot disagree about what the stage is — which is exactly
     *        what happened while both tested the raw value themselves.
     * @return TaskBuilder|array<int, TaskBuilder>
     */
    private function parseYamlStage(array $stage, string $stageName, string $yamlPath, bool $isParallel): TaskBuilder|array
    {
        $where = "Workflow file {$yamlPath} stage '{$stageName}'";

        if ($isParallel) {
            $agents = array_key_exists('agents', $stage) ? $stage['agents'] : [];
            if (!is_array($agents) || !array_is_list($agents)) {
                throw new WorkflowLoadException(
                    "{$where} is parallel, so its \"agents\" must be a list, got "
                    . (is_array($agents) ? 'a map' : get_debug_type($agents))
                );
            }

            $builders = [];
            foreach ($agents as $agentIndex => $agent) {
                if (!is_array($agent)) {
                    throw new WorkflowLoadException(
                        "{$where} agent #{$agentIndex} must be a map, got " . get_debug_type($agent)
                    );
                }

                $b = Tasks::agent($this->requireString($agent, 'type', 'coder', "{$where} agent #{$agentIndex}"));
                if (array_key_exists('name', $agent)) {
                    $b = $b->name($this->requireString($agent, 'name', '', "{$where} agent #{$agentIndex}"));
                }
                if (array_key_exists('prompt', $agent)) {
                    $b = $b->prompt($this->requireString($agent, 'prompt', '', "{$where} agent #{$agentIndex}"));
                }
                if (array_key_exists('tools', $agent)) {
                    $b = $b->tools($this->requireToolList($agent['tools'], "{$where} agent #{$agentIndex}"));
                }
                $builders[] = $b;
            }
            return $builders;
        }

        $b = Tasks::agent($this->requireString($stage, 'agent', 'coder', $where));

        if (array_key_exists('prompt', $stage)) {
            $b = $b->prompt($this->requireString($stage, 'prompt', '', $where));
        }

        if (array_key_exists('tools', $stage)) {
            $b = $b->tools($this->requireToolList($stage['tools'], $where));
        }

        return $b;
    }

    /**
     * $data[$key] as a string, or $default when the key is ABSENT.
     *
     * Absent, not "absent or null": `array_key_exists()` rather than `isset()`,
     * so `prompt: ~` is refused as the wrong shape instead of silently taking
     * the default. A key the author wrote is a key the author meant.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws WorkflowLoadException When the key is present but not a string.
     */
    private function requireString(array $data, string $key, string $default, string $where): string
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        if (!is_string($data[$key])) {
            throw new WorkflowLoadException(
                "{$where} \"{$key}\" must be a string, got " . get_debug_type($data[$key])
            );
        }

        return $data[$key];
    }

    /**
     * A `tools:` value as a list of tool names.
     *
     * @return list<string>
     *
     * @throws WorkflowLoadException When it is not a list of strings.
     */
    private function requireToolList(mixed $tools, string $where): array
    {
        if (!is_array($tools)) {
            throw new WorkflowLoadException(
                "{$where} \"tools\" must be a list of tool names, got " . get_debug_type($tools)
            );
        }

        // `tools: {a: Read}` used to load as `["Read"]` — the key discarded, no
        // warning. A tool list is a list; a map is a different intention that
        // this loader has no meaning for.
        if (!array_is_list($tools)) {
            throw new WorkflowLoadException(
                "{$where} \"tools\" must be a list of tool names, got a map"
            );
        }

        $names = [];
        foreach ($tools as $tool) {
            if (!is_string($tool)) {
                throw new WorkflowLoadException(
                    "{$where} \"tools\" must be a list of tool names, got " . get_debug_type($tool) . ' in it'
                );
            }

            $names[] = $tool;
        }

        return $names;
    }

    /**
     * Resolve workflow name to the full PHP filesystem path, or null when the
     * user tier's own directory is refused.
     *
     * NULL RATHER THAN A PATH THE CALLER THEN FILTERS, for the reason
     * {@see projectYamlPath()} returns null: {@see load()} is the one place that
     * reaches a `.php` file, and a directory {@see readableUserDir()} dropped
     * from {@see yamlDirectories()} while still being reachable here would be
     * "listed and loadable are the same set" breaking in the direction that ends
     * in `require`.
     */
    private function resolvePhpPath(string $name): ?string
    {
        $this->validateName($name);

        $dir = $this->readableUserDir();

        return $dir === null ? null : $dir . "/{$name}.php";
    }

    /**
     * Validate workflow name to prevent directory traversal.
     *
     * @throws WorkflowNotFoundException When the name contains invalid characters.
     */
    private function validateName(string $name): void
    {
        if (!$this->nameIsValid($name)) {
            throw new WorkflowNotFoundException(
                "Invalid workflow name '{$name}'. Use only alphanumeric characters, underscores, and hyphens."
            );
        }
    }

    /**
     * Whether $name is a name this registry can resolve at all.
     *
     * Split out of {@see validateName()} so {@see baseNames()} can ask the
     * question without the answer being an exception — the listing has to make
     * the SAME judgement the loader makes, and a second copy of the pattern is
     * how the two would drift apart.
     */
    private function nameIsValid(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $name) === 1;
    }

    /**
     * Expand tilde in paths and drop the trailing separator.
     */
    private function expandPath(string $path): string
    {
        // str_starts_with(), NOT preg_replace(): the replacement string is
        // where `$1`, `\1` and `\\` are meaningful to PCRE, and this one is a
        // HOME DIRECTORY — `/home/j$1` or a Windows `C:\1` spelling came back
        // mangled (or empty) rather than expanded. There is nothing to pattern
        // match here in the first place; the test is one leading character.
        $home = str_starts_with($path, '~') ? HomeDirectory::path() . substr($path, 1) : $path;

        $trimmed = rtrim($home, '/');

        // A path that is NOTHING BUT separators keeps one, and the special case
        // is not cosmetic: `rtrim('/', '/')` is the empty string, `realpath('')`
        // is the process CWD, and this method's result is what
        // {@see readableProjectDir()} anchors containment on. So
        // `--root /` — which {@see \SugarCraft\Crush\Cli\ArgvParser} accepts,
        // since `/` is a directory — silently anchored the boundary at
        // `getcwd()` instead of at `/`. It failed SAFE (a narrower anchor
        // refuses more), but the anchor was a path nobody named.
        return $trimmed === '' && $home !== '' ? '/' : $trimmed;
    }
}
