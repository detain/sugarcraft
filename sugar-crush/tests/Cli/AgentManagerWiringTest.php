<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentDefinition;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;

/**
 * Reachability tests for crush_code.md Phase 1 item 1: "Construct a real
 * AgentManager inside Bootstrap::chat()".
 *
 * The failure mode this whole audit keeps finding is a subsystem that is built,
 * unit-tested and green while being a guaranteed no-op in production, so a test
 * that only proves `new AgentManager(...)` works would restate the bug rather
 * than close it. Every test here therefore starts at {@see Bootstrap::app()} —
 * the shell `bin/sugarcrush` hands to `Program` on its final line, which hosts
 * the {@see Bootstrap::chat()} the wiring lives in — and each one FAILS if the
 * `agentManager:` argument is removed from `Bootstrap::chat()` again. Each
 * docblock says what specifically breaks it.
 *
 * ## Why this class shares ONE launch across its tests
 *
 * `Bootstrap::app()`/`chat()` is not a cheap fixture: it opens a SQLite session
 * store, seeds a row, scans skills, builds the whole engine tool set and now
 * the agent roster. This class therefore pays for exactly two launches (one
 * against a repo with valid presets, one against a repo with a malformed one)
 * and every test reads from them.
 *
 * That frugality is deliberate rather than stylistic. The suite has two
 * forked-completion tests —
 * `Integration\BinSugarcrushWiringTest::testDoctorToolIsReachableEndToEndThroughARealChatTurn`
 * and `Integration\SystemPromptWiringTest::testARealChatKeystrokeTurnDeliversBothHalves`
 * — that are known load-sensitive flakes, and adding ANY launch-based test file
 * to the run destabilises them. That was verified against an OTHERWISE
 * UNTOUCHED checkout: a file of 11, and a file of 22, tests doing nothing but
 * `Bootstrap::chat()` + an assertion each wedged the run on unmodified source,
 * while the same checkout without the file was green. So the fragility is the
 * suite's, not this file's — but there is no reason to make it worse, and a
 * reachability test for `Bootstrap::chat()` cannot avoid launching at all.
 *
 * Sharing mutable state across tests is the cost. It is contained by having the
 * tests that delegate remove their own sub-agents in a `finally`, so no test
 * depends on running before or after any other.
 *
 * Lives under `tests/Cli/` beside {@see BootstrapTest} and
 * {@see SessionRetentionWiringTest} because {@see Bootstrap} is `src/Cli/`'s
 * class and this is its wiring.
 *
 * The bin script itself cannot be driven end to end from a test: it ends in
 * `Program::run()`, which attaches to a real TTY and blocks. The bin ->
 * Bootstrap link is pinned by source instead
 * ({@see testTheBinaryHandsProgramTheShellThatCarriesTheManager}), which is the
 * same reasoning {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushWiringTest}
 * and {@see \SugarCraft\Crush\Tests\Integration\FeatWiringReachabilityTest}
 * already document.
 */
final class AgentManagerWiringTest extends TestCase
{
    // Used for its CHAIN constant only. The trait's clear/restore helpers are
    // instance methods and this class's fixture is class-level, so the loop is
    // spelled out in setUpBeforeClass()/tearDownAfterClass() below — but the
    // LIST is not copied, which is the whole point of the constant.
    use BackendSelectionEnvSandboxTrait;

    private static string $tempDir = '';
    private static string $repo = '';
    private static string $brokenRepo = '';
    private static string $originalHome = '';

    private static mixed $originalServerHome = null;

    /** @var array<string, string|false> */
    private static array $originalBackendEnv = [];
    private static ?App $app = null;
    private static ?Chat $brokenChat = null;

