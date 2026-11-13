<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Forms\Field\Color;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Date;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;

/**
 * The short-alias grid guard (SF-44 / sugar-forms-064).
 *
 * The README promise — "where a field supports a knob, it also exposes a
 * short-form alias" — was prose until now. This test turns it into a fail-closed
 * contract: every public `with*` mutator on a roster class must be named in
 * {@see GRID}, whose value is either the short-form method that must exist (same
 * parameter count, so the zero-parameter `value()`/`hex()` READS never pass for a
 * mutator alias) or `null` for a dated exemption — an `@internal` wiring channel
 * or a knob whose domain short name is squatted by a required read accessor.
 *
 * A knob added in the future without a short form (and without amending this map
 * with a reason) goes red here. That is the point: the unevenness class dies.
 *
 * `withoutValue()`-style names never match the `^with[A-Z]` walk (the `o` of
 * `without` is lowercase), so non-`with` mutators ride the pre-existing
 * surface tests.
 */
final class ShortAliasMatrixTest extends TestCase
{
    /**
     * class => (with-knob => short form | null = exempt).
     *
     * Exemption reasons:
     * - pending/async channels: `@internal` form-wiring surfaces, never
     *   user-facing (withPendingAsyncCancellation, withAsyncValidator).
     * - read squat: the domain short name is a required read accessor
     *   (0 parameters) — withValue where value() is the Field contract,
     *   withKeyOverrides where keyOverrides() reads, withErrorHelp where
     *   errorHelp() reads; PHP forbids overloading, so the mutator has no
     *   short form (Date.php carries the settled comment).
     *
     * Slider maps `withValue => default` — the slider's settled domain word
     * for its committed value, established before this guard and kept.
     *
     * @var array<class-string, array<string, ?string>>
     */
    private const GRID = [
        Form::class => [
            'withTheme'        => 'theme',
            'withAccessible'   => 'accessible',
            'withShowHelp'     => 'showHelp',
            'withShowErrors'   => 'showErrors',
            'withErrorSummary' => 'errorSummary',
            'withWidth'        => 'width',
            'withHeight'       => 'height',
            'withTimeout'      => 'timeout',
            'withFocus'        => 'focus',
            'withKeyMap'       => 'keyMap',
            'withConstraints'  => 'constraints',
        ],
        Input::class => [
            'withTitle'                   => 'title',
            'withDescription'             => 'desc',
            'withPlaceholder'             => 'placeholder',
            'withValue'                   => null,
            'withPrompt'                  => 'prompt',
            'withCharLimit'               => 'charLimit',
            'withWidth'                   => 'width',
            'withKeyOverrides'            => 'keyOverrides',
            'withPendingAsyncCancellation' => null,
            'withPassword'                => 'password',
            'withPasswordMask'            => 'passwordMask',
            'withSuggestions'             => 'suggest',
            'withSuggestionsFunc'         => 'suggestionsFunc',
            'withFuzzySuggestions'        => 'fuzzy',
            'withAsyncSuggestions'        => 'async',
            'withValidator'               => 'validator',
            'withValidateOn'              => 'validateOn',
            'withValidation'              => 'validation',
            'withKeyOverrides'            => null,
            'withAsyncValidator'          => null,
            'withErrorHelp'               => null,
            'withHideFunc'                => 'hideFunc',
            'withTitleFunc'               => 'titleFunc',
            'withDescriptionFunc'         => 'descriptionFunc',
            'withReadonly'                => 'readonly',
        ],
        Text::class => [
            'withTitle'        => 'title',
            'withDescription'  => 'desc',
            'withPlaceholder'  => 'placeholder',
            'withValue'        => null,
            'withCharLimit'    => 'charLimit',
            'withWidth'        => 'width',
            'withHeight'       => 'height',
            'withKeyOverrides' => null,
            'withValidator'    => 'validator',
            'withValidateOn'   => 'validateOn',
            'withValidation'   => 'validation',
            'withAsyncValidator'          => null,
            'withErrorHelp'               => null,
            'withHideFunc'                => 'hideFunc',
            'withTitleFunc'               => 'titleFunc',
            'withDescriptionFunc'         => 'descriptionFunc',
            'withReadonly'                => 'readonly',
        ],
        Select::class => [
            'withOptions'                  => 'options',
            'withFuzzySuggestions'         => 'fuzzy',
            'withAsyncSuggestions'         => 'async',
            'withTitle'                    => 'title',
            'withDescription'              => 'desc',
            'withHeight'                   => 'height',
            'withVisibleLines'             => 'visibleLines',
            'withWidth'                    => 'width',
            'withSelectedIndex'            => 'selectedIndex',
            'withSelected'                 => 'selected',
            'withValidator'                => 'validator',
            'withEnum'                     => 'enum',
            'withPendingAsyncCancellation' => null,
            'withHideFunc'                 => 'hideFunc',
            'withTitleFunc'                => 'titleFunc',
            'withDescriptionFunc'          => 'descriptionFunc',
            'withReadonly'                 => 'readonly',
        ],
        MultiSelect::class => [
            'withOptions'       => 'options',
            'withWidth'         => 'width',
            'withTitle'         => 'title',
            'withDescription'   => 'desc',
            'withMin'           => 'min',
            'withValue'         => null,
            'withMax'           => 'max',
            'withLimit'         => 'limit',
            'withValidator'     => 'validator',
            'withErrorHelp'      => null,
            'withHideFunc'       => 'hideFunc',
            'withTitleFunc'      => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'       => 'readonly',
        ],
        Confirm::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withLabels'      => 'labels',
            'withDefault'     => 'default',
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        Slider::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withValue'       => 'default',
            'withMin'         => 'min',
            'withMax'         => 'max',
            'withStep'        => 'step',
            'withWidth'       => 'width',
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        Date::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withValue'       => null,
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        Color::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withValue'       => null,
            'withTruecolor'   => 'truecolor',
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        NumberField::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withPlaceholder' => 'placeholder',
            'withPrompt'      => 'prompt',
            'withWidth'       => 'width',
            'withValue'       => null,
            'withMin'         => 'min',
            'withMax'         => 'max',
            'withStep'        => 'step',
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        RadioPills::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withWidth'       => 'width',
            'withOptions'     => 'options',
            'withValue'       => null,
            'withValidator'   => 'validator',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
        FilePicker::class => [
            'withTitle'             => 'title',
            'withDescription'       => 'desc',
            'withShowHidden'        => 'showHidden',
            'withAllowedExtensions' => 'exts',
            'withDirAllowed'        => 'dirAllowed',
            'withFileAllowed'       => 'fileAllowed',
            'withHeight'            => 'height',
            'withValidator'         => 'validator',
            'withHideFunc'          => 'hideFunc',
            'withTitleFunc'         => 'titleFunc',
            'withDescriptionFunc'   => 'descriptionFunc',
            'withReadonly'          => 'readonly',
        ],
        Note::class => [
            'withTitle'       => 'title',
            'withDescription' => 'desc',
            'withHeight'      => 'height',
            'withNext'        => 'next',
            'withNextLabel'   => 'nextLabel',
            'withHideFunc'    => 'hideFunc',
            'withTitleFunc'   => 'titleFunc',
            'withDescriptionFunc' => 'descriptionFunc',
            'withReadonly'    => 'readonly',
        ],
    ];

