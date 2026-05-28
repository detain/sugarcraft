# Second-Stage Ecosystem Intelligence Report: kojiflowers/php-tui-chart

## 1. Repository Overview

**kojiflowers/php-tui-chart** is an abandoned PHP wrapper for [Toast UI Charts](https://github.com/nhnent/tui.chart), a JavaScript charting library. Despite the "tui" in the name, this is **browser-based charting**, not a terminal/TUI library. The maintainer appears to have abandoned the project around 2018.

| Metric | Value |
|-------|-------|
| **Stars** | 2 |
| **Forks** | 4 |
| **Open Issues** | 0 |
| **Closed Issues** | 0 |
| **Open PRs** | 0 |
| **Closed PRs** | 0 |
| **Discussions** | 0 (404) |
| **Commits** | 5 (all 2018) |
| **Last Commit** | July 18, 2018 |
| **Live Examples** | Dead (projects.kojiflowers.com unreachable) |
| **CDN Dependencies** | Deprecated (rawgit.com shut down) |
| **Labels Defined** | 8 (bug, duplicate, enhancement, good first issue, help wanted, invalid, question, wontfix) — **none used** |

### Why This Repo Is a Black Hole

The repository has **zero community footprint**: no issues filed, no PRs submitted, no discussions initiated, no labels applied. The standard GitHub labels exist but were never used, suggesting the maintainer set them up preemptively but never engaged with any community feedback. The live demo infrastructure has been decommissioned, leaving only broken links.

### Commit History Summary
1. **Apr 24, 2018** — Initial commit with composer autoloading setup
2. **Apr 25, 2018** — Added Draw class, DataPrep trait, initial bar chart support
3. **May 23, 2018** — release_v1.0: renamed Builder to use TUI chart names, added line/column/bar examples
4. **May 27, 2018** — Redesigned example pages, updated CDN URLs
5. **Jul 18, 2018** — Code cleanup and documentation additions

The project achieved v1.0 then died. No meaningful iteration beyond initial release.

---

## 2. Existing SugarCraft Mapping

From the first-stage analysis, the relevant SugarCraft component is **sugar-charts**, which provides native PHP terminal charting via ANSI escape sequences — fundamentally different from php-tui-chart's browser-based JavaScript output.

| Aspect | php-tui-chart | sugar-charts |
|--------|---------------|--------------|
| **Rendering** | Browser (HTML+JS via TUI Chart) | Terminal (ANSI/SGR escape codes) |
| **Architecture** | Single `Draw` facade class | Canvas/Graph primitives + chart classes |
| **Chart types** | lineChart, barChart, columnChart (3) | Sparkline, BarChart, LineChart, Heatmap, OHLC, Scatter, Picture (7+) |
| **Data format** | Row-based or series-based arrays | Iterable arrays, `Bar` objects, `HeatPoint`, tuples |
| **Styling** | TUI Chart theme options | Sprinkles `Theme` with ANSI colors |
| **Output** | `<script>` tag with JS | ASCII/Unicode string |
| **Data prep** | CSV/TSV/JSON file parsing, keypair reorganization | Aggregation utilities, no file parsing |

**No direct port opportunity** — php-tui-chart's value lies entirely in its data transformation patterns, not its rendering layer.

---

## 3. Previously Identified Gaps

The first-stage analysis identified these gaps in php-tui-chart that SugarCraft could potentially address:

1. **No tests** — Zero test coverage
2. **No type safety** — No `declare(strict_types=1)`, no return types, no property types
3. **No validation** — No early checks for required keys until build-time
4. **Limited chart types** — Only line, bar, column vs TUI Chart's full arsenal
5. **No error handling** — File operations not wrapped in try/catch
6. **Hardcoded defaults** — `$default_options` not configurable from outside
7. **Insecure JS output** — String concatenation for JSON embedding vulnerable to injection
8. **Deprecated CDN URLs** — rawgit.com shut down in 2018

---

## 4. High-Signal Open Issues

**None.** The repository has zero open issues. This is a complete black hole for community signals.

### What This Means

The absence of issues is itself a signal: the library was either so simple that users didn't file bugs, or so unused that no community formed to provide feedback. Given:
- 2 stars (barely visible)
- 4 forks (suggests some interest)
- Zero issues ever filed
- Dead demo infrastructure

The most likely interpretation: users tried it, found it didn't work (broken CDN, no tests), and simply moved on rather than filing issues. The maintainer also appears to have stopped responding before any community could form.

---

## 5. Important Closed Issues

**None.** Zero closed issues exist. The label infrastructure (8 labels) was provisioned but never utilized.

---

## 6. Recurring Pain Points

Since there are no issues to analyze, pain points must be inferred from code analysis and the library's failure mode:

### Pain Point 1: Deprecated/Abandoned CDN Dependencies
The library's examples reference `https://rawgit.com/nhnent/tui.code-snippet/v1.3.0/dist/tui-code-snippet.js` and similar URLs. **rawgit.com was decommissioned in 2018.** This means any user copy-pasting the README examples gets broken code out of the box.

**Direct Risk to SugarCraft**: SugarCraft has **no external CDN dependencies** — it renders natively in PHP to ANSI sequences. This failure mode is completely irrelevant to sugar-charts.

### Pain Point 2: No Composer Package
The library uses a manual autoloader (`PhpTuiChart/` classmap) rather than a proper Composer package with PSR-4. Users must manually include the autoloader. In 2018 this was more acceptable, but today it creates friction.

**Direct Risk to SugarCraft**: sugar-charts uses proper Composer/PSR-4. This is a non-issue.

### Pain Point 3: Dead Demo Infrastructure
`projects.kojiflowers.com/php-tui-chart/` is unreachable. The only live examples that once existed are gone, leaving prospective users with no way to evaluate the library's output.

**Direct Risk to SugarCraft**: sugar-charts has `.vhs/` demo files and rendered GIFs in the repo. The CI/CD pipeline maintains these. This is a model SugarCraft should continue.

### Pain Point 4: JavaScript Output Security
The library constructs JavaScript via string concatenation:
```php
$tui_chart_init_code .= "var chart = tui.chart.{$this->type}({$this->container_id}, {$this->chart_data_json}, {$options});";
```
If `$this->chart_data_json` contains user-controlled data with quotes or special characters, this creates XSS vulnerabilities. The library offers no escaping layer between PHP data and JavaScript output.

**Direct Risk to SugarCraft**: sugar-charts outputs ANSI escape sequences, not JavaScript. However, the **data transformation layer** (Aggregation, keypair reorganization) could still be a vector if it accepts unsanitized input. sugar-charts should ensure its data transformation utilities validate and sanitize inputs.

### Pain Point 5: Zero Test Coverage
The library has not a single test. Users have no way to verify the library works correctly before deploying.

**Direct Risk to SugarCraft**: sugar-charts has comprehensive PHPUnit test suites per AGENTS.md requirements. This is already better.

### Pain Point 6: Limited Extensibility
The `keypair` transformation is the only data reorganization option. Adding custom transformations requires modifying the Builder class directly. There's no plugin architecture or extension points.

**Direct Risk to SugarCraft**: sugar-charts' Aggregation classes use a more extensible pattern with typed inputs and outputs. However, sugar-charts could benefit from documenting extension patterns for community contributions.

---

## 7. Frequently Requested Features

**None identifiable.** Zero issues means zero feature requests were ever filed.

However, from the code analysis, we can infer what users would likely have requested:

1. **More chart types** — TUI Chart supports pie, area, bubble, scatter, heatmap, treemap, map — the php-tui-chart wrapper only exposes 3 of ~10
2. **Theme support** — No way to customize colors beyond what TUI Chart defaults provide
3. **Legend control** — No options to show/hide/reposition legends
4. **Tooltips customization** — No access to TUI Chart's extensive tooltip options
5. **Animation control** — No way to disable or configure chart animations
6. **Responsive sizing** — Charts render at fixed dimensions, no auto-resize
7. **Server-side aggregation** — No min/max/avg computations, moving averages, data bucketing

### Relevance to SugarCraft

SugarCraft's sugar-charts already handles many of these through the Sprinkles `Theme` system and `Aggregation` classes. However:
- **Legend control** is not directly exposed in sugar-charts' current API
- **Animation** is meaningless in terminal output but timing/delays could be configurable
- **Server-side aggregation** is partially covered but could be expanded with more statistical functions

---

## 8. Important PRs

**None.** Zero PRs were ever submitted. The repository is a complete black box with no external contribution history.

---

## 9. Architectural Changes

No architectural changes can be traced since there are no issues, PRs, or detailed commit messages describing design evolution. The commit history shows a single developer making incremental additions without community input.

### What the Commit History Reveals

The commits show a solo developer following a typical "big bang then cleanup" pattern:
1. Initial complex structure (Apr 24-25, 2018) — drew too many pieces
2. Simplified for 1.0 release (May 23, 2018) — streamlined to core functionality
3. Example redesign (May 27, 2018) — polish pass on documentation
4. Code cleanup (Jul 18, 2018) — final maintenance, then abandonment

The lack of subsequent commits after July 2018 strongly suggests the developer moved on to other projects or lost interest. The library reached a "good enough for me" state but never achieved the polish needed for community adoption.

---

## 10. Performance Discussions

**None.** No performance-related issues or discussions exist. The library is too simple to have performance concerns — it primarily transforms arrays and emits strings.

### Inferred Performance Profile

From code analysis:
- **Data transformation**: O(n*m) where n = categories, m = series — acceptable for typical data sizes
- **String concatenation**: Uses `.=` in loops — could be a concern for very large datasets
- **Memory**: No streaming — all data kept in memory as arrays

**Direct Risk to SugarCraft**: sugar-charts uses streaming output for the Canvas renderer (as noted in AGENTS.md: "Stream-write gotcha: don't `ftruncate; rewind;` between writes — slice deltas with `ftell`/`fseek`/`stream_get_contents`"). This is a more sophisticated approach that avoids memory bloat with large charts.

---

## 11. Extensibility Discussions

**None.** No discussions about extensibility exist in the issue tracker.

### Codebase Extensibility Analysis

From source code inspection, the library has minimal extensibility:

**Non-extensible patterns:**
- `Builder` class does everything in a single monolithic method chain
- `DataPrep` trait methods are defined but never called in normal flow
- No interface or abstract class defining extension points
- Chart types are hardcoded strings validated only at render time

**Potentially extensible:**
- The `keypair` data reorganization could be swapped for custom transformations by subclassing
- Default options could be overridden by extending classes

**Direct Risk to SugarCraft**: sugar-charts uses a more extensible architecture with:
- Canvas/Graph abstraction separating primitives from chart logic
- Aggregation classes that can be composed
- Theme system via Sprinkles that can be extended

The contrast is instructive: php-tui-chart is a thin wrapper where everything is baked in; sugar-charts has more architectural depth that allows customization.

---

## 12. API/UX Complaints

**None.** Zero issues means zero user complaints on record.

### Inferred API Pain Points

From code analysis, likely user complaints if anyone had used this library enough to complain:

1. **Confusing constructor signature** — `new Draw('lineChart', $data)` requires knowing the exact TUI Chart type string
2. **Inconsistent data formats** — `keypair => true` mode reorganizes data but the documentation doesn't clearly explain when to use it
3. **No validation feedback** — When you pass bad data, you get a JavaScript error in the browser rather than a PHP exception
4. **Magic `__toString()` hides errors** — If rendering fails, `echo $chart` outputs nothing visible — no error, just silent failure
5. **Missing return types** — Modern IDEs can't autocomplete methods, reducing developer experience

**Direct Risk to SugarCraft**: sugar-charts uses proper type declarations throughout. However, the silent failure pattern (point 4) is worth vigilance — sugar-charts should ensure its `view()` method on Models surfaces errors clearly rather than returning empty strings.

---

## 13. Migration Problems

**None.** No migration issues were ever reported.

The library never reached a version where users needed to migrate from an older version. v1.0 was the only version, and it died before achieving community adoption.

---

## 14. Clever Fixes & Workarounds

**None on record.** Without any issues or discussions, no community workarounds were documented.

### Observable Workarounds from Code

The `keypair` data reorganization algorithm is itself a clever workaround for the mismatch between:
- How developers naturally think about data (rows = categories)
- How TUI Chart expects data (columns = series)

```php
// Developers naturally provide:
[
    ['Tesla' => 20, 'Chevy' => 40],  // Canada
    ['Tesla' => 30, 'Chevy' => 40],  // China
]

// But TUI Chart needs:
[
    ['name' => 'Tesla', 'data' => [20, 30]],
    ['name' => 'Chevy', 'data' => [40, 40]],
]
```

The `keypair => true` flag triggers this transposition. This is a useful pattern that SugarCraft could consider for its Aggregation layer when handling multi-series data.

---

## 15. Community Workarounds

**None.** No community formed, so no workarounds were shared.

The 4 forks suggest some developers attempted to work with the code, but none appear to have submitted PRs or opened issues. The library's dead state prevented any community from forming.

---

## 16. Maintainer Guidance Patterns

**Not applicable.** With zero issues, there's no maintainer guidance to analyze.

### Maintainer Behavior Analysis

From commit history and repo state:
- Maintained radio silence after July 2018
- Never responded to any community feedback (because none was submitted)
- Set up issue labels but never used them
- Demo infrastructure abandoned without notice
- CDN links deprecated without migration path

**Direct Risk to SugarCraft**: This is a cautionary tale about abandoning infrastructure. SugarCraft's VHS demo pipeline, documentation, and example infrastructure must be maintained alongside the code. When a dependency (like rawgit.com) dies, users are left with broken examples.

---

## 17. Rejected Ideas Worth Revisiting

**None on record.** Without any issues, no rejected ideas were formally documented.

However, the architecture itself suggests ideas that might have been considered but not implemented:

1. **More chart types** — The Builder class could theoretically support all TUI Chart types by accepting type strings, but only 3 were ever documented
2. **File-based data loading** — `DataPrep` trait has methods for CSV/TSV/JSON parsing, but these are dead code (never called from Builder)
3. **Automatic data range detection** — `findDataRanges()` exists but is never invoked

These feel like features started but never finished. For SugarCraft, this is a reminder: **don't leave dead code in the codebase**. If a method is never called, either wire it up or remove it.

---

## 18. Problems Likely Relevant To SugarCraft

Despite the different domains (browser JS vs terminal ANSI), several problems from php-tui-chart are relevant to sugar-charts:

### Problem 1: Silent Failure on Bad Data
php-tui-chart's `__toString()` returns empty string on failure. sugar-charts' `view()` methods should throw exceptions or return error indicators rather than silent empty output.

### Problem 2: No Validation Until Build Time
php-tui-chart doesn't validate required keys until `buildChart()` is called. sugar-charts should validate data at input boundaries (in constructors/factories) to fail fast with clear error messages.

### Problem 3: Deprecated External Dependencies
php-tui-chart relied on external CDN URLs that have since died. sugar-charts has no such dependencies (pure PHP), but if sugar-charts ever adds optional external resources (fonts, icons), they should be bundled or have clear fallbacks.

### Problem 4: Dead Code in Traits
`DataPrep` trait methods are defined but never called. sugar-charts' Aggregation classes should ensure all public methods are used or removed.

### Problem 5: Inconsistent Type Handling
php-tui-chart has no type safety. sugar-charts uses strict types — this is already better, and should remain a priority.

---

## 19. Features SugarCraft Should Consider

From analyzing php-tui-chart's patterns (even abandoned ones), SugarCraft should consider:

### Consider 1: Keypair-Style Data Transposition for Multi-Series
php-tui-chart's `keypair` mode is a useful pattern for handling row-oriented data transforming to series-oriented output. sugar-charts' Aggregation layer could benefit from a similar utility that transposes multi-series data.

### Consider 2: Data Range Detection Utilities
`findDataRanges()` in php-tui-chart attempts to detect data ranges per series. SugarCraft could use similar utilities for:
- Auto-scaling axes
- Normalizing data for heatmaps
- Detecting outliers for scatter plots

### Consider 3: CSV/TSV File Parsing
php-tui-chart has `prepCsv()`/`prepTsv()` methods (though unused). If sugar-charts adds file input utilities, the pattern is available to model after.

### Consider 4: `__toString()` Error Handling
The `__toString()` magic method pattern is convenient but hides errors. SugarCraft models that implement `__toString()` should ensure errors surface clearly rather than producing silent empty output.

---

## 20. Architectural Lessons

### Lesson 1: Thin Wrappers Die with Their Dependencies
php-tui-chart is essentially a configuration DSL for a JavaScript library. When TUI Chart changed, or when rawgit.com died, the PHP wrapper had no value proposition. The library wasn't self-contained.

**SugarCraft's strength**: sugar-charts is self-contained. It renders with ANSI escape sequences that any terminal can interpret. No external runtime dependencies.

### Lesson 2: No Community = No Feedback Loop
With zero issues, there's no way to know what users wanted. The maintainer had no signal to guide development. A library without community engagement tends to drift toward abandonment.

**SugarCraft's practice**: The monorepo structure, PHPUnit tests, VHS demo pipeline, and documented contribution workflow all foster community. This should continue.

### Lesson 3: Dead Code Signals Neglect
The `DataPrep` trait methods being defined but never called is a red flag. It suggests either:
- Features started and abandoned
- Design confused about where logic should live

**SugarCraft's practice**: Regular codebase audits to remove dead code. The PATH_REPO_CLOSURE skill and php-best-practices skill encourage clean code.

### Lesson 4: Examples Must Be Maintained
The live demo at projects.kojiflowers.com dying without replacement left prospective users with no way to evaluate the library. Code examples in README.md are static — if they rely on dead URLs, they teach users to build broken things.

**SugarCraft's practice**: VHS demos are auto-rendered to GIFs and committed to the repo. README.md examples are tested via the demo pipeline. This must continue.

### Lesson 5: Magic Methods Hide Failures
`__toString()` returning empty string on error is an anti-pattern. Errors should be surfaced, not swallowed.

**SugarCraft's practice**: Model errors should throw exceptions. If rendering fails, developers need to know.

---

## 21. Defensive Design Lessons

### Defensive Lesson 1: Validate Early, Fail Fast
php-tui-chart accepts any array as `$chart_data` and only fails when trying to build. SugarCraft should validate:
- Required array keys exist
- Data types are correct
- Values are within expected ranges

### Defensive Lesson 2: No External Dependencies for Core Functionality
The rawgit.com shutdown broke php-tui-chart's examples permanently. SugarCraft's core rendering has no external dependencies — this is a design strength to preserve.

### Defensive Lesson 3: Remove Dead Code
Unused methods in `DataPrep` trait (never called by Builder) create confusion and suggest design instability. SugarCraft should have a policy of removing or wiring up all defined methods.

### Defensive Lesson 4: Type Safety Prevents Subtle Bugs
php-tui-chart's complete lack of type declarations allowed subtle bugs to hide until runtime. SugarCraft's strict types are a defensive measure that reduces bugs.

### Defensive Lesson 5: Document Extension Points
With no interface hierarchy, extending php-tui-chart required modifying the core Builder class. SugarCraft's Canvas/Graph/Aggregation separation provides natural extension points that should be documented.

---

## 22. Ecosystem Trends

php-tui-chart is a symptom of a broader pattern: **PHP-as-JavaScript-generator** libraries were more common in the pre-modern-JS era (2015-2018). Since then:

1. **JavaScript tooling matured** — Webpack, Vite, esbuild made bundling JS trivial; PHP generators became unnecessary
2. **Browser APIs improved** — Chart.js, D3, ApexCharts offer better APIs than TUI Chart
3. **Server-side rendering declined** — SPAs became the default; PHP generating chart JS is an anachronism
4. **Charting libraries became more capable** — No need for a PHP wrapper when the JS library is full-featured

The ecosystem trend is clear: **if you're rendering in the browser, use JavaScript directly**. PHP has no business generating JavaScript for browser rendering in 2024+.

### SugarCraft's Position

SugarCraft occupies a different niche: **terminal output where JavaScript can't run**. This is a legitimate and underserved market. The trend toward TUI applications (lazygit, htop, vim) creates opportunity. SugarCraft should lean into the terminal-native approach rather than attempting browser interop.

---

## 23. Strategic Opportunities

### Opportunity 1: Data Transformation as a Service
php-tui-chart's `keypair` reorganization pattern is genuinely useful — it solves a real problem (developers think in rows, charting libraries want series). SugarCraft could offer a standalone data-transformation utility that works with any charting system, not just sugar-charts.

### Opportunity 2: File Input for Charts
If sugar-charts added CSV/TSV/JSON parsing (modeled after php-tui-chart's DataPrep trait but properly integrated), it would lower the barrier to entry. Users could load data from files rather than constructing arrays in code.

### Opportunity 3: Documentation of Anti-Patterns
This report itself is a contribution — documenting what NOT to do based on a failed library. SugarCraft's documentation could include a "lessons from abandoned libraries" section to guide contributors away from common mistakes.

---

## 24. Cross-Ecosystem Pattern Matches

### Pattern Match 1: thin-wrapper-around-remote-service
php-tui-chart wraps a JavaScript library. Other examples in the PHP ecosystem:
- `wp_enqueue_script` style wrappers
- PHP-JS bridge libraries

These all fail when the remote service/dependency changes or dies. SugarCraft avoids this by owning the entire rendering stack.

### Pattern Match 2: hobby-project-without-community
A single developer creates something useful for themselves, shares it, but never builds the feedback loop needed for sustainability. The labels were set up (bug, enhancement, etc.) but never used — a classic sign.

SugarCraft's multi-maintainer structure, monorepo with shared tooling, and documented contribution process helps avoid this pattern.

### Pattern Match 3: dead-cdn-kills-library
Depending on external CDNs for core functionality is dangerous. When rawgit.com died, php-tui-chart broke. The lesson: **bundle what you need or own the delivery mechanism**.

SugarCraft's ANSI escape sequences require no external delivery — they're native to terminals. This is a structural advantage.

---

## 25. High ROI Recommendations

Given php-tui-chart's complete failure mode (zero community, dead infrastructure, abandoned), the highest-value recommendations for SugarCraft are:

### Recommendation 1: Maintain the Demo Pipeline (HIGH ROI)
php-tui-chart died in part because its demos died. SugarCraft's VHS demo pipeline produces committed GIFs that never go stale. **Continue investing in this pipeline.** Consider adding automated screenshot testing of rendered chart output.

### Recommendation 2: Enforce Strict Types Everywhere (HIGH ROI)
php-tui-chart had zero type safety. Every SugarCraft class should use `declare(strict_types=1)` and typed properties/methods. This prevents subtle bugs and improves IDE support.

### Recommendation 3: Remove Dead Code (MEDIUM ROI)
The unused `DataPrep` methods suggest incomplete refactoring. Conduct a periodic audit to remove or wire up all defined methods. PHPStorm's "unused method" inspection or `phpstan --DeadCode` can automate this.

### Recommendation 4: Validate Inputs Early (HIGH ROI)
Add input validation to sugar-charts constructors and factory methods. Fail fast with clear error messages rather than silently producing broken output. This would have saved php-tui-chart users hours of debugging.

### Recommendation 5: Document Extension Points (MEDIUM ROI)
php-tui-chart had no documented way to extend it. sugar-charts' Canvas/Graph/Aggregation separation provides natural extension points. Documenting these (in CALIBER_LEARNINGS.md per lib) would help the community contribute meaningfully.

### Recommendation 6: Consider Data Transformation Utilities (LOW-MEDIUM ROI)
The `keypair` transposition pattern is genuinely useful and could be extracted into a standalone `sugar-data` or `SugarCraft\Data` utility. However, this should be prioritized after core library stability is confirmed.

---

## Conclusion

**kojiflowers/php-tui-chart** is a cautionary tale: a thin wrapper around an external JavaScript library that died when its dependencies died, without ever building a community that could have helped sustain it. The complete absence of issues, PRs, and discussions means there are no community signals to extract — the library was born, achieved v1.0, and died in the same year (2018), with no subsequent life.

For SugarCraft, the lessons are clear:
- Own your rendering stack (sugar-charts does)
- Maintain your demo infrastructure (SugarCraft does)
- Validate early and fail fast (sugar-charts already does in many places)
- Use strict types everywhere (sugar-charts does)
- Build community through feedback loops (the monorepo structure enables this)

The `keypair` data reorganization pattern and the DataPrep file parsing pattern are the only two transferable ideas — both could inform sugar-charts Aggregation utilities but neither is urgent.

**Overall assessment**: This ecosystem intelligence report yields low-direct-signal value (no community activity) but high defensive value (understanding failure modes to avoid). The library's death by infrastructure abandonment (dead CDN, dead demos) is the most instructive signal for SugarCraft's long-term sustainability planning.
