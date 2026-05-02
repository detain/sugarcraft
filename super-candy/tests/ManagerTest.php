<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\SuperCandy\ConfirmState;
use CandyCore\SuperCandy\Entry;
use CandyCore\SuperCandy\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase
{
    private function fakeFs(): \Closure
    {
        $tree = [
            '/' => [
                new Entry('home',   true,  0, 0),
                new Entry('etc',    true,  0, 0),
                new Entry('readme', false, 100, 0),
            ],
            '/home' => [
                new Entry('alice', true, 0, 0),
                new Entry('bob',   true, 0, 0),
            ],
        ];
        return static fn(string $path): array => $tree[$path] ?? [];
    }

    private function start(): Manager
    {
        return Manager::start('/', '/home', $this->fakeFs());
    }

    public function testStartOpensBothPanes(): void
    {
        $m = $this->start();
        $this->assertSame('/',     $m->left->cwd);
        $this->assertSame('/home', $m->right->cwd);
        $this->assertSame(0, $m->activeIdx);
    }

    public function testQuitDispatchesQuit(): void
    {
        $m = $this->start();
        [, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testTabSwapsActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(1, $next->activeIdx);
        [$back] = $next->update(new KeyMsg(KeyType::Tab, ''));
        $this->assertSame(0, $back->activeIdx);
    }

    public function testJMovesCursorDownInActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(1, $next->left->cursor);
        $this->assertSame(0, $next->right->cursor, 'inactive pane untouched');
    }

    public function testTabThenJAffectsRightPane(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        [$next]    = $swapped->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(0, $next->left->cursor);
        $this->assertSame(1, $next->right->cursor);
    }

    public function testEnterNavigatesActivePane(): void
    {
        $m = $this->start();
        // Move cursor in left pane to 'home' dir, then Enter.
        $names = array_map(fn(Entry $e) => $e->name, $m->left->entries);
        $idx = array_search('home', $names, true);
        $this->assertNotFalse($idx);
        for ($i = 0; $i < $idx; $i++) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        }
        [$next] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('/home', $next->left->cwd);
        $this->assertSame('/home', $next->right->cwd, 'right pane unchanged');
    }

    public function testHGoesUp(): void
    {
        $m = $this->start();
        [$swapped] = $m->update(new KeyMsg(KeyType::Tab, ''));
        [$up]      = $swapped->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame('/', $up->right->cwd);
    }

    public function testSpaceTogglesSelectionAndAdvances(): void
    {
        $m = $this->start();
        // Cursor 0 of left pane (no parent sentinel at root). With
        // name-asc + dirs-first, that's "etc" (alphabetical < "home").
        $cursorName = $m->left->entries[0]->name;
        [$next] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertSame([$cursorName], $next->left->selectedNames());
        $this->assertSame(1, $next->left->cursor, 'cursor advances after toggle');
    }

    public function testSCyclesSortOnActivePane(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertNotSame($m->left->sort, $next->left->sort);
        $this->assertSame($m->right->sort, $next->right->sort);
    }

    public function testDelArmsConfirmation(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $next->confirm);
        $this->assertStringContainsString('y/n', $next->status);
    }

    public function testConfirmCancelsOnAnythingButY(): void
    {
        $m = $this->start();
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$cancelled] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(ConfirmState::None, $cancelled->confirm);
        $this->assertStringContainsString('cancelled', $cancelled->status);
    }

    public function testRefreshUpdatesStatus(): void
    {
        $m = $this->start();
        [$next] = $m->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertStringContainsString('refreshed', $next->status);
    }

    public function testNonKeyMsgIgnored(): void
    {
        $m = $this->start();
        $msg = new \CandyCore\Core\Msg\WindowSizeMsg(80, 24);
        [$next, $cmd] = $m->update($msg);
        $this->assertSame($m, $next);
        $this->assertNull($cmd);
    }
}
