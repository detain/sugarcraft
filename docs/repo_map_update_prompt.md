You are operating inside a large monorepo containing many internal Sugarcraft libraries/apps and a large collection of research/reference documentation for third-party repositories.

Your task is to perform a comprehensive ecosystem comparison and opportunity analysis across all internal Sugarcraft packages/apps versus all documented third-party repos, PRs, issue discussions, and ecosystem references.

---

# High-Level Objective

For every Sugarcraft package/app:

* Understand what it currently does
* Understand its architecture, APIs, capabilities, roadmap, and limitations
* Compare it against all relevant third-party repositories and PR discussions
* Identify:

  * Missing features
  * Better UX patterns
  * Faster algorithms
  * Better architecture approaches
  * Better APIs
  * Better TUI interactions
  * Better plugin systems
  * Better extensibility
  * Better examples/cookbooks/docs
  * Better developer workflows
  * Better testing strategies
  * Better performance approaches
  * Better caching/indexing/search techniques
  * Better rendering/layout approaches
  * Better terminal handling
  * Better async/concurrency models
  * Better state management
  * Better observability/debugging
  * Better accessibility/usability
  * Better packaging/distribution/versioning
  * Better AI/agent integrations
  * Better MCP integrations
  * Better command systems
  * Better keyboard/mouse interaction systems
  * Better theming/layout/composition systems
  * Better persistence/session handling
  * Better virtualization/windowing/render diffing
  * Better event systems
  * Better resource handling
  * Better portability
  * Better examples/cookbooks/templates
  * Better onboarding/docs/tutorials

You must generate detailed actionable upgrade reports.

---

# Repository Layout

Primary monorepo map:

* `docs/repo_map.md`

Per-Sugarcraft package/app docs:

* `docs/repo_map/sugarcraft_<dir>.md`

Examples:

* `docs/repo_map/sugarcraft_candy-core.md`
* `docs/repo_map/sugarcraft_terminal-ui.md`

Each Sugarcraft lib/app directory also contains:

* `README.md`

Third-party research docs:

* All other files under `docs/repo_map/`
* EXCLUDING files prefixed with:

  * `sugarcraft_`

PR / issue research files:

* Files prefixed with:

  * `pr_`
  * `issue_`
  * `discussion_`
  * similar research/reference docs

---

# REQUIRED EXECUTION MODEL

You MUST follow this exact orchestration structure.

## LEVEL 1 — MASTER AGENT

The master agent will:

1. Read:

   * `docs/repo_map.md`

2. Discover all Sugarcraft package/app directories by locating:

   * `docs/repo_map/sugarcraft_*.md`

3. Extract the `<dir>` names.

4. Divide all Sugarcraft dirs into:

   * EXACTLY 8 balanced groups

Balance by:

* estimated repo complexity
* size of docs
* app/lib complexity
* likely workload

5. Spawn EXACTLY 8 parallel worker agents simultaneously.

---

# LEVEL 2 — GROUP WORKER AGENTS (8 PARALLEL)

Each group worker agent receives:

* a subset of Sugarcraft dirs

The worker processes its assigned Sugarcraft dirs SEQUENTIALLY.

For each Sugarcraft dir:

1. Spawn a dedicated analysis subagent
2. Wait for completion
3. Continue to next dir

Do NOT process multiple Sugarcraft dirs simultaneously inside a group worker.

---

# LEVEL 3 — SUGARCRAFT ANALYSIS SUBAGENT

For one Sugarcraft dir:

Example target:

* `candy-core`

The agent MUST:

---

## Phase 1 — Understand Internal Repo

Read and analyze:

1. `docs/repo_map.md`
2. `docs/repo_map/sugarcraft_<dir>.md`
3. `<dir>/README.md`
4. Any additional docs directly relevant to the package/app

Determine:

* purpose
* architecture
* APIs
* modules
* rendering systems
* plugin systems
* extension systems
* CLI/TUI structure
* current features
* current limitations
* roadmap indicators
* TODO/FIXME indicators
* existing integrations
* testing strategy
* performance concerns
* UX patterns
* current examples/docs quality

Create an internal capability profile.

---

## Phase 2 — Read All Third-Party Research Docs

Read ALL non-Sugarcraft files under:

* `docs/repo_map/`

Including:

* repo research docs
* issue summaries
* PR analyses
* benchmark docs
* architecture notes
* comparison docs
* discussions
* ecosystem notes

This includes:

* `pr_*`
* `issue_*`
* `discussion_*`
* ecosystem research files

Exclude:

* `sugarcraft_*`

---

## Phase 3 — Relevance Filtering

For EACH third-party repo/research source:

Determine:

* Is it relevant to this Sugarcraft package/app?
* Does it solve similar problems?
* Does it overlap architecturally?
* Does it implement superior approaches?
* Does it expose missing functionality?
* Does it solve edge cases better?
* Does it have better developer UX?
* Does it have superior performance/scaling?
* Does it have features users repeatedly request?
* Does it have PRs/issues discussing major improvements?
* Does it expose better abstractions?
* Does it include standout examples/docs/cookbooks?
* Does it contain useful algorithms/patterns/components?

Ignore unrelated repos.

---

## Phase 4 — Deep Comparative Analysis

For ALL relevant sources:

Identify ALL opportunities, including:

### Feature Gaps

* features they have but we lack

### Architecture Improvements

* cleaner abstractions
* better layering
* modularization
* plugin systems
* composition systems

### Performance Improvements

