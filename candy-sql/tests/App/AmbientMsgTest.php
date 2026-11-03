<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\App;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Sql\App;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\SqliteDatabase;
use SugarCraft\Sql\Pane;
use SugarCraft\Sql\Renderer;
use SugarCraft\Style\Style;

/**
 * D-M13 + D-M14: the ambient messages sugar-core's Program dispatches —
 * PasteMsg (bracketed paste) and ColorProfileMsg (the NO_COLOR / CLICOLOR /
 * TERM=dumb family) — used to be discarded at App's `!KeyMsg` guard, so pastes
 * were lost and the UI painted TrueColor escapes the terminal could not honour.
 */
final class AmbientMsgTest extends TestCase
{
    protected function tearDown(): void
    {
        // Both pins touch process-ambient statics; restore the resting state.
        Style::setDefaultProfile(null);
        Renderer::resetSizeCache();
    }

    public function testPasteMsgLandsInTheQueryEditor(): void
    {
        $app = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            // The Query pane's real focus owner is the editor; App::withPane
            // focuses it at runtime, the builder hands us the raw widget — so
            // the test arms it the way the pane does (PasteMsg needs focus).
            ->withQueryEditor(TextArea::new()->setValue('-- draft ')->focus()[0])
            ->build();

        [$next, $cmd] = $app->update(new PasteMsg("SELECT * FROM t\nWHERE x = 1"));

        $this->assertNull($cmd);
        $this->assertStringContainsString('SELECT * FROM t', $next->editor()->value());
        $this->assertStringContainsString('WHERE x = 1', $next->editor()->value());
        $this->assertStringContainsString('-- draft ', $next->editor()->value());
    }

    public function testColorProfileMsgPinsTheAmbientStyleProfile(): void
    {
        $app = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->build();

        $this->assertNull(Style::defaultProfile());

        // Mechanism baseline BEFORE arming: every candy-sql component mints
        // via Style::new(), which reads the ambient profile at construction.
        $bright = Style::new()->foreground(\SugarCraft\Core\Util\Color::hex('#ff5555'))->render('red');
        $this->assertStringContainsString("\x1b[38;2;255;85;85m", $bright, 'TrueColor baseline');

        [$next, $cmd] = $app->update(new ColorProfileMsg(ColorProfile::Ascii));
        $this->assertNull($cmd);
        $this->assertSame(ColorProfile::Ascii, Style::defaultProfile());

        // Same mint after arming: colour bytes gone, decoration survives.
        $muted = Style::new()->foreground(\SugarCraft\Core\Util\Color::hex('#ff5555'))
            ->bold()->render('red');
        $this->assertStringNotContainsString('38;2', $muted);
        $this->assertStringContainsString('1m', $muted, 'decoration survives Ascii');

        // Restore the resting ambient for the frame leg (the update() above
        // pinned it; Style::new() resolves the profile at construction).
        Style::setDefaultProfile(null);
        Renderer::setSize(120, 40);
        $queryApp = App::builder()
            ->withDb(new SqliteDatabase(new PDO('sqlite::memory:')))
            ->withFlavor(Flavor::Sqlite)
            ->withPane(Pane::Query)
            ->withQueryEditor(TextArea::new()->setValue('SELECT 1')->focus()[0])
            ->build();
        $plain = Renderer::render($queryApp);
        $this->assertStringContainsString("\x1b[38;2;", $plain, 'frame TrueColor baseline');

        // Re-arm through the model itself — the App handler is the SUT.
        [$armed,] = $queryApp->update(new ColorProfileMsg(ColorProfile::Ascii));
        Renderer::setSize(120, 40);
        $ascii = Renderer::render($armed);
        $this->assertStringNotContainsString("\x1b[38;2;", $ascii);
        $this->assertStringNotContainsString("\x1b[48;2;", $ascii);
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $ascii, 'text still painted');
    }
}
