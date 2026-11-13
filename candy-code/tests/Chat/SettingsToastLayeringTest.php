<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tui\Settings\SettingsToastExpiredMsg;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;

/**
 * S-O40 (once-048): a notification toast never paints over an open modal
 * (palette rows, tab strip) and never outlives its own timer because of
 * repaints or resizes — its expiry is the model's tick, not a frame count.
 */
final class SettingsToastLayeringTest extends TestCase
{
    private const TOAST = 'Saved 1 setting to cfg';

    public function testAnOpenPaletteHidesTheToastAndClosingItBringsTheToastBack(): void
    {
        $chat = $this->toasting();
        $this->assertStringContainsString(self::TOAST, Ansi::strip(Renderer::render($chat)));

        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNotNull($open->palette(), 'fixture: Ctrl+P opens the palette');
        $this->assertStringNotContainsString(self::TOAST, Ansi::strip(Renderer::render($open)), 'the toast sits below a modal, never over its rows');
        $this->assertNotNull($open->settingsToast(), 'hiding is paint-only: the toast is still owed its tick');

        [$closed] = $open->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($closed->palette());
        $this->assertStringContainsString(self::TOAST, Ansi::strip(Renderer::render($closed)));
    }

    public function testResizesAndRepaintsNeitherExtendNorCutTheToastsLife(): void
    {
        [$chat, $tick] = $this->toastingWithTick();

        for ($i = 0; $i < 20; ++$i) {
            Renderer::render($chat);
        }
        [$chat] = $chat->update(new WindowSizeMsg(120, 30));
        [$chat] = $chat->update(new WindowSizeMsg(90, 20));
        $this->assertNotNull($chat->settingsToast(), 'a resize is not an expiry event');

        [$gone] = $chat->update($tick);
        $this->assertNull($gone->settingsToast(), 'its own tick takes it down however often the frame repainted');
        $this->assertStringNotContainsString(self::TOAST, Ansi::strip(Renderer::render($gone)));
    }

    private function toasting(): Chat
    {
        return $this->toastingWithTick()[0];
    }

    /**
     * @return array{0: Chat, 1: SettingsToastExpiredMsg}
     */
    private function toastingWithTick(): array
    {
        [$chat, $cmd] = (new Chat(backend: new EchoBackend(), rows: 24, cols: 100))->applySettings(['parallelToolCalls'], 'cfg');
        $msg = $this->firstTick($cmd);
        $this->assertInstanceOf(SettingsToastExpiredMsg::class, $msg);

        return [$chat, $msg];
    }

    private function firstTick(?\Closure $cmd): mixed
    {
        $msg = $cmd === null ? null : $cmd();
        if ($msg instanceof BatchMsg) {
            foreach ($msg->cmds as $inner) {
                $found = $this->firstTick($inner);
                if ($found instanceof SettingsToastExpiredMsg) {
                    return $found;
                }
            }

            return null;
        }

        return $msg instanceof TickRequest ? ($msg->produce)() : $msg;
    }
}
