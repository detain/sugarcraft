<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Alert, Position, Toast, ToastType};

/**
 * Pins the alert lifecycle that once did not
 * exist: a progress toast previously had no update door at all — the README's
 * own "re-render as progress updates" pattern literally queued a SECOND box
 * per tick (and with the default unlimited concurrency, leaked one more
 * forever). The fix is id-keyed update-in-place: alert()/progressToast()/
 * push() accept an id, appendBounded() replaces the live alert with that id
 * at its queue position (never an append, never a slot, never the overflow
 * policy), and removeAlert() retires an alert by identity without filing it
 * as a dismissal.
 *
 * M15 rides the same pass: withoutExpiry()/withCancelledExpiry() now set an
 * explicit persistent sentinel that the queueing doors honour, so the
 * documented per-alert escape hatch is true even on a toast with
 * withDuration() configured — previously push() re-inherited the expiry off
 * the shared null sentinel and the alert auto-dismissed anyway.
 */
final class ToastProgressLifecycleTest extends TestCase
{
    private const BG = '';

    private function cells(Toast $toast, int $width = 80, int $height = 12): string
    {
        return $toast->view(\str_repeat("bg\n", 12), $width, $height);
    }

    public function testRepeatedTickWithoutIdAppendsASecondBox(): void
    {
        // The documented legacy behaviour, pinned so the semantics of an
        // anonymous re-queue never silently change under this fix.
        $toast = Toast::new(60)
            ->progressToast(ToastType::Info, 'Downloading...', 0.65)
            ->progressToast(ToastType::Info, 'Downloading...', 0.80);
        $frame = $this->cells($toast);
        $this->assertSame(2, \substr_count($frame, 'Downloading...'), 'anonymous re-queue is an append');
    }

    public function testRepeatedTickWithIdUpdatesInPlace(): void
    {
        $toast = Toast::new(60)
            ->progressToast(ToastType::Info, 'Downloading...', 0.65, id: 'dl')
            ->progressToast(ToastType::Info, 'Downloading...', 0.80, id: 'dl');
        $frame = $this->cells($toast);
        $this->assertSame(1, \substr_count($frame, 'Downloading...'), 'same id must not grow the stack');
        // 0.80 of the bar is filled: the 65% frame's exact bar bytes differ,
        // and the updated frame carries strictly MORE block cells than 0.65.
        $late = Toast::new(60)->progressToast(ToastType::Info, 'Downloading...', 0.80, id: 'dl');
        $this->assertSame(
            $this->cells($late),
            $frame,
            'an updated alert must render byte-identically to a single fresh one at the same state'
        );
    }

    public function testIdUpdatePreservesQueuePosition(): void
    {
        $toast = Toast::new(60)
            ->info('first')
            ->progressToast(ToastType::Info, 'Downloading...', 0.20, id: 'dl')
            ->info('last')
            ->progressToast(ToastType::Info, 'Downloading...', 0.90, id: 'dl');
        $frame = $this->cells($toast, 80, 20);
        $atFirst = \strpos($frame, 'first');
        $atLast = \strpos($frame, 'last');
        $this->assertNotFalse($atFirst);
        $this->assertNotFalse($atLast);
        // Three boxes, middle one updated: 'Downloading...' sits between the
        // siblings exactly once.
        $this->assertSame(1, \substr_count($frame, 'Downloading...'));
        $this->assertGreaterThan($atFirst, \strpos($frame, 'Downloading...'));
        $this->assertLessThan($atLast, \strpos($frame, 'Downloading...'));
    }

