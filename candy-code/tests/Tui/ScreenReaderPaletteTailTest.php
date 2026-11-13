<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * S-U02: screen-reader mode replaces the body with the overlay; the overlay
 * must stay inside the viewport so the status row (the `to quit` affordance)
 * survives at every height, even with the cursor deep in the list.
 */
final class ScreenReaderPaletteTailTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/crush-sr-tail-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        file_put_contents($this->home . '/.candy-code/config.json', '{"screenReader":true}');
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        UiSettings::forget();
        $this->restoreHomeSandbox();
        @unlink($this->home . '/.candy-code/config.json');
        @rmdir($this->home . '/.candy-code');
        @rmdir($this->home);
        parent::tearDown();
    }

    /** @return iterable<string, array{int}> */
    public static function viewportRows(): iterable
    {
        yield 'rows 12' => [12];
        yield 'rows 24' => [24];
        yield 'rows 30' => [30];
    }

    #[DataProvider('viewportRows')]
    public function testTheQuitStatusRowSurvivesAnOpenPalette(int $rows): void
    {
        $chat = (new Chat(palette: PaletteState::root()))->withSize(100, $rows);
        for ($i = 0; $i < 40; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $out = (string) preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($chat));
        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertLessThanOrEqual($rows, \count($lines), 'frame must fit the viewport');
        $this->assertStringContainsString('to quit', $out, "status row lost at rows=$rows");
    }

    #[DataProvider('viewportRows')]
    public function testTheHostedBandKeepsTheQuitStatusRowUnderAnOpenPalette(int $rows): void
    {
        $chat = (new Chat(palette: PaletteState::root(), backend: new EchoBackend()))->withSize(100, $rows);
        for ($i = 0; $i < 40; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Down));
        }
        $app = App::new($this->createMock(ProviderInterface::class), 'test-model')->withChat($chat);
        $out = Ansi::strip(TuiRenderer::render($app, 100, $rows));
        $lines = explode("\n", rtrim($out, "\n"));
        $this->assertLessThanOrEqual($rows, \count($lines), 'hosted frame must fit the viewport');
        $this->assertStringContainsString('to quit', $out, "hosted status row lost at rows=$rows");
    }
}
