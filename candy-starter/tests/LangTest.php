<?php

declare(strict_types=1);

namespace App\Tests;

use App\Lang;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

final class LangTest extends TestCase
{
    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    public function testEnglishInterpolatesTheCount(): void
    {
        T::setLocale('en');
        $this->assertSame('count: 7', Lang::t('frame.count', ['n' => 7]));
    }

    public function testHintKeepsTheThreeExitMechanisms(): void
    {
        T::setLocale('en');
        $this->assertSame('↑ ↓ to change · q / Esc / Ctrl+C quit', Lang::t('frame.hint'));
    }

    public function testAnotherLocaleSwitchesTheFrameText(): void
    {
        T::setLocale('de');
        $this->assertSame('Anzahl: 3', Lang::t('frame.count', ['n' => 3]));
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        T::setLocale('xx');
        $this->assertSame('count: 1', Lang::t('frame.count', ['n' => 1]));
    }
}
