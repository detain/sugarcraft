# SugarConstraints

> Formerly published as `sugarcraft/candy-layout` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Layout\` → `SugarCraft\Constraints\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-constraints)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-constraints)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-constraints?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-constraints)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

Constraint-based greedy layout solver for terminal grids: hand it a `Region`
and one `Constraint` per pane, get back sub-regions that tile the area in
whole cells. Why not just `int(0.3 * $width)` per pane? Because a resize must
answer six panes at once — floors, caps, proportional fills, and rounding
leftovers all interact, and hand-rolled arithmetic distributes them
differently on every code path. The solver centralises that decision, keeps it
integer-exact, and is stateless: identical inputs, identical frames, in every
process.

## Install

```sh
composer require sugarcraft/sugar-constraints
```

## Quickstart

The classic two-pane sidebar — a fixed-width rail and a content pane that
absorbs whatever width is left. The runnable version ships as
[`examples/sidebar.php`](examples/sidebar.php).

```php
use SugarCraft\Constraints\Constraint\Constraint;   // note: class inside the Constraint\ namespace
use SugarCraft\Constraints\{Direction, GreedySolver, Region};

$solver = GreedySolver::new();

$rects = $solver->solve(Region::fromSize(100, 24), Direction::Horizontal, [
    Constraint::length(24),   // sidebar:  24
    Constraint::fill(1),      // content:  76
]);
```

