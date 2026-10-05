<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Crush\Lang;

/**
 * The launch locale (audit 15b-14): `bin/sugarcrush` selects it from
 * LC_ALL / LC_MESSAGES / LANG before anything prints, so a shipped
 * `lang/<locale>.php` is what the user reads, and English answers for every
 * key it lacks.
 */
final class LangLaunchLocaleTest extends TestCase
{
    private const VARIABLES = ['LC_ALL', 'LC_MESSAGES', 'LANG'];

    /** @var array<string, array{0: mixed, 1: string|false}> */
    private array $saved = [];

    private string $localeBefore = 'en';

    private ?string $catalogue = null;

    protected function setUp(): void
    {
        $this->localeBefore = T::locale();
        foreach (self::VARIABLES as $variable) {
            $this->saved[$variable] = [$_SERVER[$variable] ?? null, \getenv($variable)];
            unset($_SERVER[$variable]);
            \putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $variable => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$variable]);
            } else {
                $_SERVER[$variable] = $server;
            }
            \putenv($env === false ? $variable : $variable . '=' . $env);
        }
        if ($this->catalogue !== null) {
            T::overrideNamespace('crush', \dirname(__DIR__) . '/lang');
            foreach (\glob($this->catalogue . '/*') ?: [] as $file) {
                \unlink($file);
            }
            \rmdir($this->catalogue);
        }
        T::setLocale($this->localeBefore);
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('environments')]
    public function testTheDecidingVariableSelectsTheLocale(array $environment, string $expected): void
    {
        foreach ($environment as $variable => $value) {
            $_SERVER[$variable] = $value;
        }

        self::assertSame($expected, Lang::useLaunchLocale());
        self::assertSame($expected, T::locale());
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function environments(): iterable
    {
        yield 'nothing set' => [[], 'en'];
        yield 'LANG alone, encoding dropped' => [['LANG' => 'de_DE.UTF-8'], 'de-de'];
        yield 'LC_MESSAGES over LANG' => [['LC_MESSAGES' => 'fr_FR', 'LANG' => 'de_DE.UTF-8'], 'fr-fr'];
        yield 'LC_ALL over both' => [['LC_ALL' => 'pt_BR.UTF-8@x', 'LC_MESSAGES' => 'fr_FR', 'LANG' => 'de_DE'], 'pt-br'];
        yield 'an empty variable is unset' => [['LC_ALL' => '', 'LANG' => 'es_ES'], 'es-es'];
        yield 'LC_ALL=C decides, it is not skipped' => [['LC_ALL' => 'C', 'LANG' => 'de_DE'], 'en'];
        yield 'C.UTF-8' => [['LANG' => 'C.UTF-8'], 'en'];
        yield 'POSIX' => [['LANG' => 'POSIX'], 'en'];
        yield 'not a language tag' => [['LANG' => '/tmp/x'], 'en'];
    }

    public function testTheProcessEnvironmentIsReadWhenServerLacksIt(): void
    {
        \putenv('LANG=it_IT.UTF-8');

        self::assertSame('it-it', Lang::useLaunchLocale());
    }

    /**
     * End to end through the catalogue: the selected locale's file answers
     * the keys it carries, its base language is tried next, and English
     * answers the rest.
     */
    public function testTheSelectedCatalogueIsWhatLangTranslatesFrom(): void
    {
        $dir = \sys_get_temp_dir() . '/crush-lang-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);
        $this->catalogue = $dir;
        \file_put_contents($dir . '/en.php', '<?php return require ' . \var_export(\dirname(__DIR__) . '/lang/en.php', true) . ';');
        \file_put_contents($dir . '/de.php', '<?php return ' . \var_export(['cli.serve.none' => '(keine)'], true) . ';');
        Lang::t('cli.serve.none');
        T::overrideNamespace('crush', $dir);

        $_SERVER['LANG'] = 'de_AT.UTF-8';
        self::assertSame('de-at', Lang::useLaunchLocale());

        self::assertSame('(keine)', Lang::t('cli.serve.none'), 'de-at falls back to de');
        self::assertSame('Run this command?', Lang::t('tui.permission.run_command'), 'a key de lacks answers in English');
        self::assertSame('Run this command?', Lang::inLocale('en', static fn (): string => Lang::t('tui.permission.run_command')));
        self::assertSame('(none)', Lang::inLocale('en', static fn (): string => Lang::t('cli.serve.none')), 'generated text pins English');
        self::assertSame('de-at', T::locale(), 'inLocale restores the launch locale');
    }

    /**
     * The binary selects the locale before it parses argv or writes a byte:
     * the parser's own refusals are translated text.
     */
    public function testTheBinarySelectsTheLocaleBeforeAnythingElseRuns(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/sugarcrush');
        $afterGuard = \substr($bin, (int) \strpos($bin, "\nuse SugarCraft\\"));

        $select = \strpos($afterGuard, 'Lang::useLaunchLocale();');
        self::assertNotFalse($select, 'bin/sugarcrush never selects a locale');
        foreach (['ArgvParser::parse(', 'fwrite(', 'Bootstrap::', 'Help::'] as $later) {
            $at = \strpos($afterGuard, $later);
            self::assertNotFalse($at, "{$later} moved out of bin/sugarcrush");
            self::assertLessThan($at, $select, "the locale is selected after {$later}");
        }
    }

    /**
     * And it runs: a locale with no catalogue of its own prints the English
     * help, byte for byte.
     */
    public function testALocaleWithNoCatalogueStillPrintsTheEnglishHelp(): void
    {
        $bin = \dirname(__DIR__) . '/bin/sugarcrush';
        $env = ['LC_ALL' => 'xx_YY.UTF-8', 'PATH' => (string) \getenv('PATH'), 'HOME' => \sys_get_temp_dir()];
        $process = \proc_open([\PHP_BINARY, $bin, '--help'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        self::assertSame(0, \proc_close($process));
        self::assertSame(Lang::inLocale('en', static fn (): string => Lang::t('cli.help.screen')), $stdout);
    }
}
