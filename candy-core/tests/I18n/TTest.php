<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\I18n;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Lang;

final class TTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        T::reset();
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-i18n-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        T::reset();
        // Best-effort cleanup; not load-bearing.
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testReturnsRawKeyWhenNoNamespaceRegistered(): void
    {
        $this->assertSame('foo.bar', T::t('foo.bar'));
    }

    public function testReturnsKeyAsIsWhenNoDot(): void
    {
        $this->assertSame('plainkey', T::t('plainkey'));
    }

    public function testTranslatesViaRegisteredNamespace(): void
    {
        $this->writeLang('en', ['greeting' => 'Hello']);
        T::register('demo', $this->tmpDir);

        $this->assertSame('Hello', T::t('demo.greeting'));
    }

    public function testFallsBackToEnglishWhenLocaleFileMissing(): void
    {
        $this->writeLang('en', ['greeting' => 'Hello']);
        T::register('demo', $this->tmpDir);
        T::setLocale('fr');

        $this->assertSame('Hello', T::t('demo.greeting'));
    }

    public function testRegionalLocaleFallsBackToBaseLanguage(): void
    {
        // fr.php covers fr-fr, fr-ca, fr-be, … unless a regional file exists.
        $this->writeLang('en', ['greeting' => 'Hello']);
        $this->writeLang('fr', ['greeting' => 'Bonjour']);
        T::register('demo', $this->tmpDir);
        T::setLocale('fr-fr');

        $this->assertSame('Bonjour', T::t('demo.greeting'));
    }

    public function testRegionalFilePreemptsBaseLanguage(): void
    {
        // pt-br.php (Brazilian) takes precedence over pt.php (European) when
        // the active locale is pt-br.
        $this->writeLang('en', ['noun.you' => 'you']);
        $this->writeLang('pt', ['noun.you' => 'tu']);
        $this->writeLang('pt-br', ['noun.you' => 'você']);
        T::register('demo', $this->tmpDir);
        T::setLocale('pt-br');

        $this->assertSame('você', T::t('demo.noun.you'));
    }

    public function testRegionalFallsThroughBaseLanguageThenEnglish(): void
    {
        // Locale 'fr-fr' with no fr.php and no fr-fr.php → 'en' fallback.
        $this->writeLang('en', ['greeting' => 'Hello']);
        T::register('demo', $this->tmpDir);
        T::setLocale('fr-fr');

        $this->assertSame('Hello', T::t('demo.greeting'));
    }

    public function testFallsBackToKeyWhenAllLookupsMiss(): void
    {
        $this->writeLang('en', ['present' => 'yes']);
        T::register('demo', $this->tmpDir);

        $this->assertSame('demo.absent', T::t('demo.absent'));
    }

    public function testInterpolatesPlaceholders(): void
    {
        $this->writeLang('en', ['hello' => 'Hello, {name}!']);
        T::register('demo', $this->tmpDir);

        $this->assertSame('Hello, world!', T::t('demo.hello', ['name' => 'world']));
    }

    public function testLeavesUnmatchedPlaceholdersIntact(): void
    {
        $this->writeLang('en', ['hello' => 'Hello, {name}!']);
        T::register('demo', $this->tmpDir);

        $this->assertSame('Hello, {name}!', T::t('demo.hello'));
    }

    public function testCoercesNonStringPlaceholders(): void
    {
        $this->writeLang('en', ['n' => 'count={count}']);
        T::register('demo', $this->tmpDir);

        $this->assertSame('count=42', T::t('demo.n', ['count' => 42]));
    }

    public function testRegisterIsIdempotent(): void
    {
        $this->writeLang('en', ['x' => 'first']);
        T::register('demo', $this->tmpDir);

        // Second registration with a different dir is ignored.
        $other = sys_get_temp_dir() . '/sugarcraft-i18n-other-' . uniqid('', true);
        mkdir($other, 0o755, true);
        file_put_contents($other . '/en.php', "<?php return ['x' => 'second'];");
        T::register('demo', $other);

        $this->assertSame('first', T::t('demo.x'));

        @unlink($other . '/en.php');
        @rmdir($other);
    }

    public function testOverrideNamespaceReplacesDirAndClearsCache(): void
    {
        $this->writeLang('en', ['x' => 'first']);
        T::register('demo', $this->tmpDir);
        $this->assertSame('first', T::t('demo.x'));

        $other = sys_get_temp_dir() . '/sugarcraft-i18n-override-' . uniqid('', true);
        mkdir($other, 0o755, true);
        file_put_contents($other . '/en.php', "<?php return ['x' => 'second'];");
        T::overrideNamespace('demo', $other);

        $this->assertSame('second', T::t('demo.x'));

        @unlink($other . '/en.php');
        @rmdir($other);
    }

    public function testRejectsNamespaceWithDot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        T::register('a.b', $this->tmpDir);
    }

    public function testSetLocaleNormalizesEncodingAndCase(): void
    {
        T::setLocale('fr_FR.UTF-8');
        $this->assertSame('fr-fr', T::locale());
    }

    public function testDetectFallsBackToEnglish(): void
    {
        // Simulate an environment with no locale set.
        $orig = [];
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $var) {
            $orig[$var] = $_SERVER[$var] ?? null;
            unset($_SERVER[$var]);
            putenv($var);
        }
        try {
            $this->assertSame('en', T::detect());
        } finally {
            foreach ($orig as $var => $val) {
                if ($val !== null) {
                    $_SERVER[$var] = $val;
                }
            }
        }
    }

    public function testDetectIgnoresPosixSentinels(): void
    {
        // Block real-environment fallthrough by overriding all three vars
        // in $_SERVER (which T::detect() consults before getenv()).
        $orig = [];
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $var) {
            $orig[$var] = $_SERVER[$var] ?? null;
            $_SERVER[$var] = 'C';
        }
        try {
            $this->assertSame('en', T::detect());
        } finally {
            foreach ($orig as $var => $val) {
                if ($val === null) {
                    unset($_SERVER[$var]);
                } else {
                    $_SERVER[$var] = $val;
                }
            }
        }
    }

    public function testCharsetExtractsUtf8Suffix(): void
    {
        $this->withLocaleEnv(['LC_ALL' => 'fr_FR.UTF-8'], function (): void {
            $this->assertSame('UTF-8', T::charset());
        });
    }

    public function testCharsetReturnsNullWhenNoEncodingSuffix(): void
    {
        $this->withLocaleEnv(['LC_ALL' => 'en_US'], function (): void {
            $this->assertNull(T::charset());
        });
    }

    public function testCharsetIgnoresPosixSentinelsAcrossWholeChain(): void
    {
        $this->withLocaleEnv(['LC_ALL' => 'C', 'LC_MESSAGES' => 'POSIX', 'LANG' => ''], function (): void {
            $this->assertNull(T::charset());
        });
    }

    public function testCharsetFallsThroughChainPastInvalidSentinel(): void
    {
        // LC_ALL='C' fails detect()'s screen, so LC_MESSAGES decides.
        $this->withLocaleEnv(['LC_ALL' => 'C', 'LC_MESSAGES' => 'en_GB.UTF-8'], function (): void {
            $this->assertSame('UTF-8', T::charset());
        });
    }

    public function testCharsetPrecedenceLcAllBeatsLcMessagesBeatsLang(): void
    {
        $this->withLocaleEnv(
            ['LC_ALL' => 'fr_FR.UTF-8', 'LC_MESSAGES' => 'es_ES.UTF-8', 'LANG' => 'de_DE.ISO-8859-1'],
            function (): void {
                $this->assertSame('UTF-8', T::charset());
            }
        );
        $this->withLocaleEnv(
            ['LC_ALL' => null, 'LC_MESSAGES' => 'es_ES.UTF-8', 'LANG' => 'de_DE.ISO-8859-1'],
            function (): void {
                $this->assertSame('UTF-8', T::charset());
            }
        );
    }

    public function testCharsetUppercasesAndPassesThroughEncodingNames(): void
    {
        $this->withLocaleEnv(['LC_ALL' => 'en_US.utf-8'], function (): void {
            $this->assertSame('UTF-8', T::charset());
        });
        $this->withLocaleEnv(['LC_ALL' => 'en_US.iso-8859-1'], function (): void {
            $this->assertSame('ISO-8859-1', T::charset());
        });
    }

    public function testCharsetFallsBackToGetenvWhenServerVarsAbsent(): void
    {
        // $_SERVER misses must defer to getenv() exactly like detect().
        $this->withLocaleEnv(
            ['LC_ALL' => null, 'LC_MESSAGES' => null, 'LANG' => null],
            function (): void {
                $this->assertSame('UTF-8', T::charset());
            },
            ['LANG' => 'pt_BR.UTF-8']
        );
    }

    public function testCoreLangHelperWorksOutOfTheBox(): void
    {
        // Lang::t() handles its own registration of the candy-core lang dir.
        $msg = Lang::t('color.invalid_hex', ['hex' => '#zz']);
        $this->assertSame('invalid hex color: #zz', $msg);
    }

    /**
     * Pin the LC_ALL/LC_MESSAGES/LANG environment for the probe, then
     * restore $_SERVER and getenv() to their exact prior state.
     *
     * @param array<string, string|null> $server $_SERVER values; null unsets
     * @param callable                   $probe  assertion closure to run
     * @param array<string, string|null> $env    getenv() values; null deletes
     */
    private function withLocaleEnv(array $server, callable $probe, array $env = []): void
    {
        $vars = ['LC_ALL', 'LC_MESSAGES', 'LANG'];
        $origServer = [];
        $origEnv = [];
        foreach ($vars as $var) {
            $origServer[$var] = $_SERVER[$var] ?? null;
            $origEnv[$var] = getenv($var);
        }
        try {
            foreach ($vars as $var) {
                if (array_key_exists($var, $server)) {
                    if ($server[$var] === null) {
                        unset($_SERVER[$var]);
                    } else {
                        $_SERVER[$var] = $server[$var];
                    }
                }
                if (array_key_exists($var, $env)) {
                    if ($env[$var] === null) {
                        putenv($var);
                    } else {
                        putenv($var . '=' . $env[$var]);
                    }
                }
            }
            $probe();
        } finally {
            foreach ($vars as $var) {
                if ($origServer[$var] === null) {
                    unset($_SERVER[$var]);
                } else {
                    $_SERVER[$var] = $origServer[$var];
                }
                if ($origEnv[$var] === false) {
                    putenv($var);
                } else {
                    putenv($var . '=' . $origEnv[$var]);
                }
            }
        }
    }

    /**
     * @param array<string, string> $rows
     */
    private function writeLang(string $locale, array $rows): void
    {
        $body = "<?php return " . var_export($rows, true) . ";";
        file_put_contents($this->tmpDir . '/' . $locale . '.php', $body);
    }
}
