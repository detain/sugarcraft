<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Model\ChooseModel;
use PHPUnit\Framework\TestCase;

final class ChooseModelTest extends TestCase
{
    private function model(): ChooseModel
    {
        return ChooseModel::fromOptions(['Pizza', 'Burger', 'Salad']);
    }

    public function testEnterSubmitsCurrentChoice(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));   // Burger
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('Burger', $m->selected());
        $this->assertNotNull($cmd);
    }

    public function testEscAborts(): void
    {
        $m = $this->model();
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
        $this->assertNull($m->selected());
        $this->assertNotNull($cmd);
    }

    public function testCtrlCAborts(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isAborted());
    }

    public function testEnterInsideFilterModeDoesNotSubmitForm(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'p'));
        // Enter inside filter mode is consumed by the inner ItemList — it
        // exits filtering, but the chooser must NOT count it as a submit.
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($m->isSubmitted());
    }

    public function testIgnoresKeysAfterSubmit(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        [$m2, $cmd] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame($m, $m2);
        $this->assertNull($cmd);
    }

    public function testEnterWithEmptyOptionsDoesNothing(): void
    {
        $m = ChooseModel::fromOptions([]);
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($m->isSubmitted());
        $this->assertNull($cmd);
    }

    public function testMultiModeSpaceTogglesSelection(): void
    {
        // noLimit enables multi mode
        $m = ChooseModel::fromOptions(['Pizza', 'Burger', 'Salad'], noLimit: true);
        $this->assertTrue($m->isMulti());

        // Move cursor down and toggle with Space
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space));

        $this->assertSame(1, $m->selectedCount());
    }

    public function testSelectedAllReturnsEmptyInSingleMode(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        // selectedAll() returns [] in single mode
        $this->assertSame([], $m->selectedAll());
    }

    public function testSelectedAllReturnsOrderedSelections(): void
    {
        $m = ChooseModel::fromOptions(['A', 'B', 'C'], noLimit: true, ordered: true);
        // Select A (at position 0) and C (at position 2)
        [$m, ] = $m->update(new KeyMsg(KeyType::Space)); // select A at cursor 0
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));  // move to B
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));  // move to C
        [$m, ] = $m->update(new KeyMsg(KeyType::Space)); // toggle C
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter)); // submit

        // With ordered=true, selection order is preserved (A then C)
        $this->assertSame(['A', 'C'], $m->selectedAll());
    }

    public function testSelectedCountReturnsZeroWhenNothingSelected(): void
    {
        $m = ChooseModel::fromOptions(['Pizza', 'Burger'], noLimit: true);
        $this->assertSame(0, $m->selectedCount());
    }

    public function testViewShowsSelectedCountInMultiMode(): void
    {
        $m = ChooseModel::fromOptions(['Pizza', 'Burger', 'Salad'], noLimit: true);
        [$m, ] = $m->update(new KeyMsg(KeyType::Space)); // select Pizza
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Space)); // select Burger

        $view = $m->view();
        // Multi mode view appends "[N selected]" line
        $this->assertStringContainsString('[2 selected]', $view);
    }

    public function testViewInSingleModeDoesNotShowSelectedCountSuffix(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));

        $view = $m->view();
        // Single mode view does not append the "[N selected]" multi-select indicator
        $this->assertStringNotContainsString(' selected]', $view);
    }
}
