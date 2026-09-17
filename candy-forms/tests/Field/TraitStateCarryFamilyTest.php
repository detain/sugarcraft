<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;

/**
 * E741 family guard (round 85, lane u4): the HasHideFunc / HasDynamicLabels
 * closures live OUTSIDE every Field's private constructor, so each class's
 * `new self(...)` rebuild paths silently dropped them until the shared
 * CarriesNonCtorState carrier was adopted (mirrors the E736-F1 Confirm fix).
 *
 * Three arms: per-class survival over each rebuild path; a reflection
 * "exactly the three label slots" census so a NEW trait slot without a
 * carrier leg fails closed; and a src-file census so a NEW label-trait user
 * that forgets the carrier fails closed.
 */
final class TraitStateCarryFamilyTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function carryProbeProvider(): array
    {
        return [
            'Input mutate'        => [Input::class, 'mutate'],
            'Input focus/blur'    => [Input::class, 'focusBlur'],
            'Input keystroke'     => [Input::class, 'keystroke'],
            'Text mutate'         => [Text::class, 'mutate'],
            'Text focus/blur'     => [Text::class, 'focusBlur'],
            'Text keystroke'      => [Text::class, 'keystroke'],
            'Select mutate'       => [Select::class, 'mutate'],
            'Select focus/blur'   => [Select::class, 'focusBlur'],
            'MultiSelect mutate'  => [MultiSelect::class, 'mutate'],
            'MultiSelect toggle'  => [MultiSelect::class, 'keystroke'],
            'Note mutate'         => [Note::class, 'mutate'],
            'Note focus/blur'     => [Note::class, 'focusBlur'],
            'FilePicker mutate'   => [FilePicker::class, 'mutate'],
            'FilePicker focus/blur' => [FilePicker::class, 'focusBlur'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('carryProbeProvider')]
    public function testLabelClosuresSurviveEachRebuildPath(string $class, string $path): void
    {
        $field = $class::new('k')
            ->withTitleFunc(static fn (): string => 'DYN')
            ->withDescriptionFunc(static fn (): string => 'DSCF')
            ->withHideFunc(static fn (array $v): bool => true);

        $rebuilt = self::carryProbeRebuild($field, $path);

        $this->assertSame('DYN', $rebuilt->getTitle(), "$class/$path dropped titleFunc");
        $this->assertSame('DSCF', $rebuilt->getDescription(), "$class/$path dropped descriptionFunc");
        $this->assertTrue($rebuilt->isHidden([]), "$class/$path dropped hideFunc");
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function carryProbeClasses(): array
    {
        return [
            Input::class        => [Input::class],
            Text::class         => [Text::class],
            Select::class         => [Select::class],
            MultiSelect::class  => [MultiSelect::class],
            Note::class         => [Note::class],
            FilePicker::class   => [FilePicker::class],
        ];
    }

    /**
     * The non-ctor surface of every label-trait Field is EXACTLY the three
     * trait-declared closures. A fourth such slot (new trait or new private
     * non-ctor property) must land in CarriesNonCtorState too — this census
     * reddens first so the drop can never ship silently again.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('carryProbeClasses')]
    public function testNonCtorStateIsExactlyTheThreeLabelClosures(string $class): void
    {
        $ref = new \ReflectionClass($class);
        $ctorParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ref->getConstructor()->getParameters(),
        );
        // A property is "non-ctor" when it is neither public (promoted) nor
        // named by a constructor parameter — trait composition reports the
        // USING class as declaring class, so declaration-site is not a usable
        // signal here; ctor-name coverage is.
        // Slots that are NOT state at all: never assigned after their default.
        // fuzzyFilterText is a dead declaration (zero reads/writes tree-wide)
        // kept because the campaign STOP list forbids removing dormant code.
        // The assignment census below fails closed if it ever becomes live.
        $dead = [Select::class => ['fuzzyFilterText']];
        // Non-ctor slots that carry NO user state: deterministic machinery
        // every rebuild re-seeds unconditionally from the constructor body,
        // so there is nothing for the carrier to preserve. E736-F2/2.2 added
        // Select::$matcher (stateless Smith-Waterman scorer); the seed
        // assertion below fails closed if it ever stops being unconditional.
        $internal = [Select::class => ['matcher']];
        $nonCtor = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->isPublic() || $prop->isStatic()) {
                continue;
            }
            if (in_array($prop->getName(), $ctorParams, true)) {
                continue;
            }
            if (in_array($prop->getName(), $dead[$class] ?? [], true)) {
                $src = (string) file_get_contents($ref->getFileName());
                $this->assertStringNotContainsStringLive(
                    $src,
                    '$this->' . $prop->getName() . ' =',
                    $class . '::$' . $prop->getName() . ' was declared dead-non-ctor but is now assigned — carry it (E741) or re-judge the roster',
                );
                continue;
            }
            if (in_array($prop->getName(), $internal[$class] ?? [], true)) {
                $src = (string) file_get_contents($ref->getFileName());
                $this->assertStringContainsString(
                    '$this->' . $prop->getName() . ' = new SmithWatermanMatcher();',
                    $src,
                    $class . '::$' . $prop->getName() . ' was allow-listed as unconditional ctor-body machinery but is no longer seeded that way — it became state worth carrying (E741)',
                );
                continue;
            }
            $nonCtor[] = $prop->getName();
        }
        sort($nonCtor);
        $this->assertSame(['descriptionFunc', 'hideFunc', 'titleFunc'], $nonCtor);
    }

    /**
     * `str_contains` with the argument order pinned + a negation message:
     * passes when $needle is absent (the dead slot claim stays true).
     */
    private static function assertStringNotContainsStringLive(string $haystack, string $needle, string $message): void
    {
        self::assertFalse(str_contains($haystack, $needle), $message);
    }

    /**
     * Fail-closed roster: every src/Field class that adopts HasHideFunc must
     * also route its rebuilds through a carryNonCtorState() carrier (its own
     * — Confirm's historical private — or the shared trait's).
     */
    public function testEveryLabelTraitFieldCarriesItsTraitState(): void
    {
        $files = glob(\dirname(__DIR__, 2) . '/src/Field/*.php');
        $this->assertNotSame([], $files, 'src/Field glob must enumerate files');
        $adopters = 0;
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/^    use HasHideFunc;/m', $src)) {
                continue;
            }
            $adopters++;
            $this->assertStringContainsString(
                'carryNonCtorState(',
                $src,
                basename($file) . ' adopts the label traits but rebuilds without a carrier (E741)',
            );
        }
        $this->assertSame(7, $adopters, 'label-trait adopter roster changed — re-judge the carrier census');
    }

    /**
     * The shared carrier assigns every label slot (guards a leg being
     * deleted while the census above still sees the method name).
     */
    public function testTheSharedCarrierCopiesAllThreeSlots(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\SugarCraft\Forms\CarriesNonCtorState::class))->getFileName()
        );
        foreach (['hideFunc', 'titleFunc', 'descriptionFunc'] as $slot) {
            $this->assertMatchesRegularExpression('/\$next->' . $slot . '\s*=\s*\$this->' . $slot . ';/', $src);
        }
    }

    /**
     * Drive one rebuild path per row. `keystroke` for the two text widgets
     * runs a real update(); for MultiSelect it toggles an option (the
     * constraint-error mutate path). `focusBlur` covers focus() plus the
     * blur() that Input/Text may extend with validation.
     */
    private static function carryProbeRebuild(object $field, string $path): object
    {
        return match ($path) {
            'mutate'    => $field->withTitle('T'),
            'focusBlur' => (static function () use ($field) {
                [$focused, ] = $field->focus();
                return $focused->blur();
            })(),
            'keystroke' => (static function () use ($field) {
                if ($field instanceof MultiSelect) {
                    [$f, ] = $field->withOptions('a', 'b')->focus();
                    [$f, ] = $f->update(new KeyMsg(KeyType::Char, ' '));
                    return $f;
                }
                [$f, ] = $field->focus();
                [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
                [$f, ] = $f->update(new KeyMsg(KeyType::Backspace));
                return $f;
            })(),
            default => throw new \LogicException("unknown carry probe path: $path"),
        };
    }
}
