<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tree;

use SugarCraft\Bits\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;

/**
 * Interactive tree component — single-cursor navigation through a
 * tree of {@see Node}s with expand / collapse and a viewport-aware
 * scroll window.
 *
 * Mirrors the long-requested upstream charmbracelet/bubbles `Tree`
 * (issue #233). The static (non-interactive) tree renderer stays at
 * `\SugarCraft\Sprinkles\Tree\Tree`; this component is the
 * Bubble-Tea-shaped Model wrapper.
 *
 * Usage
 * -----
 *
 * ```php
 * $tree = Tree::new(
 *     Node::branch('src',
 *         Node::leaf('Tree.php'),
 *         Node::leaf('Node.php'),
 *     ),
 *     Node::branch('tests',
 *         Node::leaf('TreeTest.php'),
 *     ),
 * )->withSize(40, 10);
 *
 * [$tree, ] = $tree->focus();
 * echo $tree->view();
 * ```
 *
 * Default key bindings
 * --------------------
 *
 * - `↑` / `k`  : cursor up
 * - `↓` / `j`  : cursor down
 * - `Enter`    : toggle expand on the focused node
 * - `→` / `l`  : expand the focused node (no-op if already open / leaf)
 * - `←` / `h`  : collapse the focused node (no-op if already closed / leaf)
 * - `g`        : go to top
 * - `G`        : go to bottom
 *
 * Models that wrap Tree can extend this surface by intercepting Msg
 * before delegating to {@see update()}.
 */
final class Tree implements Model
{
    /** @var list<Node> root-level nodes */
    public readonly array $roots;

    public readonly int $cursor;
    public readonly int $offset;
    public readonly int $width;
    public readonly int $height;
    public readonly bool $focused;

    public readonly string $cursorPrefix;
    public readonly string $unselectedPrefix;
    public readonly string $expandedGlyph;
    public readonly string $collapsedGlyph;
    public readonly string $leafGlyph;

