<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * P-TABS.e close/quit law: closing a BUSY tab is a two-press gesture naming
 * the same tab within the arm window (first press only arms and says so),
 * the deck never closes its last tab, closing the foreground moves away
 * first, and quitting with a busy background tab asks exactly once.
 */
final class TabCloseAndQuitTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    private function idleClosingChat(): Chat
    {
        return new Chat(backend: new EchoBackend());
    }

    private function closingApp(bool $foregroundBusy): App
    {
        $app = App::new($this->provider, 'test-model')
            ->withChat($foregroundBusy
                ? new Chat(backend: new EchoBackend(), inFlight: true, generation: 1)
                : $this->idleClosingChat())
            ->withTabFactory(fn (?string $sessionId): Chat => $this->idleClosingChat());
        [$app] = $app->update(new WindowSizeMsg(100, 30));

        return $app;
    }

    public function testClosingABusyParkedTabArmsFirstAndClosesOnTheConfirmingPress(): void
    {
        $app = $this->closingApp(foregroundBusy: true);
        [$opened] = $app->update(new OpenTabMsg());
        self::assertSame(2, $opened->deck?->count());

        [$armed, $armCmd] = $opened->update(new CloseTabMsg('tab-1'));
        self::assertNull($armCmd);
        self::assertSame(2, $armed->deck?->count(), 'the first press must not remove the tab');
        self::assertIsArray($armed->pendingTabClose);
        self::assertSame('tab-1', $armed->pendingTabClose['key']);
        self::assertSame(Lang::t('tabs.close_arm'), $armed->status);

        [$closed] = $armed->update(new CloseTabMsg('tab-1'));
        self::assertSame(1, $closed->deck?->count());
        self::assertFalse($closed->deck?->has('tab-1'));
        self::assertNull($closed->pendingTabClose);
    }

    public function testTheArmExpiresAndADifferentTargetResetsIt(): void
    {
        $app = $this->closingApp(foregroundBusy: true);
        [$opened] = $app->update(new OpenTabMsg());
        [$armed] = $opened->update(new CloseTabMsg('tab-1'));

        // A stale arm (timestamp past the window) only re-arms; the window's
        // length is the constant the arm was timed against.
        $stale = $this->reArm($armed, ['key' => 'tab-1', 'at' => microtime(true) - 10.0]);
        [$rearmed] = $stale->update(new CloseTabMsg('tab-1'));
        self::assertSame(2, $rearmed->deck?->count());
        self::assertSame('tab-1', $rearmed->pendingTabClose['key']);

        // Pressing to close ANOTHER tab does not spend tab-1's arm.
        [$otherArm] = $armed->update(new CloseTabMsg('tab-2'));
        self::assertSame(1, $otherArm->deck?->count());
        self::assertFalse($otherArm->deck?->has('tab-2'));
        self::assertTrue($otherArm->deck?->has('tab-1'));
        self::assertNull($otherArm->pendingTabClose);
    }

    /**
     * Rewrite the close arm through App's own private mutate (named
     * variadic), so the expiry branch can be timed without sleeping.
     *
     * @param array{key:string,at:float} $arm
     */
    private function reArm(App $app, array $arm): App
    {
        $method = new \ReflectionMethod($app, 'mutate');

        return $method->invokeArgs($app, ['pendingTabClose' => $arm]);
    }

    public function testClosingAnIdleTabTakesOnePress(): void
    {
        $app = $this->closingApp(foregroundBusy: false);
        [$opened] = $app->update(new OpenTabMsg());

        [$closed] = $opened->update(new CloseTabMsg('tab-1'));
        self::assertSame(1, $closed->deck?->count());
        self::assertFalse($closed->deck?->has('tab-1'));
        self::assertNull($closed->pendingTabClose);
    }

    public function testTheDeckNeverClosesItsLastTab(): void
    {
        $app = $this->closingApp(foregroundBusy: false);

        [$after, $cmd] = $app->update(new CloseTabMsg());

        self::assertSame($app, $after, 'refusing the last tab changes nothing at all');
        self::assertNull($cmd);
        self::assertSame(1, $after->deck?->count());
    }

    public function testClosingTheBusyForegroundMovesAwayFirstThenRemoves(): void
    {
        $app = $this->closingApp(foregroundBusy: true);
        [$opened] = $app->update(new OpenTabMsg());
        self::assertSame('tab-2', $opened->deck?->activeKey());
        // Move back to the busy tab so the close targets the FOREGROUND.
        [$back] = $opened->update(new KeyMsg(KeyType::Tab, ctrl: true));
        self::assertSame('tab-1', $back->deck?->activeKey());

        [$armed] = $back->update(new CloseTabMsg());
        self::assertIsArray($armed->pendingTabClose);
        self::assertSame('tab-1', $armed->pendingTabClose['key']);

        [$closed] = $armed->update(new CloseTabMsg());
        self::assertSame(1, $closed->deck?->count());
        self::assertFalse($closed->deck?->has('tab-1'));
        self::assertSame('tab-2', $closed->deck?->activeKey());
        self::assertNull($closed->pendingTabClose);
    }

    public function testQuitWithABusyBackgroundTabAsksExactlyOnce(): void
    {
        $app = $this->closingApp(foregroundBusy: true);
        [$opened] = $app->update(new OpenTabMsg());

        [$warned, $warnCmd] = $opened->update(new QuitMsg());
        self::assertNull($warnCmd);
        self::assertTrue($warned->tabQuitWarned);
        self::assertSame(Lang::t('tabs.quit_arm'), $warned->status);
        self::assertSame(2, $warned->deck?->count(), 'the warning press forwards nothing');

        [$passed] = $warned->update(new QuitMsg());
        // Chat itself ignores QuitMsg (the real quit rides the Cmd channel),
        // so the second press must run the untouched pre-deck route: an
        // identity delegate, the warning never re-arming.
        self::assertSame($warned, $passed);
        self::assertTrue($passed->tabQuitWarned);
    }

    public function testQuitWithNoBusyBackgroundGoesStraightThrough(): void
    {
        $app = $this->closingApp(foregroundBusy: false);
        [$opened] = $app->update(new OpenTabMsg());

        [$after] = $opened->update(new QuitMsg());

        self::assertFalse($after->tabQuitWarned);
        self::assertNotSame(Lang::t('tabs.quit_arm'), $after->status);
    }
}