* faster algorithms
* caching
* batching
* rendering optimizations
* virtualization
* memory reduction
* async handling
* concurrency

### UX/TUI Improvements

* keyboard navigation
* layout systems
* mouse support
* accessibility
* interaction patterns
* discoverability

### API Improvements

* cleaner APIs
* better ergonomics
* composability
* stronger typing
* extensibility

### DevEx Improvements

* tooling
* debugging
* observability
* profiling
* test harnesses
* fixtures
* mocks
* examples
* starter templates

### Documentation Improvements

* tutorials
* cookbooks
* examples
* migration guides
* architecture docs
* screenshots
* demos

### Ecosystem Improvements

* integrations
* plugin ecosystems
* adapters
* MCP support
* AI integrations
* editor integrations

### Stability / Reliability

* error handling
* crash recovery
* state consistency
* lifecycle handling
* cleanup systems

### Community Demand Signals

From:

* issues
* PRs
* discussions
* roadmap docs

Identify:

* commonly requested features
* pain points
* scalability issues
* usability complaints
* recurring implementation strategies

---

# REQUIRED OUTPUT FILE

For each Sugarcraft dir create:

* `docs/repo_map/update_sugarcraft_<dir>.md`

Example:

* `docs/repo_map/update_sugarcraft_candy-core.md`

---

# REQUIRED OUTPUT FORMAT

The update file MUST be EXTREMELY detailed.

Use this structure:

# Overview

* Summary of analyzed package/app
* Overall ecosystem positioning
* Biggest opportunity areas
* Biggest missing capabilities

# Internal Capability Summary

Detailed breakdown of:

* current architecture
* current features
* APIs
* rendering systems
* extension systems
* strengths
* weaknesses

# Relevant External Repositories

Table:

* Repo
* Relevance
* Major applicable concepts
* Priority level

# Feature Gap Analysis

Categorized by:

* critical
* high value
* medium
* low priority

For each item include:

* title
* description
* why it matters
* source repo
* source PR/issue/discussion if applicable
* implementation ideas
* estimated complexity
* expected impact

# Algorithm / Performance Opportunities

Include:

* current approach
* external approach
* why external approach is better
* tradeoffs
* applicability

# Architecture Improvements

# API / Developer Experience Improvements

# Documentation / Cookbook Opportunities

# UX / TUI Improvements

# Testing / Reliability Improvements

# Ecosystem / Integration Opportunities

# Notable PRs / Issues / Discussions

Include:

* summaries
* relevance
* lessons learned
* potential adaptations

# Recommended Roadmap

Categorize:

* immediate wins
* medium-term improvements
* major architectural upgrades
* experimental ideas

# Priority Matrix

Table:

* Opportunity
* Impact
* Complexity
* Risk
* Recommended Priority

# Final Strategic Assessment

Detailed concluding analysis.

---

# FINAL AGGREGATION STEP

After ALL group workers complete:

The master agent MUST:

1. Spawn ONE final aggregation agent.

2. The aggregation agent MUST:

   * Read ALL:

     * `docs/repo_map/update_sugarcraft_*.md`

3. Generate:

   * `docs/repo_map_update.md`

---

# FINAL MASTER REPORT REQUIREMENTS

The master report should contain:

# Global Ecosystem Summary

# Cross-Repo Common Weaknesses

# Most Requested Missing Features

# Shared Architectural Opportunities

# Shared Performance Opportunities

# Shared DevEx Problems

# Shared Documentation Gaps

# Shared UX/TUI Gaps

# Shared Testing/Reliability Problems

# Shared Integration Opportunities

# Most Valuable Third-Party Repositories

Rank by:

* ecosystem value
* innovation
* applicability
* architectural quality

# Most Valuable PRs / Discussions

# Global Priority Matrix

# Suggested Organization-Wide Roadmap

# Suggested Shared Internal Frameworks

# Suggested Shared Components/Abstractions

# Potential Consolidation Opportunities

# Repeated Reinventions Across Sugarcraft Packages

# Areas Where Sugarcraft Is Already Superior

# Strategic Recommendations

---

# IMPORTANT EXECUTION RULES

* Be exhaustive.
* Prefer depth over brevity.
* Extract concrete implementation details.
* Focus heavily on actionable engineering insights.
* Include specific algorithms/patterns when possible.
* Include source references everywhere.
* Avoid generic observations.
* Distinguish between:

  * theoretical improvements
  * production-proven approaches
  * experimental ideas
* Pay special attention to:

  * performance
  * TUI rendering
  * async/event systems
  * virtualization
  * terminal handling
  * extensibility
  * developer ergonomics
  * examples/cookbooks
  * AI/agent integrations
  * MCP ecosystem compatibility

---

# CONCURRENCY RULES

STRICTLY FOLLOW:

* 1 master agent
* 8 parallel group worker agents
* each group worker processes Sugarcraft dirs sequentially
* each Sugarcraft dir uses exactly 1 dedicated analysis subagent
* aggregation happens ONLY after all workers complete

Do NOT flatten the hierarchy.
Do NOT process everything in one agent.
Do NOT skip the aggregation phase.

---

# QUALITY BAR

The final reports should resemble:

* senior staff engineer architecture reviews
* ecosystem competitiveness reports
* platform modernization plans
* deep technical gap analyses
* product/platform strategic assessments

The output should be useful for:

* roadmap planning
* architectural redesign
* feature prioritization
* performance initiatives
* ecosystem strategy
* developer experience improvements
* platform consolidation efforts
