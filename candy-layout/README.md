# CandyLayout

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-layout)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-layout)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-layout?label=packagist)](https://packagist.org/packages/sugarcraft/candy-layout)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Constraint-based layout solver for terminal grid layouts.

**GreedySolver is the default and only supported solver.** It is deterministic
and fully implements every constraint type (Length/Min/Max/Fill/Percentage/Ratio).

`CassowarySolver` is **deprecated** — its Big-M simplex never converged (it hit
the 1000-pivot cap on every call, including trivial layouts, and silently
returned wrong state; its Ratio path returned 0). Rather than rewrite the
simplex, `CassowarySolver::solve()` now emits `E_USER_DEPRECATED` and delegates
wholly to `GreedySolver`. The class is retained only for backward compatibility;
new code should use `GreedySolver` directly.

## Install

```sh
composer require sugarcraft/candy-layout
```

## Quickstart

```php
use SugarCraft\Layout\{Constraint, Direction, GreedySolver, Region};

$solver = GreedySolver::new();
$region = Region::fromSize(100, 24);

$rects = $solver->solve($region, Direction::Horizontal, [
    Constraint::length(20),      // exactly 20 cells
    Constraint::min(10),         // at least 10, takes more if available
    Constraint::fill(1),         // fills remaining space (weight 1)
    Constraint::percentage(30),  // 30% of total
    Constraint::ratio(1, 3),     // 1/3 of remaining after fixed
    Constraint::max(50),        // ceiling — greedy but clamped
]);
```

## Solvers

| Solver           | Use case                                                        | Status      |
| ---------------- | -------------------------------------------------------------- | ----------- |
| GreedySolver     | Deterministic, fast, no deps; all constraint types             | Supported   |
| CassowarySolver  | Deprecated; `solve()` delegates to GreedySolver + warns        | Deprecated  |

## Constraint types

- `Constraint::length(int)` — fixed cell count
- `Constraint::min(int)` — floor, grows if slack available
- `Constraint::max(int)` — ceiling, greedy, clamped
- `Constraint::fill(int $weight = 1)` — proportional remainder
- `Constraint::percentage(int 0-100)` — % of total
- `Constraint::ratio(int $num, int $denom)` — fractional proportion

## Shared foundations

`candy-layout` is a **foundation package** consumed by `candy-sprinkles` (step-10) and `sugar-bits`/`candy-forms` (step-14/15). The `LayoutSolver` interface is the only public contract. Use `GreedySolver`; `CassowarySolver` is a deprecated shim that delegates to it.

`Region` here is deliberately distinct from `SugarCraft\Buffer\Region` in candy-buffer (a leaf-package name collision that keeps candy-layout dependency-free); candy-sprinkles' `RegionBridge` converts between them.

## Dock layout

`SugarCraft\Layout\Dock\*` is an original SugarCraft primitive (no upstream mirror) for the classic IDE/TUI dock: a primary center pane with optional left and right stacks, each stack holding zero or more pane slots split vertically by rational weights. Everything is immutable — mutators return new instances via a hand-rolled `mutate()` (the package is dependency-free, so no candy-core `Mutable` trait).

```php
use SugarCraft\Layout\Dock\{DockLayout, Side};
use SugarCraft\Layout\Region;

$dock = DockLayout::new('chat')                 // center id; empty sides, 1/3 column shares
    ->withSlotAdded(Side::Left, 'files')
    ->withSlotAdded(Side::Right, 'term')
    ->withStackWeight(Side::Left, 0, 2, 1);     // files takes twice the rows

$geometry = $dock->resolve(Region::fromSize(100, 30));
$geometry->regionFor('files');                  // Region(0, 0, 32, 20)
$geometry->regionFor('chat');                   // Region(33, 0, 34, 30)
$geometry->dividerColumns();                    // [['x' => 32, 'side' => Side::Left], ...]
```

**Degradation ladder.** `resolve()` never throws on small frames: a side column is raised to `sideMinCols` only while the center keeps `centerMinCols`; if no plan satisfies both floors, the side with fewer slots drops (ties drop Left), then the remaining side, and finally the center takes the whole frame. Degenerate (zero-width/height) frames resolve to empty geometry — the host decides the fallback. The center region rides inside `$geometry->regions` under its pane id.

**Shares.** Column shares are rational `[num, denom]` pairs (like sugar-crush's `Tui/SplitLayout`, referenced but not depended on). `withColumnShare()` *clamps* — never throws — so left+right stay ≤ 1/2 of usable width; chat is the primary surface. The ceiling is a mutator policy: `fromArray()` restores persisted shares verbatim so round-trips are lossless.

**Persistence.** `toArray()`/`fromArray()` ship an exact versioned shape: `['version' => 1, 'center' => 'chat', 'sides' => ['left' => [['id' => …, 'weight' => [n, d]], …], 'right' => […]], 'columnShare' => ['left' => [1, 3], 'right' => [1, 3]], 'minimums' => [24, 20]]`. Malformed manifests throw `InvalidArgumentException` naming the failed key. `dividerCols` has no public mutator and is not persisted.

## References

- Mirrors [ratatui/ratatui](https://github.com/ratatui/ratatui) layout constraint system
- Based on Badros & Borning 2001 "The Cassowary Linear Arithmetic Constraint Solving Algorithm"
