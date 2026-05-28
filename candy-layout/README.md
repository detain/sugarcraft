# CandyLayout

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-layout)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-layout)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-layout?label=packagist)](https://packagist.org/packages/sugarcraft/candy-layout)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Constraint-based layout solver for terminal grid layouts. Ships two solvers:

- **CassowarySolver** — simplex-based constraint solver (new investment)
- **GreedySolver** — deterministic 5-phase fallback (ported from candy-sprinkles)

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

| Solver          | Use case                            | Edit variables |
| --------------- | ----------------------------------- | ---------------|
| GreedySolver   | Deterministic, fast, no deps        | No             |
| CassowarySolver| Optimal, handles stay constraints   | Yes            |

## Constraint types

- `Constraint::length(int)` — fixed cell count
- `Constraint::min(int)` — floor, grows if slack available
- `Constraint::max(int)` — ceiling, greedy, clamped
- `Constraint::fill(int $weight = 1)` — proportional remainder
- `Constraint::percentage(int 0-100)` — % of total
- `Constraint::ratio(int $num, int $denom)` — fractional proportion

## Shared foundations

`candy-layout` is a **foundation package** consumed by `candy-sprinkles` (step-10) and `sugar-bits`/`candy-forms` (step-14/15). The `LayoutSolver` interface is the only public contract — swap `GreedySolver` for `CassowarySolver` without touching call-sites.

## References

- Mirrors [ratatui/ratatui](https://github.com/ratatui/ratatui) layout constraint system
- Based on Badros & Borning 2001 "The Cassowary Linear Arithmetic Constraint Solving Algorithm"
