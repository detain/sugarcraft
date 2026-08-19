<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Agents\AgentManager;
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
     * project copy wins a name collision (the precedence
     * {@see \SugarCraft\Crush\Skills\SkillLoader} already applies to skills — a
     * repo's checked-in definition is the more specific one), and a preset
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
     * the binary from starting. `AgentPresetRegistry::list()` throws on the
     * first unparseable file, and these files are hand-written — before this
     * wiring that exception had no way to reach a launch, and after it, one bad
     * `.md` in a repo would otherwise be enough to make `bin/sugarcrush`
     * unusable there.
     */
    public function testAMalformedPresetDegradesToTheBuiltInsInsteadOfKillingTheLaunch(): void
    {
        $chat = self::$brokenChat;
        $this->assertInstanceOf(Chat::class, $chat);

        $manager = $chat->agentManager();
        $this->assertNotNull($manager, 'the launch must have survived the malformed preset');
        $this->assertNotNull($manager->get('coder'), 'the built-in roster must survive a malformed preset');
        $this->assertNull($manager->get('broken'));

        $this->assertSame([], Bootstrap::agentPresets(self::$brokenRepo));
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
