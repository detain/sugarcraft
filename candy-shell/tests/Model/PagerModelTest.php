<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Model;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Shell\Model\PagerModel;
use PHPUnit\Framework\TestCase;

final class PagerModelTest extends TestCase
{
    private function content(int $n): string
    {
        $lines = [];
        for ($i = 1; $i <= $n; $i++) {
            $lines[] = "line $i";
        }
        return implode("\n", $lines);
    }

    public function testInitialView(): void
    {
        $m = PagerModel::fromContent($this->content(3), 80, 5);
        $this->assertStringContainsString('line 1', $m->view());
    }

    public function testQExits(): void
    {
        $m = PagerModel::fromContent($this->content(3));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($m->isExited());
        $this->assertNotNull($cmd);
    }

    public function testEscExits(): void
    {
        $m = PagerModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isExited());
    }

    public function testCtrlCExits(): void
    {
        $m = PagerModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isExited());
    }

    public function testDownScrollsViewport(): void
    {
        $m = PagerModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $m->viewport->yOffset);
    }

    public function testShowLineNumbersPrefixesEveryLine(): void
    {
        $m = PagerModel::fromContent($this->content(3), 80, 5, showLineNumbers: true);
        $view = $m->view();
        $this->assertStringContainsString('1 │ line 1', $view);
        $this->assertStringContainsString('2 │ line 2', $view);
        $this->assertStringContainsString('3 │ line 3', $view);
    }

    public function testShowLineNumbersAlignsWidth(): void
    {
        // 12 lines means 2-cell line-number column; line 1 should be
        // padded with a leading space.
        $m = PagerModel::fromContent($this->content(12), 80, 12, showLineNumbers: true);
        $this->assertStringContainsString(' 1 │ line 1', $m->view());
        $this->assertStringContainsString('12 │ line 12', $m->view());
    }

    public function testMatchHighlightsOccurrences(): void
    {
        $m = PagerModel::fromContent("hello world\nhello again", 80, 5, match: 'hello');
        $view = $m->view();
        // Reverse-video wrapper around each occurrence.
        $this->assertStringContainsString("\x1b[7mhello\x1b[0m", $view);
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $m = PagerModel::fromContent('Hello WORLD', 80, 5, match: 'hello');
        $view = $m->view();
        $this->assertStringContainsString("\x1b[7mHello\x1b[0m", $view);
    }

    public function testMatchEmptyIsNoOp(): void
    {
        $m = PagerModel::fromContent('hello world', 80, 5, match: '');
        $this->assertStringNotContainsString("\x1b[7m", $m->view());
    }
}