At width 10 there is not enough room for both: the fixed pane keeps what it
can take first and the elastic pane collapses — `[10, 0]`. That degradation
is silent by design (see [Degradation](#degradation)).

A tour of every constraint type in one 100-column row, with real outputs:

```php
$rects = $solver->solve(Region::fromSize(100, 24), Direction::Horizontal, [
    Constraint::length(20),      // exactly 20 cells
    Constraint::min(10),         // floor: stays 10 — Fill and Max ate the slack
    Constraint::fill(1),         // gets 1 cell
    Constraint::percentage(30),  // 30% of the span: 30
    Constraint::ratio(1, 3),     // 1/3 of the span: 33
    Constraint::max(50),         // ceiling: greedy up to 50, took 6 here
]);                                  // widths 20,10,1,30,33,6 — sum 100
```

## Types

```
LayoutSolver (interface)         one method: solve(Region, Direction, list<Constraint>)
  └─ GreedySolver (final)        the supported solver
  └─ CassowarySolver (final)     deprecated shim — warns and delegates to GreedySolver
Constraint (abstract class)      lives in the Constraint\ namespace, factories on Constraint
  └─ Length / Min / Max / Fill / Percentage / Ratio
Region                           x/y/width/height box — solver input AND output
Dock\* (DockLayout/DockGeometry/DockSlot/Side)   standalone IDE-dock surface, no solver call
```

## Constraint types

- `Constraint::length(int $cells)` — fixed cell count. One exception: when a
  set has neither `Min` nor `Fill`, `Max`-clamp reclaims are shared with
  Length/Percentage/Ratio and inflate them (see the reclaim ladder below).
- `Constraint::min(int $floor)` — soft floor. It grows only when slack
  survives Fill and Max (proportional clamp under demand); on its own it
  absorbs the whole span.
- `Constraint::max(int $ceiling)` — ceiling, greedy: takes slack up to the cap.
- `Constraint::fill(int $weight = 1)` — proportional claim on the slack left
  after fixed reservation.
- `Constraint::percentage(int $percent)` — `$percent` of the solved-axis span,
  integer `0`–`100` inclusive (so 33⅓ is inexpressible; use `ratio(1, 3)`).
- `Constraint::ratio(int $num, int $denom)` — `num/denom` of the total span on
  the solved axis, reserved like a fixed size. It is **not** an aspect ratio:
  `solve()` is strictly single-axis, so `Ratio(2, 1)` makes a pane twice as
  *wide*, never height-locked to width.

The slack economy, per the algorithm in `GreedySolver`'s class docblock:
Fill and Max share the slack first (Max greedily, up to its cap); a `Min`
grows only when there is neither. The `Max`-clamp pass then hands back what
Maxes gave up, in the order **Min → Fill → everything else** — and in a set
with no Min and no Fill, that "everything else" includes Length/Percentage/
Ratio, which is how a "fixed" `length(10)` becomes 95 in
`[length(10), max(5)]@100 → [95, 5]`. Every one of these shapes is pinned in
`solverEdgeCase`-style probes and the width-sum sweep test.

**Rounding and overflow.** Sizes are whole cells, so `floor()` loses
fractions; the solver hands those cells back so the regions still tile the
span: slack residue rides the first Fill (or the first Max when no Fill is
present), Min-only slack rides the first Min, and a Percentage/Ratio
shortfall of at most N−1 cells (N = their count — the most N floored shares
can lose) is reclaimed earliest-first. A larger gap is slack the constraints
never asked for and stays open. When fixed demand exceeds the span, regions
shrink proportionally and the floor loss is returned one cell per shrunk
region — silently, because a terminal resized below its fixed panes is a
normal runtime state, not an error. `withoutOverflowTruncation()` keeps every
region at its full base size instead (the layout runs off-grid).

## Solvers and policy toggles

| Entry | What it does |
| --- | --- |
| `GreedySolver::new()` | default policy: floor split, remainder to the FIRST region, proportional overflow truncation |
| `GreedySolver::greedy()` | byte-identical alias of `new()` (factory symmetry) |
| `GreedySolver::compat()` | sugar-panels `distribute()` parity bundle: round-split + remainder-to-last + non-truncating overflow |
| `->withRoundSplit(bool $on = true)` | `round()` instead of `floor()` for Percentage/Ratio sizes |
| `->withRemainderToLast(bool $on = true)` | rounding remainder rides the LAST region instead of the first |
| `->withoutOverflowTruncation()` | on overflow every region keeps its full base size (off-grid) |
| `->withMinShare(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0)` | sequential "reserve ≥N columns per unplaced region" floor over Fill weights — reproduces sugar-panels's non-flex `distribute()` byte-for-byte; inherently round-split + remainder-to-last while engaged |
| `GreedySolver::solveStatic(Region $region, array $constraints, Direction $direction)` | legacy no-instance entry for the pre-immutable default policy. **Transposed argument order vs `solve()`** — region, constraints, direction. Signature frozen for callers; prefer an instance. |
| `CassowarySolver::new()/greedy()/cassowary()` | deprecated shim — `solve()` emits `E_USER_DEPRECATED` and delegates wholly to `GreedySolver` |

The compat knobs exist for one reason: `sugar-panels` retired its hand-rolled
distribution helpers against this package while keeping its golden frames
byte-identical. Parity is pinned in this suite against sugar-panels's own
golden tests; a full case sweep found zero divergences. New code should use
the default policy — compat mode exists to reproduce a specific history, not
as a general recommendation.

`CassowarySolver`'s Big-M simplex never converged (it hit the 1000-pivot cap
on every call, including trivial layouts, and silently returned wrong state;
its Ratio path returned 0). The simplex was deleted rather than rewritten:
the shim keeps the class name alive for straggling callers and warns them on
every `solve()`.

## Tiling guarantee — and its two carve-outs

`solve()` tiles the region exactly for every well-constrained set: the widths
(or heights) sum to the region's total. Two deliberate exceptions:

1. **Under-constrained fixed-only sets keep their gap.** `[percentage(30)]@100`
   yields one 30-wide region and 70 untiled columns — the constraints never
   asked for more.
2. **`compat()` / `withoutOverflowTruncation()` runs off-grid on overflow by
   design:** `[length(60), length(60)]@100` yields 60+60 = 120.

Both are pinned in `tests/` and called out on the `LayoutSolver` interface
docblock — check them before painting a pane edge-to-edge off the sum law.

## Degradation

Over-constrained splits truncate **silently**: it is the ordinary state of a
TUI resized below its fixed panes, it recurs every frame, and a warning on the
default handler would print into the terminal the layout is drawing. The
solver ships no `demandOf()`/`isTruncated()` carrier — hosts that need the
signal compare demand against the area themselves: fixed sizes are exact,
Percentage/Ratio resolve against the span, Min floors sum, and Max caps
ceiling; if that sum exceeds the span, something gave. Which pane lost what
is not tellable without duplicating the allocation walk, so prefer
`withoutOverflowTruncation()` (host-side clipping) when collapse must be
visible and distinguishable. Zero-area panes appear in the output unflagged
in both modes.

## Integration and performance

The solver is stateless — nothing to cache, and re-solving on every
`ResizeMsg` is the intended pattern (and what sugar-panels and candy-code's
Dock do). Measured envelope on the 2026-07 CI hardware: ~0.18–0.85 ms per
`solve()`, 471 µs for a full 210-cell dashboard frame, and linear growth to
100k constraints (125 ms / 30 MB) — fixed-point divergence is structurally
impossible because no relational constraints survive the simplex retirement.
The one quadratic entry point is `DockLayout::withSlotAdded()` on very large
stacks (n=1500 → ~2 s); batch from `fromArray()` or keep stacks human-sized.

## Expressivity limits

- **One constraint per region, no combination API.** "30% but never under
  20" and "grow with slack, floor 10, cap 50" cannot be expressed — there is
  no `andThen`/pairing between the six factories.
- **No strength or priority vocabulary.** This port has no `REQUIRED`/`WEAK`
  or edit-constant concept; the Badros & Borning machinery died with the
  simplex and nothing replaced it. Constraints are all equal-rank inputs to
  one deterministic walk.
- **Floors are soft.** `Min` clamps proportionally under demand; `Length`
  truncates; the reclaim ladder can inflate "fixed" types (above).
- **No aspect-ratio lock** — `Ratio` is single-axis (above).
- **Dock sides are share-only.** `withColumnShare()` rationals plus
  `sideMinCols` floors; a "20 columns, always" sidebar is inexpressible
  (1/5 of 100 is 20, of 300 it is 49). No fixed-columns mutator ships.
- **Spare cells go first-wins by list order, weight-blind** — a 101-span
  `[fill(1), fill(2)]` yields `[34, 67]`; the ordering of your list is part
  of the layout.
- **Half-height dock panes vanish on one-row frames** (the stack gap is a
  fixed row).

## Shared foundations

`sugar-constraints` is a **foundation package**, dependency-free with zero
runtime requires. Consumed by `sugar-style` (`Layout\Solver` routes every
split through it, env-selectable via `SolverFactory`), `sugar-panels`
(compat mode + min-share floor), `candy-code` (the `Dock\*` surface), and
`candy-sql` (dashboard page). `sugar-forms` requires it and exposes
`Form::withConstraints()`, but that knob is currently dormant — stored, never
solved. `sugar-widgets` does not consume it. The stable surface is the
`LayoutSolver::solve()` contract, the `Constraint` factories, and the
`Dock\*` API; `Expression` (a leftover of the retired simplex) is
`@internal` and half-immutable on purpose — shipped dead, kept for a future
relational engine.

`Region` here is deliberately distinct from `SugarCraft\CellBuffer\Region` in
sugar-cellbuffer (a leaf-package name collision that keeps sugar-constraints
dependency-free); sugar-style's `RegionBridge` converts between them.

## Dock layout

`SugarCraft\Constraints\Dock\*` is an original SugarCraft primitive (no
upstream mirror) for the classic IDE/TUI dock: a primary center pane with
optional left and right stacks, each stack holding zero or more pane slots
split vertically by rational weights. Everything is immutable — mutators
return new instances via a hand-rolled `mutate()` (the package is
dependency-free, so no sugar-core `Mutable` trait).

```php
use SugarCraft\Constraints\Dock\{DockLayout, Side};
use SugarCraft\Constraints\Region;

$dock = DockLayout::new('chat')                 // center id; empty sides, 1/3 column shares
    ->withSlotAdded(Side::Left, 'files')
    ->withSlotAdded(Side::Left, 'git')
    ->withSlotAdded(Side::Right, 'term')
    ->withStackWeight(Side::Left, 0, 2, 1);     // files takes twice the rows of git

$geometry = $dock->resolve(Region::fromSize(100, 30));
$geometry->regionFor('files');                  // Region(0, 0, 32, 19)
$geometry->regionFor('git');                    // Region(0, 20, 32, 10)
$geometry->regionFor('chat');                   // Region(33, 0, 34, 30)
$geometry->dividerColumns();                    // [['x' => 32, 'side' => Side::Left], ...]
```

`$geometry->regions` iterates in insertion order — left stack, center, right
stack — but treat the pane ids as the only identity contract; `regionFor()`
answers `null` for never-docked panes, dropped sides, and degenerate frames
alike (compare against the input `DockLayout` to tell them apart).

**Degradation ladder.** `resolve()` never throws on small frames: a side
column is raised to `sideMinCols` only while the center keeps `centerMinCols`;
if no plan satisfies both floors, the side with fewer slots drops (ties drop
Left), then the remaining side, and finally the center takes the whole frame.
Degenerate (zero-width/height) frames resolve to empty geometry — the host
decides the fallback. The center region rides inside `$geometry->regions`
under its pane id. A side whose share floors to 0 columns (raise refused
while the center still meets its floor) intentionally keeps its divider and a
width-0 region — the frame stays exactly tiled; hide a side by removing all
of its slots.

**Shares.** Column shares are rational `[num, denom]` pairs (like candy-code's
`Tui/SplitLayout`, referenced but not depended on). `withColumnShare()`
**throws** on malformed rationals (numerator or denominator < 1) and **clamps**
requested magnitudes down under the pair rule — a tightened side may take at
most half the usable columns once the other side has its share, so chat stays
the primary surface. The shipped creation default is thirds left+right, which
sums to 2/3 and predates the tightening ceiling. The ceiling is a mutator
policy: `fromArray()` restores persisted shares verbatim so round-trips are
lossless. Stack heights are split in exact integer arithmetic over the
weights' common denominator (no float ever decides a boundary row), so a
stack weight set whose scaled sum would exceed `PHP_INT_MAX` is refused with
`InvalidArgumentException` at the mutator or manifest that introduces it —
`resolve()` itself never throws.

**Gutters.** The stack gap is one fixed row (internal constant), and
`dividerCols` defaults to 1 with no public mutator — it is geometry input at
construction, not persisted state.

**Persistence.** `toArray()`/`fromArray()` ship an exact versioned shape:
`['version' => 1, 'center' => 'chat', 'sides' => ['left' => [['id' => …, 'weight' => [n, d]], …], 'right' => […]], 'columnShare' => ['left' => [1, 3], 'right' => [1, 3]], 'minimums' => [24, 20]]`.
Malformed manifests throw `InvalidArgumentException` naming the failed key —
including a slot id that collides with the center pane's (a pane has exactly
one home; `withSlotAdded()` refuses the same collision). `dividerCols` has no
public mutator and is not persisted.

## Credits & inspiration

Originally inspired by the [ratatui](https://github.com/ratatui/ratatui)
layout constraint system and Badros & Borning 2001, "The Cassowary Linear
Arithmetic Constraint Solving Algorithm" — though this port implements neither
the simplex nor the strength/priority vocabulary (see Expressivity limits);
SugarCraft is developed as a native PHP project.
