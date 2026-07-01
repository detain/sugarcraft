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

## Phase 1: Security Issues [PENDING]

- [ ] 1.1 ANSI Parser SGR State Machine — Located in candy-freeze, not candy-sprinkles

## Phase 2: Bugs [PENDING]

- [ ] 2.1 Width OOB Access — Located in candy-core/Util/Width.php
- [ ] 2.2 Border Corner Off-by-One — File Border/BoxDrawing.php does not exist
- [ ] 2.3 Spinner Animation Frames — Located in candy-forms, not candy-sprinkles
- [ ] 2.4 ProgressBar ETA Division — File does not have ETA at claimed location

## Phase 3: Performance [PENDING]

- [ ] 3.1 Lazy Init Performance — File src/Lipstick.php does not exist

## Phase 4: Missing Features [PENDING]

- [ ] 4.1 Missing StringHeight — May exist in candy-core/Width.php
- [ ] 4.2 Missing Blur Effect — File src/Background.php does not exist

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
