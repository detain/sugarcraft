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
     * @param bool $isTrait whether the target is a trait (cannot be class_alias'd)
     */
    public function testAliasPointsToExpectedTarget(string $promptFqn, string $expectedTarget, bool $isTrait = false): void
    {
        $this->assertTrue(
            class_exists($promptFqn) || interface_exists($promptFqn),
            "Alias {$promptFqn} does not exist — ensure the class_alias call is reachable."
        );

        // Traits cannot be aliased via class_alias() in PHP; the stub class exists
        // for backward compatibility but does not actually re-export the trait.
        if ($isTrait) {
            $this->markTestSkipped("Trait {$expectedTarget} cannot be class_alias'd — see CALIBER_LEARNINGS.md");
            return;
        }

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
        // Traits cannot be class_alias'd — stubs exist for backward compatibility only.
        yield 'HasDynamicLabels' => [\SugarCraft\Prompt\HasDynamicLabels::class, \SugarCraft\Forms\HasDynamicLabels::class, true];
        yield 'HasHideFunc' => [\SugarCraft\Prompt\HasHideFunc::class, \SugarCraft\Forms\HasHideFunc::class, true];

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
            // Skip traits — PHP class_alias() cannot alias traits; stub classes
            // exist for BC but do not functionally re-export the trait.
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                continue;
            }
            if (trait_exists($className)) {
                continue; // Traits cannot be aliased; handled by stub classes.
            }
            $formsClasses[] = $className;
        }

        // Namespaces that sugar-prompt actively uses — missing re-exports here SHOULD fail.
        $activeNamespaces = [
            'Field\\',
            'Validator\\',
            'Form\\',
            'Group\\',
            'Theme\\',
            'KeyMap\\',
            'HasDynamicLabels\\',
            'HasHideFunc\\',
        ];

        // Namespaces with pre-existing missing re-exports (audit debt).
        // These are not used in sugar-prompt and are skipped to avoid noise.
        $preExistingDebt = [
            'Viewport\\',
            'TextArea\\',
            'Spinner\\',     // sugar-prompt has its own Spinner implementation
            'Scrollbar\\',
            'ItemList\\',
            'FilePicker\\',  // sugar-prompt has its own FilePicker implementation
            'Vim\\',         // not used in sugar-prompt
            'TextInput\\',
            'Cursor\\',
            'Util\\',        // RenderSafe — not used in sugar-prompt
            'Lang',          // i18n utility — not used in sugar-prompt re-exports
        ];

        $missing = [];
        foreach ($formsClasses as $formsClass) {
            // Derive the expected Prompt alias path.
            $shortName = ltrim(substr($formsClass, strlen($formsNamespace)), '\\');
            $promptAlias = $promptNamespace . $shortName;

            // Skip if the Prompt class doesn't exist yet.
            if (!class_exists($promptAlias) && !interface_exists($promptAlias)) {
                // Check if this is from a pre-existing debt namespace
                $isPreExistingDebt = false;
                foreach ($preExistingDebt as $ns) {
                    if (str_starts_with($shortName, $ns)) {
                        $isPreExistingDebt = true;
                        break;
                    }
                }
                if (!$isPreExistingDebt) {
                    $missing[] = $shortName;
                }
                continue;
            }

            // Verify it aliases back to the Forms class.
            // Skip Forms\Fuzzy\* — deprecated in favor of candy-fuzzy.
            if (str_starts_with($shortName, 'Fuzzy\\')) {
                continue;
            }
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
