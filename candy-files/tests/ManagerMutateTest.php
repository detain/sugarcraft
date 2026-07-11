<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Pane;
use SugarCraft\Files\UndoAction;

/**
 * Load-bearing pin for the W13 `mutate()` refactor.
 *
 * Every `with*()`/tab method on {@see Manager} used to hand-list all 16
 * constructor params; they now delegate to the candy-core `Mutable::mutate()`
 * helper. These tests prove that migration is BEHAVIOR-PRESERVING: each method
 * returns a NEW instance, leaves the receiver UNMUTATED, and — the critical
 * invariant — carries EVERY sibling field through unchanged. Reverting any
 * migrated method to a broken hand-list that drops a field (e.g. omits
 * `undoStack`, letting it fall back to the `[]` ctor default) makes the
 * "sibling carried" assertion fail here, because {@see richManager()} seeds
 * every field with a distinctive non-default value.
 */
final class ManagerMutateTest extends TestCase
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

    /**
     * A Manager with EVERY field seeded to a distinctive, non-default value so
     * that a dropped sibling field is always observable.
     */
    private function richManager(): Manager
    {
        $fs = $this->fakeFs();
        $left = Pane::open('/', $fs);
        $right = Pane::open('/home', $fs);
        $tab = ['left' => $left, 'right' => $right, 'activeIdx' => 0];

        return new Manager(
            left: $left,
            right: $right,
            activeIdx: 0,
            status: 'SENTINEL-STATUS',
            confirm: ConfirmState::DeleteSelected,
            lister: $fs,
            searchQuery: 'old-query',
            searchResults: [new Entry('old-result', false, 1, 0)],
            searchCursor: 2,
            tabs: [$tab],
            tabIndex: 0,
            showTabBar: true,
            undoStack: [UndoAction::rename(['/a' => '/b'])],
            redoStack: [UndoAction::rename(['/c' => '/d'])],
            pendingOpDest: '/pending/dest',
            pendingOpType: 'copy',
            inputBuffer: 'SENTINEL-BUFFER',
        );
    }

    /** @return array<string,mixed> name => value for every non-static instance property. */
    private function snapshot(Manager $m): array
    {
        $out = [];
        foreach ((new \ReflectionObject($m))->getProperties() as $p) {
            if ($p->isStatic()) {
                continue;
            }
            $p->setAccessible(true);
            $out[$p->getName()] = $p->getValue($m);
        }
        return $out;
    }

    private function invokePrivate(Manager $m, string $method, array $args): Manager
    {
        $ref = new \ReflectionMethod($m, $method);
        $ref->setAccessible(true);
        /** @var Manager */
        return $ref->invokeArgs($m, $args);
    }

    /**
     * @param list<string> $changed Field names the method is allowed to change.
     */
    private function assertMutation(Manager $before, Manager $after, array $changed, string $ctx): void
    {
        // 1. A genuinely new instance.
        $this->assertNotSame($before, $after, "$ctx: must return a new instance");

        $beforeProps = $this->snapshot($before);
        $afterProps = $this->snapshot($after);

        foreach ($beforeProps as $name => $val) {
            if (in_array($name, $changed, true)) {
                continue;
            }
            // 2. Every sibling field carried through unchanged (the load-bearing pin).
            if ($name === 'lister') {
                // Same closure instance must be preserved (never equality-compared).
                $this->assertSame($val, $afterProps[$name], "$ctx: lister must be carried unchanged");
                continue;
            }
            $this->assertEquals($val, $afterProps[$name], "$ctx: sibling field '$name' must be carried unchanged");
        }
    }

    /**
     * Broad revert-proof: run every migrated with*()/tab method against a
     * fully-populated receiver and assert the new-instance + siblings-carried
     * invariant. The receiver is also re-checked as unmutated.
     */
    public function testEveryMutateMethodCarriesSiblingFields(): void
    {
        $fs = $this->fakeFs();

        /** @var array<string,array{base:\Closure,apply:\Closure,changed:list<string>}> $cases */
        $cases = [
            'withStatus' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate($m, 'withStatus', ['fresh status']),
                'changed' => ['status'],
            ],
            'withInputBuffer' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate($m, 'withInputBuffer', ['fresh buffer']),
                'changed' => ['inputBuffer'],
            ],
            'withConfirm' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate(
                    $m,
                    'withConfirm',
                    [ConfirmState::None, 'confirm status', '/new/dest', 'move', 'confirm buffer'],
                ),
                'changed' => ['status', 'confirm', 'pendingOpDest', 'pendingOpType', 'inputBuffer'],
            ],
            'withUndoRedoStacks' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate(
                    $m,
                    'withUndoRedoStacks',
                    [[UndoAction::delete(['/x'])], []],
                ),
                'changed' => ['undoStack', 'redoStack'],
            ],
            'withSearch (via search)' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $m->search('read'),
                'changed' => ['searchQuery', 'searchResults', 'searchCursor'],
            ],
            'withActive (Tab swap)' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate($m, 'withActive', [1]),
                'changed' => ['activeIdx', 'tabs'],
            ],
            'withActivePane' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $this->invokePrivate(
                    $m,
                    'withActivePane',
                    [static fn(Pane $p) => Pane::open('/home', $fs)],
                ),
                'changed' => ['left', 'tabs'],
            ],
            'openNewTab' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $m->openNewTab('/'),
                'changed' => ['tabs', 'tabIndex', 'showTabBar'],
            ],
            'duplicateTab' => [
                'base' => fn() => $this->richManager(),
                'apply' => fn(Manager $m) => $m->duplicateTab(),
                'changed' => ['tabs', 'tabIndex', 'showTabBar'],
            ],
            'switchTab' => [
                'base' => fn() => $this->richManager()->openNewTab('/'), // 2 tabs, tabIndex 1
                'apply' => fn(Manager $m) => $m->switchTab(0),
                'changed' => ['tabIndex'],
            ],
            'closeTab' => [
                'base' => fn() => $this->richManager()->openNewTab('/'), // 2 tabs, tabIndex 1
                'apply' => fn(Manager $m) => $m->closeTab(),
                'changed' => ['tabs', 'tabIndex', 'showTabBar'],
            ],
        ];

        foreach ($cases as $label => $case) {
            $base = ($case['base'])();
            $snapBefore = $this->snapshot($base);

            $result = ($case['apply'])($base);

            $this->assertMutation($base, $result, $case['changed'], $label);

            // Receiver stayed immutable.
            $this->assertEquals($snapBefore, $this->snapshot($base), "$label: receiver must not be mutated");
        }
    }

    // --- Focused "changed field has the new value" checks -------------------

    public function testWithStatusSetsStatusOnly(): void
    {
        $after = $this->invokePrivate($this->richManager(), 'withStatus', ['brand-new']);
        $this->assertSame('brand-new', $after->status);
    }

    public function testWithConfirmSetsAllFiveFields(): void
    {
        $after = $this->invokePrivate(
            $this->richManager(),
            'withConfirm',
            [ConfirmState::None, 'ok', '/d', 'copy', 'buf'],
        );
        $this->assertSame(ConfirmState::None, $after->confirm);
        $this->assertSame('ok', $after->status);
        $this->assertSame('/d', $after->pendingOpDest);
        $this->assertSame('copy', $after->pendingOpType);
        $this->assertSame('buf', $after->inputBuffer);
    }

    public function testSearchSetsQueryAndResetsCursor(): void
    {
        $after = $this->richManager()->search('read');
        $this->assertSame('read', $after->searchQuery);
        $this->assertSame(0, $after->searchCursor);
        $this->assertNotSame([], $after->searchResults, 'fuzzy "read" should match "readme"');
    }

    public function testOpenNewTabAppendsAndFocusesNewTab(): void
    {
        $base = $this->richManager();
        $after = $base->openNewTab('/');
        $this->assertCount(count($base->tabs) + 1, $after->tabs);
        $this->assertSame(count($after->tabs) - 1, $after->tabIndex);
        $this->assertTrue($after->showTabBar);
    }

    public function testSwitchTabChangesOnlyIndex(): void
    {
        $twoTabs = $this->richManager()->openNewTab('/');
        $this->assertSame(1, $twoTabs->tabIndex);
        $after = $twoTabs->switchTab(0);
        $this->assertSame(0, $after->tabIndex);
        $this->assertSame($twoTabs->tabs, $after->tabs, 'switchTab must not touch the tab list');
    }

    public function testListerIsPreservedThroughMutate(): void
    {
        $base = $this->richManager();
        $before = $this->snapshot($base)['lister'];
        $after = $this->invokePrivate($base, 'withStatus', ['x']);
        $this->assertSame($before, $this->snapshot($after)['lister']);
    }
}
