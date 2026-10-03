<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * The settings view on screen: the frame invariants every shell surface keeps
 * (no line wider than the terminal, never taller than it), the full-band
 * takeover, and the `settings:` click zones landing on what is painted.
 */
final class SettingsEditorRenderTest extends TestCase
{
    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
    }

    private function reset(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE');
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        MenuBar::closeMenu();
        TuiRenderer::resetSizeCache();
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        (new \ReflectionProperty(App::class, 'chromeClickTracker'))->setValue(null, null);
        (new \ReflectionProperty(Chat::class, 'clickTracker'))->setValue(null, null);
    }

    private static function sources(): SettingsSources
    {
        return SettingsSources::fromLaunch('/nonexistent-root', null, null, null, ['SUGARCRUSH_PROVIDER' => 'dev-sglang']);
    }

    private function app(): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('sglang');

        [$app] = App::new($provider, 'test-model')
            ->withChat((new Chat(history: [Message::user('a chat line nobody should see')]))->withSize(120, 30))
            ->withSettingsSources(static fn (): SettingsSources => self::sources())
            ->update(new OpenSettingsMsg());

        return $app;
    }

    public function testTheViewIsExactlyTheSizeItWasGivenAtEverySize(): void
    {
        $editors = [
            SettingsEditor::open(self::sources()),
            SettingsEditor::open(self::sources(), 'parallel'),
            SettingsEditor::open(self::sources())->click(SettingsEditor::TAB_ZONE . '99'),
        ];

        foreach ($editors as $editor) {
            foreach ([[160, 50], [120, 30], [89, 24], [80, 24], [60, 20], [40, 12], [20, 8], [10, 3], [1, 1]] as [$cols, $rows]) {
                $lines = explode("\n", $editor->view(Theme::default(), $cols, $rows));

                self::assertCount($rows, $lines, "{$cols}x{$rows}");
                foreach ($lines as $i => $line) {
                    self::assertSame($cols, Width::string($line), "line {$i} at {$cols}x{$rows}");
                }
            }
        }
    }

    public function testTheDetailNamesTheLockingVariableBesideOrBelowTheList(): void
    {
        foreach ([[140, 30], [80, 30]] as [$cols, $rows]) {
            $plain = Ansi::strip(SettingsEditor::open(self::sources())->view(Theme::default(), $cols, $rows));

            self::assertStringContainsString('env (locked)', $plain, "{$cols}x{$rows}");
            self::assertStringContainsString('locked   set by SUGARCRUSH_PROVIDER', $plain, "{$cols}x{$rows}");
        }
    }

    public function testTheViewTakesTheWholeBandAndHidesTheChat(): void
    {
        $frame = TuiRenderer::render($this->app(), 120, 30);
        $plain = Ansi::strip($frame);

        self::assertStringContainsString('settings · read-only', $plain);
        self::assertStringNotContainsString('a chat line nobody should see', $plain);

        $lines = explode("\n", $frame);
        self::assertLessThanOrEqual(30, \count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(120, Width::string($line));
        }
    }

    public function testEveryZoneSitsOnWhatItNames(): void
    {
        $editor = SettingsEditor::open(self::sources());
        $lines = explode("\n", Ansi::strip($editor->view(Theme::default(), 120, 30)));

        $tabs = 0;
        foreach ($editor->zones(Theme::default(), 120, 30) as [$row, $from, $to, $id]) {
            self::assertStringStartsWith(SettingsEditor::ZONE_PREFIX, $id);
            $cells = trim(mb_substr($lines[$row], $from, $to - $from));

            if (str_starts_with($id, SettingsEditor::TAB_ZONE)) {
                $tabs++;
                self::assertSame($editor->tabLabels()[(int) substr($id, \strlen(SettingsEditor::TAB_ZONE))], $cells);
                continue;
            }

            $index = (int) substr($id, \strlen(SettingsEditor::ROW_ZONE));
            self::assertStringContainsString(mb_substr($editor->rows()[$index]->label, 0, 10), $cells);
        }

        self::assertGreaterThan(1, $tabs, 'the strip records a zone per painted tab');
    }

    public function testAClickOnATabSwitchesToItAndTheWheelMovesTheHighlight(): void
    {
        $app = $this->app();
        $frame = explode("\n", Ansi::strip(TuiRenderer::render($app, 120, 30)));
        // The band starts where the view's top border is painted, under
        // however many lines the menu bar took.
        $bandTop = (int) array_key_first(array_filter($frame, static fn (string $l): bool => str_contains($l, 'settings · read-only')));
        self::assertGreaterThan(0, $bandTop);

        $editor = $app->settingsEditor;
        self::assertNotNull($editor);
        [$row, $from] = array_values(array_filter(
            $editor->zones($app->theme(), 120, 30 - $bandTop),
            static fn (array $z): bool => $z[3] === SettingsEditor::TAB_ZONE . '1',
        ))[0];

        // Mouse reports are 1-based; the zones are recorded 0-based.
        [$app] = $app->update(new MouseClickMsg($from + 1, $bandTop + $row + 1, MouseButton::Left, MouseAction::Press));
        [$app] = $app->update(new MouseReleaseMsg($from + 1, $bandTop + $row + 1, MouseButton::Left, MouseAction::Release));
        self::assertSame(1, $app->settingsEditor?->tab, 'the click landed on the second tab');

        [$app] = $app->update(new MouseWheelMsg(10, 10, MouseButton::WheelDown, MouseAction::Press));
        self::assertGreaterThan(0, $app->settingsEditor?->cursor);
    }
}
