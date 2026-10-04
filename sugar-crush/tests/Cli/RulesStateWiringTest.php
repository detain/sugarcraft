<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The boot path that makes ONE rulebook toggle set serve TWO owners, pinned from
 * `Bootstrap::chat()` outward.
 *
 * ## The door this closes
 *
 * `Chat` is the only writer of the session's {@see RulesState} (the `/rules`
 * command is handled there) and `EngineBackend` is the only reader (it builds each
 * turn's `App`, and the rules splice subtracts whatever the App carries). They are
 * not in a call chain with each other, so `Bootstrap::chat()` creates the instance
 * ONCE and hands it to both. Hand-writing two instances instead — the shape the
 * boot path degrades to if either half of that hand-out is deleted — is not a crash
 * and not a wrong value. It is a `/rules` reply that says `OFF for this session`,
 * a transcript that agrees, a listing that agrees, and a prompt that goes on
 * carrying the pack the operator just switched off, for the rest of the session.
 *
 * Nothing in the suite could see that before this file. Both halves were unit-tested
 * at their own end (`Chat` answered correctly; `EngineBackend` forwarded whatever it
 * was given) and the render seam was pinned from a hand-built App, which is exactly
 * the class of gap this audit keeps finding: a subsystem green at both ends and a
 * no-op in production. The unit tests that build their own `Chat` pass with or
 * without the boot wiring, because they never route through it.
 *
 * The render seam itself — does a toggled pack leave the bytes — is pinned from a
 * hand-built App at
 * {@see \SugarCraft\Crush\Tests\Context\RulebookPromptRenderTest::testTheSamePackRendersIntoThePromptWhenOnAndOutOfItWhenToggledOff()};
 * this file does not re-argue it, it argues that the LAUNCHED pair feeds that seam
 * one shared set.
 *
 * ## Why the assertion is object identity AND a byte change, not either alone
 *
 * `assertSame` on the two accessors proves the two owners hold one instance but says
 * nothing about whether anything READS it — a backend that stores the set and never
 * carries it onto the turn's App would pass on identity alone. The render proves the
 * read but not the sharing — a hand-passed set renders identically. Only together do
 * they describe the wiring: the SAME object, whose mutation by the shell's own
 * keystroke path, changes the bytes the launched backend would send.
 *
 * ## Why ONE launch, and the hazard recorded next door
 *
 * {@see AgentManagerWiringTest}'s class docblock records that the suite has two
 * load-sensitive forked-completion tests, and that adding ANY `Bootstrap::chat()`
 * launching file to the run destabilised them when it was measured on an otherwise
 * untouched checkout. This file pays for exactly ONE launch for the whole class, the
 * same frugality that file argues for, and its existence is therefore itself a
 * measurement of that recorded hazard rather than an assumption about it. The P6.S4
 * config-seed pin reuses that one launch rather than asking for a second: a
 * disable-list has no observable effect except at boot, so it is served by capturing
 * the launch's own output in `setUpBeforeClass()` — see
 * {@see self::testTheLaunchSeedsTheDisableListFromTheUsersOwnConfig()}.
 *
     * `Bootstrap::chat()` is the seam this reads the wiring off, because it is the one
     * place the pair is created together: `Bootstrap::app()` — the entry `bin/sugarcrush`
     * actually calls — builds its shell by calling `chat()`, so every shell the binary
     * ever hosts, wrapped in `Program` or not, comes through the lines pinned here. The
     * narrower seam is also the quieter one: `app()` additionally constructs the session
     * stores and the worktree machinery around the same call, and none of that is what
     * this file is about.
 *
 * The prompt bytes are NOT observable through the launched backend's own provider:
 * with the backend-selection chain cleared, `Bootstrap::backend()` degrades to an
 * {@see EchoProvider}, which answers with prose and never receives the system prompt
 * at all. So the byte half renders the state the LAUNCHED backend carries through
 * {@see Runtime::buildSystemPrompt()} — the same splice `Runtime::run()` uses — on a
 * fresh Runtime per call, because the prompt is memoised per-Runtime (§17.2) and two
 * renders off one instance would return one string twice and make the comparison
 * vacuous.
 */
final class RulesStateWiringTest extends TestCase
{
    // Used for its CHAIN constant only, exactly as
    // {@see AgentManagerWiringTest} uses it: the clear/restore helpers are instance
    // methods and this fixture is class-level, so the loop is spelled out below and
    // only the LIST is shared.
    use BackendSelectionEnvSandboxTrait;

    /** The pack both user directories would answer to; see {@see seedRulebook()}. */
    private const PACK = 'focus';

    /**
     * A SECOND pack, seeded into the same home and named — with
     * {@see SETTINGS_PACK} — by that home's `settings.json` under `disabledRules`,
     * the P6.S4 read-side key. See
     * {@see testTheLaunchSeedsTheDisableListFromTheUsersOwnConfig()} for why both
     * names come from that file and neither from `config.json`.
     */
    private const CONFIG_PACK = 'quiet';

    /** A body no other fixture in this suite emits, so presence is unambiguous. */
    private const SENTINEL = 'WIRING-SENTINEL-9B4C the pack is riding in the prompt';

    /** The second pack's own body, so its absence is attributable to it alone. */
    private const CONFIG_SENTINEL = 'CONFIG-SENTINEL-4D7A this pack is off before anything is typed';

    /**
     * A THIRD pack, named only in `settings.json`, so the name the launch carries
     * is attributable to the hand-authored user file rather than to whichever file
     * happens to win the key.
     */
    private const SETTINGS_PACK = 'terse';

    /** The settings-file pack's own body, same reason as {@see CONFIG_SENTINEL}. */
    private const SETTINGS_SENTINEL = 'SETTINGS-SENTINEL-6E1C this pack is off from the settings file';

    private static string $tempDir = '';
    private static string $repo = '';
    private static string $originalHome = '';
    private static mixed $originalServerHome = null;

    /** @var array<string, string|false> */
    private static array $originalBackendEnv = [];

    private static ?Chat $shell = null;

    /**
     * The disable list as the launch left it, captured BEFORE any test touches the
     * shared set, and the prompt rendered from that same launch-time state.
     *
     * Read off `setUpBeforeClass()` rather than off `$state` inside a test because
     * the class shares one launch and one {@see RulesState} across all its tests: a
     * test that re-read `$state->disabled()` would be asserting on whatever the
     * test that ran before it left behind, which is order-sensitivity dressed up as
     * a pin. Capturing at launch keeps the assertion about the BOOT PATH and keeps
     * every pre-existing pin in this file byte-identical.
     *
     * @var list<string>
     */
    private static array $disabledAtLaunch = [];

    private static string $promptAtLaunch = '';

    /**
     * ONE launch for the whole class, against a synthetic `$HOME` holding THREE rule
     * packs and a `settings.json` that names two of them in `disabledRules`, with a
     * backend-selection chain cleared so the launch lands on the ENGINE path.
     *
     * The extra packs exist for {@see self::testTheLaunchSeedsTheDisableListFromTheUsersOwnConfig()}
     * and they are seeded through the SAME launch rather than a second one because the
     * disable-list key's only observable effect is at boot — so a test that built
     * `RulesState` by hand would prove the constructor and not the wiring. The
     * class still pays for exactly ONE `Bootstrap::chat()` (see the class docblock);
     * the launch-time evidence is captured once in this method and the shared set
     * is then returned to empty so nothing else in this file can tell the seeded
     * packs were ever there.
     *
     * HOME is redirected for the class rather than per test because `Bootstrap`
     * resolves `~/.sugar-crush` off it, and a developer's real rule packs would ride
     * into the render comparisons below. BOTH spellings of HOME are set — `putenv()`
     * alone leaves the skill trees and the session stores reading the real home; see
     * {@see \SugarCraft\Crush\Tests\Support\HomeSandboxTrait}, which a class-level
     * fixture cannot use because its helpers are instance methods.
     *
     * The chain is cleared for the same window: a shell-out backend builds no prompt
     * at all, so a run with `$SUGARCRUSH_BACKEND_CMD` merely exported in the
     * developer's shell would have nothing to forward and this file would be asserting
     * the `CommandBackend` caveat instead of the engine wiring.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$tempDir = sys_get_temp_dir() . '/sugarcrush_rules_wiring_' . uniqid('', true);
        self::$repo = self::$tempDir . '/repo';
        mkdir(self::$tempDir . '/home', 0700, true);
        mkdir(self::$repo, 0755, true);

        self::$originalHome = getenv('HOME') ?: '';
        self::$originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . self::$tempDir . '/home');
        $_SERVER['HOME'] = self::$tempDir . '/home';

        foreach (self::CHAIN as $var) {
            self::$originalBackendEnv[$var] = getenv($var);
            putenv($var);
        }

        self::seedRulebook();

        self::$shell = Bootstrap::chat(self::$repo);

        // P6.S4's seed is read off the launch, then the shared set is returned to
        // the empty state every pre-existing pin in this file was written against
        // — see {@see self::$disabledAtLaunch} for why the evidence is captured
        // here instead of asserted from a later test. `toggle()` is the only
        // re-enabling API RulesState has, and each pack is checked first so a
        // regression in the seed cannot make THIS line the thing that flips a
        // pack on that was never off.
        $launched = self::$shell->rulesState();
        self::$disabledAtLaunch = $launched->disabled();
        self::$promptAtLaunch = self::renderFor($launched);

        foreach ([self::CONFIG_PACK, self::SETTINGS_PACK] as $pack) {
            if ($launched->isDisabled($pack)) {
                $launched->toggle($pack);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$shell = null;

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

        self::wipeTree(self::$tempDir);

        parent::tearDownAfterClass();
    }

    /**
     * The headline claim: the shell the boot path built and the backend that shell
     * carries hold the SAME {@see RulesState} object, not two equal ones.
     *
     * Fails if `rulesState: $rulesState` is deleted from `Bootstrap::chat()`'s `new
     * Chat(...)` (the shell then falls back to a set of its own — the `?? RulesState::new()`
     * default in `Chat`'s constructor — while the backend keeps the one the injector
     * built, so the two pointers differ) and if the `instanceof EngineBackend`
     * carry-out is deleted (the backend's slot stays null against a non-null accessor).
     * Equality would not be enough here: two instances with the same contents are
     * precisely the bug.
     */
    public function testTheLaunchedShellAndItsBackendHoldOneAndTheSameRulesState(): void
    {
        $chat = self::$shell;
        self::assertInstanceOf(Chat::class, $chat, 'the class fixture must have launched');

        $backend = $chat->backend();
        self::assertInstanceOf(
            EngineBackend::class,
            $backend,
            'with the backend chain cleared the launch must land on the engine path, or there is no '
                . 'prompt for the toggle set to reach',
        );
        self::assertSame($chat->rulesState(), $backend->rulesState());
    }

    /**
     * THE SEED, off the real boot path: the names in the operator's own
     * `settings.json` are in `RulesState::disabled()` at launch with NOTHING TYPED,
     * and the packs they name are out of the prompt the launched backend builds.
     *
     * This is the §1.10 half of P6.S4. {@see RulesState::new()}'s `$disabled`
     * parameter has existed since P6.S3 with zero production callers passing an
     * argument, and no amount of unit coverage of that constructor could have said
     * the launch uses it — so this test starts at `Bootstrap::chat()` and reads what
     * the launch produced.
     *
     * ## WHY BOTH NAMES COME FROM `settings.json` AND NONE FROM `config.json`
     *
     * The obvious fixture — one pack in each file — is not a test. `disabledRules` is
     * ONE key and {@see LayeredSettings::merge()} resolves keys whole-value with the
     * CLI-written file on top, so a name in the hand-authored file is invisible to
     * every reader the moment `config.json` carries the key at all. MEASURED on this
     * checkout: with `["from-settings"]` in one file and `["from-config"]` in the
     * other, `Bootstrap::readUserConfig()['disabledRules'] === ['from-config']` — one
     * value, not a merged list. Such a fixture would pin the `config.json` route twice
     * and the `settings.json` route — the file this step exists to read, because a
     * machine-written file is not where anyone spells out a rulebook name by hand —
     * not once: rewriting the seed to read {@see Bootstrap::rawUserConfig()} directly,
     * which is `config.json` alone, would leave it GREEN.
     *
     * Sole supplier is therefore what gives that mutation something to break, and the
     * claim has to stop right there. WHAT THIS FIXTURE DOES NOT PIN: `config.json` is
     * written as `{}`, and merging an empty layer is the identity, so the merged list
     * and the `settings.json` list are THE SAME LIST here, and a seed reading
     * `LayeredSettings::userLayer()` — or `settings.json` by any other route — is
     * indistinguishable under every assertion in this method. Measured rather than
     * argued: with the seed rewritten to read the user layer directly, all four tests
     * in this class stayed green. So this test proves one thing — the seed is not
     * `config.json` alone — and the opposite direction is pinned in
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLayeredSettingsTest::testDisabledRulesReachesTheMergedConfigFromEitherUserFile()},
     * the only fixture that gives the two files different values and composes the
     * merged read with the filter and the constructor, and at
     * {@see self::testTheSeedListIsBuiltFromTheMergedReadRatherThanFromEitherSingleUserFile()}.
     * Together the two files cover the whole route from either file to the launch;
     * neither is asked to pretend it covers the other's direction.
     *
     * The exact-list assert on {@see self::$disabledAtLaunch} is also the filter's
     * test: the file this class seeds names the packs ALONGSIDE a blank string, a
     * whitespace-only string, an int and a nested array, and a seed that forwarded
     * any of those would have thrown out of `RulesState::new()` and failed the
     * launch itself. So one assertion pins "both names arrived" and "the junk did
     * not" — the two polarities of the same guard, both through the production path.
     *
     * And the absence claim carries its known-positive control IN THIS TEST, through
     * the same renderer: the fixture re-enabled both packs after capturing the launch
     * evidence, so rendering the SAME state again must now show both sentinels. Without
     * that second half, "a sentinel is absent" would be equally consistent with a pack
     * that was never loaded, never named in the file, or simply mis-seeded.
     */
    public function testTheLaunchSeedsTheDisableListFromTheUsersOwnConfig(): void
    {
        self::assertSame(
            [self::CONFIG_PACK, self::SETTINGS_PACK],
            self::$disabledAtLaunch,
            'the launch must carry exactly the two usable names out of the six entries the settings file holds',
        );

        self::assertStringNotContainsString(
            self::CONFIG_SENTINEL,
            self::$promptAtLaunch,
            'the pack the operator named must be out of the prompt the launch builds',
        );
        self::assertStringNotContainsString(
            self::SETTINGS_SENTINEL,
            self::$promptAtLaunch,
            'the seed must be reading the MERGED view: this name exists only in settings.json, so a seed '
                . 'that opened config.json by itself would leave this pack in the prompt',
        );
        self::assertStringContainsString(
            self::SENTINEL,
            self::$promptAtLaunch,
            'and the pack nobody named must be unaffected — this is a per-name disable, not a rules off-switch',
        );

        // The control, and it is asserting on the LIVE set rather than the captured
        // launch snapshot: this is the same file, after setUpBeforeClass() handed the
        // shared state back to empty, so `disabled()` must now be the empty list and both
        // seeded packs must be renderable.
        $state = self::$shell?->rulesState();
        self::assertInstanceOf(RulesState::class, $state);
        self::assertSame([], $state->disabled(), 'the fixture must leave the shared set empty for the pins below');

        $enabled = self::renderFor($state);
        self::assertStringContainsString(self::CONFIG_SENTINEL, $enabled);
        self::assertSame(
            1,
            substr_count($enabled, self::CONFIG_SENTINEL),
            'the seeded pack rides exactly once now that it is back on',
        );
        self::assertStringContainsString(self::SETTINGS_SENTINEL, $enabled);
        self::assertSame(
            1,
            substr_count($enabled, self::SETTINGS_SENTINEL),
            'the settings-file pack rides exactly once now that it is back on',
        );
    }

    /**
     * WHICH READER THE SEED EXPRESSION USES, pinned at the one place it is written.
     *
     * This is the half the single launch cannot reach on its own, and the reason it
     * lives in a launching file rather than beside the fixture that owns the two-file
     * case: `Bootstrap::chat()` builds the seed from `$userConfig`, and this pins that
     * `$userConfig` IS the merged read `self::readUserConfig()` and that the seed
     * indexes `disabledRules` off THAT variable — not off `rawUserConfig()`, not off
     * `LayeredSettings::userLayer()`, not off a second call of its own.
     *
     * WHY A SOURCE READ: the mutation this prices — retyping the seed's argument as
     * `self::rawUserConfig()['disabledRules'] ?? null`, or as
     * `LayeredSettings::userLayer(...)` — is invisible to every non-launching test AND
     * to this class's own launch, because this file's `config.json` is `{}` and the
     * merge of an empty layer is the identity. So the route is pinned behaviourally
     * next door, at
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLayeredSettingsTest::testDisabledRulesReachesTheMergedConfigFromEitherUserFile()},
     * and the call site is pinned here, where reading `src/Cli/Bootstrap.php` costs no
     * launch at all. One `Bootstrap::chat()` remains the whole class's bill and this
     * method pays none of it: it is a `file_get_contents`, the same instrument
     * {@see testTheBackendReadsItsToggleSetPerTurnRatherThanFreezingItAtLaunch()} uses.
     *
     * Both patterns are deliberately narrow — they match the statement as written, so
     * a reformat reddens here rather than leaving the guard quietly matching some older
     * shape forever.
     */
    public function testTheSeedListIsBuiltFromTheMergedReadRatherThanFromEitherSingleUserFile(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');

        // Half one: what feeds the seed — one statement, over a variable, whose name
        // half two then binds to a reader.
        preg_match_all(
            '/\$rulesState\s*=\s*RulesState::new\(\s*self::rulePacksToDisable\(\s*(\$\w+)\[[\'"]disabledRules[\'"]\]/',
            $source,
            $seed,
        );
        self::assertCount(
            1,
            $seed[1] ?? [],
            'Bootstrap::chat() must build the seed with exactly one RulesState::new(rulePacksToDisable(...)) '
                . 'statement over a variable — a seed fed straight from a call expression is not the shape '
                . 'this guard, or the merged view it is supposed to read, describes',
        );
        self::assertSame(
            '$userConfig',
            $seed[1][0],
            'the seed must index `disabledRules` off `$userConfig`; any other name is a different read',
        );

        // Half two: what that variable IS.
        self::assertSame(
            1,
            preg_match('/\$userConfig\s*=\s*self::readUserConfig\(\);/', $source),
            '`$userConfig` must be exactly one merged self::readUserConfig() call — rebinding it to '
                . 'self::rawUserConfig() or to LayeredSettings::userLayer() would make the launch read '
                . 'one user file instead of the merged view',
        );
    }

    /**
     * The read half: a toggle typed into the LAUNCHED shell takes the pack out of the
     * prompt the launched backend would build, and typing it again puts it back.
     *
     * This is the assertion that survives a wiring which shares the object but never
     * consults it. The typed path is the real one — `Chat::update()` on Enter, exactly
     * what the event loop calls — because the point of the pin is the boot path, and a
     * test that called `RulesCommand` directly would pass against a `Chat` built by
     * hand.
     *
     * Both polarities, because a set that is read once in one direction is
     * indistinguishable from a set that is never re-read: an implementation that
     * rendered the first prompt and memoised the answer forever would pass a
     * one-direction test, and §17.2's memoisation lives one level below this seam
     * where it could plausibly be done wrong.
     */
    public function testAToggleTypedIntoTheLaunchedShellMovesThePackOutOfThePromptItBuilds(): void
    {
        $chat = self::$shell;
        self::assertInstanceOf(Chat::class, $chat);
        $backend = $chat->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);

        $state = $backend->rulesState();
        self::assertInstanceOf(RulesState::class, $state, 'the boot injector must have filled the slot');

        self::assertSame(
            self::renderFor($state),
            self::renderFor($chat->rulesState()),
            'before anything is typed, the two owners render the same prompt',
        );
        self::assertStringContainsString(self::SENTINEL, $this->promptOf($backend));

        $off = $this->typeIntoShell($chat, '/rules ' . self::PACK);
        self::assertStringContainsString('Pack ' . self::PACK . ': OFF for this session.', $off);
        self::assertSame(
            $state,
            $backend->rulesState(),
            'the toggle must mutate the set the backend holds, not swap the object out from under it',
        );
        self::assertSame([self::PACK], $state->disabled());
        self::assertStringNotContainsString(
            self::SENTINEL,
            $this->promptOf($backend),
            'the pack the shell just switched off must be out of the bytes the backend builds',
        );

        $on = $this->typeIntoShell($chat, '/rules ' . self::PACK);
        self::assertStringContainsString('Pack ' . self::PACK . ': ON for this session.', $on);
        self::assertSame([], $state->disabled());
        self::assertStringContainsString(self::SENTINEL, $this->promptOf($backend));
    }

    /**
     * The set the launched backend carries is not merely consulted at construction:
     * `complete()` re-reads it onto every turn's App, so a state that exists but is
     * copied once at launch and frozen cannot pass this.
     *
     * Asserted against the live source rather than by running a turn: a full turn
     * needs a provider that reports the prompt it was handed, and the offline
     * {@see EchoProvider} this launch resolves to never sees the system prompt at all.
     * The carry's BEHAVIOUR is pinned from a hand-built backend at
     * {@see \SugarCraft\Crush\Tests\Commands\RulesCommandTest::testTheToggledSetReachesTheSystemPromptThroughAPerTurnCarry()};
     * what that test cannot see is whether the production boot path ever fills the
     * slot it reads, which is what this file is for.
     */
    public function testTheBackendReadsItsToggleSetPerTurnRatherThanFreezingItAtLaunch(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Backend/EngineBackend.php');

        // Step 1.A-2 moved the per-turn App's session half into sessionApp()
        // (shared with the parent's prompt-memo prime); the turn builds on it.
        self::assertMatchesRegularExpression(
            '/\$app = \$this->sessionApp\(\).*?->withMessages\(/s',
            $source,
            'EngineBackend::complete() must build its per-turn App on sessionApp()',
        );
        self::assertMatchesRegularExpression(
            '/function sessionApp\(\): App\s*\{\s*return App::new\([^;]*?->withRulesState\(\$this->rulesState\)[^;]*;/s',
            $source,
            'EngineBackend::sessionApp() must carry $this->rulesState onto the App it builds per turn, or the '
                . 'set the boot path injected is read once and frozen for the session',
        );
    }

    // -- helpers --------------------------------------------------------------

    /**
     * Three packs in the user tier, plus the hand-authored settings file that turns
     * two of them off.
     *
     * The choice of `rulebooks/` over `rules/` is arbitrary and said as much: both
     * directories are walked by {@see \SugarCraft\Crush\Context\RuleLoader::loadUserRules()}
     * and {@see \SugarCraft\Crush\Context\RuleLoader::loadUserRulebooks()}, both are
     * rendered behind the same fence, and each half already has its own render pin
     * next door. One directory is seeded because the test needs a pack, not because
     * the directory matters; seeding both would only add a second file to keep track
     * of, and the collision case between them belongs to
     * {@see \SugarCraft\Crush\Tests\Context\RuleLoaderTest::testTheSameStemInBothUserDirectoriesStaysTwoPacksToggledByOneName()}
     * and to {@see \SugarCraft\Crush\Tests\Commands\RulesCommandTest::testTogglingANameTwoPacksShareDisclosesThatBothMoved()},
     * not here.
     *
     * ## `settings.json` is the SOLE supplier of `disabledRules`; `config.json` has none
     *
     * Not an oversight and not a simplification — the polarity is what makes the seed
     * pin non-vacuous. {@see \SugarCraft\Crush\Config\LayeredSettings::merge()} resolves
     * ONE key whole-value with the CLI-written file on top (measured: a name in each
     * file yields the `config.json` list alone), so any name written into
     * `config.json` here would eclipse the `settings.json` names and the launch test
     * below would then be pinning the deprecated file twice while saying nothing about
     * the file this step exists to read. The `config.json` half of that route is pinned
     * where it can be seen on its own, at
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLayeredSettingsTest::testDisabledRulesReachesTheMergedConfigFromEitherUserFile()}.
     * The file is still written — with `0600`, as every policy file here is — so the
     * stack under test has its real four layers. WHAT THAT DOES NOT BUY, stated so the
     * next reader does not over-read it: an empty `config.json` is the identity for
     * {@see \SugarCraft\Crush\Config\LayeredSettings::merge()}, so this fixture pins
     * the settings route and says nothing about the merged ranking. That direction
     * lives in `BootstrapLayeredSettingsTest`, whose two-file case gives the two files
     * DIFFERENT lists.
     *
     * The `disabledRules` value written there is deliberately NOT the tidy
     * `["quiet"]` a config would show: it is two names followed by a blank string, a
     * whitespace-only string, an int and a nested array. The two blanks are the
     * load-bearing entries — they price the `trim($pack) !== ''` clause, and
     * {@see \SugarCraft\Crush\Context\RulesState::new()} rejects a blank outright, so an
     * unfiltered seed makes the LAUNCH throw. The int and the nested array are realism
     * rather than coverage: they price only the `is_string` clause, and the
     * eleven-shape table at
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLayeredSettingsTest::testTheRulesSeedKeepsOnlyUsablePackNamesFromEveryJunkShape()}
     * is what pins that clause for real. What this fixture earns by carrying them is
     * one launch proving both halves of P6.S4 together — that the names in the
     * operator's own settings file reach the prompt, which is what
     * {@see \SugarCraft\Crush\Context\RulesState::new()}'s `$disabled` parameter had
     * never done in production before this step, and that the junk around them is
     * filtered on the way in rather than thrown out of. A `try`/`catch` would have
     * hidden the first by turning "the junk was refused" into "nothing was disabled".
     *
     * `PACK` is NOT named in the list, so the seeded packs and the toggled pack stay
     * two different claims.
     */
    private static function seedRulebook(): void
    {
        $dir = self::$tempDir . '/home/.sugar-crush';
        mkdir($dir . '/rulebooks', 0700, true);
        foreach ([
            self::PACK => self::SENTINEL,
            self::CONFIG_PACK => self::CONFIG_SENTINEL,
            self::SETTINGS_PACK => self::SETTINGS_SENTINEL,
        ] as $pack => $body) {
            file_put_contents(
                $dir . '/rulebooks/' . $pack . '.md',
                "---\nname: " . ucfirst($pack) . "\n---\n\n" . $body . "\n",
            );
        }

        file_put_contents($dir . '/settings.json', (string) json_encode([
            'disabledRules' => [self::CONFIG_PACK, self::SETTINGS_PACK, '', '   ', 0, ['nested']],
        ]));
        // BOTH user files get the mode, not only the one whose comment below explains
        // it: permissionConfigLayers() runs requirePrivatePolicyFile() over
        // settings.json exactly as it does over config.json, and that check refuses the
        // launch for a world-writable policy file. On the usual umask 022 a
        // file_put_contents lands 0644 and passes; measured on this host, umask 000
        // lands 0666 and umask 020 lands 0646, and either would make the single launch
        // in setUpBeforeClass() throw for a reason nothing in this file is about.
        chmod($dir . '/settings.json', 0o600);

        $config = $dir . '/config.json';
        // An EMPTY object on purpose: the layer must exist so the merged view the
        // seed reads is the real one, and must stay silent on `disabledRules` so the
        // names below are attributable to `settings.json` alone.
        file_put_contents($config, '{}');
        // permissionConfig() refuses a policy file anyone else can write, and the
        // launch must not be spending its one pass on a refusal.
        chmod($config, 0o600);
    }

    /** The prompt the launched backend would build from the state it carries now. */
    private function promptOf(EngineBackend $backend): string
    {
        return self::renderFor($backend->rulesState());
    }

    /**
     * One render per call on a FRESH Runtime: the system prompt is memoised per-Runtime
     * (§17.2), so two states rendered through one instance would return the first
     * string twice and make every comparison in this file vacuous.
     */
    private static function renderFor(?RulesState $state): string
    {
        $app = App::new(new EchoProvider(), 'echo')
            ->withRoot(self::$repo)
            ->withRulesState($state);

        $runtime = new Runtime(new EchoProvider(), new HookManager(new HookRegistry()));
        $build = new ReflectionMethod($runtime, 'buildSystemPrompt');
        $build->setAccessible(true);

        return (string) $build->invoke($runtime, $app);
    }

    /**
     * Drive a typed line through the real `Chat::update()` dispatch — the same
     * reflected `withInputBuf` seam {@see AgentManagerWiringTest} uses, because the
     * input buffer is private and the alternative is a keystroke-per-character loop
     * that tests nothing the dispatch does not already test.
     */
    private function typeIntoShell(Chat $chat, string $command): string
    {
        $withInputBuf = new ReflectionMethod($chat, 'withInputBuf');
        $withInputBuf->setAccessible(true);
        $withBuf = $withInputBuf->invoke($chat, $command);

        [$next, ] = $withBuf->update(new KeyMsg(KeyType::Enter, ''));
        self::assertInstanceOf(Chat::class, $next);

        return $next->history[array_key_last($next->history)]->content;
    }

    private static function wipeTree(string $dir): void
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
