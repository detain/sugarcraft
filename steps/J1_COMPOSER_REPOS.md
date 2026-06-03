# Step J1: Add Missing Path Repositories to composer.json

## Goal
Ensure all SugarCraft internal dependencies have path repository entries for local development.

## Current composer.json Analysis

Check for missing path repos:
- `candy-metrics` — used by HistoryRecorder (optional), not in composer.json
- `candy-async` — in "require" but check if path repo needed

## Path Repository Convention (from AGENTS.md)
For each sugarcraft/* package used:

```json
"repositories": [
    {
        "type": "path",
        "url": "../{package-name}",
        "options": {"symlink": true}
    }
]
```

## Files to Modify

1. **`candy-query/composer.json`** — Add any missing path repo entries

## Current Repos Already Present
- candy-core, candy-sprinkles, candy-layout, candy-async, candy-ansi, candy-input
- sugar-table, sugar-toast, sugar-charts, sugar-dash, candy-buffer, candy-forms
- candy-fuzzy, candy-pty, sugar-bits, candy-kit

## Check for Missing
Run: `grep -h "sugarcraft/" /home/sites/sugarcraft/candy-query/composer.json | grep -v "sugarcraft/candy-query"`

Compare against actual imports in source to find missing ones.

## Acceptance Criteria
- All sugarcraft/* packages used in src/ have path repo entries
- `composer install` resolves all packages from local path repos when available
- No "missing repository" warnings from composer

## Coder Notes
- Only add path repos for sugarcraft/* packages (the monorepo local packages)
- External packages (react/mysql, voryx/pgasync) don't need path repos
- Verify with `composer validate --no-check-all` after changes
