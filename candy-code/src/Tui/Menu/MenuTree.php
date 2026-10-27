<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

/**
 * The whole menu bar as a tree: every top-level entry is itself a submenu
 * (its dropdown rows), so the kit has no "menu vs row" type split — a path is
 * a plain list of 0-based indexes and {@see rows()} answers it.
 *
 * Path vocabulary shared by the cursor and the cascade:
 *  - `[]`              the menu bar itself; rows = the top-level menus
 *  - `[m]`             menu m (0-based); rows = that menu's dropdown
 *  - `[m, r]`          dropdown row r of menu m; rows = its children
 *  - `[m, r, s]`       ... and so on, arbitrarily deep.
 *
 * The tree is inert data: no focus, no geometry, no rendering — those live in
 * {@see MenuCursor} and {@see MenuCascade}.
 */
final class MenuTree
{
    /**
     * @param list<MenuNode> $menus every entry a {@see MenuNodeKind::Submenu}
     */
    private function __construct(public readonly array $menus)
    {
    }

    /**
     * @param list<MenuNode> $menus
     */
    public static function new(array $menus): self
    {
        return new self($menus);
    }

    /**
     * The rows addressed by $path: `[]` yields the top-level menus, a path to
     * a submenu yields its children, anything else yields no rows.
     *
     * @param list<int> $path
     * @return list<MenuNode>
     */
    public function rows(array $path): array
    {
        if ($path === []) {
            return $this->menus;
        }

        $node = $this->node($path);

        return $node === null ? [] : $node->children;
    }

    /**
     * @param list<int> $path non-empty
     */
    public function node(array $path): ?MenuNode
    {
        $level = $this->menus;
        $node = null;

        foreach ($path as $index) {
            if (!isset($level[$index])) {
                return null;
            }

            $node = $level[$index];
            $level = $node->children;
        }

        return $node;
    }

    /**
     * The path to the node with $id, or null when the id is absent. Depths
     * walk document order (menus left to right, rows top to bottom), so a
     * duplicated id resolves to its first occurrence.
     *
     * @return list<int>|null
     */
    public function pathOf(string $id): ?array
    {
        return self::search($this->menus, [], $id);
    }

    /**
     * Every command and toggle leaf in document order, disabled rows
     * included: this is the tree's inventory of actions, not a cursor rule.
     * Separators and submenu headers carry no action and stay out.
     *
     * @return list<MenuNode>
     */
    public function leaves(): array
    {
        $leaves = [];

        foreach ($this->menus as $menu) {
            self::collectLeaves($menu, $leaves);
        }

        return $leaves;
    }

    /**
     * @param list<MenuNode> $level
     * @param list<int>      $prefix
     * @return list<int>|null
     */
    private static function search(array $level, array $prefix, string $id): ?array
    {
        foreach ($level as $index => $node) {
            if ($node->id === $id) {
                return [...$prefix, $index];
            }

            $found = self::search($node->children, [...$prefix, $index], $id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<MenuNode> $leaves
     */
    private static function collectLeaves(MenuNode $node, array &$leaves): void
    {
        if ($node->kind === MenuNodeKind::Command || $node->kind === MenuNodeKind::Toggle) {
            $leaves[] = $node;
        }

        foreach ($node->children as $child) {
            self::collectLeaves($child, $leaves);
        }
    }
}
