# Implementation Plan: candy-shell Audit Fixes

**Status:** Complete — r86-v1 verification sweep: 7 rows pre-landed ✅, 1.1+1.2 landed-reshaped, 2.1 verified non-issue, 4.1 🔀 cross-lib mis-file (already closed in candy-vcr ledger), 6.1 ⏭️ info  
**Phase:** 1  
**Updated:** 2026-09-17

## Goal

Address all 28 findings from the candy-shell audit across security issues, bugs, performance problems, memory issues, and missing features.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use argv array approach for env-var fallback | Avoids string manipulation vulnerabilities in escapeshellarg chain | `findings/candy-shell.md` |
| Add --sandbox-env allowlist flag for template mode | Prevents credential exfiltration when template source is untrusted | `findings/candy-shell.md` |
| Space key routing verified correct | ItemList::updateFilter() handles KeyType::Space properly | `candy-forms/src/ItemList/ItemList.php:478-482` |
| Create dedicated parseHexColor() method | 3-digit hex expansion and clear error messages | `findings/candy-shell.md` |
| Set $this->closed = true in terminate() | Prevent wait() on already-terminated process | `findings/candy-shell.md` |
| Cache fuzzy results by filterText | Avoid O(n·m) SmithWaterman recomputation on every keystroke | `findings/candy-shell.md` |
| Use php://temp with maxmemory setting | Prevent RAM exhaustion for large CSV data | `findings/candy-shell.md` |
| Clone tileCache array in withTheme() | Prevent shared mutable state between clones | `findings/candy-shell.md` |
| Add --no-selected option to ChooseCommand | Complete Gum compatibility | `findings/candy-shell.md` |
| Add --print-query option to FilterCommand | Complete Gum compatibility | `findings/candy-shell.md` |
| Render fuzzy highlight indices in view | highlightIndices() computes but view() ignores them | `findings/candy-shell.md` |

---

## Phase 1: Security Issues [CLOSED r86-v1]

### 1.1 [x] ✅ HIGH: Shell Injection in Env-Var Fallback — `src/Application.php:137-141` — LANDED-RESHAPE r86-v1: the escapeshellarg→stripslashes→trim chain is gone from src (grep 0 hits); `Application.php:125-191` now injects `--name=value` tokens into ArgvInput's in-process token array, parsed by Symfony's own `parseLongOption()` — no shell channel survives, values land verbatim. Landed via 5e1f08e5d ("fix env var fallback for value options"). Plan condition "new test for env value containing single quotes" was UNPINNED → shipped in r86-v1: `tests/ApplicationEnvTokenInjectionTest::testEnvValueWithQuotesBackslashesAndSpacesSurvivesVerbatim` (mutation-proven: old-chain corruption `foo'''barbaz` and quote-strip both redden exactly 1).

**What is expected:**
Refactor `applyEnvVarFallbackToInput()` to build an argv array instead of using string manipulation with `escapeshellarg → stripslashes → trim` chain. The current approach leaves backslashes that can break token parsing.

**Why:**
A value like `foo'bar` becomes `foo\'bar` after escapeshellarg, then `foo\bar` after stripslashes. The backslash no longer escapes the quote but remains in the value, potentially causing token injection issues.

**Severity:** HIGH

**Conditions for success:**

- [ ] Env vars with special characters (quotes, backslashes, spaces) are correctly passed as option values
- [ ] Existing tests in `tests/EnvVarFallbackTest.php` still pass
- [ ] New test added for edge case: env value containing single quotes

**Related code locations:**

- `src/Application.php:110-151` — `applyEnvVarFallbackToInput()` method
- `src/Application.php:137-141` — problematic code block
- `tests/EnvVarFallbackTest.php` — existing test coverage

**Investigation notes:**
The current code at lines 137-141:
```php
$escaped = escapeshellarg($envValue);
$cleanValue = stripslashes(trim($escaped, "'\"")) ?: $envValue;
$tokens[] = '--' . $option->getName() . '=' . $cleanValue;
```
The reflection hack to inject tokens into ArgvInput is at lines 145-150. The proper fix is to build an array of option values that can be directly set via `$input->setOption()` instead of manipulating the token stream.

---