    /**
     * One launch for the whole class (see the class docblock), against a repo
     * whose preset fixtures are all written FIRST so a single roster can carry
     * every preset-discovery assertion below.
     *
     * $HOME is redirected for the class rather than per test: Bootstrap
     * resolves ~/.sugar-crush (session.db, memory/, config.json and — new here
     * — agents/) off it, and a developer's real preset directory would
     * otherwise land in the roster assertions, which count and name
     * registrations.
     *
     * The backend-selection chain ({@see BackendSelectionEnvSandboxTrait}) is
     * cleared for the same window and for the same reason: this launch has to
     * land on the ENGINE path, because a shell-out backend has no provider,
     * no model and no agent roster to report. Measured, with either
     * `$SUGARCRUSH_BACKEND_CMD` or `$SUGARCRUSH_BACKEND_CMD_STREAM` merely
     * exported in the developer's shell: two failures here.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$tempDir = sys_get_temp_dir() . '/sugarcrush_agent_wiring_' . uniqid('', true);
        self::$repo = self::$tempDir . '/repo';
        self::$brokenRepo = self::$tempDir . '/broken';
        mkdir(self::$tempDir . '/home', 0700, true);
        mkdir(self::$repo, 0755, true);
        mkdir(self::$brokenRepo, 0755, true);

        // BOTH spellings of HOME. `putenv()` alone moved Bootstrap's config
        // directory while the skill trees and the team/workflow stores kept
        // reading the developer's real home -- see
        // Tests\Support\HomeSandboxTrait (a class-level fixture cannot use
        // the trait itself, so it is spelled out here).
        self::$originalHome = getenv('HOME') ?: '';
        self::$originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . self::$tempDir . '/home');
        $_SERVER['HOME'] = self::$tempDir . '/home';

        foreach (self::CHAIN as $var) {
            self::$originalBackendEnv[$var] = getenv($var);
            putenv($var);
        }

        self::writePreset(self::$repo . '/.sugar-crush/agents', 'docs-writer', 'Writes the docs');
        self::writePreset(self::$tempDir . '/home/.sugar-crush/agents', 'house-style', 'Enforces house style');
        self::writePreset(self::$tempDir . '/home/.sugar-crush/agents', 'shared', 'The user copy');
        self::writePreset(self::$repo . '/.sugar-crush/agents', 'shared', 'The project copy');
        self::writePreset(self::$repo . '/.sugar-crush/agents', 'reviewer', 'Our own reviewer');
        self::writePreset(self::$repo . '/.sugar-crush/agents', 'fancy', 'Runs on its own model', 'gpt-5-turbo');

        mkdir(self::$brokenRepo . '/.sugar-crush/agents', 0755, true);
        file_put_contents(self::$brokenRepo . '/.sugar-crush/agents/broken.md', "no frontmatter here\n");

        self::$app = Bootstrap::app(self::$repo);
        self::$brokenChat = Bootstrap::chat(self::$brokenRepo);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app = null;
        self::$brokenChat = null;

        if (self::$originalHome !== '') {
            putenv('HOME=' . self::$originalHome);
        } else {
            putenv('HOME');
        }

        if (self::$originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = self::$originalServerHome;
        }

        foreach (self::$originalBackendEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }
        self::$originalBackendEnv = [];

        self::removeDirectory(self::$tempDir);

        parent::tearDownAfterClass();
    }

    /**
     * The headline claim, plus the two things that keep it from being a hollow
     * one. Before this item `Bootstrap::chat()` passed no `agentManager:` at
     * all, so `agentManager()` was null on every real run and everything below
     * was unreachable in production. `Bootstrap::app()` takes the Chat WHOLE
     * from `Bootstrap::chat()`, so reading it back off the shell also pins that
     * the binary is not handed a second, unwired one.
     *
     * A constructed-but-empty manager would satisfy the null check while being
     * as useless as no manager, hence the roster assertion; and the roster is
     * registered IDLE, which is the property that keeps this wiring from being
     * a UX regression — `Agent::$isActive` is rendered as the literal word
     * "working" by both `Renderer::agentDisplayState()` and
     * {@see AgentDashboardPane}, so six agents registered active would make
     * every launch claim six agents were working on a session where nothing had
     * been delegated.
     */
    public function testTheLaunchedShellCarriesARealAgentManagerHoldingAnIdleBuiltInRoster(): void
    {
        $manager = $this->manager();

        $names = array_map(static fn($agent) => $agent->name, $manager->all());
        foreach (['coder', 'reviewer', 'debugger', 'architect', 'tester', 'devops'] as $expected) {
            $this->assertContains($expected, $names);
        }

        $this->assertSame([], $manager->active(), 'nothing has been delegated, so nothing is working');
        $this->assertFalse($manager->isWorking('coder'));
    }

    /**
     * The frame proves the same thing from the user's side: an idle launch
     * paints the transcript it painted before this item landed, and delegating
     * is what makes the agent strip appear.
     *
     * Both halves are needed. "Renders nothing" alone would be equally
     * satisfied by the manager still being unreachable, and "renders a strip"
     * alone would not catch a roster registered active by mistake.
     */
    public function testTheAgentStripAppearsOnlyOnceSomethingIsDelegated(): void
    {
        $chat = $this->chat();
        $manager = $this->manager();

        $this->assertStringNotContainsString('[working]', LiveRenderer::render($chat));

        $subAgent = $manager->createSubAgent('reviewer', 'review the diff');

        try {
            $frame = LiveRenderer::render($chat);
            $this->assertStringContainsString('[working]', $frame);
            $this->assertStringContainsString('reviewer', $frame);
        } finally {
            $manager->removeSubAgent($subAgent->id);
        }
    }

    /**
     * The dashboard row the shell's `Pane::Agents` draws is sourced from the
     * launched manager, and its body carries the live-output seam.
     *
     * `AgentManager::liveOutput()` is the public "current live output buffer"
     * accessor `Renderer.php`'s own docblock recorded as the missing
     * prerequisite for the split-pane compositor and the per-agent output pane.
     * Before it existed this row's `outputBuffer` was necessarily `[]` — a
     * delegating agent's row was a header with no body while a background
     * session's row showed a live tail — so the last assertion fails against
     * the pre-item code even if the manager itself were wired.
     */
    public function testTheShellsDashboardRendersALiveRowFromTheManager(): void
    {
        $app = self::$app;
        $this->assertInstanceOf(App::class, $app);
        $manager = $this->manager();

        $this->assertSame([], AgentDashboardPane::entries($app), 'no work delegated yet');

        $subAgent = $manager->createSubAgent('debugger', 'trace it');
        $subAgent->output = "checking stack\nfound the frame";

        try {
            $rows = AgentDashboardPane::entries($app);
            $this->assertCount(1, $rows);
            $this->assertSame('debugger', $rows[0]->name);
            $this->assertSame('working', $rows[0]->status);
            $this->assertSame(['checking stack', 'found the frame'], $rows[0]->outputBuffer);
        } finally {
            $manager->removeSubAgent($subAgent->id);
        }
    }

