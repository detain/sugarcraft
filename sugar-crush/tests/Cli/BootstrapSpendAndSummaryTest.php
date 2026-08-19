<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\PermissionConfigException;

/**
 * The two launch-time resolvers crush_code.md Phase 5 items 6 and 7 added:
 * {@see Bootstrap::maxCostUsd()} (the spend cap) and
 * {@see Bootstrap::summaryBackend()} (the tool-less backend `/compact` asks for
 * its exchange summaries).
 *
 * HOME and the provider env vars are redirected/cleared for the whole class,
 * same convention as {@see BootstrapContextWindowTest}: both resolvers consult
 * `$SUGARCRUSH_PROVIDER`, `$SUGARCRUSH_BACKEND_CMD`,
 * `$SUGARCRUSH_BACKEND_CMD_STREAM` and a provider persisted to
 * `~/.sugar-crush/config.json`, and a real one in the developer's own
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
            'SUGARCRUSH_PROVIDER',
            'SUGARCRUSH_BACKEND_CMD',
            'SUGARCRUSH_BACKEND_CMD_STREAM',
            'SUGARCRUSH_MODEL',
            'SUGARCRUSH_TITLE_MODEL',
            'SUGARCRUSH_SUMMARY_MODEL',
            'SUGARCRUSH_MAX_COST',
        ] as $var) {
            $this->originalEnv[$var] = getenv($var);
            putenv($var);
        }
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
        putenv('SUGARCRUSH_MAX_COST=5');
        $this->assertSame(5.0, Bootstrap::maxCostUsd());

        putenv('SUGARCRUSH_MAX_COST=2.50');
        $this->assertSame(2.5, Bootstrap::maxCostUsd());
    }

    /** Surrounding whitespace is a copy-paste artifact, not a refusal. */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        putenv('SUGARCRUSH_MAX_COST=  7.25 ');
        $this->assertSame(7.25, Bootstrap::maxCostUsd());
    }

    /**
     * A leading `$` is accepted, because `/budget $5` accepts it and a user who
     * learned the one spelling should not be surprised by the other.
     */
    public function testALeadingDollarSignIsAccepted(): void
    {
        putenv('SUGARCRUSH_MAX_COST=$5');
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
        putenv('SUGARCRUSH_MAX_COST=' . $raw);
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
     * `$SUGARCRUSH_PERMISSION_MODE` does and for the reason stated there: every
     * fallback in this chain ends somewhere more permissive, so silently
     * discarding a ceiling the user set on purpose is a fail-open.
     *
     * It used to read as unset "matching the refusal `/budget 0` gives", which
     * conflated two unalike things — `/budget 0` answers in the transcript, so the
     * user learns at once that no cap was set, whereas this is read once at launch
     * and said nothing at all. `SUGARCRUSH_MAX_COST=5USD` bought an uncapped
     * session with no hint of it.
     *
     * @dataProvider unusableSpellings
     */
    public function testAPresentButUnusableValueStopsTheLaunch(string $raw): void
    {
        putenv('SUGARCRUSH_MAX_COST=' . $raw);

        $this->expectException(PermissionConfigException::class);
        $this->expectExceptionMessageMatches('/SUGARCRUSH_MAX_COST/');
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
        putenv('SUGARCRUSH_BACKEND_CMD=/bin/cat');
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
        putenv('SUGARCRUSH_PROVIDER=openai');
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
        putenv('SUGARCRUSH_PROVIDER=openai');
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
     * The model is `$SUGARCRUSH_SUMMARY_MODEL` when set, and NOT
     * `$SUGARCRUSH_TITLE_MODEL`: a session title is a handful of words and the
     * smallest model will do, while a compaction summary is what the model will
     * be shown of the earlier conversation from then on. Conflating the two
     * variables would silently pick the titling model for a permanent rewrite.
     */
    public function testTheSummaryModelIsItsOwnVariableAndNotTheTitleModel(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');
        putenv('SUGARCRUSH_TITLE_MODEL=tiny-titler');
        putenv('SUGARCRUSH_SUMMARY_MODEL=big-summariser');

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
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');
        putenv('SUGARCRUSH_TITLE_MODEL=tiny-titler');

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
        mkdir($this->home . '/.sugar-crush', 0700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/config.json',
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
        mkdir($this->home . '/.sugar-crush', 0700, true);
        file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            json_encode(['provider' => 'openai', 'summaryModel' => 'persisted-summariser']),
        );
        putenv('SUGARCRUSH_SUMMARY_MODEL=env-summariser');
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
        putenv('SUGARCRUSH_MAX_COST=4.75');

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
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('OPENAI_API_KEY=test-key-not-used');

        try {
            $summary = $this->privateProperty(Bootstrap::chat($this->repo), 'summaryBackend');

            $this->assertInstanceOf(EngineBackend::class, $summary);
            $this->assertSame([], $this->privateProperty($summary, 'tools'), 'and still with no tools attached');
        } finally {
            putenv('OPENAI_API_KEY');
        }
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
