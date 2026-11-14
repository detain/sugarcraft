<?php

/**
 * The candy-image i18n Lang wrapper — every cli.* key the front end
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
        // Fresh T registry per test — hygiene only. The load-bearing paper
        // over it used to be (both apps baked 'image' and first-wins made
        // file order decide the outcome) died with the namespace rename;
        // the both-packages test below proves the bake is now collision-free.
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
            'bad_symbols' => ['cli.bad_symbols'],
            'passthrough_refused' => ['cli.passthrough_refused'],
            'too_many' => ['cli.too_many'],
            'no_frames' => ['cli.no_frames'],
            'animate_dials_refused' => ['cli.animate_dials_refused'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cliKeys')]
    public function testEveryCliKeyResolvesToARealMessage(string $key): void
    {
        $message = Lang::t($key);

        $this->assertNotSame('candy-image.' . $key, $message, $key . ' must ship in lang/en.php');
        $this->assertStringContainsString('candy-image', $message);
    }

    /**
     * The collision candy-image-003 reported: T::register() is first-wins, so
     * baking the sibling's 'image' namespace meant whichever package
     * registered second degraded every string to `image.<key>` literals in
     * any process resolving both (the advertised library-embed / $play seam).
     * No T::reset() between the two facades — this is that shared process.
     */
    public function testBothPackagesResolveTheirOwnStringsInOneProcess(): void
    {
        $ours = Lang::t('cli.usage');
        $this->assertStringContainsString('candy-image', $ours, 'own namespace must speak even after the sibling registers');

        $theirs = \SugarCraft\Image\Lang::t('image_source.file_not_found');
        $this->assertStringContainsString('File not found', $theirs, 'the sibling must not degrade to raw keys');
        $this->assertNotSame('image.image_source.file_not_found', $theirs);

        $again = Lang::t('cli.usage');
        $this->assertSame($ours, $again, 'registration order must not shift our lookups');
    }

    public function testUnknownKeyFallsBackToNamespacedKey(): void
    {
        $result = Lang::t('nonexistent.key');
        $this->assertSame('candy-image.nonexistent.key', $result);
    }
}
