<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;

/**
 * Verifies short-form aliases produce rendered output byte-identical to
 * the upstream-mirroring `with*` long forms. Comparing render output
 * (rather than instance equality) sidesteps internal-counter noise like
 * `Cursor::$id` while still asserting end-user-visible parity.
 */
final class ShortAliasesTest extends TestCase
{
    public function testInputAliases(): void
    {
        $long = Input::new('k')
            ->withTitle('T')->withDescription('D')->withPlaceholder('P')
            ->withCharLimit(8)->withWidth(20)->view();
        $short = Input::new('k')
            ->title('T')->desc('D')->placeholder('P')
            ->charLimit(8)->width(20)->view();
        $this->assertSame($long, $short);
    }

    public function testTextAliases(): void
    {
        $long = Text::new('k')
            ->withTitle('T')->withDescription('D')->withPlaceholder('P')
            ->withWidth(40)->withHeight(5)->view();
        $short = Text::new('k')
            ->title('T')->desc('D')->placeholder('P')
            ->width(40)->height(5)->view();
        $this->assertSame($long, $short);
    }

    public function testConfirmAliases(): void
    {
        $long = Confirm::new('k', false)
            ->withTitle('T')->withDescription('D')
            ->withLabels('Yep', 'Nope')->withDefault(true)->view();
        $short = Confirm::new('k', false)
            ->title('T')->desc('D')
            ->labels('Yep', 'Nope')->default(true)->view();
        $this->assertSame($long, $short);
    }

    public function testSelectAliases(): void
    {
        $long  = Select::new('k')->withOptions('a', 'b')->withTitle('T')->withDescription('D')->withHeight(3)->view();
        $short = Select::new('k')->options('a', 'b')->title('T')->desc('D')->height(3)->view();
        $this->assertSame($long, $short);
    }

    public function testMultiSelectAliases(): void
    {
        $long  = MultiSelect::new('k')->withOptions('a', 'b')->withTitle('T')->withMin(1)->withMax(2)->view();
        $short = MultiSelect::new('k')->options('a', 'b')->title('T')->min(1)->max(2)->view();
        $this->assertSame($long, $short);
    }

    public function testNoteAliases(): void
    {
        $long  = Note::new('k')->withTitle('T')->withDescription('D')->withHeight(3)->withNext(true)->withNextLabel('Go')->view();
        $short = Note::new('k')->title('T')->desc('D')->height(3)->next(true)->nextLabel('Go')->view();
        $this->assertSame($long, $short);
    }

    public function testFilePickerKnobsRoundTripViaAccessors(): void
    {
        // FilePicker.view() depends on the live filesystem cursor; compare configured
        // knobs via accessors instead.
        $long  = FilePicker::new('k')->withShowHidden(true)->withAllowedExtensions(['.php'])->withDirAllowed(false)->withFileAllowed(true);
        $short = FilePicker::new('k')->showHidden(true)->exts(['.php'])->dirAllowed(false)->fileAllowed(true);
        $this->assertSame($long->getTitle(), $short->getTitle());
        $this->assertSame($long->getDescription(), $short->getDescription());
    }

    public function testGroupAliases(): void
    {
        $long  = Group::new(Input::new('a'))->withTitle('T')->withDescription('D')->withShowHelp(false);
        $short = Group::new(Input::new('a'))->title('T')->desc('D')->showHelp(false);
        $this->assertSame($long->title, $short->title);
        $this->assertSame($long->description, $short->description);
        $this->assertSame($long->showHelp, $short->showHelp);
    }

    public function testFormAliases(): void
    {
        $long  = Form::new(Input::new('a'))->withWidth(80)->withHeight(20)->withShowHelp(false)->withTimeout(5000);
        $short = Form::new(Input::new('a'))->width(80)->height(20)->showHelp(false)->timeout(5000);
        $this->assertSame($long->timeoutMs(), $short->timeoutMs());
    }
}