### 1.2 [x] ✅ HIGH: Unrestricted Env Var Access in Template Mode — `src/Command/FormatCommand.php:99-102` — LANDED-RESHAPE r86-v1: shipped as `--allow-env` (not `--sandbox-env`), FormatCommand.php:35/:54-57/:98-133, commit 1b51f2019. Stronger than planned: SECURE-BY-DEFAULT — empty allowlist (the default) expands nothing; `*` is the explicit unsafe opt-in; non-listed `{{VAR}}` renders empty. Pinned in `tests/Command/FormatCommandTest.php` ("Secure-by-default: without --allow-env, no {{VAR}} expands", :99).

**What is expected:**
Add `--sandbox-env` flag to restrict environment variable substitution to a safe allowlist in `renderTemplate()`. The flag should accept a comma-separated list of allowed variable names (e.g., `--sandbox-env=USER,HOME`).

**Why:**
The current implementation at lines 97-104 substitutes ANY environment variable via `{{VAR_NAME}}` pattern. If the template source is untrusted (e.g., file input from user), credentials or API keys could be exfiltrated.

**Severity:** HIGH

**Conditions for success:**

- [ ] `--sandbox-env` option added to configure() with appropriate description
- [ ] `renderTemplate()` checks against allowlist when sandbox is active
- [ ] Clear error message or empty string when variable not in allowlist
- [ ] Existing `--type template` tests still pass
- [ ] New test: sandboxed env var returns empty string

**Related code locations:**

- `src/Command/FormatCommand.php:25-38` — configure() method
- `src/Command/FormatCommand.php:97-104` — renderTemplate() method

---

## Phase 2: Bugs [CLOSED r86-v1]

### 2.1 [x] ✅ MEDIUM: Space Key Routing in Filter Mode — `src/Model/FilterModel.php:59-67` — VERIFIED NON-ISSUE r86-v1: `FilterModel::update()` toggles only on Tab (comment: "Space is consumed by the inner filter buffer") and forwards every other key to `ItemList::update()`; in filter mode ItemList appends Space to the filter text (candy-forms/src/ItemList/ItemList.php:490 — plan's :478-482 drifted, gated by the `filtering` flag). Non-filter multi-mode Space-toggle pinned by `ChooseModelTest::testMultiModeSpaceTogglesSelection`; filter-mode Space prefill exercised in `FilterModel`'s own ctor loop. No fix required.

**What is expected:**
Verify and potentially fix how Space key is routed in filter mode for multi-select. The finding claims space may be consumed by multi-select toggle instead of filter buffer.

**Why:**
When in filter mode with multi-select enabled, pressing space should add to the filter text, not toggle selection.

**Severity:** MEDIUM

**Conditions for success:**

- [ ] Space key appends to filter buffer in filter mode
- [ ] Space key toggles selection only when NOT in filter mode
- [ ] Test confirms space in filter mode doesn't toggle selection

**Related code locations:**

- `src/Model/FilterModel.php:139-143` — Tab handling for multi-select
- `src/Model/FilterModel.php:145` — list->update() call
- `candy-forms/src/ItemList/ItemList.php:451-485` — updateFilter() method
- `candy-forms/src/ItemList/ItemList.php:478-482` — Space handling in updateFilter()

**Investigation notes:**
After investigation, `ItemList::updateFilter()` at lines 478-482 correctly handles Space:
```php
$msg->type === KeyType::Space
    => $this->mutate(
        filterText: $this->filterText . ' ',
        cursor: 0, offset: 0,
    ),
```
The `filtering` flag gates access to updateFilter() at line 96. Space routing appears correct. This finding may be a non-issue or the issue is in the multi-select Tab handling path, not the Space path.

---

### 2.2 [x] ✅ MEDIUM: Incomplete Hex Color Validation — `src/Style/StyleBuilder.php:166-168`

**What is expected:**
Create a dedicated `parseHexColor()` method that:
1. Properly expands 3-digit hex shorthand (e.g., `#abc` → `#aabbcc`)
2. Rejects invalid digit counts (1, 2, 4, 5 digits) with a clear error message

**Why:**
Current regex at line 166 only matches exactly 3 or 6 digits:
```php
if (preg_match('/^[0-9a-fA-F]{6}$/', $v) === 1 || preg_match('/^[0-9a-fA-F]{3}$/', $v) === 1) {
    return [Color::hex('#' . $v), ColorProfile::TrueColor];
}
```
Inputs like `#1`, `#12`, `#1234`, `#12345` fall through and get generic "unrecognised_color" error.

**Severity:** MEDIUM

**Conditions for success:**

