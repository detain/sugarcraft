<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;
use SugarCraft\MdView\RenderCommand;

/**
 * Byte pins for every theme name RenderCommand::pickTheme answers, and the
 * catalog round trip for the names that now resolve through
 * sugar-markdown's Theme::catalogByName(). The goldens under
 * tests/fixtures/themes were rendered before the lookup moved, so a
 * catalog that drifts from the old per-name factories fails here.
 */
final class ThemeCatalogRenderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/themes';

    /** @return iterable<string, array{string}> */
    public static function rosterNames(): iterable
    {
        foreach (['ansi', 'ascii', 'plain', 'no', 'dark', 'light', 'notty', 'auto-no',
                  'dracula', 'tokyo-night', 'tokyonight', 'pink', 'solarized', 'monokai', 'github'] as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{string, string}> spelling => canonical golden */
    public static function spellingVariants(): iterable
    {
        yield 'upper-case ANSI'      => ['ANSI', 'ansi'];
        yield 'underscore tokyo'     => ['tokyo_night', 'tokyo-night'];
        yield 'mixed-case Dark'      => ['Dark', 'dark'];
        yield 'mixed-case TokyoNight' => ['TokyoNight', 'tokyonight'];
        yield 'underscore auto_no'   => ['auto_no', 'notty'];
        yield 'upper-case NO'        => ['NO', 'plain'];
    }

    /** @return iterable<string, array{string, string}> roster name => catalog slug */
    public static function catalogResolvedNames(): iterable
    {
        yield 'ansi'        => ['ansi', 'ansi'];
        yield 'ascii'       => ['ascii', 'ascii'];
        yield 'plain'       => ['plain', 'plain'];
        yield 'dark'        => ['dark', 'dark'];
        yield 'light'       => ['light', 'light'];
        yield 'notty'       => ['notty', 'notty'];
        yield 'dracula'     => ['dracula', 'dracula'];
        yield 'tokyo-night' => ['tokyo-night', 'tokyo-night'];
        yield 'tokyonight'  => ['tokyonight', 'tokyo-night'];
        yield 'pink'        => ['pink', 'pink'];
        yield 'solarized'   => ['solarized', 'solarized'];
        yield 'monokai'     => ['monokai', 'monokai'];
        yield 'github'      => ['github', 'github'];
    }

    #[DataProvider('rosterNames')]
    public function testEveryRosterNameRendersItsGoldenBytes(string $name): void
    {
        $actual = Renderer::renderMarkdown($this->sample(), RenderCommand::pickTheme($name));

        $this->assertSame($this->golden($name), $actual, "theme '{$name}' drifted from its golden");
    }

    #[DataProvider('spellingVariants')]
    public function testSpellingVariantsRenderLikeTheirCanonicalName(string $spelling, string $canonical): void
    {
        $this->assertSame(
            $this->golden($canonical),
            Renderer::renderMarkdown($this->sample(), RenderCommand::pickTheme($spelling)),
            "spelling '{$spelling}' must answer as '{$canonical}'",
        );
    }

    #[DataProvider('catalogResolvedNames')]
    public function testCatalogRoundTripMatchesPickTheme(string $name, string $slug): void
    {
        $fromCatalog = Theme::catalogByName($name);

        $this->assertNotNull($fromCatalog, "catalog must carry '{$name}'");
        $this->assertSame(Theme::catalog()[$slug], $fromCatalog, "'{$name}' must resolve to the '{$slug}' catalog entry");
        $this->assertEquals($fromCatalog, RenderCommand::pickTheme($name));
    }

    /**
     * A built-in name must not read the bundled JSON files: sugar-markdown's
     * catalog() loads all three eagerly, so a vendor copy without themes/ would
     * otherwise refuse `--theme ansi` with theme.read_failed. The catalog cache
     * is reset, the stock roster is resolved, and the cache must stay empty.
     */
    public function testStockNamesNeverLoadTheBundledThemeFiles(): void
    {
        $cache = new \ReflectionProperty(Theme::class, 'catalog');
        $cache->setValue(null, null);

        foreach (['ansi', 'ascii', 'plain', 'no', 'notty', 'auto-no', 'dark', 'light', 'dracula', 'tokyo-night', 'tokyonight', 'pink'] as $name) {
            RenderCommand::pickTheme($name);
        }
        self::assertNull($cache->getValue(), 'stock names must answer from their factories, not catalog()');

        RenderCommand::pickTheme('solarized');
        self::assertIsArray($cache->getValue(), 'a bundled slug is the one place the catalog is read');
    }

    public function testCliAliasesAreNotCatalogNames(): void
    {
        $this->assertNull(Theme::catalogByName('no'));
        $this->assertNull(Theme::catalogByName('auto-no'));
        $this->assertSame(RenderCommand::pickTheme('plain'), RenderCommand::pickTheme('no'));
        $this->assertEquals(RenderCommand::pickTheme('notty'), RenderCommand::pickTheme('auto-no'));
    }

    /**
     * The catalog's loose matching would take `Tokyo Night` and `auto`, but
     * pickTheme's roster has never answered them, so they stay refused.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownNames(): iterable
    {
        yield 'space-separated tokyo' => ['Tokyo Night'];
        yield 'auto is not a theme'   => ['auto'];
        yield 'unknown word'          => ['mystery'];
        yield 'empty'                 => [''];
        yield 'file path'             => ['style.json'];
    }

    #[DataProvider('unknownNames')]
    public function testNamesOutsideTheRosterStillRefuse(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RenderCommand::pickTheme($name);
    }

    private function sample(): string
    {
        return (string) file_get_contents(self::FIXTURES . '/sample.md');
    }

    private function golden(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name . '.ansi');
    }
}