    public function testIdUpdateBypassesOverflowPolicyAndSlotBudget(): void
    {
        $toast = Toast::new(60)
            ->withMaxConcurrent(1)
            ->progressToast(ToastType::Info, 'Downloading...', 0.10, id: 'dl')
            ->progressToast(ToastType::Info, 'Downloading...', 0.90, id: 'dl');
        $frame = $this->cells($toast);
        $this->assertSame(1, \substr_count($frame, 'Downloading...'));
        // A DIFFERENT id at a full cap obeys the policy (DropOldest default:
        // the old alert leaves, the new one arrives) — proof the replace leg
        // is id-scoped, not a general cap bypass.
        $toast = $toast->info('intruder');
        $this->assertStringContainsString('intruder', $this->cells($toast));
        $this->assertStringNotContainsString('Downloading...', $this->cells($toast));
    }

    public function testRemoveAlertRetiresByIdentityWithoutHistory(): void
    {
        $toast = Toast::new(60)
            ->info('keep')
            ->progressToast(ToastType::Info, 'Downloading...', 0.90, id: 'dl');
        $gone = $toast->removeAlert('dl');
        $frame = $this->cells($gone);
        $this->assertStringContainsString('keep', $frame);
        $this->assertStringNotContainsString('Downloading...', $frame);
        $this->assertCount(0, $gone->history(), 'removeAlert is not a dismissal');
        // Unknown id: identical instance, untouched queue.
        $this->assertSame($gone, $gone->removeAlert('nope'));
    }

    public function testPersistentEscapeHatchSurvivesConfiguredDuration(): void
    {
        // M15: the README example, executed. withoutExpiry() must NOT be
        // re-inherited by push() when the toast carries a duration.
        $toast = Toast::new(60)->withDuration(5.0);
        $toast = $toast->push((new Alert(ToastType::Info, 'Connected'))->withoutExpiry());
        $this->assertNull($toast->nextExpiry(), 'a persistent alert arms no timer');
        $this->assertStringContainsString('Connected', $this->cells($toast));

        // The alias carries the same sentinel:
        $alias = Toast::new(60)->withDuration(5.0)
            ->push(Alert::new(ToastType::Warning, 'AlsoStays')->withCancelledExpiry());
        $this->assertNull($alias->nextExpiry());

        // Control: an ordinary push on the same toast DOES inherit —
        // null expiresAt still means "use the default".
        $inheriting = Toast::new(60)->withDuration(5.0)->push(Alert::new(ToastType::Info, 'Timed'));
        $this->assertNotNull($inheriting->nextExpiry());
    }

    public function testExplicitExpiryClearsThePersistentSentinel(): void
    {
        // Asking for a timer after withoutExpiry() must win, and the armed
        // alert then behaves like any timed one on a duration toast.
        $alert = (new Alert(ToastType::Info, 'ReTimed'))->withoutExpiry()->withExpiry(60.0);
        $this->assertFalse($alert->persistent);
        $toast = Toast::new(60)->withDuration(5.0)->push($alert);
        $expiry = $toast->nextExpiry();
        $this->assertNotNull($expiry);
        $this->assertGreaterThan(\microtime(true) + 30.0, $expiry, 'explicit 60s expiry must survive push');
    }

    public function testCancelAlertStillMakesQueuedAlertPersistent(): void
    {
        $toast = Toast::new(60)->withDuration(5.0)->info('hold on');
        $held = $toast->cancelAlert(0);
        $this->assertNull($held->nextExpiry());
        $this->assertStringContainsString('hold on', $this->cells($held));
    }

    public function testProgressTickReArmsExpirySoStalledBarsEventuallyLeave(): void
    {
        $toast = Toast::new(60)->withDuration(30.0);
        $toast = $toast->progressToast(ToastType::Info, 'Downloading...', 0.10, id: 'dl');
        $first = $toast->nextExpiry();
        $this->assertNotNull($first, 'each tick rides the normal inheritance door');
        $toast = $toast->progressToast(ToastType::Info, 'Downloading...', 0.20, id: 'dl');
        $second = $toast->nextExpiry();
        $this->assertNotNull($second);
        $this->assertGreaterThanOrEqual($first, $second, 'the update refreshed its expiry');
    }
}
