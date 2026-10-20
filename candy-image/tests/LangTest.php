<?php

/**
 * F-18: the candy-image i18n Lang wrapper — every cli.* key the front end
 * emits resolves to a real message (not the namespaced-fallback spelling),
 * and unknown keys degrade to the documented fallback.
 */

declare(strict_types=1);

namespace SugarCraft\CandyImage\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CandyImage\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        // Fresh T registry per test: no cross-contamination from a
        // previously-loaded 'image' namespace.
        \SugarCraft\Core\I18n\T::reset();
    }

    /** @return array<string, array{string}> */
    public static function cliKeys(): array
    {
        return [
            'usage' => ['cli.usage'],
            'no_autoload' => ['cli.no_autoload'],
            'bad_flag' => ['cli.bad_flag'],
            'bad_value' => ['cli.bad_value'],
            'bad_format' => ['cli.bad_format'],
            'chafa_refused' => ['cli.chafa_refused'],
            'symbols_refused' => ['cli.symbols_refused'],
            'passthrough_refused' => ['cli.passthrough_refused'],
            'too_many' => ['cli.too_many'],
            'no_frames' => ['cli.no_frames'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cliKeys')]
    public function testEveryCliKeyResolvesToARealMessage(string $key): void
    {
        $message = Lang::t($key);

        $this->assertNotSame('image.' . $key, $message, $key . ' must ship in lang/en.php');
        $this->assertStringContainsString('candy-image', $message);
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        $result = Lang::t('nonexistent.key');
        $this->assertSame('image.nonexistent.key', $result);
    }
}
