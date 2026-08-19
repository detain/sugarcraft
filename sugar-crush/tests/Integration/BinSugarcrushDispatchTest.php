<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * crush_code.md Phase 0 item 3: every unrecognized or incomplete CLI flag
 * used to fall through `bin/sugarcrush`'s dispatch into `Program::run()`,
 * which attaches to the TTY, enters the alt-screen and blocks — so
 * `sugarcrush --version`, a bare `sugarcrush run`, a bare `sugarcrush -p` and
 * `sugarcrush -px "hello"` all hung and printed raw ANSI instead of failing
 * fast. Same bug class as the already-fixed "`--help` opens the TUI".
 *
 * The reason it shipped is that no test drove the real entry point at all
 * (see {@see BinSugarcrushWiringTest}'s docblock, which exercises Bootstrap
 * instead precisely because the bin script ends in a blocking
 * `Program::run()`). This class closes that gap in the only two ways that are
 * safe to do so:
 *
 *  - Argv vectors that MUST terminate before `Program`/the backend are run
 *    against the real binary in a child process ({@see self::runBin()}), with
 *    stdin on /dev/null, a hard deadline, SIGKILL and a detached watchdog. A
 *    regression that reopens the TUI fails loudly instead of hanging the
 *    suite.
 *  - Argv vectors whose correct outcome is "boot the TUI" or "call the
 *    backend" are asserted at the parse/dispatch layer instead — exec'ing
 *    those would be exactly the hang (or the live network call) this file
 *    exists to prevent.
 */
final class BinSugarcrushDispatchTest extends TestCase
{
    use HomeSandboxTrait;

    /** Usage error, per crush_code.md; distinct from NonInteractive's 1 = ran and failed. */
    private const EXIT_USAGE = 2;

    /**
     * A temp HOME for the env-controlled cases below. The real
     * ~/.sugar-crush/config.json routinely carries a persisted `provider` from
     * a previous Ctrl+P "Switch model", which
     * {@see \SugarCraft\Crush\Cli\Bootstrap::selectedProviderName()} honours —
     * so a "nothing configured" vector run against the developer's own HOME
     * would quietly be testing their provider, and could reach a live request.
     */
    private string $tempHome = '';

    /** Every case exec'd here provably terminates immediately; this is the "it regressed" tripwire, not a normal wait. */
    private const TIMEOUT_SECONDS = 20;

