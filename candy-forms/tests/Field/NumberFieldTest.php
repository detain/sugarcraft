<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Lang;

/**
 * crush_media W3.3: bounded NumberField — coercion matrix (boundaries, step
 * lattice, int64 exactness, garbage refusals) and the behaviour key table.
 * normalise()/Slider stays the ORACLE for every lattice expectation on the
 * float overlap; the int path is pinned equal to it there, and digit-exact
 * beyond 2^53 where a float would corrupt the seed.
 */
final class NumberFieldTest extends TestCase
{
    private function nfAt(int|float $min = 0, int|float $max = 100, int|float $step = 1, int|float|null $init = null): NumberField
    {
        return NumberField::new('n', $min, $max, $step, $init);
    }

    private function focusedField(NumberField $f): NumberField
    {
        [$focused, ] = $f->focus();
        return $focused;
    }

    /** Type a draft through the real key channel (Char rune per keystroke). */
    private function typeDraft(NumberField $f, string $text): NumberField
    {
        foreach (mb_str_split($text) as $rune) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Char, $rune));
        }
        return $f;
    }

    private function pressKey(NumberField $f, KeyType $type): NumberField
    {
        [$next, ] = $f->update(new KeyMsg($type));
        return $next;
    }

    // ------------------------------------------------------------------
    // Coercion matrix
    // ------------------------------------------------------------------

    public function testSeedCoercesOntoLatticeAndIntoTheDraft(): void
    {
        self::assertSame(45, $this->nfAt(0, 100, 5, 47)->value());
        self::assertSame('45', $this->nfAt(0, 100, 5, 47)->draft(), 'the draft mirrors the committed display');
        self::assertSame(0, $this->nfAt(0, 100, 5, -10)->value(), 'below min clamps up');
        self::assertSame(98, $this->nfAt(0, 100, 7, 999)->value(), 'above max clamps down to the top lattice point');
        self::assertSame(100, $this->nfAt(0, 100, 5, 100)->value(), 'max on the lattice survives');
    }

    public function testNegativeLatticeRunsFromMin(): void
    {
        $f = $this->nfAt(-10, -1, 3, -11);
        self::assertSame(-10, $f->value());
        self::assertSame(-7, $this->nfAt(-10, -1, 3, -8)->value(), 'half-up from min: -8 -> -7');
    }

    public function testFloatLatticeKeepsShortestRepr(): void
    {
        $f = $this->nfAt(0, 1, 0.25, 0.3);
        self::assertSame(0.25, $f->value());
        self::assertTrue(is_float($f->value()));
        self::assertSame('0.25', $f->draft());
    }

    public function testIntPathAndSliderEngineAgreeOnTheOverlap(): void
    {
        // The documented two-engine ruling: int lattice walks exactly where
        // Slider::normalise walks for in-range int seeds.
        for ($raw = -3; $raw <= 23; ++$raw) {
            self::assertSame(
                Slider::new('n', 0, 20, 2)->withValue($raw)->value,
                $this->nfAt(0, 20, 2, $raw)->value(),
                "engine disagreement at raw=$raw",
            );
        }
    }

    public function testTieSnapsUpAwayFromMin(): void
    {
        // offset 3 on step 2 sits exactly halfway between 2 and 4.
        self::assertSame(4, $this->nfAt(0, 10, 2, 3)->value());
        self::assertSame(4, Slider::new('n', 0, 10, 2)->withValue(3)->value, 'same rule as the Slider EPS law');
    }

    // ------------------------------------------------------------------
    // The int64 law
    // ------------------------------------------------------------------

    public function testInt64SeedRoundTripsDigitExact(): void
    {
        $seed = 99999999999999999; // 17 nines: a float would render 1.0E+17
        $viaSeed = NumberField::new('seed', 0, PHP_INT_MAX, 1, $seed);
        self::assertSame($seed, $viaSeed->value());
        self::assertTrue(is_int($viaSeed->value()), 'seed must stay int, never widen to float');
        self::assertSame('99999999999999999', $viaSeed->draft());

        $f = $this->pressKey($this->typeDraft($this->focusedField(NumberField::new('seed', 0, PHP_INT_MAX, 1)), '99999999999999999'), KeyType::Enter);
        self::assertNull($f->getError());
        self::assertSame($seed, $f->value());

        $g = $this->pressKey($this->typeDraft($this->focusedField(NumberField::new('seed', 0, PHP_INT_MAX, 1)), '9223372036854775807'), KeyType::Enter);
        self::assertSame(9223372036854775807, $g->value());
        self::assertTrue(is_int($g->value()));
        self::assertSame('9223372036854775807', $g->draft());
    }

    public function testBeyondInt64IsRefusedNotGuessed(): void
    {
        $f = $this->focusedField($this->nfAt(0, PHP_INT_MAX, 1, 42));
        $f = $this->typeDraft($f, '9223372036854775808');
        $f = $this->pressKey($f, KeyType::Enter);
        self::assertSame(42, $f->value(), 'an overflowing draft never moves the committed value');
        self::assertSame(Lang::t('number.out_of_range'), $f->getError());

        $huge = $this->pressKey($this->typeDraft($this->focusedField($this->nfAt(0, PHP_INT_MAX, 1, 42)), str_repeat('9', 25)), KeyType::Enter);
        self::assertSame(42, $huge->value());
        self::assertSame(Lang::t('number.out_of_range'), $huge->getError());
    }

    public function testLeadingZerosDoNotFakeAnOverflow(): void
    {
        $f = $this->focusedField($this->nfAt(0, PHP_INT_MAX, 1));
        $f = $this->pressKey($this->typeDraft($f, '00000099999999999999999'), KeyType::Enter);
        self::assertSame(99999999999999999, $f->value());
    }

    // ------------------------------------------------------------------
    // Garbage refusals (locale-safe parse)
    // ------------------------------------------------------------------

    public function testThousandsSeparatorsRefusedWithMessage(): void
    {
        $f = $this->focusedField($this->nfAt(0, 100000, 1, 7));
        $f = $this->pressKey($this->typeDraft($f, '1,000'), KeyType::Enter);
        self::assertSame(7, $f->value(), 'the separator is never guessed away');
        self::assertSame(Lang::t('number.separators_refused'), $f->getError());
    }

    public function testNonNumericDraftsRefused(): void
    {
        foreach (['abc', '1.2.3', '--5', '5%', '0x10'] as $draft) {
            $f = $this->focusedField($this->nfAt(0, 100, 1, 7));
            $f = $this->pressKey($this->typeDraft($f, $draft), KeyType::Enter);
            self::assertSame(7, $f->value(), "draft '$draft' must not commit");
            self::assertNotNull($f->getError(), "draft '$draft' must surface an error");
        }
    }

    public function testExponentAndLeadingDotFloatFormsParse(): void
    {
        $f = $this->focusedField($this->nfAt(0, 100, 1));
        self::assertSame(100, $this->pressKey($this->typeDraft($f, '1e2'), KeyType::Enter)->value());
        $g = $this->focusedField($this->nfAt(0, 100, 1));
        self::assertSame(1, $this->pressKey($this->typeDraft($g, '.5'), KeyType::Enter)->value(), 'half-up onto the int lattice');
        $h = $this->focusedField($this->nfAt(0, 1, 0.5));
        self::assertSame(0.5, $this->pressKey($this->typeDraft($h, '.5'), KeyType::Enter)->value());
    }

    public function testInfiniteFloatRefused(): void
    {
        $f = $this->focusedField($this->nfAt(0, 100, 1, 7));
        $f = $this->pressKey($this->typeDraft($f, '1e999'), KeyType::Enter);
        self::assertSame(7, $f->value());
        self::assertSame(Lang::t('number.out_of_range'), $f->getError());
    }

    // ------------------------------------------------------------------
    // Empty is not zero
    // ------------------------------------------------------------------

    public function testEmptyDraftNeverFabricatesZero(): void
    {
        $unset = $this->pressKey($this->focusedField($this->nfAt()), KeyType::Enter);
        self::assertFalse($unset->hasValue(), 'committing an empty never-set box stays never-set');
        self::assertNull($unset->value());

        $f = $this->focusedField($this->nfAt(0, 100, 1, 42));
        $f = $this->pressKey($this->pressKey($this->pressKey($f, KeyType::Backspace), KeyType::Backspace), KeyType::Backspace);
        self::assertSame('', $f->draft());
        $f = $this->pressKey($f, KeyType::Enter);
        self::assertSame(42, $f->value(), 'clearing the box and committing keeps the last good value — empty != 0');
        self::assertSame('42', $f->draft(), 'and the display re-draws it');
    }

    public function testWithoutValueReturnsToNeverSet(): void
    {
        $f = $this->nfAt(0, 100, 1, 42)->withoutValue();
        self::assertFalse($f->hasValue());
        self::assertNull($f->value());
        self::assertSame('', $f->draft());
        // The step ladder then starts at min again.
        self::assertSame(2, $this->pressKey($this->focusedField($this->nfAt(0, 100, 2)->withoutValue()), KeyType::Up)->value());
    }

    // ------------------------------------------------------------------
    // Behaviour key table
    // ------------------------------------------------------------------

    public function testSteppingLadderKeys(): void
    {
        $f = $this->focusedField($this->nfAt(0, 10, 2));
        $f = $this->pressKey($f, KeyType::Up);
        self::assertSame(2, $f->value(), 'never-set ladder starts at min and steps once');
        $f = $this->pressKey($f, KeyType::Up);
        self::assertSame(4, $f->value());
        $f = $this->pressKey($f, KeyType::Down);
        self::assertSame(2, $f->value());
        $f = $this->pressKey($f, KeyType::PageUp);
        self::assertSame(10, $f->value(), 'ten steps saturate on the top lattice point');
        $f = $this->pressKey($f, KeyType::Home);
        self::assertSame(0, $f->value());
        $f = $this->pressKey($f, KeyType::End);
        self::assertSame(10, $f->value());
        $f = $this->pressKey($f, KeyType::PageDown);
        self::assertSame(0, $f->value());
        // Every step also keeps the draft truthful.
        self::assertSame('0', $f->draft());
    }

    public function testEnterAndBlurCommitTheDraft(): void
    {
        $f = $this->pressKey($this->typeDraft($this->focusedField($this->nfAt(0, 100, 5)), '47'), KeyType::Enter);
        self::assertSame(45, $f->value());
        self::assertSame('45', $f->draft());

        $g = $this->typeDraft($this->focusedField($this->nfAt(0, 100, 5)), '47');
        /** @var NumberField $blurred */
        $blurred = $g->blur();
        self::assertSame(45, $blurred->value());
        self::assertFalse($blurred->isFocused());
    }

    public function testTypedEditClearsAStaleParseError(): void
    {
        $f = $this->pressKey($this->typeDraft($this->focusedField($this->nfAt(0, 100, 1, 7)), 'abc'), KeyType::Enter);
        self::assertNotNull($f->getError());
        $f = $this->pressKey($this->pressKey($this->pressKey($f, KeyType::Backspace), KeyType::Backspace), KeyType::Backspace);
        self::assertNull($f->getError(), 'the error must not outlive the draft that caused it');
        self::assertSame(7, $f->value(), 'but the value still waits for the next commit');
    }

    public function testConsumesClaimsOnlyTheSteppingArrows(): void
    {
        $f = $this->focusedField($this->nfAt());
        self::assertTrue($f->consumes(new KeyMsg(KeyType::Up)));
        self::assertTrue($f->consumes(new KeyMsg(KeyType::Down)));
        foreach ([KeyType::Enter, KeyType::Tab, KeyType::Left, KeyType::Home, KeyType::PageUp] as $type) {
            self::assertFalse($f->consumes(new KeyMsg($type)), "NumberField must leave $type->name to the Form");
        }
        self::assertFalse($this->nfAt()->consumes(new KeyMsg(KeyType::Up)), 'unfocused releases the claim');
        self::assertFalse($f->withReadonly(true)->consumes(new KeyMsg(KeyType::Up)), 'read-only releases the claim');
    }

    public function testReadonlyRefusesTheWholeMutatingSet(): void
    {
        $ro = $this->nfAt(0, 100, 1, 42)->withTitle('Seed')->withReadonly(true);
        $ro = $this->focusedField($ro);
        [$same, $cmd] = $ro->update(new KeyMsg(KeyType::Up));
        self::assertSame($ro, $same);
        self::assertNull($cmd);
        [$pasted, ] = $ro->update(new PasteMsg('7'));
        self::assertSame($ro, $pasted, 'the paste door closes too (round-90 family law)');
        [$typed, ] = $ro->update(new KeyMsg(KeyType::Char, '9'));
        self::assertSame($ro, $typed);
        self::assertStringContainsString('(read-only)', $ro->view());
    }

    public function testGeometryWithersRecoerceTheValueAgainstTheSliderOracle(): void
    {
        $f = $this->nfAt(0, 100, 1, 50)->withMax(30);
        self::assertSame(Slider::new('n', 0, 30, 1)->withValue(50)->value, $f->value());
        $g = $f->withStep(7);
        self::assertSame(Slider::new('n', 0, 30, 7)->withValue(30)->value, $g->value());
        $h = $g->withMin(29);
        self::assertSame(Slider::new('n', 29, 30, 7)->withValue(28)->value, $h->value());
    }

    public function testRevalidateJudgesTheDraftAndIsIdempotent(): void
    {
        $clean = $this->nfAt(0, 100, 5, 45);
        self::assertSame($clean, $clean->revalidate(), 'draft matching the display commits to identity');

        $dirty = $this->typeDraft($this->focusedField($this->nfAt(0, 100, 5)), '47');
        /** @var NumberField $judged */
        $judged = $dirty->revalidate();
        self::assertSame(45, $judged->value(), 'the submit gate commits a valid draft');

        $bad = $this->typeDraft($this->focusedField($this->nfAt(0, 100, 1, 7)), 'zz');
        /** @var NumberField $blocked */
        $blocked = $bad->revalidate();
        self::assertSame(7, $blocked->value());
        self::assertNotNull($blocked->getError(), 'an invalid draft blocks submit with its error');
    }

    public function testEveryTransitionIsPure(): void
    {
        $base = $this->nfAt(0, 100, 5, 45);
        $moved = $this->pressKey($this->focusedField($base), KeyType::Up);
        self::assertNotSame($base, $moved);
        self::assertSame(45, $base->value(), 'the source instance never moves');
        self::assertSame(50, $moved->value());
        self::assertSame(45, $base->withTitle('x')->value(), 'labels never move the committed number');
    }

    public function testViewMirrorsTheInputShape(): void
    {
        $f = $this->nfAt(0, 100, 1, 42)->withTitle('Seed')->withDescription('int64 seed');
        $lines = explode("\n", $f->view());
        self::assertSame('Seed', $lines[0]);
        self::assertSame('int64 seed', $lines[1]);
        self::assertStringContainsString('42', $lines[2], 'the draft line carries the committed display');
        self::assertCount(3, $lines, 'no error line while clean');

        $bad = $this->pressKey($this->typeDraft($this->focusedField($f), '!!'), KeyType::Enter);
        $badLines = explode("\n", $bad->view());
        self::assertStringStartsWith('! ', $badLines[3], 'the parse error renders under the draft like Input does');
    }

    public function testNonsenseGeometryThrowsAtTheBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('number.min_below_max'));
        NumberField::new('n', 10, 10);
    }

    public function testNonPositiveStepThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('number.step_positive'));
        NumberField::new('n', 0, 10, 0);
    }

    public function testIntSpanOverflowThrowsAtTheBoundary(): void
    {
        // R6-M1: min=-1..max=PHP_INT_MAX is a legal-looking all-int geometry
        // whose span (2^63) overflows int — the exact walk could never run,
        // and the float delegate corrupts int64 seeds to float(-9.22e18).
        // The ctor refuses the shape instead of accepting an unhonorable law.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('number.span_overflow'));
        NumberField::new('s', -1, PHP_INT_MAX, 1, PHP_INT_MAX);
    }
}
