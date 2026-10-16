<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Theme;

/**
 * Tests for {@see Group} — one page of fields in a multi-page {@see Form}.
 */
final class GroupTest extends TestCase
{
    // =========================================================================
    // Factory constructors
    // =========================================================================

    public function testNewWithVariadicFields(): void
    {
        $f1 = Input::new('a')->title('A');
        $f2 = Confirm::new('b')->title('B');
        $g = Group::new($f1, $f2);

        $this->assertCount(2, $g->fields);
        $this->assertSame('', $g->title);
        $this->assertSame('', $g->description);
        $this->assertNull($g->hideFunc);
        $this->assertTrue($g->showHelp);
        $this->assertNull($g->theme);
    }

    public function testNewReIndexesFieldsToZeroBasedList(): void
    {
        $f1 = Input::new('a');
        $f2 = Confirm::new('b');
        $f3 = Input::new('c');
        $g = Group::new($f1, $f2, $f3);

        $this->assertSame([0, 1, 2], array_keys($g->fields));
    }

    public function testFromListWithArray(): void
    {
        $f1 = Input::new('x');
        $f2 = Confirm::new('y');
        $g = Group::fromList([$f1, $f2]);

        $this->assertCount(2, $g->fields);
        $this->assertSame($f1, $g->fields[0]);
        $this->assertSame($f2, $g->fields[1]);
    }

    // =========================================================================
    // Immutable with* setters
    // =========================================================================

    public function testWithTitleReturnsNewInstance(): void
    {
        $g = Group::new(Input::new('a'));
        $g2 = $g->withTitle('Step 1');

        $this->assertNotSame($g, $g2);
        $this->assertSame('Step 1', $g2->title);
        $this->assertSame('', $g->title); // original unchanged
    }

    public function testWithDescriptionReturnsNewInstance(): void
    {
        $g = Group::new(Input::new('a'));
        $g2 = $g->withDescription('Tell us about yourself');

        $this->assertNotSame($g, $g2);
        $this->assertSame('Tell us about yourself', $g2->description);
        $this->assertSame('', $g->description);
    }

    public function testWithShowHelpFalseReturnsNewInstance(): void
    {
        $g = Group::new(Input::new('a'));
        $g2 = $g->withShowHelp(false);

        $this->assertNotSame($g, $g2);
        $this->assertFalse($g2->showHelp);
        $this->assertTrue($g->showHelp); // original unchanged
    }

    public function testWithShowHelpTrueDefault(): void
    {
        $g = Group::new(Input::new('a'));
        $this->assertTrue($g->showHelp);
    }

    public function testWithThemeSetsTheme(): void
    {
        $g = Group::new(Input::new('a'));
        $theme = Theme::ansi();
        $g2 = $g->withTheme($theme);

        $this->assertNotSame($g, $g2);
        $this->assertSame($theme, $g2->theme);
        $this->assertNull($g->theme);
    }

    public function testWithThemeNullClearsTheme(): void
    {
        $g = Group::new(Input::new('a'))->withTheme(Theme::ansi());
        $g2 = $g->withTheme(null);

        $this->assertNotSame($g, $g2);
        $this->assertNull($g2->theme);
        $this->assertInstanceOf(Theme::class, $g->theme);
    }

    public function testWithHideFuncSetsPredicate(): void
    {
        $g = Group::new(Input::new('a'));
        $fn = static fn(array $v): bool => empty($v['agree']);
        $g2 = $g->withHideFunc($fn);

        $this->assertNotSame($g, $g2);
        $this->assertNotNull($g2->hideFunc);
        $this->assertNull($g->hideFunc);
    }

    public function testWithHideFuncNullClearsPredicate(): void
    {
        $g = Group::new(Input::new('a'))->withHideFunc(
            static fn(array $v): bool => true
        );
        $g2 = $g->withHideFunc(null);

        $this->assertNotSame($g, $g2);
        $this->assertNull($g2->hideFunc);
        $this->assertNotNull($g->hideFunc);
    }

    public function testWithFieldsReplacesFieldList(): void
    {
        $f1 = Input::new('a');
        $f2 = Confirm::new('b');
        $f3 = Note::new('c');
        $g = Group::new($f1, $f2);
        $g2 = $g->withFields([$f3]);

        $this->assertNotSame($g, $g2);
        $this->assertCount(1, $g2->fields);
        $this->assertSame($f3, $g2->fields[0]);
        $this->assertCount(2, $g->fields);
    }

    // =========================================================================
    // Short-form aliases
    // =========================================================================

    public function testTitleAlias(): void
    {
        $g = Group::new(Input::new('a'))->title('Alias Title');
        $this->assertSame('Alias Title', $g->title);
    }

    public function testDescAlias(): void
    {
        $g = Group::new(Input::new('a'))->desc('Alias Desc');
        $this->assertSame('Alias Desc', $g->description);
    }

    public function testShowHelpAlias(): void
    {
        $g = Group::new(Input::new('a'))->showHelp(false);
        $this->assertFalse($g->showHelp);
    }

    public function testThemeAlias(): void
    {
        $theme = Theme::dracula();
        $g = Group::new(Input::new('a'))->theme($theme);
        $this->assertSame($theme, $g->theme);
    }

    public function testHideIfAlias(): void
    {
        $fn = static fn(array $v): bool => $v['x'] ?? false;
        $g = Group::new(Input::new('a'))->hideIf($fn);
        $this->assertNotNull($g->hideFunc);
    }

    public function testFieldsAlias(): void
    {
        $f = Note::new('note');
        $g = Group::new(Input::new('a'))->fields([$f]);
        $this->assertCount(1, $g->fields);
        $this->assertSame($f, $g->fields[0]);
    }

    // =========================================================================
    // isHidden — runtime visibility predicate
    // =========================================================================

    public function testIsHiddenReturnsFalseWhenNoPredicate(): void
    {
        $g = Group::new(Input::new('a'));
        $this->assertFalse($g->isHidden(['a' => 'value']));
        $this->assertFalse($g->isHidden([]));
    }

    public function testIsHiddenReturnsTrueWhenPredicateReturnsTrue(): void
    {
        $g = Group::new(Input::new('a'))->withHideFunc(
            static fn(array $v): bool => empty($v['skip'])
        );
        $this->assertTrue($g->isHidden([]));
        $this->assertFalse($g->isHidden(['skip' => 'yes']));
    }

    public function testIsHiddenPassesValuesToClosure(): void
    {
        $captured = null;
        $g = Group::new(Input::new('a'))->withHideFunc(
            static function (array $v) use (&$captured): bool {
                $captured = $v;
                return false;
            }
        );
        $g->isHidden(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $captured);
    }

    // =========================================================================
    // Chained with* calls are fluent
    // =========================================================================

    public function testChainedWithMethods(): void
    {
        $g = Group::new(Input::new('a'))
            ->withTitle('Title')
            ->withDescription('Description')
            ->withShowHelp(false)
            ->withTheme(Theme::plain())
            ->withHideFunc(static fn(): bool => true);

        $this->assertSame('Title', $g->title);
        $this->assertSame('Description', $g->description);
        $this->assertFalse($g->showHelp);
        $this->assertInstanceOf(Theme::class, $g->theme);
        $this->assertNotNull($g->hideFunc);
    }
}