    /**
     * The seam observes a buffer AS IT GROWS, not only a settled result —
     * otherwise it is a completion accessor with a misleading name — and
     * `liveOutputs()` reports the multi-agent shape the split-pane compositor
     * needs, with silent agents omitted so it cannot lay out empty tiles.
     */
    public function testTheLiveOutputSeamObservesABufferWhileItIsStillBeingProduced(): void
    {
        $manager = $this->manager();

        $coder = $manager->createSubAgent('coder', 'write a function');
        $tester = $manager->createSubAgent('tester', 'write the tests');

        try {
            $this->assertSame('', $manager->liveOutput('coder'));

            // What executeSubAgent()'s streaming loop does per chunk, done here
            // without a network provider: append, and observe between appends.
            $coder->output .= "first chunk\n";
            $this->assertSame("first chunk\n", $manager->liveOutput('coder'));

            $coder->output .= 'second chunk';
            $this->assertSame("first chunk\nsecond chunk", $manager->liveOutput('coder'));
            $this->assertTrue($manager->isWorking('coder'), 'still mid-flight, which is the point of the accessor');

            // The silent 'tester' is omitted rather than reported as an empty tile.
            $this->assertSame(['coder' => "first chunk\nsecond chunk"], $manager->liveOutputs());
        } finally {
            $manager->removeSubAgent($coder->id);
            $manager->removeSubAgent($tester->id);
        }
    }

    /**
     * On-disk presets reach the launched roster from both search paths, the
     * project copy wins a name collision (a repo's checked-in definition is the
     * more specific one — the order {@see \SugarCraft\Crush\Skills\SkillLoader}
     * also used for skills until audit 15d-03(b) put the user tier first there),
     * and a preset
     * named after a built-in REPLACES it rather than adding a duplicate row to
     * `/agents`.
     *
     * Fails against unwired code twice over: with a null manager there is no
     * roster at all, and with the built-ins alone every name here is absent.
     */
    public function testOnDiskPresetsReachTheLaunchedRosterWithProjectWinning(): void
    {
        $manager = $this->manager();

        $this->assertSame('Writes the docs', $manager->get('docs-writer')?->description);
        $this->assertSame('Enforces house style', $manager->get('house-style')?->description);
        $this->assertSame('The project copy', $manager->get('shared')?->description);

        $reviewers = array_filter($manager->all(), static fn($agent) => $agent->name === 'reviewer');
        $this->assertCount(1, $reviewers, 'a preset must replace the built-in, not duplicate it');
        $this->assertSame('Our own reviewer', array_values($reviewers)[0]->description);
    }

    /**
     * A hand-authored preset with broken frontmatter must not be able to stop
     * the binary from starting. `AgentPresetRegistry::list()` used to throw on
     * the first unparseable file; it now skips that file and names it in
     * `skippedFiles()` (audit AG-2 — see AgentPresetSkipMalformedFileTest), so
     * the broken preset is absent while the launch and the built-in roster
     * survive either way.
     */
    public function testAMalformedPresetDegradesToTheBuiltInsInsteadOfKillingTheLaunch(): void
    {
        $chat = self::$brokenChat;
        $this->assertInstanceOf(Chat::class, $chat);

        $manager = $chat->agentManager();
        $this->assertNotNull($manager, 'the launch must have survived the malformed preset');
        $this->assertNotNull($manager->get('coder'), 'the built-in roster must survive a malformed preset');
        $this->assertNull($manager->get('broken'));

        // The USER tier survives the project tier's broken file. This line
        // asserted `[]` while one bad `.md` anywhere emptied every tier — the
        // AG-2 defect itself, pinned as the expected answer.
        $this->assertNotNull($manager->get('house-style'), "the user's own presets must survive a broken project file");
        $presets = Bootstrap::agentPresets(self::$brokenRepo);
        ksort($presets);
        $this->assertSame(['house-style', 'shared'], array_keys($presets));
        $this->assertSame('The user copy', $presets['shared']->description);
    }

    /**
     * Naming the preset directory must not CREATE it — the same property
     * {@see Bootstrap::userConfigPath()} documents for the config file. A
     * process that only ever reads should leave nothing behind, and the read
     * side of {@see Bootstrap::configDirPath()} is what this item had to keep
     * intact.
     */
    public function testListingPresetsForAnUnknownRootCreatesNothing(): void
    {
        $unseen = self::$tempDir . '/never_launched';
        mkdir($unseen, 0755, true);

        // The user-global half of the search path still resolves (this class
        // seeded it), which is what makes the assertion meaningful: the call
        // did real work and STILL created no project directory.
        $this->assertSame(['house-style', 'shared'], array_keys(Bootstrap::agentPresets($unseen)));
        $this->assertDirectoryDoesNotExist($unseen . '/.sugar-crush');
    }