- [x] New `parseHexColor()` method handles 3-digit expansion
- [x] Clear error message indicates digit count requirement
- [x] Test cases: `#abc` → `#aabbcc`, `#1` throws specific error

**Related code locations:**

- `src/Style/StyleBuilder.php:150-170` — `parseColorWithProfile()` method
- `src/Style/StyleBuilder.php:166-168` — problematic regex check

---

### 2.3 [x] ✅ MEDIUM: `$this->closed` Not Set in `terminate()` — `src/Process/RealProcess.php:63-71`

**What is expected:**
Add `$this->closed = true;` inside the `terminate()` method after killing the process.

**Why:**
After `terminate()` is called, subsequent `close()` calls would invoke `wait()` on an already-terminated process. Setting `$this->closed = true` prevents this.

**Severity:** MEDIUM

**Conditions for success:**

- [x] `terminate()` sets `$this->closed = true`
- [x] Test: call terminate() then close() — should not call wait() twice

**Related code locations:**

- `src/Process/RealProcess.php:63-71` — terminate() method

---

## Phase 3: Performance [CLOSED r86-v1]

### 3.1 [x] ✅ MEDIUM: O(n·m) Fuzzy Recomputation on Every Keystroke — `src/Model/FilterModel.php:161-178`

**What is expected:**
Add caching to `computeFuzzyResults()` — early return if `$filterText` is unchanged from last computation. Store cache key as `$filterText` and results as `$fuzzyResultsCache`.

**Why:**
`computeFuzzyResults()` runs `SmithWatermanMatcher::matchAll()` on every character change. No caching means O(n·m) fuzzy matching happens on every keystroke even if filter text is the same.

**Severity:** MEDIUM

**Conditions for success:**

- [x] Fuzzy results cached by filter text
- [x] Early return if filter text unchanged
- [x] Cache invalidated when items change
- [x] Performance test: filter text "abc" typed twice only computes once

**Related code locations:**

- `src/Model/FilterModel.php:99-113` — constructor with matcher
- `src/Model/FilterModel.php:161-178` — computeFuzzyResults()
- `src/Model/FilterModel.php:148-150` — call site

---

### 3.2 [x] ✅ MEDIUM: php://Memory Without Size Limit — `src/Command/TableCommand.php:168`

**What is expected:**
Replace `fopen('php://memory', 'r+')` with `fopen('php://temp/maxmemory=8192', 'r+')` to set an 8MB limit before spilling to disk.

**Why:**
For large CSV data, php://memory could exhaust RAM. php://temp with maxmemory setting provides automatic disk spilling.

**Severity:** MEDIUM

**Conditions for success:**

- [x] Large CSV input (>8MB) doesn't exhaust memory
- [x] Existing CSV parsing tests still pass
- [x] Test with large dataset

**Related code locations:**

- `src/Command/TableCommand.php:168` — memory stream creation

---

## Phase 4: Memory Issues [CLOSED r86-v1]

### 4.1 🔀 MEDIUM: ImagickRasterizer Shared Tile Cache Between Clones — `candy-vcr/src/Raster/ImagickRasterizer.php:58-66` — 🔀 moved — belongs to candy-vcr ledger (r86-v1): cross-lib mis-file, never candy-shell work. Defect already FIXED & tracked there: findings/candy-vcr.md #2 (HIGH) and #13 (LOW), both ✅ r82 — `src/Raster/ImagickRasterizer.php` withTheme()/withFont() deep-clone every Imagick tile per instance (verified at b26b9989f). Cross-reference note appended under #2; no action in this ledger.

**What is expected:**
Fix `withTheme()` and `withFont()` methods to clone the tileCache array instead of sharing it by reference.

**Why:**
When `withTheme()` is called, `tileCache` points to the same array as the original. If either calls `clearTileCache()`, it destroys tiles the other needs.

**Severity:** MEDIUM

**Conditions for success:**

- [ ] Calling `withTheme()` creates independent tileCache
- [ ] Clearing cache on one instance doesn't affect the other
- [ ] Test: clone rasterizer, clear cache on clone, verify original intact

**Related code locations:**

- `candy-vcr/src/Raster/ImagickRasterizer.php:58-67` — withTheme()
- `candy-vcr/src/Raster/ImagickRasterizer.php:69-82` — withFont()

---

## Phase 5: Missing Features [CLOSED r86-v1]

### 5.1 [x] ✅ MEDIUM: Missing `--no-selected` Option — `src/Command/ChooseCommand.php`

