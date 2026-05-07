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

    public function testCoreLangHelperWorksOutOfTheBox(): void
    {
        // Lang::t() handles its own registration of the candy-core lang dir.
        $msg = Lang::t('color.invalid_hex', ['hex' => '#zz']);
        $this->assertSame('invalid hex color: #zz', $msg);
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
