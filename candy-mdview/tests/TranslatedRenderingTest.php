<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Width;
use SugarCraft\MdView\Lang;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\RenderCommand;

/**
 * KM6 rider (copywriter m7): with the locale finally adopted from the
 * environment, translated strings actually reach users — so they must be
 * proven to render. Every locale message flows through the real pager box
 * pipeline at a cramped width without tearing rows, carrying no escape
 * bytes, and differing from the English text it replaces.
 */
final class TranslatedRenderingTest extends TestCase
{
    private const BOX_WIDTH = 20;

    private string $savedLocale = 'en';

    protected function setUp(): void
    {
        $this->savedLocale = T::locale();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->savedLocale);
    }

    /**
     * @return array<string, array{0: string}> */
    public static function localeProvider(): array
    {
        return [
            'german'   => ['de'],
            'japanese' => ['ja'],
            'korean'   => ['ko'],
            'arabic'   => ['ar'],
            'russian'  => ['ru'],
            'chinese'  => ['zh-cn'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function stdinLabelProvider(): array
    {
        return [
            // [locale, translated stdin label, English island to exclude]
            'german'  => ['de', 'Standardeingabe', 'standard input'],
            'russian' => ['ru', 'стандартный ввод', 'standard input'],
        ];
    }

    /**
     * Finding 021: the binary/stdin refusals used to embed the English
     * literal 'standard input' into every locale's message — a translated
     * sentence carrying an untranslated island. The label now rides the
     * catalogue, so a localized refusal names the stream in its own tongue.
     */
    /** @dataProvider stdinLabelProvider */
    public function testStdinRefusalNamesTheStreamInLocale(string $locale, string $label, string $english): void
    {
        T::setLocale($locale);

        $stream = fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "\x00\x01\x02binary");
        fseek($stream, 0);

        $method = new \ReflectionMethod(RenderCommand::class, 'readDocument');
        $method->setAccessible(true);
        try {
            $method->invoke(null, '', $stream);
            $this->fail('binary stdin must refuse');
        } catch (\InvalidArgumentException $refusal) {
            $message = $refusal->getMessage();
        } finally {
            fclose($stream);
        }

        $this->assertStringContainsString($label, $message);
        $this->assertStringNotContainsString($english, $message);
    }

    /** @dataProvider localeProvider */
    public function testTranslatedMessagesDifferFromEnglishAndCarryNoEscapes(string $locale): void
    {
        T::setLocale($locale);

        $message = Lang::t('render.width_invalid', ['value' => 'abc']);
        T::setLocale('en');
        $english = Lang::t('render.width_invalid', ['value' => 'abc']);

        $this->assertNotSame(
            $english,
            $message,
            'locale ' . $locale . ' returned the English text — setLocale is not reaching Lang'
        );
        $this->assertStringNotContainsString("\x1b", $message);
        $this->assertStringContainsString('abc', $message, 'placeholders must survive translation');
    }

    /** @dataProvider localeProvider */
    public function testTranslatedStringsFitABoxWithoutTearingIt(string $locale): void
    {
        T::setLocale($locale);
        $message = Lang::t('render.file_missing', ['path' => '/tmp/example.md']);

        // Ride the real pager pipeline: markdown-rendered content including
        // the translated line, in a 20-cell box. Width::of counts cells of
        // what remains after SGR, so this is the same measurement the
        // column-budget pin uses.
        $document = "# candy-mdview\n\n" . $message . "\n";
        $model    = MdViewModel::fromContent($document, self::BOX_WIDTH, 8);

        foreach (explode("\n", $model->view()) as $row) {
            $this->assertLessThanOrEqual(
                self::BOX_WIDTH,
                Width::of($row),
                'locale ' . $locale . ' row overflows the box: ' . var_export($row, true)
            );
        }
    }
}