    /**
     * @param list<Node> $roots
     */
    private function __construct(
        array $roots,
        int $cursor = 0,
        int $offset = 0,
        int $width = 60,
        int $height = 10,
        bool $focused = false,
        string $cursorPrefix = '> ',
        string $unselectedPrefix = '  ',
        string $expandedGlyph = '▼ ',
        string $collapsedGlyph = '▶ ',
        string $leafGlyph = '  ',
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException(Lang::t('tree.dim_nonneg'));
        }
        $this->roots = array_values($roots);
        $this->cursor = $cursor;
        $this->offset = $offset;
        $this->width = $width;
        $this->height = $height;
        $this->focused = $focused;
        $this->cursorPrefix = $cursorPrefix;
        $this->unselectedPrefix = $unselectedPrefix;
        $this->expandedGlyph = $expandedGlyph;
        $this->collapsedGlyph = $collapsedGlyph;
        $this->leafGlyph = $leafGlyph;
    }

    /** Construct a tree from variadic root nodes. */
    public static function new(Node ...$roots): self
    {
        return new self(array_values($roots));
    }

    /** Construct from an explicit list of roots. */
    public static function fromList(array $roots): self
    {
        return new self(array_values($roots));
    }

    public function init(): ?\Closure { return null; }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$this->focused || !$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $isUp    = $msg->type === KeyType::Up    || ($msg->type === KeyType::Char && $msg->rune === 'k');
        $isDown  = $msg->type === KeyType::Down  || ($msg->type === KeyType::Char && $msg->rune === 'j');
        $isLeft  = $msg->type === KeyType::Left  || ($msg->type === KeyType::Char && $msg->rune === 'h');
        $isRight = $msg->type === KeyType::Right || ($msg->type === KeyType::Char && $msg->rune === 'l');
        $isEnter = $msg->type === KeyType::Enter;

        if ($isUp)    return [$this->cursorUp(),   null];
        if ($isDown)  return [$this->cursorDown(), null];
        if ($isEnter) return [$this->toggleAtCursor(), null];
        if ($isRight) return [$this->expandAtCursor(),   null];
        if ($isLeft)  return [$this->collapseAtCursor(), null];

        if ($msg->type === KeyType::Char) {
            if ($msg->rune === 'g') return [$this->copy(cursor: 0)->clamp(),                          null];
            if ($msg->rune === 'G') return [$this->copy(cursor: max(0, $this->visibleCount() - 1))->clamp(), null];
        }

        return [$this, null];
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $rows = $this->visibleRows();
        if ($rows === []) {
            return '';
        }
        $window = $this->height > 0
            ? array_slice($rows, $this->offset, $this->height)
            : $rows;
        $lines = [];
        foreach ($window as $i => $row) {
            $absoluteIndex = $this->offset + $i;
            $cursor = $absoluteIndex === $this->cursor && $this->focused
                ? $this->cursorPrefix
                : $this->unselectedPrefix;
            $glyph = $row['leaf']
                ? $this->leafGlyph
                : ($row['expanded'] ? $this->expandedGlyph : $this->collapsedGlyph);
            $indent = str_repeat('  ', $row['depth']);
            $line = $cursor . $indent . $glyph . $row['label'];
            if ($this->width > 0) {
                $line = Width::truncate($line, $this->width);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    public function isFocused(): bool { return $this->focused; }

    /** Take focus. */
    public function focus(): array { return [$this->copy(focused: true), null]; }

    /** Release focus; companion to {@see focus()}. */
    public function blur(): self { return $this->copy(focused: false); }

    /** Replace the root list. */
    public function setRoots(Node ...$roots): self
    {
        return $this->copy(roots: array_values($roots))->clamp();
    }

    public function withSize(int $width, int $height): self
    {
        return $this->copy(width: $width, height: $height)->clamp();
    }

    public function cursorUp(int $n = 1): self
    {
        return $this->copy(cursor: max(0, $this->cursor - $n))->clamp();
    }

    public function cursorDown(int $n = 1): self
    {
        return $this->copy(cursor: min($this->visibleCount() - 1, $this->cursor + $n))->clamp();
    }

    /**
     * The {@see Node} the cursor is currently on, or null when the
     * tree is empty.
     */
    public function selectedNode(): ?Node
    {
        $rows = $this->visibleRows();
        return $rows[$this->cursor]['node'] ?? null;
    }

    /** Convenience wrapper returning the node's `value` payload. */
    public function selectedValue(): mixed
    {
        return $this->selectedNode()?->value;
    }

    /** Number of visible rows given the current expand/collapse state. */
    public function visibleCount(): int
    {
        return count($this->visibleRows());
    }

    /**
     * @return list<array{label:string, depth:int, leaf:bool, expanded:bool, node:Node, path:list<int>}>
     */
    public function visibleRows(): array
    {
        $rows = [];
        foreach ($this->roots as $i => $root) {
            $this->collectVisible($root, 0, [$i], $rows);
        }
        return $rows;
    }

    /**
     * @param list<int> $path
     * @param list<array{label:string, depth:int, leaf:bool, expanded:bool, node:Node, path:list<int>}> $rows
     */
    private function collectVisible(Node $node, int $depth, array $path, array &$rows): void
    {
        $rows[] = [
            'label'    => $node->label,
            'depth'    => $depth,
            'leaf'     => $node->isLeaf(),
            'expanded' => $node->expanded,
            'node'     => $node,
            'path'     => $path,
        ];
        if ($node->isLeaf() || !$node->expanded) {
            return;
        }
        foreach ($node->children as $i => $child) {
            $this->collectVisible($child, $depth + 1, [...$path, $i], $rows);
        }
    }

    public function expandAtCursor(): self
    {
        return $this->setExpandedAtCursor(true);
    }

    public function collapseAtCursor(): self
    {
        return $this->setExpandedAtCursor(false);
    }

    public function toggleAtCursor(): self
    {
        $node = $this->selectedNode();
        if ($node === null || $node->isLeaf()) {
            return $this;
        }
        return $this->setExpandedAtCursor(!$node->expanded);
    }

    private function setExpandedAtCursor(bool $on): self
    {
        $rows = $this->visibleRows();
        $row  = $rows[$this->cursor] ?? null;
        if ($row === null || $row['leaf']) {
            return $this;
        }
        if ($row['expanded'] === $on) {
            return $this;
        }
        $newRoots = $this->roots;
        $this->updateAt($newRoots, $row['path'], static fn(Node $n) => $n->withExpanded($on));
        return $this->copy(roots: $newRoots)->clamp();
    }

    /**
     * @param list<Node> $tree mutated in place
     * @param list<int>  $path indices walking from $tree root → target node
     * @param \Closure(Node):Node $mut
     */
    private function updateAt(array &$tree, array $path, \Closure $mut): void
    {
        if ($path === []) return;
        $head = array_shift($path);
        $node = $tree[$head] ?? null;
        if ($node === null) return;
        if ($path === []) {
            $tree[$head] = $mut($node);
            return;
        }
        $children = $node->children;
        $this->updateAt($children, $path, $mut);
        $tree[$head] = $node->withChildren($children);
    }

    private function clamp(): self
    {
        $count = $this->visibleCount();
        $cursor = $count === 0 ? 0 : max(0, min($this->cursor, $count - 1));
        $offset = $this->offset;
        if ($this->height > 0) {
            if ($cursor < $offset) {
                $offset = $cursor;
            }
            if ($cursor >= $offset + $this->height) {
                $offset = $cursor - $this->height + 1;
            }
        }
        $offset = max(0, $offset);
        if ($cursor === $this->cursor && $offset === $this->offset) {
            return $this;
        }
        return $this->copy(cursor: $cursor, offset: $offset);
    }

    /** @internal builder helper preserving readonly props */
    private function copy(
        ?array $roots = null,
        ?int $cursor = null,
        ?int $offset = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $focused = null,
    ): self {
        return new self(
            $roots   ?? $this->roots,
            $cursor  ?? $this->cursor,
            $offset  ?? $this->offset,
            $width   ?? $this->width,
            $height  ?? $this->height,
            $focused ?? $this->focused,
            $this->cursorPrefix,
            $this->unselectedPrefix,
            $this->expandedGlyph,
            $this->collapsedGlyph,
            $this->leafGlyph,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