    /**
     * The keystroke paths a user actually reaches, driven through
     * `Chat::update()` exactly as the live event loop does: a typed
     * `/agent <name>`, a typed `/agents`, and the Ctrl+A shortcut R20 added —
     * the one an unsuspecting user hits by accident, and the path that used to
     * crash the CLI outright before it was made to degrade.
     *
     * Against unwired code all three answered "Agent manager not configured".
     */
    public function testTheAgentsKeystrokePathsAnswerFromTheRealRoster(): void
    {
        $chat = $this->chat();

        $detail = $this->submit($chat, '/agent debugger');
        $this->assertStringNotContainsString('Agent manager not configured', $detail);
        $this->assertStringContainsString('Agent: debugger', $detail);
        $this->assertStringContainsString('Bug investigation and fixing', $detail);

        $typed = $this->submit($chat, '/agents');
        $this->assertStringContainsString('agent(s) registered and idle', $typed);
        // The two lines the user reads have to agree: the reply used to open
        // "No active agents configured." and then immediately report N agents
        // registered, which reads as a contradiction on a launch whose roster
        // is fully wired.
        $this->assertStringContainsString('No agents are working right now', $typed);
        $this->assertStringNotContainsString('No active agents configured', $typed);

        [$next, ] = $chat->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));
        $chorded = $next->history[array_key_last($next->history)]->content;
        $this->assertStringContainsString('agent(s) registered and idle', $chorded);
        $this->assertStringNotContainsString('No active agents configured', $chorded);
    }

    /**
     * Every agent's environment block reports THAT agent's model, not the
     * session's.
     *
     * `Bootstrap::agentManager()` used to capture one `EnvironmentBlock` at the
     * session model and attach the same instance to every registration, so a
     * preset declaring `model: gpt-5-turbo` was handed a system prompt whose
     * `<env>` block said `Model: echo` — the agent was told it was running as
     * something it is not, which is exactly the orientation the block exists
     * to provide.
     *
     * Fails if the fix is reverted: with one shared block the `fancy` agent's
     * prompt reports the session model (`Model: echo`) while `$agent->model`
     * still reads `gpt-5-turbo`, so the first assertion pair contradicts and
     * the `assertSame` on the rendered line fails.
     */
    public function testEachAgentsEnvironmentBlockReportsThatAgentsOwnModel(): void
    {
        $manager = $this->manager();

        $fancy = $manager->get('fancy');
        $this->assertNotNull($fancy);
        $this->assertSame('gpt-5-turbo', $fancy->model, 'the preset declares its own model');
        $this->assertSame('Model: gpt-5-turbo', self::modelLine($fancy->systemPrompt()));

        // The inheriting half of the roster still reports the session model,
        // so this is per-agent accuracy rather than a blanket change.
        $coder = $manager->get('coder');
        $this->assertNotNull($coder);
        $this->assertSame('Model: echo', self::modelLine($coder->systemPrompt()));
    }

    /**
     * A preset's markdown BODY is its prompt — the Claude Code / opencode
     * convention — and it must reach the registered agent.
     *
     * `AgentPresetRegistry::parsePresetFile()` never read anything after the
     * frontmatter, so `Agent::fromPreset()`'s `$preset->initialPrompt ?? ''`
     * registered every body-authored preset with an EMPTY prompt: the agent
     * arrived carrying nothing but an env block.
     *
     * Fails if the fix is reverted: the prompt is `''`, so both the
     * `assertStringContainsString` on the roster prompt and the ordering
     * assertion below it fail.
     */
    public function testAPresetsMarkdownBodyReachesTheRegisteredAgentsPrompt(): void
    {
        $agent = $this->manager()->get('docs-writer');
        $this->assertNotNull($agent);

        $this->assertSame('Body prose.', $agent->prompt);

        // systemPrompt() is prompt-then-environment, so the body has to land
        // ahead of the <env> block rather than replacing it.
        $prompt = $agent->systemPrompt();
        $this->assertStringStartsWith("Body prose.\n\n<env>", $prompt);
    }

    /** The single `Model: …` line an EnvironmentBlock renders into a prompt. */
    private static function modelLine(string $prompt): string
    {
        preg_match('/^Model: .*$/m', $prompt, $matches);

        return $matches[0] ?? '';
    }

    /**
     * With work in flight, `/agents` lists the working agent rather than the
     * idle-count fallback — the branch `AgentsCommand::listAgents()` was
     * written for and that no CLI user could reach.
     */
    public function testAgentsListsAWorkingAgentOnTheLaunchedChat(): void
    {
        $manager = $this->manager();
        $subAgent = $manager->createSubAgent('tester', 'write the tests');

        try {
            $reply = $this->submit($this->chat(), '/agents');

            $this->assertStringContainsString('Active Agents', $reply);
            $this->assertStringContainsString('tester', $reply);
        } finally {
            $manager->removeSubAgent($subAgent->id);
        }
    }

    /**
     * The last link the in-process tests cannot execute: that `bin/sugarcrush`
     * builds its root model with `Bootstrap::app()`. The script ends in
     * `Program::run()`, which attaches to a real TTY and blocks, so the chain
     * bin -> app -> chat -> agentManager is closed by reading the one line
     * above that call rather than by running it.
     */
    public function testTheBinaryHandsProgramTheShellThatCarriesTheManager(): void
    {
        $bin = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/sugarcrush');

        $this->assertMatchesRegularExpression(
            '/new Program\(\s*Bootstrap::app\(/',
            $bin,
            'bin/sugarcrush must build its root model through Bootstrap::app(), or nothing wired into '
                . 'Bootstrap::chat() reaches a real run',
        );
    }

    /**
     * crush_code.md issue #49: a sub-agent used to be told the PROCESS working
     * directory, because `Agent::systemPrompt()`'s last-resort
     * `EnvironmentBlock::capture(getcwd())` was the only path any caller took.
     * Bootstrap holds the session's root, so it now hands every registered
     * agent a snapshot captured there — `sugarcrush --root candy-shine`
     * orients its sub-agents at candy-shine.
     *
     * Deliberately asserts the ABSENCE of the process cwd too: a passing
     * "contains $root" alone would be satisfiable by a run whose cwd happened
     * to be the root. Read off the launched roster, so it is the production
     * wiring under test rather than a hand-built Agent.
     */
    public function testSubAgentPromptsAreOrientedAtTheConfiguredRootNotTheProcessDirectory(): void
    {
        $agent = $this->manager()->get('architect');
        $this->assertNotNull($agent);

        $prompt = $agent->systemPrompt();

        $this->assertStringContainsString('Working directory: ' . self::$repo, $prompt);
        $this->assertStringNotContainsString('Working directory: ' . getcwd(), $prompt);
    }

    /**
     * The two helpers this item extracted, without the cost of a launch.
     *
     * {@see Bootstrap::provider()} is what makes the manager and the shell's
     * status-bar label name ONE provider selection; with nothing configured it
     * degrades to the offline Echo provider the same way
     * {@see Bootstrap::backend()} does, rather than refusing to launch.
     * {@see Bootstrap::agentRoster()} must stamp the caller's provider/model
     * onto every agent rather than leaving `AgentDefinition`'s template blanks.
     */
    public function testTheRosterHelpersCarryTheRunsProviderAndModel(): void
    {
        [$provider, $model] = Bootstrap::provider();
        $this->assertInstanceOf(EchoProvider::class, $provider);
        $this->assertSame('echo', $model);

        $roster = Bootstrap::agentRoster(self::$repo, 'openai', 'gpt-4o');
        $this->assertNotSame([], $roster);
        foreach ($roster as $agent) {
            $this->assertSame('openai', $agent->provider);
            $this->assertFalse($agent->isActive);

            // A preset that names its own model keeps it; everything else
            // inherits the run's (AgentDefinition templates carry no model,
            // and AgentPreset's default is the literal 'inherit').
            $this->assertSame(
                $agent->name === 'fancy' ? 'gpt-5-turbo' : 'gpt-4o',
                $agent->model,
            );
        }
    }

    // =========================================================================
    // P7.S5 — a body-less native preset inherits the prompt it shadowed
    // =========================================================================

    /**
     * THE HEADLINE, RUN AGAINST THE REAL BUNDLE THIS REPOSITORY SHIPS.
     *
     * `.sugar-crush/agents/{coder,reviewer,security-auditor}.md` are frontmatter
     * only, so before this merge the first two arrived at a delegated sub-agent
     * with an EMPTY prompt — the built-in instructions were overwritten by a
     * preset that never said anything about instructions. No test had ever
     * loaded that bundle; every preset test in the tree writes its own fixture.
     *
     * Reads the built-in text off `AgentDefinition` at runtime rather than
     * retyping a paragraph that a reworded definition would silently outlive.
     *
     * Fails if the inherit branch is reverted: both prompts come back as ''.
     */
    public function testTheRealPresetsThatDeclareNoPromptInheritTheirBuiltInInstructions(): void
    {
        $agents = self::rosterFromRealBundle();

        $coder = self::named($agents, 'coder');
        $reviewer = self::named($agents, 'reviewer');

        $this->assertSame(
            self::attributed(AgentDefinition::fromType('coder', 'coder')->prompt, 'coder'),
            $coder->prompt,
        );
        $this->assertSame(
            self::attributed(AgentDefinition::fromType('reviewer', 'reviewer')->prompt, 'reviewer'),
            $reviewer->prompt,
        );

        // Exactly once, not twice: the merge appends, so a second application
        // over the same tree is the failure mode this pins.
        $this->assertSame(1, substr_count($coder->prompt, self::ATTRIBUTION_BUILT_IN));
        $this->assertSame(1, substr_count($reviewer->prompt, self::ATTRIBUTION_BUILT_IN));

        // And the frontmatter still supplies what it alone can supply. `AgentDefinition`
        // declares neither a colour nor an isolation mode, so both of these can only
        // have arrived from `.sugar-crush/agents/reviewer.md` -- which is the proof
        // that the merge replaced the prompt and nothing else.
        $this->assertSame(Isolation::Worktree, $reviewer->isolation);
        $this->assertSame('green', $reviewer->color);
        $this->assertSame('sonnet', $reviewer->model);
    }

    /**
     * `security-auditor` has no built-in twin, so there is nothing to inherit.
     * It keeps the empty prompt it has always had, and this pins that state
     * AS-IS rather than pretending it is a fix: authoring instructions for it is
     * a content decision that belongs to the user, not to this step.
     *
     * The launch-notice delta is asserted EMPTY on purpose. The recorded reason
     * for the absent prompt is deferred to P7.S5b because the transcript seam is
     * census-pinned; when that step lands the notice, THIS is the assertion it
     * must update, and a test that quietly allowed a notice today would let the
     * deferral disappear unnoticed.
     */
    public function testATwinlessBodylessPresetCarriesNoPromptAndRaisesNothingYet(): void
    {
        $this->assertNull(AgentDefinition::fromType('security-auditor', 'security-auditor'));

        // `$launchNotices` is process-global and this class's own broken-preset
        // fixture has already written to it, so the honest assertion is a DELTA
        // around the roster call rather than emptiness.
        $before = Bootstrap::launchNotices();
        $auditor = self::named(self::rosterFromRealBundle(), 'security-auditor');

        $this->assertSame('', $auditor->prompt);
        $this->assertStringNotContainsString(self::ATTRIBUTION_BUILT_IN, $auditor->prompt);
        $this->assertSame($before, Bootstrap::launchNotices());
    }

    /**
     * The same inherit on a synthetic root, isolating WHICH TIER supplied WHAT:
     * the prompt comes from below, the frontmatter from above, and a distinctive
     * colour the built-in coder does not have makes the direction undeniable.
     */
    public function testABodylessPresetInheritsTheBuiltInPromptAndKeepsItsOwnFrontmatter(): void
    {
        $root = self::makeRoot('inherit');
        self::writeBodylessPreset($root, 'coder', ["color: \"#123456\"", 'model: gpt-why']);

        $coder = self::named(self::roster($root), 'coder');

        $this->assertSame(
            self::attributed(AgentDefinition::fromType('coder', 'coder')->prompt, 'coder'),
            $coder->prompt,
        );
        $this->assertSame('#123456', $coder->color);
        $this->assertSame('gpt-why', $coder->model, 'the preset still chooses the model');
        $this->assertSame(1, substr_count($coder->prompt, self::ATTRIBUTION_BUILT_IN));
    }

    /**
     * The imported tier's wording. A native body-less preset shadowing an
     * IMPORTED agent of the same name must attribute the text to the imported
     * definition, because saying "built-in" about a `.opencode/agents` file
     * would be a false statement inside the agent's own instructions.
     *
     * Also pins the layering the merge must not disturb: foreign < built-in <
     * native, so a name that exists in the foreign tree and the native tree but
     * has no built-in twin still resolves through the foreign entry.
     */
    public function testABodylessPresetShadowingAnImportedAgentAttributesTheImportedTier(): void
    {
        $root = self::makeRoot('foreign');
        mkdir($root . '/.opencode/agents', 0755, true);
        file_put_contents(
            $root . '/.opencode/agents/zorg.md',
            "---\nname: zorg\ndescription: Imported on purpose\n---\n\nZorg instructions verbatim.\n",
        );
        self::writeBodylessPreset($root, 'zorg');

        $zorg = self::named(self::roster($root), 'zorg');

        $this->assertStringStartsWith('Zorg instructions verbatim.', $zorg->prompt);
        $this->assertSame(
            self::attributed('Zorg instructions verbatim.', 'zorg', false),
            $zorg->prompt,
        );
        $this->assertSame(1, substr_count($zorg->prompt, self::ATTRIBUTION_IMPORTED));
        $this->assertStringNotContainsString(self::ATTRIBUTION_BUILT_IN, $zorg->prompt);
    }

    /**
     * The two non-effect polarities. A preset that DOES supply a prompt -- by
     * body or by `initialPrompt:` -- is a preset that said something, and the
     * merge must not edit what it said or imply that it was silent.
     */
    public function testAPresetThatSuppliesItsOwnPromptIsLeftByteForByteAlone(): void
    {
        $root = self::makeRoot('silent');
        self::writeBodylessPreset($root, 'solo', ['color: teal']);
        self::writePreset($root . '/.sugar-crush/agents', 'docent', 'Has a body');
        file_put_contents(
            $root . '/.sugar-crush/agents/declared.md',
            "---\nname: declared\ndescription: Declares one\n"
                . "initialPrompt: Declared verbatim.\n---\n\nIgnored body.\n",
        );

        $agents = self::roster($root);

        // Body-less AND twinless: nothing to inherit, so nothing is invented.
        $this->assertSame('', self::named($agents, 'solo')->prompt);
        $this->assertSame('Body prose.', self::named($agents, 'docent')->prompt);
        $this->assertSame('Declared verbatim.', self::named($agents, 'declared')->prompt);
        foreach (['solo', 'docent', 'declared'] as $untouched) {
            $this->assertStringNotContainsString(
                self::ATTRIBUTION_BUILT_IN,
                self::named($agents, $untouched)->prompt,
            );
        }
    }

    /**
     * THE TWIN-PATH SKIP, which the byte-for-byte test above proves only for
     * TWINLESS bodies. Here the preset names a real built-in -- `coder` carries a
     * prompt in `AgentDefinition` -- AND supplies its own body, so the merge
     * meets a preset that said something about a definition that also said
     * something, and must leave the body byte-identical with the attribution
     * nowhere in sight. Reverting the `initialPrompt === null` guard onto this
     * path would append the sentence, and this reds.
     */
    public function testABodyBearingPresetWithABuiltInTwinKeepsItsOwnPromptByteForByte(): void
    {
        // Prove first that `coder` really is a twin: were it ever to stop being a
        // built-in, the assertion below would pass vacuously as a twinless body
        // and quietly stop guarding the very path this test exists to cover.
        $this->assertNotSame('', AgentDefinition::fromType('coder', 'coder')->prompt);

        $root = self::makeRoot('body-twin');
        self::writePreset($root . '/.sugar-crush/agents', 'coder', 'Supplies its own body');

        $coder = self::named(self::roster($root), 'coder');

        // The body wins whole: 'Body prose.' is exactly what writePreset writes,
        // with nothing inherited from the built-in and nothing appended.
        $this->assertSame('Body prose.', $coder->prompt);
        $this->assertStringNotContainsString(self::ATTRIBUTION_BUILT_IN, $coder->prompt);
        $this->assertStringNotContainsString(self::ATTRIBUTION_IMPORTED, $coder->prompt);
    }

    /**
     * IDEMPOTENCE. `agentRoster()` is called more than once per process --
     * `agentManager()` and the roster helpers each resolve it -- so a merge that
     * mutated anything durable would stack a second attribution sentence on the
     * first. Two consecutive calls over one root must answer byte-identically.
     *
     * Full-list equality rather than the two prompts alone, because it also
     * catches the roster GROWING, which is how a leaked static would show up.
     */
    public function testTwoRosterBuildsOverOneRootAnswerByteIdentically(): void
    {
        $root = self::makeRoot('idem');
        self::writeBodylessPreset($root, 'coder');
        self::writeBodylessPreset($root, 'reviewer', ['color: purple']);

        $first = self::roster($root);
        $second = self::roster($root);

        $this->assertSame(
            array_map(static fn($agent) => $agent->name, $first),
            array_map(static fn($agent) => $agent->name, $second),
        );
        $this->assertSame(
            array_map(static fn($agent) => $agent->prompt, $first),
            array_map(static fn($agent) => $agent->prompt, $second),
        );
        $this->assertSame(1, substr_count(self::named($second, 'reviewer')->prompt, self::ATTRIBUTION_BUILT_IN));
    }

    /**
     * Roster integrity around the merge: one row per name, and the merge may
     * neither add nor lose an entry. The count is derived from a twin run over
     * the same root with no presets at all, never hard-coded, so the assertion
     * keeps its meaning on a machine whose user tier holds its own presets.
     *
     * The last clause is the precedence the merge must not disturb -- a project
     * `coder.md` still wins over an imported one, and here it wins by inheriting
     * the BUILT-IN text rather than the foreign text, because the built-in loop
     * inserts above the foreign one.
     */
    public function testTheMergeAddsNoRowAndNeverLetsAnImportShadowTheBuiltInItReplaces(): void
    {
        $root = self::makeRoot('roster');
        mkdir($root . '/.opencode/agents', 0755, true);
        file_put_contents(
            $root . '/.opencode/agents/coder.md',
            "---\nname: coder\ndescription: Foreign coder\n---\n\nForeign coder text.\n",
        );
        self::writeBodylessPreset($root, 'coder');
        self::writeBodylessPreset($root, 'newcomer');

        $agents = self::roster($root);
        $names = array_map(static fn($agent) => $agent->name, $agents);

        $this->assertSame(\count($names), \count(array_unique($names)), 'a name appeared twice');
        $this->assertContains('coder', $names);

        $bare = self::roster(self::makeRoot('roster-bare'));
        // One new row, not two: `coder` shadows the built-in of the same name and
        // `newcomer` is genuinely additive.
        $this->assertSame(\count($bare) + 1, \count($agents));

        $coder = self::named($agents, 'coder');
        $this->assertStringStartsWith(AgentDefinition::fromType('coder', 'coder')->prompt, $coder->prompt);
        $this->assertStringNotContainsString('Foreign coder text.', $coder->prompt);
    }

    /**
     * The sentence itself, pinned as fixed bytes for BOTH tiers and including
     * the two edges of the helper: an empty inherited prompt returns its input
     * unchanged, and the join is one blank line rather than two.
     */
    public function testTheAttributionSentenceIsRenderedFromTheConstForBothTiers(): void
    {
        $this->assertSame(
            'Body of instructions.' . "\n\n"
                . "Your operating instructions were supplied by the built-in agent definition 'coder'; "
                . 'this preset contributed only its frontmatter settings.',
            self::attributed('Body of instructions.', 'coder'),
        );
        $this->assertSame(
            'Body of instructions.' . "\n\n"
                . "Your operating instructions were supplied by the imported agent definition 'zorg'; "
                . 'this preset contributed only its frontmatter settings.',
            self::attributed('Body of instructions.', 'zorg', false),
        );

        $this->assertSame('', self::attributed('', 'coder'));
        $this->assertStringNotContainsString("\n\n\n", self::attributed('Body of instructions.', 'coder'));
    }

    /**
     * `presetCarryingPrompt()` copies a preset by spreading its own public
     * properties, so a field added to that class travels by construction rather
     * than by someone remembering to extend a list. That is the claim, and this
     * is what makes it unfalsifiable-by-accident: it compares the merged agent
     * with the agent the SAME preset produced unmerged, field by field off the
     * object, so there is no field list here either. Delete the spread, name the
     * fields, drop one, and this reddens on the difference.
     */
    public function testTheInheritMergeCarriesEveryOtherPresetField(): void
    {
        $root = self::makeRoot('fields');
        self::writeBodylessPreset($root, 'reviewer', [
            'color: orange',
            'model: gpt-again',
            'maxTurns: 12',
            'permissionMode: plan',
            'effort: high',
            'skills: [code-review]',
        ]);

        $merged = self::named(self::roster($root), 'reviewer');

        $unmerged = Agent::fromPreset(Bootstrap::agentPresets($root)['reviewer'], 'echo', 'm');

        $differences = [];
        foreach (get_object_vars($merged) as $field => $value) {
            if (!($value === $unmerged->{$field})) {
                $differences[] = $field;
            }
        }

        $this->assertSame(['prompt'], $differences, 'every field but the prompt must survive the merge');
        $this->assertNotSame($unmerged->prompt, $merged->prompt);
    }

    /**
     * C7, restated as an assertion. A preset's `tools:` frontmatter is resolved
     * against a registry the roster never builds, so a prompt claiming a tool
     * grant would be provably false in production. Nothing this merge WRITES may
     * make such a claim; the sentences are about provenance and about nothing
     * the agent can do.
     *
     * The inherited reviewer text says "skills you have been granted" -- that is
     * `AgentDefinition`'s own wording, copied byte-verbatim, and it is a SKILL
     * claim rather than a tool one. This test is deliberately scoped to the
     * attribution sentence plus a grant-shaped scan of the merged prompts, and
     * the skill phrasing is recorded as an observation for the reviewer lane.
     */
    public function testNoMergedPromptAssertsAToolGrant(): void
    {
        $root = self::makeRoot('c7');
        self::writeBodylessPreset($root, 'coder');
        mkdir($root . '/.opencode/agents', 0755, true);
        file_put_contents(
            $root . '/.opencode/agents/zorg.md',
            "---\nname: zorg\ndescription: Imported\n---\n\nZorg text.\n",
        );
        self::writeBodylessPreset($root, 'zorg');

        $checked = 0;
        $violations = [];
        foreach (self::roster($root) as $agent) {
            if (!\in_array($agent->name, ['coder', 'zorg'], true)) {
                continue;
            }

            $checked++;
            if (preg_match('/\b(?:tools?)[^.]{0,40}\bgranted\b|\bgranted[^.]{0,40}\btools?\b/i', $agent->prompt)) {
                $violations[] = $agent->name;
            }
        }

        $this->assertSame(2, $checked, 'both merged agents must have been examined');
        $this->assertSame([], $violations);
        $this->assertStringNotContainsString('tool', self::attributed('x', 'coder'));
    }

    // ---------------------------------------------------------------------
    // P7.S5 fixtures
    // ---------------------------------------------------------------------

    /** The rendered built-in-tier sentence, taken from the production helper. */
    private const ATTRIBUTION_BUILT_IN = 'supplied by the built-in agent definition';

    /** The rendered imported-tier sentence, same source of truth. */
    private const ATTRIBUTION_IMPORTED = 'supplied by the imported agent definition';

    /**
     * The production renderer, called rather than copied: an assertion that
     * retyped the sentence would keep passing after the wording changed, which
     * is the one thing a pin on that sentence must not do.
     */
    private static function attributed(string $inherited, string $definitionName, bool $builtIn = true): string
    {
        $method = new \ReflectionMethod(Bootstrap::class, 'attributeInheritedPrompt');
        $method->setAccessible(true);

        return $method->invoke(null, $inherited, $definitionName, $builtIn);
    }

    /**
     * `Bootstrap::agentRoster()` is what the merge changed, so the tests call it
     * directly. Going through `chat()` instead would pay for a whole launch for
     * no extra truth -- and this class's docblock records that launch-based test
     * files destabilise two forked-completion tests elsewhere in the suite.
     */
    private static function roster(string $root): array
    {
        /** @var list<Agent> $agents */
        return Bootstrap::agentRoster($root, 'echo', 'm');
    }

    /**
     * The roster over THIS CHECKOUT -- the only bundle whose prompt-less state
     * actually matters to a user of the binary.
     *
     * The root is found by walking up from this file rather than hard-coded, so
     * the test still names the right tree from a worktree, a clone or a
     * packaged copy. `$HOME` is already redirected to an empty temp home by
     * `setUpBeforeClass()`, which is what stops whoever runs CI from contributing
     * their own `~/.sugar-crush/agents` to the roster these assertions read.
     */
    private static function rosterFromRealBundle(): array
    {
        for ($path = \dirname(__DIR__, 3); is_string($path) && $path !== ''; $path = \dirname($path)) {
            if (!is_dir($path . '/.sugar-crush/agents')) {
                continue;
            }

            $agents = self::roster($path);

            // The bundle is only a real test of the merge if these three are
            // still the frontmatter-only files the premise describes.
            foreach (['coder', 'reviewer', 'security-auditor'] as $name) {
                self::assertFileExists($path . '/.sugar-crush/agents/' . $name . '.md');
            }

            return $agents;
        }

        self::fail('no checkout root carrying .sugar-crush/agents above ' . \dirname(__DIR__));
    }

    /** @param list<Agent> $agents */
    private static function named(array $agents, string $name): Agent
    {
        foreach ($agents as $agent) {
            if ($agent->name === $name) {
                return $agent;
            }
        }

        self::fail("roster has no agent named {$name}");
    }

    private static function makeRoot(string $label): string
    {
        $root = self::$tempDir . '/' . $label . '-' . uniqid('', true);
        mkdir($root . '/.sugar-crush/agents', 0755, true);

        return $root;
    }

    /**
     * A frontmatter-only preset: the closing fence, a newline, and nothing after
     * it -- exactly the shape of this repository's own three.
     *
     * @param list<string> $extraFrontmatter
     */
    private static function writeBodylessPreset(string $root, string $name, array $extraFrontmatter = []): void
    {
        $frontmatter = "name: {$name}\ndescription: Bodyless on purpose\n";
        foreach ($extraFrontmatter as $line) {
            $frontmatter .= $line . "\n";
        }

        file_put_contents(
            $root . '/.sugar-crush/agents/' . $name . '.md',
            "---\n{$frontmatter}---\n",
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** The Chat the shared shell hosts — the one `Bootstrap::chat()` built. */
    private function chat(): Chat
    {
        $chat = self::$app?->chat;
        $this->assertInstanceOf(Chat::class, $chat, 'Bootstrap::app() must host a Chat');

        return $chat;
    }

    /** The manager that Chat carries, which is the whole point of this item. */
    private function manager(): AgentManager
    {
        $manager = $this->chat()->agentManager();
        $this->assertInstanceOf(
            AgentManager::class,
            $manager,
            'Bootstrap::chat() must hand the Chat a live AgentManager',
        );

        return $manager;
    }

    /** Drive a typed command through the real `Chat::update()` dispatch. */
    private function submit(Chat $chat, string $command): string
    {
        $withInputBuf = new \ReflectionMethod($chat, 'withInputBuf');
        $withInputBuf->setAccessible(true);
        $withBuf = $withInputBuf->invoke($chat, $command);

        [$next, ] = $withBuf->update(new KeyMsg(KeyType::Enter, ''));

        return $next->history[array_key_last($next->history)]->content;
    }

    /**
     * @param string|null $model A `model:` frontmatter key, for the presets
     *                           that declare one of their own; omitted leaves
     *                           the preset on AgentPreset's `inherit` default.
     */
    private static function writePreset(string $dir, string $name, string $description, ?string $model = null): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $frontmatter = "name: {$name}\ndescription: {$description}\n";
        if ($model !== null) {
            $frontmatter .= "model: {$model}\n";
        }

        file_put_contents(
            $dir . '/' . $name . '.md',
            "---\n{$frontmatter}---\n\nBody prose.\n",
        );
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