**What is expected:**
Add `--no-selected` option that prints a specified message when no items are selected and exits with code different from success.

**Why:**
Gum's choose has `--no-selected` which prints message when no items selected. This is needed for complete Gum compatibility.

**Severity:** MEDIUM

**Conditions for success:**

- [x] `--no-selected="message"` option added to configure()
- [x] When no selection made and --no-selected is set, output message and fail
- [x] Test: --no-selected outputs message when no selection

**Related code locations:**

- `src/Command/ChooseCommand.php:24-41` — configure() method
- `src/Command/ChooseCommand.php:86-88` — aborted/not-submitted handling

---

### 5.2 [x] ✅ MEDIUM: Missing `--print-query` Option — `src/Command/FilterCommand.php`

**What is expected:**
Add `--print-query` option that outputs the current filter text to stdout (mirrors Gum's filter --print-query).

**Why:**
Gum's filter has `--print-query` which outputs current filter text. Needed for pipe chaining where filter result is the query itself.

**Severity:** MEDIUM

**Conditions for success:**

- [x] `--print-query` option added to configure()
- [x] When --print-query is set, print filter text before exiting
- [x] Test: --print-query outputs the typed filter text

**Related code locations:**

- `src/Command/FilterCommand.php:27-49` — configure() method
- `src/Command/FilterCommand.php:87-95` — execute() result handling

---

### 5.3 [x] ✅ MEDIUM: Fuzzy Match Highlighting Not Rendered — `src/Model/FilterModel.php`

**What is expected:**
Use `highlightIndices()` result in `view()` to render highlighted characters in the selected item. The highlighted characters should be visually distinct (e.g., bold or underlined).

**Why:**
`highlightIndices()` at lines 272-289 computes match indices but `view()` at lines 214-223 doesn't use them. Users can't see which characters matched.

**Severity:** MEDIUM

**Conditions for success:**

- [x] view() renders highlighted characters when fuzzy matching
- [x] Test: fuzzy search "bna" shows "b**an**ana" with match highlighted
- [x] Non-fuzzy mode not affected

**Related code locations:**

- `src/Model/FilterModel.php:214-223` — view() method
- `src/Model/FilterModel.php:272-289` — highlightIndices() method
- `src/Style/StyleBuilder.php` — SGR escape sequences for bold/highlight

---

## Phase 6: PHP 8.3+ Compatibility [CLOSED r86-v1]

### 6.1 [x] ⏭️ INFO: PHP 8.3+ Compatibility Confirmed — ⏭️ no action (r86-v1): INFO row, zero changes expected and none made. Compatibility demonstrated: full suite green 334T/694A on PHP 8.3.6 at r86-v1. "PHPStan level 8+" condition is not repo tooling (no phpstan config in candy-shell or the monorepo) — not assessable here; 8.4 leg belongs to CI matrix.

**What is expected:**
No changes needed. The library is fully compatible with PHP 8.3+.

**Why:**
Uses readonly, promoted constructors, match expressions, final class. No PHP 8.4 issues detected.

**Severity:** N/A

**Conditions for success:**

- [ ] Library passes PHPStan level 8+ on PHP 8.3
- [ ] All tests pass on PHP 8.3 and 8.4

---

## Summary of Findings by Severity

| Severity | Count | Items |
|----------|-------|-------|
| HIGH | 2 | 1.1 Shell injection, 1.2 Env var exfiltration |
| MEDIUM | 7 | 2.1 Space routing, 2.2 Hex validation, 2.3 terminate, 3.1 Fuzzy perf, 3.2 Memory stream, 4.1 Imagick cache, 5.1-5.3 Missing features |
| INFO | 1 | 6.1 PHP 8.3+ compatible |

**Total: ~28 findings across all categories**

---

## Notes

- 2026-06-30: Implementation plan created based on audit findings in `findings/candy-shell.md`
- Phase ordering follows severity: HIGH security issues first, then bugs, performance, memory, features
- Each task should be verified with tests before marking complete
- The 1.1 and 1.2 security fixes should be reviewed carefully as they involve security-sensitive code paths
- 2026-09-17 (r86-v1): verification sweep — header "Not Started" was FALSE (7/12 rows already ✅); remaining rows dispositioned in place. Only code shipped: 1 pinning test for the 1.1 plan condition (quote/backslash/space env value), 2 mutations discriminating.
