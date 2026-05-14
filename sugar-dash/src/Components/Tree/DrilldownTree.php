<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Components\Nav\Breadcrumb;
use SugarCraft\Dash\Layout\StackLayout;

/**
 * A drill-down tree view component.
 *
 * Features:
 * - Hierarchical node structure with expand/collapse
 * - Multiple levels of nesting
 * - Current path highlighting
 * - Configurable node rendering
 * - Interactive drill-down navigation
 * - StackLayout pattern for child-view navigation
 * - Breadcrumb showing current navigation path
 * - onEnter/onExit lifecycle hooks
 *
 * Mirrors drill-down tree patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class DrilldownTree implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private TreeNode $root;
    private string $currentPath = '';
    private bool $showValues = true;
    private bool $showIcons = true;
    private bool $expanded = true;

    /** @var list<string> Navigation stack of expanded node IDs */
    private array $navigationStack = [];

    /** @var callable|null Called when entering a node: callable(string $nodeId): void */
    private mixed $onEnter = null;

    /** @var callable|null Called when exiting a node: callable(string $nodeId): void */
    private mixed $onExit = null;

    public function __construct(
        private ?Color $nodeColor = null,
        private ?Color $selectedColor = null,
        private ?Color $textColor = null,
        private ?Color $lineColor = null,
        private string $style = 'rounded',
    ) {
        $this->root = new TreeNode('root', 'Root', 0);
    }

    /**
     * Create a new drill-down tree with default styling.
     */
    public static function new(TreeNode $root): self
    {
        $tree = new self(
            nodeColor: Color::hex('#89B4FA'),
            selectedColor: Color::hex('#A6E3A1'),
            textColor: Color::hex('#CDD6F4'),
            lineColor: Color::hex('#45475A'),
            style: 'rounded',
        );
        return $tree->withRoot($root);
    }

    /**
     * Set the allocated dimensions for this drill-down tree.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Set the root node.
     */
    public function withRoot(TreeNode $root): self
    {
        $clone = clone $this;
        $clone->root = $root;
        return $clone;
    }

    /**
     * Set the current drill-down path.
     */
    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->currentPath = $path;
        return $clone;
    }

    /**
     * Show or hide values.
     */
    public function withShowValues(bool $show): self
    {
        $clone = clone $this;
        $clone->showValues = $show;
        return $clone;
    }

    /**
     * Show or hide icons.
     */
    public function withShowIcons(bool $show): self
    {
        $clone = clone $this;
        $clone->showIcons = $show;
        return $clone;
    }

    /**
     * Set expanded state.
     */
    public function withExpanded(bool $expanded): self
    {
        $clone = clone $this;
        $clone->expanded = $expanded;
        return $clone;
    }

    /**
     * Set the onEnter lifecycle hook.
     */
    public function withOnEnter(callable $onEnter): self
    {
        $clone = clone $this;
        $clone->onEnter = $onEnter;
        return $clone;
    }

    /**
     * Set the onExit lifecycle hook.
     */
    public function withOnExit(callable $onExit): self
    {
        $clone = clone $this;
        $clone->onExit = $onExit;
        return $clone;
    }

    /**
     * Push a node onto the navigation stack and call onEnter.
     */
    public function pushNode(string $nodeId): self
    {
        $clone = clone $this;
        $clone->navigationStack[] = $nodeId;
        if ($clone->onEnter !== null) {
            ($clone->onEnter)($nodeId);
        }
        return $clone;
    }

    /**
     * Pop a node from the navigation stack and call onExit.
     */
    public function popNode(): self
    {
        $clone = clone $this;
        $nodeId = array_pop($clone->navigationStack);
        if ($nodeId !== null && $clone->onExit !== null) {
            ($clone->onExit)($nodeId);
        }
        return $clone;
    }

    /**
     * Get the current navigation stack.
     *
     * @return list<string>
     */
    public function getNavigationStack(): array
    {
        return $this->navigationStack;
    }

    /**
     * Get breadcrumb items from the navigation stack.
     *
     * @return list<string>
     */
    public function getBreadcrumbItems(): array
    {
        if (empty($this->navigationStack)) {
            return [$this->root->label];
        }

        $items = [$this->root->label];
        foreach ($this->navigationStack as $nodeId) {
            $node = $this->findNodeById($this->root, $nodeId);
            if ($node !== null) {
                $items[] = $node->label;
            }
        }
        return $items;
    }

    /**
     * Find a node by its ID in the tree.
     */
    private function findNodeById(TreeNode $root, string $id): ?TreeNode
    {
        if ($root->id === $id) {
            return $root;
        }
        foreach ($root->children as $child) {
            $found = $this->findNodeById($child, $id);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Render the drill-down tree as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 20;

        if ($useWidth < 20 || $useHeight < 5) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Title
        $title = 'Drill-Down Tree';
        $titlePadding = intval(($useWidth - 2 - strlen($title)) / 2);
        $result .= $tl . str_repeat('─', $titlePadding) . $title . str_repeat('─', $useWidth - 2 - $titlePadding - strlen($title)) . $tr . "\n";

        // Render breadcrumb if navigation stack is not empty
        if (!empty($this->navigationStack)) {
            $breadcrumbItems = $this->getBreadcrumbItems();
            $breadcrumb = Breadcrumb::new($breadcrumbItems);
            $breadcrumb = $breadcrumb->withSeparator('›');
            $breadcrumbStr = $breadcrumb->setSize($useWidth - 2, 1)->render();
            $result .= $v . mb_substr($breadcrumbStr, 0, $useWidth - 2) . $v . "\n";
        }

        // Render tree content
        $content = $this->renderNode($this->root, 0, $useWidth - 4);
        foreach (explode("\n", $content) as $line) {
            $result .= $v . mb_substr(str_pad($line, $useWidth - 2), 0, $useWidth - 2) . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat('─', $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Render a node and its children recursively.
     */
    private function renderNode(TreeNode $node, int $depth, int $maxWidth): string
    {
        $result = '';
        $prefix = str_repeat('│  ', $depth);
        $nodeColor = $this->nodeColor ?? Color::hex('#89B4FA');
        $textColor = $this->textColor ?? Color::hex('#CDD6F4');

        // Determine if this node is in the current path
        $isSelected = $this->currentPath !== '' && str_starts_with($this->currentPath, $node->id);

        if ($isSelected && $this->selectedColor !== null) {
            $color = $this->selectedColor;
        } else {
            $color = $nodeColor;
        }

        // Build node line
        $nodeLine = $prefix;
        if ($depth > 0) {
            $nodeLine .= '├─ ';
        }

        $icon = $this->showIcons ? ($this->expanded && !empty($node->children) ? '▼ ' : '▶ ') : '';
        $valueStr = $this->showValues ? ' (' . sprintf('%.1f', $node->value) . ')' : '';

        if ($color !== null) {
            $nodeLine .= $color->toFg(ColorProfile::TrueColor);
        }
        $nodeLine .= $icon . $node->label . $valueStr;
        if ($color !== null) {
            $nodeLine .= Ansi::reset();
        }

        $result .= mb_substr($nodeLine, 0, $maxWidth) . "\n";

        // Render children if expanded
        if ($this->expanded && !empty($node->children)) {
            foreach ($node->children as $child) {
                $result .= $this->renderNode($child, $depth + 1, $maxWidth);
            }
        }

        return $result;
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }

    /**
     * Calculate the natural dimensions of this drill-down tree.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 20;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the node color.
     */
    public function withNodeColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->nodeColor = $color;
        return $clone;
    }

    /**
     * Set the selected path color.
     */
    public function withSelectedColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->selectedColor = $color;
        return $clone;
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->textColor = $color;
        return $clone;
    }

    /**
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->lineColor = $color;
        return $clone;
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        $clone = clone $this;
        $clone->style = $style;
        return $clone;
    }
}
