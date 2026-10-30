<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\ThemeFile;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\TerminalBackground;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;

/**
 * C-TUI-4.a: custom TUI themes load from `~/.candy-code/themes/*.json` through
 * the user directory ONLY — a project naming a theme that is not on the
 * machine must degrade to the default with a notice, never throw at launch
 * (Chat::themeName is fed the raw merged config and resolves lazily per frame).
 */
final class ThemeFileTest extends TestCase
{
    use HomeSandboxTrait;

    private string $fileTestHome = '';

    private string $fileTestPreviousCwd = '';

    /** @var list<string> extra trees to prune at teardown */
    private array $fileTestTrees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileTestPreviousCwd = (string) getcwd();
        $this->fileTestHome = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_theme_file_home_' . bin2hex(random_bytes(6)),
        );
        $this->fileTestTrees[] = $this->fileTestHome;

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
        Theme::resetForTesting();
    }

    protected function tearDown(): void
    {
        Theme::resetForTesting();
        RuntimeNoticeSink::reset();
        TerminalBackground::forget();
        chdir($this->fileTestPreviousCwd);

        $this->restoreHomeSandbox();
        foreach ($this->fileTestTrees as $tree) {
            $this->pruneFileTestTree($tree);
        }

        parent::tearDown();
    }

    public function testAValidFilePaintsMergedTokensUnderItsOwnName(): void
    {
        $this->seedFileTestTheme('midnight', '{"extends": "tokyoNight", "tokens": {"success": "#ff00ff"}}');
        Theme::resetForTesting();

        Theme::byName('dark');
        $builtInSuccess = Theme::default()->shellSuccess->toHex();

        $theme = Theme::byName('midnight');

        $this->assertSame('midnight', $theme->name);
        $this->assertSame('#ff00ff', $theme->shellSuccess->toHex(), 'a token that already clears the floor paints verbatim');
        $this->assertNotSame($builtInSuccess, $theme->shellSuccess->toHex());
        $this->assertSame([], RuntimeNoticeSink::drain(), 'a well-formed file stays silent');

        $this->assertSame(['midnight'], ThemeFile::names());
        $this->assertContains('midnight', Theme::names(), 'names() carries customs so every Chat consumer lists them');
        $this->assertNotContains('midnight', Theme::builtInNames());
    }

    public function testAMalformedFileFallsBackToItsBaseWithOneNotice(): void
    {
        $this->seedFileTestTheme('broken', '{oops');
        Theme::resetForTesting();
        $this->pinFileTestBackground(30, 30, 30);

        $theme = Theme::byName('broken');

        $this->assertSame(Theme::default()->border->toHex(), $theme->border->toHex(), 'a broken file paints as its base (dark)');

        $rows = RuntimeNoticeSink::drain();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString("Theme file 'broken'", $rows[0]);
        $this->assertStringContainsString('not a JSON object', $rows[0]);

        Theme::byName('broken');
        $this->assertSame([], RuntimeNoticeSink::drain(), 'the notice is once per process, never per frame');
    }

    public function testALowContrastTokenIsProjectedUpToTheFloor(): void
    {
        $this->seedFileTestTheme('dusk', '{"extends": "dark", "tokens": {"border": "#2a2a2a"}}');
        Theme::resetForTesting();
        $this->pinFileTestBackground(30, 30, 30);

        $theme = Theme::byName('dusk');

        $this->assertNotSame('#2a2a2a', $theme->border->toHex());
        $contrast = Theme::contrast(Color::hex($theme->border->toHex()), Color::hex('#1e1e1e'));
        $this->assertGreaterThanOrEqual(Theme::CONTRAST_MIN - 0.01, $contrast, 'custom colours ride the same contrast projection');
    }

    public function testUnknownTokensAndBadColoursAreNoticedAndIgnored(): void
    {
        $this->seedFileTestTheme('edge', '{"extends": "light", "tokens": {"glow": "#ffffff", "border": "blue"}}');
        Theme::resetForTesting();

        $theme = Theme::byName('edge');
        $this->assertSame('edge', $theme->name);

        $rows = RuntimeNoticeSink::drain();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString("unknown token 'glow'", $rows[0]);
        $this->assertStringContainsString("'border'", $rows[0]);
    }

    public function testTheProjectDirectoryIsNeverRead(): void
    {
        $project = sys_get_temp_dir() . '/crush_theme_file_project_' . bin2hex(random_bytes(6));
        $this->fileTestTrees[] = $project;
        mkdir($project . '/' . ThemeFile::SUBDIRECTORY, 0700, true);
        file_put_contents($project . '/' . ThemeFile::SUBDIRECTORY . '/sneaky.json', '{"tokens": {"success": "#ff00ff"}}');
        chdir($project);

        Theme::resetForTesting();
        $this->assertSame([], ThemeFile::names(), 'a theme file living in the project tree is never discovered');

        $theme = Theme::byName('sneaky');
        $this->assertSame('dark', $theme->name, 'a project naming a theme this machine lacks degrades to the default');

        $rows = array_values(array_filter(
            RuntimeNoticeSink::drain(),
            static fn (string $row): bool => str_contains($row, 'sneaky'),
        ));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString(ThemeFile::SUBDIRECTORY, $rows[0]);
    }

    public function testASymlinkedThemeFileIsListedButRefusedNotFollowed(): void
    {
        $outside = sys_get_temp_dir() . '/crush_theme_file_escape_' . bin2hex(random_bytes(6)) . '.json';
        $this->fileTestTrees[] = $outside;
        file_put_contents($outside, '{"tokens": {"success": "#ff00ff"}}');

        $directory = $this->fileTestHome . '/' . ThemeFile::SUBDIRECTORY;
        mkdir($directory, 0700, true);
        $this->assertTrue(symlink($outside, $directory . '/linked.json'));
        Theme::resetForTesting();

        $this->assertContains('linked', ThemeFile::names(), 'the scan lists names; the gate is on the read');

        $theme = Theme::byName('linked');
        $this->assertSame('dark', $theme->name, 'a file whose real path leaves the owned directory is refused');
    }

    public function testAnUnknownExtendsFallsBackToDarkWithANote(): void
    {
        $this->seedFileTestTheme('weird', '{"extends": "neon", "tokens": {"success": "#ff00ff"}}');
        Theme::resetForTesting();

        $theme = Theme::byName('weird');

        $this->assertSame('weird', $theme->name);
        $this->assertSame('#ff00ff', $theme->shellSuccess->toHex(), 'valid tokens still merge onto the dark base');

        $rows = RuntimeNoticeSink::drain();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString("unknown extends 'neon'", $rows[0]);
    }

    public function testACustomNamedAfterABuiltInIsShadowed(): void
    {
        $this->seedFileTestTheme('dark', '{"tokens": {"success": "#ff00ff"}}');
        Theme::resetForTesting();

        $theme = Theme::byName('dark');

        $this->assertNotSame('#ff00ff', $theme->shellSuccess->toHex(), 'the six built-ins always win a name collision');
        $this->assertSame(1, count(array_keys(Theme::names(), 'dark', true)));
    }

    public function testAnEmptyObjectIsAThemeThatOverridesNothing(): void
    {
        $this->seedFileTestTheme('plain', '{}');
        Theme::resetForTesting();
        $this->pinFileTestBackground(30, 30, 30);

        $theme = Theme::byName('plain');

        $this->assertSame('plain', $theme->name);
        $this->assertSame(Theme::default()->border->toHex(), $theme->border->toHex());
        $this->assertSame([], RuntimeNoticeSink::drain());
    }

    private function seedFileTestTheme(string $stem, string $body): void
    {
        $directory = $this->fileTestHome . '/' . ThemeFile::SUBDIRECTORY;
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($directory . '/' . $stem . '.json', $body);
    }

    private function pinFileTestBackground(int $red, int $green, int $blue): void
    {
        TerminalBackground::observe(new BackgroundColorMsg($red, $green, $blue));
    }

    private function pruneFileTestTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $child) {
            if ($child !== '.' && $child !== '..') {
                $this->pruneFileTestTree($path . '/' . $child);
            }
        }

        @rmdir($path);
    }
}
