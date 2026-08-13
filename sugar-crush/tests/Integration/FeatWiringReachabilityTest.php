<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zone;

/**
 * Reachability tests for crush_feat.md's Executive Summary table of
 * subsystems that were "fully built, fully tested, never wired into the live
 * app". Each test here proves a subsystem is reachable FROM the real
 * `bin/sugarcrush` construction chain — `Bootstrap::app()` /
 * `Bootstrap::chat()` — rather than merely that its class works in isolation,
 * which is exactly the failure mode the audit found: every one of these had
 * green unit tests while being a guaranteed no-op in production.
 *
 * The bin script itself cannot be driven from a test (it ends in
 * `Program::run()`, which attaches to a real TTY and blocks), so the entry
 * point under test is `Bootstrap`, the construction logic that script's IIFE
 * was extracted into — the same reasoning {@see BinSugarcrushWiringTest}
 * documents.
 *
 * Rows covered here (W4.S1a): the session store, session tabs, and background
 * sessions. (W4.S1b): the Skills subsystem. (W4.S1c): candy-mouse/candy-zone.
 * Later sub-steps extend this class with the remaining rows.
 */
final class FeatWiringReachabilityTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_feat_reach_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/repo', 0755, true);

        // Bootstrap resolves its config dir (session.db, memory/, config.json)
        // off $HOME. Isolating it keeps a developer's real ~/.sugar-crush/
        // session list out of the seeding assertions below, which count rows.
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // Row: SessionStore/EnhancedSessionStore + SessionPicker + SessionTabs
    // "SessionStore::createSession() is never called in production —
    //  listSessions() always returns [], so /sessions, the tab strip, and
    //  Ctrl+Tab cycling are all correctly implemented but permanently
    //  unreachable."
    // =========================================================================

    /**
     * The whole row hinges on one claim: no production path ever calls
     * `createSession()`. Asserting the store is non-null is not enough (it
     * always was) — what must hold now is that a *row exists* after a plain
     * launch and that the Chat is pointed at it. Against the pre-W3.S1 code
     * `listSessions()` returned `[]` and `currentSessionId()` was `null` for
     * the process's entire lifetime, so both assertions below failed.
     */
    public function testAPlainLaunchSeedsARealSessionRowAndPointsTheChatAtIt(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $sessionId = $chat->currentSessionId();
        $this->assertNotNull($sessionId, 'Bootstrap::chat() must hand the Chat a live session id');

        $store = $chat->sessionStore();
        $this->assertNotNull($store);
        $this->assertSame(
            [$sessionId],
            array_column($store->listSessions(), 'id'),
            'the seeded id must be a real persisted row, not an in-memory label',
        );
    }

    /**
     * Seeding must RESUME the most recent row rather than create one per
     * launch: a create-always seed would still satisfy the test above while
     * growing the store unboundedly and orphaning every previous run's
     * /rewind checkpoints.
     */
    public function testASecondLaunchResumesTheSeededSessionInsteadOfAddingARow(): void
    {
        $first = Bootstrap::chat($this->tempDir . '/repo');
        $second = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertSame($first->currentSessionId(), $second->currentSessionId());
        $this->assertCount(1, $second->sessionStore()?->listSessions() ?? []);
    }

    /**
     * `bin/sugarcrush` runs `Bootstrap::app()`, not `Bootstrap::chat()`, so
     * the seeded session has to survive the App shell that hosts the Chat —
     * otherwise the pane layer's session-keyed state disagrees with the
     * transcript it is displaying.
     */
    public function testTheAppShellTheBinaryLaunchesCarriesTheSeededSessionId(): void
    {
        $app = Bootstrap::app($this->tempDir . '/repo');

        $this->assertNotNull($app->sessionId);
        $this->assertSame($app->chat?->currentSessionId(), $app->sessionId);
        $this->assertContains(
            $app->sessionId,
            array_column($app->chat?->sessionStore()?->listSessions() ?? [], 'id'),
        );
    }

    // =========================================================================
    // Row: session tabs / Ctrl+Tab cycling
    // "Chat::cycleSessionTab() is correctly implemented and tested but a
    //  guaranteed no-op — and separately, candy-core's InputReader doesn't
    //  yet decode the CSI 1;5I (Ctrl+Tab) sequence most terminals actually
    //  send, so the binding is doubly unreachable."
    // =========================================================================

    /**
     * Both halves of that "doubly unreachable" claim in one chain: the raw
     * bytes a real terminal emits for Ctrl+Tab are fed through the same
     * {@see InputReader} `Program` reads stdin with, and the resulting
     * `KeyMsg` is dispatched into a `Bootstrap`-built `Chat`. Pre-wiring this
     * failed twice over — `CSI 1;5I` decoded to nothing (W3.S2 fixed the
     * decoder), and even a synthesised KeyMsg hit `cycleSessionTab()`'s
     * `currentSessionId === null` early return (W3.S1 fixed the seed).
     */
    public function testRealCtrlTabBytesCycleTheSessionOnABootstrapBuiltChat(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $other = $this->addSecondSession($chat);

        $key = $this->soleKeyMsg("\x1b[1;5I");
        $this->assertSame(KeyType::Tab, $key->type);
        $this->assertTrue($key->ctrl, 'CSI 1;5I must decode as a ctrl-modified Tab');

        [$next] = $chat->update($key);

        $this->assertInstanceOf(Chat::class, $next);
        $this->assertSame($other, $next->currentSessionId());
    }

    /**
     * Ctrl+Shift+Tab (`CSI 1;6I`) is the reverse binding, so a forward step
     * followed by a backward step must land back on the session the run
     * started on — a cycle that only moved forward would pass the test above
     * while making the strip un-navigable in one direction.
     */
    public function testRealCtrlShiftTabBytesCycleBackToTheStartingSession(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $seeded = (string) $chat->currentSessionId();
        $this->addSecondSession($chat);

        $forward = $this->soleKeyMsg("\x1b[1;5I");
        $backward = $this->soleKeyMsg("\x1b[1;6I");
        $this->assertTrue($backward->ctrl && $backward->shift, 'CSI 1;6I must decode as ctrl+shift Tab');

        [$stepped] = $chat->update($forward);
        $this->assertNotSame($seeded, $stepped->currentSessionId());

        [$returned] = $stepped->update($backward);
        $this->assertSame($seeded, $returned->currentSessionId());
    }

    // =========================================================================
    // Row: BackgroundSupervisor/BackgroundSession
    // "Zero live callers anywhere — no /bg command exists to trigger it."
    // =========================================================================

    /**
     * The supervisor owns the IPC table for the sessions it spawns, so the
     * live Chat must carry one instance rather than construct one per
     * command; a null supervisor is what made every `/bg` answer "not
     * configured" on a real run.
     */
    public function testTheLaunchedChatCarriesOneLiveBackgroundSupervisor(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $this->assertInstanceOf(BackgroundSupervisor::class, $chat->backgroundSupervisor());

        // And it survives the App shell the binary actually launches: the
        // hosted Chat is taken whole, so the shell and the transcript share
        // the one supervisor that owns the spawned children's sockets.
        $app = Bootstrap::app($this->tempDir . '/repo');
        $this->assertInstanceOf(BackgroundSupervisor::class, $app->chat?->backgroundSupervisor());
    }

    /**
     * Typing `/bg <task>` into the real launch-time Chat must dispatch onto
     * the supervisor: the turn returns a spawn Cmd and records the command,
     * instead of the "Background sessions not configured" refusal every
     * pre-wiring run produced.
     *
     * The returned Cmd is deliberately NOT invoked — `spawnSession()`
     * double-forks a daemon and blocks up to 5s on a socket accept, which is
     * why the spawn is a Cmd and not inline work in `update()`. That its
     * existence (not its execution) is the assertion is the point: a null Cmd
     * here means nothing was scheduled at all.
     */
    public function testBgCommandOnTheLaunchedChatSchedulesASpawnInsteadOfRefusing(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        [$next, $cmd] = $this->submit($chat, '/bg summarise the audit');

        $this->assertNotNull($cmd, '/bg must schedule a background spawn Cmd');
        $transcript = implode("\n", array_map(static fn($m) => $m->content, $next->history));
        $this->assertStringNotContainsString('Background sessions not configured', $transcript);
        $this->assertStringContainsString('/bg summarise the audit', $transcript);
    }

    /**
     * The refusal branch still has to exist for a Chat built without a
     * supervisor — without this, the test above could pass against a build
     * that had simply deleted the guard rather than wired the supervisor.
     */
    public function testAChatWithNoSupervisorStillRefusesBgExplicitly(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo')->withBackgroundSupervisor(null);

        [$next, $cmd] = $this->submit($chat, '/bg summarise the audit');

        $this->assertNull($cmd);
        $transcript = implode("\n", array_map(static fn($m) => $m->content, $next->history));
        $this->assertStringContainsString('Background sessions not configured', $transcript);
    }

    // =========================================================================
    // Row: Skills subsystem (SkillLoader, SkillRegistry, 12 built-in SKILL.md)
    // "App::availableSkills is never populated in AppBuilder::build() — the
    //  entire skill roster is dormant in a live session. bin/sugarcrush has
    //  zero references to Skill at all."
    // =========================================================================

    /**
     * `App::availableSkills` is not populated where a test can see it directly
     * on a live run: {@see EngineBackend::complete()} builds the `App` per turn
     * from its OWN registry and throws it away, and {@see Bootstrap::app()}'s
     * docblock is explicit that the registry on the shell App is a *display*
     * copy for the Skills pane, not the one the engine reasons with. So the
     * load-bearing assertion is one level up: the registry the launched Chat's
     * backend holds — the single value `complete()` passes to
     * `withAvailableSkills()` — must already carry the on-disk roster.
     *
     * Against the pre-W3.S8 code this was `null`, `complete()` substituted
     * `new SkillRegistry()`, and every skill the model could have used was
     * dormant for the process's whole lifetime.
     */
    public function testTheLaunchedChatsEngineBackendCarriesAPopulatedSkillRegistry(): void
    {
        $this->writeProjectSkill('reach-marker-skill', 'Marker skill for the engine-path reachability test.');

        $registry = $this->engineSkillRegistry();

        $this->assertNotNull(
            $registry->get('reach-marker-skill'),
            'a skill dropped under <root>/.sugar-crush/skills must reach the engine registry',
        );
        $this->assertNotNull(
            $registry->get('security-audit'),
            'the shipped BuiltIn/ roster must reach the engine registry too',
        );
    }

    /**
     * The registry is only half the wire: the model reaches a skill by calling
     * the `Skill` tool, which resolves names against whichever registry it was
     * constructed with. Two independently scanned registries would let a skill
     * disabled in the engine's copy stay invocable through the tool, so the
     * instances must be identical — and the tool must return the real on-disk
     * body, which is the end of the chain the audit found missing entirely
     * ("bin/sugarcrush has zero references to Skill").
     */
    public function testTheEngineSkillToolSharesThatRegistryAndReturnsTheOnDiskBody(): void
    {
        $this->writeProjectSkill(
            'reach-body-skill',
            'Marker skill whose body proves on-demand loading.',
            "# Reach Body\n\nBODY MARKER LINE\n",
        );

        $backend = $this->launchedEngineBackend();
        $tools = $this->privateValue($backend, 'tools');

        $skillTool = null;
        foreach ($tools as $tool) {
            if ($tool instanceof SkillTool) {
                $skillTool = $tool;
            }
        }

        $this->assertInstanceOf(SkillTool::class, $skillTool, 'the engine tool list must ship the Skill tool');
        $this->assertSame(
            $this->privateValue($backend, 'skillRegistry'),
            $this->privateValue($skillTool, 'registry'),
        );

        $result = $skillTool->execute(['id' => 'c1', 'name' => 'reach-body-skill']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('BODY MARKER LINE', $result->content());
    }

    /**
     * W3.S9's `paths:` auto-scoping, proved from the ENGINE's tool list rather
     * than from `Bootstrap::tools()` in isolation ({@see
     * SkillPathScopingWiringTest} covers the latter): the Read instance the
     * live agent loop actually calls must announce a path-scoped skill the
     * first time it opens a matching file. Before the nudge existed,
     * `SkillRegistry::getForPaths()` was correct, tested, and had no
     * production caller at all.
     */
    public function testTheEngineReadToolAnnouncesAPathScopedSkillOnFirstTouch(): void
    {
        $this->writeProjectSkill(
            'reach-paths-skill',
            'Marker skill scoped to PHP files.',
            "# body\n",
            ['*.php'],
        );
        $file = $this->tempDir . '/repo/Touched.php';
        file_put_contents($file, "<?php\n");

        $read = $this->engineTool(Read::class);
        $content = $read->execute(['id' => 'c1', 'file_path' => $file])->content();

        $this->assertStringContainsString('<system-reminder>', $content);
        $this->assertStringContainsString(
            'reach-paths-skill: Marker skill scoped to PHP files.',
            $content,
        );
    }

    /**
     * The nudge is session-scoped, not per-call: Read/Edit/Glob in the engine
     * list share one tracker, and a skill already announced must not be
     * re-announced on the next matching file. A per-tool or per-call tracker
     * would still pass the test above while re-spending context on the same
     * reminder for every file the agent opens in a long session.
     */
    public function testTheEnginesPathNudgeIsSharedAcrossToolsAndFiresOncePerSkill(): void
    {
        $this->writeProjectSkill(
            'reach-once-skill',
            'Marker skill scoped to PHP files.',
            "# body\n",
            ['*.php'],
        );
        $backend = $this->launchedEngineBackend();

        $read = $this->toolOf($backend, Read::class);
        $this->assertSame(
            $this->privateValue($read, 'skillNudge'),
            $this->privateValue($this->toolOf($backend, Edit::class), 'skillNudge'),
            'Read and Edit must share one SkillPathNudge so an announcement on one silences the other',
        );
        $this->assertInstanceOf(SkillPathNudge::class, $this->privateValue($read, 'skillNudge'));

        $first = $this->tempDir . '/repo/First.php';
        $second = $this->tempDir . '/repo/Second.php';
        file_put_contents($first, "<?php\n");
        file_put_contents($second, "<?php\n");

        $firstContent = $read->execute(['id' => 'c1', 'file_path' => $first])->content();
        $secondContent = $read->execute(['id' => 'c2', 'file_path' => $second])->content();

        $this->assertStringContainsString('reach-once-skill', $firstContent);
        $this->assertStringNotContainsString('reach-once-skill', $secondContent);
    }

    // =========================================================================
    // Row: candy-mouse/candy-zone
    // "grep -rln \"Mouse\" src/ returns zero files in sugar-crush. Mouse mode
    //  isn't even turned on (ProgramOptions::$mouseMode defaults Off and is
    //  never overridden)." — crush_feat.md §8 D/E1.
    //
    // The coordinate invariant (pane-local zone boxes rebased against
    // Renderer::zoneOrigin()) is already guarded by tests/Tui/ShellMouseZoneTest
    // against a hand-built App. What is asserted here is the other half: that
    // the App a REAL launch produces turns tracking on and paints clickable
    // zones at all.
    // =========================================================================

    /**
     * `bin/sugarcrush` hands `Chat::programOptions()` to `Program`, so this is
     * literally the value the terminal's tracking mode is set from. Against
     * the pre-wiring code the entrypoint constructed `new ProgramOptions(...)`
     * without a `mouseMode`, leaving candy-core's `MouseMode::Off` default —
     * asserted alongside so the test fails if the wiring ever regresses back
     * to "whatever ProgramOptions defaults to".
     */
    public function testTheOptionsTheBinaryStartsTheProgramWithTurnMouseTrackingOn(): void
    {
        $options = Chat::programOptions();

        $this->assertSame(MouseMode::CellMotion, $options->mouseMode);
        $this->assertNotSame(
            (new ProgramOptions())->mouseMode,
            $options->mouseMode,
            'mouse mode must be overridden, not inherited from the Off default',
        );
        $this->assertTrue($options->useAltScreen);
    }

    /**
     * Reachability end to end: the root Model `bin/sugarcrush` runs is
     * `Bootstrap::app()`, and its first frame has to leave a populated
     * hit-test registry behind — the chain being
     * App::view() → Tui\Renderer::renderView() → ChatPane::renderView() →
     * live Renderer::renderView() → Renderer::scanRoot().
     *
     * `pane:menu` is the zone a plain single-session launch paints (session
     * tabs need a second session on disk, tool rows need a tool call), so it
     * is the one that proves a *default* launch is clickable rather than only
     * an elaborately staged one.
     *
     * The markers themselves must not survive into the painted frame: they
     * are Private-Use codepoints a terminal renders as replacement glyphs and
     * candy-core's line diff counts as content.
     */
    public function testAPlainLaunchsFirstFrameRegistersClickZonesAndPaintsNoMarkers(): void
    {
        try {
            $body = $this->bootFrame();

            $this->assertNotSame(
                [],
                LiveRenderer::scanner()->all(),
                'a real launch frame must leave click zones behind for the hit test',
            );
            $this->assertInstanceOf(
                Zone::class,
                LiveRenderer::scanner()->get(LiveRenderer::PANE_ZONE_PREFIX . Pane::Menu->value),
            );
            $this->assertStringNotContainsString(Sentinel::OPEN, $body);
            $this->assertStringNotContainsString(Sentinel::CLOSE, $body);
        } finally {
            LiveRenderer::clearZones();
        }
    }

    /**
     * The registry existing is not the same as a click landing in it: the
     * shell composes the chat pane inset behind a sidebar and below a menu
     * bar, so a zone recorded pane-locally is only reachable if the boot path
     * also declares the pane's origin. Asserting on the cell the launch
     * actually PAINTED the affordance on — rather than on the recorded box —
     * is what makes this a reachability test and not a restatement of the
     * coordinate arithmetic.
     */
    public function testAClickWhereARealLaunchPaintsTheMenuHintResolvesToThatZone(): void
    {
        try {
            [$col, $row] = $this->locate($this->bootFrame(), 'Ctrl+P menu');

            $this->assertSame(
                LiveRenderer::PANE_ZONE_PREFIX . Pane::Menu->value,
                Chat::zoneAt($col, $row)?->id,
            );
        } finally {
            LiveRenderer::clearZones();
        }
    }

    /**
     * §8's most-repeated cross-tool complaint: while SGR tracking is active
     * the terminal stops offering copy-on-select, so the opt-out has to reach
     * the protocol itself, not just the hit test. Both halves are asserted —
     * tracking off AND no zones marked, since marking would cost the ~24ms
     * grapheme scan per frame for boxes nothing can ever click.
     */
    public function testDisableMouseTurnsTrackingOffForARealLaunch(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE=1');

        try {
            $this->assertSame(MouseMode::Off, Chat::programOptions()->mouseMode);
            $this->bootFrame();
            $this->assertSame([], LiveRenderer::scanner()->all());
        } finally {
            putenv('SUGARCRUSH_DISABLE_MOUSE');
            LiveRenderer::clearZones();
        }
    }

    /**
     * The "keep scroll, drop clicks" escape hatch (§8 B). Wheel events are
     * reported over the same tracking mode as clicks, so this one must NOT
     * touch the mode — a launch that dropped to `Off` here would take
     * wheel-scroll down with it, which is the exact regression the split
     * flag exists to avoid.
     */
    public function testDisableMouseClicksKeepsTrackingOnButRegistersNoZones(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');

        try {
            $this->assertSame(MouseMode::CellMotion, Chat::programOptions()->mouseMode);
            $this->bootFrame();
            $this->assertSame([], LiveRenderer::scanner()->all());
        } finally {
            putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
            LiveRenderer::clearZones();
        }
    }

    // =========================================================================
    // Row: InstructionFileLoader::loadRoot()/loadForced() + the environment block
    // "Never called — root CLAUDE.md/AGENTS.md never auto-load into the system
    //  prompt. Only nested on-touch loading (loadForPath()) is actually
    //  wired." (crush_feat.md §6 D, gap items 1, 2 and 4.)
    // =========================================================================

    /**
     * The headline claim of the row: a repo-root AGENTS.md has "zero effect
     * unless the agent happens to Read/Glob/Edit a file in that exact
     * directory". Asserted on the system prompt a provider is handed by the
     * backend `Bootstrap::chat()` built — not by a hand-assembled one, which
     * is what {@see SystemPromptWiringTest} covers. Against the pre-wave code
     * `Bootstrap::backend()` attached no loader and `buildSystemPrompt()` had
     * no `loadRoot()` call, so the marker below could not appear.
     */
    public function testARealLaunchsBackendFeedsRootAgentsMdIntoTheSystemPrompt(): void
    {
        file_put_contents($this->tempDir . '/repo/AGENTS.md', 'LAUNCHED ROOT AGENTS MARKER');

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringContainsString('LAUNCHED ROOT AGENTS MARKER', $prompt);
        $this->assertStringContainsString('<project-instructions>', $prompt);
    }

    /**
     * Gap item 4 — the environment half, which "does not exist anywhere"
     * before this wave. The `<env>` block must also precede the project
     * instructions: conventions talk about paths relative to a cwd the model
     * has to have been told about first.
     */
    public function testARealLaunchDeliversTheEnvironmentBlockAheadOfProjectInstructions(): void
    {
        file_put_contents($this->tempDir . '/repo/AGENTS.md', 'ENV ORDER MARKER');

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('Working directory: ', $prompt);
        $this->assertStringContainsString('Platform: ', $prompt);
        $this->assertStringContainsString('Current date: ', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<project-instructions>'),
            strpos($prompt, '<env>'),
            'the environment snapshot must reach the model before project conventions',
        );
    }

    /**
     * Gap item 5: this repo's own root CLAUDE.md uses `@./AGENTS.md`, so an
     * unexpanded import would reach a real launch as literal dead text. The
     * expansion has to happen inside the loader `Bootstrap` builds, not in a
     * separately-constructed one.
     */
    public function testARealLaunchExpandsAtImportsInsideRootClaudeMd(): void
    {
        file_put_contents($this->tempDir . '/repo/CLAUDE.md', "# Root\n@./AGENTS.md\n");
        file_put_contents($this->tempDir . '/repo/AGENTS.md', 'LAUNCHED IMPORT BODY MARKER');

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringContainsString('LAUNCHED IMPORT BODY MARKER', $prompt);
        $this->assertStringNotContainsString('@./AGENTS.md', $prompt);
    }

    /**
     * Gap item 2, end to end: `loadForced()` stayed dead not only because
     * nothing called it but because nothing ever passed it a pattern. The
     * chain proven here is config-file -> `Bootstrap::forcedInstructions()`
     * -> the loader `Bootstrap::backend()` attaches -> the system prompt, so
     * a break at any link fails this test.
     */
    public function testConfiguredForcedInstructionGlobsReachARealLaunchsSystemPrompt(): void
    {
        mkdir($this->tempDir . '/repo/docs', 0755, true);
        file_put_contents($this->tempDir . '/repo/docs/conventions.md', 'FORCED GLOB MARKER');
        Bootstrap::writeUserConfig(['instructions' => ['docs/*.md']]);

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringContainsString('FORCED GLOB MARKER', $prompt);
    }

    /**
     * An absolute forced pattern must not be honoured even when it arrives
     * from the user's own config file: `loadForced()`'s containment guard is
     * the only thing standing between a hand-edited config and arbitrary
     * host files being pasted into every system prompt.
     */
    public function testAnAbsoluteForcedPatternIsRefusedOnARealLaunch(): void
    {
        $outside = $this->tempDir . '/outside.md';
        file_put_contents($outside, 'OUTSIDE THE REPO MARKER');
        Bootstrap::writeUserConfig(['instructions' => [$outside]]);

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringNotContainsString('OUTSIDE THE REPO MARKER', $prompt);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * The painted body of the first frame of a real launch — `Bootstrap::app()`
     * is the root Model `bin/sugarcrush` hands to `Program`, and `view()` is
     * what `Program` would call on it.
     */
    private function bootFrame(): string
    {
        $view = Bootstrap::app($this->tempDir . '/repo')->view();

        return $view instanceof View ? $view->body : $view;
    }

    /**
     * The 1-based display column and row of $needle in a rendered frame — the
     * coordinate space an SGR mouse report uses. SGR runs are stripped and
     * wide glyphs measured rather than counted as bytes, so the column is a
     * real terminal cell rather than a byte offset.
     *
     * @return array{0:int,1:int}
     */
    private function locate(string $frame, string $needle): array
    {
        foreach (explode("\n", $frame) as $index => $line) {
            $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
            $at = mb_strpos($plain, $needle);
            if ($at === false) {
                continue;
            }

            return [Width::string(mb_substr($plain, 0, $at)) + 1, $index + 1];
        }

        $this->fail("frame does not contain '{$needle}'");
    }

    /**
     * Drop a SKILL.md into the launch root's project skill directory, the
     * lowest-precedence-beating tier {@see \SugarCraft\Crush\Skills\SkillLoader}
     * merges last, so the assertions above cannot be satisfied by a built-in
     * that happens to be named similarly.
     *
     * @param list<string> $paths `paths:` frontmatter globs; omitted when empty
     *                            so the skill is not path-scoped at all.
     */
    private function writeProjectSkill(
        string $name,
        string $description,
        string $body = "# body\n",
        array $paths = [],
    ): void {
        $dir = $this->tempDir . '/repo/.sugar-crush/skills/' . $name;
        mkdir($dir, 0o755, true);

        $frontmatter = "---\ndescription: {$description}\nuser-invocable: true\ndisable-model-invocation: false\n";
        if ($paths !== []) {
            $frontmatter .= "paths:\n";
            foreach ($paths as $glob) {
                $frontmatter .= "  - \"{$glob}\"\n";
            }
        }

        file_put_contents($dir . '/SKILL.md', $frontmatter . "---\n" . $body);
    }

    /**
     * The backend a plain launch hands the Chat — the engine half of the app,
     * as opposed to {@see Bootstrap::app()}'s display copies.
     *
     * The two backend-selection env vars are cleared for the call and restored
     * after: `$SUGARCRUSH_BACKEND_CMD` selects a `CommandBackend`, which has no
     * tools and no registry, so a value leaked in from the environment (or from
     * an earlier test in the same PHPUnit process) would turn a real wiring
     * regression into a silently different assertion.
     */
    private function launchedEngineBackend(): EngineBackend
    {
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');

        try {
            $backend = Bootstrap::chat($this->tempDir . '/repo')->backend();
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
        }

        $this->assertInstanceOf(EngineBackend::class, $backend);

        return $backend;
    }

    /**
     * The registry {@see EngineBackend::complete()} feeds to
     * `App::withAvailableSkills()` on every turn.
     */
    private function engineSkillRegistry(): SkillRegistry
    {
        $registry = $this->privateValue($this->launchedEngineBackend(), 'skillRegistry');

        $this->assertInstanceOf(SkillRegistry::class, $registry);

        return $registry;
    }

    /**
     * @param class-string $class
     */
    private function engineTool(string $class): object
    {
        return $this->toolOf($this->launchedEngineBackend(), $class);
    }

    /**
     * @param class-string $class
     */
    private function toolOf(EngineBackend $backend, string $class): object
    {
        foreach ($this->privateValue($backend, 'tools') as $tool) {
            if ($tool instanceof $class) {
                return $tool;
            }
        }

        $this->fail("Expected {$class} among the engine backend's tools");
    }

    private function privateValue(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }

    /**
     * Add a second row to the launched Chat's own store so tab cycling has
     * somewhere to cycle to, and return its id. Uses the store the Chat is
     * actually holding — a separately constructed store would not be the one
     * `cycleSessionTab()` reads.
     */
    private function addSecondSession(Chat $chat): string
    {
        $id = 'second-' . bin2hex(random_bytes(4));
        $chat->sessionStore()?->createSession($id, 'echo', 'echo');

        return $id;
    }

    /**
     * Decode $bytes the way `Program`'s stdin loop does and return the single
     * KeyMsg they produce.
     */
    private function soleKeyMsg(string $bytes): KeyMsg
    {
        $msgs = (new InputReader())->parse($bytes);

        $this->assertCount(1, $msgs, 'expected exactly one decoded message for ' . bin2hex($bytes));
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);

        return $msgs[0];
    }

    /**
     * Type $text and press Enter, the way a user reaches a slash command.
     * `withInputBuf()` is private on Chat, so the buffer is filled through
     * reflection rather than by replaying every character.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function submit(Chat $chat, string $text): array
    {
        $ref = new \ReflectionMethod($chat, 'withInputBuf');
        $ref->setAccessible(true);

        /** @var Chat $filled */
        $filled = $ref->invoke($chat, $text);

        /** @var array{0:Chat,1:?\Closure} $result */
        $result = $filled->update(new KeyMsg(KeyType::Enter, ''));

        return $result;
    }

    /**
     * The system prompt one real turn hands the provider, driven from the
     * backend `Bootstrap::chat()` built for a plain launch.
     *
     * Only the provider is substituted: the tool list, hook chain, skill
     * registry and — the object this row is about — the
     * {@see InstructionFileLoader} are the very instances `Bootstrap` wired,
     * lifted off the launched backend by reflection and re-seated on a
     * capturing twin, because `EngineBackend::$provider` is readonly and the
     * default `EchoProvider` never reports what it was asked. A regression
     * that dropped `withInstructionLoader()` from `Bootstrap::backend()`
     * therefore fails here, which a locally-rebuilt backend could not catch.
     */
    private function launchedSystemPrompt(): string
    {
        $backend = $this->launchedEngineBackend();

        $loader = $this->privateValue($backend, 'instructionLoader');
        $this->assertInstanceOf(
            InstructionFileLoader::class,
            $loader,
            'a real launch must hand its engine the shared instruction loader',
        );

        $provider = $this->promptCapturingProvider();
        $this->reseatProvider($backend, $provider)->complete([Message::user('hello')]);

        $this->assertCount(1, $provider->requests, 'expected exactly one provider round-trip');
        $prompt = $provider->requests[0]->systemPrompt;
        $this->assertIsString($prompt);

        return $prompt;
    }

    /**
     * Rebuild $backend around $provider, carrying every other constructor
     * argument over as the same instance.
     */
    private function reseatProvider(EngineBackend $backend, ProviderInterface $provider): EngineBackend
    {
        return new EngineBackend(
            $provider,
            (string) $this->privateValue($backend, 'model'),
            $this->privateValue($backend, 'tools'),
            $this->privateValue($backend, 'skills'),
            $this->privateValue($backend, 'hookManager'),
            (int) $this->privateValue($backend, 'maxSteps'),
            (bool) $this->privateValue($backend, 'hooksDisabled'),
            $this->privateValue($backend, 'skillRegistry'),
            $this->privateValue($backend, 'instructionLoader'),
        );
    }

    /**
     * Records every {@see CompleteRequest} the engine builds. Non-streaming
     * on purpose — `Runtime::run()` only takes the deterministic
     * one-response-per-step `runBatch()` path when the provider says so.
     */
    private function promptCapturingProvider(): object
    {
        return new class implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function name(): string
            {
                return 'stub-reachability';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;

                return new CompleteResponse(content: 'answered');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
