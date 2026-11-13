<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Lang;

/**
 * bin/candy-top argument and terminal guards, run as a real child process
 * with stdin/stdout that are NOT terminals (a file and a pipe). `timeout`
 * bounds every run, so a regressed guard that starts the TUI fails the
 * test instead of hanging it.
 */
final class BinTest extends TestCase
{
    public function testRefusesToStartWithoutATty(): void
    {
        [$code, $out, $err] = self::invoke(['--fake']);
        $this->assertSame(1, $code);
        $this->assertSame('', $out, 'no frame bytes written into the pipe');
        $this->assertStringStartsWith('No tty detected!', $err);
    }

    public function testHelpNeedsNoTty(): void
    {
        [$code, $out] = self::invoke(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('--config <path>', $out);
        foreach (['cli.help.title', 'cli.help.opt.fake'] as $key) {
            $this->assertStringContainsString(Lang::t($key), $out, "{$key} comes from the lang table");
        }
        foreach (KeyTable::keyRows() as $row) {
            $this->assertMatchesRegularExpression('/^  ' . preg_quote($row->keyLabel(), '/') . ' +' . preg_quote($row->description(), '/') . '$/m', $out, 'KEYS is the KeyTable');
        }
    }

    public function testComposerPublishesTheBinary(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['bin/candy-top'], $manifest['bin'] ?? null);
        $this->assertTrue(is_executable(__DIR__ . '/../bin/candy-top'), 'bin/candy-top is executable');
    }

    /** @return iterable<string, array{list<string>}> */
    public static function missingConfigValues(): iterable
    {
        yield 'last argument' => [['--config']];
        yield 'followed by a flag' => [['--config', '--fake']];
        yield 'empty equals form' => [['--config=']];
    }

    /** @param list<string> $args */
    #[DataProvider('missingConfigValues')]
    public function testConfigWithoutAValueReportsTheMissingValue(array $args): void
    {
        [$code, , $err] = self::invoke($args);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('missing value for --config', $err);
        $this->assertStringNotContainsString('unknown argument', $err);
    }

