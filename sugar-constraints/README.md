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
integer-exact for realistic frames (extreme-magnitude inputs are carve-out 4
below), and is stateless: identical inputs give identical frames — no
ambient state, nothing cached between solves. A "cell" here is a terminal
character column: the library places boxes and never measures text — sizing a
string with CJK, emoji, or combining marks is the caller's problem, and
`sugar-core`'s `Width::of()` answers it.

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

$rects = $solver->solve(Region::fromSize(100, 20), Direction::Horizontal, [
    Constraint::length(24),   // sidebar:  24
    Constraint::fill(1),      // content:  76
]);
```

`solve()` returns one positioned `Region` per constraint, in list order —
output `x`/`y` are offsets **inside the input region**, so each returned box
can be painted directly. The input may itself be a sub-area: pass
`new Region($x, $y, $w, $h)` (say, a span inset by borders) and the walk
places panes within it.

When `SugarCraft\Style\Layout\Constraint` is also in scope (sugar-style's own
class shares the final name), alias this lib's and call the factories through
the alias:

```php
use SugarCraft\Constraints\Constraint\Constraint as Cell;

$cellFill = Cell::fill(1);   // same object as Constraint::fill(1), unambiguous name
```

At width 10 there is not enough room for both: the fixed pane keeps what it
can take first and the elastic pane collapses — `[10, 0]`. That degradation
is silent by design (see [Degradation](#degradation)).

A tour of every constraint type in one 100-column row, with real outputs:

```php
$rects = $solver->solve(Region::fromSize(100, 20), Direction::Horizontal, [
    Constraint::length(20),      // 20 cells — survives here only because the set's Min and Fill catch the reclaim (ladder step 6)
    Constraint::min(10),         // floor: stays 10 — Fill and Max ate the slack
    Constraint::fill(1),         // gets 1 cell
    Constraint::percentage(30),  // 30% of the span: 30
    Constraint::ratio(1, 3),     // 1/3 of the span: 33
    Constraint::max(50),         // ceiling: greedy up to 50, took 6 here
]);                                  // widths 20,10,1,30,33,6 — sum 100
```

## Choosing a layout strategy

- One content flow that sizes itself (paragraphs, form fields): reach for
  `sugar-flexbox`.
- Several panes split and re-resolved on every resize: this solver.
- An IDE-style center with vertical side stacks: the `Dock\*` surface below.

The first is a different package; the last is a different surface of this one.
Cross-references only — nothing here depends on sugar-flexbox.

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

- `Constraint::length(int $n)` — fixed cell count. One exception: when a
  set has neither `Min` nor `Fill`, `Max`-clamp reclaims are shared with
  Length/Percentage/Ratio and inflate them (the **reclaim ladder** — step 6
  below).
- `Constraint::min(int $n)` — soft floor of `$n` cells. It grows only when **slack**
  (what the fixed reservation leaves over) survives Fill and Max
  (proportional clamp under demand); on its own it absorbs the whole span.
- `Constraint::max(int $n)` — ceiling, greedy: takes slack up to the cap, and its clamp pass can hand cells back to the rest of the set (ladder step 6).
- `Constraint::fill(int $weight = 1)` — proportional claim on the slack left
  after the **fixed reservation** (Length plus resolved Percentage/Ratio —
  step 2 below).
- `Constraint::percentage(int $n)` — `$n` of the solved-axis span,
  integer `0`–`100` inclusive (so 33⅓ is inexpressible; use `ratio(1, 3)`).
  The range is the input contract; sizes resolved on spans so huge that the
  `span × n` product overflows int64 fall under carve-out 4 below.
- `Constraint::ratio(int $numerator, int $denominator)` — `numerator/denominator`
  of the total span on
  the solved axis, reserved like a fixed size. It is **not** an aspect ratio:
  `solve()` is strictly single-axis, so `Ratio(2, 1)` makes a pane twice as
  *wide*, never height-locked to width.

The slack economy, per `GreedySolver`'s class docblock — which stays the
code-side mirror of this walk. The seven steps, condensed:

1. `Percentage` and `Ratio` resolve to absolute sizes against the span.
2. The **fixed reservation** sums: `Length` plus the step-1 sizes.
3. `Min` floors and `Max` ceilings are entered; if slack is smaller than the
   sum of floors, every `Min` clamps proportionally.
4. The remaining slack is distributed across `Fill` weights and, greedily up
   to each cap, `Max`.
5. When the set has neither `Fill` nor `Max`, the slack rides the `Min`s
   proportionally instead.
6. The **reclaim ladder**: the `Max`-clamp pass hands back over-allocated
   cells in the order Min → Fill → everything else, and only a set with no
   `Min` and no `Fill` lets "everything else" reach Length/Percentage/Ratio.
7. Demand above supply shrinks regions proportionally and hands the floor
   loss back one cell per shrunk region; a **well-constrained set** — one the
   walk finishes without gap or overflow — then tiles the span exactly.

In action: Fill and Max share the slack first (Max greedily, up to its cap);
a `Min` grows only when there is neither. The clamp pass then hands back what
Maxes gave up, which is how a "fixed" `length(10)` becomes 95 in
`[length(10), max(5)]@100 → [95, 5]`. Every one of these shapes is pinned in
`tests/SolverEdgeCaseTest.php` and swept for the sum law by
`tests/WidthSumInvariantSweepTest.php`.

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
| `GreedySolver::minShareFloor(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0)` | dedicated construction door for the sequential min-share floor — the compat knobs arrive at the mode's truth (floor split, remainder to the LAST region, truncation on) rather than being inherited, for instances built engaged rather than retuned |
| `->withRoundSplit(bool $on = true)` | `round()` instead of `floor()` for Percentage/Ratio sizes |
| `->withRemainderToLast(bool $on = true)` | rounding remainder rides the LAST region instead of the first |
| `->withoutOverflowTruncation()` | on overflow every region keeps its full base size (off-grid) |
| `->withOverflowTruncation(bool $on = true)` | inverse knob: re-enables (or with `false`, disables again) proportional overflow truncation — `compat()` is the factory that starts non-truncating, so this is how a compat instance re-arms it |
| `new GreedySolver(bool $roundSplit = false, bool $remainderToLast = false, bool $truncateOverflow = true, int $minShare = 0, int $reserveGap = 0, int $reserveLead = 0)` | public six-named-arg constructor — a supported entry point (sugar-style's `SolverFactory` and `CassowarySolver` construct through it; the zero-arg call preserves the legacy defaults byte-for-byte). Negative `$minShare`/`$reserveGap`/`$reserveLead` throw `InvalidArgumentException`; all six fields are public readonly, so the resolved policy reads back off the instance |
| `->withMinShare(int $cells = 1, int $reserveGap = 0, int $reserveLead = 0)` | sequential "reserve ≥N cells (columns on the horizontal axis, rows on the vertical) per unplaced region" floor over Fill weights — reproduces sugar-panels's non-flex `distribute()` byte-for-byte. While engaged the floor ignores the compat knobs, so the mutator resets `roundSplit=false` + `remainderToLast=true` (the mode's truth) and leaves `truncateOverflow` alone; `GreedySolver::minShareFloor()` is the dedicated construction door with the same truth. It is its own proportional path: a set containing anything that is not `Fill` is refused with `InvalidArgumentException` ("Min-share floor supports only Fill constraints (proportional split); got …") on both axes |
| `GreedySolver::solveStatic(Region $area, Direction $dir, array $constraints)` | legacy no-instance entry for the pre-immutable default policy. Argument order matches `solve()` (reordered 2026-11 — repo grep found no external positional callers of the old transposed order); prefer an instance for the compat() toggles. |
| `CassowarySolver::new()/cassowary()` | deprecated shim — `solve()` emits `E_USER_DEPRECATED` on EVERY call and delegates wholly to `GreedySolver` (sugar-style's factory latches its own selection notice once per process; the shim's per-solve loudness is deliberate and cross-pinned) |

The compat knobs exist for one reason: `sugar-panels` retired its hand-rolled
distribution helpers against this package while keeping its golden frames
byte-identical. Parity is pinned here by `tests/GreedySolverCompatTest.php`,
which encodes expected splits hand-computed from sugar-panels's
`distribute()`/`distributeFlex()` semantics — it runs this package only, not
the upstream golden suite; a full case sweep found
zero divergences. New code should use
the default policy — compat mode exists to reproduce a specific history, not
as a general recommendation.

`CassowarySolver`'s Big-M simplex never converged (it hit the 1000-pivot cap
on every call, including trivial layouts, and silently returned wrong state;
its Ratio path returned 0). The simplex was deleted rather than rewritten:
the shim keeps the class name alive for straggling callers and warns them on
every `solve()`.

## Tiling guarantee — and its four carve-outs

`solve()` tiles the region exactly for every well-constrained set: the widths
(or heights) sum to the region's total. Four deliberate exceptions:

1. **Under-constrained fixed-only sets keep their gap.** `[percentage(30)]@100`
   yields one 30-wide region and 70 untiled columns — the constraints never
   asked for more.
2. **`compat()` / `withoutOverflowTruncation()` runs off-grid on overflow by
   design:** `[length(60), length(60)]@100` yields 60+60 = 120.
3. **Zero total elastic demand keeps its gap.** `[fill(0)]@100` yields one
   0-wide region: a `Fill` weight of 0 is weightless, and with no positive-
   weight recipient the span is never absorbed — the slack stays untiled.
4. **Extreme-magnitude inputs ride float arithmetic.** The `span × n` /
   `span × numerator` products are unguarded, so beyond int64 they leave
   exact integer arithmetic: resolved sizes then carry IEEE-754 rounding,
   and a final cast that exceeds int64 makes `Region` construction throw a
   misleading negative-width `InvalidArgumentException` naming components the
   caller never passed (probe, 2026-11: `percentage(100)` on a
   `PHP_INT_MAX`-wide frame reported `width=-9223372036854775808`). Terminal
   frames never approach that scale; the behaviour is documented, not
   guarded.

The first two are pinned in `tests/`; the latter two are probe-measured, and
all four are called out on the
`LayoutSolver` interface docblock — check them before painting a pane
edge-to-edge off the sum law.

## Degradation

Over-constrained splits truncate **silently**: it is the ordinary state of a
TUI resized below its fixed panes, it recurs every frame, and a warning on the
default handler would print into the terminal the layout is drawing.
`GreedySolver::demandOf(Region, Direction, array): int` answers the
comparison for you — fixed sizes are exact, Percentage/Ratio resolve against
the span under the instance's rounding policy, Min floors sum, Max caps
ceiling, and Fill contributes 0; demand above the area's solved axis
(`area->width` / `area->height`) says the set cannot be satisfied whole.
Which pane lost what is still not tellable without duplicating the allocation
walk, so prefer `withoutOverflowTruncation()` (host-side clipping) when
collapse must be visible and distinguishable. Zero-area panes appear in the
output unflagged in both modes.

## Integration and performance

The solver is stateless — nothing to cache, and re-solving on every
`ResizeMsg` is the intended pattern (and what sugar-panels and candy-code's
Dock do). Measured envelope (2026-11, PHP 8.3.6 dev box, CLI): ~0.01 ms for
the six-constraint quickstart, ~0.15 ms for a dashboard-sized frame of ~210
constraints, and linear growth to 100k constraints (~95 ms for one solve) —
fixed-point divergence is structurally impossible because no relational
constraints survive the simplex retirement.
Every `DockLayout` slot mutator — `withSlotAdded()`, `withSlotRemoved()`,
`withSlotMovedTo()` — scans all docked slots, O(n) per call, so assembling or
dismantling a large stack is quadratic: a 1,500-slot add chain and its
removal chain each measured ~2 s here. Batch with `fromArray()` (a single
linear pass) or keep stacks human-sized.

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
split vertically by rational weights. Everything is immutable — the solver's
flag mutators and the dock mutators alike return new instances via a
hand-rolled `mutate()` (the package is dependency-free, so no sugar-core
`Mutable` trait).

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
requested magnitudes down under the pair rule — once the other side holds its
share, a tightened side caps at 1/2 minus that share, that is
(otherDenom − 2·otherNum) / (2·otherDenom), floored to whole columns. With the
default right share of 1/3 the left side caps at 1/6: on a 100-column frame
that floors to 16 columns, which `resolve()` then raises to the 20-column side
minimum while the centre keeps its 24; on a 300-column frame the cap is 49.
Chat stays the primary surface. The comparison is an integer cross-product —
exact for realistic magnitudes; shares near `PHP_INT_MAX` overflow it into
float arithmetic and the ceiling can bind incorrectly, while `resolve()`
itself still degrades safely and never throws. The shipped creation default is thirds left+right, which
sums to 2/3 and predates the tightening ceiling. The ceiling is a mutator
policy: `fromArray()` restores persisted shares verbatim so round-trips are
lossless. Stack heights are split in exact integer arithmetic over the
weights' common denominator (no float ever decides a boundary row), so a
stack weight set whose scaled sum would exceed `PHP_INT_MAX` is refused with
`InvalidArgumentException` at the mutator or manifest that introduces it —
`resolve()` itself never throws.

**Gutters.** The stack gap is one fixed row (internal constant). The divider
width is fixed at 1 column with no public configuration:
`DockLayout::__construct` is private, `new()` takes only the centre pane id
and hardcodes the divider, every `with*()` preserves it, and `fromArray()`
forces 1 — it is not part of the persisted shape.

**Persistence.** `toArray()`/`fromArray()` ship an exact versioned shape:
`['version' => 1, 'center' => 'chat', 'sides' => ['left' => [['id' => …, 'weight' => [n, d]], …], 'right' => […]], 'columnShare' => ['left' => [1, 3], 'right' => [1, 3]], 'minimums' => [24, 20]]`.
Malformed manifests throw `InvalidArgumentException` naming the failed key —
including a slot id that collides with the center pane's (a pane has exactly
one home; `withSlotAdded()` refuses the same collision). `dividerCols` has no
public mutator and is not persisted.

## Development and tests

After `composer install`, the examples run directly: `php examples/sidebar.php`
walks the horizontal quickstart (fixed rail, elastic content, a crushed frame),
`php examples/vertical.php` runs the same walk on `Direction::Vertical` — where
a "cell" is one text row — and `php examples/dock.php` resolves the `Dock\*`
surface (center pane, weighted side stacks, and the small-frame ladder that
drops sides instead of throwing). The suite lives in `tests/`
(`vendor/bin/phpunit -c phpunit.xml`). Every fenced `php` block in this README
is executed by `tests/ReadmeSnippetsTest.php`, which also asserts
`examples/sidebar.php` prints the exact frames quoted here — the snippets
cannot rot.

## Credits & inspiration

Originally inspired by the [ratatui](https://github.com/ratatui/ratatui)
layout constraint system and Badros & Borning 2001, "The Cassowary Linear
Arithmetic Constraint Solving Algorithm" — though this port implements neither
the simplex nor the strength/priority vocabulary (see Expressivity limits);
SugarCraft is developed as a native PHP project.
