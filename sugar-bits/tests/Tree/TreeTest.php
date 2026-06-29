<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Tree\Node;
use SugarCraft\Bits\Tree\Tree;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

final class TreeTest extends TestCase
{
    private function sample(): Tree
    {
        return Tree::new(
            Node::branch('src',
                Node::leaf('Tree.php', 'src/Tree.php'),
                Node::leaf('Node.php', 'src/Node.php'),
            ),
            Node::branch('tests',
                Node::leaf('TreeTest.php', 'tests/TreeTest.php'),
            ),
            Node::leaf('README.md', 'README.md'),
        );
    }

    private function focused(Tree $t): Tree
    {
        [$t, ] = $t->focus();
        return $t;
    }

    public function testNodeFactories(): void
    {
        $leaf = Node::leaf('readme', 'r');
        $this->assertTrue($leaf->isLeaf());
        $this->assertSame('r', $leaf->value);

        $branch = Node::branch('src', Node::leaf('a'));
        $this->assertFalse($branch->isLeaf());
        $this->assertSame('src', $branch->label);
        $this->assertCount(1, $branch->children);
    }

    public function testVisibleRowsReflectsExpandState(): void
    {
        $t = $this->sample();
        // Defaults: every branch starts expanded
        // Rows: src, src/Tree.php, src/Node.php, tests, tests/TreeTest.php, README.md = 6
        $this->assertSame(6, $t->visibleCount());

        // Collapse 'src' at cursor=0
        $t = $t->collapseAtCursor();
        // Now: src (collapsed), tests, tests/TreeTest.php, README.md = 4
        $this->assertSame(4, $t->visibleCount());
    }

    public function testCursorNavigationStaysInBounds(): void
    {
        $t = $this->focused($this->sample());
        $this->assertSame(0, $t->cursor);
        $t = $t->cursorDown(20);
        $this->assertSame($t->visibleCount() - 1, $t->cursor);
        $t = $t->cursorUp(100);
        $this->assertSame(0, $t->cursor);
    }

    public function testToggleAtCursorOnLeafIsNoOp(): void
    {
        $t = $this->focused($this->sample())->cursorDown(); // src/Tree.php
        $before = $t->visibleCount();
        $t = $t->toggleAtCursor();
        $this->assertSame($before, $t->visibleCount());
    }

    public function testToggleAtCursorOnBranchFlipsExpansion(): void
    {
        $t = $this->focused($this->sample());
        $this->assertSame(6, $t->visibleCount());
        $t = $t->toggleAtCursor(); // collapse src
        $this->assertSame(4, $t->visibleCount());
        $t = $t->toggleAtCursor(); // re-expand src
        $this->assertSame(6, $t->visibleCount());
    }

    public function testKeyboardDownArrowMovesCursor(): void
    {
        $t = $this->focused($this->sample());
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        assert($t instanceof Tree);
        $this->assertSame(1, $t->cursor);
    }

    public function testKeyboardEnterToggles(): void
    {
        $t = $this->focused($this->sample());
        [$t, ] = $t->update(new KeyMsg(KeyType::Enter));
        assert($t instanceof Tree);
        $this->assertSame(4, $t->visibleCount(), 'Enter on src should collapse it');
    }

    public function testKeyboardLeftCollapsesRightExpands(): void
    {
        $t = $this->focused($this->sample());
        [$t, ] = $t->update(new KeyMsg(KeyType::Left));
        assert($t instanceof Tree);
        $this->assertSame(4, $t->visibleCount());
        [$t, ] = $t->update(new KeyMsg(KeyType::Right));
        assert($t instanceof Tree);
        $this->assertSame(6, $t->visibleCount());
    }

    public function testGoToTopAndBottomViaCharKeys(): void
    {
        $t = $this->focused($this->sample())->cursorDown(2);
        [$tg, ] = $t->update(new KeyMsg(KeyType::Char, 'G'));
        assert($tg instanceof Tree);
        $this->assertSame($tg->visibleCount() - 1, $tg->cursor);
        [$tg, ] = $tg->update(new KeyMsg(KeyType::Char, 'g'));
        assert($tg instanceof Tree);
        $this->assertSame(0, $tg->cursor);
    }

    public function testSelectedValueAndNode(): void
    {
        $t = $this->focused($this->sample())->cursorDown(); // src/Tree.php
        $this->assertSame('Tree.php', $t->selectedNode()?->label);
        $this->assertSame('src/Tree.php', $t->selectedValue());
    }

    public function testViewMarksCursorAndRendersExpansionGlyph(): void
    {
        $t = $this->focused($this->sample());
        $view = $t->view();
        $lines = explode("\n", $view);
        $this->assertStringStartsWith('> ', $lines[0],  'cursor row gets cursorPrefix');
        $this->assertStringContainsString('▼ src', $lines[0], 'expanded branch shows ▼ glyph');
        $this->assertStringContainsString('README.md', $view);
    }

    public function testViewCollapsedShowsArrowGlyph(): void
    {
        $t = $this->focused($this->sample())->collapseAtCursor();
        $lines = explode("\n", $t->view());
        $this->assertStringContainsString('▶ src', $lines[0], 'collapsed branch shows ▶ glyph');
    }

    public function testViewportScrollWindowsLargeTrees(): void
    {
        $kids = [];
        for ($i = 0; $i < 30; $i++) {
            $kids[] = Node::leaf("item-$i");
        }
        $t = Tree::new(Node::branch('group', ...$kids))->withSize(40, 5);
        $t = $this->focused($t);
        $view = $t->view();
        $this->assertSame(5, substr_count($view, "\n") + 1);
    }

    public function testUnfocusedTreeIgnoresKeyboard(): void
    {
        $t = $this->sample(); // not focused
        [$t2, ] = $t->update(new KeyMsg(KeyType::Down));
        $this->assertSame($t, $t2, 'unfocused tree should not respond to keys');
    }

    public function testEmptyTreeRendersEmpty(): void
    {
        $t = Tree::new();
        $this->assertSame('', $t->view());
        $this->assertSame(0, $t->visibleCount());
        $this->assertNull($t->selectedNode());
    }

    public function testLabelWithNewlineStaysOneLine(): void
    {
        // A node whose label contains a newline must not produce multiple
        // output lines — the newline must be replaced with a space.
        $t = Tree::new(
            Node::leaf("a\nb"),
        );
        $view = $t->view();
        // Each rendered row is one line separated by "\n".
        // The label "a\nb" must not appear as two separate rows.
        $this->assertSame(1, substr_count($view, "\n"), 'Newline in label should not produce extra rows');
        // The label text itself should appear (newlines replaced with space).
        $this->assertStringContainsString('a b', $view);
        $this->assertStringNotContainsString("a\nb", $view);
    }
}
