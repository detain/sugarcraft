<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderInterface;

/**
 * P-TABS.g (GAP-UI-N6): while a deck is up the shell owns the terminal
 * window title through OSC 2 — the plain app name while every window is
 * quiet, `waiting · running` counts the moment one is not — rewritten only
 * when the count actually changes. The title counts EVERY window, focused
 * included (unlike the desktop notice, which TabAttentionTest pins to
 * background transitions); either way the emission is a `Cmd`, never a
 * write from `view()`.
 */
final class WindowTitleTest extends TestCase
{
    /**
     * The `]2;…` bytes $cmd writes, or null when it writes no title.
     * Batches are walked to the depth they actually nest.
     */
    private static function titleSequence(?\Closure $cmd): ?string
    {
        if ($cmd === null) {
            return null;
        }

        $pending = [$cmd];
        do {
            $msg = array_shift($pending)();
            if ($msg instanceof BatchMsg) {
                foreach ($msg->cmds as $child) {
                    $pending[] = $child;
                }

                continue;
            }

            if ($msg instanceof RawMsg && str_starts_with($msg->bytes, "\x1b]2;")) {
                return $msg->bytes;
            }
        } while ($pending !== []);

        return null;
    }

    private function titledDeck(bool $foregroundBusy): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        [$armed] = App::new($provider, 'test-model')
            ->withChat(new Chat(backend: new EchoBackend(), inFlight: $foregroundBusy, generation: 1))
            ->withTabFactory(static fn (?string $sessionId): Chat => new Chat(backend: new EchoBackend()))
            ->update(new WindowSizeMsg(100, 30));
        [$opened] = $armed->update(new OpenTabMsg());

        return $opened;
    }

    public function testOpeningASecondTabMintsThePlainTitleOnce(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        [$armed] = App::new($provider, 'test-model')
            ->withChat(new Chat(backend: new EchoBackend()))
            ->withTabFactory(static fn (?string $sessionId): Chat => new Chat(backend: new EchoBackend()))
            ->update(new WindowSizeMsg(100, 30));

        self::assertNull($armed->deckTitle, 'the seed route is not a deck choke — nothing titled yet');

        [$opened, $cmd] = $armed->update(new OpenTabMsg());
        $plain = Lang::t('tui.window.title.plain');

        self::assertSame($plain, $opened->deckTitle);
        self::assertStringStartsWith("\x1b]2;" . $plain, (string) self::titleSequence($cmd));

        // A further wrapped route with nothing to say never re-sends it.
        [, $again] = $opened->update(new KeyMsg(KeyType::Tab, ctrl: true));
        self::assertNull(self::titleSequence($again), 'the once-on-change law: an unchanged title is not restated');
    }

    public function testAnUnseenFinishedWindowRetitlesClearsOnArrivalAndDiesWithTheWindow(): void
    {
        $app = $this->titledDeck(foregroundBusy: false);

        // tab-1 is the PARKED window (tab-2 opened last is foreground):
        // a settled reply there is UNSEEN, which the title counts as waiting.
        [$waiting, $firstCmd] = $app->update(new TabScopedMsg('tab-1', new AssistantMsg(Message::assistant('a quiet reply'), 1)));
        $expect = Lang::t('tui.window.title.attention', ['waiting' => 1, 'running' => 0]);
        self::assertSame($expect, $waiting->deckTitle);
        self::assertStringContainsString($expect, (string) self::titleSequence($firstCmd));

        // The same state again: no count move, so no write at all.
        [, $secondCmd] = $waiting->update(new TabScopedMsg('tab-1', new AssistantMsg(Message::assistant('another'), 1)));
        self::assertNull(self::titleSequence($secondCmd));

        // Arriving clears it: you are no longer away from a window you are
        // looking at, so the plain name rides back out on the switch route.
        [$onTab1, $switchCmd] = $waiting->update(new KeyMsg(KeyType::Tab, ctrl: true));
        $plain = Lang::t('tui.window.title.plain');
        self::assertSame($plain, $onTab1->deckTitle);
        self::assertStringStartsWith("\x1b]2;" . $plain, (string) self::titleSequence($switchCmd));

        // Closing it changes nobody's count — no further title.
        [$closed, $closeCmd] = $onTab1->update(new CloseTabMsg('tab-1'));
        self::assertSame(1, $closed->deck?->count());
        self::assertSame($plain, $closed->deckTitle);
        self::assertNull(self::titleSequence($closeCmd));
    }

    public function testAWorkingForegroundWindowReadsAsRunning(): void
    {
        $app = $this->titledDeck(foregroundBusy: true);

        // Ctrl+Tab back to tab-1 re-derives its attention from the unparked
        // chat — its turn never stopped — and the switch route retitles.
        [$back, $cmd] = $app->update(new KeyMsg(KeyType::Tab, ctrl: true));
        $expect = Lang::t('tui.window.title.attention', ['waiting' => 0, 'running' => 1]);

        self::assertSame($expect, $back->deckTitle);
        self::assertStringContainsString($expect, (string) self::titleSequence($cmd));
    }

    public function testNoDeckNeverTouchesTheTitle(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $app = App::new($provider, 'test-model')->withChat(new Chat(backend: new EchoBackend()));

        self::assertNull($app->windowList(), 'fixture: no deck, no windows');
        self::assertNull($app->deckTitle);

        [, $cmd] = $app->update(new WindowSizeMsg(100, 30));
        self::assertNull(self::titleSequence($cmd), 'arming the deck is not a deck choke — the title waits for a window route');
    }
}
