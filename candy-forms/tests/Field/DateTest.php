<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Date;
use SugarCraft\Forms\Validator\Required;

/**
 * E736 5.6 (r88-x4): calendar-grid date picker — boundary parsing,
 * month-clamped navigation, snapshot frames, validator wiring.
 */
final class DateTest extends TestCase
{
    public function testNewDefaultsToUncommittedValueAndTodayCursor(): void
    {
        $f = Date::new('d');
        $this->assertNull($f->value());
        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $f->cursorDate());
    }

    public function testNewAcceptsValidInitialIntoBothSlots(): void
    {
        $f = Date::new('d', '2026-02-15');
        $this->assertSame('2026-02-15', $f->value());
        $this->assertSame('2026-02-15', $f->cursorDate());
    }

    /** @return array<string, array{0: string}> */
    public static function malformedDates(): array
    {
        return [
            'not-a-date'      => ['not-a-date'],
            'unpadded'        => ['2026-3-5'],
            'impossible-day'  => ['2026-02-30'],
            'zero-date'       => ['0000-00-00'],
            'month-13'        => ['2026-13-01'],
            'empty'           => [''],
            'trailing-space'  => ['2026-02-15 '],
            'datetime-tail'   => ['2026-02-15 10:00'],
        ];
    }

    #[DataProvider('malformedDates')]
    public function testNewRejectsMalformedInitial(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Date::new('d', $bad);
    }

    #[DataProvider('malformedDates')]
    public function testWithValueRejectsMalformed(string $bad): void
    {
        $f = Date::new('d', '2026-02-15');
        $this->expectException(\InvalidArgumentException::class);
        $f->withValue($bad);
    }

    public function testWithValueJumpsCursorToValue(): void
    {
        $f = Date::new('d')->withValue('2027-11-03');
        $this->assertSame('2027-11-03', $f->value());
        $this->assertSame('2027-11-03', $f->cursorDate());
    }

    public function testWithValueNullClearsButKeepsCursor(): void
    {
        $f = Date::new('d', '2026-02-15')->withValue(null);
        $this->assertNull($f->value());
        $this->assertSame('2026-02-15', $f->cursorDate(), 'clearing the commit must not teleport the grid');
    }

    // ------------------------------------------------------------------
    // Keyboard navigation
    // ------------------------------------------------------------------

    public function testDayArrowsRollOverMonthBounds(): void
    {
        [$f, ] = Date::new('d', '2026-03-01')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Left));
        $this->assertSame('2026-02-28', $f->value(), 'left across the month boundary');
        [$f, ] = $f->update(new KeyMsg(KeyType::Right));
        $this->assertSame('2026-03-01', $f->value());
    }

    public function testVimDayAndWeekKeys(): void
    {
        [$f, ] = Date::new('d', '2026-02-15')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame('2026-02-16', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame('2026-02-15', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame('2026-02-22', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame('2026-02-15', $f->value());
        // ctrl-modified chars are not navigation (house Confirm guard).
        [$g, ] = $f->update(new KeyMsg(KeyType::Char, 'l', ctrl: true));
        $this->assertSame('2026-02-15', $g->value());
    }

    public function testWeekArrows(): void
    {
        [$f, ] = Date::new('d', '2026-02-15')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Down));
        $this->assertSame('2026-02-22', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame('2026-02-08', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame('2026-02-01', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::Up));
        $this->assertSame('2026-01-25', $f->value(), 'a week up leaves the month');
    }

    /**
     * The off-by-one keystone set (mutation: flip the month delta sign or
     * drop the day clamp): wrong sign lands the far month, missing clamp
     * lets setDate roll 2026-02-31 into March, and the leap arm proves the
     * clamp reads the TARGET month's length.
     */
    public function testMonthNavigationClampsDaysAndRollsYears(): void
    {
        $moves = [
            ['2026-03-31', KeyType::PageUp,   '2026-02-28', '31 days clamp into a 28-day February'],
            ['2028-03-29', KeyType::PageUp,   '2028-02-29', 'leap February keeps the 29th'],
            ['2026-12-15', KeyType::PageDown, '2027-01-15', 'December rolls into next January'],
            ['2026-01-15', KeyType::PageUp,   '2025-12-15', 'January rolls back into previous December'],
            ['2026-05-31', KeyType::PageDown, '2026-06-30', 'May 31 clamps to 30-day June'],
        ];
        foreach ($moves as [$start, $key, $expect, $why]) {
            [$f, ] = Date::new('d', $start)->focus();
            [$f, ] = $f->update(new KeyMsg($key));
            $this->assertSame($expect, $f->value(), $why);
        }
    }

    public function testShiftedArrowsAreTheSameMonthStepAsPaging(): void
    {
        [$f, ] = Date::new('d', '2026-03-31')->focus();
        [$a, ] = $f->update(new KeyMsg(KeyType::Left, shift: true));
        [$b, ] = $f->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame('2026-02-28', $a->value());
        $this->assertSame($b->value(), $a->value());
        [$c, ] = $f->update(new KeyMsg(KeyType::Right, shift: true));
        $this->assertSame('2026-04-30', $c->value());
    }

    public function testHomeEndJumpToMonthEdges(): void
    {
        [$f, ] = Date::new('d', '2026-02-15')->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Home));
        $this->assertSame('2026-02-01', $f->value());
        [$f, ] = $f->update(new KeyMsg(KeyType::End));
        $this->assertSame('2026-02-28', $f->value());
        [$g, ] = Date::new('d', '2028-02-10')->focus();
        [$g, ] = $g->update(new KeyMsg(KeyType::End));
        $this->assertSame('2028-02-29', $g->value(), 'End reads the shown month length, leap included');
    }

    public function testSpaceCommitsCursorWithoutMoving(): void
    {
        $f = Date::new('d', '2026-02-15')->withValue(null);
        $this->assertNull($f->value());
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertSame('2026-02-15', $f->value(), 'Space commits the untouched cursor');
        $this->assertSame('2026-02-15', $f->cursorDate(), 'Space must not move the cursor');
    }

    public function testMovementCommits(): void
    {
        $f = Date::new('d'); // uncommitted
        $this->assertNull($f->value());
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Right));
        $this->assertNotNull($f->value(), 'moving the cursor is a commit');
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $f = Date::new('d', '2026-02-15');
        [$g, $cmd] = $f->update(new KeyMsg(KeyType::Right));
        $this->assertSame($f, $g);
        $this->assertNull($cmd);
    }

    public function testConsumesExactlyTheBoundNavigationKeys(): void
    {
        [$f, ] = Date::new('d')->focus();
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Up)), 'Up is bound by the default KeyMap — claim it');
        $this->assertTrue($f->consumes(new KeyMsg(KeyType::Down)));
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Tab)), 'Tab must keep leaving the field');
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Enter)), 'Enter stays the Form submit key');
        $this->assertFalse($f->consumes(new KeyMsg(KeyType::Right)), 'unbound keys flow through');
        $plain = Date::new('d');
        $this->assertFalse($plain->consumes(new KeyMsg(KeyType::Up)), 'unfocused fields claim nothing');
    }

    // ------------------------------------------------------------------
    // Validators
    // ------------------------------------------------------------------

    public function testValidatorClosureJudgesOnAttachAndChange(): void
    {
        $f = Date::new('d')->withValidator(
            static fn (?string $v): ?string => $v === null ? 'pick a date' : null,
        );
        $this->assertSame('pick a date', $f->getError());
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Space));
        $this->assertNull($f->getError(), 'committing clears the error');
    }

    public function testValidatorInstancePathFeedsStringForm(): void
    {
        $f = Date::new('d')->withValidator(new Required());
        $this->assertNotNull($f->getError(), 'uncommitted feeds the empty string');
        $f2 = Date::new('d', '2026-02-15')->withValidator(new Required());
        $this->assertNull($f2->getError());
    }

    public function testValidatorNullClears(): void
    {
        $f = Date::new('d')
            ->withValidator(static fn (?string $v): ?string => 'always')
            ->withValidator(null);
        $this->assertNull($f->getError());
    }

    // ------------------------------------------------------------------
    // Render snapshots
    // ------------------------------------------------------------------

    public function testSnapshotFrameFebruary2026(): void
    {
        $f = Date::new('d', '2026-02-15')->withTitle('When?');
        $this->assertSame(
            "When?\n"
            . "February 2026\n"
            . "Su Mo Tu We Th Fr Sa\n"
            . " 1  2  3  4  5  6  7\n"
            . " 8  9 10 11 12 13 14\n"
            . "\x1b[7m15\x1b[0m 16 17 18 19 20 21\n"
            . "22 23 24 25 26 27 28\n"
            . "Selected: 2026-02-15",
            $f->view(),
        );
    }

    public function testSnapshotFrameMidweekWithUncommittedLine(): void
    {
        // June 2026 starts on a Monday — exercises the leading offset cell,
        // a partial trailing week (right-aligned blanks) and the "none"
        // commit line with a live cursor.
        $f = Date::new('d', '2026-06-10')->withValue(null);
        $this->assertSame(
            "June 2026\n"
            . "Su Mo Tu We Th Fr Sa\n"
            . "    1  2  3  4  5  6\n"
            . " 7  8  9 \x1b[7m10\x1b[0m 11 12 13\n"
            . "14 15 16 17 18 19 20\n"
            . "21 22 23 24 25 26 27\n"
            . "28 29 30            \n"
            . "Selected: none",
            $f->view(),
        );
    }

    public function testValueLineRendersNonePlaceholder(): void
    {
        $f = Date::new('d', '2026-02-15')->withValue(null);
        $this->assertStringContainsString('Selected: none', $f->view());
    }

    // ------------------------------------------------------------------
    // Contract surface
    // ------------------------------------------------------------------

    public function testInterfaceAccessors(): void
    {
        $f = Date::new('d', '2026-02-15')->withTitle('T')->withDescription('D');
        $this->assertSame('d', $f->key());
        $this->assertSame('2026-02-15', $f->value());
        $this->assertSame('T', $f->getTitle());
        $this->assertSame('D', $f->getDescription());
        $this->assertFalse($f->isFocused());
        $this->assertFalse($f->skippable());
        $this->assertNull($f->getError());
        $this->assertFalse($f->isHidden([]));
    }

    public function testShortAliasesMatchLongForms(): void
    {
        $long  = Date::new('d', '2026-02-15')->withTitle('T')->withDescription('D')->view();
        $short = Date::new('d', '2026-02-15')->title('T')->desc('D')->view();
        $this->assertSame($long, $short);
        $longV  = Date::new('d')->withValidator(new Required())->getError();
        $shortV = Date::new('d')->validator(new Required())->getError();
        $this->assertSame($longV, $shortV);
    }

    public function testFieldsAreImmutable(): void
    {
        $f = Date::new('d', '2026-02-15');
        [$g, ] = $f->focus();
        $this->assertNotSame($f, $g);
        $this->assertFalse($f->isFocused());
        $this->assertTrue($g->isFocused());
    }

    public function testTraitClosuresSurviveKeystrokeRebuilds(): void
    {
        [$f, ] = Date::new('d', '2026-02-15')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true)
            ->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Right));
        [$f, ] = $f->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame('2026-03-16', $f->value(), 'sanity: both rebuilds landed');
        $this->assertSame('DYN', $f->getTitle(), 'titleFunc dropped on a navigation rebuild');
        $this->assertSame('DSCF', $f->getDescription());
        $this->assertTrue($f->isHidden([]));
    }

    public function testFocusReturnsNullCmd(): void
    {
        [$f, $cmd] = Date::new('d')->focus();
        $this->assertNull($cmd, 'the grid is self-contained — no blink/tick to schedule');
    }
}
