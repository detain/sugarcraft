Use this as a high-agency orchestration prompt for the parent agent. It is written assuming the agent system supports subagents/tasks/workers and parallel execution controls.

---

You are operating inside a large monorepo.

Your task is to perform a deep comparative analysis and innovation review across our internal libraries/apps/packages and a set of mapped third-party repositories.

## Primary Objectives

1. Understand every internal package/app/library in depth.
2. Compare each one against similar third-party projects listed in `repo_map.md`.
3. Identify:

   * missing features
   * better UX patterns
   * smarter implementations
   * better APIs
   * superior architecture
   * clever algorithms
   * extensibility improvements
   * customization improvements
   * developer experience improvements
   * performance optimizations
   * maintainability improvements
   * automation opportunities
   * composability patterns
   * UI/UX interaction ideas
   * configuration improvements
   * ecosystem opportunities
4. Generate highly detailed reports for each internal package.
5. Evaluate unmatched third-party repos to determine whether they should become part of the Sugarcraft ecosystem.

---

# INITIALIZATION

First:

1. Read and fully understand:

   * `repo_map.md`
   * root documentation
   * monorepo workspace/package configuration
   * any repo indexing files
   * architecture docs if present

2. Determine:

   * all internal Sugarcraft apps/libs/packages
   * all third-party repos
   * all explicit mappings between them

3. Build a dependency/context understanding before spawning workers.

---

# EXECUTION MODEL

Spawn subagents in parallel.

## Concurrency Rules

* Maximum concurrent agents: 10
* Maintain a rolling queue of work.
* As agents finish, spawn additional ones until all work is complete.

---

# AGENT TYPES

You will spawn TWO categories of agents.

---

# TYPE 1 — INTERNAL PACKAGE ANALYSIS AGENTS

Spawn one agent per internal Sugarcraft package/app/library.

Each agent must:

## Step 1 — Understand The Internal Package Thoroughly

The agent must deeply inspect:

* source code
* documentation
* examples
* tests
* configs
* generated artifacts
* demos
* storybooks
* schemas
* API definitions
* CLIs
* UI components
* menus
* workflows
* extension systems
* plugin systems
* hooks/events
* rendering systems
* state systems
* caching
* persistence
* transports
* protocols
* abstractions
* developer ergonomics
* customization systems
* theming systems
* accessibility support
* configuration APIs
* architecture
* patterns
* algorithms
* performance characteristics

The goal is to understand:

* what it does
* how it works
* how flexible it is
* how customizable it is
* how easy it is to use
* where it is strong
* where it is weak
* where the implementation is clever
* where the implementation is brittle
* where the UX is excellent or poor

Do NOT stay surface-level.

---

## Step 2 — Compare Against Mapped Third-Party Repositories

Start with the third-party repos mapped to this package in `repo_map.md`.

For each mapped repo:

* inspect code
* inspect architecture
* inspect APIs
* inspect UX
* inspect configuration systems
* inspect feature sets
* inspect algorithms
* inspect abstractions
* inspect customization capabilities
* inspect extension points
* inspect performance strategies
* inspect developer ergonomics

Then compare them against the Sugarcraft package.

Identify:

### Feature Gaps

* features they have that we do not
* features we have that they do not
* partially implemented features
* weak implementations
* missing integrations
* missing extensibility

### Design & UX Improvements

* cleaner APIs
* easier workflows
* better defaults
* better configuration patterns
* more composable primitives
* more discoverable UX
* more powerful menu systems
* better keyboard support
* accessibility improvements
* mobile/responsive improvements

### Engineering Improvements

* smarter algorithms
* better data structures
* cleaner abstractions
* simpler architecture
* performance improvements
* caching strategies
* rendering optimizations
* memory improvements
* concurrency improvements
* async patterns
* modularization
* testability improvements

### Ecosystem Opportunities

* plugin ideas
* adapters
* integrations
* reusable utilities
* common abstractions

### Innovation Opportunities

Look specifically for:

* unusually clever solutions
* elegant architecture
* hidden gems
* patterns worth generalizing across Sugarcraft
* techniques that reduce complexity dramatically
* automation opportunities
* AI-assisted workflow opportunities
* codegen opportunities

Do not merely compare features.

Analyze *how* problems are solved.

---

## Step 3 — Explore ALL Remaining Third-Party Repositories

After mapped repos are completed:

Inspect all remaining third-party repos for additional ideas relevant to this package.

Even if they are not directly related, look for:

* reusable patterns
* architectural ideas
* useful abstractions
* UI paradigms
* infrastructure techniques
* tooling ideas
* developer experience improvements
* extensibility models
* workflow improvements

Cross-pollinate ideas aggressively.

---

## Step 4 — Produce Detailed Report

Write findings to:

`repo_map/sugarcraft_<package-name>.md`

Example:

* `repo_map/sugarcraft_candy-crush.md`

Reports must be comprehensive and structured.

Include sections such as:

# Overview

# Current Architecture

# Major Systems

# Feature Inventory

# Strengths

# Weaknesses

# UX Evaluation

# Developer Experience Evaluation

# Performance Notes

# Extensibility Analysis

# Comparison Against Mapped Repositories

# Cross-Repo Innovation Opportunities

# Missing Features

# Smarter Implementations To Adopt

# High-Value Refactors

# Architectural Recommendations

# Quick Wins

# Long-Term Opportunities

# Notable Clever Implementations Found Elsewhere

# Suggested Roadmap

Be concrete and implementation-oriented.

Include:

* file references
* subsystem references
* architectural references
* implementation examples where useful

Avoid vague statements.

---

# TYPE 2 — UNMATCHED THIRD-PARTY REPO ANALYSIS AGENTS

Spawn agents for all unmatched third-party repositories.

These agents should determine:

1. Should this become part of Sugarcraft?
2. Is it redundant with something we already have?
3. Should it instead be mapped to an existing internal package?
4. Does it contain valuable ideas worth borrowing?
5. Is it strategically useful?
6. Does it solve a problem we currently do not solve?
7. Is its implementation superior to ours?

If redundancy is discovered:

* identify which Sugarcraft package it matches
* recommend updates to `repo_map.md`

If it is valuable and unique:

* explain why it should be adopted
* explain where it fits in the ecosystem
* explain integration opportunities

Output all findings into:

`repo_map/unmatched.md`

Structure findings clearly by repository.

---

# ANALYSIS STANDARDS

All agents must:

* think critically
* avoid shallow summaries
* avoid marketing language
* avoid generic observations
* prioritize actionable insights
* prioritize implementation details
* prioritize concrete technical value

Agents should behave like:

* senior architects
* framework authors
* performance engineers
* DX specialists
* product designers
* OSS maintainers

combined together.

---

# IMPORTANT

Do NOT stop at:

* “feature X exists”
* “repo Y has better UX”

Instead explain:

* WHY it is better
* HOW it works
* WHAT tradeoffs exist
* HOW we could adapt it
* WHAT implementation approach should be used
* WHETHER it generalizes across Sugarcraft

---

# FINAL ORCHESTRATION REQUIREMENTS

Track:

* completed analyses
* pending analyses
* spawned agents
* failed agents
* generated report files

Retry failed agents automatically.

At completion:

1. Validate all report files exist.
2. Validate every internal package was analyzed.
3. Validate every third-party repo was inspected.
4. Validate unmatched repos were processed.
5. Validate all reports are non-trivial and detailed.

Then produce a final summary including:

* highest-value opportunities discovered
* recurring architectural weaknesses
* recurring architectural strengths
* ecosystem-wide opportunities
* patterns repeatedly found across external repos
* highest ROI improvements
* most innovative external implementations discovered
