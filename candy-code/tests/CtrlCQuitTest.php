<?php
declare(strict_types=1);
namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;

/**
 * Ctrl+C Ctrl+C must quit (G-14: a single press interrupts) — it stopped doing so on the live path.
 *
 * sugar-core's InputReader normalizes control bytes 0x01-0x1a into
 * (Char, chr(0x60 + code), ctrl: true), so a real terminal delivers ^C as
 * rune 'c' WITH the ctrl flag. Chat tested only for the raw "\x03" rune, so
 * the check could never match in production and the user could not exit.
 */
final class CtrlCQuitTest extends TestCase
{
    /** The encoding sugar-core actually produces for byte 0x03. */
    public function testCtrlFlaggedCQuitsStandalone(): void
    {
        $ctrlC = new KeyMsg(KeyType::Char, 'c', ctrl: true);
        [$armed, $first] = (new Chat())->update($ctrlC);
        $this->assertNull($first, 'G-14: the first Ctrl+C only arms the quit');
        [, $cmd] = $armed->update($ctrlC);
        $this->assertNotNull($cmd, 'Ctrl+C Ctrl+C must quit');
    }

    /** And through the pane shell, which is what bin/candy-code boots. */
    public function testCtrlFlaggedCQuitsWhenHostedInTheShell(): void
    {
        $p = $this->createMock(ProviderInterface::class);
        $p->method('name')->willReturn('stub');
        $app = App::new($p, 'm')->withChat(new Chat());

        $ctrlC = new KeyMsg(KeyType::Char, 'c', ctrl: true);
        [$armed] = $app->update($ctrlC);
        [, $cmd] = $armed->update($ctrlC);
        $this->assertNotNull($cmd, 'Ctrl+C Ctrl+C must quit through the shell too');
    }

    /** The synthesized raw-rune form still works. */
    public function testRawControlCRuneStillQuits(): void
    {
        [, $cmd] = (new Chat())->update(new KeyMsg(KeyType::Char, "\x03"));
        $this->assertNotNull($cmd);
    }

    /** A plain 'c' must still type, not quit. */
    public function testPlainCDoesNotQuit(): void
    {
        [, $cmd] = (new Chat())->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertNull($cmd, 'typing c must not quit');
    }
}
