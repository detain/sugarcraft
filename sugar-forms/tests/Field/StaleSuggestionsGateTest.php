<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\FormSuggestionsReadyMsg;
use PHPUnit\Framework\TestCase;

/**
 * SF-52 (sugar-forms-081/-085): the ready handler drops stale suggestion
 * arrivals — superseded generation (seq) or a field the user left — instead
 * of popping an overlay nobody asked for.
 */
final class StaleSuggestionsGateTest extends TestCase
{
    public function testFocusedCurrentGenerationLands(): void
    {
        [$f, ] = Input::new('k')->withValue('fr')->focus();
        [$next, $cmd] = $f->update(new FormSuggestionsReadyMsg('k', ['fresh'], seq: 0));
        $this->assertNotSame($f, $next, 'matching seq + focus applies the arrival');
        $this->assertTrue($next->consumes(new FormSuggestionsReadyMsg('k', [], seq: 0)));
        $this->assertSame(['fresh'], $next->input->availableSuggestions(), 'matching generation lands the list');
    }

    public function testSupersededGenerationIsDiscardedEvenWhileFocused(): void
    {
        [$f, ] = Input::new('k')->focus();
        [$next, $cmd] = $f->update(new FormSuggestionsReadyMsg('k', ['ghost'], seq: 99));
        $this->assertSame($f->input, $next->input, 'stale seq must discard untouched');
        $this->assertNull($cmd);
        $this->assertSame([], $next->input->availableSuggestions(), 'stale generation never arms the list');
    }

    public function testUnfocusedFieldDiscardsItsOwnGeneration(): void
    {
        $f = Input::new('k');
        [$next, $cmd] = $f->update(new FormSuggestionsReadyMsg('k', ['left-behind'], seq: 0));
        $this->assertSame($f->input, $next->input, '081: a fetch landing after navigate-away must not re-show the overlay');
        $this->assertNull($cmd);
    }

    public function testSelectGatesTheSameWayOnBothTwins(): void
    {
        [$open, ] = Select::new('k')->withOptions('a', 'b')->focus();
        [$landed, ] = $open->update(new FormSuggestionsReadyMsg('k', ['Z'], seq: 0));
        $this->assertSame('Z', $landed->value(), 'current generation repaints the picker');

        [$stale, ] = $open->update(new FormSuggestionsReadyMsg('k', ['W'], seq: 42));
        $this->assertSame('a', $stale->value(), 'stale generation discards on Select too (value frozen)');
        $this->assertStringNotContainsString('W', $stale->view());

        $unfocused = $open->blur();
        [$left, ] = $unfocused->update(new FormSuggestionsReadyMsg('k', ['V'], seq: 0));
        $this->assertSame('a', $left->value(), 'blurred picker drops the arrival');
        $this->assertStringNotContainsString('V', $left->view());
    }

    public function testForeignFieldKeyDropsIndependentlyOfSeqAndFocus(): void
    {
        [$f, ] = Input::new('mine')->withValue('x')->focus();
        [$next, $cmd] = $f->update(new FormSuggestionsReadyMsg('other', ['nope'], seq: 0));
        // (update() rebuilds the field object for every message — the
        // pre-existing shape — so the drop is pinned by the untouched
        // widget + absent overlay, not by instance identity.)
        $this->assertSame($f->input, $next->input, 'foreign key never touches the widget');
        $this->assertSame([], $next->input->availableSuggestions(), 'foreign key never arms the list');
        $this->assertNull($cmd);
    }
}
