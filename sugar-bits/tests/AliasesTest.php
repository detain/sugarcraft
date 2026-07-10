<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test for the sugar-bits façade layer.
 *
 * A large slice of sugar-bits (TextInput, TextArea, ItemList, Viewport,
 * FilePicker, Cursor, Scrollbar, Spinner) is a thin deprecated façade: each
 * `SugarCraft\Bits\*` symbol is a `class_alias` re-export of the canonical
 * `SugarCraft\Forms\*` implementation in candy-forms.
 *
 * The behaviour of those classes is exercised by candy-forms' own suites
 * against the canonical impl. Re-running byte-identical copies here would be
 * zero incremental coverage, double CI cost, and a silent-drift hazard, so the
 * duplicated suites were deleted (workstream W17). What remains worth asserting
 * is that every alias still resolves to the expected canonical class — that is
 * this file's job.
 *
 * The map below is exhaustive over `grep -r "class_alias(" sugar-bits/src`.
 * Add a row whenever a new façade alias is introduced.
 */
final class AliasesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function aliasProvider(): array
    {
        return [
            // Cursor
            'Cursor\\Cursor'              => [\SugarCraft\Bits\Cursor\Cursor::class,              \SugarCraft\Forms\Cursor\Cursor::class],
            'Cursor\\BlinkMsg'           => [\SugarCraft\Bits\Cursor\BlinkMsg::class,           \SugarCraft\Forms\Cursor\BlinkMsg::class],
            'Cursor\\Mode'               => [\SugarCraft\Bits\Cursor\Mode::class,               \SugarCraft\Forms\Cursor\Mode::class],

            // FilePicker
            'FilePicker\\FilePicker'     => [\SugarCraft\Bits\FilePicker\FilePicker::class,     \SugarCraft\Forms\FilePicker\FilePicker::class],
            'FilePicker\\Entry'          => [\SugarCraft\Bits\FilePicker\Entry::class,          \SugarCraft\Forms\FilePicker\Entry::class],
            'FilePicker\\SortMode'       => [\SugarCraft\Bits\FilePicker\SortMode::class,       \SugarCraft\Forms\FilePicker\SortMode::class],

            // ItemList
            'ItemList\\ItemList'         => [\SugarCraft\Bits\ItemList\ItemList::class,         \SugarCraft\Forms\ItemList\ItemList::class],
            'ItemList\\Item'             => [\SugarCraft\Bits\ItemList\Item::class,             \SugarCraft\Forms\ItemList\Item::class],
            'ItemList\\StringItem'       => [\SugarCraft\Bits\ItemList\StringItem::class,       \SugarCraft\Forms\ItemList\StringItem::class],

            // Scrollbar
            'Scrollbar\\Scrollbar'       => [\SugarCraft\Bits\Scrollbar\Scrollbar::class,       \SugarCraft\Forms\Scrollbar\Scrollbar::class],
            'Scrollbar\\ScrollbarState'  => [\SugarCraft\Bits\Scrollbar\ScrollbarState::class,  \SugarCraft\Forms\Scrollbar\ScrollbarState::class],

            // Spinner
            'Spinner\\Spinner'           => [\SugarCraft\Bits\Spinner\Spinner::class,           \SugarCraft\Forms\Spinner\Spinner::class],
            'Spinner\\Style'             => [\SugarCraft\Bits\Spinner\Style::class,             \SugarCraft\Forms\Spinner\Style::class],
            'Spinner\\TickMsg'           => [\SugarCraft\Bits\Spinner\TickMsg::class,           \SugarCraft\Forms\Spinner\TickMsg::class],

            // TextArea
            'TextArea\\TextArea'          => [\SugarCraft\Bits\TextArea\TextArea::class,          \SugarCraft\Forms\TextArea\TextArea::class],
            'TextArea\\TextAreaEditedMsg' => [\SugarCraft\Bits\TextArea\TextAreaEditedMsg::class, \SugarCraft\Forms\TextArea\TextAreaEditedMsg::class],

            // TextInput
            'TextInput\\TextInput'       => [\SugarCraft\Bits\TextInput\TextInput::class,       \SugarCraft\Forms\TextInput\TextInput::class],
            'TextInput\\Styles'          => [\SugarCraft\Bits\TextInput\Styles::class,          \SugarCraft\Forms\TextInput\Styles::class],
            'TextInput\\ValidateOn'      => [\SugarCraft\Bits\TextInput\ValidateOn::class,      \SugarCraft\Forms\TextInput\ValidateOn::class],
            'TextInput\\EchoMode'        => [\SugarCraft\Bits\TextInput\EchoMode::class,        \SugarCraft\Forms\TextInput\EchoMode::class],

            // Viewport
            'Viewport\\Viewport'         => [\SugarCraft\Bits\Viewport\Viewport::class,         \SugarCraft\Forms\Viewport\Viewport::class],
            'Viewport\\ViewportTickMsg'  => [\SugarCraft\Bits\Viewport\ViewportTickMsg::class,  \SugarCraft\Forms\Viewport\ViewportTickMsg::class],
        ];
    }

    /**
     * Each sugar-bits façade name must be a live type that resolves to its
     * canonical candy-forms class. `ReflectionClass::getName()` on an alias
     * returns the *original* class name, so a broken/renamed alias target
     * (or a shim pointing at the wrong FQN) fails the `assertSame`.
     */
    #[DataProvider('aliasProvider')]
    public function testAliasResolvesToCanonicalForms(string $alias, string $target): void
    {
        $this->assertTrue(
            class_exists($alias) || interface_exists($alias) || enum_exists($alias),
            "Façade alias {$alias} is not a defined type (class_alias shim missing or not autoloadable).",
        );

        $this->assertTrue(
            class_exists($target) || interface_exists($target) || enum_exists($target),
            "Canonical target {$target} does not exist.",
        );

        // The alias and the canonical name must denote the same underlying
        // type. Reflection on the alias normalises to the canonical name, so
        // this is the load-bearing check: a wrong/renamed target fails here.
        $this->assertSame(
            $target,
            (new \ReflectionClass($alias))->getName(),
            "Façade {$alias} must resolve to {$target}.",
        );
    }
}
