<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Validator\Validator;

/**
 * E736 5.7 (r88-x4): handle slider — single-normalisation census, clamp /
 * lattice coercion edges, saturating keys, snapshot frames.
 */
final class SliderTest extends TestCase
{
    // ------------------------------------------------------------------
    // Structural law: the clamp exists at exactly ONE site (r1-q16 idiom)
    // ------------------------------------------------------------------

    public function testTheClampLivesAtExactlyOneSite(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Field/Slider.php');
        // Behavioural pins can all stay green while someone re-spreads
        // max(/min( across withers and render; the token census cannot.
        $this->assertSame(1, substr_count($src, 'max('), 'clamp re-spread — collapse into normalise() again');
        $this->assertSame(1, substr_count($src, 'min('), 'clamp re-spread — collapse into normalise() again');
    }

    // ------------------------------------------------------------------
    // Construction + coercion edges
    // ------------------------------------------------------------------

    public function testNewStartsAtMinimumWithDefaultGeometry(): void
    {
        $s = Slider::new('vol');
        $this->assertSame(0, $s->value());
        $this->assertSame(0, $s->min);
        $this->assertSame(100, $s->max);
        $this->assertSame(1, $s->step);
        $this->assertSame(20, $s->width);
        $this->assertFalse($s->isFocused());
    }

    public function testIntKnobsKeepIntValue(): void
    {
        $this->assertSame(7, Slider::new('s', 0, 10, 1, 7)->value());
    }

    public function testOutOfRangeSeedsClampInsteadOfThrowing(): void
    {
        $s = Slider::new('s', 0, 10, 1, 1000);
        $this->assertSame(10, $s->value());
        $this->assertSame(0, Slider::new('s', 0, 10, 1, -5)->value());
        $this->assertSame(10, $s->withValue(9999)->value());
        $this->assertSame(0, $s->withValue(-9999)->value());
    }

    public function testOffLatticeValuesSnapToNearestStep(): void
    {
        $this->assertSame(5,  Slider::new('s', 0, 20, 5)->withValue(7)->value());
        $this->assertSame(10, Slider::new('s', 0, 20, 5)->withValue(8)->value());
        // PHP round() ties go away from zero — pin the house convention.
        $this->assertSame(10, Slider::new('s', 0, 20, 5)->withValue(7.5)->value());
    }

    public function testFloatLatticeIsClean(): void
    {
        $s = Slider::new('v', 0, 1, 0.1, 0.35);
        $this->assertSame(0.4, $s->value(), 'naive float math would land 0.30000000000000004-ish');
        $walked = $s;
        for ($i = 0; $i < 6; $i++) {
            [$walked, ] = $walked->focus();
            [$walked, ] = $walked->update(new KeyMsg(KeyType::Right));
        }
        $this->assertSame(1.0, $walked->value());
        $this->assertSame('1', (string) $walked->value(), 'the rendered label must not print 1.0000000000000002');
    }

    public function testNonsenseGeometryThrowsAtEveryEntry(): void
    {
        foreach ([
            'min above max'  => static fn () => Slider::new('s', 10, 0),
            'min equals max' => static fn () => Slider::new('s', 5, 5),
            'zero step'      => static fn () => Slider::new('s', 0, 10, 0),
            'negative step'  => static fn () => Slider::new('s', 0, 10, -2),
        ] as $why => $make) {
            try {
                $make();
                $this->fail("expected throw: $why");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $s = Slider::new('s', 0, 10);
        $this->expectException(\InvalidArgumentException::class);
        $s->withMin(20);
    }

    public function testWithStepZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Slider::new('s', 0, 10)->withStep(0);
    }

    public function testWidthBelowOneThrowsViaBothEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Slider::new('s', 0, 10)->withWidth(0);
    }

    // ------------------------------------------------------------------
    // Keys
    // ------------------------------------------------------------------

