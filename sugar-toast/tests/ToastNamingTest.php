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
}
