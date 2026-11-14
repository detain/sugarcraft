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
 * CF-13 (closes 019): `false` is the lister's third answer — "readable,
 * but too many entries to list" (the real FsLister caps at
 * FsLister::MAX_LIST_ENTRIES so no directory can ever hand the event loop
 * an unbounded synchronous scan — the starvation that made `q` and
 * SIGINT stop answering in the finding). Every refusal door the CF-22
 * unreadable shape passes through must name the CAP truthfully instead
 * of blaming permissions: pane keeps its last honest listing, `tooLarge`
 * distinguishes the door, and the status rides status.dir_too_large.
 */
final class ManagerTooLargeTest extends TestCase
{
    private function lister(): \Closure
    {
        $tree = [
            '/'            => [new Entry('home', true, 0, 0), new Entry('small', true, 0, 0)],
            '/home'         => [new Entry('big', true, 0, 0), new Entry('file.txt', false, 3, 0)],
            '/home/big'     => false,
            '/big'          => false,
            '/stuck'        => false,
            '/stuck/small'  => [new Entry('s.txt', false, 1, 0)],
        ];
        // array_key_exists, not ??: false/null ARE the answers at the doors.
        return static fn(string $path) => array_key_exists($path, $tree) ? $tree[$path] : [];
    }

    public function testEnterIntoTooLargeDirRefusesKeepsListingAndNamesTheCap(): void
    {
        [$tabbed] = Manager::start('/', '/home', $this->lister())
            ->update(new KeyMsg(KeyType::Tab, ''));
        [$armed] = $tabbed->update(new KeyMsg(KeyType::Char, 'j'));

        [$next] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        self::assertSame('/home', $next->right->cwd, 'the refused pane must not teleport');
        $names = array_map(static fn(Entry $e) => $e->name, $next->right->entries);
        self::assertSame(['..', 'big', 'file.txt'], $names, 'the last honest listing stays on screen');
        self::assertFalse($next->right->readable, 'the pane admits it could not list');
        self::assertTrue($next->right->tooLarge, 'the refusal carries the CF-13 reason, not the CF-22 one');
        self::assertSame(
            Lang::t('status.dir_too_large', ['dir' => '/home/big']),
            $next->status,
            'the status names which door refused — the cap, not permissions',
        );
    }

    public function testGoUpIntoTooLargeParentRefusesWithItsOwnWording(): void
    {
        [$next] = Manager::start('/stuck/small', '/', $this->lister())
            ->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertSame('/stuck/small', $next->left->cwd, 'the refused goUp keeps the pane where it was');
        self::assertTrue($next->left->tooLarge, 'the left pane carries the too-large truth');
        self::assertSame(
            Lang::t('status.dir_too_large', ['dir' => '/stuck']),
            $next->status,
            'goUp names the parent door honestly',
        );
    }

    public function testRefreshOnTooLargePaneSaysCapInsteadOfLyingRefreshed(): void
    {
        [$m] = Manager::start('/big', '/', $this->lister())
            ->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertNotSame(Lang::t('status.refreshed'), $m->status);
        self::assertNotSame(Lang::t('status.unreadable', ['dir' => '/big']), $m->status, 'a readable door is never blamed on permissions');
        self::assertSame(Lang::t('status.dir_too_large', ['dir' => '/big']), $m->status);
        self::assertTrue($m->left->tooLarge, 'relist retains the refusal reason');
    }
}
