<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\JsonSchemaArgument;
use SugarCraft\Core\Util\Sanitize;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and loads workflow definitions from PHP DSL files.
 *
 * Workflows are defined as PHP files that return a Workflow object:
 *
 *   // ~/.candy-code/workflows/refactor-service.php
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
    /**
     * The keys a task map may carry, listed once (the rider law): the parse
     * arms read these keys, and the unknown-key refusal allows exactly these,
     * so a spelling the parser never reads can never ride into a workflow
     * silently and quietly do less. An arm added here without its refusal
     * update is caught by {@see \SugarCraft\Code\Tests\Workflows\WorkflowYamlKeyRosterTest}.
     */
    public const YAML_TASK_KEYS = ['agent', 'name', 'prompt', 'tools', 'retries', 'outputSchema', 'isolation', 'model', 'resource', 'lockWaitSeconds', 'timeout', 'reject', 'rejectMinOccurrences', 'stallTimeout', 'restarts', 'input', 'votes', 'lenses', 'quorum', 'preset', 'effort'];

    /**
     * The stage-shape vocabulary (names, flags and the nested containers the
     * stage arms consume). Stage-level refusal allows this list PLUS
     * {@see self::YAML_TASK_KEYS}, because a single-task stage legitimately
     * carries its task keys at stage level.
     */
    private const YAML_STAGE_KEYS = ['name', 'parallel', 'capture', 'cache', 'type', 'agents', 'tasks', 'task', 'verifier', 'stages', 'when', 'uses', 'with', 'from', 'loop', 'minSuccess', 'steps', 'announce'];

    /**
     * The keys a parallel stage's `from:` sub-map may carry (C8 + P0-3
     * `max`), listed once for the same rider law as the rosters above.
     */
    public const YAML_FROM_KEYS = ['glob', 'stage', 'context', 'pointer', 'key', 'chunk', 'max'];

    /**
     * C6 (wf-impl): the top-level document keys this loader reads, listed once
     * for the rider law. A key outside it is refused at load naming it and
     * this set — `maxToken:` beside `config:` used to load as nothing at all.
     */
    public const YAML_WORKFLOW_KEYS = ['name', 'description', 'config', 'schemas', 'stages'];

    /**
     * C6 (wf-impl): the `config:` keys this loader reads. A typo here was the
     * worst kind of silent: `config: {maxToken: 50000}` loaded as a run with
     * NO token ceiling at all. Refused at load like any unknown key.
     */
    public const YAML_CONFIG_KEYS = ['timeout', 'maxConcurrent', 'maxAgents', 'maxTokens', 'maxCost', 'stopOnFirstFailure'];

    /** @var array<string, Workflow> */
    private array $registered = [];

    /**
     * C9: fan-in join specs recorded while parsing the current workflow file,
     * each with the joining stage's position and the source names it lists.
     * The order law ("input joins PRIOR stages only") cannot be judged at the
     * arm site — later stages have not been seen yet — so every `input:`
     * declaration defers its name check to the end of the stages loop, where
     * {@see self::parseYamlWorkflow()}'s $seenStageNames map is complete.
     * Reset (and drained) by every file parse.
     *
     * @var list<array{at:string,index:int,from:list<string>}>
     */
    private array $pendingJoins = [];

    /**
     * P2-4: the current file's top-level `schemas:` map, name => schema,
     * read BEFORE its stages so every `outputSchema: {$ref: <name>}` on any
     * task surface resolves at load. Reset by every file parse.
     *
     * @var array<string, array<mixed>>
     */
    private array $namedSchemas = [];

    /**
     * C9: the stage whose body is parsing right now, or null between files.
     * applyTaskArms() has no stage context of its own — it is shared by every
     * task surface (stage bodies, `tasks:` lists, agents, sub-maps) — so the
     * declaring stage's index rides here instead of through six signatures.
     *
     * @var array{name:string,index:int}|null
     */
    private ?array $joinStage = null;

    /**
     * Why the project tier's directory was refused the last time
     * {@see readableProjectDir()} judged it, or null when it was not refused.
     *
     * The diagnostic half of the refusal, kept rather than thrown away, for the
     * reason {@see \SugarCraft\Code\Skills\SkillLoader::recordSkip()} keeps
     * its skips: the refusal is silent everywhere else — the not-found message
     * stops naming the directory and {@see projectWorkflowsPath()} still
     * reports it — so a user whose repository ships a workflows directory this
     * loader will not read had nothing at all telling them so. Read through
     * {@see projectTierRefusal()}, surfaced at launch by
     * {@see \SugarCraft\Code\Cli\Bootstrap::reportProjectTierRefusals()}.
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
     * {@see \SugarCraft\Code\Cli\Bootstrap::workflowEngine()} into the same
     * launch-refusal collector, which prints `ignoring <path> — <reason>` before
     * Program takes the terminal.
     */
    private ?string $userDirRefusal = null;

    /**
     * One compiled `.php` workflow per resolved path, for the whole process.
     *
     * The map MUST outlive any one registry: {@see \SugarCraft\Code\Tools\BuiltIn\WorkflowTool}
     * builds a fresh registry per call, so an instance field would re-`require`
     * the file on every `/workflow run` of the same name — and `require` (not
     * `require_once`) RE-EXECUTES top-level code, so a workflow that declares a
     * function or class at file scope died with a `Cannot redeclare` COMPILE
     * fatal the second time it ran (ISS-045). {@see Workflow} is `final
     * readonly`, so handing the same instance to several engines shares an
     * immutable value, not mutable state.
     *
     * The entry is written whatever the file RETURNED, and that is deliberate:
     * a workflow file that hands back a non-Workflow value is cached too, so
     * the second attempt repeats the {@see WorkflowLoadException} instead of
     * re-executing — and possibly fataling on redeclaration — the broken file.
     * Each entry rides the bytes it was compiled from (C16): when the file on
     * disk has changed, an edit that DECLARES nothing at file scope is
     * re-required and this answer replaced — the edit lands in-process —
     * while an edit beside file-scope declarations is refused loudly, naming
     * the restart that settles it, because re-requiring it is exactly the
     * redeclare fatal this memo exists to close. A file that does not PARSE
     * caches nothing (the {@see \ParseError} escapes before the write), so a
     * typo heals on retry.
     *
     * @var array<string, mixed>
     */
    private static array $phpWorkflows = [];

    /**
     * The failure-side companion to {@see self::$phpWorkflows} (ISS-045).
     *
     * A file that compiles and then THROWS at top level has still INSTALLED
     * its file-scope declarations — PHP compiles the whole file before
     * executing any of it — so re-requiring it on the next attempt raises the
     * uncatchable `Cannot redeclare` compile fatal, the very door the result
     * memo exists to close. Such a path is therefore poisoned: the original
     * Throwable is remembered and every later load re-throws IT without
     * touching the file again — until the bytes change (C16): an edit to a
     * file that declares NOTHING at file scope re-requires and drops the
     * poison along with the stale bytes, while an edit beside file-scope
     * declarations answers a loud restart-naming {@see WorkflowLoadException}
     * instead — exactly like the non-Workflow-return case above.
     *
     * A {@see \ParseError} is exempt by design: a file that failed to compile
     * declared nothing, so there is nothing to redeclare and a typo must heal
     * on retry. The stated boundary: a ParseError thrown from an INCLUDE the
     * file reaches at runtime has already let the outer file compile — this
     * map chooses heal-over-poison for that shape (no fixture exercises it;
     * the include itself keeps its own compile accounting).
     *
     * @var array<string, \Throwable>
     */
    private static array $phpWorkflowFailures = [];

    /**
     * The bytes each {@see self::$phpWorkflows}/{@see self::$phpWorkflowFailures}
     * entry was compiled from (C16), keyed like both maps. A null digest means
     * md5_file could not read the file when the entry was stored: the memo
     * then keeps its old path-only law, because re-requiring bytes we cannot
     * even hash is the one risk this compile memo exists to control.
     *
     * @var array<string, string|null>
     */
    private static array $phpWorkflowHashes = [];

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
     *        {@see \SugarCraft\Code\Agents\SubAgent}s that carry the launch's
     *        {@see \SugarCraft\Code\Permissions\PermissionGate}, which refuses
     *        any tool the definition DECLARES and this session's mode denies
     *        before the stage is dispatched at all
     *        ({@see WorkflowEngine::refuseDeniedTools()}). What that gate does
     *        not yet do is decide an individual tool call mid-run, and the
     *        reason is not a missing gate: the pool's executor is still the
     *        simulation described on {@see \SugarCraft\Code\Agents\ProcessExecutor},
     *        so a workflow stage issues no provider request and no tool call for
     *        anything to evaluate. Read this parameter's safety story as
     *        "declarative, and its declared capability is gated", NOT as
     *        "identical enforcement to a typed delegation" — a typed delegation
     *        through {@see \SugarCraft\Code\Agents\AgentManager::executeSubAgent()}
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
     *        halves are decided by {@see \SugarCraft\Code\Support\ContainedPath},
     *        which is also what a project's `.claude/skills` now asks
     *        ({@see \SugarCraft\Code\Skills\SkillLoader::skillFilesIn()}) — the
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
     *        {@see \SugarCraft\Code\Support\HomeDirectory::owned()} answers YES
     *        for every such row, because the user extracted the tarball into
     *        their own home. Ownership answers "whose directory is this"; it
     *        cannot answer "who chose where this link points", and only
     *        containment does — the same correction
     *        {@see \SugarCraft\Code\Cli\Bootstrap::agentPresetTiers()} made for
     *        `~/.candy-code/agents`, which is a directory of DATA. This one is
     *        the directory of CODE.
     *
     *        MEASURED on this host, `$HOME` mode 0700 and owned by the
     *        launching uid, no `.git` anywhere in the tree, against the build
     *        immediately before this change:
     *
     *            ~/.candy-code/workflows -> <outside>    load('pwned') EXECUTED uid=1000
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
     *        `~/.candy-code/workflows/deploy.php -> /opt/team-workflows/deploy.php`
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
     *        {@see \SugarCraft\Code\Cli\Bootstrap}'s configured path — which is
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
     *        repository can commit `.candy-code/workflows -> <some other
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
     *        whose files are EXECUTED. {@see \SugarCraft\Code\Cli\Bootstrap::workflowEngine()}
     *        passes the parent of {@see \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath()},
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
     *        `~/.candy-code -> /elsewhere` leaves `<...>/workflows` resolving
     *        inside its own parent). A caller that knows the home gets the
     *        complete boundary; one that does not gets the partial one written
     *        down here rather than a guarantee only somebody else holds.
     *
     *        IT IS NOT AN OWNERSHIP CHECK EITHER, and this class does not make
     *        one. {@see expandPath()} resolves a leading `~` through
     *        {@see \SugarCraft\Code\Support\HomeDirectory::path()}, whose
     *        fallback is `sys_get_temp_dir()` — so a DEFAULT-constructed
     *        registry in a process with no resolvable `HOME` anchors
     *        `/tmp/.candy-code/workflows` to `/tmp/.candy-code`, which
     *        contains it, and a local user who pre-created that tree owns the
     *        `require`. Nothing in `src/` constructs one that way — the
     *        production caller passes an absolute path derived from
     *        {@see \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath()},
     *        which refuses that launch on {@see \SugarCraft\Code\Cli\Bootstrap::chat()}'s
     *        first line — and the residual is recorded here rather than
     *        half-migrated, because two home resolutions inside one class is the
     *        failure {@see \SugarCraft\Code\Support\HomeDirectory} exists to
     *        have ended.
     */
    public function __construct(
        private readonly string $workflowsPath = '~/.candy-code/workflows/',
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
     * anchor. So a `~/.candy-code/workflows -> <outside>` link that this registry
     * refuses to list or `require` out of still relocates
     * `<outside>/.running/*.json`, which the engine writes and reads back. What that
     * yields an attacker is the engine's own JSON in a directory they already chose
     * — not code, not a foreign file's contents — which is why it is recorded here
     * and in {@see \SugarCraft\Code\Tests\Support\ReadPathCensusTest} instead of
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
     * user-tier `.php` workflow is reached by `require`, whose own parse
     * failure arrives as a catchable {@see \ParseError}, but whose second
     * EXECUTION of a file that declares anything at top level is the
     * uncatchable `Cannot redeclare` compile fatal no `catch (\Throwable)`
     * around `/workflow run` survives. That second run is exactly what
     * {@see self::$phpWorkflows} and its failure-side companion
     * {@see self::$phpWorkflowFailures} together prevent: each set of file
     * bytes compiles at most once per process — whether it returned, returned
     * garbage, or threw (ISS-045) — while an edit to bytes that declare nothing
     * at file scope re-requires in-process (C16). The YAML path, by contrast, reports every
     * malformed shape as a {@see WorkflowLoadException} the command turns
     * into one transcript line.
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
        // same precedence {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()}
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
        // `~/.candy-code/workflows/deploy.php` the power to shadow the curated
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
            // Keyed by the resolved path, not the name or the spelled path: two
            // names, or a symlink spelling beside the direct one, are the SAME
            // file to PHP's compiler, and the redeclaration this cache prevents
            // (ISS-045) is a property of the file, not of the route taken to it.
            $cacheKey = \realpath($phpPath) ?: $phpPath;
            // The bytes this decision is about; null keeps the old path-only
            // law (serve whatever is memoized), per $phpWorkflowHashes.
            $digest = self::contentDigest($phpPath);
            // A file that compiled and then THREW has still installed its
            // top-level declarations, so it must never be re-required on the
            // SAME bytes: the remembered Throwable answers instead, making the
            // two throw-shapes (cached failure, cached garbage result) one
            // door. An EDITED poison is the different question (C16) below.
            $poisoned = isset(self::$phpWorkflowFailures[$cacheKey]);
            // array_key_exists, NOT `??`: a file returning null is a cached
            // ANSWER (it fails the instanceof check below), and a null-coalesce
            // would read it as a miss and re-execute the very file the cache
            // exists to run once.
            $memoized = \array_key_exists($cacheKey, self::$phpWorkflows);

            // C16: a remembered answer goes stale when the bytes change. An
            // edited file that DECLARES nothing at file scope is safe to
            // re-require — nothing exists to redeclare — so the stale entry
            // (or poison) is dropped and the fresh require below replaces it;
            // the edit lands in-process instead of waiting for a restart. An
            // edited file that DOES declare cannot be re-required (uncatchable
            // redeclare fatal) and serving the known-stale answer would
            // silently run bytes the user already replaced; both are wrong, so
            // this refuses loudly and names the restart that settles it.
            if (($poisoned || $memoized) && $digest !== null) {
                $stored = self::$phpWorkflowHashes[$cacheKey] ?? null;
                if ($stored !== null && $stored !== $digest) {
                    if (self::sourceDeclaresFileScopeSymbols($phpPath)) {
                        throw new WorkflowLoadException(
                            "Workflow file {$phpPath} was edited after it was loaded in this process, and its "
                            . 'new bytes declare file-scope symbols; re-requiring them could raise the '
                            . 'uncatchable redeclare fatal, and the cached answer is known-stale — the edit '
                            . 'needs a restart to take effect.'
                        );
                    }
                    unset(
                        self::$phpWorkflows[$cacheKey],
                        self::$phpWorkflowFailures[$cacheKey],
                        self::$phpWorkflowHashes[$cacheKey]
                    );
                    $poisoned = false;
                    $memoized = false;
                }
            }

            if ($poisoned) {
                throw self::$phpWorkflowFailures[$cacheKey];
            }

            try {
                $workflow = $memoized
                    ? self::$phpWorkflows[$cacheKey]
                    : require $phpPath;
            } catch (\ParseError $error) {
                // A failed compile declared NOTHING, so there is nothing to
                // redeclare on the next attempt: no poison, and a typo heals
                // on retry (ISS-045 boundary, see $phpWorkflowFailures).
                throw $error;
            } catch (\Throwable $error) {
                // Compiled, executed, exploded: poison the path with the
                // ORIGINAL Throwable so attempt two re-reports this exact
                // refusal without touching the same bytes again — and record
                // the bytes that installed it, so a FIX can re-require (C16).
                self::$phpWorkflowFailures[$cacheKey] = $error;
                self::$phpWorkflowHashes[$cacheKey] = $digest;
                throw $error;
            }
            self::$phpWorkflows[$cacheKey] = $workflow;
            self::$phpWorkflowHashes[$cacheKey] = $digest;

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
     * `~/.candy-code/workflows` — and a repeated entry would put the same
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
     * {@see \SugarCraft\Code\Cli\Bootstrap::workflowEngine()} builds is
     * `<root>/.candy-code/workflows`, and committing that as a symlink was
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
     * `.candy-code/workflows -> tools/workflows` layout for no gain.
     *
     * STRICTLY, and the word replaced a measured leak. {@see containedIn()}
     * counts "resolves onto the boundary" as contained, which is right for an
     * entry and wrong for this: a repository committing the single line
     * `.candy-code/workflows -> ..` resolved its workflows directory exactly
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
     * repository that commits `.candy-code/workflows -> vendor` can still
     * disclose `vendor/*.yaml` basenames. That residual is bounded by the
     * attacker having to name a directory whose contents they cannot see, and
     * closing it entirely would mean refusing the `-> tools/workflows` layout
     * this method exists not to refuse. It is a reduction, not an elimination.
     *
     * With no $projectRoot the anchor falls back to the directory's own parent.
     * Be precise about what that does and does not catch: it refuses a link AT
     * the workflows directory, and it does NOT refuse one at a component above
     * it (a committed `.candy-code -> /elsewhere` leaves `<...>/workflows`
     * resolving inside its own parent). That is the whole of what a registry
     * holding only the leaf path can check, which is why
     * {@see \SugarCraft\Code\Cli\Bootstrap} passes the root — the production
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
     * `.candy-code/workflows` — keeping it is what lets {@see loadYaml()}'s
     * not-found message still name the directory the user was expecting. The
     * half worth refusing is a DANGLING SYMLINK: a link naming a path that does
     * not exist yet is a request to read whatever appears there later, and a link
     * that names nothing has no workflows to lose. `is_link()` tells those two
     * apart with no extra trust.
     *
     * `is_link()` DOES NOT COVER EVERY NON-RESOLVING LAYOUT A REPOSITORY CAN
     * COMMIT, and an earlier revision of this paragraph claimed it did. Measured
     * this session with the link one component higher — `.candy-code -> <a
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
     * `.candy-code -> /tmp/pwn` it costs write access to `/tmp`, which any local
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
        // (see {@see \SugarCraft\Code\Cli\ArgvParser}), and "outside the checkout
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
     * `~/.candy-code` would make every `.php` file in the config directory a
     * loadable workflow, and `~/.candy-code` is where `config.json`,
     * `hooks.yaml` and the session store live rather than a curated directory of
     * workflow files.
     *
     * A DIRECTORY THAT DOES NOT RESOLVE splits the same two ways it does for the
     * project tier, and for the same measured reason — a fresh install simply has
     * no `~/.candy-code/workflows` and must keep {@see loadYaml()}'s not-found
     * message naming it, while a DANGLING link is a request to read whatever
     * appears at that path later. `is_link()` tells those apart, and carries the
     * same bound as it does there: a link one component HIGHER
     * (`~/.candy-code -> <an existing directory holding no workflows/>`)
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
     * {@see \SugarCraft\Code\Cli\Bootstrap::projectTierRefusals()} — and the one
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
     *   - {@see \SugarCraft\Code\Skills\SkillLoader::recordRefusedDirectory()}
     *     and {@see \SugarCraft\Code\Agents\AgentPresetRegistry::refusedDirectories()}
     *     name the resolved target AND interpolate the anchor PATH, without the
     *     words.
     *
     * So all of them omit the configured path — which is the property the
     * collector needs, since the collector supplies it as the map key — and all
     * of them now name the anchor. The previous statement, "only this one also
     * omits the anchor path", was accurate and is what got fixed: a refusal that
     * cannot say which root it measured against is unfalsifiable for the person
     * reading it. A further holder of a repository-chosen directory,
     * {@see \SugarCraft\Code\Commands\CommandLoader}, does not feed the collector
     * at all — it `error_log()`s, and its message carries `$dir`, `$realDir` and
     * `$anchoredIn` together.
     *
     * Public and pull-based rather than a stderr write, for the reason
     * {@see \SugarCraft\Code\Skills\SkillLoader::recordSkip()} is: a write from
     * here can land mid-frame under the alt screen.
     * {@see \SugarCraft\Code\Cli\Bootstrap::reportProjectTierRefusals()} asks
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
     * `tools` is a list of strings; `retries` (on a stage or a parallel agent)
     * is a whole number >= 0; a parallel stage's `agents` is a list of maps;
     * `config` is a map whose `maxConcurrent` is a whole number >= 0 (0 = no
     * cap) and whose `timeout` and `maxAgents` are whole numbers >= 1.
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

        return $this->buildFromYamlData($data, $yamlPath);
    }

    /**
     * A workflow from a YAML plan held in a STRING — the plan a model hands
     * the `Workflow` tool (roadmap 4.10-2) — held to every check a `.yaml`
     * file gets ({@see buildFromYamlFile()}): the same loader, so a plan the
     * model writes and a file a user writes cannot mean two different things.
     *
     * Two differences, both because the text is the caller's own rather than
     * a file on disk. The parser's message IS included on a syntax error —
     * the reason {@see buildFromYamlFile()} withholds it is that it quotes the
     * file being read, and here it can only quote what the caller wrote. And
     * `name` may be omitted (it defaults to $defaultName): a plan is run, not
     * stored, so its name only labels the run.
     *
     * Not registered and not written anywhere: nothing here makes the plan
     * loadable by name later.
     *
     * @param string $source How error messages name the text — "Workflow
     *        file <source> …", the same shape a file's errors take.
     * @throws WorkflowLoadException When the text is not a valid workflow.
     */
    public function fromYamlString(string $yaml, string $source = '(plan)', string $defaultName = 'plan'): Workflow
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new WorkflowLoadException("Workflow file {$source} is not valid YAML: " . $e->getMessage());
        }

        if (is_array($data) && !array_key_exists('name', $data)) {
            $data['name'] = $defaultName;
        }

        return $this->buildFromYamlData($data, $source)->build();
    }

    /**
     * Validate one parsed YAML document and map it onto a builder chain — the
     * shared half of {@see buildFromYamlFile()} and {@see fromYamlString()}.
     *
     * @throws WorkflowLoadException When $data is not a valid workflow map.
     */
    private function buildFromYamlData(mixed $data, string $yamlPath): WorkflowBuilder
    {
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
                "Workflow file {$yamlPath} has an unusable \"name\" ('" . Sanitize::untrusted($data['name']) . "'). "
                . 'Use only alphanumeric characters, underscores, and hyphens.'
            );
        }

        // C6: the document vocabulary itself fails closed, the first
        // offender named with the supported set (the fix is in the refusal).
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::YAML_WORKFLOW_KEYS, true)) {
                throw new WorkflowLoadException(
                    "Workflow file {$yamlPath} has unknown top-level key \"{$key}\"; supported keys: " . implode(', ', self::YAML_WORKFLOW_KEYS)
                );
            }
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

        // P2-4: named schemas first — the stage arms below resolve their
        // `outputSchema: {$ref: …}` against this map as they parse.
        $this->namedSchemas = $this->parseNamedSchemas($data, $yamlPath);

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
            $this->pendingJoins = [];
            $this->joinStage = null;

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
                // `#1 and #4` says where both are while the name says neither.
                // The numbers are 1-based ({@see self::ordinal()}), like
                // `stage #N` in {@see requireStageName()}, `agent #N` in
                // {@see parseYamlStage()} and the tool report's `Stage %d`:
                // an author counts the stages on the page from one, and a
                // 0-based diagnostic pointed at the wrong entry (P2-3).
                if (isset($seenStageNames[$stageName])) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} has two stages with the same name "
                        . '(stages #' . self::ordinal($seenStageNames[$stageName]) . ' and #' . self::ordinal($index) . '); stage names are how '
                        . '{{<name>.output}} refers to one, so they must be unique.'
                    );
                }
                $seenStageNames[$stageName] = $index;

                $where = "Workflow file {$yamlPath} stage '{$stageName}'";
                // C9: everything parsed below belongs to this stage, so any
                // `input:` arm reached through applyTaskArms() joins from
                // stages before this index.
                $this->joinStage = ['name' => $stageName, 'index' => $index];
                // The stage map itself answers to the roster (rider): shape
                // keys and tail task keys, one merged allowed list. Nested
                // sub-maps are checked where they are read (readTaskMap).
                $this->requireNoUnknownTaskKeys(
                    $stage,
                    $where,
                    array_merge(self::YAML_STAGE_KEYS, self::YAML_TASK_KEYS)
                );
                $flaggedParallel = $this->requireParallelFlag($stage, $where);
                // C1: the stage's shape is decided once, here, from either
                // spelling — the `parallel: true` flag or the `type:` key —
                // and never twice down the line.
                $stageType = $this->requireStageType($stage, $where);
                if ($flaggedParallel && $stageType === 'parallel') {
                    throw new WorkflowLoadException(
                        "{$where} carries both \"parallel: true\" and \"type: parallel\"; one spelling is enough"
                    );
                }
                if ($flaggedParallel && ($stageType === 'verification' || $stageType === 'pipeline' || $stageType === 'map')) {
                    throw new WorkflowLoadException(
                        "{$where} declares \"parallel: true\", which contradicts \"type: {$stageType}\""
                    );
                }
                $isParallel = $flaggedParallel || $stageType === 'parallel';
                // C8: the dynamic-wave fan-out is a parallel-stage affordance.
                // Refused here — before any type arm dispatches — so a
                // `from:` beside tasks:, a single task, a verification pair or
                // a pipeline list is named, never silently dropped (rider law).
                if (!$isParallel && $stageType !== 'map' && array_key_exists('from', $stage)) {
                    throw new WorkflowLoadException(
                        "{$where} carries \"from\", which only a parallel or map stage can fan out"
                    );
                }
                // P1-2: `steps:` is the map body; anywhere else it names
                // nothing the stage would run.
                if ($stageType !== 'map' && array_key_exists('steps', $stage)) {
                    throw new WorkflowLoadException(
                        "{$where} carries \"steps\", which only a \"type: map\" stage runs"
                    );
                }
                // P1-3: partial-failure tolerance is a fan-out affordance —
                // v1 reads it on parallel stages only (and never beside
                // `uses:`, whose body is another workflow's stages).
                if (array_key_exists('minSuccess', $stage) && ((!$isParallel && $stageType !== 'map') || array_key_exists('uses', $stage))) {
                    throw new WorkflowLoadException(
                        "{$where} carries \"minSuccess\", which only a parallel or map stage can tolerate failures with"
                    );
                }
                // S3: the capture flag is stage-scoped like `parallel:` — a
                // key the author wrote beside `tasks:` is legitimate, not a
                // task key misplaced.
                $capture = $this->requireCaptureFlag($stage, $where);
                // C13's door rides the same stage-scoped law: strict bool,
                // absent means off.
                $cache = $this->requireCacheFlag($stage, $where);
                // C2: the `when:` clause is read and grammar-checked here, at
                // load — the engine only ever evaluates a parsed shape.
                $when = $this->requireStageWhen($stage, $where);
                // C4: the `loop:` map is read and grammar-checked on the same
                // leg — shape before the builder sees it, normalization once
                // at attach.
                $loop = $this->requireStageLoop($stage, $where);

                if (array_key_exists('with', $stage) && !array_key_exists('uses', $stage)) {
                    // A knob that arms nothing is a silent lie on the page;
                    // `with:` only means something beside `uses:` (C11).
                    throw new WorkflowLoadException(
                        "{$where} carries \"with\", which only arms a \"uses\" stage; add \"uses\" or drop it"
                    );
                }

                if (array_key_exists('uses', $stage) && $loop !== null) {
                    // C4 rider (NIT-1): a carried workflow keeps the plain-stage
                    // shape, so the stage-only door below would wave it through —
                    // but per-round re-carrying has semantics neither the include
                    // lane nor the loop lane specified (expansion, cache rows and
                    // fan-out capture all interact). v1 refuses the pairing at
                    // load, the same closed-pair idiom as with-without-uses,
                    // rather than freeze an accidental one.
                    throw new WorkflowLoadException(
                        "{$where} carries \"loop\" beside \"uses\"; v1 loops plain stages, not carried workflows"
                    );
                }

                // C9: same stand-aside for the join arm. `input:` reads the
                // recorded rows of PRIOR stages in this file; a carried
                // workflow's stages are not visible to this parser, so v1
                // refuses the pairing loudly rather than let the join
                // silently join nothing.
                if (array_key_exists('uses', $stage) && array_key_exists('input', $stage)) {
                    throw new WorkflowLoadException(
                        "{$where} carries \"input\" beside \"uses\"; v1 joins prior stages of the same file, not carried workflows"
                    );
                }

                if (array_key_exists('uses', $stage)) {
                    // C11: `uses:` arms the stage with another workflow's body;
                    // the shape stays 'stage' and no task container may ride.
                    $builder = $builder->uses(
                        $stageName,
                        $this->requireUsesArm($stage, $where, $stageType, $flaggedParallel),
                        $this->parseUsesWithSubMap($stage, $where),
                        capture: $capture,
                        cache: $cache,
                    );
                } elseif ($stageType === 'verification') {
                    $builder = $builder->withVerification(
                        $stageName,
                        $this->parseVerificationSubMap($stage, 'task', $where),
                        $this->parseVerificationSubMap($stage, 'verifier', $where),
                        capture: $capture,
                        cache: $cache,
                    );
                } elseif ($stageType === 'pipeline') {
                    $builder = $builder->pipeline($stageName, $this->parsePipelineSteps($stage, $where), capture: $capture, cache: $cache);
                } elseif ($stageType === 'map') {
                    $builder = $this->parseMapStage($builder, $stage, $stageName, $where, array_keys(array_filter(
                        $seenStageNames,
                        static fn (int $at): bool => $at < $index,
                    )), $capture, $cache);
                } elseif ($isParallel) {
                    // $parsed is an array of TaskBuilders for parallel stages
                    // This delegates to WorkflowBuilder::parallel(), which feeds
                    // executeParallelStage() in WorkflowEngine.php.  The full
                    // parallel() primitive chain spans: WorkflowEngine,
                    // WorkflowBuilder, Workflow, WorkflowRegistry, AgentWorkerPool.
                    $parsed = $this->parseYamlStage($stage, $stageName, $yamlPath, $isParallel);
                    // C8: the `from:` sub-map is validated only when the
                    // author wrote it; absent means the static list rides on.
                    $fromSpec = array_key_exists('from', $stage)
                        ? $this->parseDynamicWaveSubMap($stage, $where, array_keys(array_filter(
                            $seenStageNames,
                            static fn (int $at): bool => $at < $index,
                        )))
                        : [];
                    $builder = $builder->parallel($stageName, $parsed, capture: $capture, cache: $cache, from: $fromSpec);
                } else {
                    // $parsed is a single TaskBuilder for a regular stage, or
                    // a list of them for a `tasks:` stage run in sequence.
                    $parsed = $this->parseYamlStage($stage, $stageName, $yamlPath, $isParallel);
                    $builder = $builder->stage($stageName, $parsed, capture: $capture, cache: $cache);
                }

                // P1-3: the tolerance attaches to the parallel stage this
                // iteration just added; the builder's parser is the ONE
                // spelling law, its refusal re-raised naming the stage.
                if (array_key_exists('minSuccess', $stage)) {
                    $threshold = $stage['minSuccess'];
                    if (!is_int($threshold) && !is_string($threshold)) {
                        throw new WorkflowLoadException(
                            "{$where} minSuccess must be a whole number of agents or a \"NN%\" percentage, got " . get_debug_type($threshold)
                        );
                    }
                    try {
                        $builder = $builder->minSuccess($threshold);
                    } catch (\InvalidArgumentException $e) {
                        throw new WorkflowLoadException("{$where} {$e->getMessage()}", 0, $e);
                    }
                }

                // P2-2: the stage's settle announcement — a template string,
                // interpolated by the engine when the stage settles.
                if (array_key_exists('announce', $stage)) {
                    if (!is_string($stage['announce']) || trim($stage['announce']) === '') {
                        throw new WorkflowLoadException(
                            "{$where} \"announce\" must be a non-empty template string, got "
                            . (is_string($stage['announce']) ? 'an empty string' : get_debug_type($stage['announce']))
                        );
                    }
                    $builder = $builder->announce($stage['announce']);
                }

                // C2: the clause lands on the stage THIS iteration just added,
                // after whichever arm shaped it — same order law as capture.
                if ($when !== null) {
                    $builder = $builder->when($when);
                }

                // C4: the loop rides the same attach-after-shape law. The v1
                // scope door refuses it off a plain stage here, before the
                // builder ever sees it, so a YAML plan can never carry a
                // round policy its runner would not honour.
                if ($loop !== null) {
                    $shape = $isParallel ? 'parallel' : $stageType;
                    if (!in_array($shape, WorkflowBuilder::LOOPABLE_TYPES, true)) {
                        throw new WorkflowLoadException(
                            "{$where} \"loop\" cannot attach to a \"{$shape}\" stage; stage, parallel, verification and map stages loop"
                        );
                    }

                    try {
                        $builder = $builder->loop($loop);
                    } catch (\InvalidArgumentException $e) {
                        throw new WorkflowLoadException("{$where} {$e->getMessage()}", 0, $e);
                    }
                }
            }

            // C9: the order law, judged once every stage name in this file is
            // known. A forward reference, the joining stage itself, or a name
            // this file never declares all refuse HERE, at load — the author
            // sees the mistake at parse, never a join that silently misses at
            // run time.
            foreach ($this->pendingJoins as $join) {
                foreach ($join['from'] as $name) {
                    if (!isset($seenStageNames[$name]) || $seenStageNames[$name] >= $join['index']) {
                        throw new WorkflowLoadException(
                            "{$join['at']} \"input\" \"from\": \"{$name}\" is not a stage declared before this one;"
                            . ' input joins prior stages only'
                        );
                    }
                }
            }

            $this->pendingJoins = [];
            $this->joinStage = null;
        }

        if (array_key_exists('config', $data) && !is_array($data['config'])) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"config\" must be a map, got " . get_debug_type($data['config'])
            );
        }

        if (array_key_exists('config', $data) && is_array($data['config'])) {
            foreach (array_keys($data['config']) as $key) {
                if (!in_array($key, self::YAML_CONFIG_KEYS, true)) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} \"config\" has unknown key \"{$key}\"; supported keys: " . implode(', ', self::YAML_CONFIG_KEYS)
                    );
                }
            }
            // C6: the PHP builder's fail-fast switch, spelled in YAML. Strict
            // boolean like `capture:` — `'yes'` or `1` is refused, never read
            // as truthy. Absent keeps the default false byte-identical.
            if (array_key_exists('stopOnFirstFailure', $data['config'])) {
                $stop = $data['config']['stopOnFirstFailure'];
                if (!is_bool($stop)) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} \"config.stopOnFirstFailure\" must be a boolean (true or false, unquoted), got "
                        . get_debug_type($stop)
                    );
                }
                $builder = $builder->stopOnFirstFailure($stop);
            }
            // array_key_exists(), not isset(): `timeout: ~` is a key the author
            // wrote, and isset() reported it absent so it silently took the
            // default the docblock says it must be a whole number instead of.
            if (array_key_exists('maxConcurrent', $data['config'])) {
                $builder = $builder->maxConcurrent($this->requireWidthOrUnlimited(
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
            // P0-3: the run-wide dispatch backstop — a whole number >= 1;
            // absent keeps Workflow::DEFAULT_MAX_AGENTS.
            if (array_key_exists('maxAgents', $data['config'])) {
                $builder = $builder->maxAgents($this->requirePositiveInt(
                    $data['config']['maxAgents'],
                    'config.maxAgents',
                    $yamlPath,
                ));
            }
            // P1-7: the hard run-wide budgets — a whole number of tokens >= 1,
            // and a positive number of USD. Absent = no ceiling.
            if (array_key_exists('maxTokens', $data['config'])) {
                $builder = $builder->maxTokens($this->requirePositiveInt(
                    $data['config']['maxTokens'],
                    'config.maxTokens',
                    $yamlPath,
                ));
            }
            if (array_key_exists('maxCost', $data['config'])) {
                $cost = $data['config']['maxCost'];
                if (is_string($cost) && is_numeric(trim($cost))) {
                    $cost = (float) trim($cost);
                }
                if (!is_int($cost) && !is_float($cost)) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} \"config.maxCost\" must be a positive number of USD, got " . get_debug_type($cost)
                    );
                }
                if (!is_finite((float) $cost) || $cost <= 0) {
                    throw new WorkflowLoadException(
                        "Workflow file {$yamlPath} \"config.maxCost\" must be a positive number of USD, got " . var_export($cost, true)
                    );
                }
                $builder = $builder->maxCost($cost);
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
     * A stage's `capture:` flag (S3) — strict boolean, the same spelling law
     * as `parallel:`: `capture: true` or `capture: false` unquoted, anything
     * else is the author's intent mangled, so it is refused, not coerced.
     */
    private function requireCaptureFlag(array $stage, string $where): bool
    {
        if (!array_key_exists('capture', $stage)) {
            return false;
        }

        if (!is_bool($stage['capture'])) {
            throw new WorkflowLoadException(
                "{$where} \"capture\" must be a boolean (true or false, unquoted), got "
                . get_debug_type($stage['capture'])
            );
        }

        return $stage['capture'];
    }

    /**
     * A stage's `cache:` flag (C13) — strict boolean, the same spelling law
     * as `capture:`/`parallel:`. It is the opt-in door for resume reuse:
     * absent (the default) means the stage re-runs on resume like always,
     * and only an explicit `cache: true` lets a recorded stage settle from
     * the pause file. A mangled flag must never quietly widen reuse.
     */
    private function requireCacheFlag(array $stage, string $where): bool
    {
        if (!array_key_exists('cache', $stage)) {
            return false;
        }

        if (!is_bool($stage['cache'])) {
            throw new WorkflowLoadException(
                "{$where} \"cache\" must be a boolean (true or false, unquoted), got "
                . get_debug_type($stage['cache'])
            );
        }

        return $stage['cache'];
    }

    /**
     * C2: read and shape-validate a stage's `when:` clause at LOAD time via
     * {@see StageCondition::parse()}. The grammar is refused, never coerced —
     * a malformed clause fails the file exactly like an unknown key does
     * ("Everything malformed is refused, not coerced", docs/WORKFLOWS.md).
     * The RAW author spelling is returned: normalization happens exactly
     * once, when {@see WorkflowBuilder::when()} attaches the clause.
     *
     * @param array<array-key, mixed> $stage
     * @return array<array-key, mixed>|null the raw clause, or null when absent
     */
    private function requireStageWhen(array $stage, string $where): ?array
    {
        if (!array_key_exists('when', $stage)) {
            return null;
        }

        $clause = $stage['when'];
        if (!is_array($clause)) {
            throw new WorkflowLoadException(
                "{$where} \"when\" must be a map carrying one of all, any, equals, contains, matches (with stage), got "
                . get_debug_type($clause)
            );
        }

        try {
            StageCondition::parse($clause);
        } catch (\InvalidArgumentException $error) {
            throw new WorkflowLoadException("{$where} " . $error->getMessage());
        }

        return $clause;
    }

    /**
     * C4: read and shape-validate a stage's `loop:` map at LOAD time. The
     * grammar is a closed pair — `max` (a whole number of at least 1, the
     * same {@see requireTaskPositiveInt()} family `timeout` rides) and
     * `until` (a C2 {@see StageCondition} clause) — and both are required:
     * a loop that names no exit clause would be a fixed count wearing a
     * conditioner's coat, and the system refuses rather than guesses
     * ("Everything malformed is refused, not coerced", docs/WORKFLOWS.md).
     * The RAW author spelling is returned: normalization happens exactly
     * once, when {@see WorkflowBuilder::loop()} attaches the map.
     *
     * @param array<array-key, mixed> $stage
     * @return array<array-key, mixed>|null the raw map, or null when absent
     */
    private function requireStageLoop(array $stage, string $where): ?array
    {
        if (!array_key_exists('loop', $stage)) {
            return null;
        }

        $loop = $stage['loop'];
        if (!is_array($loop)) {
            throw new WorkflowLoadException(
                "{$where} \"loop\" must be a map carrying max and until (and optionally accumulate), got "
                . get_debug_type($loop)
            );
        }

        $unknown = array_diff_key($loop, array_flip(WorkflowBuilder::LOOP_KEYS));
        if ($unknown !== []) {
            throw new WorkflowLoadException(
                "{$where} \"loop\" carries unknown key(s) '" . implode("', '", array_keys($unknown))
                . "'; the grammar is max, until and accumulate only"
            );
        }

        if (!array_key_exists('max', $loop) || !array_key_exists('until', $loop)) {
            throw new WorkflowLoadException(
                "{$where} \"loop\" must carry both max and until"
            );
        }

        // Validated for the refusal; the RAW spelling rides on, and the
        // (int) normalization is the builder's once-and-only-once job.
        $this->requireTaskPositiveInt($loop['max'], 'loop.max', $where);

        $clause = $loop['until'];
        if (!is_array($clause)) {
            throw new WorkflowLoadException(
                "{$where} \"loop\" \"until\" must be a map carrying one of all, any, equals, contains, matches (with stage), got "
                . get_debug_type($clause)
            );
        }

        try {
            // P1-5: `until:` admits the loop-only leaves ({dry: N},
            // {budgetBelow: N}); `when:` keeps refusing them.
            StageCondition::parse($clause, allowLoopLeaves: true);
        } catch (\InvalidArgumentException $error) {
            throw new WorkflowLoadException("{$where} \"loop\" \"until\" " . $error->getMessage());
        }

        if (array_key_exists('accumulate', $loop)) {
            try {
                WorkflowBuilder::parseAccumulate($loop['accumulate']);
            } catch (\InvalidArgumentException $error) {
                throw new WorkflowLoadException("{$where} " . $error->getMessage());
            }
        }

        return $loop;
    }

    /**
     * A stage's `type:` spelling (C1). Absent means the plain `stage` shape;
     * anything present must name exactly one of the shapes the engine walks
     * ({@see Workflow::STAGE_TYPES}) — the parser, the linter and the engine
     * refuse from that ONE list, so a YAML plan can never declare a stage
     * shape its runner would reject at dispatch time.
     *
     * @param array<array-key, mixed> $stage
     *
     * @throws WorkflowLoadException When `type` is not one of the supported spellings.
     */
    private function requireStageType(array $stage, string $where): string
    {
        $type = $this->requireString($stage, 'type', 'stage', $where);

        if (in_array($type, Workflow::STAGE_TYPES, true)) {
            return $type;
        }

        $supported = [];
        foreach (Workflow::STAGE_TYPES as $spelling) {
            $supported[] = "'{$spelling}'";
        }
        $last = array_pop($supported);

        throw new WorkflowLoadException(
            "{$where} declares type: {$type}; supported stage types are "
            . implode(', ', $supported)
            . ", and {$last} — the same list the engine dispatches on"
        );
    }

    /**
     * A `config:` value as a count of things, at least 1.
     *
     * The `(int)` casts this replaces were the quietest defect in the loader.
     * `maxConcurrent: [1,2]` cast to 1 and `timeout: abc` cast to 0; and in the
     * pre-no-cap era, when `maxConcurrent` still routed through this validator
     * rather than {@see self::requireWidthOrUnlimited()}, `maxConcurrent: -1`
     * was taken literally — which
     * {@see \SugarCraft\Code\Agents\AgentWorkerPool::executeAll()}'s
     * `while (count($active) < $maxConcurrent)` never runs against, so it yields
     * no results at all, and {@see WorkflowEngine::executeParallelStage()} maps "no failures
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
        return $this->requireTaskPositiveInt($value, $key, "Workflow file {$yamlPath}");
    }

    /**
     * The same whole-number-at-least-1 parse, for surfaces whose message
     * prefix is already a full `$where` (task maps beside a stage path).
     * ONE parser: `config.timeout` and a per-task `timeout:` must never
     * disagree about what "600" or "abc" means.
     *
     * @throws WorkflowLoadException When the value is not a whole number >= 1.
     */
    private function requireTaskPositiveInt(mixed $value, string $key, string $where): int
    {
        $int = null;
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);
        }

        if ($int === null) {
            throw new WorkflowLoadException(
                "{$where} \"{$key}\" must be a whole number, got " . get_debug_type($value)
            );
        }

        if ($int < 1) {
            throw new WorkflowLoadException(
                "{$where} \"{$key}\" must be at least 1, got {$int}"
            );
        }

        return $int;
    }

    /**
     * A `config.maxConcurrent` value as a width: a whole number >= 1, or 0 for
     * NO cap — kept as 0, because since P0-3 an ABSENT key (null on the DTO)
     * means the CPU-derived default width, and the two must stay apart.
     *
     * `requirePositiveInt()` cannot serve here. Its whole point is that a
     * literal 0 reaching {@see \SugarCraft\Code\Agents\AgentWorkerPool} means
     * "dispatch nothing", which a parallel stage reports as Completed — a
     * silent no-op. Now that the pool's no-cap value is null, 0 is the spelling
     * an author reaches for when they mean unlimited (it is what
     * `subagentMaxConcurrent` and `max_teammates` accept for the same verdict),
     * so the loader translates it instead of refusing it. A negative number is
     * still refused: it names no width, and clamping it to 1 would run a stage
     * the author asked to stop.
     *
     * @return int the width, or 0 when the author asked for no cap
     *
     * @throws WorkflowLoadException When the value is not a whole number >= 0.
     */
    private function requireWidthOrUnlimited(mixed $value, string $key, string $yamlPath): int
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

        if ($int < 0) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"{$key}\" must be 0 (no cap) or at least 1, got {$int}"
            );
        }

        return $int;
    }

    /**
     * A list position as the 1-based number every load diagnostic prints
     * (`stage #1` is the first stage on the page). A non-int key cannot come
     * from a list, but is echoed rather than guessed at.
     */
    private static function ordinal(int|string $index): string
    {
        return is_int($index) ? (string) ($index + 1) : $index;
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
                "Workflow file {$yamlPath} stage #" . self::ordinal($index) . " must be a map, got " . get_debug_type($stage)
            );
        }

        if (!array_key_exists('name', $stage) || !is_string($stage['name']) || $stage['name'] === '') {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} stage #" . self::ordinal($index) . " must have a \"name\" field"
            );
        }

        return $stage['name'];
    }

    /**
     * Map a parsed YAML stage to a TaskBuilder or array of TaskBuilders.
     *
     * Returns an array of TaskBuilders for parallel stages and for a regular
     * stage that lists `tasks:` (run one after another, roadmap 4.10-1), or a
     * single TaskBuilder for a regular stage written in the one-task form.
     *
     * A `tasks:` stage takes everything from its entries, so a stage-level
     * `agent`/`prompt`/`tools`/`retries` beside it is refused rather than
     * guessed at (does it apply to every task? to none?), as is `tasks:` on a
     * parallel stage, whose list is `agents:`.
     *
     * @param array{name: string, agent?: string, prompt?: string, tools?: array, parallel?: bool, agents?: array, tasks?: array} $stage
     * @param bool $isParallel Already decided by {@see requireParallelFlag()}
     *        and PASSED IN rather than re-derived here, so this method and its
     *        caller cannot disagree about what the stage is — which is exactly
     *        what happened while both tested the raw value themselves.
     * @return TaskBuilder|array<int, TaskBuilder>
     */
    private function parseYamlStage(array $stage, string $stageName, string $yamlPath, bool $isParallel): TaskBuilder|array
    {
        $where = "Workflow file {$yamlPath} stage '{$stageName}'";

        if ($isParallel && array_key_exists('tasks', $stage)) {
            throw new WorkflowLoadException(
                "{$where} is parallel, so it lists \"agents\", not \"tasks\" (a \"tasks\" stage runs its tasks in sequence)"
            );
        }

        // C8: the dynamic-wave spelling. The stage carries a template task
        // (stage-level task keys, prompt required) instead of an agents list;
        // the engine fans the template out per glob match at run time. The
        // parser reads the shape once and returns the template builder in a
        // list, so parallel()'s TaskBuilder[] contract holds unchanged.
        if ($isParallel && array_key_exists('from', $stage)) {
            if (array_key_exists('agents', $stage)) {
                throw new WorkflowLoadException(
                    "{$where} carries both \"from\" and \"agents\"; the fan-out replaces the static list, one spelling at a time"
                );
            }
            if (!array_key_exists('prompt', $stage)) {
                throw new WorkflowLoadException(
                    "{$where} fans out from \"from\", so it needs a stage-level \"prompt\" for each item to run"
                );
            }

            // The stage's own \"name\" is its label, not a task name — it is
            // read by the caller above and must NOT leak into the template
            // (the engine names every fanned-out item by its path).
            $template = array_intersect_key($stage, array_flip(self::YAML_TASK_KEYS));
            unset($template['name']);

            return [$this->readTaskMap($template, "{$where} \"from\" template")];
        }

        if (!$isParallel && array_key_exists('tasks', $stage)) {
            return $this->parseYamlTaskList($stage, $where);
        }

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
                $agentOrdinal = self::ordinal($agentIndex);
                if (!is_array($agent)) {
                    throw new WorkflowLoadException(
                        "{$where} agent #{$agentOrdinal} must be a map, got " . get_debug_type($agent)
                    );
                }

                // Parallel agents spell their label "type"; the task-map "agent"
                // key has no meaning here, so it is refused as an unknown key
                // rather than ignored (rider law, applied to this surface's
                // spelling variant of the roster).
                $this->requireNoUnknownTaskKeys(
                    $agent,
                    "{$where} agent #{$agentOrdinal}",
                    array_values(array_merge(['type'], array_diff(self::YAML_TASK_KEYS, ['agent'])))
                );

                $b = Tasks::agent($this->requireString($agent, 'type', 'coder', "{$where} agent #{$agentOrdinal}"));
                if (array_key_exists('name', $agent)) {
                    $b = $b->name($this->requireString($agent, 'name', '', "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('prompt', $agent)) {
                    $b = $b->prompt($this->requireString($agent, 'prompt', '', "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('tools', $agent)) {
                    $b = $b->tools($this->requireToolList($agent['tools'], "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('retries', $agent)) {
                    $b = $b->retries($this->requireRetryCount($agent['retries'], "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('outputSchema', $agent)) {
                    $b = $b->outputSchema($this->requireSchema($agent['outputSchema'], "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('isolation', $agent)) {
                    $b = $b->isolation($this->requireIsolation($agent['isolation'], "{$where} agent #{$agentOrdinal}"));
                }
                // S2 (wf-g2g3): the per-stage model ask — a trimmed non-empty
                // string here; whether the session can SERVE it is the ONE
                // ladder's job at dispatch (ModelChoice), never a second here.
                if (array_key_exists('model', $agent)) {
                    $b = $b->model($this->requireModel($agent['model'], "{$where} agent #{$agentOrdinal}"));
                }
                // O3: the host resource this agent's stage serializes on,
                // plus its wait bound — checked spellings, refused here.
                if (array_key_exists('resource', $agent)) {
                    $b = $b->resource($this->requireResource($agent['resource'], "{$where} agent #{$agentOrdinal}"));
                }
                if (array_key_exists('lockWaitSeconds', $agent)) {
                    $b = $b->lockWaitSeconds($this->requireLockWaitSeconds($agent['lockWaitSeconds'], "{$where} agent #{$agentOrdinal}"));
                }
                $b = $this->applyTaskArms($b, $agent, "{$where} agent #{$agentOrdinal}");
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

        if (array_key_exists('retries', $stage)) {
            $b = $b->retries($this->requireRetryCount($stage['retries'], $where));
        }

        if (array_key_exists('outputSchema', $stage)) {
            $b = $b->outputSchema($this->requireSchema($stage['outputSchema'], $where));
        }

        if (array_key_exists('isolation', $stage)) {
            $b = $b->isolation($this->requireIsolation($stage['isolation'], $where));
        }
        // S2 (wf-g2g3): the per-stage model ask, same spelling law as the
        // parallel-agent and task-list arms below.
        if (array_key_exists('model', $stage)) {
            $b = $b->model($this->requireModel($stage['model'], $where));
        }

        // O3: resource + wait bound for a one-task stage; the two keys read
        // exactly as their per-agent and per-task spellings do.
        if (array_key_exists('resource', $stage)) {
            $b = $b->resource($this->requireResource($stage['resource'], $where));
        }
        if (array_key_exists('lockWaitSeconds', $stage)) {
            $b = $b->lockWaitSeconds($this->requireLockWaitSeconds($stage['lockWaitSeconds'], $where));
        }

        // C1: the per-task timeout / reject verdict arms, same spelling law
        // as the task-list and verification sub-map surfaces.
        return $this->applyTaskArms($b, $stage, $where);
    }

    /**
     * A regular stage's `tasks:` list, one TaskBuilder per entry, in order.
     *
     * Each entry is the one-task stage's shape — `agent` (a label, default
     * `coder`), `name`, `prompt`, `tools`, `retries` — so moving a stage's
     * body into a list entry changes nothing about how it is read.
     *
     * @param array<array-key, mixed> $stage
     * @return list<TaskBuilder>
     *
     * @throws WorkflowLoadException When the list is empty or not a list, an
     *         entry is not a map, or the stage also carries task keys itself.
     */
    private function parseYamlTaskList(array $stage, string $where): array
    {
        // C1: the list grew with the surface — a stage that chooses `tasks:`
        // keeps the new per-task verdict keys at task level too, so the
        // stage-level spelling of every roster key (minus `name`, which is
        // legitimately both) is refused exactly like the nine that came
        // before it. The rider consolidated this from the parsed-key roster.
        foreach (array_diff(self::YAML_TASK_KEYS, ['name']) as $key) {
            if (array_key_exists($key, $stage)) {
                throw new WorkflowLoadException(
                    "{$where} lists \"tasks\", so \"{$key}\" belongs on each task, not on the stage"
                );
            }
        }

        $tasks = $stage['tasks'];
        if (!is_array($tasks) || !array_is_list($tasks)) {
            throw new WorkflowLoadException(
                "{$where} \"tasks\" must be a list, got " . (is_array($tasks) ? 'a map' : get_debug_type($tasks))
            );
        }
        if ($tasks === []) {
            throw new WorkflowLoadException("{$where} \"tasks\" must list at least one task");
        }

        $builders = [];
        foreach ($tasks as $taskIndex => $task) {
            $at = "{$where} task #" . self::ordinal($taskIndex);
            if (!is_array($task)) {
                throw new WorkflowLoadException("{$at} must be a map, got " . get_debug_type($task));
            }

            $builders[] = $this->readTaskMap($task, $at);
        }

        return $builders;
    }

    /**
     * One task-map reader, shared by the `tasks:` list entries, the
     * verification stage's `task`/`verifier` sub-maps and the pipeline
     * stage's step maps (C1).
     *
     * The WHY is single-source: the same key must mean the same thing,
     * refuse with the same message, and land in the same DTO field on every
     * surface that carries a task — a per-surface copy of this body is how
     * the pre-C1 spellings drifted into three near-identical literals.
     *
     * @param array<array-key, mixed> $map
     */
    private function readTaskMap(array $map, string $at): TaskBuilder
    {
        // One door for every surface that reaches here — tasks-list entries,
        // verification sub-maps, pipeline steps (C1 unified them on this
        // reader): the same roster, the same refusal, on all three.
        $this->requireNoUnknownTaskKeys($map, $at, self::YAML_TASK_KEYS);

        $b = Tasks::agent($this->requireString($map, 'agent', 'coder', $at));
        if (array_key_exists('name', $map)) {
            $b = $b->name($this->requireString($map, 'name', '', $at));
        }
        if (array_key_exists('prompt', $map)) {
            $b = $b->prompt($this->requireString($map, 'prompt', '', $at));
        }
        if (array_key_exists('tools', $map)) {
            $b = $b->tools($this->requireToolList($map['tools'], $at));
        }
        if (array_key_exists('retries', $map)) {
            $b = $b->retries($this->requireRetryCount($map['retries'], $at));
        }
        if (array_key_exists('outputSchema', $map)) {
            $b = $b->outputSchema($this->requireSchema($map['outputSchema'], $at));
        }
        if (array_key_exists('isolation', $map)) {
            $b = $b->isolation($this->requireIsolation($map['isolation'], $at));
        }
        // S2 (wf-g2g3): the per-stage model ask, same spelling law as the
        // stage and parallel-agent arms — whether the session can SERVE it
        // is the ONE ladder's job at dispatch (ModelChoice), never a second here.
        if (array_key_exists('model', $map)) {
            $b = $b->model($this->requireModel($map['model'], $at));
        }
        // O3: per-task resource spelling — the stage's lock set is the
        // union of its tasks' declarations, read at dispatch.
        if (array_key_exists('resource', $map)) {
            $b = $b->resource($this->requireResource($map['resource'], $at));
        }
        if (array_key_exists('lockWaitSeconds', $map)) {
            $b = $b->lockWaitSeconds($this->requireLockWaitSeconds($map['lockWaitSeconds'], $at));
        }

        return $this->applyTaskArms($b, $map, $at);
    }

    /**
     * The C1 verdict arms every task surface shares: `timeout`, `reject`
     * and `rejectMinOccurrences`.
     *
     * reject parses on EVERY task surface on purpose: placement is the
     * engine's policy, not the parser's — WorkflowEngine's reject-misuse
     * pre-flight refuses a reject() that sits anywhere but a verification
     * stage's verifier slot, before a single agent is dispatched. The
     * parser refuses shapes; it never second-guesses strategy.
     *
     * @param array<array-key, mixed> $map
     */
    private function applyTaskArms(TaskBuilder $b, array $map, string $at): TaskBuilder
    {
        if (array_key_exists('timeout', $map)) {
            $b = $b->timeout($this->requireTaskPositiveInt($map['timeout'], 'timeout', $at));
        }

        // C7 (Option A): the idle-stall window rides the same whole-number
        // law as `timeout` (>=1 — a window of 0 means "omit the key", never
        // "expire instantly"), and its restart budget shares the >=0 parser
        // family with `retries`. Both are inert when absent.
        if (array_key_exists('stallTimeout', $map)) {
            $b = $b->stallTimeout($this->requireTaskPositiveInt($map['stallTimeout'], 'stallTimeout', $at));
        }

        if (array_key_exists('restarts', $map)) {
            $b = $b->restarts($this->requireTaskCount($map['restarts'], 'restarts', $at));
        }

        if (array_key_exists('rejectMinOccurrences', $map) && !array_key_exists('reject', $map)) {
            throw new WorkflowLoadException(
                "{$at} sets \"rejectMinOccurrences\" without \"reject\"; the threshold counts"
                . " reject-pattern matches, so the pattern has to be there too"
            );
        }

        $pattern = $this->requireString($map, 'reject', '', $at);
        if ($pattern !== '') {
            $atLeast = array_key_exists('rejectMinOccurrences', $map)
                ? $this->requireTaskPositiveInt($map['rejectMinOccurrences'], 'rejectMinOccurrences', $at)
                : 1;
            try {
                $b = $b->reject($pattern, $atLeast);
            } catch (\InvalidArgumentException $error) {
                // Same wrap law as requireSchema(): a pattern the author wrote is a
                // pattern the author meant, so a bad PCRE is named, not coerced.
                throw new WorkflowLoadException("{$at} \"reject\": {$error->getMessage()}", 0, $error);
            }
        }

        // P1-4: the verifier-panel arms. Like `reject` they parse on EVERY
        // task surface and their placement is the engine's pre-flight law
        // (verifier slot only); the parser judges shapes and the cross-key
        // law (one lens per vote, a quorum the votes can meet).
        $votes = 1;
        $lenses = null;
        $quorum = null;
        if (array_key_exists('votes', $map)) {
            $votes = $this->requireTaskPositiveInt($map['votes'], 'votes', $at);
            $b = $b->votes($votes);
        }
        if (array_key_exists('lenses', $map)) {
            if (!is_array($map['lenses'])) {
                throw new WorkflowLoadException(
                    "{$at} \"lenses\" must be a list of lens names, one per vote, got " . get_debug_type($map['lenses'])
                );
            }
            try {
                $lenses = TaskBuilder::trustedLenses($map['lenses']);
            } catch (\InvalidArgumentException $error) {
                throw new WorkflowLoadException("{$at} {$error->getMessage()}", 0, $error);
            }
            $b = $b->lenses($lenses);
        }
        if (array_key_exists('quorum', $map)) {
            $quorum = $this->requireTaskPositiveInt($map['quorum'], 'quorum', $at);
            $b = $b->quorum($quorum);
        }
        try {
            TaskBuilder::verifierPanel($votes, $lenses, $quorum);
        } catch (\InvalidArgumentException $error) {
            throw new WorkflowLoadException("{$at} {$error->getMessage()}", 0, $error);
        }

        // P1-6: the agent-preset arm. Only the SPELLING is judged here — the
        // roster the name resolves against belongs to the engine (the same
        // tiers `Task` uses), which refuses an unknown preset before the run
        // dispatches anything.
        if (array_key_exists('preset', $map)) {
            if (!is_string($map['preset']) || trim($map['preset']) === '') {
                throw new WorkflowLoadException(
                    "{$at} \"preset\" must name an agent preset (e.g. reviewer), got "
                    . (is_string($map['preset']) ? 'an empty string' : get_debug_type($map['preset']))
                );
            }
            $b = $b->preset($map['preset']);
        }
        if (array_key_exists('effort', $map)) {
            if (!is_string($map['effort'])) {
                throw new WorkflowLoadException(
                    "{$at} \"effort\" must be one of low, medium, high, xhigh, max, got " . get_debug_type($map['effort'])
                );
            }
            try {
                $b = $b->effort($map['effort']);
            } catch (\InvalidArgumentException $error) {
                throw new WorkflowLoadException("{$at} {$error->getMessage()}", 0, $error);
            }
        }

        // C9: the fan-in join arm. trustedJoin() judges the SHAPE (raw
        // English, wrapped to a load error naming the declaration site
        // exactly like the reject pattern above); the declaring stage's
        // index rides a pending record so the end-of-file pass in
        // parseYamlWorkflow() can judge ORDER — a join may only name stages
        // declared before its own. A null joinStage means a task surface was
        // parsed outside any stage loop; index -1 then fails closed there.
        if (array_key_exists('input', $map)) {
            // trustedJoin is typed array: a scalar spelling must refuse loudly
            // through the load door, never leak a TypeError past the parse.
            if (!is_array($map['input'])) {
                throw new WorkflowLoadException(
                    "{$at} \"input\" must be a map carrying \"from\" and \"as\", got "
                    . get_debug_type($map['input'])
                );
            }

            try {
                $spec = TaskBuilder::trustedJoin($map['input']);
            } catch (\InvalidArgumentException $error) {
                throw new WorkflowLoadException("{$at} \"input\": {$error->getMessage()}", 0, $error);
            }

            $b = $b->input($spec);
            $this->pendingJoins[] = [
                'at' => $at,
                'index' => (int) ($this->joinStage['index'] ?? -1),
                'from' => $spec['from'],
            ];
        }

        return $b;
    }

    /**
     * A verification stage's `task:` or `verifier:` sub-map (C1).
     *
     * Both slots read through the one task-map surface, so the verifier is
     * a first-class agent (model, isolation, schema all available) and not
     * a second-class prompt string.
     *
     * @param array<array-key, mixed> $stage
     */
    private function parseVerificationSubMap(array $stage, string $slot, string $where): TaskBuilder
    {
        if (!array_key_exists($slot, $stage)) {
            throw new WorkflowLoadException(
                "{$where} is type: verification, so it needs a \"{$slot}\" sub-map"
            );
        }
        $map = $stage[$slot];
        if (!is_array($map)) {
            throw new WorkflowLoadException("{$where} \"{$slot}\" must be a map, got " . get_debug_type($map));
        }

        return $this->readTaskMap($map, "{$where} \"{$slot}\"");
    }

    /**
     * The `uses:` value of an include-compose stage (C11): a workflow name,
     * and exactly one body per stage.
     *
     * The arm IS the body, so any other body spelling — `agent:`, `tasks:`,
     * `task:`, `agents:`, a nested `stages:` list, the `parallel: true` flag,
     * or a `type:` other than stage — refuses at load. Raw English: the name
     * is the author's own, and the loader never reaches the engine.
     *
     * @param array<array-key, mixed> $stage
     */
    private function requireUsesArm(array $stage, string $where, string $stageType, bool $flaggedParallel): string
    {
        $uses = $stage['uses'];
        if (!is_string($uses) || trim($uses) === '') {
            throw new WorkflowLoadException(
                "{$where} \"uses\" must name the workflow to include, got "
                . (is_string($uses) ? 'an empty string' : get_debug_type($uses))
            );
        }

        foreach (['agent', 'tasks', 'task', 'agents', 'stages'] as $bodyKey) {
            if (array_key_exists($bodyKey, $stage)) {
                throw new WorkflowLoadException(
                    "{$where} carries \"uses\", which is itself a stage body; it cannot accompany \"{$bodyKey}\""
                );
            }
        }

        if ($flaggedParallel) {
            throw new WorkflowLoadException(
                "{$where} carries \"uses\", which is itself a stage body; it cannot accompany \"parallel: true\""
            );
        }

        if ($stageType !== 'stage') {
            throw new WorkflowLoadException(
                "{$where} carries \"uses\", which is itself a stage body; it cannot accompany \"type: {$stageType}\""
            );
        }

        return $uses;
    }

    /**
     * The optional `with:` sub-map of an include-compose stage (C11).
     *
     * Names to values; values are stringified scalars. The inner workflow
     * receives them as plain run-context seeds, so a number or boolean rides
     * as its string spelling and a container or null refuses instead of being
     * silently JSON-encoded. Absent `with:` is an empty map.
     *
     * @param array<array-key, mixed> $stage
     * @return array<string, string>
     */
    private function parseUsesWithSubMap(array $stage, string $where): array
    {
        if (!array_key_exists('with', $stage)) {
            return [];
        }

        $map = $stage['with'];
        if (!is_array($map)) {
            throw new WorkflowLoadException("{$where} \"with\" must be a map, got " . get_debug_type($map));
        }

        $values = [];
        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                throw new WorkflowLoadException(
                    "{$where} \"with\" key is not a name, got " . get_debug_type($key)
                );
            }

            if (is_array($value) || $value === null) {
                throw new WorkflowLoadException(
                    "{$where} \"with\" value for \"{$key}\" must be a string or scalar, got " . get_debug_type($value)
                );
            }

            $values[$key] = is_string($value) ? $value : (string) $value;
        }

        return $values;
    }

    /**
     * A parallel stage's `from:` sub-map: the fan-out SOURCE plus the
     * optional `chunk` — a ceiling on how many of the fanned-out items run
     * at once (a cap, never a floor) — and the optional `max` (P0-3): keep
     * the first N items and RECORD the rest as dropped. `max` is at most
     * {@see Workflow::FAN_OUT_CEILING}: a cap above the ceiling could never
     * be honoured, so it is refused here rather than discovered at run time.
     *
     * Exactly one source ({@see WorkflowBuilder::fromSource()} is the shared
     * law): `glob` (C8, files under the environment root), `stage` +
     * `pointer` (P1-1, an array in a PRIOR stage's typed row — the same
     * order law `input.from` keeps, judged here against $priorStages) or
     * `context` (P1-1, a run-context list); data sources may add `key`.
     *
     * The parser refuses shapes only; expansion is the engine's job
     * (rider law — a sub-map is checked where it is read, and this one is
     * read to a spec, not to a materialised list, because the list can only
     * exist while the workflow runs).
     *
     * @param array<array-key, mixed> $stage
     * @param list<string> $priorStages names of the stages declared before this one
     *
     * @return array<string, mixed>
     */
    private function parseDynamicWaveSubMap(array $stage, string $where, array $priorStages = []): array
    {
        $map = $stage['from'];
        $at = "{$where} \"from\"";
        if (!is_array($map)) {
            throw new WorkflowLoadException("{$at} must be a map, got " . get_debug_type($map));
        }

        $this->requireNoUnknownTaskKeys($map, $at, self::YAML_FROM_KEYS);

        if (!array_key_exists('glob', $map) && !array_key_exists('stage', $map) && !array_key_exists('context', $map)) {
            throw new WorkflowLoadException(
                "{$at} needs exactly one source: a \"glob\" to expand against the environment root, "
                . '"stage" + "pointer" (an array in a prior stage\'s typed row) or "context" (a run-context list)'
            );
        }
        if (array_key_exists('glob', $map) && is_string($map['glob']) && trim($map['glob']) === ''
            && !array_key_exists('stage', $map) && !array_key_exists('context', $map)) {
            throw new WorkflowLoadException("{$at} \"glob\" must not be empty");
        }

        try {
            $source = WorkflowBuilder::fromSource($map);
        } catch (\InvalidArgumentException $e) {
            throw new WorkflowLoadException("{$where} {$e->getMessage()}", 0, $e);
        }

        if (isset($source['stage']) && !in_array($source['stage'], $priorStages, true)) {
            throw new WorkflowLoadException(
                "{$at} \"stage\": \"{$source['stage']}\" is not a stage declared before this one; a fan-out reads prior stages only"
            );
        }

        $chunk = null;
        if (array_key_exists('chunk', $map)) {
            $chunk = $this->requireTaskPositiveInt($map['chunk'], 'chunk', $at);
        }

        // `max` (and a data source's `key`) ride only when written, so a
        // glob spec without them stays the two-key shape every pre-P0-3
        // consumer reads.
        $spec = $source + ['chunk' => $chunk];
        if (array_key_exists('max', $map)) {
            $max = $this->requireTaskPositiveInt($map['max'], 'max', $at);
            if ($max > Workflow::FAN_OUT_CEILING) {
                throw new WorkflowLoadException(
                    "{$at} \"max\" must be at most " . Workflow::FAN_OUT_CEILING . " (the per-stage fan-out ceiling), got {$max}"
                );
            }
            $spec['max'] = $max;
        }

        return $spec;
    }

    /**
     * P1-2: a `type: map` stage — a `from:` source (the parallel fan-out's
     * sub-map, same law) and a `steps:` list. The stage map itself carries
     * no task keys: each step is its own body. A step is a task map (task
     * keys plus `when:`) or `type: verification` with `task:`/`verifier:`
     * sub-maps (plus `name` and `when`); every step needs a unique `name`.
     * `type: parallel|map|pipeline`, `uses:` and nested fan-out keys are
     * refused as steps in v1. A step's `when:` may spell its leaves
     * `step: <name>` (read as `stage:`) and must not read itself or a later
     * step ({@see WorkflowBuilder::map()} judges that, re-raised here).
     *
     * @param array<array-key, mixed> $stage
     * @param list<string> $priorStages
     */
    private function parseMapStage(WorkflowBuilder $builder, array $stage, string $stageName, string $where, array $priorStages, bool $capture, bool $cache): WorkflowBuilder
    {
        foreach (['agents', 'tasks', 'task', 'verifier', 'stages'] as $body) {
            if (array_key_exists($body, $stage)) {
                throw new WorkflowLoadException(
                    "{$where} is type: map, so its body is \"steps\"; it cannot carry \"{$body}\""
                );
            }
        }
        foreach (array_diff(self::YAML_TASK_KEYS, ['name']) as $key) {
            if (array_key_exists($key, $stage)) {
                throw new WorkflowLoadException(
                    "{$where} is type: map, so \"{$key}\" belongs on a step, not on the stage"
                );
            }
        }
        if (!array_key_exists('from', $stage)) {
            throw new WorkflowLoadException(
                "{$where} is type: map, so it needs a \"from\" source: a \"glob\", \"stage\" + \"pointer\", or \"context\""
            );
        }
        $from = $this->parseDynamicWaveSubMap($stage, $where, $priorStages);

        if (!array_key_exists('steps', $stage)) {
            throw new WorkflowLoadException("{$where} is type: map, so it needs a \"steps\" list");
        }
        $steps = $stage['steps'];
        if (!is_array($steps) || !array_is_list($steps)) {
            throw new WorkflowLoadException(
                "{$where} \"steps\" must be a list, got " . (is_array($steps) ? 'a map' : get_debug_type($steps))
            );
        }
        if ($steps === []) {
            throw new WorkflowLoadException("{$where} \"steps\" must list at least one step");
        }

        $built = [];
        foreach ($steps as $stepIndex => $step) {
            $at = "{$where} step #" . self::ordinal($stepIndex);
            if (!is_array($step)) {
                throw new WorkflowLoadException("{$at} must be a map, got " . get_debug_type($step));
            }
            $type = $this->requireString($step, 'type', 'stage', $at);
            if ($type !== 'stage' && $type !== 'verification') {
                throw new WorkflowLoadException(
                    "{$at} declares type: {$type}; a map step is a task map or \"type: verification\" "
                    . '(parallel, map, pipeline and uses steps are refused in v1)'
                );
            }
            foreach (['uses', 'parallel', 'agents', 'tasks', 'stages', 'from', 'steps', 'loop', 'minSuccess', 'capture', 'cache', 'with'] as $stageOnly) {
                if (array_key_exists($stageOnly, $step)) {
                    throw new WorkflowLoadException(
                        "{$at} carries \"{$stageOnly}\", which a map step cannot (a step is one task or one verification sub-stage)"
                    );
                }
            }
            if (!array_key_exists('name', $step)) {
                throw new WorkflowLoadException(
                    "{$at} needs a \"name\": it keys the step in each item's row and is read as {{<name>.results}} inside the item"
                );
            }
            $when = null;
            if (array_key_exists('when', $step)) {
                if (!is_array($step['when'])) {
                    throw new WorkflowLoadException("{$at} \"when\" must be a condition map, got " . get_debug_type($step['when']));
                }
                $when = $step['when'];
            }

            if ($type === 'verification') {
                $this->requireNoUnknownTaskKeys($step, $at, ['name', 'type', 'task', 'verifier', 'when']);
                $name = $this->requireString($step, 'name', '', $at);
                $built[] = WorkflowBuilder::mapVerification(
                    $name,
                    $this->parseVerificationSubMap($step, 'task', $at),
                    $this->parseVerificationSubMap($step, 'verifier', $at),
                    $when,
                );

                continue;
            }

            $this->requireNoUnknownTaskKeys($step, $at, array_merge(self::YAML_TASK_KEYS, ['type', 'when']));
            $taskMap = $step;
            unset($taskMap['type'], $taskMap['when']);
            $built[] = WorkflowBuilder::mapStep($this->readTaskMap($taskMap, $at), $when);
        }

        try {
            return $builder->map($stageName, $from, $built, capture: $capture, cache: $cache);
        } catch (\InvalidArgumentException $e) {
            throw new WorkflowLoadException("{$where} {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * A pipeline stage's `stages:` list of steps (C1).
     *
     * Each step is one task map; {@see WorkflowBuilder::pipeline()} names
     * the nested sub-stage from the step's own `name`, falling back to its
     * agent label — the same ladder the PHP form uses.
     *
     * @param array<array-key, mixed> $stage
     * @return list<TaskBuilder>
     */
    private function parsePipelineSteps(array $stage, string $where): array
    {
        if (!array_key_exists('stages', $stage)) {
            throw new WorkflowLoadException(
                "{$where} is type: pipeline, so it needs a \"stages\" list of steps"
            );
        }
        $steps = $stage['stages'];
        if (!is_array($steps) || !array_is_list($steps)) {
            throw new WorkflowLoadException(
                "{$where} \"stages\" must be a list, got " . (is_array($steps) ? 'a map' : get_debug_type($steps))
            );
        }
        if ($steps === []) {
            throw new WorkflowLoadException("{$where} \"stages\" must list at least one step");
        }

        $builders = [];
        foreach ($steps as $stepIndex => $step) {
            $at = "{$where} step #" . self::ordinal($stepIndex);
            if (!is_array($step)) {
                throw new WorkflowLoadException("{$at} must be a map, got " . get_debug_type($step));
            }
            $builders[] = $this->readTaskMap($step, $at);
        }

        return $builders;
    }

    /**
     * A task's `retries:` — how many times a failed or timed-out agent is
     * re-run (audit WF-1(b); {@see \SugarCraft\Code\Agents\AgentWorkerPool::withMaxRetries()}).
     *
     * A whole number >= 0, with {@see requirePositiveInt()}'s acceptance of a
     * quoted integer; 0 is legal and means what leaving the key out means.
     * Refused rather than coerced for that method's reason: `retries: -1` or
     * `retries: lots` read as 0 would be an author's retry silently not
     * happening.
     *
     * @throws WorkflowLoadException When the value is not a whole number >= 0.
     */
    private function requireRetryCount(mixed $value, string $where): int
    {
        return $this->requireTaskCount($value, 'retries', $where);
    }

    /**
     * The whole-number-at-least-0 parse, key-parameterized — ONE parser
     * family for `retries` and `restarts` (C7): the two budgets must never
     * disagree about what "2" or "two" means.
     *
     * @throws WorkflowLoadException When the value is not a whole number >= 0.
     */
    private function requireTaskCount(mixed $value, string $key, string $where): int
    {
        $int = null;
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);
        }

        if ($int === null) {
            throw new WorkflowLoadException("{$where} \"{$key}\" must be a whole number, got " . get_debug_type($value));
        }

        if ($int < 0) {
            throw new WorkflowLoadException("{$where} \"{$key}\" must be at least 0, got {$int}");
        }

        return $int;
    }

    /**
     * A task's `outputSchema:` (G3) — the JSON schema document that constrains
     * this stage agent's terminal turn, the workflow spelling of headless
     * `--json-schema`. Either a native YAML map (a schema written inline in the
     * document; the empty map `{}` passes through as the empty schema exactly
     * as headless passes `{}`) or a string carrying headless's token: inline
     * JSON starting with `{`, or a path to a schema file — read through the ONE
     * shared decoder {@see JsonSchemaArgument::decode()} so the same token
     * means the same schema on both surfaces. Refused, never ignored: a schema
     * that cannot be read would silently degrade the verdict back to free
     * text, which is the very thing the option exists to prevent.
     *
     * @return array<mixed> the decoded-trusted schema document
     *
     * @throws WorkflowLoadException When the value is neither a map nor a
     *         decodable schema token.
     */
    private function requireSchema(mixed $value, string $where): array
    {
        if (is_array($value)) {
            if (array_is_list($value) && $value !== []) {
                throw new WorkflowLoadException(
                    "{$where} \"outputSchema\" must be a JSON object (a schema); a list or a scalar is not one"
                );
            }

            return $this->resolveSchemaRef($value, $where);
        }

        if (is_string($value)) {
            try {
                $decoded = JsonSchemaArgument::decode($value);
            } catch (\InvalidArgumentException $e) {
                throw new WorkflowLoadException("{$where} \"outputSchema\": {$e->getMessage()}", 0, $e);
            }

            if ($decoded === null) {
                // Unreachable for a string token (decode() nulls only null);
                // refused here so no future caller mistake reads "no schema".
                throw new WorkflowLoadException("{$where} \"outputSchema\" must not be empty");
            }

            return $this->resolveSchemaRef($decoded, $where);
        }

        throw new WorkflowLoadException(
            "{$where} \"outputSchema\" must be a schema map or a string (inline JSON or a file path), got "
            . get_debug_type($value)
        );
    }

    /**
     * P2-4: the top-level `schemas:` map — named JSON Schemas a task reuses
     * as `outputSchema: {$ref: <name>}` instead of repeating the document per
     * shard. Absent means none. Each name is an identifier
     * (`[A-Za-z_][A-Za-z0-9_-]*`) and each value an object-rooted schema
     * map (`type: object`) whose `required`, when written beside
     * `properties`, names only declared properties — a schema the
     * StructuredOutput contract could never satisfy is refused at load, not
     * discovered after an agent has been billed for it.
     *
     * @param array<array-key, mixed> $data
     * @return array<string, array<mixed>>
     */
    private function parseNamedSchemas(array $data, string $yamlPath): array
    {
        if (!array_key_exists('schemas', $data)) {
            return [];
        }

        $map = $data['schemas'];
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} \"schemas\" must be a map of name => schema, got "
                . (is_array($map) ? 'a list' : get_debug_type($map))
            );
        }

        $schemas = [];
        foreach ($map as $name => $schema) {
            $at = "Workflow file {$yamlPath} schema \"{$name}\"";
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
                throw new WorkflowLoadException(
                    "Workflow file {$yamlPath} \"schemas\" key \"{$name}\" is not a usable name; use letters, digits, _ and -, not starting with a digit"
                );
            }
            if (!is_array($schema) || array_is_list($schema)) {
                throw new WorkflowLoadException("{$at} must be a schema map, got " . (is_array($schema) ? 'a list' : get_debug_type($schema)));
            }
            if (($schema['type'] ?? null) !== 'object') {
                throw new WorkflowLoadException("{$at} must be object-rooted (\"type: object\" at its root)");
            }
            if (array_key_exists('required', $schema)) {
                $required = $schema['required'];
                if (!is_array($required) || !array_is_list($required)) {
                    throw new WorkflowLoadException("{$at} \"required\" must be a list of property names");
                }
                $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
                foreach ($required as $property) {
                    if (!is_string($property) || !array_key_exists($property, $properties)) {
                        throw new WorkflowLoadException(
                            "{$at} \"required\" names \"" . (is_scalar($property) ? (string) $property : get_debug_type($property))
                            . "\", which is not one of its \"properties\""
                        );
                    }
                }
            }
            $schemas[$name] = $schema;
        }

        return $schemas;
    }

    /**
     * P2-4: `outputSchema: {$ref: <name>}` — a map whose ONLY key is `$ref`
     * — resolved against the file's `schemas:` map. The RESOLVED document is
     * what the task carries, so the engine's stage identity hash digests the
     * schema itself, not its name: editing a shared schema invalidates every
     * `cache:` row that used it. Any other map passes through untouched (a
     * `$ref` NESTED inside a schema is JSON Schema's own and not ours), and
     * `$ref` beside other root keys is refused rather than guessed at.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function resolveSchemaRef(array $schema, string $where): array
    {
        if (!array_key_exists('$ref', $schema)) {
            return $schema;
        }

        if (count($schema) !== 1) {
            throw new WorkflowLoadException(
                "{$where} \"outputSchema\" carries \"\$ref\" beside other keys; a reference is the one key {\$ref: <name>}"
            );
        }

        $name = $schema['$ref'];
        if (!is_string($name) || $name === '') {
            throw new WorkflowLoadException(
                "{$where} \"outputSchema\" \"\$ref\" must name a schema, got " . get_debug_type($name)
            );
        }

        if (!array_key_exists($name, $this->namedSchemas)) {
            throw new WorkflowLoadException(
                "{$where} \"outputSchema\" \"\$ref\" names unknown schema \"{$name}\"; "
                . ($this->namedSchemas === []
                    ? 'this file declares no top-level "schemas:" map'
                    : 'declared schemas: ' . implode(', ', array_keys($this->namedSchemas)))
            );
        }

        return $this->namedSchemas[$name];
    }

    /**
     * A task's `isolation:` (G2) — where the stage agent runs: 'none' for the
     * shared checkout (the default), 'worktree' for its own git worktree.
     *
     * The ONE spelling check for the YAML form, mirroring the agent-preset
     * parser (SubagentSettingsTest): the same spellings, the same trim- and
     * case-insensitivity, the same refusal — an author who writes `isolation:
     * sandbox` means something specific, and quietly running in the shared
     * checkout (or quietly not isolating) is the one outcome worse than an
     * error. Parse-don't-validate: downstream sees the trusted enum or nothing.
     *
     * @throws WorkflowLoadException When the value is not a string, or names
     *                               a mode that does not exist.
     */
    private function requireIsolation(mixed $value, string $where): Isolation
    {
        if (!is_string($value)) {
            throw new WorkflowLoadException(
                "{$where} \"isolation\" must be a string ('none' or 'worktree'), got " . get_debug_type($value)
            );
        }

        $raw = strtolower(trim($value));

        return match ($raw) {
            'none' => Isolation::None,
            'worktree' => Isolation::Worktree,
            default => throw new WorkflowLoadException(
                "{$where} declares isolation: {$raw}; supported spellings are 'none' and 'worktree'"
            ),
        };
    }

    /**
     * A task's `model:` (S2) — the model the stage author asks the run to
     * dial, trimmed to a non-empty string.
     *
     * Parse at the boundary, trust inside: the trim happens HERE so every
     * downstream reader (the {@see \SugarCraft\Code\Agents\ModelChoice}
     * ladder, the report) sees the same exact id. Blank is refused, never
     * read as "session": a key the author wrote must name something, and a
     * silent default is the one outcome worse than an error — the same law
     * the isolation spelling check enforces. Whether a named model is
     * SERVABLE is not checked here — that is the one ladder's job at
     * dispatch, and duplicating its view here would be a second copy.
     *
     * @throws WorkflowLoadException When the value is not a string, or is
     *                               only whitespace.
     */
    private function requireModel(mixed $value, string $where): string
    {
        if (!is_string($value)) {
            throw new WorkflowLoadException(
                "{$where} \"model\" must be a string naming a model, got " . get_debug_type($value)
            );
        }

        $asked = trim($value);
        if ($asked === '') {
            throw new WorkflowLoadException("{$where} \"model\" names no model");
        }

        return $asked;
    }

    /**
     * A task's `resource:` (O3) — the host resource its stage serializes
     * on, trimmed to a non-empty string, with the same refuse-a-blank law
     * `model:` holds: a key the author wrote must name something. Which
     * process currently HOLDS the name is the lock's own question, answered
     * at dispatch, never here.
     *
     * @throws WorkflowLoadException When the value is not a string, or is
     *                               only whitespace.
     */
    private function requireResource(mixed $value, string $where): string
    {
        if (!is_string($value)) {
            throw new WorkflowLoadException(
                "{$where} \"resource\" must be a string naming a host resource, got " . get_debug_type($value)
            );
        }

        $asked = trim($value);
        if ($asked === '') {
            throw new WorkflowLoadException("{$where} \"resource\" names no resource");
        }

        return $asked;
    }

    /**
     * A task's `lockWaitSeconds:` (O3) — how long its stage may wait for a
     * contended resource before failing loudly. A whole number >= 0 with
     * requireRetryCount's quoted-integer acceptance; 0 is the legitimate
     * ask-once-and-give-up. Refused rather than coerced for the retry
     * count's reason: a bound the author typed and the loader quietly
     * dropped is a wait silently not happening.
     *
     * @throws WorkflowLoadException When the value is not a whole number,
     *                               or is negative.
     */
    private function requireLockWaitSeconds(mixed $value, string $where): int
    {
        $int = null;
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) trim($value);
        }

        if ($int === null) {
            throw new WorkflowLoadException("{$where} \"lockWaitSeconds\" must be a whole number, got " . get_debug_type($value));
        }

        if ($int < 0) {
            throw new WorkflowLoadException("{$where} \"lockWaitSeconds\" must be at least 0, got {$int}");
        }

        return $int;
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
     * Fail closed on any key this parser does not read (the rider law, from
     * the wftpl review NIT-1): a key the author wrote but the loader silently
     * dropped is a workflow that quietly does less than it looks like — the
     * exact coercion class every sibling refusal in this file rejects. The
     * first offender is named, and the supported set is named with it, so the
     * refusal is also the fix. Placement of ROSTER-KNOWN keys on the wrong
     * surface stays the per-shape refusals' job (parallel+tasks, tasks+tail);
     * this door only closes the vocabulary itself.
     *
     * @param list<string> $allowed
     */
    private function requireNoUnknownTaskKeys(array $map, string $where, array $allowed): void
    {
        foreach (array_keys($map) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new WorkflowLoadException(
                    "{$where} has unknown key \"{$key}\"; supported keys: " . implode(', ', $allowed)
                );
            }
        }
    }

    /**
     * The bytes a .php workflow memo was compiled from (C16). Null when
     * md5_file cannot read the file — the memo then keeps its path-only law.
     */
    private static function contentDigest(string $path): ?string
    {
        $digest = @md5_file($path);

        return \is_string($digest) ? $digest : null;
    }

    /**
     * Whether the file at $path declares anything a second compile could
     * redeclare (C16) — the shape whose blind re-require raises the
     * uncatchable `Cannot redeclare` compile fatal.
     *
     * The scan is deliberately conservative toward the loud refusal: a false
     * refusal costs one restart, a false pass costs the whole process. A
     * namespace anywhere (braced namespace blocks hide file-scope declarations
     * from a depth walk), or a file-scope named function, class, interface,
     * trait, enum or constant, accuses. Closures (`function (`), arrow
     * functions (T_FN is its own token), anonymous classes (T_ANON_CLASS is
     * its own token) and import lines (a keyword preceded by T_USE aliases,
     * never declares) do not. Unreadable source accuses.
     */
    private static function sourceDeclaresFileScopeSymbols(string $path): bool
    {
        $source = @file_get_contents($path);
        if (!\is_string($source)) {
            return true;
        }

        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);
        $depth = 0;
        $previous = -1;
        $skipIds = [\T_WHITESPACE => true, \T_COMMENT => true, \T_DOC_COMMENT => true];

        for ($i = 0; $i < $count; ++$i) {
            $id = $tokens[$i]->id;
            if (isset($skipIds[$id])) {
                continue;
            }

            if ($id === \T_NAMESPACE) {
                return true;
            }
            // Depth arms name every brace-opening token the scanner can meet:
            // T_CURLY_OPEN is the "{$" opener (its text is "{", so it also
            // rides the character arm, which covers plain literal braces —
            // PHP publishes no T_OPEN_CURLY_BRACE constant);
            // T_DOLLAR_OPEN_CURLY_BRACES is the "${" opener, a single token
            // whose text is "${" and therefore invisible to the character
            // arm. InterpolationOpenerTokenTest demands each opener be named
            // as code: a silently-missed one loses a level and the walk then
            // mis-reads everything after the first interpolated string.
            $text = $tokens[$i]->text;
            if ($id === \T_CURLY_OPEN || $text === '{' || $id === \T_DOLLAR_OPEN_CURLY_BRACES) {
                ++$depth;
                $previous = -1;
                continue;
            }
            if ($text === '}') {
                --$depth;
                $previous = -1;
                continue;
            }
            if ($depth !== 0) {
                continue;
            }

            if ($id === \T_FUNCTION) {
                if ($previous !== \T_USE) {
                    // A named function accuses; a closure does not. Scan past
                    // by-ref '&' — the first real character decides.
                    for ($j = $i + 1; $j < $count; ++$j) {
                        $nid = $tokens[$j]->id;
                        if (isset($skipIds[$nid])) {
                            continue;
                        }
                        if ($nid === \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG
                            || $nid === \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
                        ) {
                            continue;
                        }
                        $text = $tokens[$j]->text;
                        if ($text === '(') {
                            break;
                        }
                        if (\preg_match('/^[A-Za-z_\\\\]/', $text) === 1) {
                            return true;
                        }
                        break;
                    }
                }
            } elseif (\in_array($id, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_CONST], true) && $previous !== \T_USE) {
                return true;
            }
            $previous = $id;
        }

        return false;
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
        // `--root /` — which {@see \SugarCraft\Code\Cli\ArgvParser} accepts,
        // since `/` is a directory — silently anchored the boundary at
        // `getcwd()` instead of at `/`. It failed SAFE (a narrower anchor
        // refuses more), but the anchor was a path nobody named.
        return $trimmed === '' && $home !== '' ? '/' : $trimmed;
    }
}
