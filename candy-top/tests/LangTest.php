<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Top\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    protected function tearDown(): void
    {
        T::reset();
    }

    public function testTranslatesKnownKey(): void
    {
        $this->assertSame('Options', Lang::t('menu.main.options'));
    }

    public function testInterpolatesParams(): void
    {
        $this->assertSame(
            'Got an invalid bool value for config name: vim_keys',
            Lang::t('config.warn.invalid_bool', ['name' => 'vim_keys']),
        );
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        $this->assertSame('top.no.such.key', Lang::t('no.such.key'));
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        T::setLocale('xx-yy');
        $this->assertSame('Help', Lang::t('menu.main.help'));
    }

    public function testEnglishFileIsAFlatStringMap(): void
    {
        /** @var mixed $strings */
        $strings = require __DIR__ . '/../lang/en.php';
        $this->assertIsArray($strings);
        foreach ($strings as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value, $key);
        }
    }
}
