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
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Workflows\WorkflowEngine;
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
    use \SugarCraft\Crush\Tests\Support\DrivesWorkflowRunsTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

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

        // BOTH SPELLINGS. This said "Bootstrap reads getenv('HOME'),
        // ForeignSkillDiscovery reads $_SERVER['HOME'], so redirecting one leaves
        // the other scanning the developer's own ~/.claude/skills" — the two-way
        // split it describes is gone (every `~` reader in src/ resolves through
        // HomeDirectory, which prefers getenv()), but setting the superglobal too
        // is still right for the reason {@see \SugarCraft\Crush\Tests\Support\HomeSandboxTrait}
        // gives: half a sandbox is not a sandbox.
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
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
     * Write the persisted user config the way the CLI itself writes it —
     * {@see Bootstrap::writeUserConfig()}, the production read-merge-write with
     * the atomic rename, into `$HOME/.sugar-crush/config.json` inside this
     * test's home sandbox (see {@see setUp()}). That is the same helper the
     * instruction-loading tests below already call, so there is no second,
     * test-shaped writer to drift from the real one; the sandboxed HOME is what
     * makes it land in the fixture rather than in the developer's file.
     * `rawUserConfig()` is a free-form passthrough, so a key this step
     * introduces (`enabledSkills`) lands without touching
     * `LayeredSettings::LAYERED_KEYS` — that roster growth (settings.json
     * tiering + its doc guards) is deliberately deferred out of P7.S3.
     *
     * THEN IT READS THE FILE BACK AND ASSERTS IT. A config write that fails
     * quietly is the worst failure this seam can have: every one of these tests
     * would fall through to the default-empty path and PASS, reporting a
     * configured launch that never happened. Asserting the keys landed is what
     * turns a silent degrade into an error at the line that caused it. The
     * merge is shallow and the sandbox starts empty, so each key asked for must
     * come back with exactly the value asked for.
     *
     * @param array<string, mixed> $settings
     */
    private function writeUserConfig(array $settings): void
    {
        Bootstrap::writeUserConfig($settings);

        $path = $this->tempDir . '/home/.sugar-crush/config.json';
        $this->assertFileExists(
            $path,
            'the production writer put no config where production reads one — every assertion after '
                . 'this would be measuring the default-empty path',
        );
        $written = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($written, 'the user config written for this test does not parse as JSON');

        foreach ($settings as $key => $value) {
            $this->assertArrayHasKey($key, $written, "`{$key}` was asked for and did not arrive");
            $this->assertSame(
                $value,
                $written[$key],
                "`{$key}` did not persist as configured — the launch below would be reading something else",
            );
        }
    }

    /**
     * P7.S3, the kill-shot against lesson 16.1: `EngineBackend::withSkills()`
     * existed since P7.S1 with zero production callers — implemented,
     * unreachable. This drives the WHOLE composition path a real launch takes
     * (`Bootstrap::chat()` → `backend()` → `promptEnabledSkills()` reading
     * `enabledSkills` from the user config → `withSkills()` → `complete()`
     * rebuilding the App `withEnabledSkills()` → the Runtime body splice)
     * and asserts on the bytes the PROVIDER PAYLOAD receives: the enabled
     * skill's full `Skill::systemPromptContribution()` lands exactly once.
     *
     * And the exclusion rides the same capture: the enabled name must NOT
     * also appear as its level-1 listing line — one fact, one presentation.
     *
     * RED-ON-REVERT: deleting the `->withSkills(self::promptEnabledSkills(
     * $skills))` link from BOTH chains in Bootstrap.php (backend() and
     * backendFor()) reds the substr_count here at 0 while the default-empty
     * sibling test below stays green — which is exactly the asymmetry that
     * proves the channel is this line's, not a fixture artifact. Reverting
     * the Runtime/SkillMatcher exclusion seam reds the listing-count at 1.
     */
    public function testAnEnabledSkillBodyReachesTheProviderPayloadThroughARealLaunch(): void
    {
        $this->writeProjectSkill(
            'p7s3-killshot-skill',
            'Kills the implemented-but-unreachable class of bug.',
            "# Killshot body\n\nMARKER-P7S3-BODY\n",
        );
        $this->writeUserConfig(['enabledSkills' => ['p7s3-killshot-skill']]);

        $prompt = $this->launchedSystemPrompt();

        $this->assertSame(
            1,
            substr_count($prompt, '## Skill: p7s3-killshot-skill'),
            'the pre-enabled body must reach the launched provider payload exactly once (P7.S3)',
        );
        $this->assertStringContainsString('MARKER-P7S3-BODY', $prompt);
        $this->assertSame(
            0,
            substr_count($prompt, '- p7s3-killshot-skill:'),
            'an enabled skill must not also be listed as a level-1 line (P7.S3 exclusion)',
        );
    }

    /**
     * The other polarity, at the same seam: the DEFAULT is the empty list, so
     * a launch that configures nothing — whether the key is absent or present
     * and empty — must hand the provider the same bytes it handed before
     * P7.S3 existed. Byte-identical comparison of two real launches, one per
     * spelling, plus the absence of the seeded body. If this reds while the
     * kill-shot above also matches, the default is NOT empty — the one
     * regression shape this step exists to forbid.
     */
    public function testTheDefaultEmptyEnabledSkillsKeepsTheLaunchedProviderPayloadByteIdentical(): void
    {
        $this->writeProjectSkill('p7s3-dormant-skill', 'Discovered, never enabled.', "# Dormant\n\nDORMANT-P7S3-BODY\n");

        $withoutKey = $this->launchedSystemPrompt();

        $this->writeUserConfig(['enabledSkills' => []]);
        $emptyList = $this->launchedSystemPrompt();

        $this->assertSame(
            $withoutKey,
            $emptyList,
            'an absent key and an explicitly empty `enabledSkills` must produce the identical launched prompt — the default is empty either way (P7.S3)',
        );
        $this->assertStringNotContainsString('## Skill: p7s3-dormant-skill', $withoutKey);
        $this->assertStringNotContainsString('DORMANT-P7S3-BODY', $withoutKey);
        // And the listing still carries it, untouched: exclusion applies only
        // to enabled names, the discovery half of the wire never changed.
        $this->assertStringContainsString(
            '- p7s3-dormant-skill: Discovered, never enabled.',
            $withoutKey,
        );
    }

    /**
     * Fail-safe resolution, pinned launched: a config that names a skill the
     * registry does not know must not brick the start — the body channel
     * drops the name with a launch notice and the turn still reaches the
     * provider. This is the deliberate exception to fail-loud: standing
     * instructions a user half-configured beat a dead CLI, and the notice
     * routes the miss to stderr AND the transcript.
     */
    public function testAnUnknownEnabledSkillNameDropsWithANoticeInsteadOfCrashingTheLaunch(): void
    {
        $this->writeUserConfig(['enabledSkills' => ['p7s3-no-such-skill']]);

        // The launch completing AT ALL is the load-bearing half: a thrown
        // PermissionConfigException-style failure here would take the whole
        // CLI down for one stale name.
        $prompt = $this->launchedSystemPrompt();

        // The prompt is a WHOLE one, not a half-built one: declining the body of
        // a name that resolves to nothing must cost nothing else. This replaces
        // an assertion that the missing body was absent, which could not fail —
        // a name with no manifest has no body to splice, so it graded the
        // resolver's no-op rather than its decision (P7.S3 review N3).
        $this->assertStringContainsString(
            '</env>',
            $prompt,
            'the launch survived the stale name — the fail-safe is the claim, and a truncated prompt '
                . 'would mean it threw instead (P7.S3)',
        );

        $misses = array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn(string $notice): bool => str_contains($notice, 'p7s3-no-such-skill'),
        ));
        $this->assertCount(
            1,
            $misses,
            'a dropped enabled-skill name must be recorded as a launch notice, not swallowed (P7.S3)',
        );
        // And the notice has to be a DIAGNOSTIC: naming the skill and naming the
        // reason, which is the half that tells the reader to look at their
        // config rather than at a log line about something dropping.
        $this->assertStringContainsString(
            "enabled skill 'p7s3-no-such-skill' was not found",
            $misses[0],
            'the notice must name the missing skill and say it was not found (P7.S3 review N3)',
        );
    }

    /**
     * Both keys naming the same skill: the registry resolves `get()` through
     * the disable, so the opt-out wins and the name follows the drop path — a
     * body can never be smuggled past a deliberate ban by also listing it as
     * enabled. Pinned because nothing else in the suite decides this precedence,
     * and the two keys are read by two different methods.
     *
     * The NOTICE is pinned here too, and that is the MINOR-3 half: the drop path
     * is shared with an unknown name, so a notice that said "was not found" for a
     * skill sitting on disk in the same config that bans it misnames the cause and
     * sends the reader looking in the wrong file. `isDisabled()` is checked before
     * the not-found wording is chosen, and this asserts both halves of that — the
     * disabled phrase present, the not-found phrase absent.
     */
    public function testADisabledSkillStaysOutOfThePromptEvenWhenAlsoEnabled(): void
    {
        $this->writeProjectSkill('p7s3-contested-skill', 'Banned and blessed in the same file.', "# Contested\n\nCONTESTED-P7S3-BODY\n");
        $this->writeUserConfig([
            'enabledSkills' => ['p7s3-contested-skill'],
            'disabledSkills' => ['p7s3-contested-skill'],
        ]);

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringNotContainsString('## Skill: p7s3-contested-skill', $prompt);
        $this->assertStringNotContainsString('CONTESTED-P7S3-BODY', $prompt);
        $this->assertStringNotContainsString(
            '- p7s3-contested-skill:',
            $prompt,
            'a disabled skill is out of the listing too — the disable happens registry-side',
        );

        $causes = array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn(string $notice): bool => str_contains($notice, 'p7s3-contested-skill'),
        ));
        $this->assertCount(1, $causes, 'the contested name must be reported exactly once');
        $this->assertStringContainsString(
            "enabled skill 'p7s3-contested-skill' is disabled by configuration",
            $causes[0],
            'a name the config both enables and disables must be reported as DISABLED, which is what '
                . 'decided its fate (P7.S3 review MINOR-3)',
        );
        $this->assertStringNotContainsString(
            'was not found',
            $causes[0],
            'and it must not be reported as missing: the SKILL.md exists on disk, and pointing the reader '
                . 'at a vanished file is a diagnostic that sends them looking in the wrong place',
        );
    }

    /**
     * MAJOR-1 (P7.S3 review): a name listed TWICE in `enabledSkills` used to
     * resolve twice, because the name loop filtered without deduplicating —
     * and Runtime renders one section per entry of
     * `App::$enabledSkills`, so one duplicated line in the user's config put
     * the same body in the system prompt TWICE on every turn, silently, with
     * nothing anywhere to notice it. That is the direct negation of this step's
     * exactly-once invariant, so the dedupe lives in the resolver and both
     * halves of it are pinned here: the resolver hands back ONE skill for two
     * configured names, and the launched provider payload carries the body once.
     *
     * RED-ON-REVERT: restoring `array_filter(...)` without `array_unique` reds
     * the resolver count at 2 and the launched `substr_count` at 2.
     */
    public function testADuplicatedEnabledSkillNameResolvesOnceAndSplicesOnce(): void
    {
        $this->writeProjectSkill(
            'p7s3-duplicate-skill',
            'Named twice by one config file.',
            "# Duplicate\n\nDUPLICATE-P7S3-BODY\n",
        );
        $this->writeUserConfig([
            'enabledSkills' => ['p7s3-duplicate-skill', 'p7s3-duplicate-skill'],
        ]);

        $resolved = $this->resolveEnabledSkills($this->engineSkillRegistry());
        $this->assertCount(
            1,
            $resolved,
            'a name listed twice is ONE skill asked for twice; the resolver may not hand the engine two '
                . 'objects for it (P7.S3 review MAJOR-1)',
        );
        $this->assertSame(
            ['p7s3-duplicate-skill'],
            array_map(static fn($skill): string => $skill->name, $resolved),
            'and the surviving entry keeps its configured name',
        );

        $prompt = $this->launchedSystemPrompt();

        $this->assertSame(
            1,
            substr_count($prompt, '## Skill: p7s3-duplicate-skill'),
            'the launched prompt must carry the duplicated name body exactly once (P7.S3 review MAJOR-1)',
        );
        $this->assertSame(
            1,
            substr_count($prompt, 'DUPLICATE-P7S3-BODY'),
            'counted on the body marker as well, so the pin cannot be satisfied by a heading alone',
        );
    }

    /**
     * MINOR-2 (P7.S3 review): a value that is not a list at all — a bare string
     * where a list of names belongs — used to `return []` in silence, and
     * silence is the one answer a user who typed the key cannot use. The house
     * shape for a malformed list key (`permissionRules()`,
     * `trustedProjectRoots()`) is one notice naming the key, then continue.
     *
     * Fail-safe is preserved on the axis that matters: the LAUNCH still
     * completes. What changed is that the drop is now said.
     *
     * RED-ON-REVERT: restoring the bare `if (!is_array(...)) { return []; }`
     * reddens the notice assertion while the "launch completes" half stays
     * green — the asymmetry that proves the notice is the fix.
     */
    public function testEnabledSkillsThatIsNotAListIsReportedAndDoesNotBrickTheLaunch(): void
    {
        $this->writeProjectSkill(
            'p7s3-string-shaped-skill',
            'Discovered, named by a value that is not a list.',
            "# String shaped\n\nSTRING-P7S3-BODY\n",
        );
        $this->writeUserConfig(['enabledSkills' => 'p7s3-string-shaped-skill']);

        // Reaching a prompt at all is the load-bearing half: a wrong-shaped
        // value is a config mistake, not a reason to kill the CLI.
        $prompt = $this->launchedSystemPrompt();

        $this->assertStringNotContainsString('## Skill: p7s3-string-shaped-skill', $prompt);
        $this->assertStringNotContainsString('STRING-P7S3-BODY', $prompt);
        $this->assertStringContainsString(
            '- p7s3-string-shaped-skill: Discovered, named by a value that is not a list.',
            $prompt,
            'the launch is whole, not degraded: the skill is still discovered and listed, only the '
                . 'body channel declined to open',
        );

        $notices = array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn(string $notice): bool => str_contains($notice, 'enabledSkills is not a list'),
        ));
        $this->assertCount(
            1,
            $notices,
            'a wrong-shaped `enabledSkills` must say so once, naming the key (P7.S3 review MINOR-2)',
        );
        $this->assertStringContainsString('enabledSkills', $notices[0]);
    }

    /**
     * The other half of MINOR-2: a well-shaped list with one wrong-shaped
     * ENTRY. The good entry must still land — one typo must not forfeit the
     * skill the user spelled correctly — and the bad one must be named BY
     * INDEX, because the position in the file is the only handle the reader has
     * on which entry they mistyped.
     *
     * RED-ON-REVERT: restoring `array_filter($enabled, 'is_string')` (drop
     * quietly, no index) reddens the indexed notice.
     */
    public function testANonStringEntryInEnabledSkillsIsSkippedWithAnIndexedNotice(): void
    {
        $this->writeProjectSkill(
            'p7s3-mixed-skill',
            'The half of a mixed list that is a name.',
            "# Mixed\n\nMIXED-P7S3-BODY\n",
        );
        $this->writeUserConfig(['enabledSkills' => ['p7s3-mixed-skill', 42]]);

        $prompt = $this->launchedSystemPrompt();

        $this->assertSame(
            1,
            substr_count($prompt, '## Skill: p7s3-mixed-skill'),
            'a bad entry must not forfeit the good one beside it (P7.S3 review MINOR-2)',
        );
        $this->assertStringContainsString('MIXED-P7S3-BODY', $prompt);

        $notices = array_values(array_filter(
            Bootstrap::launchNotices(),
            static fn(string $notice): bool => str_contains($notice, 'enabledSkills[1]'),
        ));
        $this->assertCount(1, $notices, 'the skipped entry must be named by its index');
        $this->assertStringContainsString('is not a skill name; entry skipped', $notices[0]);
        $this->assertSame(
            [],
            array_values(array_filter(
                Bootstrap::launchNotices(),
                static fn(string $notice): bool => str_contains($notice, '## Skill: 42')
                    || str_contains($notice, "enabled skill '42'"),
            )),
            'a non-string entry is not a name, so it must not reach the name-resolution path either',
        );
    }

    /**
     * Drive the private resolver directly, so a dedupe defect is visible at the
     * level it is fixed rather than only through the whole launch.
     *
     * @return list<\SugarCraft\Crush\Skills\Skill>
     */
    private function resolveEnabledSkills(SkillRegistry $registry): array
    {
        $method = new \ReflectionMethod(Bootstrap::class, 'promptEnabledSkills');
        $method->setAccessible(true);
        $resolved = $method->invoke(null, $registry);
        $this->assertIsArray($resolved);

        return $resolved;
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
     * before this wave. P3.S1 moved <env> to the end of the assembly
     * (stable layers first, volatile last — prompt_expand.md §9.2), so this
     * pin is inverted, not deleted: an inverted assertion still pins an
     * order, and a reorder that put <env> back ahead of the project
     * instructions would red it. The block must still reach a real launch;
     * only its position changed.
     */
    public function testARealLaunchDeliversTheEnvironmentBlockAfterProjectInstructions(): void
    {
        file_put_contents($this->tempDir . '/repo/AGENTS.md', 'ENV ORDER MARKER');

        $prompt = $this->launchedSystemPrompt();

        $this->assertStringContainsString('<env>', $prompt);
        $this->assertStringContainsString('Working directory: ', $prompt);
        $this->assertStringContainsString('Platform: ', $prompt);
        $this->assertStringContainsString('Current date: ', $prompt);
        $this->assertLessThan(
            strpos($prompt, '<env>'),
            strpos($prompt, '<project-instructions>'),
            'the environment snapshot must follow the project conventions in the assembled prompt',
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
    // Row: Workflows (WorkflowEngine + WorkflowRegistry + the PHP/YAML DSL)
    // "`Chat`'s constructor accepts an optional `?WorkflowEngineInterface
    //  $workflowEngine` and `handleWorkflowCommand()` guards on it being
    //  non-null [...] So `/workflow run|pause|resume|status|list` on a real
    //  `bin/sugarcrush` invocation ALWAYS prints 'Workflow engine not
    //  configured,' regardless of any `.sugar-crush/workflows/*.yaml` a user
    //  might author." — crush_code.md §10 Finding 3 / Phase 2 item 3.
    // =========================================================================

    /**
     * The launch has to carry ONE engine — and it has to survive the App shell
     * `bin/sugarcrush` actually runs, since the hosted Chat is what `/workflow`
     * dispatches through.
     */
    public function testTheLaunchedChatCarriesALiveWorkflowEngine(): void
    {
        $this->assertInstanceOf(
            WorkflowEngine::class,
            Bootstrap::chat($this->tempDir . '/repo')->workflowEngine(),
        );

        $this->assertInstanceOf(
            WorkflowEngine::class,
            Bootstrap::app($this->tempDir . '/repo')->chat?->workflowEngine(),
        );
    }

    /**
     * E641's feed: the launched engine must carry the session's tool REGISTRY,
     * not merely exist. Without it `resolveRequestTools()` cannot turn a
     * workflow task's tool NAME into the Tool OBJECT the provider boundary
     * demands, and every `tools:` declaration a stage makes goes out as null —
     * the exact seam lane B left for this merge step. Pinned against the
     * LAUNCHED chat rather than a hand-built engine (which
     * WorkflowToolsHandoffTest covers) because what can regress here is the
     * wiring. An empty HOME config means `toolSetUnder()` is the identity, so
     * the registry arrives as the full built-in universe — non-null, non-empty,
     * all Tool instances.
     */
    public function testTheLaunchedWorkflowEngineCarriesTheSessionToolRegistry(): void
    {
        $engine = Bootstrap::chat($this->tempDir . '/repo')->workflowEngine();
        $this->assertInstanceOf(WorkflowEngine::class, $engine);

        $registry = $this->privateValue($engine, 'toolRegistry');

        $this->assertIsArray(
            $registry,
            'the launch left the E641 seam null — a stage that declares tools cannot resolve them',
        );
        $this->assertNotSame([], $registry);
        foreach ($registry as $tool) {
            $this->assertInstanceOf(Tool::class, $tool);
        }
    }

    /**
     * The narrowing travels with the feed: `disabledTools: ["Bash"]` in the
     * user config must come out of the engine's registry too, the same pure
     * predicate `agentManager()` applies to its own `toolRegistry:`. This is
     * the other half of the E639 distinction the seam documents — the registry
     * is the session's narrowed set, so a workflow stage naming Bash is
     * refused as narrowing rather than served a tool the operator removed.
     */
    public function testTheLaunchedWorkflowEngineRegistryIsNarrowedByDisabledTools(): void
    {
        $this->writeUserConfig(['disabledTools' => ['Bash']]);

        $engine = Bootstrap::chat($this->tempDir . '/repo')->workflowEngine();
        $registry = $this->privateValue($engine, 'toolRegistry');

        $this->assertIsArray($registry);
        $names = array_map(static fn (Tool $tool): string => $tool->name(), $registry);
        $this->assertNotContains('Bash', $names, 'the operator removed Bash; the engine must not hand it to a stage');
        $this->assertNotEmpty(array_diff($names, ['Bash']), 'narrowing must not empty an untouched universe');
    }

    /**
     * The headline claim of the row, driven the way a user reaches it: a
     * workflow checked into the repo the session was launched against must be
     * listed by `/workflow list` instead of the refusal every pre-wiring run
     * produced.
     */
    public function testWorkflowListOnTheLaunchedChatFindsAProjectWorkflow(): void
    {
        $this->writeProjectWorkflow('reach-workflow', "name: reach-workflow\nstages: []\n");

        [$next] = $this->submit(Bootstrap::chat($this->tempDir . '/repo'), '/workflow list');

        $transcript = $this->transcript($next);
        $this->assertStringNotContainsString('Workflow engine not configured', $transcript);
        $this->assertStringContainsString('reach-workflow', $transcript);
    }

    /**
     * The other side of that reachability: a repository that ships its
     * `.sugar-crush/workflows` as a SYMLINK out of the checkout discloses
     * nothing through the launched chat.
     *
     * Driven through `Bootstrap` rather than against `WorkflowRegistry`
     * directly because what is at risk here is the WIRING: this asserts the
     * boundary is reached at all from a real launch. It deliberately does NOT
     * prove the launch passed its project ROOT — a link out of the checkout is
     * also out of the fallback anchor, so this test passes either way (measured:
     * mutating `projectRoot: $root` to `null` leaves it green). The test below
     * is the one that pins the argument.
     */
    public function testASymlinkedProjectWorkflowsDirectoryDisclosesNothingThroughTheLaunchedChat(): void
    {
        $victim = $this->tempDir . '/victim';
        mkdir($victim, 0755, true);
        file_put_contents($victim . '/leaked.yaml', "name: leaked\ndescription: SENTINEL-OUTSIDE-CHECKOUT\nstages: []\n");

        mkdir($this->tempDir . '/repo/.sugar-crush', 0755, true);
        $this->assertTrue(
            symlink($victim, $this->tempDir . '/repo/.sugar-crush/workflows'),
            'test needs a real symlinked directory',
        );

        [$next] = $this->submit(Bootstrap::chat($this->tempDir . '/repo'), '/workflow list');

        $transcript = $this->transcript($next);
        $this->assertStringNotContainsString('Workflow engine not configured', $transcript);
        $this->assertStringNotContainsString('leaked', $transcript);
        $this->assertStringNotContainsString('SENTINEL-OUTSIDE-CHECKOUT', $transcript);
    }

    /**
     * The launch passes its project ROOT to the registry, pinned by the one
     * layout that tells the two anchors apart.
     *
     * `.sugar-crush/workflows -> tools/workflows` leaves the workflows
     * directory's own parent but stays inside the checkout. With the root it is
     * honoured (repo content pointing at repo content, the same trust as a
     * committed `.yaml`); without it the registry falls back to that parent as
     * the anchor and the whole tier disappears. So this is simultaneously the
     * usability control for the test above and the only assertion that fails
     * when `Bootstrap::workflowEngine()` stops passing `projectRoot`.
     */
    public function testAnInCheckoutSymlinkedWorkflowsDirectoryIsStillHonouredByTheLaunch(): void
    {
        mkdir($this->tempDir . '/repo/tools/workflows', 0755, true);
        mkdir($this->tempDir . '/repo/.sugar-crush', 0755, true);
        file_put_contents(
            $this->tempDir . '/repo/tools/workflows/in-repo-link.yaml',
            "name: in-repo-link\nstages: []\n",
        );
        $this->assertTrue(symlink(
            $this->tempDir . '/repo/tools/workflows',
            $this->tempDir . '/repo/.sugar-crush/workflows',
        ));

        [$next] = $this->submit(Bootstrap::chat($this->tempDir . '/repo'), '/workflow list');

        $this->assertStringContainsString(
            'in-repo-link',
            $this->transcript($next),
            'a workflows directory linked to elsewhere INSIDE the checkout must still be read — '
            . 'if this fails, the launch stopped telling the registry where the checkout is',
        );
    }

    /**
     * The refusal is not silent: a launch whose repository ships a workflows or
     * skills directory pointing out of the checkout SAYS SO, once, naming the
     * path.
     *
     * The gap this closes had the user looking at three separate half-truths and
     * no statement of fact: `loadYaml()`'s not-found message drops the refused
     * directory from the list it searched, `projectWorkflowsPath()` still reports
     * it, and `/workflow list`'s empty answer named it as a place that had been
     * looked in. Nothing said "your repository's directory was rejected".
     *
     * DRIVEN AS A SUBPROCESS, because the notice is a real `fwrite(STDERR, …)`
     * and the point is that it reaches a stream — an in-process assertion on
     * `Bootstrap::projectTierRefusals()` would pass against a build that
     * collected the refusal and printed nothing, which is the previous state of
     * this defect one layer up. The subprocess also sidesteps the report-once
     * bookkeeping being static: a second in-process launch prints nothing by
     * design.
     *
     * BOTH SUBSYSTEMS in one launch, deliberately: they reach the collector by
     * different routes (`workflowEngine()` asks the registry eagerly,
     * `skillRegistry()` merges the loader's) and one route working is not the
     * other working.
     */
    public function testALaunchNamesEveryProjectDirectoryItRefusedOnStderr(): void
    {
        $root = $this->tempDir . '/repo';
        $victim = $this->tempDir . '/victim';
        mkdir($victim . '/leak', 0755, true);
        file_put_contents($victim . '/leak/SKILL.md', "---\nname: leak\ndescription: SENTINEL-SKILL\n---\nbody\n");
        file_put_contents($victim . '/leaked.yaml', "name: leaked\ndescription: SENTINEL-WORKFLOW\nstages: []\n");

        mkdir($root . '/.sugar-crush', 0755, true);
        $this->assertTrue(symlink($victim, $root . '/.sugar-crush/workflows'));
        $this->assertTrue(symlink($victim, $root . '/.sugar-crush/skills'));

        $script = $this->tempDir . '/launch.php';
        file_put_contents($script, sprintf(
            '<?php require %s; SugarCraft\Crush\Cli\Bootstrap::chat(%s);',
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($root, true),
        ));

        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HOME' => $this->tempDir . '/home', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringContainsString(
            $root . '/.sugar-crush/workflows',
            $stderr,
            'the launch must name the refused workflows directory: ' . $stderr . $stdout,
        );
        $this->assertStringContainsString(
            $root . '/.sugar-crush/skills',
            $stderr,
            'and the refused skills directory: ' . $stderr . $stdout,
        );
        // The notice explains rather than merely announcing, and it leaks
        // nothing from behind the link it refused.
        $this->assertStringContainsString($victim, $stderr, 'and where each one actually resolved to');
        $this->assertStringNotContainsString('SENTINEL-SKILL', $stderr . $stdout);
        $this->assertStringNotContainsString('SENTINEL-WORKFLOW', $stderr . $stdout);
    }

    /**
     * The control: an ordinary launch says nothing at all.
     *
     * A notice that fires on every launch is the failure mode
     * `SkillLoader::recordSkip()` was written to end, so the quiet case is worth
     * an assertion of its own.
     */
    public function testAnOrdinaryLaunchRefusesNothingAndSaysNothing(): void
    {
        $this->writeProjectWorkflow('quiet', "name: quiet\nstages: []\n");

        $script = $this->tempDir . '/launch-quiet.php';
        file_put_contents($script, sprintf(
            '<?php require %s; SugarCraft\Crush\Cli\Bootstrap::chat(%s);',
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->tempDir . '/repo', true),
        ));

        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HOME' => $this->tempDir . '/home', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringNotContainsString('ignoring', $stderr, 'stderr: ' . $stderr);
    }

    /**
     * `/workflow list` only proves the REGISTRY is reachable — it never calls
     * the engine. This drives a workflow all the way to a `WorkflowResult`
     * through `Chat::update()`, which is the only assertion here that fails if
     * `WorkflowEngine::run()` itself is unreachable from a real launch.
     *
     * SCOPE, stated exactly, because a zero-stage workflow proves less than it
     * looks like it does. What this genuinely reaches: `Chat::workflowRun()` →
     * `WorkflowEngine::run()` → `WorkflowRegistry::load()` (the project tier,
     * YAML, parsed and built) → `runFromWorkflow()` → `installInterruptHandlers()`
     * / `restoreInterruptHandlers()` → the `WorkflowResult` the command formats.
     * That is the dispatchability claim of this row, and it is the whole claim.
     *
     * What it does NOT reach: `foreach ($workflow->stages ...)` iterates zero
     * times, so none of `executeStage()`, `executeParallelStage()`,
     * `executePipelineStage()` or `executeVerificationStage()` runs, no
     * `AgentWorkerPool` is touched, and none of the six `new Agent(...)` sites is
     * evaluated. Those are covered from `tests/Workflows/WorkflowEngineTest.php`,
     * which drives real stages through an injected `ExecutorInterface` — see
     * `testEachStageTypeDispatchesOnTheEnginesOwnModelAndProvider()` there for
     * the model/provider assertion this test cannot make.
     *
     * Zero stages is deliberate all the same: a real stage here would fork a
     * worker from a wiring test. `run()` is no longer called synchronously
     * inside `Chat::update()` — it goes into a fiber the loop steps — so this
     * has to drive the loop to see the reply at all. `Chat::workflowRun()`'s
     * docblock has the shape; the mid-run frame is proved in
     * {@see \SugarCraft\Crush\Tests\Workflows\WorkflowLivePaneTest}.
     */
    public function testWorkflowRunOnTheLaunchedChatCompletesAProjectWorkflow(): void
    {
        $this->writeProjectWorkflow('run-reach', "name: run-reach\nstages: []\n");

        $transcript = $this->transcript($this->submitAndSettle(
            Bootstrap::chat($this->tempDir . '/repo'),
            '/workflow run run-reach',
        ));
        $this->assertStringNotContainsString('Workflow engine not configured', $transcript);
        $this->assertStringNotContainsString('**Error:**', $transcript);
        $this->assertStringContainsString("**Workflow 'run-reach' completed**", $transcript);
        $this->assertStringContainsString('Status: completed', $transcript);
    }

    /**
     * The user's own `~/.sugar-crush/workflows` tier has to reach the same
     * launch — a wiring that only threaded the project root would leave every
     * workflow a user has ever written unreachable.
     */
    public function testWorkflowListOnTheLaunchedChatAlsoFindsTheUsersOwnWorkflow(): void
    {
        $dir = $this->tempDir . '/home/.sugar-crush/workflows';
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/home-workflow.yaml', "name: home-workflow\nstages: []\n");

        [$next] = $this->submit(Bootstrap::chat($this->tempDir . '/repo'), '/workflow list');

        $this->assertStringContainsString('home-workflow', $this->transcript($next));
    }

    /**
     * Wiring the registry to a project directory is what turns a cloned
     * repository into an input, so the tier's `.yaml`-only rule is asserted
     * from the live entry point and not only on the registry: a `.php`
     * workflow committed to a checkout must not be `require`d by `/workflow
     * run`. The marker file is the load-bearing assertion — a test that only
     * checked for an error message would still pass against a build that
     * executed the file first.
     */
    public function testRunningAProjectPhpWorkflowFromTheLaunchedChatExecutesNothing(): void
    {
        $marker = $this->tempDir . '/repo-rce-marker';
        $this->writeProjectWorkflow(
            'pwn',
            "<?php\nfile_put_contents('" . $marker . "', 'executed');\nreturn null;\n",
            '.php',
        );

        $next = $this->submitAndSettle(Bootstrap::chat($this->tempDir . '/repo'), '/workflow run pwn');

        $this->assertFileDoesNotExist($marker);
        $this->assertStringContainsString('**Error:**', $this->transcript($next));
    }

    /**
     * The refusal branch still has to exist for a Chat built without an engine
     * — without this, the tests above could pass against a build that had
     * simply deleted the guard rather than wired the engine.
     */
    public function testAChatWithNoWorkflowEngineStillRefusesExplicitly(): void
    {
        [$next] = $this->submit(new Chat(), '/workflow list');

        $this->assertStringContainsString('Workflow engine not configured', $this->transcript($next));
    }

    /**
     * A workflow stage names an agent TYPE, never a model, so the engine
     * supplies one to every sub-agent it dispatches. Wired straight from the
     * class defaults that would have been the literal `claude-sonnet-4-6` on a
     * session that selected something else entirely.
     */
    public function testTheLaunchedEngineDispatchesOnTheSessionsOwnProviderAndModel(): void
    {
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $model = getenv('SUGARCRUSH_MODEL');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        $streamCommand = getenv('SUGARCRUSH_BACKEND_CMD_STREAM');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_MODEL');
        putenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM');

        try {
            $engine = Bootstrap::chat($this->tempDir . '/repo')->workflowEngine();
            [$expectedProvider, $expectedModel] = Bootstrap::selectedProviderLabel();
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $model === false ? putenv('SUGARCRUSH_MODEL') : putenv('SUGARCRUSH_MODEL=' . $model);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
            $streamCommand === false ? putenv('SUGARCRUSH_BACKEND_CMD_STREAM') : putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $streamCommand);
        }

        $this->assertInstanceOf(WorkflowEngine::class, $engine);
        $this->assertSame($expectedModel, $engine->model());
        $this->assertSame($expectedProvider, $engine->provider());
        $this->assertNotSame(
            'claude-sonnet-4-6',
            $engine->model(),
            'the launch must supply its own model, not fall through to the engine default',
        );
    }

    /**
     * The launch's ONE {@see \SugarCraft\Crush\Permissions\PermissionGate} has
     * to reach the engine, because it is the only thing that can refuse a stage
     * a cloned repository's YAML declared. Before this it did not: every
     * workflow-spawned sub-agent was built with `permissionGate: null`.
     *
     * Asserted as identity against the gate the rest of the launch carries —
     * a second gate built from the same config would satisfy an
     * assertInstanceOf and still split PermissionGate's per-instance Auto-mode
     * strike counter in half, which is the exact reason
     * {@see Bootstrap::chat()} builds only one.
     */
    public function testTheLaunchedEngineCarriesTheLaunchsOwnPermissionGate(): void
    {
        $chat = $this->launchedChat();
        $engine = $chat->workflowEngine();
        $backend = $chat->backend();

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertInstanceOf(WorkflowEngine::class, $engine);
        $this->assertNotNull($engine->permissionGate());
        $this->assertSame(
            $backend->permissionGate(),
            $engine->permissionGate(),
            'the engine must carry the launch gate itself, not a second one built from the same config',
        );
    }

    /**
     * Chat is the only object holding both collaborators, so it is where the
     * engine learns which manager a parallel stage's sub-agents register with.
     * Passing the engine without that link would leave a `/workflow run`'s
     * agents invisible to the renderer that reads the manager for telemetry.
     */
    public function testTheLaunchedEngineIsLinkedToTheLaunchsAgentManager(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $engine = $chat->workflowEngine();

        $this->assertInstanceOf(WorkflowEngine::class, $engine);
        $this->assertNotNull($chat->agentManager());
        $this->assertSame($chat->agentManager(), $engine->agentManager());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Drop a workflow definition into the launch root's project workflow
     * directory — the tier {@see \SugarCraft\Crush\Workflows\WorkflowRegistry}
     * consults before the user's own.
     */
    private function writeProjectWorkflow(string $name, string $body, string $extension = '.yaml'): void
    {
        $dir = $this->tempDir . '/repo/.sugar-crush/workflows';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $name . $extension, $body);
    }

    /**
     * Submit a `/workflow run` and drive the loop until its reply lands.
     *
     * The run is asynchronous, so the Chat returned by `submit()` holds the
     * echoed command and nothing else.
     */
    private function submitAndSettle(Chat $chat, string $text): Chat
    {
        [$next, $cmd] = $this->submit($chat, $text);
        $this->assertInstanceOf(\Closure::class, $cmd, 'a /workflow run must return a Cmd');

        [$after] = $next->update($this->settleWorkflowCmd($cmd));

        return $after;
    }

    /**
     * Every message body of a Chat's transcript, joined — what a user would be
     * looking at after the turn.
     */
    private function transcript(Chat $chat): string
    {
        return implode("\n", array_map(static fn($m) => $m->content, $chat->history));
    }

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
     * All THREE backend-selection env vars are cleared for the call and restored
     * after — `$SUGARCRUSH_PROVIDER` and both shell-out variables, which is what
     * the code below does and what this sentence used to under-count as "two".
     * Either `$SUGARCRUSH_BACKEND_CMD` or `$SUGARCRUSH_BACKEND_CMD_STREAM`
     * selects a command backend, which has no tools and no registry, so a value
     * leaked in from the environment (or from an earlier test in the same
     * PHPUnit process) would turn a real wiring regression into a silently
     * different assertion.
     */
    private function launchedEngineBackend(): EngineBackend
    {
        $backend = $this->launchedChat()->backend();

        $this->assertInstanceOf(EngineBackend::class, $backend);

        return $backend;
    }

    /**
     * A launch whose backend is guaranteed to be the {@see EngineBackend} — the
     * env dance from {@see launchedEngineBackend()}, split out so a test can
     * hold the Chat itself and compare two of its collaborators for identity
     * (which needs ONE launch; two calls to Bootstrap::chat() build two of
     * everything and would compare unrelated instances).
     */
    private function launchedChat(): Chat
    {
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        $streamCommand = getenv('SUGARCRUSH_BACKEND_CMD_STREAM');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM');

        try {
            return Bootstrap::chat($this->tempDir . '/repo');
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
            $streamCommand === false ? putenv('SUGARCRUSH_BACKEND_CMD_STREAM') : putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $streamCommand);
        }
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

            // is_link() FIRST: is_dir() answers true THROUGH a link to a
            // directory, so recursing on that answer would empty the link's
            // TARGET rather than remove the link. One fixture below points a
            // project workflows directory at another directory on purpose.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }

            $this->removeDirectory($path);
        }

        rmdir($dir);
    }
}
