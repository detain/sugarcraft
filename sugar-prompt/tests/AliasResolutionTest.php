<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies every class_alias re-export in sugar-prompt resolves to its
 * documented canonical target. This guards against accidental re-divergence
 * if someone modifies a source file without updating the alias.
 */
final class AliasResolutionTest extends TestCase
{
    /**
     * @dataProvider aliasProvider
     * @template T
     * @param class-string<T> $promptFqn  the SugarCraft\Prompt\* alias FQN
     * @param class-string<T> $expectedTarget  the canonical Forms\* / Fuzzy\* target
     */
    public function testAliasPointsToExpectedTarget(string $promptFqn, string $expectedTarget): void
    {
        $this->assertTrue(
            class_exists($promptFqn) || interface_exists($promptFqn),
            "Alias {$promptFqn} does not exist — ensure the class_alias call is reachable."
        );
        $this->assertSame(
            $expectedTarget,
            (new \ReflectionClass($promptFqn))->getName(),
            "{$promptFqn} no longer aliases {$expectedTarget} — possible re-divergence."
        );
    }

    /**
     * Returns every alias FQN and its expected target.
     * Derived from reading each source file's class_alias() argument.
     *
     * @return iterable<array{string, string}>
     */
    public static function aliasProvider(): iterable
    {
        // Root-level shims
        yield 'Form' => [\SugarCraft\Prompt\Form::class, \SugarCraft\Forms\Form::class];
        yield 'Group' => [\SugarCraft\Prompt\Group::class, \SugarCraft\Forms\Group::class];
        yield 'Theme' => [\SugarCraft\Prompt\Theme::class, \SugarCraft\Forms\Theme::class];
        yield 'KeyMap' => [\SugarCraft\Prompt\KeyMap::class, \SugarCraft\Forms\KeyMap::class];
        yield 'HasDynamicLabels' => [\SugarCraft\Prompt\HasDynamicLabels::class, \SugarCraft\Forms\HasDynamicLabels::class];
        yield 'HasHideFunc' => [\SugarCraft\Prompt\HasHideFunc::class, \SugarCraft\Forms\HasHideFunc::class];

        // Field/ subnamespace aliases
        // Note: Field\Field alias removed (cannot class_alias an interface)
        yield 'Field\Confirm' => [\SugarCraft\Prompt\Field\Confirm::class, \SugarCraft\Forms\Field\Confirm::class];
        yield 'Field\FilePicker' => [\SugarCraft\Prompt\Field\FilePicker::class, \SugarCraft\Forms\Field\FilePicker::class];
        yield 'Field\Input' => [\SugarCraft\Prompt\Field\Input::class, \SugarCraft\Forms\Field\Input::class];
        yield 'Field\MultiSelect' => [\SugarCraft\Prompt\Field\MultiSelect::class, \SugarCraft\Forms\Field\MultiSelect::class];
        yield 'Field\Note' => [\SugarCraft\Prompt\Field\Note::class, \SugarCraft\Forms\Field\Note::class];
        yield 'Field\Select' => [\SugarCraft\Prompt\Field\Select::class, \SugarCraft\Forms\Field\Select::class];
        yield 'Field\Text' => [\SugarCraft\Prompt\Field\Text::class, \SugarCraft\Forms\Field\Text::class];

        // Validator/ subnamespace aliases
        yield 'Validator\Validator' => [\SugarCraft\Prompt\Validator\Validator::class, \SugarCraft\Forms\Validator\Validator::class];
        yield 'Validator\Email' => [\SugarCraft\Prompt\Validator\Email::class, \SugarCraft\Forms\Validator\Email::class];
        yield 'Validator\MaxLength' => [\SugarCraft\Prompt\Validator\MaxLength::class, \SugarCraft\Forms\Validator\MaxLength::class];
        yield 'Validator\MinLength' => [\SugarCraft\Prompt\Validator\MinLength::class, \SugarCraft\Forms\Validator\MinLength::class];
        yield 'Validator\Pattern' => [\SugarCraft\Prompt\Validator\Pattern::class, \SugarCraft\Forms\Validator\Pattern::class];
        yield 'Validator\Required' => [\SugarCraft\Prompt\Validator\Required::class, \SugarCraft\Forms\Validator\Required::class];

        // Fuzzy/ subnamespace alias
        yield 'Fuzzy\FuzzyMatcher' => [\SugarCraft\Prompt\Fuzzy\FuzzyMatcher::class, \SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher::class];
    }
}
