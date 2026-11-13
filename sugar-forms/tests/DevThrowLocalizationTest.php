<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Forms\KeyMap;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\Validator\Pattern;

/**
 * SF-56 (sugar-forms-089/-090): the four dev-facing throw stragglers joined
 * the catalogue so the lib's boundary-localization policy is coherent —
 * KeyMap::labelsFor(), TextInput::withRestrict()/withMask() and the Pattern
 * ctor now ride Lang::t. Each leg pins BOTH halves: the default-locale bytes
 * are identical to the pre-catalogue literals (zero-regression), and a
 * switched locale re-colors the message (proof the site consults the
 * catalogue rather than interpolating raw — the idiom established by the
 * SF-34 locale-switch probes).
 */
final class DevThrowLocalizationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> key => [legacy literal, French text] */
    private const SITES = [
        'keys.unknown_binding' => [
            "Unknown key binding 'nope' — expected next, prev, submit or abort.",
            'Bad binding {binding}',
        ],
        'input.bad_restrict' => [
            'Invalid restrict pattern: (',
            'Mauvais restrict {pattern}',
        ],
        'input.bad_mask' => [
            'Invalid mask pattern: (',
            'Mauvais mask {pattern}',
        ],
        'validator.bad_regex' => [
            'Invalid regex pattern: (',
            'Mauvais regex {pattern}',
        ],
    ];

    public function testDefaultLocaleBytesAreIdenticalToThePreCatalogueLiterals(): void
    {
        foreach (self::SITES as $key => [$legacy]) {
            $this->assertSame($legacy, $this->throwMessage($key), $key . ': en default must not drift');
        }
    }

    public function testEverySiteResolvesThroughTheCatalogueUnderASwitchedLocale(): void
    {
        $tmp     = sys_get_temp_dir() . '/sf56-lang-' . uniqid();
        $dir     = $tmp . '/forms-lang';
        @mkdir($dir, 0777, true);
        $flat = [];
        foreach (self::SITES as $key => [, $french]) {
            $flat[$key] = $french;
        }
        file_put_contents($dir . '/fr.php', "<?php\nreturn " . var_export($flat, true) . ";\n");

        $realDir   = (new \ReflectionClass(Lang::class))->getConstant('DIR');
        $oldLocale = T::locale();
        T::overrideNamespace('forms', $dir);
        T::setLocale('fr');
        try {
            foreach (self::SITES as $key => [$legacy, $french]) {
                $expected = str_replace(
                    ['{binding}', '{pattern}'],
                    ['nope', '('],
                    $french,
                );
                $this->assertSame($expected, $this->throwMessage($key), $key . ': locale switch re-colors the throw');
            }
        } finally {
            T::setLocale($oldLocale);
            T::overrideNamespace('forms', $realDir);
            @unlink($dir . '/fr.php');
            @rmdir($dir);
            @rmdir($tmp);
        }
    }

    /** Raise the boundary throw for a catalogue key and return its message. */
    private function throwMessage(string $key): string
    {
        try {
            match ($key) {
                'keys.unknown_binding' => KeyMap::new()->labelsFor('nope'),
                'input.bad_restrict' => TextInput::new()->withRestrict('('),
                'input.bad_mask'     => TextInput::new()->withMask('('),
                'validator.bad_regex' => new Pattern('('),
                default               => $this->fail('unknown site ' . $key),
            };
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $this->fail($key . ': the boundary did not throw');
    }
}
