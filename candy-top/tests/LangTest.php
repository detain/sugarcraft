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
        // 'menu.options.category.proc' is reached through the dynamic
        // 'menu.options.category.' . $category lookup in OptionsMenu, so it is a
        // live key: a translation test must not lean on a dead one.
        $this->assertSame('proc', Lang::t('menu.options.category.proc'));
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
        $this->assertSame('Ok', Lang::t('menu.msgbox.ok'));
    }

    public function testTheRetiredMenuKeysStayRetired(): void
    {
        // btop's menu titles and the suspend help row have no consumer here (the
        // overlays read the overlay.* keys, and candy-top never implements
        // ctrl+z). They were carried for two eras as dead copy — re-adding one
        // instead of the live key must cost a test.
        $strings = require __DIR__ . '/../lang/en.php';
        foreach ([
            'menu.main.options',
            'menu.main.help',
            'menu.main.quit',
            'menu.options.title',
            'menu.options.page',
            'menu.help.title',
            'menu.signal.title',
            'menu.signal.prompt',
            'menu.signal.enter',
            'menu.renice.title',
            'menu.renice.prompt',
            'menu.msgbox.cancel',
            'menu.size_error',
            'menu.size_needed',
        ] as $dead) { // help.suspend rejoined the live roster with S-O05 (2026-11-13)
            $this->assertArrayNotHasKey($dead, $strings, "{$dead} has no consumer: it belongs retired");
        }
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