    /**
     * Fail-closed discovery: every public with* mutator on a roster class —
     * class-declared or trait-supplied (HasValidation brings withValidator) —
     * must be named in the grid. A future knob without an alias and without a
     * dated exemption goes red here.
     */
    public function testEveryWithKnobIsRostered(): void
    {
        foreach (self::GRID as $class => $row) {
            $found = [];
            foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if (preg_match('/^with[A-Z]/', $m->getName()) === 1) {
                    $found[] = $m->getName();
                }
            }
            $unrostered = array_values(array_diff($found, array_keys($row)));
            self::assertSame(
                [],
                $unrostered,
                $class . ' carries with* knobs missing from the alias grid: ' . implode(', ', $unrostered),
            );
        }
    }

    /**
     * Each mapped short form exists on the class with the same parameter count
     * (arity equality keeps the zero-parameter value()/hex() READS from passing
     * for a mutator alias). Null entries are the dated exemptions: the domain
     * short name is squatted by a required read accessor or the knob is an
     * @internal wiring channel.
     */
    public function testMappedShortFormsExistWithMatchingArity(): void
    {
        foreach (self::GRID as $class => $row) {
            foreach ($row as $knob => $short) {
                $declared = (new \ReflectionClass($class))->getMethod($knob);
                if ($short === null) {
                    // Exemptions must still be a with* knob (dead-map rot check).
                    self::assertMatchesRegularExpression('/^with[A-Z]/', $knob);
                    continue;
                }
                self::assertTrue(
                    method_exists($class, $short),
                    $class . ': promised short form ' . $short . '() for ' . $knob . ' does not exist',
                );
                $alias = new ReflectionMethod($class, $short);
                self::assertTrue($alias->isPublic(), $class . '::' . $short . ' must be public');
                self::assertSame(
                    $declared->getNumberOfParameters(),
                    $alias->getNumberOfParameters(),
                    $class . '::' . $short . ' arity must mirror ' . $knob,
                );
            }
        }
    }


}
