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
 * their diagnostics in a file. Before `Bootstrap::app()` — i.e. in the
 * statement immediately ahead of `new Program(` — because app() builds the
 * Chat that arms the notice sink, and the turn child forked after that must
 * inherit the redirected ini.
 */
final class BinSugarcrushTuiErrorLogTest extends TestCase
{
    private const INSTALL = 'TuiErrorLog::install(HomeDirectory::owned());';

    public function testTheRedirectIsInstalledExactlyOnceFromTheOwnedHome(): void
    {
        $code = self::binCode();

        self::assertSame(1, substr_count($code, 'TuiErrorLog::install('), 'expected exactly one install call');
        self::assertStringContainsString(self::INSTALL, $code, 'the install must take HomeDirectory::owned()');
    }

    public function testTheRedirectIsTheStatementImmediatelyBeforeProgramRun(): void
    {
        $code = self::binCode();
        $install = strpos($code, self::INSTALL);
        $program = strpos($code, '(new Program(Bootstrap::app(');

        self::assertIsInt($install);
        self::assertIsInt($program);
        self::assertGreaterThan($install, $program);
        self::assertSame(
            '',
            trim(substr($code, $install + \strlen(self::INSTALL), $program - $install - \strlen(self::INSTALL))),
            'something runs between the redirect and Program::run() — Bootstrap::app() must come after it',
        );
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
