# Implementation Plan: candy-sprinkles Findings

## Goal
Address findings in candy-sprinkles library, mapping each issue to actual file locations in the codebase.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Findings file references non-existent files | 8 files in findings do not exist in candy-sprinkles | investigation |
| ANSI Parser in candy-freeze | Actual AnsiParser is in candy-freeze/src/AnsiParser.php | investigation |
| Width utility in candy-core | Actual Width is in candy-core/src/Util/Width.php | investigation |
| Upstream is lipgloss not bubbles | candy-sprinkles ports charmbracelet/lipgloss | composer.json |
| Border/BoxDrawing.php does not exist | Only Border.php and related exist | glob search |
| Lipstick.php and Background.php do not exist | No such classes in entire monorepo | grep search |

## Phase 1: Security Issues [CLOSED — all misreferenced]

- [x] 1.1 ANSI Parser SGR State Machine — ⏭️ N/A (misreferenced; candy-freeze/src/AnsiParser.php already delegates to candy-ansi's Parser/SgrState — verified 2026-07-04, no fix outstanding)

## Phase 2: Bugs [CLOSED — all misreferenced]

- [x] 2.1 Width OOB Access — ⏭️ N/A (misreferenced; belongs to candy-core Util/Width, no OOB reproduced — verified 2026-07-04)
- [x] 2.2 Border Corner Off-by-One — ⏭️ N/A (file never existed; only sugar-dash has a BoxDrawing.php — verified 2026-07-04)
- [x] 2.3 Spinner Animation Frames — ⏭️ N/A (misreferenced; Spinner lives in candy-forms — verified 2026-07-04)
- [x] 2.4 ProgressBar ETA Division — ⏭️ N/A (no ETA code exists in candy-sprinkles — verified 2026-07-04)

## Phase 3: Performance [CLOSED — misreferenced]

- [x] 3.1 Lazy Init Performance — ⏭️ N/A (Lipstick.php does not exist anywhere in the repo — verified 2026-07-04)

## Phase 4: Missing Features [CLOSED]

- [x] 4.1 Missing StringHeight — ✅ already satisfied: Layout::height(string \$block) in candy-sprinkles/src/Layout.php:59 mirrors lipgloss.Height (verified 2026-07-04)
- [x] 4.2 Missing Blur Effect — ⏭️ N/A (Background.php does not exist anywhere; blur is not a lipgloss feature — verified 2026-07-04)

## Phase 5: PHP 8.3+ Compatibility [COMPLETE]

- [x] 5.1 Already fully compatible with PHP 8.3+ features

## Summary

The findings file for candy-sprinkles has significant discrepancies:
- 8 referenced files do not exist in candy-sprinkles
- Actual issues are in candy-freeze, candy-core, candy-forms, and sugar-dash
- Upstream is incorrectly stated as bubbles instead of lipgloss

## Files Verified as Existing in candy-sprinkles

- candy-sprinkles/src/Style.php (1699 lines)
- candy-sprinkles/src/Theme.php (582 lines)
- candy-sprinkles/src/Border.php (215 lines)
- candy-sprinkles/src/StyleParser.php (214 lines)
- candy-sprinkles/src/Layout.php (261 lines)
- candy-sprinkles/src/Canvas.php
- candy-sprinkles/src/Output.php
- candy-sprinkles/composer.json
