<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Cli\PermissionConfigException;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The two launch flags `--model` and `--permission-mode` (P6.6), end to end:
 * parsed, registered, honoured, documented and completable.
 *
 * DOMAIN NOTE, because this is the trap the flags sit on: `--model` names a
 * MODEL — the same axis as `$SUGARCRUSH_MODEL` — and NOT a provider, even
 * though `Bootstrap::backendFor()`'s first parameter is a provider name and the
 * Ctrl+P palette entry that calls it is labelled "Switch model". Every
 * assertion below about `--model` is about the model axis; none of them claims
 * anything about provider selection.
 *
 * `$SUGARCRUSH_PERMISSION_MODE` and the whole backend-selection chain are
 * sandboxed for the same reason {@see BootstrapPermissionGateTest} sandboxes
 * them: an ambient export in the developer's shell would otherwise decide the
 * outcome these tests attribute to a flag.
 */
final class LaunchFlagsTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;
    private string|false $originalMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/launch_flags_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir . '/home';

        $this->originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->clearBackendSelectionEnv();

        Bootstrap::useModel(null);
        Bootstrap::usePermissionMode(null);
    }

    protected function tearDown(): void
    {
        // Both are process-wide statics, like Bootstrap::useConfigPath() — a
        // test that leaves one set decides the next test's launch.
        Bootstrap::useModel(null);
        Bootstrap::usePermissionMode(null);

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

        if ($this->originalMode !== false) {
            putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalMode);
        } else {
            putenv('SUGARCRUSH_PERMISSION_MODE');
        }

        $this->restoreBackendSelectionEnv();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    // Parsing
    // =========================================================================

    /**
     * Both spellings of both flags, and — the half that matters — neither is
     * recorded as an unknown flag. `bin/sugarcrush` turns a non-empty
     * `unknownFlags` into an exit-2 usage error, so a flag the parser branches
     * on but forgets to consume would refuse to start.
     *
     * @return array<string, array{list<string>, ?string, ?string}>
     */
    public static function flagSpellings(): array
    {
        return [
            'model spaced'      => [['sugarcrush', '--model', 'gpt-5'], 'gpt-5', null],
            'model attached'    => [['sugarcrush', '--model=gpt-5'], 'gpt-5', null],
            'mode spaced'       => [['sugarcrush', '--permission-mode', 'plan'], null, 'plan'],
            'mode attached'     => [['sugarcrush', '--permission-mode=plan'], null, 'plan'],
            'both together'     => [['sugarcrush', '--model=gpt-5', '--permission-mode=auto'], 'gpt-5', 'auto'],
            'value with a dash' => [['sugarcrush', '--model=-weird-name'], '-weird-name', null],
        ];
    }

    /**
     * @param list<string> $argv
     * @dataProvider flagSpellings
     */
    public function testBothSpellingsParseAndAreNotUnknownFlags(array $argv, ?string $model, ?string $mode): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame($model, $args->model);
        $this->assertSame($mode, $args->permissionMode);
        $this->assertSame([], $args->unknownFlags);
        $this->assertNull($args->usageError);
    }

    /**
     * A bare flag that swallows the NEXT OPTION is a typo, not a value. Left
     * lenient, `--permission-mode -p "..."` would drop the one-shot prompt and
     * silently start the TUI under the config's mode.
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function malformedFlagUses(): array
    {
        return [
            'model, list ended' => [['sugarcrush', '--model'], '--model expects a model name'],
            'model ate a flag'  => [['sugarcrush', '--model', '-p', 'hi'], '--model expects a model name'],
            'mode, list ended'  => [['sugarcrush', '--permission-mode'], '--permission-mode expects a mode'],
            'mode ate a flag'   => [['sugarcrush', '--permission-mode', '-p', 'hi'], '--permission-mode expects a mode'],

            // An EMPTY value is not a value. MEASURED against the real binary
            // before this was fixed: `--permission-mode= doctor` and
            // `--permission-mode "" doctor` BOTH exited 0 having applied no
            // mode at all, while `--config=` already exited 2 on the identical
            // shape. The flag claimed to follow `--config`'s precedent and
            // followed half of it.
            //
            // What the defect IS, stated precisely because the obvious reading
            // is wrong: the flag SILENTLY DID NOTHING. It is not a privilege
            // escalation — the mode it fell back to is
            // Bootstrap::DEFAULT_PERMISSION_MODE, which is deliberately
            // permissive and documented as a stopgap in three places. The harm
            // is an operator writing `--permission-mode="$MODE"` with `$MODE`
            // unset, believing a mode is in force, and being told nothing.
            //
            // Both spellings are pinned because they fail through DIFFERENT
            // branches: the attached form checks substr() === '', the spaced
            // form checks $next === '' after looksLikeFlag() declines it.
            // Killing one branch leaves the other alive.
            'model, empty attached' => [['sugarcrush', '--model='], '--model expects a model name, but the value is empty'],
            'model, empty spaced'   => [['sugarcrush', '--model', ''], '--model expects a model name, but the value is empty'],
            'mode, empty attached'  => [['sugarcrush', '--permission-mode='], '--permission-mode expects a mode, but the value is empty'],
            'mode, empty spaced'    => [['sugarcrush', '--permission-mode', ''], '--permission-mode expects a mode, but the value is empty'],
        ];
    }

    /**
     * @param list<string> $argv
     * @dataProvider malformedFlagUses
     */
    public function testAMissingValueIsAUsageError(array $argv, string $needle): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertNotNull($args->usageError);
        $this->assertStringContainsString($needle, $args->usageError);
        $this->assertNull($args->model);
        $this->assertNull($args->permissionMode);
    }

    /**
     * The empty token is CONSUMED, not re-read as a prompt.
     *
     * The spaced branch advances `$i += 2` past it deliberately. Left at
     * `++$i`, `sugarcrush --permission-mode "" ` would raise the usage error
     * AND hand `''` to the positional loop, where an empty prompt is a second,
     * different complaint — two errors from one typo, the first of which is
     * the only true one.
     */
    public function testAnEmptyValueIsConsumedRatherThanReReadAsAPrompt(): void
    {
        foreach ([
            ['sugarcrush', '--permission-mode', ''],
            ['sugarcrush', '--model', ''],
        ] as $argv) {
            $args = ArgvParser::parse($argv);

            $this->assertNull($args->prompt, implode(' ', $argv));
            $this->assertFalse($args->promptRequested, implode(' ', $argv));
            $this->assertSame([], $args->unknownFlags, implode(' ', $argv));
        }
    }

    /**
     * The flag is deliberately STRICTER on an empty value than the other two
     * sources, and the README now says so — this pins the asymmetry so the
     * claim cannot quietly stop being true.
     *
     * An empty `$SUGARCRUSH_PERMISSION_MODE` (and an empty `permissionMode`
     * config key) is read as ABSENT and the chain falls through to the next
     * source. An empty FLAG is refused by the parser instead. That is not an
     * inconsistency to be tidied away: an unset variable is a normal state of
     * an environment, whereas typing the flag is an explicit act, so the two
     * spellings of "empty" mean genuinely different things.
     *
     * DOMAIN: this asserts the ENV half only. The flag half cannot be asserted
     * here at all — `Bootstrap::usePermissionMode('')` coerces to null by
     * design, because after the parser fix no empty value can ever reach it
     * from the CLI. The flag's refusal is pinned in {@see malformedFlagUses()}
     * at the layer that actually performs it.
     */
    public function testAnEmptyEnvironmentVariableIsAbsenceWhileAnEmptyFlagIsAnError(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=');

        // Absence, so the chain continues to the documented default rather
        // than refusing the launch.
        $this->assertSame(
            PermissionMode::BypassPermissions,
            Bootstrap::permissionGate()->mode(),
            'an empty env var must read as absent, not as a bad value',
        );

        // The flag, at the layer that judges it, refuses instead.
        $args = ArgvParser::parse(['sugarcrush', '--permission-mode=']);
        $this->assertNotNull($args->usageError);
    }

    /**
     * The hint enumerates the modes, and it is DERIVED — a literal list would
     * be a set measured once and then free to disagree with the enum.
     */
    public function testThePermissionModeHintNamesEveryModeTheEnumHas(): void
    {
        $hint = ArgvParser::parse(['sugarcrush', '--permission-mode'])->usageHint;

        $this->assertNotNull($hint);
        foreach (PermissionMode::cases() as $mode) {
            $this->assertStringContainsString($mode->value, $hint, "hint omits {$mode->value}");
        }
    }

    // =========================================================================
    // --permission-mode reaches the gate, at the top of the precedence chain
    // =========================================================================

    public function testThePermissionModeFlagSelectsTheGateMode(): void
    {
        Bootstrap::usePermissionMode('plan');

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    /**
     * Highest precedence, and this is the assertion that makes the flag worth
     * having: an inherited `$SUGARCRUSH_PERMISSION_MODE` must not out-rank an
     * explicit flag on THIS launch.
     */
    public function testThePermissionModeFlagBeatsTheEnvironmentVariable(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=bypass-permissions');
        Bootstrap::usePermissionMode('plan');

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    public function testThePermissionModeFlagBeatsTheConfigFile(): void
    {
        mkdir($this->tempDir . '/home/.sugar-crush', 0700, true);
        file_put_contents(
            $this->tempDir . '/home/.sugar-crush/config.json',
            json_encode(['permissionMode' => 'bypass-permissions']),
        );

        // Without the flag the config decides — proving the fixture is live,
        // so the assertion after it is about the flag and not about an
        // unreadable file.
        $this->assertSame(PermissionMode::BypassPermissions, Bootstrap::permissionGate()->mode());

        Bootstrap::usePermissionMode('plan');
        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    /**
     * Absence must stay absence: registering null leaves the env/config/default
     * chain exactly as it was.
     */
    public function testAnAbsentFlagLeavesTheExistingChainAlone(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=plan');
        Bootstrap::usePermissionMode(null);

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    /**
     * Every mode the enum has is reachable through the flag — so the flag
     * cannot come to accept a subset of the vocabulary it documents.
     */
    public function testEveryPermissionModeIsReachableThroughTheFlag(): void
    {
        foreach (PermissionMode::cases() as $mode) {
            Bootstrap::usePermissionMode($mode->value);
            $this->assertSame($mode, Bootstrap::permissionGate()->mode(), $mode->value);
        }
    }

    /**
     * An unrecognised value refuses the launch through the SAME exception the
     * env var and the config key raise, naming the flag as the source — not a
     * silent fall back to the permissive default.
     */
    public function testAnInvalidModeThrowsNamingTheFlagAsTheSource(): void
    {
        Bootstrap::usePermissionMode('nonsense');

        try {
            Bootstrap::permissionGate();
            $this->fail('expected a PermissionConfigException');
        } catch (PermissionConfigException $e) {
            $this->assertStringContainsString('--permission-mode', $e->getMessage());
            $this->assertStringContainsString('nonsense', $e->getMessage());
            // The message enumerates the real vocabulary, same as the other
            // two sources do.
            foreach (PermissionMode::cases() as $mode) {
                $this->assertStringContainsString($mode->value, $e->getMessage());
            }
        }
    }

    // =========================================================================
    // --model reaches BOTH readers
    // =========================================================================

    /**
     * The status-bar caption and the backend that actually answers must name
     * the same model. They were two independent `getenv('SUGARCRUSH_MODEL')`
     * calls; if only one had learned about `--model`, the UI would have
     * reported a model that was not the one in use.
     */
    public function testTheModelFlagChangesTheReportedModel(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');

        Bootstrap::useModel('a-model-from-the-flag');
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('a-model-from-the-flag', $model);
    }

    public function testTheModelFlagBeatsTheEnvironmentVariable(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('SUGARCRUSH_MODEL=from-the-environment');

        Bootstrap::useModel('from-the-flag');
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('from-the-flag', $model);
    }

    /**
     * Absence stays absence here too: with no flag the env var still decides.
     */
    public function testWithoutTheFlagTheEnvironmentVariableStillDecides(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');
        putenv('SUGARCRUSH_MODEL=from-the-environment');

        Bootstrap::useModel(null);
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('from-the-environment', $model);
    }

    /**
     * The other reader, and the one that decides what actually answers:
     * `Bootstrap::backendFor()` builds the `EngineBackend` the run uses, and
     * its model is private, so this reads it by reflection rather than
     * inferring it from the caption. Asserting only through
     * {@see selectedProviderLabel()} would pin the LABEL and leave the backend
     * free to use a different model — the exact split-domain defect the shared
     * resolver exists to prevent.
     *
     * `sglang` because it is a provider `ProviderFactory` constructs with no
     * credentials; `openai`/`anthropic` throw for a missing apiKey and
     * `vertex` for a missing projectId, so none of them can be built here.
     */
    public function testTheModelFlagChangesTheModelTheBackendActuallyUses(): void
    {
        putenv('SUGARCRUSH_MODEL=from-the-environment');
        Bootstrap::useModel('from-the-flag');

        $backend = Bootstrap::backendFor('sglang');
        $model = (new \ReflectionProperty($backend, 'model'))->getValue($backend);

        $this->assertSame('from-the-flag', $model);
    }

    /**
     * And without the flag that same reader still honours the environment —
     * so the assertion above is about the flag winning, not about the resolver
     * having broken the variable.
     */
    public function testWithoutTheFlagTheBackendStillUsesTheEnvironmentModel(): void
    {
        putenv('SUGARCRUSH_MODEL=from-the-environment');
        Bootstrap::useModel(null);

        $backend = Bootstrap::backendFor('sglang');
        $model = (new \ReflectionProperty($backend, 'model'))->getValue($backend);

        $this->assertSame('from-the-environment', $model);
    }

    /**
     * `--model` is the MODEL axis only. Registering one must not change which
     * provider was selected — the trap this flag's name sets.
     */
    public function testTheModelFlagDoesNotChangeTheSelectedProvider(): void
    {
        putenv('SUGARCRUSH_PROVIDER=openai');
        Bootstrap::useModel('anthropic');

        $this->assertSame('openai', Bootstrap::selectedProviderName());
        $this->assertSame('openai', Bootstrap::selectedProviderLabel()[0]);
    }

    // =========================================================================
    // Documented and completable
    // =========================================================================

    /**
     * An undocumented flag is the same illusion as an unwired one. The generic
     * scrape in {@see HelpTest} proves every parser flag appears somewhere in
     * an option line; this pins that these two say what they DO, including the
     * provider-vs-model distinction the help text has to carry.
     */
    public function testHelpDocumentsBothFlagsAndTheModelDistinction(): void
    {
        $screen = Help::screen();

        $this->assertMatchesRegularExpression('/^ +--model(?:[ =,]|$)/m', $screen);
        $this->assertMatchesRegularExpression('/^ +--permission-mode(?:[ =,]|$)/m', $screen);
        $this->assertStringContainsString('not a provider', $screen);
        foreach (PermissionMode::cases() as $mode) {
            $this->assertStringContainsString($mode->value, $screen, "help omits {$mode->value}");
        }
    }

    /**
     * All three completion dialects offer both flags, and the mode list they
     * offer is the enum's rather than a copy of it.
     */
    public function testAllThreeCompletionsOfferBothFlagsAndEveryMode(): void
    {
        foreach (['bash', 'zsh', 'fish'] as $shell) {
            ob_start();
            Subcommands::dispatch(ArgvParser::parse(['sugarcrush', 'completion', $shell]));
            $script = (string) ob_get_clean();

            $needleModel = $shell === 'fish' ? '-l model' : '--model';
            $needleMode = $shell === 'fish' ? '-l permission-mode' : '--permission-mode';

            $this->assertStringContainsString($needleModel, $script, "{$shell} omits --model");
            $this->assertStringContainsString($needleMode, $script, "{$shell} omits --permission-mode");

            foreach (PermissionMode::cases() as $mode) {
                $this->assertStringContainsString(
                    $mode->value,
                    $script,
                    "{$shell} completion cannot complete the mode {$mode->value}",
                );
            }
        }
    }
}
