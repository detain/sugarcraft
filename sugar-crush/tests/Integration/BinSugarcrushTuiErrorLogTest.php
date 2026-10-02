<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Audit C2a, at the binary: `bin/sugarcrush` installs
 * {@see \SugarCraft\Crush\Diagnostics\TuiErrorLog} on the TUI path, right
 * before `Program::run()`, and on no other path.
 *
 * READ FROM SOURCE, not exec'd, for the reason
 * {@see BinSugarcrushDispatchTest} gives its TUI-bound controls: exec'ing the
 * TUI path IS the blocking `Program::run()`. Comments are stripped first so
 * the comment that explains the call cannot satisfy the assertions about it.
 *
 * WHAT EACH ORDERING BUYS. After the `-p` and subcommand dispatches, because
 * on those paths stderr is the operator's channel and a redirect would hide
 * their diagnostics in a file. Before `Bootstrap::app()`, with nothing but
 * the display_errors block between it and `new Program(`, because app()
 * builds the Chat that arms the notice sink, and the turn child forked after
 * that must inherit the redirected ini.
 *
 * The same file pins the display_errors half: audit CLI-1's top-of-file
 * `ini_set('display_errors', 'stderr')`, and the TUI block that stops
 * displaying diagnostics while the redirect holds and reports a fatal in one
 * line naming the log.
 */
final class BinSugarcrushTuiErrorLogTest extends TestCase
{
    private const INSTALL = '$tuiErrorLog = TuiErrorLog::install(HomeDirectory::owned());';

    public function testTheRedirectIsInstalledExactlyOnceFromTheOwnedHome(): void
    {
        $code = self::binCode();

        self::assertSame(1, substr_count($code, 'TuiErrorLog::install('), 'expected exactly one install call');
        self::assertStringContainsString(self::INSTALL, $code, 'the install must take HomeDirectory::owned()');
    }

    /**
     * Between the redirect and `Program::run()` sits only the display_errors
     * block below, which builds nothing: no Bootstrap, no Chat, no Program.
     */
    public function testNothingIsBuiltBetweenTheRedirectAndProgramRun(): void
    {
        $code = self::binCode();
        $install = strpos($code, self::INSTALL);
        $program = strpos($code, '(new Program(Bootstrap::app(');

        self::assertIsInt($install);
        self::assertIsInt($program);
        self::assertGreaterThan($install, $program);

        $between = substr($code, $install + \strlen(self::INSTALL), $program - $install - \strlen(self::INSTALL));
        foreach (['Bootstrap::', 'Chat::', 'new ', 'pcntl_fork'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $between,
                'something is built between the redirect and Program::run() — Bootstrap::app() must come after it',
            );
        }
        // displayBlock() asserts that the slice is exactly one `if` statement.
        self::displayBlock($code);
    }

    /**
     * Audit CLI-1: the first statement after `declare(strict_types=1);` sends
     * display_errors to stderr, ahead of the autoload guard and everything
     * else, so no warning can reach stdout ahead of a JSON document.
     */
    public function testDisplayErrorsGoesToStderrBeforeAnythingElseRuns(): void
    {
        $code = self::binCode();
        $declare = strpos($code, 'declare(strict_types=1);');
        self::assertIsInt($declare);

        self::assertStringStartsWith(
            "ini_set('display_errors', 'stderr');",
            ltrim(substr($code, $declare + \strlen('declare(strict_types=1);'))),
        );
        self::assertSame(1, substr_count($code, "ini_set('display_errors', 'stderr');"));
    }

    /**
     * C2a, finished: with the redirect in force PHP's own diagnostics are
     * logged to the file and NOT displayed (display_errors would paint them
     * over the frame), and a fatal leaves one line on the terminal naming the
     * log. All of it is conditional on install() having returned a path, so a
     * failed redirect keeps display_errors on stderr.
     */
    public function testTheTuiStopsDisplayingDiagnosticsOnlyWhileTheRedirectHolds(): void
    {
        $block = self::displayBlock(self::binCode());

        self::assertStringStartsWith('if ($tuiErrorLog !== null) {', $block);
        $off = strpos($block, "ini_set('display_errors', '0');");
        $log = strpos($block, "ini_set('log_errors', '1');");
        $shutdown = strpos($block, 'register_shutdown_function(');
        self::assertIsInt($off, 'display_errors is not switched off under the redirect');
        self::assertIsInt($log, 'log_errors is not forced on under the redirect');
        self::assertIsInt($shutdown, 'no shutdown callback reports a fatal');

        foreach (['E_ERROR', 'E_PARSE', 'E_CORE_ERROR', 'E_COMPILE_ERROR', 'E_USER_ERROR', 'E_RECOVERABLE_ERROR'] as $type) {
            self::assertMatchesRegularExpression('/\b' . $type . '\b/', $block, $type . ' is not treated as fatal');
        }
        self::assertStringContainsString('error_get_last()', $block);
        self::assertStringContainsString('fwrite(STDERR, ', $block);
        self::assertStringContainsString('{$tuiErrorLog}', $block, 'the fatal line must name the log file');
    }

    /**
     * Audit R16: install() can now return the null device as its last
     * resort. A fatal line saying "details in /dev/null" would send the
     * reader nowhere, so that branch prints the message itself.
     */
    public function testAFatalUnderTheNullDeviceFallbackCarriesItsOwnMessage(): void
    {
        $block = self::displayBlock(self::binCode());

        $branch = strpos($block, 'if (TuiErrorLog::isNullDevice($tuiErrorLog)) {');
        self::assertIsInt($branch, 'the fatal line no longer distinguishes the null-device fallback');
        $after = substr($block, $branch);
        $message = strpos($after, "{\$error['message']}");
        $write = strpos($after, 'fwrite(STDERR, ');
        self::assertIsInt($message, 'the null-device fatal line must carry the message');
        self::assertIsInt($write);
        self::assertLessThan($write, $message, 'the message must be chosen on the null-device branch, before the one write');
    }

    public function testTheOneShotAndSubcommandDispatchesComeBeforeTheRedirect(): void
    {
        $code = self::binCode();
        $install = strpos($code, self::INSTALL);
        self::assertIsInt($install);

        foreach (['Subcommands::dispatch(', 'NonInteractive::run(', 'Help::screen()', 'Help::version()'] as $dispatch) {
            $at = strpos($code, $dispatch);
            self::assertIsInt($at, $dispatch . ' is no longer in bin/sugarcrush; this guard needs re-deriving');
            self::assertLessThan($install, $at, $dispatch . ' now runs after the TUI redirect — its stderr would be hidden');
        }
    }

    /**
     * Everything between the redirect and `(new Program(`, which must be the
     * `if ($tuiErrorLog !== null) { … }` statement and nothing else. Sliced
     * between those two anchors rather than brace-matched, so no brace walk
     * has to know PHP's interpolation openers.
     */
    private static function displayBlock(string $code): string
    {
        $install = strpos($code, self::INSTALL);
        $program = strpos($code, '(new Program(Bootstrap::app(');
        self::assertIsInt($install);
        self::assertIsInt($program);

        $block = trim(substr($code, $install + \strlen(self::INSTALL), $program - $install - \strlen(self::INSTALL)));
        self::assertStringStartsWith('if ($tuiErrorLog !== null) {', $block, 'the TUI display_errors block is gone from bin/sugarcrush');
        self::assertStringEndsWith('}', $block);

        return $block;
    }

    private static function binCode(): string
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/bin/sugarcrush');
        self::assertIsString($source);

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $code .= $token[1];
                }
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
