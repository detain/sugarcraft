<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testNamespaceIsCodeShot(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('NAMESPACE');
        $this->assertSame('codeshot', $constant);
    }

    public function testDirPointsToLangDirectory(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $this->assertSame(realpath(__DIR__ . '/../lang'), realpath($constant));
    }

    public function testDirExists(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $this->assertDirectoryExists($constant);
    }

    public function testEnLocaleExists(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $enFile = $constant . '/en.php';
        $this->assertFileExists($enFile);
    }

    public function testLangExtendsBaseLang(): void
    {
        $lang = new Lang();
        $this->assertInstanceOf(\SugarCraft\Core\I18n\Lang::class, $lang);
    }

    public function testLangClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testRealKeyResolvesToItsEnglishCopy(): void
    {
        // candy-codeshot-062 (cs-31) vacuous cure: the old leg keyed on
        // `app.title`, which en.php does not carry — the raw-key fallback
        // always answers with a string, so the is_string pin could never
        // fail. A key the library actually owns resolves to its copy.
        $lang = new Lang();
        $usage = $lang->t('cli.usage');

        $this->assertStringStartsWith('candy-codeshot - render code', $usage);
        $this->assertNotSame('cli.usage', $usage);
    }

    public function testTranslationLookupFallsBackToRawKeyWhenNotFound(): void
    {
        $lang = new Lang();
        $result = $lang->t('nonexistent.translation.key');

        $this->assertSame('codeshot.nonexistent.translation.key', $result);
    }

    public function testInterpolationFillsThePlaceholders(): void
    {
        // -062: placeholders on a REAL key with a real interpolated value;
        // the old app.title leg interpolated the raw-key fallback and pinned
        // nothing.
        $lang = new Lang();
        $message = $lang->t('cli.missing_value', ['option' => '--theme']);

        $this->assertSame('candy-codeshot: --theme requires a value', $message);
        $this->assertStringNotContainsString('{option}', $message);
    }
}
