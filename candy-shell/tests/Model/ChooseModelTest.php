<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\ChooseModel;
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
}
