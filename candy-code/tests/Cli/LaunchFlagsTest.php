<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Cli\PermissionConfigException;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;

/**
 * The launch flags `--model`, `--permission-mode` (P6.6) and `--max-cost`
 * (ISS-006), end to end: parsed, registered, honoured, documented and
 * completable.
 *
 * DOMAIN NOTE, because this is the trap the flags sit on: `--model` names a
 * MODEL — the same axis as `$CANDY_CODE_MODEL` — and NOT a provider, even
 * though `Bootstrap::backendFor()`'s first parameter is a provider name and the
 * Ctrl+P palette entry that calls it is labelled "Switch model". Every
 * assertion below about `--model` is about the model axis; none of them claims
 * anything about provider selection.
 *
 * `$CANDY_CODE_PERMISSION_MODE` and the whole backend-selection chain are
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

        $this->originalMode = getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('CANDY_CODE_PERMISSION_MODE');

        $this->clearBackendSelectionEnv();

        Bootstrap::useModel(null);
        Bootstrap::usePermissionMode(null);
        Bootstrap::useMaxCost(null);
        Bootstrap::useMaxTurns(null);
    }

    protected function tearDown(): void
    {
        // All three are process-wide statics, like Bootstrap::useConfigPath() — a
        // test that leaves one set decides the next test's launch.
        Bootstrap::useModel(null);
        Bootstrap::usePermissionMode(null);
        Bootstrap::useMaxCost(null);
        Bootstrap::useMaxTurns(null);

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
            putenv('CANDY_CODE_PERMISSION_MODE=' . $this->originalMode);
        } else {
            putenv('CANDY_CODE_PERMISSION_MODE');
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
     * recorded as an unknown flag. `bin/candy-code` turns a non-empty
     * `unknownFlags` into an exit-2 usage error, so a flag the parser branches
     * on but forgets to consume would refuse to start.
     *
     * @return array<string, array{list<string>, ?string, ?string}>
     */
    public static function flagSpellings(): array
    {
        return [
            'model spaced'      => [['candy-code', '--model', 'gpt-5'], 'gpt-5', null],
            'model attached'    => [['candy-code', '--model=gpt-5'], 'gpt-5', null],
            'mode spaced'       => [['candy-code', '--permission-mode', 'plan'], null, 'plan'],
            'mode attached'     => [['candy-code', '--permission-mode=plan'], null, 'plan'],
            'both together'     => [['candy-code', '--model=gpt-5', '--permission-mode=auto'], 'gpt-5', 'auto'],
            'value with a dash' => [['candy-code', '--model=-weird-name'], '-weird-name', null],
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
     * `--max-cost` carries its value RAW, like `--permission-mode` above —
     * `5USD` must arrive whole so Bootstrap::maxCostUsd() can refuse it
     * naming the flag, rather than the parser pre-judging money grammar in a
     * second place that could drift from the first.
     *
     * @return array<string, array{list<string>, ?string}>
     */
    public static function maxCostSpellings(): array
    {
        return [
            'cost spaced'   => [['candy-code', '--max-cost', '5'], '5'],
            'cost attached' => [['candy-code', '--max-cost=2.50'], '2.50'],
            'dollar spelled' => [['candy-code', '--max-cost=$2.50'], '$2.50'],
            'negative raw'  => [['candy-code', '--max-cost=-5'], '-5'],
            'prose raw'     => [['candy-code', '--max-cost', 'five dollars'], 'five dollars'],
        ];
    }

    /**
     * @param list<string> $argv
     * @dataProvider maxCostSpellings
     */
    public function testTheMaxCostFlagParsesRawAndIsNotAnUnknownFlag(array $argv, ?string $cost): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame($cost, $args->maxCost);
        $this->assertSame([], $args->unknownFlags);
        $this->assertNull($args->usageError);
    }

    /**
     * `--json-schema` carries its value RAW too (AUT-4), and RAWER than the
     * money/step ceilings: the parser touches no filesystem and runs no JSON
     * decoder, so an inline `{"...":...}` document and a path to one are the
     * same string here. Every shape judgement belongs to
     * {@see NonInteractive::decodeJsonSchema()} at the console boundary —
     * a parser that opened files would make `parse()` observable by cwd and
     * break the SUBCOMMANDS no-fs-in-parse law.
     *
     * @return array<string, array{list<string>, ?string}>
     */
    public static function jsonSchemaSpellings(): array
    {
        // C-AGT-7.b: every row gained its `-p`. These rows exist to pin the
        // RAW carry (spelling parsed verbatim, not an unknown flag); a
        // promptless `--json-schema` is no longer a clean parse — it is the
        // usage error the same step added, pinned in its own test below.
        return [
            'schema spaced'   => [['candy-code', '--json-schema', 'answer.json', '-p', 'x'], 'answer.json'],
            'schema attached' => [['candy-code', '--json-schema=answer.json', '-p', 'x'], 'answer.json'],
            'schema inline'   => [['candy-code', '--json-schema', '{"type":"object"}', '-p', 'x'], '{"type":"object"}'],
            'schema inline attached' => [['candy-code', '--json-schema={"type":"object"}', '-p', 'x'], '{"type":"object"}'],
        ];
    }

    /**
     * @param list<string> $argv
     * @dataProvider jsonSchemaSpellings
     */
    public function testTheJsonSchemaFlagParsesRawAndIsNotAnUnknownFlag(array $argv, ?string $schema): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame($schema, $args->jsonSchema);
        $this->assertSame([], $args->unknownFlags);
        $this->assertNull($args->usageError);
    }

    /**
     * C-AGT-7.b: a schema with no prompt to constrain is refused at the
     * parser, exit 2 — not carried to the TUI boundary and silently dropped
     * there. The flag's only production reader is the one-shot path
     * (`NonInteractive::run()` hands it to the engine), and bin reaches that
     * method only on a prompt launch; before this step,
     * `candy-code --json-schema answer.json` (a CI line that lost its `-p` to
     * a refactor) parsed, accepted, and ignored the schema in full silence.
     *
     * The refusal is about the PROMPT, so both prompt spellings clear it, and
     * the raw parse still stands underneath the guard.
     */
    public function testTheJsonSchemaFlagWithoutAPromptIsAUsageError(): void
    {
        foreach ([
            ['candy-code', '--json-schema', 'answer.json'],
            ['candy-code', '--json-schema={"type":"object"}'],
            ['candy-code', '--json-schema', 'answer.json', 'doctor'],
        ] as $argv) {
            $args = ArgvParser::parse($argv);

            $this->assertNotNull($args->usageError, implode(' ', $argv));
            $this->assertStringContainsString(
                '--json-schema constrains a one-shot answer',
                $args->usageError,
                implode(' ', $argv),
            );
            $this->assertNotNull($args->usageHint, implode(' ', $argv));
            $this->assertStringContainsString('-p', $args->usageHint, implode(' ', $argv));
            $this->assertStringContainsString(
                'the interactive TUI takes no schema',
                $args->usageHint,
                'the hint names the OTHER reading of the launch, which is the one that should end in dropping the flag',
            );
            $this->assertSame(
                in_array('--json-schema', $argv, true)
                    ? (string) $argv[array_search('--json-schema', $argv, true) + 1]
                    : (string) substr($argv[1], strlen('--json-schema=')),
                $args->jsonSchema,
                'the guard refuses the launch; it does not launder the value',
            );
        }
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function promptSpellingsThatHonourASchema(): array
    {
        return [
            'short prompt'     => [['candy-code', '--json-schema', 'answer.json', '-p', 'extract']],
            'long prompt'      => [['candy-code', '--json-schema=answer.json', '--prompt', 'extract']],
            'run operand'      => [['candy-code', 'run', 'extract', '--json-schema', 'answer.json']],
        ];
    }

    /**
     * The same flag, the same guard, on each launch form that HAS something to
     * constrain: none may refuse. `run` matters most — it reaches the one-shot
     * path through a different code route than `-p`, so a guard written only
     * against `-p` would refuse a working launch.
     *
     * @param list<string> $argv
     *
     * @dataProvider promptSpellingsThatHonourASchema
     */
    public function testASchemaBesideAnyPromptFormIsNotRefused(array $argv): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertTrue($args->promptRequested, implode(' ', $argv));
        $this->assertNull($args->usageError, implode(' ', $argv));
        $this->assertNotNull($args->jsonSchema, implode(' ', $argv));
    }

    /**
     * THE SIBLING FLAG KEEPS ITS ASYMMETRY — and that is a decision, not an
     * oversight. `--output-format` without a prompt is NOT inert: `serve`,
     * `self-update` and bin's own usage-failure path all read it on launches
     * that never take a prompt. Refusing those would break working commands to
     * police a flag doing real work, so this pins the absence of a guard as
     * deliberately as the test above pins its presence.
     *
     * @return array<string, array{list<string>}>
     */
    public static function promptlessLaunchesThatKeepOutputFormat(): array
    {
        return [
            'serve'             => [['candy-code', 'serve', '--output-format', 'json']],
            'self-update'       => [['candy-code', 'self-update', '--output-format', 'json']],
            'session list'      => [['candy-code', 'session', 'list', '--output-format', 'json']],
            'bare format only'  => [['candy-code', '--output-format', 'json']],
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @dataProvider promptlessLaunchesThatKeepOutputFormat
     */
    public function testOutputFormatIsNotRefusedForWantOfAPrompt(array $argv): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertFalse($args->promptRequested, implode(' ', $argv));
        $this->assertSame('json', $args->outputFormat, implode(' ', $argv));
        $this->assertNull($args->usageError, implode(' ', $argv));
    }

    /**
     * C-AGT-6.c: `--worktree` carries an OPTIONAL value in `--resume`'s
     * shape — a bare one is a legal launch (the tree gets an auto-chosen
     * name), only its ABSENCE is absence. What counts as a legal NAME is
     * judged by MainWorktree::enter(), the code that turns the string into a
     * directory and a ref; the parser just carries it raw.
     *
     * @return array<string, array{list<string>, bool, ?string}>
     */
    public static function worktreeSpellings(): array
    {
        return [
            'named spaced'          => [['candy-code', '--worktree', 'fix-bug'], true, 'fix-bug'],
            'named attached'        => [['candy-code', '--worktree=fix-bug'], true, 'fix-bug'],
            'named beside prompt'   => [['candy-code', '--worktree', 'fix-bug', '-p', 'x'], true, 'fix-bug'],
            'bare before option'    => [['candy-code', '--worktree', '-p', 'x'], true, null],
            'bare at end'           => [['candy-code', '--worktree'], true, null],
            'bare with empty arg'   => [['candy-code', '--worktree', '', '-p', 'x'], true, null],
            'attached empty is bare'=> [['candy-code', '--worktree='], true, null],
            'absent'                => [['candy-code', '-p', 'x'], false, null],
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @dataProvider worktreeSpellings
     */
    public function testTheWorktreeFlagParsesItsOptionalValueAndIsNotAnUnknownFlag(array $argv, bool $requested, ?string $name): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame($requested, $args->worktreeRequested, implode(' ', $argv));
        $this->assertSame($name, $args->worktreeName, implode(' ', $argv));
        $this->assertSame([], $args->unknownFlags, implode(' ', $argv));
        $this->assertNull($args->usageError, implode(' ', $argv));
    }

    /**
     * The flag eats its value, not the next option: `--worktree -p "x"` must
     * still be a one-shot run. A leading `-`-shaped token is never the name —
     * the same rule as `--root`, which refuses an option-eaten value outright
     * rather than this flag's softer "then it is bare" because --root has no
     * legal bare shape.
     */
    public function testTheWorktreeFlagLeavesAFollowingPromptFlagAlone(): void
    {
        $args = ArgvParser::parse(['candy-code', '--worktree', '-p', 'hello']);

        $this->assertTrue($args->worktreeRequested);
        $this->assertNull($args->worktreeName, 'a flag-shaped token is the NEXT option, not the worktree name');
        $this->assertTrue($args->promptRequested, 'the -p survives the optional-value probe');
        $this->assertSame('hello', $args->prompt);
    }

    /**
     * `--worktree` beside `serve` is refused at the parser (C-AGT-6.c): the
     * host decides a root per client session (that is what its own
     * --allow-dir-browse/--browse-root pair is FOR), so there is nothing for
     * a launch-level worktree to name — and a carried-then-ignored flag is
     * the lie the jsonSchema guard above was minted to forbid. Both orders
     * refuse: `--worktree x serve` through this usage rule, `serve
     * --worktree x` through serve's own flag table, which does not list it.
     */
    public function testTheWorktreeFlagBesideServeIsAUsageError(): void
    {
        $pre = ArgvParser::parse(['candy-code', '--worktree', 'fix-bug', 'serve']);
        $this->assertNotNull($pre->usageError);
        $this->assertStringContainsString('--worktree', (string) $pre->usageError, 'the refusal names the flag');
        $this->assertStringContainsString('serve', (string) $pre->usageError, 'and what it collides with');
        $this->assertNotNull($pre->usageHint);
        $this->assertStringContainsString('serve', (string) $pre->usageHint, 'the hint repeats the safe path');

        $attached = ArgvParser::parse(['candy-code', '--worktree=fix-bug', 'serve']);
        $this->assertNotNull($attached->usageError, 'the `=` spelling collides the same way');

        $post = ArgvParser::parse(['candy-code', 'serve', '--worktree', 'fix-bug']);
        $this->assertNotNull($post->usageError, 'the verb-first order refuses too — through serve\'s flag table');

        $this->assertNull(ArgvParser::parse(['candy-code', '--worktree', 'fix-bug', 'session', 'list'])->usageError, 'other verbs do not carry a per-client root law and stay clean');
    }

    /**
     * `--worktree` is a value flag: a line that splits argv must consume the
     * name before testing for a following prompt — bin's JSON-format
     * pre-scan and the parser now agree (same roster law as --resume).
     */
    public function testBinsPreScanCarriesWorktreeAmongTheValueFlags(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../../bin/candy-code');

        $this->assertMatchesRegularExpression(
            '/\$strictValueFlags = \[[^\]]*\'--worktree\'[^\]]*\]/',
            $bin,
            'the autoload-guard value-flag roster must list --worktree or the pre-scan reads its name as the next flag',
        );
    }

    /**
     * `--max-turns` carries its value RAW too (AUT-5): the parser judges only
     * the SHAPE failures that make "which value was asked for" unanswerable
     * (missing, flag-eaten, empty). Whether `five` or `0` or an
     * unrepresentable figure is a step ceiling is judged in ONE place —
     * Bootstrap::maxTurns() — which refuses naming the flag.
     *
     * @return array<string, array{list<string>, ?string}>
     */
    public static function maxTurnsSpellings(): array
    {
        return [
            'turns spaced'      => [['candy-code', '--max-turns', '8'], '8'],
            'turns attached'    => [['candy-code', '--max-turns=12'], '12'],
            'leading zero raw'  => [['candy-code', '--max-turns=007'], '007'],
            'negative raw'      => [['candy-code', '--max-turns=-5'], '-5'],
            'prose raw'         => [['candy-code', '--max-turns', 'five'], 'five'],
        ];
    }

    /**
     * @param list<string> $argv
     * @dataProvider maxTurnsSpellings
     */
    public function testTheMaxTurnsFlagParsesRawAndIsNotAnUnknownFlag(array $argv, ?string $turns): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame($turns, $args->maxTurns);
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
            'model, list ended' => [['candy-code', '--model'], '--model expects a model name'],
            'model ate a flag'  => [['candy-code', '--model', '-p', 'hi'], '--model expects a model name'],
            'mode, list ended'  => [['candy-code', '--permission-mode'], '--permission-mode expects a mode'],
            'mode ate a flag'   => [['candy-code', '--permission-mode', '-p', 'hi'], '--permission-mode expects a mode'],

            // An EMPTY value is not a value. MEASURED against the real binary
            // before this was fixed: `--permission-mode= doctor` and
            // `--permission-mode "" doctor` BOTH exited 0 having applied no
            // mode at all, while `--config=` already exited 2 on the identical
            // shape. The flag claimed to follow `--config`'s precedent and
            // followed half of it.
            //
            // What the defect IS, stated precisely because the obvious reading
            // is wrong: the flag SILENTLY DID NOTHING. It is not a privilege
            // escalation — the mode it fell back to is the path's documented
            // default (`default` in the TUI, the deliberately permissive
            // `bypass-permissions` for -p and the daemon; DEF-MODE). The harm
            // is an operator writing `--permission-mode="$MODE"` with `$MODE`
            // unset, believing a mode is in force, and being told nothing.
            //
            // Both spellings are pinned because they fail through DIFFERENT
            // branches: the attached form checks substr() === '', the spaced
            // form checks $next === '' after looksLikeFlag() declines it.
            // Killing one branch leaves the other alive.
            'model, empty attached' => [['candy-code', '--model='], '--model expects a model name, but the value is empty'],
            'model, empty spaced'   => [['candy-code', '--model', ''], '--model expects a model name, but the value is empty'],
            'mode, empty attached'  => [['candy-code', '--permission-mode='], '--permission-mode expects a mode, but the value is empty'],
            'mode, empty spaced'    => [['candy-code', '--permission-mode', ''], '--permission-mode expects a mode, but the value is empty'],

            // --max-cost joined the same strict family (ISS-006 follow): a
            // ceiling whose value went missing must refuse the launch, not
            // silently run uncapped — the fail-open this whole chain exists
            // to close.
            'cost, list ended'      => [['candy-code', '--max-cost'], '--max-cost expects a spend ceiling, but the argument list ended'],
            'cost ate a flag'       => [['candy-code', '--max-cost', '-p', 'hi'], '--max-cost expects a spend ceiling, but the next argument is the option -p'],
            'cost, empty attached'  => [['candy-code', '--max-cost='], '--max-cost expects a spend ceiling, but the value is empty'],
            'cost, empty spaced'    => [['candy-code', '--max-cost', ''], '--max-cost expects a spend ceiling, but the value is empty'],

            // --max-turns joins the strict family (AUT-5): a step ceiling
            // whose value went missing must refuse the launch, never silently
            // defer to the maxToolSteps setting the operator just overrode.
            'turns, list ended'      => [['candy-code', '--max-turns'], '--max-turns expects a step ceiling, but the argument list ended'],
            'turns ate a flag'       => [['candy-code', '--max-turns', '-p', 'hi'], '--max-turns expects a step ceiling, but the next argument is the option -p'],
            'turns, empty attached'  => [['candy-code', '--max-turns='], '--max-turns expects a step ceiling, but the value is empty'],
            'turns, empty spaced'    => [['candy-code', '--max-turns', ''], '--max-turns expects a step ceiling, but the value is empty'],

            // --json-schema joins the strict family (AUT-4): a schema whose
            // value went missing must refuse the launch, never silently run
            // UNCONSTRAINED — the whole capability-honesty posture starts at
            // the parser.
            'schema, list ended'      => [['candy-code', '--json-schema'], '--json-schema expects a schema, but the argument list ended'],
            'schema ate a flag'       => [['candy-code', '--json-schema', '-p', 'hi'], '--json-schema expects a schema, but the next argument is the option -p'],
            'schema, empty attached'  => [['candy-code', '--json-schema='], '--json-schema expects a schema, but the value is empty'],
            'schema, empty spaced'    => [['candy-code', '--json-schema', ''], '--json-schema expects a schema, but the value is empty'],
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
        $this->assertNull($args->maxCost);
        $this->assertNull($args->maxTurns);
        $this->assertNull($args->jsonSchema);
    }

    /**
     * The empty token is CONSUMED, not re-read as a prompt.
     *
     * The spaced branch advances `$i += 2` past it deliberately. Left at
     * `++$i`, `candy-code --permission-mode "" ` would raise the usage error
     * AND hand `''` to the positional loop, where an empty prompt is a second,
     * different complaint — two errors from one typo, the first of which is
     * the only true one.
     */
    public function testAnEmptyValueIsConsumedRatherThanReReadAsAPrompt(): void
    {
        foreach ([
            ['candy-code', '--permission-mode', ''],
            ['candy-code', '--model', ''],
            ['candy-code', '--max-cost', ''],
            ['candy-code', '--max-turns', ''],
            ['candy-code', '--json-schema', ''],
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
     * An empty `$CANDY_CODE_PERMISSION_MODE` (and an empty `permissionMode`
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
        putenv('CANDY_CODE_PERMISSION_MODE=');

        // Absence, so the chain continues to the documented default rather
        // than refusing the launch.
        $this->assertSame(
            PermissionMode::BypassPermissions,
            Bootstrap::permissionGate()->mode(),
            'an empty env var must read as absent, not as a bad value',
        );

        // The flag, at the layer that judges it, refuses instead.
        $args = ArgvParser::parse(['candy-code', '--permission-mode=']);
        $this->assertNotNull($args->usageError);
    }

    /**
     * The hint enumerates the modes, and it is DERIVED — a literal list would
     * be a set measured once and then free to disagree with the enum.
     */
    public function testThePermissionModeHintNamesEveryModeTheEnumHas(): void
    {
        $hint = ArgvParser::parse(['candy-code', '--permission-mode'])->usageHint;

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
     * having: an inherited `$CANDY_CODE_PERMISSION_MODE` must not out-rank an
     * explicit flag on THIS launch.
     */
    public function testThePermissionModeFlagBeatsTheEnvironmentVariable(): void
    {
        putenv('CANDY_CODE_PERMISSION_MODE=bypass-permissions');
        Bootstrap::usePermissionMode('plan');

        $this->assertSame(PermissionMode::Plan, Bootstrap::permissionGate()->mode());
    }

    public function testThePermissionModeFlagBeatsTheConfigFile(): void
    {
        mkdir($this->tempDir . '/home/.candy-code', 0700, true);
        file_put_contents(
            $this->tempDir . '/home/.candy-code/config.json',
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
        putenv('CANDY_CODE_PERMISSION_MODE=plan');
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
     * the same model. They were two independent `getenv('CANDY_CODE_MODEL')`
     * calls; if only one had learned about `--model`, the UI would have
     * reported a model that was not the one in use.
     */
    public function testTheModelFlagChangesTheReportedModel(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');

        Bootstrap::useModel('a-model-from-the-flag');
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('a-model-from-the-flag', $model);
    }

    public function testTheModelFlagBeatsTheEnvironmentVariable(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('CANDY_CODE_MODEL=from-the-environment');

        Bootstrap::useModel('from-the-flag');
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('from-the-flag', $model);
    }

    /**
     * Absence stays absence here too: with no flag the env var still decides.
     */
    public function testWithoutTheFlagTheEnvironmentVariableStillDecides(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
        putenv('CANDY_CODE_MODEL=from-the-environment');

        Bootstrap::useModel(null);
        [, $model] = Bootstrap::selectedProviderLabel();

        $this->assertSame('from-the-environment', $model);
    }

    /**
     * The other reader, and the one that decides what actually answers:
     * `Bootstrap::backendFor()` builds the `EngineBackend` the run uses, and
     * its model is private, so this reads it by reflection rather than
     * inferring it from the caption. Asserting only through
     * {@see \SugarCraft\Code\Cli\Bootstrap::selectedProviderLabel()} would pin the LABEL and leave the backend
     * free to use a different model — the exact split-domain defect the shared
     * resolver exists to prevent.
     *
     * `sglang` because it is a provider `ProviderFactory` constructs with no
     * credentials; `openai`/`anthropic` throw for a missing apiKey and
     * `vertex` for a missing projectId, so none of them can be built here.
     */
    public function testTheModelFlagChangesTheModelTheBackendActuallyUses(): void
    {
        putenv('CANDY_CODE_MODEL=from-the-environment');
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
        putenv('CANDY_CODE_MODEL=from-the-environment');
        Bootstrap::useModel(null);

        $backend = Bootstrap::backendFor('sglang');
        $model = (new \ReflectionProperty($backend, 'model'))->getValue($backend);

        $this->assertSame('from-the-environment', $model);
    }

    /**
     * The PROVIDER is built on the flag's model too, not only the engine
     * (integ-w8b follow-up to audit A26). Built from the bare default config,
     * a `sglang` provider believed it carried `SglangProvider::DEFAULT_MODEL`,
     * so it adopted whatever the server served and its family-mismatch notice
     * stayed silent for a `--model` of another family — the engine sent the
     * named id while the provider's parser, sampling and window followed the
     * default one.
     */
    public function testTheModelFlagReachesTheProviderSoAFamilyMismatchIsNotAdoptedAway(): void
    {
        Bootstrap::useModel('deepseek-ai/DeepSeek-V4.5-Flash');

        $backend = Bootstrap::backendFor('sglang');
        $provider = (new \ReflectionProperty($backend, 'provider'))->getValue($backend);

        $this->assertInstanceOf(\SugarCraft\Code\Providers\SglangProvider::class, $provider);
        $this->assertSame('deepseek-ai/DeepSeek-V4.5-Flash', (new \ReflectionProperty($provider, 'model'))->getValue($provider));
        // A provider built on the named id keeps it: it does not adopt the
        // served model, so warnAboutServerMismatch() judges the named family.
        $this->assertFalse((new \ReflectionMethod($provider, 'adoptsServedModel'))->invoke($provider));
    }

    /**
     * `--model` is the MODEL axis only. Registering one must not change which
     * provider was selected — the trap this flag's name sets.
     */
    public function testTheModelFlagDoesNotChangeTheSelectedProvider(): void
    {
        putenv('CANDY_CODE_PROVIDER=openai');
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
    public function testHelpDocumentsAllFlagsAndTheModelDistinction(): void
    {
        $screen = Help::screen();

        $this->assertMatchesRegularExpression('/^ +--model(?:[ =,]|$)/m', $screen);
        $this->assertMatchesRegularExpression('/^ +--permission-mode(?:[ =,]|$)/m', $screen);
        $this->assertMatchesRegularExpression('/^ +--max-cost(?:[ =,]|$)/m', $screen);
        $this->assertMatchesRegularExpression('/^ +--max-turns(?:[ =,]|$)/m', $screen);
        $this->assertMatchesRegularExpression('/^ +--json-schema(?:[ =,]|$)/m', $screen);
        $this->assertStringContainsString('Constrain the answer to a JSON Schema', $screen);
        $this->assertStringContainsString('it beats $CANDY_CODE_MAX_COST', $screen);
        $this->assertStringContainsString('it beats the maxToolSteps setting key', $screen);
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
            Subcommands::dispatch(ArgvParser::parse(['candy-code', 'completion', $shell]));
            $script = (string) ob_get_clean();

            $needleModel = $shell === 'fish' ? '-l model' : '--model';
            $needleMode = $shell === 'fish' ? '-l permission-mode' : '--permission-mode';
            $needleTurns = $shell === 'fish' ? '-l max-turns' : '--max-turns';
            $needleSchema = $shell === 'fish' ? '-l json-schema' : '--json-schema';

            $this->assertStringContainsString($needleModel, $script, "{$shell} omits --model");
            $this->assertStringContainsString($needleMode, $script, "{$shell} omits --permission-mode");
            $this->assertStringContainsString($needleTurns, $script, "{$shell} omits --max-turns");
            $this->assertStringContainsString($needleSchema, $script, "{$shell} omits --json-schema");

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
