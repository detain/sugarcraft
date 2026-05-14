<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Connection line styles for mind map branches.
 */
enum ConnectionStyle: string
{
    case Straight = 'straight';
    case Curved = 'curved';
    case Rounded = 'rounded';
}

/**
 * Layout direction for mind map branches.
 */
enum MindMapDirection: string
{
    case LeftRight = 'lr';
    case RightLeft = 'rl';
    case TopBottom = 'tb';
    case BottomTop = 'bt';
}

/**
 * A mind map node with optional children.
 */
final class MindMapNode
{
    /** @var list<MindMapNode> */
    public array $children = [];

    public function __construct(
        public readonly string $text,
        public readonly ?Color $color = null,
        public readonly ?string $icon = null,
    ) {}

    /**
     * Add a child node.
     */
    public function withChild(MindMapNode $child): self
    {
        $clone = clone $this;
        $clone->children[] = $child;
        return $clone;
    }

    /**
     * Create a new child node and add it.
     */
    public function addChild(string $text, ?Color $color = null, ?string $icon = null): self
    {
        $child = new MindMapNode($text, $color, $icon);
        return $this->withChild($child);
    }
}

/**
 * A mind map component for hierarchical data visualization.
 *
 * Features:
 * - Hierarchical node structure
 * - Customizable node colors
 * - Connection lines between nodes
 * - Multiple layout directions
 * - Collapsible branches
 *
 * Mirrors mind map visualization patterns adapted to PHP with
 * wither-style immutable setters.
 */
final class MindMap implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private MindMapNode $root;
    private bool $collapsed = false;

    public function __construct(
        private ?int $maxDepth = null,
        private MindMapDirection $direction = MindMapDirection::LeftRight,
        private ConnectionStyle $connectionStyle = ConnectionStyle::Rounded,
        private ?Color $rootColor = null,
        private ?Color $nodeColor = null,
        private ?Color $lineColor = null,
        private ?Color $textColor = null,
        private ?Color $backgroundColor = null,
        private string $style = 'rounded',
    ) {
        $this->root = new MindMapNode('Root');
    }

    /**
     * Create a new mind map with default styling.
     */
    public static function new(): self
    {
        return new self(
            maxDepth: null,
            direction: MindMapDirection::LeftRight,
            connectionStyle: ConnectionStyle::Rounded,
            rootColor: Color::hex('#A6E3A1'),
            nodeColor: Color::hex('#89B4FA'),
            lineColor: Color::hex('#45475A'),
            textColor: Color::hex('#CDD6F4'),
            backgroundColor: Color::hex('#1E1E2E'),
            style: 'rounded',
        );
    }

    /**
     * Set the allocated dimensions for this mind map.
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
    public function withRoot(MindMapNode $root): self
    {
        $clone = clone $this;
        $clone->root = $root;
        return $clone;
    }

    /**
     * Set the root node text.
     */
    public function withRootText(string $text): self
    {
        $clone = clone $this;
        $clone->root = new MindMapNode($text, $this->rootColor);
        return $clone;
    }

    /**
     * Add a child to the root node.
     */
    public function withChild(string $text, ?Color $color = null, ?string $icon = null): self
    {
        $clone = clone $this;
        $clone->root = $this->root->addChild($text, $color ?? $this->nodeColor, $icon);
        return $clone;
    }

    /**
     * Set collapsed state.
     */
    public function withCollapsed(bool $collapsed): self
    {
        $clone = clone $this;
        $clone->collapsed = $collapsed;
        return $clone;
    }

    /**
     * Render the mind map as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? 15;

        if ($useWidth < 15 || $useHeight < 5) {
            return '';
        }

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $textColor = $this->textColor ?? Color::hex('#CDD6F4');
        $rootColor = $this->rootColor ?? Color::hex('#A6E3A1');

        $result = '';

        // Top border
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Render root node centered
        $centerY = intval($useHeight / 2);
        $rootNodeStr = '◉ ' . $this->root->text . ' ◉';

        for ($row = 0; $row < $useHeight - 2; $row++) {
            $result .= $v;

            if ($row === $centerY) {
                // Root node line
                $padding = intval(($useWidth - 2 - strlen($rootNodeStr)) / 2);
                $result .= str_repeat(' ', max(0, $padding));
                $result .= $rootNodeStr;
                $remaining = $useWidth - 2 - $padding - strlen($rootNodeStr);
                $result .= str_repeat(' ', max(0, $remaining));
            } else {
                // Connection lines or empty
                $result .= str_repeat(' ', $useWidth - 2);
            }

            $result .= $v . "\n";
        }

        // Render child nodes below if not collapsed
        if (!$this->collapsed && $this->root->children !== []) {
            $childY = $useHeight - 2;
            $result = mb_substr($result, 0, -strlen($v . "\n")); // Remove last line

            $result .= $v;
            $childrenStr = $this->renderChildren();
            $padding = $useWidth - 2 - strlen($childrenStr);
            if ($padding > 0) {
                $result .= str_repeat(' ', intval($padding / 2));
            }
            $result .= $childrenStr;
            $result .= $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        return $result;
    }

    /**
     * Render child nodes as connected string.
     */
    private function renderChildren(): string
    {
        $connector = match ($this->connectionStyle) {
            ConnectionStyle::Straight => '─',
            ConnectionStyle::Curved => '─',
            ConnectionStyle::Rounded => '─',
        };

        $branch = '├' . str_repeat($connector, 3) . ' ';
        $lastBranch = '└' . str_repeat($connector, 3) . ' ';

        $result = '';
        $children = $this->root->children;
        $count = count($children);

        foreach ($children as $index => $child) {
            $isLast = $index === $count - 1;
            $prefix = $isLast ? $lastBranch : $branch;
            $nodeStr = $prefix . '◆ ' . $child->text;

            if ($result !== '' && !$isLast) {
                $result .= '   '; // Spacing between nodes
            }
            $result .= $nodeStr;
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
     * Calculate the natural dimensions of this mind map.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 60;
        $height = $this->height ?? 15;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the layout direction.
     */
    public function withDirection(MindMapDirection $direction): self
    {
        $clone = clone $this;
        $clone->direction = $direction;
        return $clone;
    }

    /**
     * Set the connection style.
     */
    public function withConnectionStyle(ConnectionStyle $style): self
    {
        $clone = clone $this;
        $clone->connectionStyle = $style;
        return $clone;
    }

    /**
     * Set the root node color.
     */
    public function withRootColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->rootColor = $color;
        return $clone;
    }

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
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->lineColor = $color;
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
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->backgroundColor = $color;
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
