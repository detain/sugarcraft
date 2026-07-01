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

    /**
     * Verifies that every class in SugarCraft\Forms\* has a corresponding
     * re-export alias in SugarCraft\Prompt\*. This prevents silent drift
     * when a new class is added to candy-forms without a sugar-prompt alias.
     *
     * @see https://github.com/sugarcraft/sugar-prompt/issues/20
     */
    public function testAllFormsClassesHavePromptAlias(): void
    {
        $formsNamespace = 'SugarCraft\\Forms\\';
        $promptNamespace = 'SugarCraft\\Prompt\\';

        // Walk all classes in the Forms namespace using reflection.
        $formsClasses = [];
        $formsDir = dirname(__DIR__) . '/vendor/sugarcraft/candy-forms/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($formsDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($formsDir) + 1);
            $className = $formsNamespace . str_replace('/', '\\', substr($relativePath, 0, -4));
            if (!class_exists($className) && !interface_exists($className)) {
                continue;
            }
            $formsClasses[] = $className;
        }

        $missing = [];
        foreach ($formsClasses as $formsClass) {
            // Derive the expected Prompt alias path.
            $shortName = ltrim(substr($formsClass, strlen($formsNamespace)), '\\');
            $promptAlias = $promptNamespace . $shortName;

            // Skip if the Prompt class doesn't exist yet — this test ensures it should.
            if (!class_exists($promptAlias) && !interface_exists($promptAlias)) {
                $missing[] = $shortName;
                continue;
            }

            // Verify it aliases back to the Forms class.
            $this->assertSame(
                $formsClass,
                (new \ReflectionClass($promptAlias))->getName(),
                "{$promptAlias} should alias {$formsClass}"
            );
        }

        $this->assertEmpty(
            $missing,
            'New candy-forms classes found without sugar-prompt re-exports: ' . implode(', ', $missing)
        );
    }
}
