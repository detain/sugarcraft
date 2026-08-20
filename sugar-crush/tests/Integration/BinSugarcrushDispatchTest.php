<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Cli\ParsedArgs;
use SugarCraft\Crush\Cli\Subcommands;
use SugarCraft\Crush\Session\EnhancedSessionStore;
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
     * crush_code.md Phase 4 item 6, the `--output-format` half. Measured
     * before the fix: `--output-format xml` reached every consumer, each of
     * which tests `=== FORMAT_JSON` and renders text otherwise, so this
     * invocation printed a plain-text answer and exited 0 — a `| jq` caller
     * got an empty pipe with a success status.
     */
    public function testAnUnsupportedOutputFormatIsAUsageErrorAtTheBinary(): void
    {
        $result = $this->runBin(['-p', 'hello there', '--output-format', 'xml'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stdout: ' . $result['stdout']);
        $this->assertStringContainsString('--output-format xml', $result['stderr']);
    }

    /**
     * The same vector WITHOUT `-p`. This is the case that decides where the
     * check has to live: the TUI never reads $outputFormat, so a check inside
     * NonInteractive would let this one open the alt-screen and block. Exec'd
     * deliberately — a regression that moves the check back out of the parser
     * shows up here as a 20-second watchdog kill, not as a green suite.
     */
    public function testAnUnsupportedOutputFormatDoesNotOpenTheTuiEither(): void
    {
        $result = $this->runBin(['--output-format', 'xml'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stdout: ' . $result['stdout']);
        $this->assertStringContainsString('unsupported output format', $result['stderr']);
    }

    /**
     * The hint printed under a usage error is now the one that error earns —
     * the binary used to print the `--prompt=<text>` remedy under every one.
     */
    public function testTheOutputFormatErrorDoesNotPrintThePromptHint(): void
    {
        $result = $this->runBin(['--output-format', 'xml'], []);

        $this->assertStringNotContainsString('--prompt=', $result['stderr']);
        $this->assertStringContainsString('text, json', $result['stderr']);
    }

    /**
     * `--config` naming no file is a usage error in the class of `--root`
     * naming no directory, and for a sharper reason: that file carries the
     * permission mode, the permission rules and the trustedProjectHooks list,
     * so falling through to discovery would run the DEFAULT policy while the
     * operator believed the named one was in force.
     */
    public function testAConfigThatNamesNoFileIsAUsageError(): void
    {
        $missing = \sys_get_temp_dir() . '/bin_config_absent_' . \uniqid('', true) . '.json';
        $result = $this->runBin(['-p', 'hello there', '--config', $missing], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stdout: ' . $result['stdout']);
        $this->assertStringContainsString('no such file', $result['stderr']);
        $this->assertStringContainsString($missing, $result['stderr']);
    }

    /** The TUI path rejects it identically — same reasoning as --root. */
    public function testAConfigThatNamesNoFileDoesNotOpenTheTui(): void
    {
        $missing = \sys_get_temp_dir() . '/bin_config_absent_tui_' . \uniqid('', true) . '.json';
        $result = $this->runBin(['--config', $missing], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stdout: ' . $result['stdout']);
        $this->assertStringContainsString('no such file', $result['stderr']);
    }

    /**
     * Reported through NonInteractive::failUsage() like every other exit-2
     * cause, so it honours the "exactly one JSON object on stdout" contract
     * rather than being a second reporting channel.
     */
    public function testTheConfigUsageErrorEmitsAJsonDocument(): void
    {
        $missing = \sys_get_temp_dir() . '/bin_config_absent_json_' . \uniqid('', true) . '.json';
        $result = $this->runBin(['--output-format', 'json', '--config', $missing], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);

        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString('--config', $decoded['error']['message']);
    }

    /**
     * `sugarcrush --config` with nothing after it. The parser stored null,
     * which is how absence is spelled, so configError() passed, the override
     * was never set, discovery stayed in force and the TUI opened —
     * MEASURED before the fix: rc 124 under a 15s bound, stdout beginning
     * `\e[?1049h`. The exec'd form is the assertion that matters here: this
     * is the "`--help` opens the TUI" class, and only a real child process
     * can tell a fall-through from a refusal.
     */
    public function testAConfigWithNoValueDoesNotOpenTheTui(): void
    {
        $result = $this->runBin(['--config'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stdout: ' . \substr($result['stdout'], 0, 120));
        $this->assertStringContainsString('--config', $result['stderr']);
        $this->assertSame('', $result['stdout'], 'the alt-screen was entered on a value-less --config');
    }

    /**
     * THE FLAG IS APPLIED BY THE BINARY, not merely validated by it.
     *
     * Both halves are the test: the same malformed policy is invisible when it
     * sits in a file nobody named (run 1, exit 0 — the discovered
     * ~/.sugar-crush in the throwaway HOME is empty), and stops the launch when
     * `--config` names it (run 2, exit 2). Without run 1 the case is satisfied
     * by a binary that refuses everything; without run 2, by a
     * `Bootstrap::useConfigPath(null)` — which is exactly the mutation that
     * survived the previous round, because every other `--config` test either
     * calls Bootstrap directly or asserts on a file that does not exist.
     *
     * The fixture lives in a 0700 directory inside the throwaway HOME, not in
     * /tmp directly: `requirePrivatePolicyFile()` refuses a policy file whose
     * directory is world-writable, so a /tmp fixture would fail for the wrong
     * reason.
     */
    public function testTheBinaryReadsThePolicyOutOfTheFileConfigNames(): void
    {
        $path = $this->writeOverridePolicy('{"permissionMode":"plan",}');

        $ignored = $this->runBin(['-p', 'hello there'], []);
        $this->assertSame(0, $ignored['status'], 'the fixture leaked into discovery: ' . $ignored['stderr']);

        $named = $this->runBin(['-p', 'hello there', '--config', $path], []);
        $this->assertSame(self::EXIT_USAGE, $named['status'], 'stdout: ' . $named['stdout']);
        $this->assertStringContainsString('not usable JSON', $named['stderr']);
        $this->assertStringContainsString($path, $named['stderr'], 'the failure named a file other than the one --config chose');
    }

    /**
     * The one exit-2 cause that CANNOT honour the README's "exactly one JSON
     * object on stdout" contract, asserted so the README and the binary cannot
     * drift apart again: the rejected value IS the requested rendering, so
     * there is no format left to emit the document in and stdout stays empty.
     * The README documents this as one of its two exceptions.
     */
    public function testAnInvalidOutputFormatIsTheDocumentedEmptyStdoutException(): void
    {
        $result = $this->runBin(['-p', 'hello there', '--output-format', 'xml'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status']);
        $this->assertSame('', $result['stdout'], 'the README exception says stdout is empty here');
        $this->assertStringContainsString('unsupported output format', $result['stderr']);
    }

    /**
     * ...and the exception is the invalid VALUE, not the flag: a valid
     * `--output-format json` next to a different usage error still emits the
     * document, which is the half of the README sentence that survives.
     */
    public function testAValidJsonFormatStillEmitsTheDocumentBesideAnotherUsageError(): void
    {
        $missing = \sys_get_temp_dir() . '/bin_config_absent_pair_' . \uniqid('', true) . '.json';
        $result = $this->runBin(['--output-format', 'json', '--config', $missing, '-p', 'hello there'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status']);
        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertNull($decoded['result']);
    }

    /**
     * Write a policy fixture the child can be pointed at with `--config`, in a
     * private directory inside the throwaway HOME `minimalEnv()` hands it.
     * Returns the absolute path.
     */
    private function writeOverridePolicy(string $contents): string
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_dispatch_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        if (!\is_dir($this->tempHome . '/elsewhere')) {
            \mkdir($this->tempHome . '/elsewhere', 0700, true);
        }

        $path = $this->tempHome . '/elsewhere/crush.json';
        \file_put_contents($path, $contents);

        return $path;
    }

    /**
     * `--config` is a RECOGNISED flag in both spellings, which is a different
     * assertion from "it errors": before it existed, this vector failed with
     * "unrecognized option: --config" and would keep passing an exit-code-only
     * check forever.
     */
    public function testConfigIsNotReportedAsAnUnrecognizedOption(): void
    {
        $missing = \sys_get_temp_dir() . '/bin_config_known_' . \uniqid('', true) . '.json';

        foreach ([['--config', $missing], ['--config=' . $missing]] as $args) {
            $result = $this->runBin($args, []);
            $this->assertStringNotContainsString('unrecognized option', $result['stderr']);
            $this->assertStringContainsString('no such file', $result['stderr']);
        }
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

    // -------------------------------------------------------------------------
    // crush_code.md Phase 4 item 6: the real subcommands.
    //
    // EVERY CASE HERE IS EXEC'D through runBin(), deliberately and against the
    // temptation to call Subcommands::dispatch() directly: ROUTING is the thing
    // most likely to break. A handler that works perfectly while ArgvParser
    // fails to recognise its verb is precisely the failure mode `--version` had
    // (crush_code.md Phase 0 item 3) — the token fell through every dispatch
    // guard into Program::run() and hung. A unit test on the handler cannot see
    // that; a child process that hangs on the deadline can.
    //
    // The env is always the minimal one ([] -> PATH + a throwaway HOME), so a
    // runner's real ~/.sugar-crush/config.json or SUGARCRUSH_PROVIDER cannot
    // change what these report.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function subcommandInvocations(): array
    {
        return [
            'doctor'          => [['doctor']],
            'models'          => [['models']],
            'session list'    => [['session', 'list']],
            'mcp list'        => [['mcp', 'list']],
            'completion bash' => [['completion', 'bash']],
            'completion zsh'  => [['completion', 'zsh']],
            'completion fish' => [['completion', 'fish']],
        ];
    }

    /**
     * The whole point of the item: each subcommand answers on a machine with no
     * provider, no config and no TTY, WITHOUT entering the alt-screen. A
     * regression that drops the dispatch branch in bin/sugarcrush reopens the
     * TUI, so this fails on runBin()'s deadline rather than hanging the suite.
     *
     * `doctor` is exercised here rather than exempted even though it is the one
     * that reads the most: it must not require the thing it diagnoses.
     *
     * @param list<string> $args
     *
     * @dataProvider subcommandInvocations
     */
    public function testEverySubcommandAnswersWithoutOpeningTheTui(array $args): void
    {
        $result = $this->runBin($args, []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertNotSame('', \trim($result['stdout']), 'a subcommand must not print nothing');
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'the TUI was entered');
        $this->assertStringNotContainsString('unrecognized option', $result['stderr']);
    }

    /**
     * A subcommand behind a flag. `run` regressed on exactly this (the parser
     * only recognised it at $argv[1], so any preceding flag discarded it and
     * the binary fell into Program::run()), and the new verbs are recognised by
     * the same loop — so the regression is available to them too.
     *
     * @return array<string, array{0: list<string>}>
     */
    public static function subcommandBehindAFlagInvocations(): array
    {
        return [
            '--output-format json first' => [['--output-format', 'json', 'models']],
            '--output-format=json first' => [['--output-format=json', 'models']],
            '--root . first'             => [['--root', '.', 'doctor']],
        ];
    }

    /**
     * @param list<string> $args
     *
     * @dataProvider subcommandBehindAFlagInvocations
     */
    public function testASubcommandIsDispatchedEvenWhenAFlagPrecedesIt(array $args): void
    {
        $result = $this->runBin($args, []);

        $this->assertNotSame(
            124,
            $result['status'],
            'the subcommand was discarded and the TUI opened: ' . \implode(' ', $args),
        );
        $this->assertStringNotContainsString("\x1b", $result['stdout'], 'the TUI was entered');
        $this->assertStringNotContainsString('unrecognized option', $result['stderr']);
    }

    /**
     * The exit-code convention, reused rather than re-invented: a subcommand
     * given a missing or unknown operand is exit 2 (nothing was attempted, a
     * retry cannot help), reported through NonInteractive::failUsage() like
     * every other pre-flight failure.
     *
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function subcommandUsageErrors(): array
    {
        return [
            'session with no action' => [['session'], 'no action given'],
            'session unknown action' => [['session', 'bogus'], 'unknown action'],
            'session delete with no id' => [['session', 'delete'], 'no session id given'],
            'mcp with no action'     => [['mcp'], 'no action given'],
            'mcp unknown action'     => [['mcp', 'bogus'], 'unknown action'],
            'completion with no shell' => [['completion'], 'no shell given'],
            'completion unknown shell' => [['completion', 'tcsh'], 'unsupported shell'],
        ];
    }

    /**
     * @param list<string> $args
     *
     * @dataProvider subcommandUsageErrors
     */
    public function testSubcommandOperandErrorsExitTwoAndStayStdoutSilent(array $args, string $fragment): void
    {
        $result = $this->runBin($args, []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString($fragment, $result['stderr']);
        $this->assertSame('', $result['stdout'], 'a text-mode usage error must not write to stdout');
    }

    /**
     * The same operand errors under `--output-format json` put ONE object on
     * stdout, because they go through the SAME NonInteractive::failUsage()
     * every other exit-2 cause does rather than through a second reporting
     * channel of their own.
     *
     * @param list<string> $args
     *
     * @dataProvider subcommandUsageErrors
     */
    public function testSubcommandOperandErrorsEmitTheJsonErrorDocument(array $args, string $fragment): void
    {
        $result = $this->runBin([...$args, '--output-format', 'json'], []);

        $this->assertSame(self::EXIT_USAGE, $result['status'], 'stderr: ' . $result['stderr']);
        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($result['stdout'], true));
        $this->assertNull($decoded['result']);
        $this->assertSame('usage', $decoded['error']['type']);
        $this->assertStringContainsString($fragment, $decoded['error']['message']);
    }

    /**
     * `doctor` reports the install rather than an image protocol — i.e. it is
     * NOT the model-invoked {@see \SugarCraft\Crush\Tools\BuiltIn\Doctor} tool,
     * which exists, is registered in Bootstrap::tools(), and answers the
     * completely different question "what image protocol does this terminal
     * speak" with a PNG swatch attached. crush_code.md Phase 4 item 6 asks for
     * them to stay distinct; this pins that they are.
     */
    public function testDoctorReportsTheInstallAndIsNotTheModelInvokedDoctorTool(): void
    {
        $result = $this->runBin(['doctor'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        foreach (['php', 'pdo_sqlite', 'config file', 'permission policy', 'provider', 'session store'] as $label) {
            $this->assertStringContainsString($label, $result['stdout'], "doctor omitted the '$label' check");
        }
        // The tool's own vocabulary, which the CLI report must not borrow.
        $this->assertStringNotContainsString('pixel-graphics', $result['stdout']);
        $this->assertStringNotContainsString('capability swatch', $result['stdout']);
    }

    /**
     * A config whose `permissionMode` is unusable makes the LAUNCH refuse to
     * start (PermissionConfigException -> exit 2 with an empty stdout). That is
     * exactly the install `doctor` exists for, so `doctor` must still answer,
     * name the problem, and exit 1 — "ran and failed", not "nothing was
     * attempted". This is why the dispatch sits OUTSIDE bin/sugarcrush's
     * try/catch.
     */
    public function testDoctorStillAnswersOnAnInstallThatRefusesToLaunch(): void
    {
        $home = $this->privateHome();
        \mkdir($home . '/.sugar-crush', 0700, true);
        \file_put_contents($home . '/.sugar-crush/config.json', '{"permissionMode":"nonsense"}');
        \chmod($home . '/.sugar-crush/config.json', 0600);

        $launch = $this->runBin(['-p', 'hi'], ['HOME' => $home]);
        $this->assertSame(self::EXIT_USAGE, $launch['status'], 'the launch was expected to refuse');

        $doctor = $this->runBin(['doctor'], ['HOME' => $home]);
        $this->assertSame(1, $doctor['status'], 'stderr: ' . $doctor['stderr']);
        $this->assertStringContainsString('FAIL permission policy', $doctor['stdout']);
        $this->assertStringContainsString('nonsense', $doctor['stdout']);
        $this->assertStringContainsString('1 check failed', $doctor['stdout']);
    }

    /**
     * `models` reports the same provider enumeration Bootstrap::backendFor()
     * resolves a name against — asserted through the JSON document so the shape
     * a `| jq` consumer sees is pinned too.
     */
    public function testModelsListsTheProvidersBootstrapCanSelect(): void
    {
        $result = $this->runBin(['models', '--output-format', 'json'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $decoded = \json_decode(\trim($result['stdout']), true);
        $this->assertIsArray($decoded);
        $this->assertNull($decoded['result']['selected'], 'the minimal env selects nothing');

        $names = \array_column($decoded['result']['providers'], 'provider');
        $this->assertContains('openai', $names);
        $this->assertContains('anthropic', $names);
        // Not a subset of some hand-written list: the SAME accessor, called
        // in-process here. Compared as a SORTED set because `models` ksort()s
        // its output for a stable display order, which is a presentation choice
        // and not a claim about the accessor -- the membership is the property
        // under test.
        $expected = \array_keys(Bootstrap::availableProviders());
        \sort($expected);
        $actual = $names;
        \sort($actual);
        $this->assertSame(
            $expected,
            $actual,
            'models must enumerate Bootstrap::availableProviders(), not a second list',
        );
        $this->assertSame($names, $actual, 'models output must be sorted by provider name');
    }

    /**
     * `SUGARCRUSH_PROVIDER` marks the selected row, which is the only part of
     * `models` that can be wrong in a way a name list cannot show.
     */
    public function testModelsMarksTheSelectedProvider(): void
    {
        $result = $this->runBin(['models'], ['SUGARCRUSH_PROVIDER' => 'openai']);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertMatchesRegularExpression('/^\* openai\s/m', $result['stdout']);
        $this->assertMatchesRegularExpression('/^  anthropic\s/m', $result['stdout']);
    }

    /**
     * `session list` shows what the store holds, and `session delete <id>`
     * removes exactly that row. Seeded through the real
     * {@see EnhancedSessionStore} at the path Bootstrap derives from $HOME, so
     * the id printed by `list` is the id `delete` accepts — the property that
     * would break if the subcommand opened session.db by a second route.
     */
    public function testSessionListShowsSeededRowsAndDeleteRemovesOne(): void
    {
        $home = $this->privateHome();
        \mkdir($home . '/.sugar-crush', 0700, true);
        $store = new EnhancedSessionStore($home . '/.sugar-crush/session.db');
        $store->createSession('sess-keep-0001', 'openai', 'gpt-4o', null, 'keep-me');
        $store->createSession('sess-drop-0002', 'openai', 'gpt-4o', null, 'drop-me');

        $listed = $this->runBin(['session', 'list'], ['HOME' => $home]);
        $this->assertSame(0, $listed['status'], 'stderr: ' . $listed['stderr']);
        $this->assertStringContainsString('sess-keep-0001', $listed['stdout']);
        $this->assertStringContainsString('sess-drop-0002', $listed['stdout']);
        $this->assertStringContainsString('drop-me', $listed['stdout']);

        $deleted = $this->runBin(['session', 'delete', 'sess-drop-0002'], ['HOME' => $home]);
        $this->assertSame(0, $deleted['status'], 'stderr: ' . $deleted['stderr']);
        $this->assertStringContainsString('sess-drop-0002', $deleted['stdout']);

        $after = $this->runBin(['session', 'list'], ['HOME' => $home]);
        $this->assertStringContainsString('sess-keep-0001', $after['stdout']);
        $this->assertStringNotContainsString('sess-drop-0002', $after['stdout']);
    }

    /**
     * An id nothing matches is exit 1, NOT 2, and the distinction is the
     * documented one: the store was opened and queried, so something ran. Exit
     * 2 is reserved for "nothing was attempted", which is what a MISSING id is
     * — asserted beside it so the two cannot quietly collapse into one code.
     */
    public function testDeletingAnUnknownSessionIsExitOneWhileOmittingTheIdIsExitTwo(): void
    {
        $home = $this->privateHome();
        \mkdir($home . '/.sugar-crush', 0700, true);
        new EnhancedSessionStore($home . '/.sugar-crush/session.db');

        $unknown = $this->runBin(['session', 'delete', 'no-such-session'], ['HOME' => $home]);
        $this->assertSame(1, $unknown['status'], 'stderr: ' . $unknown['stderr']);
        $this->assertStringContainsString('no such session', $unknown['stderr']);

        $omitted = $this->runBin(['session', 'delete'], ['HOME' => $home]);
        $this->assertSame(self::EXIT_USAGE, $omitted['status']);
    }

    /**
     * `mcp list` reads `.mcp.json` and STARTS NOTHING. The fixture's only
     * server is a `command` that would create a file if it were ever executed;
     * asserting the file's absence is the only way to prove the listing did not
     * take mcpClient()'s proc_open() path — a `mcp list` implemented by asking
     * for the client would launch every program the repository names, which is
     * the act the trust gate exists to make deliberate.
     */
    public function testMcpListEnumeratesDeclaredServersWithoutStartingThem(): void
    {
        $home = $this->privateHome();
        $project = $this->privateProject();
        $tripwire = $project . '/started.tripwire';

        \file_put_contents($project . '/.mcp.json', \json_encode([
            'mcpServers' => [
                'tripwire' => ['command' => '/usr/bin/touch', 'args' => [$tripwire]],
                'remote' => ['type' => 'http', 'url' => 'https://example.invalid/mcp'],
            ],
        ]));

        \mkdir($home . '/.sugar-crush', 0700, true);
        \file_put_contents(
            $home . '/.sugar-crush/config.json',
            \json_encode(['trustedProjectMcp' => [\realpath($project)]]),
        );
        \chmod($home . '/.sugar-crush/config.json', 0600);

        $result = $this->runBin(['--root', $project, 'mcp', 'list'], ['HOME' => $home]);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('tripwire', $result['stdout']);
        $this->assertStringContainsString('stdio', $result['stdout']);
        $this->assertStringContainsString('remote', $result['stdout']);
        $this->assertStringContainsString('https://example.invalid/mcp', $result['stdout']);
        $this->assertFileDoesNotExist($tripwire, 'mcp list started a configured server');
    }

    /**
     * An untrusted project root is REPORTED, not enumerated: listing the
     * servers of a file this launch refuses to run would tell the operator the
     * opposite of the truth. Same trust verdict mcpClient() makes, because both
     * come through Bootstrap::mcpConfigDecision().
     */
    public function testMcpListReportsAnUntrustedProjectRootInsteadOfListingIt(): void
    {
        $project = $this->privateProject();
        \file_put_contents(
            $project . '/.mcp.json',
            \json_encode(['mcpServers' => ['secret' => ['command' => '/usr/bin/true']]]),
        );

        $result = $this->runBin(['--root', $project, 'mcp', 'list'], []);

        $this->assertSame(0, $result['status'], 'stderr: ' . $result['stderr']);
        $this->assertStringContainsString('not trusted', $result['stdout']);
        $this->assertStringContainsString('trustedProjectMcp', $result['stdout']);
        $this->assertStringNotContainsString('secret', $result['stdout'], 'an untrusted config was enumerated');
    }

    /**
     * A throw from inside a subcommand is exit 2 with a message, NOT an
     * uncaught PHP fatal with a stack trace over the terminal.
     *
     * MEASURED before the fix: with the dispatch placed OUTSIDE
     * bin/sugarcrush's try/catch, a `~/.sugar-crush/config.json` of `{ this is
     * not json` made `mcp list` die with rc 255 and a
     * `PHP Fatal error: Uncaught PermissionConfigException` — because
     * `mcpConfigDecision()` reaches `permissionConfig()` through
     * `projectMcpIsTrusted()`. The dispatch now sits inside that block, so this
     * reports like every other unusable-policy path.
     *
     * `doctor` is asserted beside it because moving the dispatch inside the
     * catch is exactly what could cost `doctor` its report — it must still
     * print, name the failure and exit 1 rather than being swallowed into a
     * bare exit 2. That it does not is a property of the per-probe catches in
     * Subcommands::doctorProbes(), and this is what holds them there.
     */
    public function testAThrowInsideASubcommandIsAReportedExitTwoAndNeverAFatal(): void
    {
        $home = $this->privateHome();
        $project = $this->privateProject();
        \file_put_contents(
            $project . '/.mcp.json',
            \json_encode(['mcpServers' => ['x' => ['command' => '/usr/bin/true']]]),
        );
        \mkdir($home . '/.sugar-crush', 0700, true);
        \file_put_contents($home . '/.sugar-crush/config.json', '{ this is not json');
        \chmod($home . '/.sugar-crush/config.json', 0600);

        $mcp = $this->runBin(['--root', $project, 'mcp', 'list'], ['HOME' => $home]);
        $this->assertSame(self::EXIT_USAGE, $mcp['status'], 'stdout: ' . $mcp['stdout']);
        $this->assertStringNotContainsString('Fatal error', $mcp['stderr']);
        $this->assertStringNotContainsString('Stack trace', $mcp['stderr']);
        $this->assertStringContainsString('not usable JSON', $mcp['stderr']);

        // ...and the JSON contract still holds on that exit.
        $json = $this->runBin(
            ['--root', $project, 'mcp', 'list', '--output-format', 'json'],
            ['HOME' => $home],
        );
        $decoded = \json_decode(\trim($json['stdout']), true);
        $this->assertIsArray($decoded, 'stdout was not JSON: ' . \var_export($json['stdout'], true));
        $this->assertSame('usage', $decoded['error']['type']);

        // The catch must not have swallowed doctor's whole report.
        $doctor = $this->runBin(['--root', $project, 'doctor'], ['HOME' => $home]);
        $this->assertSame(1, $doctor['status'], 'stderr: ' . $doctor['stderr']);
        $this->assertStringContainsString('FAIL', $doctor['stdout']);
        $this->assertStringContainsString('not usable JSON', $doctor['stdout']);
    }

    /**
     * `--config <file>` must reach a subcommand, which is why the dispatch sits
     * AFTER Bootstrap::useConfigPath() in bin/sugarcrush rather than before it.
     * The fixture is chosen so the two answers cannot be confused: the SAME
     * project root, listed under `trustedProjectMcp` in the file `--config`
     * names and absent from the one discovery would find, so `mcp list` either
     * enumerates the server (the override was honoured) or reports the root as
     * untrusted (it was not). A dispatch moved above useConfigPath() reports
     * "not trusted" here.
     */
    public function testConfigOverrideReachesASubcommand(): void
    {
        $home = $this->privateHome();
        $project = $this->privateProject();
        \file_put_contents(
            $project . '/.mcp.json',
            \json_encode(['mcpServers' => ['named' => ['command' => '/usr/bin/true']]]),
        );

        $override = $home . '/elsewhere.json';
        \file_put_contents($override, \json_encode(['trustedProjectMcp' => [\realpath($project)]]));
        \chmod($override, 0600);

        $discovered = $this->runBin(['--root', $project, 'mcp', 'list'], ['HOME' => $home]);
        $this->assertStringContainsString('not trusted', $discovered['stdout'], 'the fixture is not discriminating');

        $overridden = $this->runBin(['--config', $override, '--root', $project, 'mcp', 'list'], ['HOME' => $home]);
        $this->assertSame(0, $overridden['status'], 'stderr: ' . $overridden['stderr']);
        $this->assertStringContainsString('named', $overridden['stdout']);
        $this->assertStringNotContainsString('not trusted', $overridden['stdout']);
    }

    /**
     * The three completion dialects are genuinely different, not one script
     * under three labels — emitting bash syntax under a `zsh` label would be
     * worse than emitting nothing, because compinit would source it and break
     * the user's completion. bash syntax is additionally checked by `bash -n`
     * below, which is the only assertion here that proves the output PARSES.
     */
    public function testTheThreeCompletionDialectsAreDistinct(): void
    {
        $bash = $this->runBin(['completion', 'bash'], [])['stdout'];
        $zsh = $this->runBin(['completion', 'zsh'], [])['stdout'];
        $fish = $this->runBin(['completion', 'fish'], [])['stdout'];

        $this->assertNotSame($bash, $zsh);
        $this->assertNotSame($zsh, $fish);
        $this->assertNotSame($bash, $fish);

        // bash: a COMPREPLY-filling function bound with `complete -F`.
        $this->assertStringContainsString('COMPREPLY=', $bash);
        $this->assertStringContainsString('complete -F _sugarcrush sugarcrush', $bash);

        // zsh: #compdef plus _arguments/_describe. It must carry NONE of bash's
        // vocabulary — that substitution is the failure this test exists for.
        $this->assertStringStartsWith('#compdef sugarcrush', $zsh);
        $this->assertStringContainsString('_arguments', $zsh);
        $this->assertStringContainsString('_describe', $zsh);
        $this->assertStringNotContainsString('COMPREPLY', $zsh);
        $this->assertStringNotContainsString('compgen', $zsh);

        // fish: declarative `complete -c` lines, no dispatch function at all.
        $this->assertStringContainsString('complete -c sugarcrush', $fish);
        $this->assertStringContainsString('__fish_use_subcommand', $fish);
        $this->assertStringNotContainsString('COMPREPLY', $fish);
        $this->assertStringNotContainsString('_arguments', $fish);
    }

    /**
     * The bash script actually PARSES. Everything above is substring matching,
     * which a syntactically broken script passes just as happily — and a broken
     * script is worse than none, because `eval "$(sugarcrush completion bash)"`
     * runs in the user's interactive shell.
     */
    public function testTheBashCompletionScriptParses(): void
    {
        $script = $this->runBin(['completion', 'bash'], [])['stdout'];
        $path = \sys_get_temp_dir() . '/sugarcrush_completion_' . \uniqid('', true) . '.bash';
        \file_put_contents($path, $script);

        $output = [];
        $status = 0;
        \exec('bash -n ' . \escapeshellarg($path) . ' 2>&1', $output, $status);
        @\unlink($path);

        $this->assertSame(0, $status, 'bash -n rejected the emitted script: ' . \implode("\n", $output));
    }

    /**
     * Every subcommand and every option the completions offer is one the parser
     * actually recognises. A completion script that offers a flag the binary
     * rejects is a trap, and the two lists sit in different classes.
     */
    public function testTheCompletionsOnlyOfferTokensTheParserAccepts(): void
    {
        $bash = $this->runBin(['completion', 'bash'], [])['stdout'];

        \preg_match('/COMPREPLY=\(\$\(compgen -W "([^"]+)" -- "\$cur"\)\)\n}/', $bash, $verbs);
        $this->assertNotEmpty($verbs, 'could not find the subcommand word list in the bash script');

        foreach (\explode(' ', $verbs[1]) as $verb) {
            if ($verb === 'run') {
                $parsed = ArgvParser::parse(['sugarcrush', $verb, 'x']);
                $this->assertTrue($parsed->promptRequested, "`$verb` is offered but not recognised");
                continue;
            }
            $parsed = ArgvParser::parse(['sugarcrush', $verb]);
            $this->assertSame($verb, $parsed->subcommand, "`$verb` is offered but not recognised");
        }

        \preg_match('/compgen -W "(--[^"]+)" -- "\$cur"/', $bash, $options);
        $this->assertNotEmpty($options, 'could not find the option word list in the bash script');
        foreach (\explode(' ', $options[1]) as $flag) {
            $parsed = ArgvParser::parse(['sugarcrush', $flag, 'value']);
            $this->assertSame([], $parsed->unknownFlags, "`$flag` is offered but the parser rejects it");
        }
    }

    /**
     * `--` still turns a subcommand verb back into a plain operand — asserted
     * at the parse layer because the correct outcome is "boot the TUI", and
     * exec'ing that is the hang the rest of this file guards against.
     */
    public function testASubcommandVerbAfterTheSeparatorIsAnOperand(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--', 'doctor']);

        $this->assertNull($args->subcommand);
        $this->assertSame([], $args->unknownFlags);
        $this->assertFalse($args->promptRequested);
    }

    /**
     * A trusted `.mcp.json` that cannot be PARSED is exit 1 in BOTH output
     * formats.
     *
     * MEASURED before this fix: `mcp list` returned 1 while
     * `--output-format json mcp list` returned 0 for the same install, so
     * `sugarcrush mcp list --output-format json || fail` was a CI gate that
     * could never fire. The JSON arm also put the failure string at
     * `result.error`, so a consumer branching on the package's top-level
     * `.error` envelope read null on a failure.
     *
     * Asserted here rather than at the accessor because the defect lived in
     * the exit code, which only the binary produces.
     */
    public function testAnUnreadableMcpConfigIsExitOneInBothOutputFormats(): void
    {
        $home = $this->privateHome();
        $project = $this->privateProject();
        \file_put_contents($project . '/.mcp.json', '{ this is not json');

        \mkdir($home . '/.sugar-crush', 0700, true);
        \file_put_contents(
            $home . '/.sugar-crush/config.json',
            \json_encode(['trustedProjectMcp' => [\realpath($project)]]),
        );
        \chmod($home . '/.sugar-crush/config.json', 0600);

        $text = $this->runBin(['--root', $project, 'mcp', 'list'], ['HOME' => $home]);
        $this->assertSame(1, $text['status'], 'stdout: ' . $text['stdout']);
        $this->assertStringContainsString('not valid JSON', $text['stderr']);

        $json = $this->runBin(['--root', $project, '--output-format', 'json', 'mcp', 'list'], ['HOME' => $home]);
        $this->assertSame(
            $text['status'],
            $json['status'],
            'the exit code changed with the output format: ' . $json['stdout'],
        );

        $document = \json_decode(\trim($json['stdout']), true);
        $this->assertIsArray($document, 'stdout was not one JSON document: ' . $json['stdout']);
        $this->assertNull($document['result'], 'a failure was reported with a non-null result');
        $this->assertIsArray($document['error'] ?? null, 'the failure is not in the top-level error envelope');
        $this->assertArrayHasKey('type', $document['error']);
        $this->assertStringContainsString('not valid JSON', (string) $document['error']['message']);
    }

    /**
     * ...while a config that is merely REFUSED — absent, out of tree,
     * untrusted — is an answer, and stays exit 0 in both formats. Asserted
     * beside the case above so "make the codes agree" cannot be satisfied by
     * failing everything.
     */
    public function testARefusedMcpConfigIsExitZeroInBothOutputFormats(): void
    {
        $project = $this->privateProject();
        \file_put_contents(
            $project . '/.mcp.json',
            (string) \json_encode(['mcpServers' => ['secret' => ['command' => '/usr/bin/true']]]),
        );

        $text = $this->runBin(['--root', $project, 'mcp', 'list'], []);
        $json = $this->runBin(['--root', $project, '--output-format', 'json', 'mcp', 'list'], []);

        $this->assertSame(0, $text['status'], 'stderr: ' . $text['stderr']);
        $this->assertSame(0, $json['status'], 'stdout: ' . $json['stdout']);

        $document = \json_decode(\trim($json['stdout']), true);
        $this->assertIsArray($document);
        $this->assertNull($document['error'] ?? null, 'a refusal was reported as a failure');
        $this->assertSame([], $document['result']['servers'], 'an untrusted config was enumerated');
    }

    /**
     * `doctor` COUNTS the session rows; it must not delete any.
     *
     * MEASURED before the fix: the probe called the plain
     * Bootstrap::sessionStore(), which applies the opt-in
     * SUGARCRUSH_SESSION_RETENTION_DAYS sweep on construction — so a health
     * check on this fixture printed "retention removed 1 unnamed session" and
     * the table went 2 rows to 1. The sweep is a launch behaviour; a
     * diagnostic is read-only.
     */
    public function testDoctorCountsStoredSessionsWithoutPruningThem(): void
    {
        $home = $this->privateHome();
        \mkdir($home . '/.sugar-crush', 0700, true);
        $dbPath = $home . '/.sugar-crush/session.db';
        $store = new EnhancedSessionStore($dbPath);
        $store->createSession('sess-ancient-01', 'openai', 'gpt-4o', null, null);
        $store->createSession('sess-recent-02', 'openai', 'gpt-4o', null, null);

        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', 'sess-ancient-01']);

        $result = $this->runBin(
            ['doctor'],
            ['HOME' => $home, 'SUGARCRUSH_SESSION_RETENTION_DAYS' => '7'],
        );

        $this->assertStringNotContainsString('retention removed', $result['stderr']);
        $this->assertStringContainsString('2 session(s) stored', $result['stdout']);

        $after = new EnhancedSessionStore($dbPath);
        $this->assertNotNull($after->getSession('sess-ancient-01'), 'doctor deleted a stored conversation');
        $this->assertNotNull($after->getSession('sess-recent-02'));
    }

    /**
     * Every verb the parser accepts is offered by ALL THREE completion
     * scripts.
     *
     * The existing round-trip is one-directional — it proves every offered
     * token parses, and so passes just as happily when a verb is offered by
     * nobody. MEASURED: deleting one row of
     * Subcommands::SUBCOMMAND_DESCRIPTIONS drops that verb out of all three
     * scripts with the whole suite green.
     */
    public function testEveryVerbTheParserAcceptsIsOfferedByAllThreeCompletions(): void
    {
        $scripts = [
            'bash' => $this->runBin(['completion', 'bash'], [])['stdout'],
            'zsh' => $this->runBin(['completion', 'zsh'], [])['stdout'],
            'fish' => $this->runBin(['completion', 'fish'], [])['stdout'],
        ];

        // ParsedArgs ships INSIDE ArgvParser.php, so PSR-4 only has it once
        // that file is loaded — a bare ParsedArgs::SUBCOMMANDS here is a
        // "class not found" in a test that touches the parser nowhere else.
        \class_exists(ArgvParser::class);

        // `run` is not in SUBCOMMANDS (the parser handles it as a prompt
        // alias) but is a word a user types, so it is offered too.
        foreach ([...ParsedArgs::SUBCOMMANDS, 'run'] as $verb) {
            foreach ($scripts as $shell => $script) {
                $this->assertMatchesRegularExpression(
                    '/\b' . \preg_quote($verb, '/') . '\b/',
                    $script,
                    $shell . ' completion never offers the `' . $verb . '` subcommand',
                );
            }
        }

        // ...and every flag the one OPTIONS table names reaches all three
        // generators, so a flag added there cannot silently miss a dialect.
        // fish spells a long option `-l name`, not `--name`, which is why the
        // needle differs per shell rather than being one string.
        $options = (new \ReflectionClass(Subcommands::class))->getConstant('OPTIONS');
        $this->assertIsArray($options);
        foreach (\array_keys($options) as $flag) {
            $flag = (string) $flag;
            $this->assertStringContainsString($flag, $scripts['bash'], 'bash completion omits ' . $flag);
            $this->assertStringContainsString($flag, $scripts['zsh'], 'zsh completion omits ' . $flag);
            $this->assertStringContainsString(
                '-l ' . \ltrim($flag, '-'),
                $scripts['fish'],
                'fish completion omits ' . $flag,
            );
        }

        // Every flag `parse()` itself branches on is in that table. Read out
        // of the parser's source because nothing else enumerates them, and a
        // flag the binary accepts but no completion offers is a feature users
        // never find.
        $parserSource = (string) \file_get_contents(
            (string) (new \ReflectionClass(ArgvParser::class))->getFileName(),
        );
        \preg_match_all("/\\\$arg === '(--[a-z][a-z-]*)'/", $parserSource, $matches);
        $this->assertNotEmpty($matches[1], 'could not enumerate the parser\'s long flags');
        foreach (\array_unique($matches[1]) as $flag) {
            $this->assertArrayHasKey(
                $flag,
                $options,
                $flag . ' is accepted by the parser but offered by no completion',
            );
        }
    }

    /**
     * `doctor` and `models` take no operands, and REJECT one rather than
     * discarding it.
     *
     * MEASURED before the fix: `sugarcrush models delete everything` printed
     * the provider table at exit 0. `session`, `mcp` and `completion` all
     * reject an unknown operand at exit 2; a word the CLI silently ignores is
     * a word the user believes did something.
     */
    public function testDoctorAndModelsRejectAnOperand(): void
    {
        foreach ([['models', 'delete', 'everything'], ['doctor', 'bogus']] as $argv) {
            $result = $this->runBin($argv, []);
            $label = \implode(' ', $argv);

            $this->assertSame(self::EXIT_USAGE, $result['status'], $label . ': ' . $result['stdout']);
            $this->assertStringContainsString('unexpected operand', $result['stderr'], $label);
            $this->assertSame('', $result['stdout'], $label . ' printed its report anyway');
        }
    }

    /**
     * The `pdo_sqlite` probe EXERCISES the driver rather than checking an
     * extension name, and the driver it exercises is the one the session store
     * opens.
     *
     * The name-checking spelling (`extension_loaded('pdo_sqlite')`) could be
     * swapped for the neighbouring `sqlite3` — the extension composer.json
     * declares and nothing in src/ calls — with every test green, because both
     * are loaded on any box that runs this suite. There is no host here on
     * which the two answers differ, so the check is pinned in the two ways
     * that do not need one: the failing branch is driven directly with a DSN
     * no driver claims, and the probe's DSN scheme is compared against the
     * literal in SessionStore's own constructor.
     */
    public function testThePdoProbeExercisesTheDriverTheSessionStoreOpens(): void
    {
        $probe = new \ReflectionMethod(Subcommands::class, 'pdoDriverProbe');
        $probe->setAccessible(true);
        $dsn = (string) (new \ReflectionClass(Subcommands::class))->getConstant('SESSION_STORE_PROBE_DSN');

        $good = $probe->invoke(null, $dsn);
        $this->assertSame('OK', $good['status'], (string) $good['detail']);
        $this->assertStringContainsString('pdo_sqlite usable', (string) $good['detail']);

        // The branch no host can reach by unloading an extension.
        $bad = $probe->invoke(null, 'nosuchdriver:whatever');
        $this->assertSame('FAIL', $bad['status'], (string) $bad['detail']);
        $this->assertStringContainsString('the session store cannot open its database', (string) $bad['detail']);

        // ...and it is the store's OWN driver being opened.
        $storeSource = (string) \file_get_contents(
            (string) (new \ReflectionClass(EnhancedSessionStore::class))->getFileName(),
        );
        $storeSource .= (string) \file_get_contents(
            (string) (new \ReflectionClass(\SugarCraft\Crush\Session\SessionStore::class))->getFileName(),
        );
        \preg_match('/new PDO\("([a-z0-9]+):/i', $storeSource, $m);
        $this->assertNotEmpty($m, 'could not find the session store\'s PDO DSN');
        $this->assertStringStartsWith(
            $m[1] . ':',
            $dsn,
            'doctor probes a different PDO driver than the session store opens',
        );
    }

    /**
     * A private HOME for one test, separate from minimalEnv()'s shared
     * $tempHome so a test that seeds a config or a database cannot contaminate
     * the neighbouring cases that assume an empty one. Cleaned by tearDown()
     * along with the rest of $tempHome.
     */
    private function privateHome(): string
    {
        if ($this->tempHome === '') {
            $this->tempHome = \sys_get_temp_dir() . '/bin_dispatch_home_' . \uniqid('', true);
            \mkdir($this->tempHome, 0700, true);
        }

        $home = $this->tempHome . '/home_' . \uniqid('', true);
        \mkdir($home, 0700, true);

        return $home;
    }

    /** A throwaway project root, for the `.mcp.json` fixtures. */
    private function privateProject(): string
    {
        $project = $this->privateHome() . '/project';
        \mkdir($project, 0700, true);

        return $project;
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
