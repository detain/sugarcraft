# Implementation Plan: sugar-style Findings

## Goal

Address findings in sugar-style library, mapping each issue to actual file locations in the codebase.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Findings file references non-existent files | 8 files in findings do not exist in sugar-style | investigation |
| ANSI Parser in candy-codeshot | Actual AnsiParser is in candy-codeshot/src/AnsiParser.php | investigation |
| Width utility in candy-core | Actual Width is in candy-core/src/Util/Width.php | investigation |
| Upstream is lipgloss not bubbles | sugar-style ports charmbracelet/lipgloss | composer.json |
| Border/BoxDrawing.php does not exist | Only Border.php and related exist | glob search |
| Lipstick.php and Background.php do not exist | No such classes in entire monorepo | grep search |

## Phase 1: Security Issues [CLOSED — all misreferenced]

- [x] 1.1 ANSI Parser SGR State Machine — ⏭️ N/A (misreferenced; candy-codeshot/src/AnsiParser.php already delegates to candy-ansi's Parser/SgrState — verified 2026-07-04, no fix outstanding)

## Phase 2: Bugs [CLOSED — all misreferenced]

- [x] 2.1 Width OOB Access — ⏭️ N/A (misreferenced; belongs to candy-core Util/Width, no OOB reproduced — verified 2026-07-04)
- [x] 2.2 Border Corner Off-by-One — ⏭️ N/A (file never existed; only sugar-dashboard has a BoxDrawing.php — verified 2026-07-04)
- [x] 2.3 Spinner Animation Frames — ⏭️ N/A (misreferenced; Spinner lives in sugar-forms — verified 2026-07-04)
- [x] 2.4 ProgressBar ETA Division — ⏭️ N/A (no ETA code exists in sugar-style — verified 2026-07-04)

## Phase 3: Performance [CLOSED — misreferenced]

- [x] 3.1 Lazy Init Performance — ⏭️ N/A (Lipstick.php does not exist anywhere in the repo — verified 2026-07-04)

## Phase 4: Missing Features [CLOSED]

- [x] 4.1 Missing StringHeight — ✅ already satisfied: Layout::height(string \$block) in sugar-style/src/Layout.php:59 mirrors lipgloss.Height (verified 2026-07-04)
- [x] 4.2 Missing Blur Effect — ⏭️ N/A (Background.php does not exist anywhere; blur is not a lipgloss feature — verified 2026-07-04)

## Phase 5: PHP 8.3+ Compatibility [COMPLETE]

- [x] 5.1 Already fully compatible with PHP 8.3+ features

## Summary

The findings file for sugar-style has significant discrepancies:
- 8 referenced files do not exist in sugar-style
- Actual issues are in candy-codeshot, candy-core, sugar-forms, and sugar-dashboard
- Upstream is incorrectly stated as bubbles instead of lipgloss

## Files Verified as Existing in sugar-style

- sugar-style/src/Style.php (1699 lines)
- sugar-style/src/Theme.php (582 lines)
- sugar-style/src/Border.php (215 lines)
- sugar-style/src/StyleParser.php (214 lines)
- sugar-style/src/Layout.php (261 lines)
- sugar-style/src/Canvas.php
- sugar-style/src/Output.php
- sugar-style/composer.json
