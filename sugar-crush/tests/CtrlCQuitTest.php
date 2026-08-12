<?php
declare(strict_types=1);
namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * Ctrl+C must quit — it stopped doing so on the live path.
 *
 * candy-core's InputReader normalizes control bytes 0x01-0x1a into
 * (Char, chr(0x60 + code), ctrl: true), so a real terminal delivers ^C as
 * rune 'c' WITH the ctrl flag. Chat tested only for the raw "\x03" rune, so
 * the check could never match in production and the user could not exit.
 */
final class CtrlCQuitTest extends TestCase
{
    /** The encoding candy-core actually produces for byte 0x03. */
    public function testCtrlFlaggedCQuitsStandalone(): void
    {
        [, $cmd] = (new Chat())->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertNotNull($cmd, 'Ctrl+C must quit');
    }

    /** And through the pane shell, which is what bin/sugarcrush boots. */
    public function testCtrlFlaggedCQuitsWhenHostedInTheShell(): void
    {
        $p = $this->createMock(ProviderInterface::class);
        $p->method('name')->willReturn('stub');
        $app = App::new($p, 'm')->withChat(new Chat());

        [, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertNotNull($cmd, 'Ctrl+C must quit through the shell too');
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