    /**
     * How long the detached watchdog waits after the in-process SIGKILL
     * before firing its own — i.e. the grace given to `proc_terminate()` +
     * the `proc_close()` wait() to reap a child that ignored SIGKILL.
     */
    private const WATCHDOG_GRACE_SECONDS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // Every case here exec's a child with minimalEnv()'s throwaway HOME,
        // but the in-process side of the class had no sandbox at all -- so any
        // assertion added here that touches Bootstrap would read the
        // developer's ~/.claude/skills. Sandbox both spellings up front rather
        // than waiting for that to be someone's flaky test (see
        // Tests\Support\HomeSandboxTrait).
        $this->useHomeSandbox(\sys_get_temp_dir() . '/bin_dispatch_inproc_home_' . \uniqid('', true));
    }

    protected function tearDown(): void
    {
        $sandbox = \getenv('HOME');
        $this->restoreHomeSandbox();
        if (\is_string($sandbox) && \str_contains($sandbox, '/bin_dispatch_inproc_home_')) {
            @\rmdir($sandbox);
        }

        if ($this->tempHome !== '' && \is_dir($this->tempHome)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempHome, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
            }
            @\rmdir($this->tempHome);
        }
        $this->tempHome = '';

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function unknownFlagInvocations(): array
    {
        return [
            // `--version` used to live here: crush_code.md Phase 0 item 3 made
            // it fail cleanly as an unknown flag, and Phase 4 item 3 made it a
            // real flag. A near-miss typo of it stands in, so the guard that
            // used to catch `--version` itself is still covered.
            'near-miss typo'       => [['--verzion']],
            'unrecognized long'    => [['--bogus-flag']],
            'unrecognized short'   => [['-z']],
            // -px is a single unknown token, not "-p" + "x" — the parser does
            // no short-flag clustering, so "hello" is left as a positional
            // that is not path-shaped and is discarded. Before the fix this
            // combination parsed to an empty ParsedArgs and opened the TUI.
            '-px with a value'     => [['-px', 'hello']],
            'unknown before -p'    => [['--bogus', '-p', 'hello']],
            // `--` protects what follows it, never what precedes it, so this
            // must still be a usage error rather than booting the TUI at
            // /tmp.
            'unknown before --'    => [['--bogus', '--', '/tmp']],
        ];
    }

    /**
     * @param list<string> $args
     *
     * @dataProvider unknownFlagInvocations
     */
    public function testUnrecognizedFlagsExitTwoWithoutOpeningTheTui(array $args): void
    {
        $result = $this->runBin($args);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('unrecognized option', $result['stderr']);
        $this->assertStringContainsString($args[0], $result['stderr'], 'the offending flag must be named');
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'a usage error must never emit terminal escapes');
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function promptlessOneShotInvocations(): array
    {
        return [
            'bare run'      => [['run']],
            'bare -p'       => [['-p']],
            'bare --prompt' => [['--prompt']],
            'empty --prompt=' => [['--prompt=']],
        ];
    }

    /**
     * A one-shot invocation with no prompt VALUE must reach
     * NonInteractive::run()'s "no prompt given" error, not the TUI. The
     * message is deliberately not duplicated in the binary, so asserting its
     * text here also pins that single ownership.
     *
     * Exit 2, not the 1 this originally pinned: nothing is attempted on that
     * branch, which is the same thing `--bogus` and a bad `--root` mean, and
     * a CI gate that retries on 1 would have retried a malformed invocation
     * forever. See NonInteractive::EXIT_CONFIG.
     *
     * @param list<string> $args
     *
     * @dataProvider promptlessOneShotInvocations
     */
    public function testPromptlessOneShotInvocationsReachTheNoPromptError(array $args): void
    {
        $result = $this->runBin($args);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('no prompt given', $result['stderr']);
        $this->assertStringNotContainsString("\x1b", $result['stdout']);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function runSubcommandBehindAFlagInvocations(): array
    {
        return [
            '--output-format json run' => [['--output-format', 'json', 'run']],
            '--output-format=json run' => [['--output-format=json', 'run']],
            '--root . run'             => [['--root', '.', 'run']],
        ];
    }

    /**
     * The `run` subcommand behind a flag: `ArgvParser` only recognised it at
     * $argv[1], so ANY preceding flag discarded it, promptRequested stayed
     * false, and the binary fell past all three dispatch guards into
     * `Program::run()` — a three-minute hang on a probe, not a fast failure.
     *
     * Every vector here is promptless on purpose: it proves the subcommand is
     * recognised (it reaches the one-shot path's own "no prompt given" error
     * at exit 2) without supplying a prompt that would call a backend. A
     * regression re-opens the TUI and this fails on the deadline instead of
     * hanging the suite.
     *
     * @param list<string> $args
     *
     * @dataProvider runSubcommandBehindAFlagInvocations
     */
    public function testRunSubcommandIsDispatchedEvenWhenAFlagPrecedesIt(array $args): void
    {
        $result = $this->runBin($args);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('no prompt given', $result['stderr']);
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'the TUI was entered');
    }

    /**
     * `--` still turns `run` back into a plain operand, which means the binary
     * legitimately boots the TUI — so this one is asserted at the parse layer,
     * exec'ing it being exactly the hang the rest of this file guards against.
     */
    public function testRunAfterTheSeparatorIsStillAnOperandAndNotDispatched(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--output-format=json', '--', 'run']);

        $this->assertFalse($args->promptRequested);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->help);
    }

    // -------------------------------------------------------------------------
    // The --output-format json contract, on the exits that happen BEFORE
    // NonInteractive is reached. Both used to exit 2 with a completely empty
    // stdout, so `| jq` died on an unexpected EOF on two of the three exit-2
    // causes the README promises a document for.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function binLevelUsageErrorsUnderJson(): array
    {
        return [
            'unrecognized flag' => [['--bogus', '--output-format', 'json'], 'unrecognized option'],
            'bad --root'        => [['--root', '/no/such/dir', '--output-format', 'json'], 'no such directory'],
            'no prompt given'   => [['--output-format', 'json', 'run'], 'no prompt given'],
        ];
    }

    /**
     * @param list<string> $args
     *
     * @dataProvider binLevelUsageErrorsUnderJson
     */
    public function testEveryDocumentedExitTwoCauseStillPutsOneJsonObjectOnStdout(array $args, string $expectedFragment): void
    {
        $result = $this->runBin($args);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertNotSame('', \trim($result['stdout']), 'a | jq consumer got an empty pipe');

        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertNull($decoded['result']);
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString($expectedFragment, $decoded['error']['message']);
    }

    /**
     * The same two invocations without `--output-format json` must keep stdout
     * empty — the document is opt-in, and a `sugarcrush --bogus > out.txt`
     * caller must not suddenly find JSON in the file.
     */
    public function testBinLevelUsageErrorsStayStdoutSilentInTextMode(): void
    {
        foreach ([['--bogus'], ['--root', '/no/such/dir']] as $args) {
            $result = $this->runBin($args);

            $this->assertSame(self::EXIT_USAGE, $result['status']);
            $this->assertSame('', $result['stdout'], \implode(' ', $args) . ' wrote to stdout');
        }
    }

    /**
     * Positive control: --help still short-circuits ahead of the new
     * unknown-flag guard, so the guard cannot have swallowed it.
     */
    public function testHelpStillPrintsUsageAndExitsZero(): void
    {
        $result = $this->runBin(['--help']);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('Usage:', $result['stdout']);
    }

    // -------------------------------------------------------------------------
    // crush_code.md Phase 4 item 3: --version dispatches like --help.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function versionInvocations(): array
    {
        return [
            'long'  => [['--version']],
            'short' => [['-v']],
        ];
    }

    /**
     * Safe to exec, and the only way to prove the thing that actually matters:
     * `--version` has to answer on a machine with no provider and no TTY, and
     * must never reach `Program::run()`. A regression that drops the dispatch
     * branch reopens the TUI, so this fails on the deadline rather than
     * hanging the suite.
     *
     * The env is passed explicitly (empty overrides -> minimal environment) so
     * a runner's real `SUGARCRUSH_PROVIDER` cannot turn this into a run that
     * constructs a backend before printing.
     *
     * @param list<string> $args
     *
     * @dataProvider versionInvocations
     */
    public function testVersionPrintsAndExitsZeroWithoutOpeningTheTui(array $args): void
    {
        $result = $this->runBin($args, []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringStartsWith('sugarcrush ', $result['stdout']);
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'the TUI was entered');
        $this->assertStringNotContainsString('unrecognized option', $result['stderr']);
    }

    /**
     * The version reported by the binary is the one {@see Help::versionString()}
     * resolves — i.e. the binary is not hardcoding a second copy of it.
     */
    public function testVersionMatchesTheResolvedPackageVersion(): void
    {
        $result = $this->runBin(['--version'], []);

        $this->assertSame('sugarcrush ' . Help::versionString() . "\n", $result['stdout']);
    }

    // -------------------------------------------------------------------------
    // Follow-up #48: a prompt option handed a flag is a usage error.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function flagShapedPromptInvocations(): array
    {
        return [
            '-p'       => [['-p', '--verbose'], '-p'],
            '--prompt' => [['--prompt', '--verbose'], '--prompt'],
            'run'      => [['run', '--verbose'], 'run'],
        ];
    }

    /**
     * These used to run a real one-shot turn whose prompt text was the literal
     * string "--verbose" — a live backend call, billed, answering a question
     * nobody asked. Exit 2 (nothing attempted, a retry cannot help), and the
     * message names the option rather than only the token.
     *
     * @param list<string> $args
     *
     * @dataProvider flagShapedPromptInvocations
     */
    public function testAFlagShapedPromptValueIsAUsageErrorAndNeverReachesABackend(array $args, string $option): void
    {
        $result = $this->runBin($args, []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString($option . ' expects a prompt', $result['stderr']);
        $this->assertStringContainsString('--verbose', $result['stderr']);
        $this->assertStringContainsString('--prompt=', $result['stderr'], 'the escape hatch must be named');
        $this->assertSame('', $result['stdout'], 'nothing was attempted, so nothing may look like an answer');
    }

    /**
     * The sharper of the two messages wins: the unconsumed flag also lands in
     * $unknownFlags, and "unrecognized option: --verbose" blames the symptom
     * rather than naming the option that was misused.
     */
    public function testTheFlagShapedPromptErrorOutranksTheUnknownFlagError(): void
    {
        $result = $this->runBin(['-p', '--verbose'], []);

        $this->assertStringNotContainsString('unrecognized option', $result['stderr']);
    }

    /**
     * Same guarantee the other bin-level usage errors carry: a
     * `--output-format json` caller reads stdout and nothing else, so this
     * must arrive there as one document rather than as an empty pipe.
     */
    public function testTheFlagShapedPromptErrorStillEmitsAJsonDocument(): void
    {
        $result = $this->runBin(['--output-format', 'json', '-p', '--verbose'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);

        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertNull($decoded['result']);
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString('expects a prompt', $decoded['error']['message']);
    }

    /**
     * The escape hatch has to keep working end to end, not just at the parse
     * layer — but exec'ing it would call a backend, so it is asserted where
     * the other backend-bound vectors are.
     */
    public function testTheEqualsFormStillCarriesAPromptBeginningWithADash(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--prompt=--verbose']);

        $this->assertNull($args->usageError);
        $this->assertSame('--verbose', $args->prompt);
        $this->assertTrue($args->promptRequested);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function validOneShotInvocations(): array
    {
        return [
            '-p with value'         => [['sugarcrush', '-p', 'hello']],
            '--prompt with value'   => [['sugarcrush', '--prompt', 'hello']],
            '--prompt= with value'  => [['sugarcrush', '--prompt=hello']],
            'run with value'        => [['sugarcrush', 'run', 'hello']],
        ];
    }

    /**
     * Positive control, asserted at the parse layer: a VALID one-shot
     * invocation still carries no unknown flags and still routes to
     * NonInteractive with its prompt intact. Not exec'd — a real
     * `-p "hello"` run would call a live provider.
     *
     * @param list<string> $argv
     *
     * @dataProvider validOneShotInvocations
     */
    public function testValidOneShotInvocationsAreUnaffected(array $argv): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->help);
        $this->assertTrue($args->promptRequested);
        $this->assertSame('hello', $args->prompt);
    }

    /**
     * Positive control, asserted at the parse layer: a bare `sugarcrush` must
     * still fall past all three of the binary's guards (help / unknown flags
     * / one-shot) and open the TUI. Exec'ing this one IS the hang the rest of
     * the file guards against, so the three guard conditions are asserted
     * directly instead.
     */
    public function testBareInvocationStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
    }

    /**
     * Same for `sugarcrush /some/path` — a root-path positional is not a flag
     * and must not be mistaken for one.
     */
    public function testBareRootPathInvocationStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '/tmp/some/repo']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
        $this->assertSame('/tmp/some/repo', $args->root);
    }

    /**
     * `sugarcrush -- /tmp/some/repo` is the one shape that used to DO
     * something — set root and open the TUI — and briefly started exiting 2,
     * because `--` had no handling and landed in the unknown-flag recorder.
     * It has to fall through all three of the binary's guards again. Exec'ing
     * it is the hang this file exists to prevent, so the guard conditions are
     * asserted directly, as with the other TUI-bound controls above.
     */
    public function testEndOfOptionsSeparatorStillFallsThroughToTheTui(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--', '/tmp/some/repo']);

        $this->assertFalse($args->help);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
        $this->assertSame('/tmp/some/repo', $args->root);
    }

    // -------------------------------------------------------------------------
    // crush_code.md Phase 0 item 10: a one-shot run never answers from the
    // offline echo provider on behalf of a provider that was asked for.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableProviderInvocations(): array
    {
        return [
            // The exact reproduction in crush_code.md §5: this used to print a
            // warning to stderr and a canned Echo sentence to stdout at exit 0.
            'known type, missing credential' => ['openai', "requires 'apiKey'"],
            'unknown provider name'          => ['definitely-not-a-real-provider', 'Unknown provider type'],
        ];
    }

    /**
     * Safe to exec: every provider in this codebase is constructed without any
     * I/O, so `SUGARCRUSH_PROVIDER=openai` with no key fails inside
     * `ProviderFactory` — before `Backend::complete()`, before any socket, and
     * long before `Program::run()`. The env is passed EXPLICITLY rather than
     * inherited precisely so this stays true on a machine that exports a real
     * `OPENAI_API_KEY`.
     *
     * @dataProvider unusableProviderInvocations
     */
    public function testExplicitlySelectedUnusableProviderExitsTwoWithNothingOnStdout(string $provider, string $expectedDetail): void
    {
        $result = $this->runBin(['-p', 'review this diff'], ['SUGARCRUSH_PROVIDER' => $provider]);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString($provider, $result['stderr'], 'the offending provider must be named');
        $this->assertStringContainsString($expectedDetail, $result['stderr']);
        $this->assertSame('', $result['stdout'], 'a caller must never get a canned reply it could mistake for an answer');
    }

    /**
     * A `--output-format json` caller reads stdout and nothing else, so a
     * failure has to arrive there as a well-formed document rather than as an
     * empty pipe plus an exit code.
     */
    public function testUnusableProviderStillProducesAParseableJsonDocumentOnStdout(): void
    {
        $result = $this->runBin(
            ['-p', 'hi', '--output-format', 'json'],
            ['SUGARCRUSH_PROVIDER' => 'openai'],
        );

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);

        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . $result['stdout']);
        $this->assertNull($decoded['result']);
        $this->assertSame('provider_configuration', $decoded['error']['type']);
        $this->assertSame('openai', $decoded['error']['provider']);
    }

    /**
     * The remediation line has to name the SOURCE the selection came from, and
     * a subprocess is the only place stderr can actually be read: with the
     * name coming from a persisted Ctrl+P "Switch model" choice and no
     * variable set anywhere, "unset SUGARCRUSH_PROVIDER" sends the operator
     * hunting for something that was never set. The config file is named
     * instead, because that is the file they have to edit.
     */
    public function testThePersistedSelectionHintNamesTheConfigFileNotTheEnvironmentVariable(): void
    {
        $this->persistProviderChoice('definitely-not-a-real-provider');

        $result = $this->runBin(['-p', 'hi'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringNotContainsString(
            'unset SUGARCRUSH_PROVIDER',
            $result['stderr'],
            'no such variable was set on this run',
        );
        $this->assertStringContainsString($this->tempHome . '/.sugar-crush/config.json', $result['stderr']);
        $this->assertStringContainsString('Switch model', $result['stderr'], 'the operator has to know which choice to undo');
    }

    /**
     * The other branch, same configuration file present: when
     * `$SUGARCRUSH_PROVIDER` IS what selected the provider, it outranks the
     * persisted entry, so "unset SUGARCRUSH_PROVIDER" is the correct advice
     * and the file must not be named.
     */
    public function testTheEnvironmentSelectionHintNamesTheVariableEvenWhenAChoiceIsAlsoPersisted(): void
    {
        $this->persistProviderChoice('dev-sglang');

        $result = $this->runBin(['-p', 'hi'], ['SUGARCRUSH_PROVIDER' => 'definitely-not-a-real-provider']);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('unset SUGARCRUSH_PROVIDER', $result['stderr']);
        $this->assertStringNotContainsString('/.sugar-crush/config.json', $result['stderr']);
    }

    // -------------------------------------------------------------------------
    // An unusable permission policy: the USER-VISIBLE half of the fail-open
    // fix. `bin/sugarcrush`'s try/catch, `NonInteractive::run()`'s rethrow and
    // `Bootstrap::backend()`'s two rethrows had zero tests between them, so
    // nothing anywhere checked that a stray comma in the config produces an
    // exit code rather than a PHP fatal painted over the alt-screen.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusablePermissionPolicies(): array
    {
        return [
            'trailing comma' => ['{"permissionMode":"plan",}', 'not usable JSON'],
            'top-level list' => ['[{"permissionMode":"plan"}]', 'not usable JSON'],
            'mode that names nothing' => ['{"permissionMode":"paln"}', 'permissionMode'],
        ];
    }

    /**
     * The one-shot dispatch path. `-p` never reaches `Program::run()`, so this
     * exercises `NonInteractive::run()`'s rethrow and the bin's catch.
     *
     * @dataProvider unusablePermissionPolicies
     */
    public function testAnUnusablePermissionPolicyFailsTheOneShotRunAtExitTwo(string $config, string $fragment): void
    {
        $this->writeRawUserConfig($config);

        $result = $this->runBin(['-p', 'hello there'], ['SUGARCRUSH_PROVIDER' => 'custom', 'CUSTOM_API_KEY' => 'k']);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertSame('', $result['stdout'], 'a failed launch must not answer on stdout');
        $this->assertStringContainsString($fragment, $result['stderr']);
        $this->assertStringNotContainsString("\033", $result['stderr'], 'ANSI leaked out of a run that never took the screen');
        $this->assertStringNotContainsString("\033", $result['stdout']);
    }

    /**
     * ...and under `--output-format json`, the same failure still puts exactly
     * one document on stdout — the contract the README makes for every exit-2
     * cause, which this one was missing from entirely.
     *
     * @dataProvider unusablePermissionPolicies
     */
    public function testAnUnusablePermissionPolicyStillEmitsOneJsonDocument(string $config, string $fragment): void
    {
        $this->writeRawUserConfig($config);

        $result = $this->runBin(
            ['-p', 'hello there', '--output-format', 'json'],
            ['SUGARCRUSH_PROVIDER' => 'custom', 'CUSTOM_API_KEY' => 'k'],
        );

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);

        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertNull($decoded['result']);
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString($fragment, $decoded['error']['message']);
    }

    /**
     * The OTHER dispatch path: no `-p`, so the bin goes for the TUI. It must
     * die at exit 2 BEFORE `Program::run()` takes the terminal, which is the
     * whole reason the try/catch wraps both branches — and is safe to exec
     * precisely because it never gets that far.
     */
    public function testAnUnusablePermissionPolicyStopsTheTuiLaunchBeforeItTakesTheScreen(): void
    {
        $this->writeRawUserConfig('{"permissionMode":"plan",}');

        $result = $this->runBin([], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertSame('', $result['stdout'], 'the alt-screen was entered before the policy was read');
        $this->assertStringContainsString('not usable JSON', $result['stderr']);
    }

    /**
     * The absence half, end to end: a run with no config at all still starts.
     * Without this, the two tests above are equally satisfied by a binary that
     * refuses to launch on everything.
     */
    public function testAnAbsentConfigStillLaunchesTheOneShotRun(): void
    {
        $result = $this->runBin(['-p', 'hello there'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('hello there', $result['stdout']);
    }

    /**
     * Write a byte-exact ~/.sugar-crush/config.json into the throwaway HOME the
     * child will get. Raw, because these fixtures are precisely the JSON that
     * `Bootstrap::writeUserConfig()` can never produce.
     */
    private function writeRawUserConfig(string $contents): void
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_dispatch_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        if (!\is_dir($this->tempHome . '/.sugar-crush')) {
            \mkdir($this->tempHome . '/.sugar-crush', 0700, true);
        }

        \file_put_contents($this->tempHome . '/.sugar-crush/config.json', $contents);
    }

    /**
     * Seed the throwaway HOME `minimalEnv()` hands the child with a persisted
     * Ctrl+P provider choice. Creates the directory eagerly so the later
     * `runBin()` reuses this HOME rather than making a fresh empty one.
     */
    private function persistProviderChoice(string $provider): void
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_dispatch_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        \mkdir($this->tempHome . '/.sugar-crush', 0700, true);
        \file_put_contents(
            $this->tempHome . '/.sugar-crush/config.json',
            (string) \json_encode(['provider' => $provider]),
        );
    }

    /**
     * The unset-provider decision, end to end: with nothing configured the run
     * still succeeds offline (exit 0, a real reply on stdout) — nothing was
     * substituted for anything — but says on stderr that the answer came from
     * the offline provider, so the CI caller who merely forgot to export
     * `$SUGARCRUSH_PROVIDER` is not left guessing.
     *
     * Safe to exec: the offline `EchoProvider` makes no network call, and
     * one-shot mode returns before `Program::run()`.
     */
    public function testUnconfiguredOneShotRunAnswersOfflineAtExitZeroAndSaysSo(): void
    {
        $result = $this->runBin(['-p', 'hello there'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('hello there', $result['stdout']);
        $this->assertStringContainsString('no provider configured', $result['stderr']);
        $this->assertStringContainsString('offline echo provider', $result['stderr']);
    }

    /**
     * A run configured ONLY through `$SUGARCRUSH_BACKEND_CMD_STREAM` must not
     * be told it configured nothing.
     *
     * {@see \SugarCraft\Crush\Cli\NonInteractive}'s offline notice keys on
     * {@see \SugarCraft\Crush\Cli\Bootstrap::selectedProviderLabel()},
     * which had exactly one shell-out variable in it. Add a tier to
     * `Bootstrap::backend()` without teaching that helper about it and `-p`
     * answers from the tier it really selected while announcing on stderr that
     * nothing was configured — a claim about `SUGARCRUSH_BACKEND_CMD`'s domain,
     * printed over a run in a different one.
     *
     * `cat > /dev/null` before the reply is load-bearing: the child has to
     * consume the JSON history the parent writes, or the parent's write races
     * the closing pipe and takes an EPIPE.
     */
    public function testAStreamingShellOutRunIsNotToldNoProviderIsConfigured(): void
    {
        $result = $this->runBin(
            ['-p', 'anything'],
            ['SUGARCRUSH_BACKEND_CMD_STREAM' => 'cat > /dev/null; echo STREAMED42'],
        );

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('STREAMED42', $result['stdout'], 'the streaming tier never ran');
        $this->assertStringNotContainsString('no provider configured', $result['stderr']);
        $this->assertStringNotContainsString('offline echo provider', $result['stderr']);
    }

    /**
     * The same run with the variable removed DOES get the notice, so the
     * assertion above cannot be passing because the notice was deleted.
     */
    public function testTheOfflineNoticeStillFiresWithNeitherShellOutVariableSet(): void
    {
        $result = $this->runBin(['-p', 'anything'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('no provider configured', $result['stderr']);
        $this->assertStringContainsString('SUGARCRUSH_BACKEND_CMD_STREAM', $result['stderr'], 'the notice has to name every variable it claims is unset');
    }

    /**
     * Run the real bin/sugarcrush in a child process.
     *
     * stdin is /dev/null (never a TTY, so nothing can block waiting on input)
     * and the child is killed at the deadline; a hang surfaces as an explicit
     * failure. Because this repo's documented gotcha is that a plain
     * `timeout` does not reliably kill a PTY/TUI hang, a detached SIGKILL
     * watchdog on the exact pid is armed as a second layer — but only on the
     * deadline path, see {@see self::armWatchdog()}.
     *
     * @param list<string> $args
     * @param array<string, string>|null $env When non-null, the child gets a
     *   MINIMAL environment (PATH + a throwaway HOME) plus these entries, and
     *   inherits nothing else. Required for every provider-selection vector:
     *   inheriting the runner's environment would let a real `OPENAI_API_KEY`
     *   or a persisted `~/.sugar-crush/config.json` provider turn a vector
     *   that must fail at construction into one that opens a socket. Null
     *   inherits, which is right for the argv-only cases.
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runBin(array $args, ?array $env = null): array
    {
        $root = \dirname(__DIR__, 2);
        $command = 'exec ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/bin/sugarcrush');
        foreach ($args as $arg) {
            $command .= ' ' . \escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = \proc_open($command, $descriptors, $pipes, $root, $env === null ? null : $this->minimalEnv($env));
        $this->assertIsResource($process, 'failed to spawn bin/sugarcrush');

        // `exec` above makes $pid the php process itself, not an sh wrapper.
        $pid = (int) \proc_get_status($process)['pid'];

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = \microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            $stdout .= (string) \stream_get_contents($pipes[1]);
            $stderr .= (string) \stream_get_contents($pipes[2]);

            $status = \proc_get_status($process);
            if ($status['running'] === false) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (\microtime(true) > $deadline) {
                // Armed HERE rather than at spawn time. The normal path exits
                // in milliseconds, so a watchdog armed unconditionally fired
                // `kill -9` at a pid that had been dead for ~25s on every
                // single exec'd case. This host's pid_max is 4194304 so reuse
                // is negligible, but in a container with the classic 32768 on
                // a busy runner that is an unconditional SIGKILL aimed at
                // whatever unrelated process recycled the number. Coverage is
                // not lost: this branch is the only one the watchdog exists
                // for, because `proc_close()` below wait()s, so a child that
                // survived `proc_terminate()` would wedge the suite right
                // here — and the arm happens BEFORE the terminate so it is
                // already ticking if that wait() never returns.
                $this->armWatchdog($pid);
                \proc_terminate($process, 9);
                break;
            }

            \usleep(20000);
        }

        $stdout .= (string) \stream_get_contents($pipes[1]);
        $stderr .= (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);

        if ($exitCode === null) {
            $this->fail(
                'bin/sugarcrush ' . \implode(' ', $args) . ' did not exit within '
                . self::TIMEOUT_SECONDS . 's — it almost certainly fell through to Program::run(). '
                . 'stdout: ' . \substr($stdout, 0, 200)
            );
        }

        return ['status' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * PATH + a throwaway HOME + $overrides, and deliberately nothing else.
     *
     * A whitelist rather than "inherit and override": the whole point is that
     * NO `SUGARCRUSH_*` or provider credential from the runner's environment
     * can reach the child, and an override list cannot enumerate variables it
     * does not know the machine has set.
     *
     * TMPDIR earns its place for the same reason HOME does. The child is a real
     * CLI launch, so it performs a real startup sweep of abandoned tool-IPC
     * payloads ({@see \SugarCraft\Crush\Support\ToolIpcFiles::sweepOnce()}) —
     * and without this it performs it on the developer's actual `/tmp`.
     * tests/bootstrap.php parks a sandbox there for the whole run.
     *

     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function minimalEnv(array $overrides): array
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_dispatch_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        return \array_merge(
            [
                'PATH' => (string) (\getenv('PATH') ?: '/usr/bin:/bin'),
                'HOME' => $this->tempHome,
                'TMPDIR' => (string) (\getenv('TMPDIR') ?: \sys_get_temp_dir()),
            ],
            $overrides,
        );
    }

    /**
     * Arm a detached, identity-checked SIGKILL for $pid, firing
     * self::WATCHDOG_GRACE_SECONDS from now.
     *
     * WHY the descriptor gymnastics rather than the obvious one-liner
     * `shell_exec('(sleep N; kill -9 PID) >/dev/null 2>&1 &')`: that redirect
     * only covers fds 0-2, and PHP marks only its own proc_open pipes
     * FD_CLOEXEC. Every other descriptor the process holds — the running
     * script, whatever the test runner dup'd — is inherited by the detached
     * subshell and pinned open for the whole sleep. Under `phpunit | tail`
     * one of those is a dup of PHPUnit's stdout pipe, so the reader never saw
     * EOF: a 1.6s filtered run took 27s and left 20 orphaned sleeps.
     *
     * Swapping shell_exec for proc_open does NOT fix it on its own — the
     * inherited set above 2 leaks identically (measured: fd 3, the running
     * script, survives both). Hardcoding `3>&- 4>&-` assumes a layout that is
     * not ours to assume, and a shell-side `for` loop over /dev/fd is worse
     * still: dash parks the fds displaced by a compound redirection on high
     * descriptors, so the loop closes the shell's own bookkeeping out from
     * under it. So the actually-inherited set is enumerated from
     * /proc/self/fd and closed by number, before anything else runs.
     * proc_open (not shell_exec) then avoids creating a popen pipe at all.
     */
    private function armWatchdog(int $pid): void
    {
        $closes = '';
        foreach (\glob('/proc/self/fd/*') ?: [] as $entry) {
            $fd = (int) \basename($entry);
            if ($fd > 2) {
                $closes .= ' ' . $fd . '>&-';
            }
        }
        // Closing a descriptor that turns out not to have been inherited (a
        // CLOEXEC one, or glob()'s own transient dirfd) is a silent no-op.
        $closes = $closes === '' ? '' : 'exec' . $closes . '; ';

        // Only ever SIGKILL a pid that is still the child we spawned. `kill
        // -0` alone loses the pid-reuse race, so an identity token captured
        // here — while the child is provably still running — is re-checked
        // inside the watchdog after the sleep. Without /proc there is nothing
        // to compare against, so degrade to the liveness check alone rather
        // than to a watchdog that can never fire.
        $token = $this->startTimeToken($pid);
        $guard = 'kill -0 ' . $pid . ' 2>/dev/null';
        if ($token !== null) {
            $guard .= ' && [ "$(awk \'{sub(/^.*\\) /, ""); print $20}\' /proc/'
                . $pid . '/stat 2>/dev/null)" = ' . \escapeshellarg($token) . ' ]';
        }

        $devNull = static fn(string $mode): array => ['file', '/dev/null', $mode];
        $watchdog = @\proc_open(
            $closes . '(sleep ' . self::WATCHDOG_GRACE_SECONDS . '; ' . $guard
                . ' && kill -9 ' . $pid . ') >/dev/null 2>&1 &',
            [0 => $devNull('r'), 1 => $devNull('w'), 2 => $devNull('w')],
            $ignored,
            \dirname(__DIR__, 2)
        );

        if (\is_resource($watchdog)) {
            // The shell backgrounds the sleeper and exits immediately, so this
            // wait() returns at once instead of blocking out the whole grace.
            \proc_close($watchdog);
        }
    }

    /**
     * Identity token for $pid that survives exec() and cannot be recycled:
     * field 22 of /proc/<pid>/stat, the process start time in clock ticks
     * since boot.
     *
     * WHY not the far more obvious /proc/<pid>/comm: `proc_open()` starts the
     * child as `/bin/sh -c '<command>'`, so comm reads "sh" until the `exec`
     * in the command lands and "php" forever after. A watchdog comparing comm
     * captured at one side of that transition against comm read at the other
     * silently never fires — measured, before this was start-time based.
     * Start time is fixed at fork and is exactly the field the kernel's own
     * pid-reuse disambiguation uses.
     *
     * @return string|null Null when /proc is unavailable or unparseable.
     */
    private function startTimeToken(int $pid): ?string
    {
        $stat = @\file_get_contents('/proc/' . $pid . '/stat');
        if (!\is_string($stat)) {
            return null;
        }

        // comm is the only field allowed to contain spaces or parens, so it is
        // skipped by cutting at the LAST ") " rather than by splitting. What
        // follows starts at field 3 (state), which puts starttime (22) at
        // offset 19.
        $tail = \strrpos($stat, ') ');
        if ($tail === false) {
            return null;
        }

        $fields = \preg_split('/\s+/', \substr($stat, $tail + 2), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return $fields[19] ?? null;
    }
}
