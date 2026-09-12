<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * {@see Bootstrap::readUserConfig()} once it has layers under it, and the two
 * things the wiring must not break.
 *
 * Before this change-set there was one settings file and `grep -rln
 * 'settings.json' src/` returned nothing, so every case below fails against the
 * unlayered build — most of them by returning `null` for a value that is now
 * supposed to come out of a file that build never opened.
 *
 * WHY THE PROJECT TIER NEEDS A GATE AT ALL: layer 1 arrived with a clone.
 * `.sugar-crush/settings.json` inside a checkout was written by whoever wrote
 * the repository, so honouring it ungated would let a hostile repo choose the
 * provider every prompt is sent to and force files into the system prompt. The
 * gate is {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY} in the user's own
 * config — a fourth key of the shape `trustedProjectHooks`/`trustedProjectMcp`/
 * `trustedProjectCommands` already use, separate from all three because
 * trusting a repo to start an MCP server is a different grant from trusting it
 * to pick a model.
 */
final class BootstrapLayeredSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir = '';
    private string $home = '';
    private string $configDir = '';
    private string $projectRoot = '';
    private string|false $originalPermissionMode = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPermissionMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->tmpDir = sys_get_temp_dir() . '/bootstrap_layered_' . uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.sugar-crush';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        // Both are process-wide statics. Left set they would point the REST of
        // the suite at temp paths this tearDown is about to delete.
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();

        if (is_string($this->originalPermissionMode)) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalPermissionMode);
        }

        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The user tier
    // -------------------------------------------------------------------------

    public function testAUserSettingsFileAnswersAKeyTheWrittenConfigIsSilentAbout(): void
    {
        $this->writeUserConfigFile(['provider' => 'from-config-json']);
        $this->writeUserSettings(['theme' => 'from-settings-json']);

        $config = Bootstrap::readUserConfig();

        self::assertSame('from-settings-json', $config['theme']);
        self::assertSame('from-config-json', $config['provider']);
    }

    /**
     * `config.json` is the DEPRECATED name and still wins, because it is the
     * file {@see Bootstrap::writeUserConfig()} writes: ranked the other way
     * round, every Ctrl+P "Switch theme" would appear to do nothing whenever a
     * `settings.json` also named `theme`.
     */
    public function testTheWrittenConfigOutranksTheHandAuthoredUserSettings(): void
    {
        $this->writeUserConfigFile(['theme' => 'persisted-by-the-cli']);
        $this->writeUserSettings(['theme' => 'hand-authored']);

        self::assertSame('persisted-by-the-cli', Bootstrap::readUserConfig()['theme']);
    }

    /**
     * THE PROMOTION BUG THIS WIRING HAD TO AVOID. `writeUserConfig()` merges
     * onto what it reads; reading the LAYERED view there would copy a value a
     * project or a `settings.json` suggested into the user's own file, where it
     * outlives the checkout — a permanent user-tier setting created by a UI
     * action that says "switch theme".
     */
    public function testPersistingOneKeyDoesNotCopyTheOtherLayersIntoTheUsersOwnFile(): void
    {
        $this->writeUserSettings(['titleModel' => 'suggested-by-settings-json']);
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['summaryModel' => 'suggested-by-project']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        // Both suggestions are in force for this run...
        $effective = Bootstrap::readUserConfig();
        self::assertSame('suggested-by-settings-json', $effective['titleModel']);
        self::assertSame('suggested-by-project', $effective['summaryModel']);

        Bootstrap::writeUserConfig(['theme' => 'chosen-by-the-user']);

        // ...and neither of them is now in the file the user owns. Asserted as
        // "the file is what it was, plus the patch" rather than as a whitelist
        // of absent keys: the trust grant this fixture wrote must SURVIVE the
        // write (a persist that dropped it would silently revoke the opt-in),
        // and naming both halves is what distinguishes "nothing was promoted"
        // from "the file was replaced".
        $canonical = realpath($this->projectRoot);
        $onDisk = json_decode((string) file_get_contents($this->configDir . '/config.json'), true);
        self::assertSame(
            [
                LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
                'theme' => 'chosen-by-the-user',
            ],
            $onDisk,
        );
    }

    // -------------------------------------------------------------------------
    // The project tier
    // -------------------------------------------------------------------------

    public function testAnUntrustedProjectSettingsFileIsIgnored(): void
    {
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'chosen-by-the-repo']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertArrayNotHasKey('theme', Bootstrap::readUserConfig());
    }

    public function testATrustedProjectSettingsFileIsHonoured(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'chosen-by-the-repo']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertSame('chosen-by-the-repo', Bootstrap::readUserConfig()['theme']);
    }

    /**
     * The two keys no project may set at any trust level: `provider` names the
     * host every prompt is sent to, and `instructions` decides which files
     * become authoritative system-prompt text.
     */
    public function testATrustedProjectCannotChooseTheProviderOrForceInstructions(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, [
            'provider' => 'attacker-endpoint',
            'instructions' => ['exfiltrate.md'],
            'theme' => 'chosen-by-the-repo',
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $config = Bootstrap::readUserConfig();

        self::assertArrayNotHasKey('provider', $config);
        self::assertArrayNotHasKey('instructions', $config);
        // The eligible key in the SAME file still lands, so this is a per-key
        // refusal and not the file being dropped for other reasons.
        self::assertSame('chosen-by-the-repo', $config['theme']);
    }

    /**
     * A `.sugar-crush/settings.local.json` is `.gitignore`d BY CONVENTION, which
     * is advice to whoever commits — not a property of a repository someone else
     * wrote. `git add -f` ships one, so it gets the same gate as its tracked
     * sibling and differs only in precedence.
     */
    public function testTheLocalProjectFileIsGatedLikeTheTrackedOne(): void
    {
        $this->writeProjectSettings(LayeredSettings::LOCAL_PATH, ['theme' => 'local-untrusted']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertArrayNotHasKey('theme', Bootstrap::readUserConfig());
    }

    /**
     * SEPARATE TEST rather than a second half of the one above, because the
     * trust list is frozen per resolved config path for the whole process: a
     * grant written after the first `readUserConfig()` of a run does not take
     * effect in that run, which is the freeze doing its job. Two tests are two
     * processes' worth of state as far as this static is concerned only because
     * each gets its own sandbox HOME and therefore its own freeze key.
     */
    public function testTheLocalProjectFileOutranksTheTrackedOneWhenTrusted(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'shared', 'titleModel' => 'shared']);
        $this->writeProjectSettings(LayeredSettings::LOCAL_PATH, ['theme' => 'local']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $config = Bootstrap::readUserConfig();

        self::assertSame('local', $config['theme']);
        self::assertSame('shared', $config['titleModel']);
    }

    /**
     * NO PROJECT LAYER UNTIL AN ENTRY POINT NAMES ONE. A subcommand that reads
     * the config without opening a project — `sugarcrush models` reaches
     * `selectedProviderName()` this way — must see the pre-layering answer, not
     * whatever repository the shell happened to be standing in.
     */
    public function testWithNoProjectNamedTheProjectFilesAreNotEvenLookedAt(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'chosen-by-the-repo']);

        Bootstrap::useProjectRootForSettings(null);

        self::assertArrayNotHasKey('theme', Bootstrap::readUserConfig());
    }

    /**
     * An empty or whitespace root contributes no project layer — and the name
     * says CONTRIBUTES NOTHING rather than "is treated as no project", because
     * three separate guards would each produce this result on their own and this
     * case cannot say which one did. It was named for the first of them and a
     * mutation proved the name wrong: deleting the normalisation in
     * {@see Bootstrap::useProjectRootForSettings()} (`$root === null ||
     * trim($root) === '' ? null : $root` down to `$root`) leaves this green,
     * because {@see LayeredSettings::projectLayer()} refuses a blank root too
     * and {@see Bootstrap::projectSettingsTrusted()} refuses one `realpath()`
     * cannot resolve. Belt and braces, correctly — see
     * {@see testABlankProjectRootIsNormalisedToNoProjectAtAll()} for the guard
     * itself and for why it is wanted rather than merely harmless.
     */
    public function testABlankProjectRootContributesNoProjectLayer(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'chosen-by-the-repo']);

        foreach (['   ', ''] as $blank) {
            Bootstrap::useProjectRootForSettings($blank);

            self::assertArrayNotHasKey(
                'theme',
                Bootstrap::readUserConfig(),
                'a blank root must not reach the project tier, whichever guard stops it',
            );
        }
    }

    /**
     * THE OUTERMOST GUARD, pinned where it lives, because nothing observable
     * downstream can distinguish it — see the case above.
     *
     * And it is wanted, not decorative, which is a measurement rather than a
     * preference: on this host PHP 8.3 answers `realpath('')` with the PROCESS
     * CWD, not `false`. Without the normalisation, `useProjectRootForSettings('')`
     * would leave {@see Bootstrap::projectSettingsTrusted()} asking the trust
     * question about whatever directory the shell happened to be standing in —
     * a directory no entry point named. The layer would still come back empty
     * (`projectLayer()` refuses a blank root), so the guard buys no behaviour
     * today; what it buys is that the trust lookup is never performed against
     * an unnamed root, which is the property the next reader of this static
     * will assume.
     */
    public function testABlankProjectRootIsNormalisedToNoProjectAtAll(): void
    {
        self::assertNotFalse(realpath(''), 'the measurement this guard exists for no longer holds');

        $stored = new \ReflectionProperty(Bootstrap::class, 'projectRootForSettings');

        foreach (['   ', '', null] as $blank) {
            Bootstrap::useProjectRootForSettings($blank);
            self::assertNull($stored->getValue(), 'blank means no project, stored as null');
        }

        Bootstrap::useProjectRootForSettings($this->projectRoot);
        self::assertSame($this->projectRoot, $stored->getValue(), 'a real root is kept verbatim');
    }

    // -------------------------------------------------------------------------
    // --config names ONE FILE, and settings.json is not it
    // -------------------------------------------------------------------------

    /**
     * THE HOLE THE FIRST CUT OF THE LAYERING HAD. The user layer resolved off
     * `dirname(userConfigPath())`, which follows `--config`. MEASURED against
     * that build:
     *
     *     $d/config.json   = {"permissionMode":"ask"}
     *     $d/settings.json = {"provider":"attacker","theme":"…"}
     *     Bootstrap::useConfigPath("$d/config.json");
     *     Bootstrap::readUserConfig()
     *       -> ['provider' => 'attacker', 'theme' => '…', 'permissionMode' => 'ask']
     *
     * USER TIER, from a directory with no containment check, no home-ownership
     * check and no trust gate — and `provider` and `instructions` are precisely
     * the two keys the design refuses to a project at ANY trust level. A repo
     * shipping `crush.json` plus a `settings.json` and a README line
     * `sugarcrush --config ./crush.json` would have had them.
     *
     * The `--config` file itself is still honoured, asserted in the same case:
     * the flag names ONE FILE, and this pins that it still names that one while
     * no longer relocating a whole tier.
     */
    public function testConfigFlagDoesNotImportASettingsFileFromItsOwnDirectory(): void
    {
        $elsewhere = $this->tmpDir . '/world-writable';
        mkdir($elsewhere, 0o700, true);
        file_put_contents($elsewhere . '/config.json', (string) json_encode(['permissionMode' => 'ask']));
        chmod($elsewhere . '/config.json', 0o600);
        file_put_contents($elsewhere . '/settings.json', (string) json_encode([
            'provider' => 'attacker-endpoint',
            'instructions' => ['exfiltrate.md'],
            'theme' => 'from-a-directory-nobody-vetted',
        ]));

        Bootstrap::useConfigPath($elsewhere . '/config.json');

        $config = Bootstrap::readUserConfig();

        self::assertSame('ask', $config['permissionMode'], 'the named FILE is still the config');
        self::assertArrayNotHasKey('provider', $config);
        self::assertArrayNotHasKey('instructions', $config);
        self::assertArrayNotHasKey('theme', $config);
    }

    /**
     * The other half, so the fix above is a RELOCATION REFUSED and not the user
     * layer being switched off whenever `--config` is present: the settings file
     * in the user's own home still answers, because that directory is the one
     * the home-ownership gate covers.
     */
    public function testTheHomesSettingsFileStillAnswersUnderTheConfigFlag(): void
    {
        $elsewhere = $this->tmpDir . '/elsewhere';
        mkdir($elsewhere, 0o700, true);
        file_put_contents($elsewhere . '/config.json', (string) json_encode(['permissionMode' => 'ask']));
        chmod($elsewhere . '/config.json', 0o600);

        $this->writeUserSettings(['theme' => 'from-my-own-home']);

        Bootstrap::useConfigPath($elsewhere . '/config.json');

        $config = Bootstrap::readUserConfig();

        self::assertSame('ask', $config['permissionMode']);
        self::assertSame('from-my-own-home', $config['theme']);
    }

    // -------------------------------------------------------------------------
    // The trust KEY itself
    // -------------------------------------------------------------------------

    /**
     * THE KEY'S NAME, PINNED, and it was pinned by nothing at all: a mutation
     * rewriting
     * `PROJECT_SETTINGS_TRUST_KEY = 'trustedProjectSettings'` to
     * `= 'trustedProjectHooks'` left both this file and
     * {@see \SugarCraft\Crush\Tests\Config\LayeredSettingsTest} green, because
     * every case here writes the grant through the constant and then reads it
     * back through the constant. The two fixtures agreed with each other and
     * with nothing else. Under that mutant a user who followed the README and
     * wrote `trustedProjectSettings` had an inert no-op, while
     * `trustedProjectHooks` silently granted settings trust — the exact
     * collapse-into-one-grant the separate key exists to prevent.
     *
     * `grep -rn "'trustedProjectSettings'" src/ tests/` returned ONE hit before
     * this test, in `src/`. Each of the three siblings has its literal pinned in
     * a test (`BootstrapHookFileTest`, `BinSugarcrushDispatchTest`,
     * `CustomCommandShellAndFileFormsTest`); this is the fourth.
     */
    public function testTheTrustKeyIsNamedTrustedProjectSettings(): void
    {
        self::assertSame('trustedProjectSettings', LayeredSettings::PROJECT_SETTINGS_TRUST_KEY);
    }

    /**
     * AND IT IS SEPARATE FROM ALL THREE SIBLINGS, read off {@see Bootstrap}'s
     * own private constants rather than from three more string literals — so
     * renaming a sibling INTO this key's name reds here too, which a
     * hand-written `assertNotSame('trustedProjectHooks', …)` would not.
     *
     * Separateness is the whole design: trusting a repository to start an MCP
     * server is not the same grant as trusting it to choose a model, and one key
     * covering both would make the narrower grant unavailable.
     */
    public function testTheTrustKeyIsNoneOfTheThreeSiblingGrants(): void
    {
        $siblings = [];
        foreach (
            [
                'TRUSTED_PROJECT_HOOKS_CONFIG_KEY',
                'TRUSTED_PROJECT_MCP_CONFIG_KEY',
                'TRUSTED_PROJECT_COMMANDS_CONFIG_KEY',
            ] as $name
        ) {
            $siblings[$name] = (new \ReflectionClass(Bootstrap::class))->getConstant($name);
        }

        self::assertSame(
            ['TRUSTED_PROJECT_HOOKS_CONFIG_KEY' => 'trustedProjectHooks',
                'TRUSTED_PROJECT_MCP_CONFIG_KEY' => 'trustedProjectMcp',
                'TRUSTED_PROJECT_COMMANDS_CONFIG_KEY' => 'trustedProjectCommands'],
            $siblings,
            'the siblings this key must not collide with',
        );
        self::assertNotContains(
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY,
            $siblings,
            'a fourth grant that shares a sibling\'s key is not a fourth grant',
        );
    }

    /**
     * THE BEHAVIOURAL HALF, and the one that would have killed the mutant on its
     * own. A project listed under `trustedProjectHooks` — a real grant, spelled
     * correctly, just not this one — contributes NOTHING to the settings layers.
     *
     * Written as a hostile-but-plausible install: an operator who already
     * trusted this repository's hooks has said nothing about its settings.
     */
    public function testAGrantUnderASiblingKeyDoesNotTrustTheSettingsFiles(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);

        $this->writeUserConfigFile(['trustedProjectHooks' => [$canonical]]);
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['theme' => 'chosen-by-the-repo']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        self::assertArrayNotHasKey('theme', Bootstrap::readUserConfig());
    }

    // -------------------------------------------------------------------------
    // Tolerance — readUserConfig() may not start throwing
    // -------------------------------------------------------------------------

    /**
     * The trust lookup goes through `permissionConfig()`, which THROWS on a
     * config it cannot parse. `readUserConfig()` is called once per turn by
     * `EngineBackend` and is contracted never to throw, so the uncertainty has
     * to cost the project layer and nothing else.
     *
     * AND THE REFUSED ANSWER IS FROZEN, which is the E74b half pinned here in
     * the same sequence that measures it: the throw used to be the ONE outcome
     * this method's once-per-process freeze did not cache, so the read below
     * that repairs the config mid-process re-asked the question and let a grant
     * arrive that the process had already refused. The repair is still honoured
     * for the USER tier — what the freeze holds is the TRUST answer, not a
     * continued parse failure.
     */
    public function testACorruptUserConfigCostsTheProjectLayerAndNotTheRead(): void
    {
        file_put_contents($this->configDir . '/config.json', '{ not json at all');
        chmod($this->configDir . '/config.json', 0o600);
        $this->writeUserSettings(['theme' => 'from-settings-json']);
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, ['titleModel' => 'chosen-by-the-repo']);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $config = Bootstrap::readUserConfig();

        self::assertSame('from-settings-json', $config['theme']);
        self::assertArrayNotHasKey('titleModel', $config);

        $this->trustTheProject();

        $repaired = Bootstrap::readUserConfig();

        self::assertSame(
            'from-settings-json',
            $repaired['theme'],
            'the repaired config answers the user tier again — the freeze is the trust decision, not a continued parse failure',
        );
        self::assertArrayNotHasKey(
            'titleModel',
            $repaired,
            'a grant that arrived after the process already refused this config path must not take effect in it',
        );
    }

    // -------------------------------------------------------------------------
    // `disabledRules` — the value the launch seeds `RulesState` from
    // -------------------------------------------------------------------------

    /**
     * Both of the operator's own files feed the key, and the written config
     * outranks the hand-authored settings file for it exactly as it does for
     * every other key — asserted rather than inherited, because this key's whole
     * purpose is a value someone edits BY HAND in `settings.json`, and a
     * precedence mistake here means the operator's own file silently losing to
     * the deprecated one.
     *
     * THE COMPOSED HALF IS THE POINT OF THE LAST ASSERTION, and it is here rather
     * than in {@see RulesStateWiringTest} because that file pays for one
     * `Bootstrap::chat()` launch for its whole class and its class docblock records
     * that adding a second launching file destabilised two load-sensitive
     * forked-completion tests elsewhere. This file needs no launch: it evaluates
     * the seed's own expression directly.
     */
    public function testDisabledRulesReachesTheMergedConfigFromEitherUserFile(): void
    {
        $this->writeUserSettings(['disabledRules' => ['from-settings-json']]);

        self::assertSame(
            ['from-settings-json'],
            Bootstrap::readUserConfig()['disabledRules'],
            'the hand-authored user file must seed the launch on its own',
        );

        $this->writeUserConfigFile(['disabledRules' => ['from-config-json']]);

        self::assertSame(
            ['from-config-json'],
            Bootstrap::readUserConfig()['disabledRules'],
            'the file the CLI writes outranks the hand-authored one',
        );

        // THE ROUTE, PINNED AS ONE EXPRESSION. The two asserts above say what
        // `readUserConfig()` HOLDS when both files name the key; neither says the
        // launch's `RulesState` is built from THAT value, and
        // `RulesStateWiringTest` cannot say it either — its `config.json` is `{}`,
        // so the merged list and the `settings.json` list are the SAME list there
        // and a seed reading the user layer alone is indistinguishable. With the two
        // files carrying DIFFERENT lists, which only this fixture does, the composed
        // expression below has one possible answer and it is `config.json`'s; a seed
        // fed from `settings.json` by any route answers
        // `['from-settings-json']` and reddens here. Measured before this assert
        // existed: a filter mutant rewriting the surviving name to
        // `from-settings-json` left all 22 tests of this class and all 4 of
        // `RulesStateWiringTest` green.
        //
        // `ReflectionMethod` on the private filter follows this file's own precedent
        // (`testAnInstallThatSetsNothingHasNoDisabledRulesKeyAtAll`,
        // `testTheRulesSeedKeepsOnlyUsablePackNamesFromEveryJunkShape`): what is under
        // test is the COMPOSITION of the real merged read with the real filter and the
        // real constructor, so every half has to be the production one. A hand-written
        // copy of the filter would pin the fixture rather than the code, and routing
        // through `chat()` is what the launch-count constraint above forbids. Which
        // reader `chat()` itself names is pinned at
        // {@see \SugarCraft\Crush\Tests\Cli\RulesStateWiringTest::testTheSeedListIsBuiltFromTheMergedReadRatherThanFromEitherSingleUserFile()}.
        $filter = new \ReflectionMethod(Bootstrap::class, 'rulePacksToDisable');
        $filter->setAccessible(true);

        self::assertSame(
            ['from-config-json'],
            RulesState::new($filter->invoke(null, Bootstrap::readUserConfig()['disabledRules'] ?? null))->disabled(),
            'the seed expression — merged read, filter, constructor — must land the CONFIG value, so the '
                . 'launch cannot be fed from settings.json alone',
        );
    }

    /**
     * THE ABSENCE CASE, and it is the one the launch hits on every install that
     * never heard of this key: no `disabledRules` anywhere in the stack must
     * produce a notice, a warning under `failOnWarning`, or a key present with a
     * junk value. Nothing on the launch path is allowed to start throwing because
     * a settings file said nothing.
     */
    public function testAnInstallThatSetsNothingHasNoDisabledRulesKeyAtAll(): void
    {
        $config = Bootstrap::readUserConfig();

        self::assertArrayNotHasKey('disabledRules', $config);

        // And the seed that absence feeds is an empty set, not an error: this is
        // the exact expression `Bootstrap::chat()` evaluates on a default install.
        $filter = new \ReflectionMethod(Bootstrap::class, 'rulePacksToDisable');
        $filter->setAccessible(true);

        self::assertSame([], RulesState::new($filter->invoke(null, $config['disabledRules'] ?? null))->disabled());
    }

    /**
     * The tier gate, from this end: a TRUSTED checkout shipping `disabledRules`
     * contributes nothing, while the eligible sibling in the same file still
     * lands. `LayeredSettingsTest` pins the same property on the pure class; this
     * one pins it through the reader the launch actually calls, because the seed
     * consumes `readUserConfig()` and not `projectLayer()`, and the two could in
     * principle disagree.
     */
    public function testATrustedProjectCannotSeedTheRulesDisableList(): void
    {
        $this->trustTheProject();
        $this->writeProjectSettings(LayeredSettings::SHARED_PATH, [
            'disabledRules' => ['the-operators-own-pack'],
            'theme' => 'chosen-by-the-repo',
        ]);
        Bootstrap::useProjectRootForSettings($this->projectRoot);

        $config = Bootstrap::readUserConfig();

        self::assertArrayNotHasKey('disabledRules', $config);
        self::assertSame('chosen-by-the-repo', $config['theme'], 'per-key refusal, not a dropped file');
    }

    /**
     * THE SEED MUST NOT BE ABLE TO THROW. {@see \SugarCraft\Crush\Cli\Bootstrap::rulePacksToDisable()}
     * is the private half of `Bootstrap::chat()`'s rules seed, and this calls it
     * with every shape a JSON value can take, asserting the EXACT surviving list.
     * It has to be stricter than the sibling reader for `disabledSkills`, which
     * filters on `is_string` alone: {@see \SugarCraft\Crush\Context\RulesState::new()}
     * parses each entry through a check that REJECTS a blank or whitespace-only
     * string, so copying that filter verbatim would make `"disabledRules": [""]`
     * crash the launch instead of disabling nothing.
     *
     * The table is written as input => expected pairs rather than as one assert
     * per case so a missing row is visible as a missing row. What an
     * `is_string`-only filter would have produced is stated here rather than kept
     * as a third column, MEASURED over this exact table: three rows — the absent
     * key, the bare string and the int — are not arrays at all, so that mutant dies
     * on `array_filter()` before it can answer; every array-shaped row agrees with
     * the expected list except `the pathological blank list`, where it returns both
     * blanks and the second assertion in the loop throws out of
     * `RulesState::new()` instead of merely disagreeing. Two rows therefore price
     * that mutant, and neither of them prices it with a list comparison.
     */
    public function testTheRulesSeedKeepsOnlyUsablePackNamesFromEveryJunkShape(): void
    {
        $filter = new \ReflectionMethod(Bootstrap::class, 'rulePacksToDisable');
        $filter->setAccessible(true);

        /** @var array<string, array{0: mixed, 1: list<string>}> $cases */
        $cases = [
            'one real pack' => [['terse'], ['terse']],
            'key absent, read as null' => [null, []],
            'a bare string instead of a list' => ['terse', []],
            'an int instead of a list' => [7, []],
            'a nested object value' => [['type' => ['command' => 'x']], []],
            'the pathological blank list' => [['', '   '], []],
            'an int entry among strings' => [[0 => 5, 1 => 'terse'], ['terse']],
            'an array entry among strings' => [['ok', ['nested']], ['ok']],
            'a nested pack key with a slash' => [['style/terse'], ['style/terse']],
            'an empty list' => [[], []],
            'the same pack named twice' => [['terse', 'terse'], ['terse', 'terse']],
        ];

        foreach ($cases as $label => [$configured, $expected]) {
            $kept = $filter->invoke(null, $configured);

            self::assertSame($expected, $kept, $label);
            // The claim the whole filter exists for: what survives is exactly
            // what RulesState::new() accepts, so constructing it cannot throw.
            // `disable()` is idempotent, so the set is the unique list.
            self::assertSame(
                array_values(array_unique($expected)),
                RulesState::new($kept)->disabled(),
                $label,
            );
        }
    }

    /**
     * The other polarity of the same gate, at the seam that matters: the seed
     * `chat()` performs on the real value is `RulesState::new($filtered)`, so a
     * config naming `terse` must land a state that reports `terse` disabled, and
     * the pathological `[""]` must land an EMPTY one rather than an exception.
     * Written as the composition rather than as two halves because the defect this
     * prices is a filter that admits something `new()` rejects — either half alone
     * stays green for that.
     */
    public function testSeedingRulesStateWithTheFilteredConfigValueDisablesThePackAndNeverThrows(): void
    {
        $filter = new \ReflectionMethod(Bootstrap::class, 'rulePacksToDisable');
        $filter->setAccessible(true);

        $usable = $filter->invoke(null, ['terse']);
        self::assertSame(['terse'], RulesState::new($usable)->disabled());
        self::assertTrue(RulesState::new($usable)->isDisabled('terse'));

        $junkOnly = $filter->invoke(null, ['', '  ', 3, [['x']]]);
        self::assertSame([], $junkOnly);
        self::assertSame([], RulesState::new($junkOnly)->disabled());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function writeUserConfigFile(array $data): void
    {
        file_put_contents($this->configDir . '/config.json', (string) json_encode($data));
        // permissionConfig() refuses a policy file anyone else can write.
        chmod($this->configDir . '/config.json', 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettings(array $data): void
    {
        file_put_contents(
            $this->configDir . '/' . LayeredSettings::USER_FILE,
            (string) json_encode($data),
        );
    }

    /**
     * @param string $relative a path relative to the PROJECT ROOT — the shape
     *        {@see LayeredSettings::SHARED_PATH} is spelled in.
     * @param array<string, mixed> $data
     */
    private function writeProjectSettings(string $relative, array $data): void
    {
        file_put_contents($this->projectRoot . '/' . $relative, (string) json_encode($data));
    }

    /**
     * Opt the fixture project in, through the user's own config.
     *
     * `realpath()`, because {@see Bootstrap::trustedProjectRoots()} compares
     * canonical paths and `sys_get_temp_dir()` is a symlink on some hosts — a
     * raw string here would make every trusted case silently untrusted and the
     * suite would look like the gate was working.
     */
    private function trustTheProject(): void
    {
        $canonical = realpath($this->projectRoot);
        self::assertIsString($canonical);

        $this->writeUserConfigFile([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$canonical],
        ]);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
