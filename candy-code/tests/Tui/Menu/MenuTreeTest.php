<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Menu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\Menu\MenuNode;
use SugarCraft\Code\Tui\Menu\MenuTree;

/**
 * The path algebra of {@see MenuTree}: rows/node agree on the same path
 * vocabulary, pathOf walks document order, leaves inventories every action.
 */
final class MenuTreeTest extends TestCase
{
    private function tree(): MenuTree
    {
        return MenuTree::new([
            MenuNode::submenu('sub:file', 'File', [
                MenuNode::command('cmd:open-doc', 'Open'),
                MenuNode::separator(),
                MenuNode::submenu('sub:recent-files', 'Recent', [
                    MenuNode::command('cmd:open-alpha', 'Alpha'),
                    MenuNode::command('cmd:open-beta', 'Beta'),
                ]),
            ]),
            MenuNode::submenu('sub:edit', 'Edit', [
                MenuNode::toggle('toggle:vi', 'Vi keys', true),
                MenuNode::command('cmd:find', 'Find', enabled: false),
            ]),
        ]);
    }

    public function testEmptyPathYieldsTheTopLevelMenus(): void
    {
        $tree = $this->tree();

        $rows = $tree->rows([]);

        self::assertCount(2, $rows);
        self::assertSame('sub:file', $rows[0]->id);
        self::assertSame($tree->menus[0], $rows[0]);
    }

    public function testRowsAreTheChildrenOfTheNodeAtThatPath(): void
    {
        $tree = $this->tree();

        $dropdown = $tree->rows([0]);
        self::assertCount(3, $dropdown);
        self::assertSame('cmd:open-doc', $dropdown[0]->id);

        $nested = $tree->rows([0, 2]);
        self::assertCount(2, $nested);
        self::assertSame('cmd:open-alpha', $nested[0]->id);

        // A leaf answers with no rows: nothing nests under a command.
        self::assertSame([], $tree->rows([0, 0]));
    }

    public function testNodeRoundTripsEveryPathInDocumentOrder(): void
    {
        $tree = $this->tree();

        $expected = [
            [[0], 'sub:file'],
            [[0, 0], 'cmd:open-doc'],
            [[0, 1], ''],
            [[0, 2], 'sub:recent-files'],
            [[0, 2, 0], 'cmd:open-alpha'],
            [[0, 2, 1], 'cmd:open-beta'],
            [[1], 'sub:edit'],
            [[1, 0], 'toggle:vi'],
            [[1, 1], 'cmd:find'],
        ];

        foreach ($expected as [$path, $id]) {
            $node = $tree->node($path);
            self::assertNotNull($node, 'path ' . implode('.', $path) . ' must resolve');
            self::assertSame($id, $node->id);
        }
    }

    public function testUnresolvablePathsYieldNull(): void
    {
        $tree = $this->tree();

        self::assertNull($tree->node([9]));
        self::assertNull($tree->node([0, 9]));
        self::assertNull($tree->node([0, 0, 0]));
        self::assertNull($tree->node([1, 1, 1]));
    }

    public function testPathOfResolvesEveryIdBackToItsPath(): void
    {
        $tree = $this->tree();

        self::assertSame([0], $tree->pathOf('sub:file'));
        self::assertSame([0, 2, 1], $tree->pathOf('cmd:open-beta'));
        self::assertSame([1, 1], $tree->pathOf('cmd:find'));

        // Round trip: walking pathOf lands on the very node the id came from.
        $node = $tree->node($tree->pathOf('cmd:open-alpha'));
        self::assertNotNull($node);
        self::assertSame('cmd:open-alpha', $node->id);
    }

    public function testPathOfMissesUnknownIds(): void
    {
        self::assertNull($this->tree()->pathOf('cmd:no-such'));
        self::assertNull($this->tree()->pathOf('sub:no-such-at-all'));
    }

    public function testLeavesCollectCommandsAndTogglesInDocumentOrder(): void
    {
        $ids = array_map(
            static fn(MenuNode $node): string => $node->id,
            $this->tree()->leaves(),
        );

        self::assertSame([
            'cmd:open-doc',
            'cmd:open-alpha',
            'cmd:open-beta',
            'toggle:vi',
            // disabled included: leaves is the tree's inventory, not a cursor rule
            'cmd:find',
        ], $ids);
    }
}
