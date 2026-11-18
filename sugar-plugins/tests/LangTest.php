<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\Core\I18n\T;
use SugarCraft\Plugins\Lang;
use SugarCraft\Plugins\Tests\Support\FixtureLang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        T::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        T::reset();
    }

    public function testBaseIsAbstractAndExtendsCoreLang(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->isSubclassOf(BaseLang::class));
    }

    public function testSubclassSuppliesNamespaceAndDir(): void
    {
        $reflection = new \ReflectionClass(FixtureLang::class);
        $this->assertSame('fixture', $reflection->getConstant('NAMESPACE'));
        $this->assertFileExists($reflection->getConstant('DIR') . '/en.php');
    }

    public function testExactLocaleWinsOverBaseLanguage(): void
    {
        T::setLocale('fr-fr');
        $this->assertSame('Bonjour (fr-fr) Ada', FixtureLang::t('greeting', ['name' => 'Ada']));
    }

    public function testBaseLanguageUsedWhenExactLocaleMissing(): void
    {
        T::setLocale('fr-ca');
        $this->assertSame('Bonjour Ada', FixtureLang::t('greeting', ['name' => 'Ada']));
        $this->assertSame('base french', FixtureLang::t('base_only'));
    }

    public function testEnglishFallbackForKeyMissingInLocale(): void
    {
        T::setLocale('fr-ca');
        $this->assertSame('english only', FixtureLang::t('en_only'));
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        T::setLocale('de');
        $this->assertSame('Hello Ada', FixtureLang::t('greeting', ['name' => 'Ada']));
    }

    public function testDefaultLocaleIsEnglish(): void
    {
        $this->assertSame('Hello Ada', FixtureLang::t('greeting', ['name' => 'Ada']));
    }

    public function testMissingKeyReturnsNamespacedRawKey(): void
    {
        $this->assertSame('fixture.no.such.key', FixtureLang::t('no.such.key'));
    }

    public function testUnmatchedPlaceholderIsLeftLiteral(): void
    {
        $this->assertSame('Hello {name}', FixtureLang::t('greeting'));
    }

    public function testPluginNamespacesStayIsolated(): void
    {
        $other = new class () extends Lang {
            protected const NAMESPACE = 'other';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->assertSame('Hello Ada', FixtureLang::t('greeting', ['name' => 'Ada']));
        $this->assertSame('Other Ada', $other::t('greeting', ['name' => 'Ada']));
    }

    public function testEmptyNamespaceIsRefused(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = '';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty NAMESPACE');
        $plugin::t('greeting');
    }

    public function testDottedNamespaceIsRefused(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'dotted.ns';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->expectException(\InvalidArgumentException::class);
        $plugin::t('greeting');
    }

    public function testMissingDirIsRefusedInsteadOfEchoingRawKeys(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'nodir-plugin';
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty DIR');
        $plugin::t('greeting');
    }

    public function testMissingDirectoryIsRefusedInsteadOfEchoingRawKeys(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'missing-dir-plugin';
            protected const DIR = __DIR__ . '/fixtures/no-such-lang-dir';
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');
        $plugin::t('greeting');
    }

    public function testHostNamespaceCodeIsReserved(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'code';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("reserved for the host or a library");
        $plugin::t('greeting');
    }

    public function testCoreNamespaceIsReserved(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'core';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->expectException(\InvalidArgumentException::class);
        $plugin::t('greeting');
    }

    public function testLibraryNamespaceIsReserved(): void
    {
        $plugin = new class () extends Lang {
            protected const NAMESPACE = 'charts';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->expectException(\InvalidArgumentException::class);
        $plugin::t('greeting');
    }

    public function testNamespaceClaimedByAnotherDirectoryIsRefused(): void
    {
        $first = new class () extends Lang {
            protected const NAMESPACE = 'claim-clash';
            protected const DIR = __DIR__ . '/fixtures/lang';
        };
        $second = new class () extends Lang {
            protected const NAMESPACE = 'claim-clash';
            protected const DIR = __DIR__ . '/fixtures/lang-other';
        };

        $this->assertSame('Hello Ada', $first::t('greeting', ['name' => 'Ada']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already claimed by another plugin directory');
        $second::t('greeting');
    }

    public function testSameNamespaceFromTheSameDirectoryIsAccepted(): void
    {
        $again = new class () extends Lang {
            protected const NAMESPACE = 'fixture';
            protected const DIR = __DIR__ . '/Support/../fixtures/lang';
        };

        $this->assertSame('Hello Ada', $again::t('greeting', ['name' => 'Ada']));
    }
}
