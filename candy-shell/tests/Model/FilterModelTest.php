<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\FilterModel;
use PHPUnit\Framework\TestCase;

final class FilterModelTest extends TestCase
{
    private function model(): FilterModel
    {
        return FilterModel::fromOptions(['apple', 'banana', 'cherry', 'date']);
    }

    public function testStartsInFilterMode(): void
    {
        $m = $this->model();
        $this->assertTrue($m->list->isFiltering());
    }

    public function testTypingFiltersAndEnterSubmits(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        // Filter "an" matches 'banana'.
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('banana', $m->selected());
    }

    public function testEnterWithNoMatchesIsNoOp(): void
    {
        $m = $this->model();
        foreach (str_split('xyz') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($m->isSubmitted());
        $this->assertNull($cmd);
    }

    public function testArrowMovesCursorWhilePreservingFilterText(): void
    {
        $m = $this->model();
        // Filter "a" matches all four.
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame('a', $m->list->filterText);
        // Cursor should now sit at index 1; Enter submits 'banana'.
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('banana', $m->selected());
    }

    public function testEscAborts(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
    }
}
