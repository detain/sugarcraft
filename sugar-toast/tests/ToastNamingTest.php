<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Position, Toast, ToastType};

/**
 * Naming-convention pins from the 2026-09-30 audit (LOW: View()/getHistory()
 * broke the project's bare-accessor / lowercase-renderer conventions).
 *
 * There is no `View()` method — the README states the real mechanism: PHP
 * method dispatch is case-insensitive, so the legacy `View()` call sites in
 * sugar-dashboard and candy-sql resolve directly to `view()`, with no alias
 * that could drift. `getHistory()` had no cross-library consumer and was
 * renamed to `history()` outright, no alias.
 */
final class ToastNamingTest extends TestCase
{
    public function testLegacyViewCallSiteResolvesToViewByteIdentically(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::MiddleRight)
            ->alert(ToastType::Info, 'alias probe');
        $bg = \str_repeat("line\n", 24);

        $this->assertSame(
            $t->view($bg, 80, 24),
            $t->View($bg, 80, 24),
            'PHP dispatch is case-insensitive — the legacy View() call site resolves directly to view()',
        );
    }

    public function testHistoryIsTheBareAccessor(): void
    {
        $dismissed = Toast::new(50)->alert(ToastType::Info, 'gone')->dismiss();

        $this->assertCount(1, $dismissed->history());
        $this->assertFalse(
            \method_exists(Toast::class, 'getHistory'),
            'getHistory() was renamed without an alias — no cross-lib caller existed',
        );
    }

    public function testWithoutExpiryIsADeprecatedForwarderToTheCanonicalDoor(): void
    {
        // TST-ALIAS: two spellings, ONE door until 2.x. The deprecated alias
        // keeps working identically (hosts compile unchanged), while src
        // carries exactly one persistence body and no NEW call sites of the
        // dead spelling — census/prose lead with the canonical word.
        $alert = new \SugarCraft\Toast\Alert(ToastType::Info, 'stay', 2_000_000_000.5);
        $viaAlias = $alert->withoutExpiry();
        $viaCanonical = $alert->withCancelledExpiry();
        $this->assertTrue($viaAlias->persistent, 'alias must still set the sentinel');
        $this->assertNull($viaAlias->expiresAt, 'alias must still clear the expiry');
        $this->assertSame($viaCanonical->persistent, $viaAlias->persistent);
        $this->assertSame($viaCanonical->expiresAt, $viaAlias->expiresAt);

        $alertSrc = (string) \file_get_contents(\dirname(__DIR__) . '/src/Alert.php');
        $toastSrc = (string) \file_get_contents(\dirname(__DIR__) . '/src/Toast.php');
        $this->assertSame(1, \substr_count($alertSrc, 'function withoutExpiry'), 'forwarder count moved');
        $this->assertSame(1, \substr_count($alertSrc, "'persistent' => true"), 'persistence body must stay singular');
        $this->assertStringContainsString('@deprecated', $alertSrc, 'the alias must carry its deprecation in-doc');
        $this->assertSame(0, \substr_count($toastSrc, '->withoutExpiry()'), 'src call sites must use the canonical door');
    }
}
