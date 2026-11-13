<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;

/**
 * CF-22 (closes audit 031): the lister answers null for "cannot read",
 * and the Manager must honour it — keep the last honest listing, name
 * the refused door in the status, and stop telling the user a stale or
 * fake-empty pane is "refreshed".
 */
final class ManagerUnreadableTest extends TestCase
{
    private function lister(): \Closure
    {
        $tree = [
            '/'     => [new Entry('home', true, 0, 0), new Entry('readme', false, 10, 0)],
            '/home' => [new Entry('alice', true, 0, 0)],
            '/home/alice' => null,
            '/stuck'      => null,
            '/stuck/deep' => null,
        ];
        // array_key_exists, not ??: null IS the answer for the refused doors.
        return static fn(string $path) => array_key_exists($path, $tree) ? $tree[$path] : [];
    }

    public function testEnterIntoUnreadableDirRefusesMovesNobodyAndNamesTheDoor(): void
    {
        [$tabbed] = Manager::start('/', '/home', $this->lister())
            ->update(new KeyMsg(KeyType::Tab, ''));
        [$armed] = $tabbed->update(new KeyMsg(KeyType::Char, 'j'));

        [$next] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        self::assertSame('/home', $next->right->cwd, 'the refused pane must not teleport');
        $names = array_map(static fn(Entry $e) => $e->name, $next->right->entries);
        self::assertSame(['..', 'alice'], $names, 'the last honest listing stays on screen');
        self::assertFalse($next->right->readable, 'the pane admits it could not read');
        self::assertSame(
            Lang::t('status.unreadable', ['dir' => '/home/alice']),
            $next->status,
            'the status names WHICH door refused',
        );
    }

    public function testRefreshOnUnreadableSaysWhyInsteadOfLyingRefreshed(): void
    {
        [$m] = Manager::start('/stuck', '/', $this->lister())
            ->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertNotSame(Lang::t('status.refreshed'), $m->status);
        self::assertSame(Lang::t('status.unreadable', ['dir' => '/stuck']), $m->status);
        self::assertFalse($m->left->readable);
    }

    public function testRefreshOnReadableDirStillHonestlyReportsRefreshed(): void
    {
        [$m] = Manager::start('/', '/', $this->lister())
            ->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame(Lang::t('status.refreshed'), $m->status);
    }

    public function testGoUpIntoUnreadableParentStaysPutAndNamesTheDoor(): void
    {
        [$m] = Manager::start('/stuck/deep', '/', $this->lister())
            ->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertSame('/stuck/deep', $m->left->cwd, 'a parent that refuses must not be entered blind');
        self::assertFalse($m->left->readable);
        self::assertSame(Lang::t('status.unreadable', ['dir' => '/stuck']), $m->status);
    }

    public function testGoUpIntoReadableParentClearsTheUnreadableMark(): void
    {
        // Opened over an unreadable dir (the bin's argv guard is the prod
        // preventer; this is the recovery shape): stepping up into a
        // readable parent must land readable and cursor-restored.
        $stuck = Manager::start('/home/alice', '/', $this->lister());
        self::assertFalse($stuck->left->readable);

        [$up] = $stuck->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertSame('/home', $up->left->cwd);
        self::assertTrue($up->left->readable);
        $names = array_map(static fn(Entry $e) => $e->name, $up->left->entries);
        self::assertSame(1, array_search('alice', $names, true), 'cursor lands on the way we came from');
    }
}
