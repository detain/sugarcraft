<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\CacheReusingSummaryBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\PermissionConfigException;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;

/**
 * The launch-time resolvers: the two crush_code.md Phase 5 items 6 and 7 added
 * — {@see Bootstrap::maxCostUsd()} (the spend cap) and
 * {@see Bootstrap::summaryBackend()} (the tool-less backend `/compact` asks for
 * its exchange summaries) — plus {@see Bootstrap::keybindingsSetting()}, the
 * ISS-047 override map, which refuses the launch on the same machinery.
 *
 * HOME and the provider env vars are redirected/cleared for the whole class,
 * same convention as {@see BootstrapContextWindowTest}: both resolvers consult
 * `$CANDY_CODE_PROVIDER`, `$CANDY_CODE_BACKEND_CMD`,
 * `$CANDY_CODE_BACKEND_CMD_STREAM` and a provider persisted to
 * `~/.candy-code/config.json`, and a real one in the developer's own
 * environment would otherwise decide the answers. All FOUR tiers — the
 * `setUp()` list below was widened for the streaming variable in the same diff
 * that left this sentence naming only two of them.
 */
final class BootstrapSpendAndSummaryTest extends TestCase
{
    private string $tempDir;
    private string $home;
    private string $repo;
    private string $originalHome;
    private mixed $originalServerHome;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/bootstrap_spend_' . uniqid('', true);
        $this->home = $this->tempDir . '/home';
        $this->repo = $this->tempDir . '/repo';
        mkdir($this->home, 0700, true);
        mkdir($this->repo, 0755, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        foreach ([
            'CANDY_CODE_PROVIDER',
            'CANDY_CODE_BACKEND_CMD',
            'CANDY_CODE_BACKEND_CMD_STREAM',
            'CANDY_CODE_MODEL',
            'CANDY_CODE_TITLE_MODEL',
            'CANDY_CODE_SUMMARY_MODEL',
            'CANDY_CODE_MAX_COST',
        ] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }

        // Process-wide static like the launch flags in LaunchFlagsTest — this
        // file reads the whole flag/env/setting chain, so it owns clearing it.
        Bootstrap::useMaxCost(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useMaxCost(null);

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

        foreach ($this->originalEnv as $var => $value) {
            $value === false ? putenv($var) : putenv($var . '=' . $value);
        }

        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =====================================================================
    // maxCostUsd()
    // =====================================================================

    public function testNoVariableMeansNoCap(): void
    {
        $this->assertNull(Bootstrap::maxCostUsd());
    }

    public function testAPositiveNumberIsTheCap(): void
    {
        putenv('CANDY_CODE_MAX_COST=5');
        $this->assertSame(5.0, Bootstrap::maxCostUsd());

        putenv('CANDY_CODE_MAX_COST=2.50');
        $this->assertSame(2.5, Bootstrap::maxCostUsd());
    }

    /** Surrounding whitespace is a copy-paste artifact, not a refusal. */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        putenv('CANDY_CODE_MAX_COST=  7.25 ');
        $this->assertSame(7.25, Bootstrap::maxCostUsd());
    }

    /**
     * A leading `$` is accepted, because `/budget $5` accepts it and a user who
     * learned the one spelling should not be surprised by the other.
     */
    public function testALeadingDollarSignIsAccepted(): void
    {
        putenv('CANDY_CODE_MAX_COST=$5');
        $this->assertSame(5.0, Bootstrap::maxCostUsd());
    }

    /**
     * EMPTY is absence, and absence is not an error: `FOO=` is how a wrapper
     * script drops an inherited override, and every other variable this class
     * reads treats it that way.
     *
     * @dataProvider absentSpellings
     */
    public function testEmptyAndWhitespaceAreAbsenceAndMeanNoCap(string $raw): void
    {
        putenv('CANDY_CODE_MAX_COST=' . $raw);
        $this->assertNull(Bootstrap::maxCostUsd(), "'{$raw}' must read as absence");
    }

    /** @return iterable<string, array{string}> */
    public static function absentSpellings(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    /**
     * A PRESENT but unusable value STOPS THE LAUNCH, the same way an unrecognised
     * `$CANDY_CODE_PERMISSION_MODE` does and for the reason stated there: every
     * fallback in this chain ends somewhere more permissive, so silently
     * discarding a ceiling the user set on purpose is a fail-open.
     *
     * It used to read as unset "matching the refusal `/budget 0` gives", which
     * conflated two unalike things — `/budget 0` answers in the transcript, so the
     * user learns at once that no cap was set, whereas this is read once at launch
     * and said nothing at all. `CANDY_CODE_MAX_COST=5USD` bought an uncapped
     * session with no hint of it.
     *
     * @dataProvider unusableSpellings
     */
    public function testAPresentButUnusableValueStopsTheLaunch(string $raw): void
    {
        putenv('CANDY_CODE_MAX_COST=' . $raw);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/CANDY_CODE_MAX_COST/');
        Bootstrap::maxCostUsd();
    }

    /** @return iterable<string, array{string}> */
    public static function unusableSpellings(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.00'];
        yield 'negative' => ['-5'];
        yield 'prose' => ['five dollars'];
        yield 'a number with a unit suffix' => ['5.00USD'];
        // Numeric, casts to INF, and INF > 0.0 - so this one used to install a
        // cap that rendered as `$inf` and, every comparison against infinity
        // being false, enforced nothing.
        yield 'too large to represent' => ['1e309'];
    }

    // =====================================================================
    // --max-cost (ISS-006 follow): flag > env > setting, one judgement
    // =====================================================================

    /**
     * The flag alone is the cap — and it shares the variable's TEXT grammar
     * (leading `$`, surrounding whitespace) because both arrive as argv/env
     * strings through the same {@see Bootstrap::parseSpendCap()}.
     */
    public function testTheMaxCostFlagIsTheCap(): void
    {
        Bootstrap::useMaxCost(' $2.50 ');
        $this->assertSame(2.5, Bootstrap::maxCostUsd());
    }

    /**
     * The assertion that makes the flag worth having: an inherited
     * `$CANDY_CODE_MAX_COST` must not out-rank an explicit ceiling typed on
     * THIS launch — the same precedence argument the --permission-mode flag
     * above makes about its variable.
     */
    public function testTheMaxCostFlagBeatsTheEnvironmentVariable(): void
    {
        putenv('CANDY_CODE_MAX_COST=5');
        Bootstrap::useMaxCost('2.50');

        $this->assertSame(2.5, Bootstrap::maxCostUsd());
    }

    /**
     * And the persisted setting, from the HOME-sandboxed config the class
     * already reads. The no-flag assertion first proves the fixture is live,
     * so the flag assertion after it is about precedence and not about an
     * unreadable file.
     */
    public function testTheMaxCostFlagBeatsThePersistedSetting(): void
    {
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            json_encode(['maxCostUsd' => 9.0]),
        );

        $this->assertSame(9.0, Bootstrap::maxCostUsd());

        Bootstrap::useMaxCost('3');
        $this->assertSame(3.0, Bootstrap::maxCostUsd());
    }

    /**
     * Absence must stay absence: an unregistered flag leaves the env/config
     * chain exactly as it was, rather than clearing a cap that was set.
     */
    public function testAnAbsentMaxCostFlagLeavesTheExistingChainAlone(): void
    {
        putenv('CANDY_CODE_MAX_COST=5');
        Bootstrap::useMaxCost(null);

        $this->assertSame(5.0, Bootstrap::maxCostUsd());
    }

    /**
     * A present-but-unusable flag refuses the launch through the SAME
     * exception as the variable, and the message names `--max-cost` — "unset
     * the variable" would be wrong advice for a flag typed on this command
     * line, and the parser's shape check deliberately does not pre-judge the
     * money grammar (that judgement lives in exactly one place).
     */
    public function testAnUnusableMaxCostFlagStopsTheLaunchNamingTheFlag(): void
    {
        Bootstrap::useMaxCost('5USD');

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/--max-cost/');
        $this->expectExceptionMessageMatches('/5USD/');
        Bootstrap::maxCostUsd();
    }

    /**
     * The zero/negative/infinity refusals ride the shared judgement, so the
     * flag gets the variable's whole vocabulary test for free — one provider,
     * both spellings pinned where the semantics are declared identical.
     *
     * @dataProvider unusableSpellings
     */
    public function testAnUnusableMaxCostFlagValueStopsTheLaunch(string $raw): void
    {
        Bootstrap::useMaxCost($raw);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/--max-cost/');
        Bootstrap::maxCostUsd();
    }

    // =====================================================================
    // keybindingsSetting() (ISS-047)
    // =====================================================================

    /** Absence is the shipped bindings: an empty map, not a refusal. */
    public function testNoKeybindingsSettingMeansTheShippedBindings(): void
    {
        $this->assertSame([], Bootstrap::keybindingsSetting());
    }

    /** A valid map round-trips verbatim — the reader adds and drops nothing. */
    public function testAValidKeybindingsMapRoundTrips(): void
    {
        $this->writeKeybindings(['shell.palette' => 'Ctrl+B']);

        $this->assertSame(['shell.palette' => 'Ctrl+B'], Bootstrap::keybindingsSetting());
    }

    /**
     * An id nothing declares is a typo the user must hear about: launching
     * anyway would leave the binding on its shipped chord while the settings
     * file claimed otherwise.
     */
    public function testAnUnknownBindingIdStopsTheLaunch(): void
    {
        $this->writeKeybindings(['nope' => 'Ctrl+B']);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/nope.*is not a known binding/s');
        Bootstrap::keybindingsSetting();
    }

    /**
     * The chord grammar mirrors KeyBinding::ctrlRune(): the handlers claim and
     * dispatch bare single-character Ctrl chords and nothing wider, so a
     * `Cmd+K` or `Ctrl+AB` override could never be honoured — and neither
     * could overriding a row whose SHIPPED chord is not `Ctrl+<key>` (the
     * alias has no rune to land on).
     *
     * @param array<string,string> $map
     *
     * @dataProvider unusableChordSpellings
     */
    public function testAChordTheRoutingCannotHonourStopsTheLaunch(array $map): void
    {
        $this->writeKeybindings($map);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/Ctrl\+/');
        Bootstrap::keybindingsSetting();
    }

    /** @return list<array{array<string,string|string>}> */
    public static function unusableChordSpellings(): array
    {
        return [
            'wrong modifier' => [['shell.palette' => 'Cmd+K']],
            'missing modifier' => [['shell.palette' => 'K']],
            'two-character tail' => [['shell.palette' => 'Ctrl+KB']],
            'empty tail' => [['shell.palette' => 'Ctrl+']],
            'non-string chord' => [['shell.palette' => 7]],
            'a list is not a map' => [['shell.palette']],
            'target row ships Enter, not Ctrl+<key>' => [['chat.send' => 'Ctrl+B']],
        ];
    }

    /**
     * The settings family the launch reader trusts: the definition the /settings
     * view edits must be the Map/user-tier entry the reader validates against.
     */
    public function testTheSettingDefinitionIsWiredTheWayTheReaderAssumes(): void
    {
        $definition = SettingsSchema::byKey('keybindings');

        $this->assertNotNull($definition, 'the keybindings definition vanished from the schema');
        $this->assertSame(SettingType::Map, $definition->type);
        $this->assertSame([], $definition->default);
        $this->assertSame(ApplyMode::Restart, $definition->applyMode);
        $this->assertSame(UiEditability::Complex, $definition->ui);
        $this->assertTrue($definition->layered);
        $this->assertFalse($definition->projectSettable, 'a project must not be able to re-key the user\'s chords');

        // Save-side and launch-side agree: the schema route refuses the same
        // shapes Bootstrap::keybindingsSetting() stops the launch on, and
        // accepts what it accepts.
        $this->assertNull($definition->validate(null));
        $this->assertNull($definition->validate([]));
        $this->assertNull($definition->validate(['chat.palette' => 'Ctrl+B']));
        $this->assertNotNull($definition->validate(['nope' => 'Ctrl+B']));
        $this->assertNotNull($definition->validate(['shell.palette' => 'Cmd+K']));
        $this->assertNotNull($definition->validate(['a', 'b']), 'a list is not a map');
    }

    /**
     * The launch actually registers what the reader validated — pinned on the
     * source because driving chat() end-to-end needs a provider. If the line
     * is dropped, the setting becomes write-only and the drift is silent.
     */
    public function testTheChatLaunchRegistersTheOverrides(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Cli/Bootstrap.php');

        $this->assertSame(
            1,
            substr_count($source, 'KeyBindingRegistry::useOverrides(self::keybindingsSetting());'),
            'Bootstrap::chat() must register the validated keybindings overrides exactly once',
        );
    }

    /**
     * @param array<mixed> $map
     */
    private function writeKeybindings(array $map): void
    {
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode(['keybindings' => $map]),
        );
    }

