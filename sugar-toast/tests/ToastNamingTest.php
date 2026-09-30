<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Toast\{Position, Toast, ToastType};

/**
 * Naming-convention pins from the 2026-09-30 audit (LOW: View()/getHistory()
 * broke the project's bare-accessor / lowercase-renderer conventions).
 *
 * `View()` survives ONLY as a deprecated delegating shim — sugar-dash and
 * candy-query call it across the library boundary, so it must keep answering
 * byte-identically until those callers migrate. `getHistory()` had no
 * cross-library consumer and was renamed outright, no alias.
 */
final class ToastNamingTest extends TestCase
{
    public function testDeprecatedViewAliasAnswersByteIdentically(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::MiddleRight)
            ->alert(ToastType::Info, 'alias probe');
        $bg = \str_repeat("line\n", 24);

        $this->assertSame(
            $t->view($bg, 80, 24),
            $t->View($bg, 80, 24),
            'the deprecated View() shim must not drift from view()',
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
