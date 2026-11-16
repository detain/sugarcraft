<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use SugarCraft\Dashboard\Foundation\EnumCoerce;
use SugarCraft\Dashboard\Components\Form\CursorState;
use SugarCraft\Dashboard\Components\Toast\NoticePosition;
use SugarCraft\Dashboard\Layout\HAlign;
use PHPUnit\Framework\TestCase;

/**
 * Pins the D-147 accept-both coercion rule: enum pass-through, deprecated
 * legacy strings resolve to their case, and unknown values die at the
 * boundary instead of silently defaulting.
 */
final class EnumCoerceTest extends TestCase
{
    public function testEnumCasePassesThroughIdentically(): void
    {
        self::assertSame(HAlign::Center, EnumCoerce::from(HAlign::Center, HAlign::class, 'alignment'));
        self::assertSame(HAlign::Left, EnumCoerce::from(HAlign::Left, HAlign::class, 'alignment'));
    }

    public function testLegacyStringCoercesToItsCase(): void
    {
        // PHPUnit 10 has no expectUserDeprecationMessage(); a scoped handler
        // both proves the notice fires and keeps the summary clean.
        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            if ($errno !== E_USER_DEPRECATED) {
                return false;
            }
            $notices[] = $message;

            return true;
        });
        try {
            $case = EnumCoerce::from('right', HAlign::class, 'alignment');
        } finally {
            restore_error_handler();
        }

        self::assertSame(HAlign::Right, $case);
        self::assertSame(
            ['Passing the string "right" as alignment is deprecated; pass SugarCraft\Dashboard\Layout\HAlign::Right instead.'],
            $notices,
        );
    }

    public function testEnumPathCarriesNoDeprecationNotice(): void
    {
        $notices = [];
        set_error_handler(static function (int $errno, string $message) use (&$notices): bool {
            if ($errno !== E_USER_DEPRECATED) {
                return false;
            }
            $notices[] = $message;

            return true;
        });
        try {
            EnumCoerce::from(HAlign::Center, HAlign::class, 'alignment');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $notices);
    }

    public function testUnknownStringThrowsNamingTheKnobAndAcceptedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown alignment "sideways"');

        try {
            EnumCoerce::from('sideways', HAlign::class, 'alignment');
        } catch (\InvalidArgumentException $e) {
            // The message must teach the caller the vocabulary (D-L11 dies here).
            self::assertStringContainsString('left', $e->getMessage());
            self::assertStringContainsString('center', $e->getMessage());
            self::assertStringContainsString('right', $e->getMessage());

            throw $e;
        }
    }

    public function testForeignEnumCaseThrowsInsteadOfBeingCoerced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NoticePosition::Center');

        EnumCoerce::from(NoticePosition::Center, HAlign::class, 'alignment');
    }

    public function testPureEnumClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string-backed');

        EnumCoerce::from('left', CursorState::class, 'cursor state');
    }
}