    // =====================================================================
    // summaryBackend()
    // =====================================================================

    /**
     * No provider selected means no summarizer — the offline default. `/compact`
     * then uses the heuristic, which is not an error path.
     */
    public function testNoProviderMeansNoSummaryBackend(): void
    {
        $this->assertNull(Bootstrap::summaryBackend());
    }

    /** A shell-out backend has no provider to build one from either. */
    public function testAShellOutBackendGetsNoSummaryBackend(): void
    {
        putenv('CANDY_CODE_BACKEND_CMD=/bin/cat');
        $this->assertNull(Bootstrap::summaryBackend());
    }

    /**
     * With a provider selected there IS one, and it is an
     * {@see EngineBackend} — the class that carries `contextWindow()` and the
     * agentic loop. Crucially it is constructed with NO tools: nothing calls
     * `withTools()` on it, which is what makes a summarization one plain
     * completion rather than something that can run `Bash` mid-compaction.
     */
    public function testWithAProviderThereIsAToollessEngineBackend(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $backend = Bootstrap::summaryBackend();

            $this->assertInstanceOf(EngineBackend::class, $backend);
            $this->assertSame(
                [],
                $this->privateProperty($backend, 'tools'),
                'a summarization backend with tools attached could call one DURING a compaction, '
                . 'including one that raises a permission prompt',
            );
            $this->assertNull(
                $this->privateProperty($backend, 'skillRegistry'),
                'and no skill registry, so a compaction carries no skill bodies either',
            );
            $this->assertNull(
                $this->privateProperty($backend, 'instructionLoader'),
                'and no instruction loader, so no CLAUDE.md/AGENTS.md preamble rides along',
            );
            // "No hooks" was the one quarter of that four-part sentence that was
            // false: left at its default, EngineBackend::resolveHookManager()
            // calls registerBuiltIns() and the backend carries ProtectFilesHook,
            // ConfirmRemoveHook and AuditHook. All three are PreToolUse/PostToolUse
            // only, so with no tools attached none could fire and the safety
            // conclusion held anyway - but it held as a two-step argument resting
            // on a second fact, and it is asserted as a safety property at four
            // sites. One flag makes the sentence true on its own terms.
            $this->assertTrue(
                $this->privateProperty($backend, 'hooksDisabled'),
                'the "no tools, no hooks, no skill registry, no instruction loader" claim is asserted '
                . 'as a safety property at four sites; hooks must actually be off',
            );
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /**
     * And the titler's backend is built by the same builder, so it gets the same
     * four guarantees — this is the reason there is one builder and not two
     * near-copies.
     */
    public function testTheTitleBackendCarriesTheSameFourGuarantees(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $backend = Bootstrap::titleBackend();

            $this->assertInstanceOf(EngineBackend::class, $backend);
            $this->assertSame([], $this->privateProperty($backend, 'tools'));
            $this->assertTrue($this->privateProperty($backend, 'hooksDisabled'));
            $this->assertNull($this->privateProperty($backend, 'skillRegistry'));
            $this->assertNull($this->privateProperty($backend, 'instructionLoader'));
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /**
     * The model is `$CANDY_CODE_SUMMARY_MODEL` when set, and NOT
     * `$CANDY_CODE_TITLE_MODEL`: a session title is a handful of words and the
     * smallest model will do, while a compaction summary is what the model will
     * be shown of the earlier conversation from then on. Conflating the two
     * variables would silently pick the titling model for a permanent rewrite.
     */
    public function testTheSummaryModelIsItsOwnVariableAndNotTheTitleModel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');
        putenv('CANDY_CODE_TITLE_MODEL=tiny-titler');
        putenv('CANDY_CODE_SUMMARY_MODEL=big-summariser');

        try {
            $this->assertSame('big-summariser', $this->privateProperty(Bootstrap::summaryBackend(), 'model'));
            $this->assertSame(
                'tiny-titler',
                $this->privateProperty(Bootstrap::titleBackend(), 'model'),
                'and the title backend keeps its own, so the shared builder did not merge them',
            );
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /**
     * Unset, it falls back to the PROVIDER's default model rather than to the
     * title model — see the previous test for why that direction.
     */
    public function testUnsetItFallsBackToTheProvidersDefaultAndNotToTheTitleModel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');
        putenv('CANDY_CODE_TITLE_MODEL=tiny-titler');

        try {
            $summaryModel = $this->privateProperty(Bootstrap::summaryBackend(), 'model');

            $this->assertIsString($summaryModel);
            $this->assertNotSame('tiny-titler', $summaryModel);
            $this->assertNotSame('', $summaryModel);
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /** A `summaryModel` key in the persisted config is the middle tier. */
    public function testThePersistedSummaryModelKeyIsHonoured(): void
    {
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            json_encode(['provider' => 'openai', 'summaryModel' => 'persisted-summariser']),
        );
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $this->assertSame(
                'persisted-summariser',
                $this->privateProperty(Bootstrap::summaryBackend(), 'model'),
            );
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /** And the env var outranks it, the precedence every other variable here uses. */
    public function testTheEnvironmentVariableOutranksThePersistedKey(): void
    {
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            json_encode(['provider' => 'openai', 'summaryModel' => 'persisted-summariser']),
        );
        putenv('CANDY_CODE_SUMMARY_MODEL=env-summariser');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $this->assertSame(
                'env-summariser',
                $this->privateProperty(Bootstrap::summaryBackend(), 'model'),
            );
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    // =====================================================================
    // Reachability from the real launch chain
    // =====================================================================

    /**
     * Both resolvers have to be REACHED by `Bootstrap::chat()`, not merely to
     * exist. Every subsystem this audit found unwired had green unit tests while
     * being a guaranteed no-op in production, so the resolver's own tests above
     * are not the interesting half.
     */
    public function testAPlainLaunchCarriesTheCapOntoTheChat(): void
    {
        putenv('CANDY_CODE_MAX_COST=4.75');

        $this->assertSame(4.75, Bootstrap::chat($this->repo)->maxCostUsd());
    }

    /** And with no variable set, the launched Chat has no cap. */
    public function testAPlainLaunchWithNoVariableHasNoCap(): void
    {
        $this->assertNull(Bootstrap::chat($this->repo)->maxCostUsd());
    }

    /**
     * The offline launch gets no summarizer, so `/compact` there is the
     * synchronous heuristic it has always been. This is the DEFAULT run, so the
     * fallback is the common path rather than an edge case.
     */
    public function testAnOfflineLaunchHasNoSummaryBackendAndSoCompactsHeuristically(): void
    {
        $this->assertNull($this->privateProperty(Bootstrap::chat($this->repo), 'summaryBackend'));
    }

    /** With a provider selected, the launched Chat really does hold one. */
    public function testAProviderLaunchCarriesTheSummaryBackendOntoTheChat(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $summary = $this->privateProperty(Bootstrap::chat($this->repo), 'summaryBackend');

            // Roadmap 2.4-2: compaction summaries reuse the conversation's
            // cached prefix, everything else stays on the tool-less half.
            $this->assertInstanceOf(CacheReusingSummaryBackend::class, $summary);
            $this->assertInstanceOf(EngineBackend::class, $summary->toolless());
            $this->assertSame([], $this->privateProperty($summary->toolless(), 'tools'), 'and the plain half still has no tools attached');
            $this->assertInstanceOf(EngineBackend::class, $summary->engine());
            $this->assertNotSame([], $this->privateProperty($summary->engine(), 'tools'), 'the summary half advertises the conversation\'s tools');
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /**
     * Given the launch's engine, the summary backend sends summaries to THAT
     * engine and plain completions to a tool-less one on the same model — the
     * conversation's, unless the user named another.
     */
    public function testGivenTheMainEngineSummariesReuseItAndDefaultToItsModel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $main = Bootstrap::backendFor('openai')->withModel('conversation-model');
            $summary = Bootstrap::summaryBackend($main);

            $this->assertInstanceOf(CacheReusingSummaryBackend::class, $summary);
            $this->assertSame('conversation-model', $this->privateProperty($summary->toolless(), 'model'));
            $this->assertSame([], $this->privateProperty($summary->toolless(), 'tools'));
            $engine = $summary->engine();
            $this->assertInstanceOf(EngineBackend::class, $engine);
            $this->assertSame('conversation-model', $engine->model());
            $this->assertNull($engine->summaryModel(), 'no override: summaries go to the conversation\'s own model');
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /** The named model reaches both halves, and the main engine itself, at launch. */
    public function testANamedSummaryModelIsCarriedOntoTheEngineAtLaunch(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents($this->home . '/.candy-code/config.json', json_encode(['summaryModel' => 'persisted-summariser']));

        try {
            $this->assertSame('persisted-summariser', Bootstrap::summaryModel());
            $main = Bootstrap::backendFor('openai');
            $this->assertInstanceOf(EngineBackend::class, $main);
            $this->assertSame('persisted-summariser', $main->summaryModel(), 'the in-turn step summary reads it off the engine');

            $summary = Bootstrap::summaryBackend($main);
            $this->assertInstanceOf(CacheReusingSummaryBackend::class, $summary);
            $this->assertSame('persisted-summariser', $this->privateProperty($summary->toolless(), 'model'));

            putenv('CANDY_CODE_SUMMARY_MODEL=env-summariser');
            $this->assertSame('env-summariser', Bootstrap::summaryModel(), 'the variable outranks the key');
        } finally {
            putenv('OPENAI_API_KEY');
        }
    }

    /** Unset everywhere, the summary model is the conversation's own: null. */
    public function testNoNamedSummaryModelMeansTheConversationsOwn(): void
    {
        $this->assertNull(Bootstrap::summaryModel());
    }

    private function privateProperty(?object $object, string $name): mixed
    {
        $this->assertNotNull($object, "fixture: expected an object to read {$name} from");
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
