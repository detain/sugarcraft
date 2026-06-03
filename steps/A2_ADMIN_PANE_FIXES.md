# Step A2: Fix Admin Pane to Page Mapping

## Goal
Align the 6 AdminPane enum values to correctly map to the MySQL Workbench 6-section layout. Currently:
- `ConnStats` → `DashboardPage` (wrong semantically)
- `TableStats` → `ReportsPage` (misleading)

## MySQL Workbench Layout (correct mapping)
1. **Performance Dashboard** → DashboardPage (ConnStats currently means this)
2. **Server Status** → ServerStatusPage  
3. **Client Connections** → ConnectionsPage
4. **Status/System Variables** → VariablesPage
5. **Performance Reports** → ReportsPage (QueryStats currently)
6. **Performance Schema Setup** → PerfSchemaPage (TableStats currently)

## Files to Modify

1. **`src/Admin/AdminPane.php`** — Rename enum cases to match intent:
   - `ConnStats` → `Dashboard` (more accurate label)
   - `TableStats` → `PerfSchema` (wait, PerfSchema is step A1 - we need to think about this)

## Decision Needed Before Coding
The current 6 panes are:
- ProcessList → Connections
- Variables → Variables  
- Status → Server Status
- QueryStats → Reports
- ConnStats → ??? (should be Dashboard/Dashboard)
- TableStats → ???

**Proposed fix** - Rename cases:
- `ConnStats` → `Dashboard` (label: "Dashboard")
- `TableStats` → `TableStats` (keep, it's a valid report in ReportsPage)

**Alternative** - Keep enum as-is, just fix labels.

## Acceptance Criteria
- All 6 panes map to semantically correct pages
- Labels match MySQL Workbench section names
- No functional change to routing logic (if enum values stay same)

## Coder Notes
- If renaming enum cases, update ALL references in App.php, tests, and anywhere AdminPane is used
- Use `grep -r "AdminPane::" /home/sites/sugarcraft/candy-query/src` to find all usages before renaming