    public function testArrowsWalkStepsAndSaturate(): void
    {
        [$s, ] = Slider::new('s', 0, 3, 1, 3)->focus();
        [$s, ] = $s->update(new KeyMsg(KeyType::Left));
        $this->assertSame(2, $s->value());
        [$s, ] = $s->update(new KeyMsg(KeyType::Right));
        [$s, ] = $s->update(new KeyMsg(KeyType::Right));
        $this->assertSame(3, $s->value(), 'walking past max saturates');
        for ($i = 0; $i < 10; $i++) {
            [$s, ] = $s->update(new KeyMsg(KeyType::Left));
        }
        $this->assertSame(0, $s->value(), 'walking past min saturates');
    }

    public function testVimCharsWalkTheSameAxis(): void
    {
        [$s, ] = Slider::new('s', 0, 10, 2, 4)->focus();
        [$s, ] = $s->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame(6, $s->value());
        [$s, ] = $s->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame(4, $s->value());
        [$g, ] = $s->update(new KeyMsg(KeyType::Char, 'l', ctrl: true));
        $this->assertSame(4, $g->value(), 'ctrl+l is the Form redraw key');
    }

    public function testHomeEnd(): void
    {
        [$s, ] = Slider::new('s', 0, 10, 1, 7)->focus();
        [$h, ] = $s->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $h->value());
        [$e, ] = $s->update(new KeyMsg(KeyType::End));
        $this->assertSame(10, $e->value());
    }

    public function testEndLandsOnTopLatticePointUnderOffLatticeMax(): void
    {
        // 0..10 step 3: the lattice tops out at 9; the constructor
        // re-normalisation is the only thing that can express that.
        [$s, ] = Slider::new('s', 0, 10, 3)->focus();
        [$s, ] = $s->update(new KeyMsg(KeyType::End));
        $this->assertSame(9, $s->value());
        $this->assertSame(3, $s->steps());
        $this->assertSame(3, $s->position());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $s = Slider::new('s', 0, 10, 1, 5);
        [$t, $cmd] = $s->update(new KeyMsg(KeyType::Right));
        $this->assertSame($s, $t);
        $this->assertNull($cmd);
    }

    public function testConsumesNothing(): void
    {
        [$s, ] = Slider::new('s')->focus();
        foreach ([KeyType::Up, KeyType::Down, KeyType::Left, KeyType::Tab, KeyType::Enter] as $type) {
            $this->assertFalse($s->consumes(new KeyMsg($type)), "Slider owns only its own axis ($type->name)");
        }
    }

    // ------------------------------------------------------------------
    // Geometry knobs re-normalise the value
    // ------------------------------------------------------------------

    public function testMovingBoundsReAlignsValue(): void
    {
        $s = Slider::new('s', 0, 100, 1, 7);
        $this->assertSame(6, $s->withMax(6)->value(), 'clamped down');
        $this->assertSame(20, $s->withMin(20)->value(), 'clamped up');
        $this->assertSame(6, $s->withStep(3)->value(), 'snapped to the new lattice');
    }

    public function testPositionAndSteps(): void
    {
        $s = Slider::new('s', 10, 20, 2.5, 15);
        $this->assertSame(2, $s->position());
        $this->assertSame(4, $s->steps());
    }

    // ------------------------------------------------------------------
    // Validators
    // ------------------------------------------------------------------

    public function testValidatorClosureSeesRawNumber(): void
    {
        $odd = static fn (int|float $v): ?string => $v % 2 === 0 ? null : 'even only';
        $s = Slider::new('s', 0, 10, 1, 3)->withValidator($odd);
        $this->assertSame('even only', $s->getError(), 'judged on attach');
        $this->assertNull($s->withValue(4)->getError(), 're-judged on change');
        $this->assertNull($s->withValue(4)->withValidator(null)->getError());
    }

    public function testValidatorInstanceIsFedDisplayString(): void
    {
        $validator = new class implements Validator {
            public function validate(string $value): true|string
            {
                return $value === '7' ? 'no sevens' : true;
            }
        };
        $s = Slider::new('s', 0, 10, 1, 7)->withValidator($validator);
        $this->assertSame('no sevens', $s->getError(), 'int 7 arrives as "7"');
        $this->assertNull($s->withValue(8)->getError());
    }

    // ------------------------------------------------------------------
    // Render snapshots
    // ------------------------------------------------------------------

    public function testSnapshotFrameMidRange(): void
    {
        $s = Slider::new('vol', 0, 10, 1, 7)->withTitle('Volume')->withWidth(10);
        $this->assertSame(
            "Volume\n[██████◆░░░] 7",
            $s->view(),
        );
    }

    public function testSnapshotFrameFullTrack(): void
    {
        $s = Slider::new('s', 0, 10, 1, 10)->withWidth(10);
        $this->assertSame('[█████████◆] 10', explode("\n", $s->view())[0]);
    }

    public function testSnapshotFrameUnitWidthHasBareHandle(): void
    {
        $s = Slider::new('s', 0, 10, 1, 0)->withWidth(1);
        $this->assertSame('[◆] 0', $s->view());
    }

    public function testValueLabelPrintsShortFloats(): void
    {
        $s = Slider::new('s', 0, 1, 0.5, 0.5)->withWidth(3);
        $this->assertSame('[█◆░] 0.5', $s->view());
    }

    public function testEmptyTitleLineIsOmitted(): void
    {
        $s = Slider::new('s', 0, 10, 1, 5)->withWidth(5);
        $this->assertStringNotContainsString("\n\n", $s->view());
        $this->assertSame('[██◆░░] 5', $s->view());
    }

    // ------------------------------------------------------------------
    // Contract surface + aliases + trait carry
    // ------------------------------------------------------------------

    public function testInterfaceAccessors(): void
    {
        $s = Slider::new('s', 0, 10, 1, 5)->withTitle('T')->withDescription('D');
        $this->assertSame('s', $s->key());
        $this->assertSame(5, $s->value());
        $this->assertSame('T', $s->getTitle());
        $this->assertSame('D', $s->getDescription());
        $this->assertNull($s->getError());
        $this->assertFalse($s->skippable());
        $this->assertFalse($s->isHidden([]));
    }

    public function testShortAliasesMatchLongForms(): void
    {
        $long  = Slider::new('s', 0, 10, 1, 7)->withTitle('T')->withDescription('D')->withWidth(10);
        $short = Slider::new('s', 0, 10, 1)->title('T')->desc('D')->width(10)->default(7);
        $this->assertSame($long->view(), $short->view());
        $this->assertSame($long->value(), $short->value());
    }

    public function testFieldsAreImmutable(): void
    {
        $s = Slider::new('s', 0, 10, 1, 5);
        [$t, ] = $s->focus();
        $this->assertNotSame($s, $t);
        $this->assertFalse($s->isFocused());
        $this->assertTrue($t->isFocused());
    }

    public function testTraitClosuresSurviveEveryRebuildPath(): void
    {
        [$s, ] = Slider::new('s', 0, 10, 1, 5)
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => $v['s'] === 5)
            ->focus();
        [$s, ] = $s->update(new KeyMsg(KeyType::Right));
        $this->assertSame(6, $s->value(), 'sanity: the walk landed');
        $this->assertSame('DYN', $s->getTitle(), 'titleFunc dropped on a key-rebuild');
        $this->assertSame('DSCF', $s->getDescription());
        $this->assertTrue($s->isHidden(['s' => 5]));
        $this->assertFalse($s->isHidden(['s' => 6]));
        // Knob rebuilds too:
        $this->assertSame('DYN', $s->withMax(20)->getTitle());
        $this->assertSame('DYN', $s->withValidator(static fn (): ?string => null)->getTitle());
    }

    public function testBlurUnfocuses(): void
    {
        [$s, ] = Slider::new('s')->focus();
        $this->assertFalse($s->blur()->isFocused());
    }
}