    public function testUnknownArgument(): void
    {
        [$code, , $err] = self::invoke(['--bogus']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('unknown argument --bogus', $err);
    }

    /**
     * Every startup refusal names the program before it speaks (audit m34):
     * a line that reaches a shell scrollback has to be attributable, and
     * 'candy-top: ' is written once, in $report, never per call site.
     */
    public function testStartupRefusalsCarryTheProgramNamePrefix(): void
    {
        foreach ([['--config'], ['--bogus']] as $args) {
            [$code, , $err] = self::invoke($args);
            $this->assertSame(2, $code, implode(' ', $args) . ' is a usage error');
            $this->assertStringStartsWith('candy-top: ', $err, implode(' ', $args) . ' names the program');
        }
    }

    /**
     * M6: the pre-TUI streams have no Surface, so nothing strips control
     * characters between a planted value and the user's terminal. Config
     * warnings embed the offending VALUE verbatim (ConfigReader only refuses
     * '"', CR and LF — ESC and BEL ride through, so `graph_symbol = <ESC>]0;x<BEL>`
     * would set the window title from a copied-over btop.conf and outlive the
     * process), and paths come from $HOME/$XDG_*. Those sites sit BEHIND the
     * no-tty gate this file pins, so a child probe cannot reach them; the law
     * is pinned structurally instead. Strip the sanitizer, add a raw
     * fwrite(STDERR, or unwire a call site and this goes red.
     *
     * m27 rides here: ConfigFile::new throws when neither XDG_CONFIG_HOME nor
     * HOME resolves. Unguarded that is an uncaught fatal with a stack trace on
     * the same stream — the refusal has to be a report + exit 2 like a bad
     * argument.
     */
    public function testEveryStartupStderrWriteGoesThroughTheSanitizingReporter(): void
    {
        $lines = file(__DIR__ . '/../bin/candy-top', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $source = implode("\n", $lines);

        // One writer, sanitizing, with the program-name prefix built in.
        $this->assertStringContainsString(
            "@fwrite(STDERR, 'candy-top: ' . Sanitize::untrustedForDisplay(\$message) . \"\\n\");",
            $source,
            '$report is the single stderr choke',
        );

        // Raw writes outside the choke are exactly the two fixed literals that
        // carry no external text: the autoload probe (which runs before the
        // closure exists) and the no-tty refusal (pinned byte-first above).
        $carvedOut = ['cannot find composer autoload.php', "Lang::t('cli.no_tty')"];
        $inChoke = 0;
        foreach ($lines as $index => $line) {
            if (!str_contains($line, 'fwrite(STDERR')) {
                continue;
            }

            if (str_contains($line, 'Sanitize::untrustedForDisplay($message)')) {
                ++$inChoke;
                continue;
            }

            $known = false;
            foreach ($carvedOut as $marker) {
                if (str_contains($line, $marker)) {
                    $known = true;
                    break;
                }
            }

            $this->assertTrue($known, 'unattributed raw stderr write at bin/candy-top:' . ($index + 1));
        }
        $this->assertSame(1, $inChoke, 'the choke owns exactly one raw write');

        // The startup diagnostics route through it: two flag refusals, the
        // config-path refusal, every load warning, the box-layout reset
        // notice, the four exit/save-state error reports (2 in the crash
        // path, 2 after run()), the five launch-flag usage refusals (-p/-u/-t,
        // S-O06), the --themes-dir path refusal (S-O07), the non-UTF-8 locale
        // refusal (S-O04), the two tree-state prune notes (S-X02, one per exit
        // path) and the two after-load roster refusals (unknown theme, unknown
        // preset). Raised 9 -> 16 on 2026-11-13 with S-O06, 16 -> 17 the same
        // day with S-O07, 17 -> 18 with S-O04, 18 -> 20 with S-X02.
        $this->assertSame(20, substr_count($source, '$report('), 'no startup diagnostic bypasses $report');
        $this->assertStringContainsString('foreach ($loaded->warnings as $warning) {', $source);

        $at = strpos($source, '$file = ConfigFile::new($configPath);');
        $this->assertNotFalse($at, 'the config file is still built by ConfigFile::new');
        $window = substr($source, (int) $at, 240);
        $this->assertStringContainsString('catch (\RuntimeException $e) {', $window, 'a throwing ConfigFile::new is a refusal, not a fatal (m27)');
        $this->assertStringContainsString('$report($e->getMessage());', $window);
        $this->assertStringContainsString('exit(2);', $window);
    }

    /**
     * The box-layout reset used to be silent: the layout a user hand-picked
     * vanished on a host that could not show it, and needsRewrite then made the
     * loss permanent (m28). The notice has to be reported from the same decision
     * the reset takes, before the reset is applied.
     */
    public function testTheBoxLayoutResetIsReportedFromTheSameDecision(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../bin/candy-top');

        $at = strpos($source, '$boxReset = $config->shownBoxesSettlement($detected)');
        $this->assertNotFalse($at, 'startup consults the settlement for its reason');
        $window = substr($source, (int) $at, 260);
        $this->assertStringContainsString('$report($boxReset);', $window, 'the reason reaches the sanitizing choke');
        $this->assertStringContainsString('$config->withShownBoxesSettled($detected);', $window, 'and the reset follows the notice');
        $this->assertLessThan(
            (int) strpos($window, 'withShownBoxesSettled($detected)'),
            (int) strpos($window, '$report($boxReset);'),
            'notice first: the reset is what the next save persists',
        );
    }

    /**
     * S-O06: -p/-t/-u usage refusals come before the tty gate, like --config's,
     * so a child without a terminal can pin them.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function launchFlagUsageErrors(): iterable
    {
        yield 'preset last argument' => [['-p'], 'missing value for -p'];
        yield 'preset eaten by a flag' => [['--preset', '--fake'], 'missing value for --preset'];
        yield 'preset empty equals form' => [['--preset='], 'missing value for --preset'];
        yield 'preset not a digit' => [['-p=x'], 'bad value for -p: x'];
        yield 'preset two digits' => [['--preset', '12'], 'bad value for --preset: 12'];
        yield 'update last argument' => [['-u'], 'missing value for -u'];
        yield 'update not a number' => [['--update=fast'], 'bad value for --update: fast'];
        yield 'update negative' => [['-u=-5'], 'bad value for -u: -5'];
        yield 'theme eaten by a flag' => [['-t', '--fake'], 'missing value for -t'];
        yield 'theme empty equals form' => [['--theme='], 'missing value for --theme'];
        yield 'themes-dir last argument' => [['--themes-dir'], 'missing value for --themes-dir'];
        yield 'themes-dir eaten by a flag' => [['--themes-dir', '--fake'], 'missing value for --themes-dir'];
        yield 'themes-dir empty equals form' => [['--themes-dir='], 'missing value for --themes-dir'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('launchFlagUsageErrors')]
    public function testLaunchFlagUsageRefusals(array $args, string $needle): void
    {
        [$code, , $err] = self::invoke($args);
        $this->assertSame(2, $code, implode(' ', $args) . ' is a usage error');
        $this->assertStringStartsWith('candy-top: ', $err, 'the refusal names the program (m34)');
        $this->assertStringContainsString($needle, $err);
        $this->assertStringNotContainsString('No tty', $err, 'usage answers before the tty gate');
    }

    public function testHelpListsTheLaunchFlags(): void
    {
        [$code, $out] = self::invoke(['--help']);
        $this->assertSame(0, $code);
        foreach (['-p, --preset <N>', '-t, --theme <name>', '-u, --update <ms>', '[-p N] [-t theme] [-u ms]', '--themes-dir <path>', '[--utf-force]'] as $row) {
            $this->assertStringContainsString($row, $out, $row . ' is in the help');
        }
        foreach (['cli.help.opt.preset', 'cli.help.opt.theme', 'cli.help.opt.update', 'cli.help.opt.themes_dir', 'cli.help.opt.utf_force'] as $key) {
            $this->assertStringContainsString(Lang::t($key), $out, "{$key} comes from the lang table");
        }
    }

    /**
     * The unknown-theme and unknown-preset refusals sit behind the tty gate
     * (they need the loaded roster), so a child probe cannot reach them —
     * pinned structurally: each refusal reports through $report and exits 2,
     * and the three session legs ride on Config's override API.
     */
    public function testLaunchFlagStartupRefusalsAreWiredAfterLoad(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../bin/candy-top');

        foreach (['theme_unknown', 'preset_unknown'] as $key) {
            $this->assertMatchesRegularExpression(
                '/\$report\(Lang::t\(\'cli\.' . $key . '\'[^\n]*\n\s*exit\(2\);/',
                $source,
                "cli.{$key} reports and exits 2",
            );
        }
        $this->assertStringContainsString('$catalog->find($themeArg)', $source, 'the theme is refused before the silent palette fallback');
        $this->assertStringContainsString('->withSessionUpdateMs($updateMs)', $source);
        $this->assertStringContainsString("withSessionOverrides(['color_theme'", $source);
        $this->assertStringContainsString('->withSessionPreset($preset)', $source);
        // S-O07: --themes-dir reaches both the startup catalog and the runtime theme loader.
        $this->assertStringContainsString('ThemeRegistry::new($themesDir)', $source);
        $this->assertStringContainsString('themes: App::systemThemes($themesDir)', $source);
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env locale overrides merged over the inherited environment
     * @return array{int, string, string}
     */
    private static function invoke(array $args, array $env = []): array
    {
        $proc = proc_open(
            ['timeout', '20', PHP_BINARY, __DIR__ . '/../bin/candy-top', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env === [] ? null : [...getenv(), 'LC_ALL' => '', 'LC_CTYPE' => '', ...$env],
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    /**
     * S-O04: the UTF-8 locale gate answers before the tty gate, so a plain
     * child pins all three arms. glibc reports ANSI_X3.4-1968 for LANG=C;
     * a libc that cannot report a codeset at all takes the fail-open arm.
     */
    public function testLocaleGateRefusesNonUtf8AndHonoursTheHatch(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('nl_langinfo')) {
            $this->markTestSkipped('the gate is pinned where libc reports codesets (Linux/glibc)');
        }
        [$code, , $err] = self::invoke([], ['LANG' => 'C']);
        $this->assertSame(1, $code, 'LANG=C is refused');
        $this->assertStringStartsWith('candy-top: ', $err);
        $this->assertStringContainsString('ANSI_X3.4-1968', $err, 'the refusal names the codeset');
        $this->assertStringContainsString('--utf-force', $err, 'the refusal names the hatch');
        $this->assertStringNotContainsString('No tty', $err, 'the locale leg answers before the tty gate');

        [, , $err] = self::invoke([], ['LANG' => 'C.UTF-8']);
        $this->assertStringContainsString('No tty', $err, 'a UTF-8 locale walks past the gate');

        [, , $err] = self::invoke(['--utf-force'], ['LANG' => 'C']);
        $this->assertStringContainsString('No tty', $err, '--utf-force takes the hatch');
    }
}
