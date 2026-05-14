# Task Context: sugar-dash Phase 0 Reorganization

Session ID: 2026-05-13-sugar-dash-reorg
Created: 2026-05-13
Status: in_progress

## Current Request
Reorganize sugar-dash from flat `src/Grid/` namespace (222 PHP files) into proper subpackages per `plans/dash_update_claude.md`.

## Context Files (Standards to Follow)
- sugar-dash/CALIBER_LEARNINGS.md (technical debt + patterns)
- sugar-dash/composer.json (PSR-4 autoload config)
- AGENTS.md (ship-as-you-go PR cadence)
- .opencode/context/core/standards/code-quality.md (if available)

## Reference Files (Source Material)
- plans/dash_update_claude.md (full plan - lines 1-858)
- sugar-dash/src/Grid/ (all 222 PHP files to reorganize)

## External Docs Fetched
- /tmp/dash-research/src/ (reference Go libraries for future phases)

## Components (Phase 0 target structure)
```
src/
├── Foundation/           # Pure interfaces + low-level primitives
├── Layout/               # Layout primitives (Grid, Boxer, Tile, etc.)
├── Components/           # UI components (Modal, Select, Toast, etc.)
├── Plot/                 # Charts and plotting
├── Module/               # Module interface + implementations
├── Registry/             # Registry pattern
├── Plugin/               # Plugin system
├── Modules/              # Built-in modules
├── Events/               # Input plumbing
├── Keys/                 # Key registry
├── Position/             # ANSI-aware geometry
├── Output/               # Extracted helpers
└── State/                # State management
```

## Phase 0 Scope
- Move ALL 222 files from src/Grid/ to proper subnamespaces
- Update PSR-4 autoload (namespace inferred from path - no composer.json change needed)
- Update all internal `use` statements
- Update tests tree
- Update examples use lines
- Update README.md table
- Fix TD-1 through TD-8 technical debt inline during move

## Constraints
- Sub-agents ONE-AT-A-TIME per CALIBER_LEARNINGS (file moves touch shared files)
- Boxer.php rename to Pad.php is highest risk (grep first)
- Every public method needs ≥1 test
- vendor/bin/phpunit green before commit

## Exit Criteria
- [ ] All 222 files moved to correct subnamespaces
- [ ] All internal use statements updated
- [ ] All tests pass
- [ ] Examples run with updated use lines
- [ ] README.md updated
- [ ] Phase 0 PR merged

## Technical Debt to Fix During Phase 0
| ID | Issue | Fix |
|----|-------|-----|
| TD-1 | readonly constructor-promoted + clone-mutate fails | Convert to private non-readonly or named constructors |
| TD-2 | Dual-state collections with withers | Add rebuildFiltered() helper |
| TD-5 | str_pad byte-counts ANSI strings | Replace with Width::string()-aware padder |
| TD-6 | Inline secondary classes break PSR-4 | Split files with >1 class |
