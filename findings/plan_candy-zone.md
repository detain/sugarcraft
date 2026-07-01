---
status: not-started
phase: 1
updated: 2026-06-30
---

# Implementation Plan: candy-zone

## Goal

Implement Zone::contains() method and document findings discrepancies.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| The findings reference a `Stack` class that does not exist | The `Stack` class with `current()`, `switchTo()`, `switchUp()`, `switchDown()` describes a zone-stacking/selection system, but `candy-zone` is a bubblezone port (mouse zone tracking) | Investigation revealed no `Stack.php` in `candy-zone/src/` |
| `Zone::collidesWithPoint()` does not exist | Findings reference `Zone.php:58-62` but actual `Zone.php` has only 61 lines and uses `inBounds()` with closed intervals | `candy-zone/src/Zone.php` analysis |
| Examples directory already exists | Finding 13 states "No examples/ directory" but `candy-zone/examples/` contains 3 files | `glob` of `candy-zone/examples/` |

## Phase 1: Investigation & Discrepancy Analysis [COMPLETE]

- [x] 1.1 Investigated all source files in `candy-zone/src/` — no `Stack.php` exists
- [x] 1.2 Verified `Zone.php` line count (61 lines) — findings reference line 58-62 which don't exist
- [x] 1.3 Confirmed examples directory exists with 3 functional files
- [x] 1.4 Analyzed Msg classes for hover/drag/click tracking
- [x] 1.5 Reviewed test coverage

## Phase 2: Implementation [IN PROGRESS]

- [ ] 2.1 **Add `Zone::contains(Zone $other): bool` method** ← CURRENT
  - **Severity**: LOW
  - **Location**: `candy-zone/src/Zone.php`
  - **Implementation**: Add rect-vs-rect containment test using closed interval semantics
  - **Tests**: Add to `candy-zone/tests/ZoneTest.php`
- [x] 2.2 Close Finding 11 as PARTIAL — `ZoneHoverTracker` provides equivalent `ZoneEnterMsg`/`ZoneExitMsg` functionality
- [x] 2.3 Close Finding 13 as N/A — Examples directory already exists
- [x] 2.4 Close Findings 2, 3, 5, 6 as N/A — `Stack` class does not exist

## Phase 3: Documentation [PENDING]

- [x] 3.1 Document informational findings (1, 7, 8, 9, 10, 14, 15) that require no action
- [ ] 3.2 Add investigation notes to plan

## Phase 4: Implementation Details [PENDING]

### Zone::contains() Implementation

**Source**: `candy-zone/src/Zone.php` (add after line 60 after `isZero()`)

```php
/**
 * True when $other is entirely contained within this zone.
 * Uses closed interval semantics (boundaries are inclusive).
 */
public function contains(Zone $other): bool
{
    return $other->startCol >= $this->startCol
        && $other->endCol <= $this->endCol
        && $other->startRow >= $this->startRow
        && $other->endRow <= $this->endRow;
}
```

**Tests to add** (`candy-zone/tests/ZoneTest.php`):
```php
public function testContainsInnerZoneReturnsTrue(): void
{
    $outer = new Zone('outer', 1, 1, 10, 10);
    $inner = new Zone('inner', 3, 3, 7, 7);
    $this->assertTrue($outer->contains($inner));
}

public function testContainsOuterZoneReturnsFalse(): void
{
    $outer = new Zone('outer', 1, 1, 10, 10);
    $inner = new Zone('inner', 3, 3, 7, 7);
    $this->assertFalse($inner->contains($outer));
}

public function testContainsOverlappingZoneReturnsFalse(): void
{
    $a = new Zone('a', 1, 1, 5, 5);
    $b = new Zone('b', 3, 3, 7, 7);
    $this->assertFalse($a->contains($b));
    $this->assertFalse($b->contains($a));
}

public function testContainsEqualZoneReturnsTrue(): void
{
    $zone = new Zone('zone', 2, 2, 8, 8);
    $this->assertTrue($zone->contains($zone));
}

public function testContainsAdjacentZoneReturnsFalse(): void
{
    $a = new Zone('a', 1, 1, 5, 5);
    $b = new Zone('b', 6, 1, 10, 5);
    $this->assertFalse($a->contains($b));
    $this->assertFalse($b->contains($a));
}
```

## Summary Table

| Finding | Action | Notes |
|---------|--------|-------|
| 1 | Informational | `inBounds()` uses closed intervals |
| 2, 3, 5, 6 | N/A | `Stack` class doesn't exist |
| 4 | N/A | Stack class doesn't exist |
| 7, 8, 9, 10, 14, 15 | N/A | No issues found |
| 11 | PARTIAL | `ZoneHoverTracker` provides equivalent |
| 12 | **IMPLEMENT** | Add `Zone::contains()` |
| 13 | N/A | Examples already exist |

**Total implementation tasks: 1**

## Notes

- **2026-06-30**: The findings file describes a `Stack` class (findings 2-6, 11) that does not exist in this codebase. `candy-zone` is a bubblezone port for mouse zone tracking, not a zone stacking/selection library.

- **2026-06-30**: `Zone.php` has only 61 lines. Finding 1 references `Zone::collidesWithPoint()` at lines 58-62, which doesn't exist. The actual method is `inBounds()` at lines 26-30 with closed interval semantics.

- **2026-06-30**: Examples exist at `candy-zone/examples/` — Finding 13's claim of missing examples is incorrect.
